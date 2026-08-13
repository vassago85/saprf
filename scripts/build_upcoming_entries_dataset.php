<?php

/**
 * One-off generator: convert the "SAPRF Upcoming Matches & Entries" workbook
 * (exported from precisionrifle.co.za) into a committed JSON dataset that the
 * `saprf:import-upcoming-entries` command consumes. Uses only PHP's built-in
 * ZipArchive + SimpleXML so it runs anywhere without PhpSpreadsheet.
 *
 * Usage:
 *   php scripts/build_upcoming_entries_dataset.php "path/to/SAPRF_Upcoming_Entries.xlsx" [out.json]
 *
 * Default output: database/data/upcoming_entries_2026.json
 */

$in = $argv[1] ?? null;
$out = $argv[2] ?? (__DIR__.'/../database/data/upcoming_entries_2026.json');

if (! $in || ! is_file($in)) {
    fwrite(STDERR, "Input xlsx not found: ".var_export($in, true)."\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($in) !== true) {
    fwrite(STDERR, "Could not open xlsx\n");
    exit(1);
}

// ── Shared strings ──
$shared = [];
if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
    $x = simplexml_load_string($ss);
    foreach ($x->si as $si) {
        if (isset($si->t)) {
            $shared[] = (string) $si->t;
        } else {
            $buf = '';
            foreach ($si->r as $r) {
                $buf .= (string) $r->t;
            }
            $shared[] = $buf;
        }
    }
}

// ── Sheet name → file map ──
$sheetNames = [];
if (($wb = $zip->getFromName('xl/workbook.xml')) !== false) {
    $wx = simplexml_load_string($wb);
    foreach ($wx->sheets->sheet as $s) {
        $sheetNames[] = (string) $s['name'];
    }
}
$sheetFiles = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $name, $m)) {
        $sheetFiles[(int) $m[1]] = $name;
    }
}
ksort($sheetFiles);
$sheetFiles = array_values($sheetFiles);

$colToIndex = function (string $ref): int {
    $letters = preg_replace('/\d+/', '', $ref);
    $n = 0;
    foreach (str_split($letters) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
};

$parseSheet = function (string $xml) use ($shared, $colToIndex): array {
    $x = simplexml_load_string($xml);
    $rowsOut = [];
    foreach ($x->sheetData->row as $row) {
        $cells = [];
        $maxCol = 0;
        foreach ($row->c as $c) {
            $ref = (string) $c['r'];
            $type = (string) $c['t'];
            $idx = $colToIndex($ref);
            if ($type === 's') {
                $val = $shared[(int) $c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string) $c->is->t;
            } else {
                $val = isset($c->v) ? (string) $c->v : '';
            }
            $cells[$idx] = trim($val);
            $maxCol = max($maxCol, $idx);
        }
        $line = [];
        for ($i = 0; $i <= $maxCol; $i++) {
            $line[] = $cells[$i] ?? '';
        }
        $rowsOut[] = $line;
    }
    return $rowsOut;
};

// Locate sheets by name.
$byName = [];
foreach ($sheetNames as $i => $label) {
    if (isset($sheetFiles[$i])) {
        $byName[$label] = $parseSheet($zip->getFromName($sheetFiles[$i]));
    }
}
$zip->close();

// ── Helpers ──
$monthMap = [
    'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04', 'may' => '05', 'jun' => '06',
    'jul' => '07', 'aug' => '08', 'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12',
];

$parseDates = function (string $text) use ($monthMap): array {
    $text = trim($text);
    // Range within a month, e.g. "29 - 30 Aug 2026".
    if (preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})\s+([A-Za-z]{3,})\s+(\d{4})$/', $text, $m)) {
        $mon = $monthMap[strtolower(substr($m[3], 0, 3))] ?? null;
        if ($mon) {
            return [
                'start' => sprintf('%s-%s-%02d', $m[4], $mon, (int) $m[1]),
                'end' => sprintf('%s-%s-%02d', $m[4], $mon, (int) $m[2]),
            ];
        }
    }
    // Single date, e.g. "15 Aug 2026".
    if (preg_match('/^(\d{1,2})\s+([A-Za-z]{3,})\s+(\d{4})$/', $text, $m)) {
        $mon = $monthMap[strtolower(substr($m[2], 0, 3))] ?? null;
        if ($mon) {
            return ['start' => sprintf('%s-%s-%02d', $m[3], $mon, (int) $m[1]), 'end' => null];
        }
    }
    return ['start' => null, 'end' => null];
};

$parseFee = function (string $v): ?int {
    $v = trim($v);
    if ($v === '' || stripos($v, 'not set') !== false) {
        return null;
    }
    $digits = preg_replace('/[^0-9]/', '', $v);
    return $digits === '' ? null : (int) $digits;
};

$matchType = function (string $name): string {
    if (stripos($name, 'PR22') !== false || stripos($name, 'Rimfire') !== false) {
        return 'PR22';
    }
    if (stripos($name, 'Centrefire') !== false) {
        return 'PRS';
    }
    return 'PR22';
};

$seriesLevel = function (string $league): ?string {
    if (stripos($league, 'National') !== false) {
        return 'national';
    }
    if (stripos($league, 'Provincial') !== false) {
        return 'provincial';
    }
    return null;
};

$divisionSlug = function (string $name): string {
    return strtolower(trim($name));
};

// ── Build matches from Summary ──
$matches = [];
foreach (($byName['Summary'] ?? []) as $row) {
    $eventId = $row[0] ?? '';
    if (! ctype_digit($eventId)) {
        continue;
    }
    $dates = $parseDates($row[2] ?? '');
    $matches[$eventId] = [
        'old_event_id' => (int) $eventId,
        'name' => $row[1] ?? '',
        'match_type' => $matchType($row[1] ?? ''),
        'series_level' => $seriesLevel($row[5] ?? ''),
        'league' => $row[5] ?? '',
        'date_text' => $row[2] ?? '',
        'match_date' => $dates['start'],
        'match_end_date' => $dates['end'],
        'venue' => $row[3] ?? '',
        'province' => $row[4] ?? '',
        'match_director' => ($row[6] ?? '') === '(not listed)' ? null : ($row[6] ?? null),
        'match_director_contact' => ($row[7] ?? '') === '(not listed)' ? null : ($row[7] ?? null),
        'entry_fee' => $parseFee($row[9] ?? ''),
        'junior_fee' => $parseFee($row[10] ?? ''),
        'event_url' => $row[12] ?? '',
    ];
}

// ── Build entrants from All Entries ──
$entrants = [];
foreach (($byName['All Entries'] ?? []) as $row) {
    $eventId = $row[0] ?? '';
    if (! ctype_digit($eventId)) {
        continue;
    }
    $saprf = trim($row[6] ?? '');
    $name = trim($row[7] ?? '');
    if ($saprf === '' || $name === '') {
        continue;
    }
    $entrants[] = [
        'old_event_id' => (int) $eventId,
        'saprf_number' => $saprf,
        'name' => $name,
        'division' => $divisionSlug($row[8] ?? ''),
        'membership_type' => strtolower(trim($row[9] ?? '')),
        'fee' => $parseFee($row[10] ?? ''),
    ];
}

$dataset = [
    'meta' => [
        'source' => 'precisionrifle.co.za upcoming entries export',
        'generated_at' => date('c'),
        'match_count' => count($matches),
        'entrant_count' => count($entrants),
    ],
    'matches' => array_values($matches),
    'entrants' => $entrants,
];

@mkdir(dirname($out), 0777, true);
file_put_contents($out, json_encode($dataset, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

echo "Wrote {$out}\n";
echo "Matches:  ".count($matches)."\n";
echo "Entrants: ".count($entrants)."\n";
