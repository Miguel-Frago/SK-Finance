<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Helpers for the QR Access ID: hashing the secret token, building the address the QR points to,
 * and drawing the QR code itself as an SVG (pure PHP, no JavaScript, no internet needed).
 */
class AccessQr
{
    /** The token is long and random, so a plain SHA-256 is enough (it cannot be guessed or brute-forced). */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Base address the phone must be able to reach, e.g. http://192.168.1.10:8000 (SK_QR_BASE_URL in .env). */
    public static function baseUrl(): string
    {
        return rtrim((string) (config('sk.qr_base_url') ?: config('app.url')), '/');
    }

    /** The full address stored inside the QR code. */
    public static function url(string $token): string
    {
        return self::baseUrl() . '/qr/' . $token;
    }

    /** True when the address only works on this PC (a phone could never open it). */
    public static function baseUrlIsPcOnly(): bool
    {
        $host = (string) parse_url(self::baseUrl(), PHP_URL_HOST);

        return $host === '' || $host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.test');
    }

    /** The QR code as inline-ready SVG markup. */
    public static function svg(string $text, int $size = 320): string
    {
        $renderer = new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd());
        $svg      = (new Writer($renderer))->writeString($text);

        // Drop the XML header line so the markup can sit inside an HTML page
        return trim((string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg));
    }
}
