<?php
/**
 * Estimate PR22 .22LR ammunition volume from scraped match results.
 *
 * Methodology (agreed with SAPRF for the report):
 *   rounds fired at an event  ≈  (top score at that event) × (shooters who shot)
 *   season total              =  Σ over completed events
 *
 * Rationale: the source site (precisionrifle.co.za) does not publish a course
 * of fire / round count per match — only each entrant's total score. The
 * winning (top) score is the best available floor for the number of scored
 * rounds in the course of fire, and every shooter fires the full course, so
 * top-score × shooters approximates total rounds sent downrange.
 *
 * Reads storage/scraped/pr22/matches.csv + each per-match CSV.
 * Standalone (no Laravel bootstrap). Run from repo root:
 *   php scripts/pr22_ammo_estimate.php
 *
 * The 2-day-national provincial MIRROR events (day-1 re-posted as a separate
 * provincial result) are excluded so their rounds aren't counted twice. By
 * default that's source id 250 (WC), overridable with --skip-source-ids=.
 */

declare(strict_types=1);

$root   = str_replace('\\', '/', dirname(__DIR__));
$outDir = $root.'/storage/scraped/pr22';
$catalog = $outDir.'/matches.csv';

$skip = ['250'];
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--skip-source-ids=')) {
        $skip = array_filter(array_map('trim', explode(',', substr($arg, 18))));
    }
}

if (!is_file($catalog)) {
    fwrite(STDERR, "matches.csv not found at {$catalog}. Run scripts/scrape_pr22.php first.\n");
    exit(1);
}

/** Read a BOM-tolerant CSV into an array of assoc rows keyed by lower-cased headers. */
function readCsv(string $path): array
{
    $rows = [];
    $fh = fopen($path, 'r');
    if (!$fh) return $rows;
    $bom = pack('H*', 'EFBBBF');
    if (fread($fh, 3) !== $bom) rewind($fh);
    $headers = fgetcsv($fh);
    if (!$headers) { fclose($fh); return $rows; }
    $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $headers);
    while (($line = fgetcsv($fh)) !== false) {
        if (count($line) === 1 && trim((string) $line[0]) === '') continue;
        $line = array_pad(array_slice($line, 0, count($headers)), count($headers), '');
        $rows[] = array_combine($headers, array_map('trim', $line));
    }
    fclose($fh);
    return $rows;
}

$matches = readCsv($catalog);

$perEvent   = [];
$bySeason   = [];   // season => ['events'=>,'shooters'=>,'rounds'=>]
$bySeasonLvl = [];  // "season|level" => same

foreach ($matches as $m) {
    $sid = (string) ($m['source_id'] ?? '');
    if (in_array($sid, $skip, true)) {
        continue;
    }

    $csv = $root.'/'.($m['scores_csv'] ?? '');
    if (!is_file($csv)) {
        fwrite(STDERR, "  ! missing scores csv for #{$sid}: {$csv}\n");
        continue;
    }

    $scoreRows = readCsv($csv);
    $scores = [];
    foreach ($scoreRows as $r) {
        $raw = trim((string) ($r['raw_score'] ?? ''));
        if ($raw === '' || !is_numeric($raw)) continue;   // blank = DNS/DNF, no rounds credited
        $scores[] = (float) $raw;
    }

    $shooters = count($scores);
    $top      = $shooters ? max($scores) : 0.0;
    $rounds   = (int) round($top * $shooters);

    $season = (string) ($m['season'] ?? '?');
    $level  = (string) ($m['series_level'] ?? '?');

    $perEvent[] = [
        'season' => $season,
        'date' => $m['match_date'] ?? '',
        'level' => $level,
        'name' => $m['name'] ?? '',
        'shooters' => $shooters,
        'top' => $top,
        'rounds' => $rounds,
    ];

    foreach ([$season => &$bySeason, $season.'|'.$level => &$bySeasonLvl] as $key => &$agg) {
        $agg[$key] ??= ['events' => 0, 'shooters' => 0, 'rounds' => 0];
        $agg[$key]['events']++;
        $agg[$key]['shooters'] += $shooters;
        $agg[$key]['rounds']   += $rounds;
    }
    unset($agg);
}

// ── Per-event detail ──
usort($perEvent, fn ($a, $b) => [$a['season'], $a['date']] <=> [$b['season'], $b['date']]);

echo "PR22 .22LR ammunition estimate — rounds ≈ top score × shooters, per event\n";
echo "Skipped source id(s): ".(implode(',', $skip) ?: '<none>')." (2-day national provincial mirror)\n\n";

printf("%-6s %-11s %-11s %-40s %8s %6s %10s\n",
    'Season', 'Date', 'Level', 'Match', 'Shooters', 'Top', 'Rounds');
echo str_repeat('-', 96)."\n";
foreach ($perEvent as $e) {
    printf("%-6s %-11s %-11s %-40s %8d %6.0f %10s\n",
        $e['season'], $e['date'], $e['level'],
        mb_strimwidth($e['name'], 0, 40, ''),
        $e['shooters'], $e['top'], number_format($e['rounds']));
}

// ── Season × level ──
echo "\nBy season and level:\n";
ksort($bySeasonLvl);
printf("%-6s %-11s %8s %10s %12s\n", 'Season', 'Level', 'Events', 'Shooters', 'Rounds');
echo str_repeat('-', 50)."\n";
foreach ($bySeasonLvl as $key => $a) {
    [$s, $l] = explode('|', $key);
    printf("%-6s %-11s %8d %10d %12s\n", $s, $l, $a['events'], $a['shooters'], number_format($a['rounds']));
}

// ── Season totals ──
echo "\nSeason totals:\n";
ksort($bySeason);
printf("%-6s %8s %10s %12s\n", 'Season', 'Events', 'Shooters', 'Rounds');
echo str_repeat('-', 40)."\n";
$grand = ['events' => 0, 'shooters' => 0, 'rounds' => 0];
foreach ($bySeason as $s => $a) {
    printf("%-6s %8d %10d %12s\n", $s, $a['events'], $a['shooters'], number_format($a['rounds']));
    $grand['events'] += $a['events'];
    $grand['shooters'] += $a['shooters'];
    $grand['rounds'] += $a['rounds'];
}
echo str_repeat('-', 40)."\n";
printf("%-6s %8d %10d %12s\n", 'TOTAL', $grand['events'], $grand['shooters'], number_format($grand['rounds']));
echo "\nNote: shooters = entrants with a recorded score (blank = DNS/DNF, excluded).\n";
echo "2026 is a partial season (in progress).\n";
