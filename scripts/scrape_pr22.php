<?php
/**
 * Scrape SAPRF PR22 2026 (National + Provincial) match results from
 * https://www.precisionrifle.co.za and write one CSV per match into
 * storage/scraped/pr22/. Also writes storage/scraped/pr22/INDEX.md.
 *
 * Standalone script (no Laravel bootstrap needed). Run from repo root:
 *   php scripts/scrape_pr22.php
 */

declare(strict_types=1);

$root       = str_replace('\\', '/', dirname(__DIR__));
$outDir     = $root.'/storage/scraped/pr22';
$cacheDir   = $outDir.'/_cache';
$natDir     = $outDir.'/national';
$provDir    = $outDir.'/provincial';

foreach ([$outDir, $cacheDir, $natDir, $provDir] as $d) {
    if (!is_dir($d)) {
        mkdir($d, 0777, true);
    }
}

$baseUrl = 'https://www.precisionrifle.co.za';

$targetLeagues = [
    'national'   => 'SAPRF PR22 National Series',
    'provincial' => 'SAPRF PR22 Provincial Series',
];
$targetYear = '2026';

function httpGet(string $url, string $cacheFile): string
{
    if (is_file($cacheFile)) {
        return (string) file_get_contents($cacheFile);
    }
    $ctx = stream_context_create([
        'http' => [
            'header'        => "User-Agent: SAPRF-Platform-Scraper/1.0\r\nAccept: text/html\r\n",
            'timeout'       => 30,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new RuntimeException("Failed to fetch: $url");
    }
    file_put_contents($cacheFile, $body);
    return $body;
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
    $t = trim(preg_replace('/\s+/', ' ', $n->textContent ?? ''));
    return html_entity_decode($t, ENT_QUOTES | ENT_HTML5);
}

function slugify(string $s): string
{
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5);
    $s = preg_replace('/[^A-Za-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return strtolower($s ?: 'match');
}

function normalizeDate(string $s): string
{
    return parseDateRange($s)['start'] ?? preg_replace('/\s+/', '-', trim($s));
}

/**
 * Parse strings like:
 *   "06 - 07 Jun 2026"       -> start 2026-06-06, end 2026-06-07
 *   "23 May 2026"            -> start 2026-05-23, end null
 *   "30 Nov - 01 Dec 2026"   -> start 2026-11-30, end 2026-12-01
 */
function parseDateRange(string $s): array
{
    $s = trim(preg_replace('/\s+/', ' ', $s));
    if (preg_match('/^(\d{1,2})\s+([A-Za-z]{3,})\s*-\s*(\d{1,2})\s+([A-Za-z]{3,})\s+(\d{4})$/', $s, $m)) {
        $y = (int) $m[5];
        return [
            'start' => sprintf('%d-%s-%02d', $y, date('m', strtotime($m[2].' 1 2000')), (int) $m[1]),
            'end'   => sprintf('%d-%s-%02d', $y, date('m', strtotime($m[4].' 1 2000')), (int) $m[3]),
        ];
    }
    if (preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})\s+([A-Za-z]{3,})\s+(\d{4})$/', $s, $m)) {
        $mon = date('m', strtotime($m[3].' 1 2000'));
        return [
            'start' => sprintf('%s-%s-%02d', $m[4], $mon, (int) $m[1]),
            'end'   => sprintf('%s-%s-%02d', $m[4], $mon, (int) $m[2]),
        ];
    }
    if (preg_match('/^(\d{1,2})\s+([A-Za-z]{3,})\s+(\d{4})$/', $s, $m)) {
        return [
            'start' => sprintf('%s-%s-%02d', $m[3], date('m', strtotime($m[2].' 1 2000')), (int) $m[1]),
            'end'   => null,
        ];
    }
    return ['start' => null, 'end' => null];
}

function extractDetail(DOMXPath $xp, string $label): ?string
{
    $nodes = $xp->query('//span[normalize-space()="'.$label.':"]');
    foreach ($nodes as $span) {
        $sib = $span->nextSibling;
        $buf = '';
        while ($sib) {
            if ($sib->nodeType === XML_ELEMENT_NODE && strtolower($sib->nodeName) === 'br') break;
            $buf .= $sib->textContent ?? '';
            $sib = $sib->nextSibling;
        }
        $t = trim(preg_replace('/\s+/', ' ', $buf));
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5);
        if ($t !== '') return $t;
    }
    return null;
}

echo "Fetching past events list...\n";
$pastHtml = httpGet($baseUrl.'/events/past/partial/list', $cacheDir.'/past.html');

echo "Fetching upcoming events lists (in case results posted early)...\n";
$upHtml = '';
try {
    $upHtml = httpGet($baseUrl.'/events/upcoming/partial/listbymonth', $cacheDir.'/upcoming.html');
} catch (Throwable $e) {
}

$candidates = [];
foreach ([$pastHtml, $upHtml] as $listHtml) {
    if ($listHtml === '') continue;
    $doc = loadDom($listHtml);
    $xp = new DOMXPath($doc);
    $rows = $xp->query('//table//tbody/tr');
    foreach ($rows as $tr) {
        $tds = $xp->query('./td', $tr);
        if ($tds->length < 5) continue;

        $dateText   = textOf($tds->item(0));
        $eventCell  = $tds->item(1);
        $venueText  = textOf($tds->item(2));
        $provText   = textOf($tds->item(3));
        $leagueText = textOf($tds->item(4));

        if (strpos($leagueText, $targetYear) === false) continue;

        $level = null;
        foreach ($targetLeagues as $lvl => $needle) {
            if (stripos($leagueText, $needle) !== false) {
                $level = $lvl;
                break;
            }
        }
        if ($level === null) continue;

        $link = $xp->query('.//a[contains(@href, "/events/")]', $eventCell)->item(0);
        if (!$link) continue;

        $href = $link->getAttribute('href');
        if (!preg_match('#/events/(\d+)#', $href, $m)) continue;
        $eventId = (int) $m[1];

        $name = textOf($link);

        $range = parseDateRange($dateText);
        $candidates[$eventId] = [
            'id'         => $eventId,
            'name'       => $name,
            'date'       => $dateText,
            'date_iso'   => $range['start'] ?? normalizeDate($dateText),
            'end_iso'    => $range['end'],
            'venue'      => $venueText,
            'province'   => $provText,
            'league'     => $leagueText,
            'level'      => $level,
            'match_type' => 'pr22',
            'series'     => 'pr22',
            'season'     => $targetYear,
            'source_url' => $baseUrl.'/events/'.$eventId,
        ];
    }
}

usort($candidates, fn($a, $b) => strcmp($a['date_iso'], $b['date_iso']));

echo count($candidates)." PR22 2026 match(es) discovered.\n";

$scraped = [];
$skipped = [];

foreach ($candidates as $ev) {
    $slug = slugify($ev['name']);
    $csvPath = ($ev['level'] === 'national' ? $natDir : $provDir).'/'.$ev['date_iso'].'_'.$slug.'.csv';
    $cacheHtml = $cacheDir.'/event_'.$ev['id'].'.html';

    echo "- [{$ev['level']}] #{$ev['id']} {$ev['date_iso']} {$ev['name']}... ";

    try {
        $html = httpGet($baseUrl.'/events/'.$ev['id'], $cacheHtml);
    } catch (Throwable $e) {
        echo "FETCH FAIL\n";
        $skipped[] = ['ev' => $ev, 'reason' => 'fetch failed: '.$e->getMessage()];
        continue;
    }

    $doc = loadDom($html);
    $xp = new DOMXPath($doc);

    $ev['match_director'] = extractDetail($xp, 'Match Director');
    $ev['contact']        = extractDetail($xp, 'Contact');
    $venueFromPage        = extractDetail($xp, 'Venue');
    if ($venueFromPage) $ev['venue'] = $venueFromPage;
    $ev['also_counts_for_provincial'] = ($ev['level'] === 'national' && !empty($ev['end_iso'])) ? 1 : 0;

    $resultsTable = null;
    foreach ($xp->query('//h4') as $h) {
        if (stripos(textOf($h), 'Match Results') !== false) {
            $node = $h;
            while ($node && $node->parentNode) {
                $node = $node->parentNode;
                $tbl = $xp->query('.//table[.//thead//th[normalize-space()="Entrant"]]', $node)->item(0);
                if ($tbl) { $resultsTable = $tbl; break; }
            }
            if ($resultsTable) break;
        }
    }
    if (!$resultsTable) {
        $resultsTable = $xp->query('//table[.//thead//th[normalize-space()="Entrant"]]')->item(0);
    }

    if (!$resultsTable) {
        echo "NO RESULTS TABLE\n";
        $skipped[] = ['ev' => $ev, 'reason' => 'no results table on event page (probably not yet published)'];
        continue;
    }

    $headers = [];
    foreach ($xp->query('.//thead//th', $resultsTable) as $th) {
        $headers[] = strtolower(textOf($th));
    }
    $idxPos      = array_search('pos', $headers, true);
    $idxEntrant  = array_search('entrant', $headers, true);
    $idxDivision = array_search('division', $headers, true);
    $idxScore    = array_search('score', $headers, true);
    if ($idxEntrant === false || $idxScore === false) {
        echo "HEADERS MISMATCH: ".implode('|', $headers)."\n";
        $skipped[] = ['ev' => $ev, 'reason' => 'header layout unrecognised: '.implode('|', $headers)];
        continue;
    }

    $rows = [];
    foreach ($xp->query('.//tbody/tr', $resultsTable) as $tr) {
        $tds = $xp->query('./td', $tr);
        if ($tds->length < count($headers)) continue;
        $rows[] = [
            'shooter_name' => textOf($tds->item($idxEntrant)),
            'division'     => $idxDivision !== false ? textOf($tds->item($idxDivision)) : 'unknown',
            'raw_score'    => textOf($tds->item($idxScore)),
            'placement'    => $idxPos !== false ? textOf($tds->item($idxPos)) : '',
        ];
    }

    if (!$rows) {
        echo "0 ROWS\n";
        $skipped[] = ['ev' => $ev, 'reason' => 'results table present but empty'];
        continue;
    }

    $fh = fopen($csvPath, 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, ['shooter_name', 'division', 'raw_score', 'placement']);
    foreach ($rows as $r) {
        fputcsv($fh, [$r['shooter_name'], $r['division'], $r['raw_score'], $r['placement']]);
    }
    fclose($fh);

    $scraped[] = [
        'ev'      => $ev,
        'rows'    => count($rows),
        'csvPath' => str_replace($root.'/', '', str_replace('\\', '/', $csvPath)),
    ];
    echo count($rows)." rows -> $csvPath\n";
}

$catalogPath = $outDir.'/matches.csv';
$ch = fopen($catalogPath, 'w');
fwrite($ch, "\xEF\xBB\xBF");
fputcsv($ch, [
    'source_id','name','match_type','series','season','series_level',
    'match_date','match_end_date','province','venue_name',
    'match_director','contact','also_counts_for_provincial',
    'shooter_count','source_url','scores_csv',
]);
foreach ($scraped as $s) {
    $ev = $s['ev'];
    fputcsv($ch, [
        $ev['id'], $ev['name'], $ev['match_type'], $ev['series'], $ev['season'], $ev['level'],
        $ev['date_iso'], $ev['end_iso'] ?? '',
        $ev['province'], $ev['venue'],
        $ev['match_director'] ?? '', $ev['contact'] ?? '', $ev['also_counts_for_provincial'] ?? 0,
        $s['rows'], $ev['source_url'], $s['csvPath'],
    ]);
}
fclose($ch);

$indexPath = $outDir.'/INDEX.md';
$md  = "# SAPRF PR22 2026 — Scraped Match Results\n\n";
$md .= "Source: https://www.precisionrifle.co.za/events (past + upcoming listings)\n";
$md .= "Scraped: ".date('Y-m-d H:i:s')."\n\n";
$md .= "Series in scope:\n\n";
foreach ($targetLeagues as $k => $v) $md .= "- $v $targetYear (`$k`)\n";
$md .= "\nPer-match score CSV columns: `shooter_name,division,raw_score,placement`\n";
$md .= "Match catalog: `matches.csv` (one row per match, ready to import as `MatchEvent` records)\n\n";
$md .= "## Matches scraped\n\n";
$md .= "| Date | End | Level | Match | Venue | Province | MD | Shooters | 2day→prov | Src | CSV |\n";
$md .= "|---|---|---|---|---|---|---|---:|:---:|---|---|\n";
foreach ($scraped as $s) {
    $ev = $s['ev'];
    $md .= sprintf(
        "| %s | %s | %s | %s | %s | %s | %s | %d | %s | [#%d](%s) | `%s` |\n",
        $ev['date_iso'],
        $ev['end_iso'] ?? '—',
        $ev['level'],
        $ev['name'],
        $ev['venue'],
        $ev['province'],
        $ev['match_director'] ?? '—',
        $s['rows'],
        ($ev['also_counts_for_provincial'] ?? 0) ? 'yes' : '—',
        $ev['id'],
        $ev['source_url'],
        $s['csvPath'],
    );
}
if ($skipped) {
    $md .= "\n## Skipped\n\n";
    foreach ($skipped as $sk) {
        $md .= sprintf(
            "- **#%d %s** (%s, %s) — %s\n",
            $sk['ev']['id'], $sk['ev']['name'], $sk['ev']['date_iso'], $sk['ev']['level'], $sk['reason']
        );
    }
}
file_put_contents($indexPath, $md);

$totalRows = array_sum(array_column($scraped, 'rows'));
echo "\nDone. Matches scraped: ".count($scraped)."  Total shooter rows: $totalRows  Skipped: ".count($skipped)."\n";
echo "INDEX: $indexPath\n";
