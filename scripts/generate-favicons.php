<?php

/**
 * One-shot: rebuild square favicon.png + favicon.ico from the PWA mark.
 *
 * Google Search rejects non-square favicons (our old favicon.png was 1376x768)
 * and browsers fall back to /favicon.ico which was a 0-byte stub.
 */

$srcPath = dirname(__DIR__).'/public/images/pwa/icon-512.png';
$public = dirname(__DIR__).'/public';

$src = imagecreatefrompng($srcPath);
if (! $src) {
    fwrite(STDERR, "Failed to load {$srcPath}\n");
    exit(1);
}

$sw = imagesx($src);
$sh = imagesy($src);

$makeSquarePng = function (int $size, string $out) use ($src, $sw, $sh): void {
    $dst = imagecreatetruecolor($size, $size);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $size, $size, $transparent);
    imagealphablending($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, $sw, $sh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagepng($dst, $out, 9);
    imagedestroy($dst);
};

$pngToIco = function (array $pngPaths, string $icoPath): void {
    $images = [];
    foreach ($pngPaths as $path) {
        $data = file_get_contents($path);
        $img = imagecreatefromstring($data);
        $w = imagesx($img);
        $h = imagesy($img);
        imagedestroy($img);
        $images[] = [
            'w' => $w >= 256 ? 0 : $w,
            'h' => $h >= 256 ? 0 : $h,
            'data' => $data,
            'size' => strlen($data),
        ];
    }

    $count = count($images);
    $offset = 6 + ($count * 16);
    $ico = pack('vvv', 0, 1, $count);

    foreach ($images as $image) {
        $ico .= pack('CCCCvvVV', $image['w'], $image['h'], 0, 0, 1, 32, $image['size'], $offset);
        $offset += $image['size'];
    }

    foreach ($images as $image) {
        $ico .= $image['data'];
    }

    file_put_contents($icoPath, $ico);
};

$tmp16 = $public.'/.favicon-16.png';
$tmp32 = $public.'/.favicon-32.png';
$tmp48 = $public.'/.favicon-48.png';

$makeSquarePng(192, $public.'/favicon.png');
$makeSquarePng(48, $tmp48);
$makeSquarePng(32, $tmp32);
$makeSquarePng(16, $tmp16);
$pngToIco([$tmp16, $tmp32, $tmp48], $public.'/favicon.ico');

@unlink($tmp16);
@unlink($tmp32);
@unlink($tmp48);
imagedestroy($src);

[$w, $h] = getimagesize($public.'/favicon.png');
echo "favicon.png: {$w}x{$h} ".filesize($public.'/favicon.png')." bytes\n";
echo 'favicon.ico: '.filesize($public.'/favicon.ico')." bytes\n";
