<?php

declare (strict_types=1);
namespace ProductQrBarcode\Vendor\BaconQrCode\Renderer\Color;

defined( 'ABSPATH' ) || exit;

interface ColorInterface
{
    /**
     * Converts the color to RGB.
     */
    public function toRgb(): Rgb;
    /**
     * Converts the color to CMYK.
     */
    public function toCmyk(): Cmyk;
    /**
     * Converts the color to gray.
     */
    public function toGray(): Gray;
}
