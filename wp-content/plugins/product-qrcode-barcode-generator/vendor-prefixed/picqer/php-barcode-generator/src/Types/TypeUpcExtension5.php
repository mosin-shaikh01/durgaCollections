<?php

namespace ProductQrBarcode\Vendor\Picqer\Barcode\Types;

defined( 'ABSPATH' ) || exit;

/*
 * UPC-Based Extensions
 * 2-Digit Ext.: Used to indicate magazines and newspaper issue numbers
 * 5-Digit Ext.: Used to mark suggested retail price of books
 */
class TypeUpcExtension5 extends TypeUpcExtension2
{
    protected int $length = 5;
}
