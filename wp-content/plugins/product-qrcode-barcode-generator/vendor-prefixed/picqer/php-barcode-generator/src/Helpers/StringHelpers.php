<?php

namespace ProductQrBarcode\Vendor\Picqer\Barcode\Helpers;

defined( 'ABSPATH' ) || exit;

class StringHelpers
{
    public static function getSafeFilenameFrom(string $className): string
    {
        $lastPart = substr($className, strrpos($className, '\\') + 1);
        $safeFilename = preg_replace('/[^a-zA-Z0-9_ \-+]/s', '-', $lastPart);
        if ($safeFilename === null) {
            throw new \RuntimeException('Could not sanitize filename.');
        }
        return $safeFilename;
    }
}
