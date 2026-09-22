<?php
declare(strict_types=1);

namespace Dormitory\Support;

/** Local encoder: payment payloads never leave this server to render a QR. */
final class QrPng
{
    public static function render(string $payload): string
    {
        if (!function_exists('imagepng') || !preg_match('/^[0-9A-Z.]{1,200}$/D', $payload)) {
            throw new \RuntimeException('QR renderer unavailable or invalid payment payload');
        }
        require_once __DIR__ . '/Vendor/QRCode.php';
        $qr = \Dormitory\Support\Vendor\QRCode::getMinimumQRCode($payload, QR_ERROR_CORRECT_LEVEL_M);
        // Integer-sized modules and a four-module quiet zone, no antialiasing/cropping.
        $scale = 10;
        $image = $qr->createImage($scale, 4 * $scale);
        ob_start();
        try {
            if (!imagepng($image)) throw new \RuntimeException('Cannot encode payment QR');
            $png = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if (!is_string($png) || !str_starts_with($png, "\x89PNG\r\n\x1a\n") || strlen($png) > 300_000) {
            throw new \RuntimeException('Invalid payment QR image');
        }
        return $png;
    }
}
