<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use RuntimeException;

/**
 * Renders a green-on-white QR PNG via bacon-qr-code + GD (no Imagick required).
 */
class GreenQrCodePng
{
    /**
     * @return string Raw PNG binary
     */
    public function render(string $payload, int $sizePx = 240): string
    {
        $qr = Encoder::encode($payload, ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ECODING);
        $matrix = $qr->getMatrix();
        $modules = $matrix->getWidth();

        if ($modules < 1) {
            throw new RuntimeException('Failed to encode QR payload.');
        }

        $moduleSize = max(1, intdiv($sizePx, $modules));
        $imageSize = $moduleSize * $modules;

        $image = imagecreatetruecolor($imageSize, $imageSize);
        if ($image === false) {
            throw new RuntimeException('Unable to allocate QR image.');
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $green = imagecolorallocate($image, 0, 104, 56); // #006838
        imagefilledrectangle($image, 0, 0, $imageSize - 1, $imageSize - 1, $white);

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    imagefilledrectangle(
                        $image,
                        $x * $moduleSize,
                        $y * $moduleSize,
                        (($x + 1) * $moduleSize) - 1,
                        (($y + 1) * $moduleSize) - 1,
                        $green
                    );
                }
            }
        }

        ob_start();
        imagepng($image);
        imagedestroy($image);
        $png = ob_get_clean();

        if ($png === false || $png === '') {
            throw new RuntimeException('Failed to encode QR PNG.');
        }

        return $png;
    }

    public function toDataUri(string $payload, int $sizePx = 240): string
    {
        return 'data:image/png;base64,'.base64_encode($this->render($payload, $sizePx));
    }
}
