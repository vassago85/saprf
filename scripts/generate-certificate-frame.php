<?php

/**
 * Generate a print-quality A4 certificate frame PNG (transparent):
 * gold outer frame + mil ticks + corner brackets + faint green reticle.
 *
 * Usage: php scripts/generate-certificate-frame.php
 */

$out = __DIR__.'/../public/images/certificates/saprf-frame-a4.png';
@mkdir(dirname($out), 0775, true);

// 150dpi keeps DomPDF memory reasonable while staying sharp enough in print.
$w = 1240; // 210mm @ 150dpi
$h = 1754; // 297mm @ 150dpi
$mm = $w / 210.0;

$img = imagecreatetruecolor($w, $h);
imagesavealpha($img, true);
imagealphablending($img, false);
$transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $transparent);
imagealphablending($img, true);

$gold = imagecolorallocatealpha($img, 201, 151, 28, 0);      // #C9971C
$green = imagecolorallocatealpha($img, 0, 104, 56, 110);     // #006838 ~15% (alpha 110/127)
$greenHair = imagecolorallocatealpha($img, 0, 104, 56, 64);  // ~50%

$outerInset = (int) round(8 * $mm);
$innerInset = (int) round(10.6 * $mm);
$outerX1 = $outerInset;
$outerY1 = $outerInset;
$outerX2 = $w - $outerInset - 1;
$outerY2 = $h - $outerInset - 1;

// Outer gold rect (approx 0.55mm stroke)
$stroke = max(2, (int) round(0.55 * $mm));
imagerectangleThick($img, $outerX1, $outerY1, $outerX2, $outerY2, $gold, $stroke);

// Inner green hairline
$hair = max(1, (int) round(0.35 * $mm));
imagerectangleThick($img, $innerInset, $innerInset, $w - $innerInset - 1, $h - $innerInset - 1, $greenHair, $hair);

// Mil ticks
$minor = (int) round(1.6 * $mm);
$major = (int) round(2.4 * $mm);
$tickW = max(1, (int) round(0.22 * $mm));

for ($i = 0; $i <= 194; $i += 10) {
    $len = ($i % 50 === 0) ? $major : $minor;
    $x = $outerX1 + (int) round($i * $mm);
    imagelineThick($img, $x, $outerY1, $x, $outerY1 + $len, $gold, $tickW);
    imagelineThick($img, $x, $outerY2, $x, $outerY2 - $len, $gold, $tickW);
}
for ($i = 0; $i <= 281; $i += 10) {
    $len = ($i % 50 === 0) ? $major : $minor;
    $y = $outerY1 + (int) round($i * $mm);
    imagelineThick($img, $outerX1, $y, $outerX1 + $len, $y, $gold, $tickW);
    imagelineThick($img, $outerX2, $y, $outerX2 - $len, $y, $gold, $tickW);
}

// Corner L-brackets
$bracket = (int) round(9 * $mm);
$bStroke = max(2, (int) round(0.9 * $mm));
// TL
imagelineThick($img, $outerX1, $outerY1 + $bracket, $outerX1, $outerY1, $gold, $bStroke);
imagelineThick($img, $outerX1, $outerY1, $outerX1 + $bracket, $outerY1, $gold, $bStroke);
// TR
imagelineThick($img, $outerX2 - $bracket, $outerY1, $outerX2, $outerY1, $gold, $bStroke);
imagelineThick($img, $outerX2, $outerY1, $outerX2, $outerY1 + $bracket, $gold, $bStroke);
// BL
imagelineThick($img, $outerX1, $outerY2 - $bracket, $outerX1, $outerY2, $gold, $bStroke);
imagelineThick($img, $outerX1, $outerY2, $outerX1 + $bracket, $outerY2, $gold, $bStroke);
// BR
imagelineThick($img, $outerX2 - $bracket, $outerY2, $outerX2, $outerY2, $gold, $bStroke);
imagelineThick($img, $outerX2, $outerY2, $outerX2, $outerY2 - $bracket, $gold, $bStroke);

// Reticle watermark centred near member-name area (~105mm, 118mm)
$cx = (int) round(105 * $mm);
$cy = (int) round(118 * $mm);
$r1 = (int) round(52 * $mm);
$r2 = (int) round(34 * $mm);
$reticleStroke = max(1, (int) round(0.28 * $mm));
imageellipseThick($img, $cx, $cy, $r1 * 2, $r1 * 2, $green, $reticleStroke);
imageellipseThick($img, $cx, $cy, $r2 * 2, $r2 * 2, $green, $reticleStroke);

$gap = (int) round(8 * $mm);
imagelineThick($img, $cx - $r1, $cy, $cx - $gap, $cy, $green, $reticleStroke);
imagelineThick($img, $cx + $gap, $cy, $cx + $r1, $cy, $green, $reticleStroke);
imagelineThick($img, $cx, $cy - $r1, $cx, $cy - $gap, $green, $reticleStroke);
imagelineThick($img, $cx, $cy + $gap, $cx, $cy + $r1, $green, $reticleStroke);

$hash = (int) round(2.2 * $mm);
foreach ([-40, -20, 20, 40] as $m) {
    $d = (int) round($m * $mm);
    imagelineThick($img, $cx + $d, $cy - $hash, $cx + $d, $cy + $hash, $green, $reticleStroke);
    imagelineThick($img, $cx - $hash, $cy + $d, $cx + $hash, $cy + $d, $green, $reticleStroke);
}

imagepng($img, $out, 6);
imagedestroy($img);

echo "Wrote {$out} (".filesize($out)." bytes) {$w}x{$h}\n";

function imagerectangleThick($img, int $x1, int $y1, int $x2, int $y2, $color, int $t): void
{
    for ($i = 0; $i < $t; $i++) {
        imagerectangle($img, $x1 + $i, $y1 + $i, $x2 - $i, $y2 - $i, $color);
    }
}

function imagelineThick($img, int $x1, int $y1, int $x2, int $y2, $color, int $t): void
{
    if ($t <= 1) {
        imageline($img, $x1, $y1, $x2, $y2, $color);

        return;
    }

    imagesetthickness($img, $t);
    imageline($img, $x1, $y1, $x2, $y2, $color);
    imagesetthickness($img, 1);
}

function imageellipseThick($img, int $cx, int $cy, int $w, int $h, $color, int $t): void
{
    for ($i = 0; $i < $t; $i++) {
        imageellipse($img, $cx, $cy, max(1, $w - $i * 2), max(1, $h - $i * 2), $color);
    }
}
