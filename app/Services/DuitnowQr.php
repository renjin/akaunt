<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class DuitnowQr
{
    /** SVG markup for the company's DuitNow QR payload, sized for the invoice PDF. */
    public static function svg(string $payload, int $size = 110): string
    {
        $renderer = new ImageRenderer(new RendererStyle($size, 0), new SvgImageBackEnd());

        return (new Writer($renderer))->writeString($payload);
    }

    /** data: URI form, embeddable in dompdf <img> tags. */
    public static function dataUri(string $payload, int $size = 110): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::svg($payload, $size));
    }
}
