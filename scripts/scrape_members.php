<?php
/**
 * Scrape the legacy SAPRF member roster from https://www.precisionrifle.co.za
 * (an ASP.NET Core admin) and write a CSV ready for `php artisan users:import-members`.
 *
 *   Input : storage/scraped/members/cookie.txt   (the logged-in Cookie header:
 *            ".AspNetCore.Session=...; .AspNetCore.Antiforgery....=...")
 *   Output: storage/scraped/members/members.csv
 *
 * Only ACTIVE members with a membership expiry >= --min-expiry (default
 * 2025-01-01) are exported; everyone else can re-register.
 *
 * Endpoints (discovered from the site's JS):
 *   - roster page : GET /members/partial/list?pageNumber=N&pageSize=40  (XHR, 40/page)
 *   - member form : GET /members/{id}/partial/edit                      (XHR)
 *
 * Run from repo root (the cookie must be fresh — it expires):
 *   php scripts/scrape_members.php
 *   php scripts/scrape_members.php --min-expiry=2025-01-01 --limit=10   (test run)
 *   php scripts/scrape_members.php --refresh                            (ignore cache)
 */

declare(strict_types=1);

$root     = str_replace('\\', '/', dirname(__DIR__));
$outDir   = $root.'/storage/scraped/members';
$cacheDir = $outDir.'/_cache';
$baseUrl  = 'https://www.precisionrifle.co.za';

if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}

// ── CLI options ──────────────────────────────────────────────────────────
$opts = [
    'min-expiry' => '2025-01-01',
    'limit'      => 0,      // 0 = no limit (fetch details for every eligible member)
    'refresh'    => false,  // ignore cache
];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--refresh') { $opts['refresh'] = true; continue; }
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    }
}
$minExpiry = $opts['min-expiry'];
$limit     = (int) $opts['limit'];

$cookieFile = $outDir.'/cookie.txt';
if (!is_file($cookieFile)) {
    fwrite(STDERR, "Missing cookie file: {$cookieFile}\n");
    fwrite(STDERR, "Paste the logged-in Cookie header (the .AspNetCore.Session=...; line) into it and re-run.\n");
    exit(1);
}
$cookie = trim((string) file_get_contents($cookieFile));
if ($cookie === '') {
    fwrite(STDERR, "cookie.txt is empty.\n");
    exit(1);
}

// ── HTTP ─────────────────────────────────────────────────────────────────
function httpGet(string $url, ?string $cacheFile, string $cookie, bool $refresh): string
{
    if (!$refresh && $cacheFile !== null && is_file($cacheFile)) {
        return (string) file_get_contents($cacheFile);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_SSL_VERIFYPEER => false, // Laragon PHP ships without a CA bundle
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_COOKIE         => $cookie,
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html, */*; q=0.01',
            'X-Requested-With: XMLHttpRequest',
            'Referer: https://www.precisionrifle.co.za/members',
            'User-Agent: SAPRF-Platform-Scraper/1.0',
        ],
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("cURL error for {$url}: {$err}");
    }
    if ($status === 302 || $status === 401 || $status === 403
        || stripos((string) $body, 'name="__RequestVerificationToken"') !== false && stripos((string) $body, 'password') !== false) {
        throw new RuntimeException(
            "Auth failed ({$status}) for {$url} — your session cookie has probably expired. "
            ."Grab a fresh Cookie header into storage/scraped/members/cookie.txt and re-run."
        );
    }
    if ($status !== 200) {
        throw new RuntimeException("HTTP {$status} for {$url}");
    }

    if ($cacheFile !== null) {
        file_put_contents($cacheFile, $body);
    }
    return (string) $body;
}

function loadDom(string $html): DOMDocument
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    return $doc;
}

function textOf(?DOMNode $n): string
{
    if (!$n) return '';
    return trim(html_entity_decode(preg_replace('/\s+/', ' ', $n->textContent ?? ''), ENT_QUOTES | ENT_HTML5));
}

function inputVal(DOMXPath $xp, string $id): string
{
    $n = $xp->query("//input[@id='{$id}']")->item(0);
    return $n instanceof DOMElement ? trim($n->getAttribute('value')) : '';
}

function selectedText(DOMXPath $xp, string $id): string
{
    $n = $xp->query("//select[@id='{$id}']/option[@selected]")->item(0);
    return $n ? textOf($n) : '';
}

// ── Step 1: page through the roster ────────────────────────────────────────
echo "Fetching roster from {$baseUrl}/members/partial/list ...\n";

$roster = [];   // id => [first, surname, club, type, saprf, expiry, active]
$page   = 1;
$maxPages = 300; // safety valve

while ($page <= $maxPages) {
    $url  = "{$baseUrl}/members/partial/list?pageNumber={$page}&pageSize=40";
    $html = httpGet($url, "{$cacheDir}/list_{$page}.html", $cookie, $opts['refresh']);

    $doc  = loadDom($html);
    $xp   = new DOMXPath($doc);
    $rows = $xp->query('//tbody/tr');

    $rowsOnPage = 0;
    $newOnPage  = 0;
    foreach ($rows as $tr) {
        $rowHtml = $doc->saveHTML($tr);
        if (!preg_match('/showEditModal\((\d+)\)/', $rowHtml, $m)) {
            continue;
        }
        $id = (int) $m[1];
        if (!isset($roster[$id])) {
            $newOnPage++;
        }

        $tds = [];
        foreach ($tr->childNodes as $c) {
            if (strtolower($c->nodeName) === 'td') {
                $tds[] = $c;
            }
        }
        if (count($tds) < 7) {
            continue;
        }

        $roster[$id] = [
            'first'  => textOf($tds[1]),
            'surname'=> textOf($tds[2]),
            'club'   => textOf($tds[3]),
            'type'   => textOf($tds[4]),
            'saprf'  => textOf($tds[5]),
            'expiry' => textOf($tds[6]),
            'active' => str_contains($rowHtml, 'showDeactivateModal'),
        ];
        $rowsOnPage++;
    }

    echo "  page {$page}: {$rowsOnPage} rows, {$newOnPage} new (running total ".count($roster).")\n";
    // Stop at the last page: the site clamps out-of-range pageNumber to the
    // final page and keeps returning it, so bail once a page is short or adds
    // nothing new.
    if ($rowsOnPage === 0 || $newOnPage === 0 || $rowsOnPage < 40) {
        break;
    }
    $page++;
}

echo "Roster complete: ".count($roster)." members across ".($page)." pages.\n\n";

// ── Step 2: filter to active + expiry >= min-expiry ────────────────────────
$eligible = [];
$skippedExpiry = $skippedInactive = 0;
foreach ($roster as $id => $r) {
    if (!$r['active']) { $skippedInactive++; continue; }
    $exp = $r['expiry'];
    if ($exp === '' || $exp < $minExpiry) { $skippedExpiry++; continue; }
    $eligible[$id] = $r;
}

echo "Eligible (active & expiry >= {$minExpiry}): ".count($eligible)."\n";
echo "  skipped inactive: {$skippedInactive}, skipped pre-{$minExpiry} expiry: {$skippedExpiry}\n\n";

if ($limit > 0) {
    $eligible = array_slice($eligible, 0, $limit, true);
    echo "--limit={$limit} -> fetching details for ".count($eligible)." members only.\n\n";
}

// ── Step 3: fetch each eligible member's detail form ───────────────────────
echo "Fetching member details ...\n";
$members = [];
$done = 0;
foreach ($eligible as $id => $r) {
    $html = httpGet("{$baseUrl}/members/{$id}/partial/edit", "{$cacheDir}/member_{$id}.html", $cookie, $opts['refresh']);
    $xp   = new DOMXPath(loadDom($html));

    $first   = inputVal($xp, 'FirstName')   ?: $r['first'];
    $surname = inputVal($xp, 'Surname')     ?: $r['surname'];
    $name    = trim($first.' '.$surname);
    if ($name === '') { continue; }

    $type = strtolower(trim(selectedText($xp, 'MembershipTypeId') ?: $r['type']));

    // "free" registrants only signed up to shoot one provincial — they are not
    // paid-up members, so never stamp them as paid.
    $isFree = $type === 'free';

    $members[] = [
        'name'           => $name,
        'email'          => strtolower(inputVal($xp, 'Email')),
        'phone'          => inputVal($xp, 'PhoneNo'),
        'sa_id_number'   => inputVal($xp, 'IDPassportNo'),
        'date_of_birth'  => inputVal($xp, 'DateOfBirth'),
        'province'       => selectedText($xp, 'HomeProvinceId'),
        'saprf_number'   => inputVal($xp, 'MembershipNo') ?: $r['saprf'],
        'membership_type'=> $type,
        'status'         => 'active',
        'payment_status' => $isFree ? 'unpaid' : 'paid',
        'start_date'     => '',
        'expiry_date'    => inputVal($xp, 'MembershipExpiryDate') ?: $r['expiry'],
        'division'       => '',                       // legacy system has no division
        'club'           => selectedText($xp, 'PrimaryClubId') ?: $r['club'],
        'role'           => 'member',
        'is_active'      => '1',
    ];

    $done++;
    if ($done % 25 === 0) {
        echo "  {$done}/".count($eligible)." ...\n";
    }
    usleep(120000); // be polite: ~120ms between requests
}

echo "Fetched details for ".count($members)." members.\n\n";

// ── Step 4: sanitise emails (avoid merging distinct people) ────────────────
// Blank invalid emails, and de-duplicate: if two members share an email they
// are distinct people, so keep it only on the first and let the importer mint
// placeholder addresses for the rest.
$emailSeen = [];
foreach ($members as &$mrow) {
    $email = $mrow['email'];
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $mrow['email'] = '';
        continue;
    }
    if ($email === '') { continue; }
    if (isset($emailSeen[$email])) {
        $mrow['email'] = '';           // duplicate -> placeholder on import
    } else {
        $emailSeen[$email] = true;
    }
}
unset($mrow);

// ── Write CSV ──────────────────────────────────────────────────────────────
$columns = [
    'name', 'email', 'phone', 'sa_id_number', 'date_of_birth', 'province',
    'saprf_number', 'membership_type', 'status', 'payment_status',
    'start_date', 'expiry_date', 'division', 'club', 'role', 'is_active',
];

$csvPath = $outDir.'/members.csv';
$fp = fopen($csvPath, 'w');
fputcsv($fp, $columns);
foreach ($members as $mrow) {
    fputcsv($fp, array_map(fn ($c) => $mrow[$c] ?? '', $columns));
}
fclose($fp);

$withEmail = count(array_filter($members, fn ($m) => $m['email'] !== ''));
echo "Wrote ".count($members)." members -> {$csvPath}\n";
echo "  with a real email: {$withEmail}, needing a placeholder: ".(count($members) - $withEmail)."\n\n";
echo "Next:\n";
echo "  php artisan users:import-members storage/scraped/members/members.csv --dry-run\n";
echo "  php artisan users:import-members storage/scraped/members/members.csv\n";
