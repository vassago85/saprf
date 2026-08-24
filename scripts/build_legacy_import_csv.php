<?php

$root = dirname(__DIR__);
$inPath = $root.'/database/data/legacy-full-member-expiries.csv';
$outPath = $root.'/database/data/legacy-full-member-import.csv';

$in = fopen($inPath, 'r');
if (!$in) {
    fwrite(STDERR, "Cannot open {$inPath}\n");
    exit(1);
}

$out = fopen($outPath, 'w');
if (!$out) {
    fwrite(STDERR, "Cannot write {$outPath}\n");
    exit(1);
}

fgetcsv($in);
fputcsv($out, [
    'first_name', 'surname', 'email', 'sa_id_number', 'saprf_number',
    'membership_type', 'status', 'payment_status', 'start_date', 'expiry_date',
]);

$count = 0;
while (($row = fgetcsv($in)) !== false) {
    if (count($row) < 7) {
        continue;
    }
    [$no, $first, $surname, $email, $saId, $start, $expiry] = $row;
    fputcsv($out, [
        trim($first),
        trim($surname),
        trim($email),
        trim($saId),
        trim($no),
        'paid',
        'active',
        'paid',
        trim($start),
        trim($expiry),
    ]);
    $count++;
}

fclose($in);
fclose($out);

echo "Wrote {$count} rows to {$outPath}\n";
