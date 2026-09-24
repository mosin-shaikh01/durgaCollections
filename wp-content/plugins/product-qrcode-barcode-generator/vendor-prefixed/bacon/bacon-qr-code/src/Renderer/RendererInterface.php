<?php

declare (strict_types=1);
namespace ProductQrBarcode\Vendor\BaconQrCode\Renderer;

defined( 'ABSPATH' ) || exit;

use ProductQrBarcode\Vendor\BaconQrCode\Encoder\QrCode;
interface RendererInterface
{
    public function render(QrCode $qrCode): string;
}
