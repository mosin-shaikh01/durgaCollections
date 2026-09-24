<?php

namespace ProductQrBarcode\Vendor\Picqer\Barcode\Types;

defined( 'ABSPATH' ) || exit;

use ProductQrBarcode\Vendor\Picqer\Barcode\Barcode;
interface TypeInterface
{
    public function getBarcode(string $code): Barcode;
}
