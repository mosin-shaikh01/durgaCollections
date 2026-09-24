<?php
/**
 * PHP-Scoper configuration: moves the bundled libraries under ProductQrBarcode\Vendor\.
 *
 * Only each package's src/ is scoped. Every file also gets the ABSPATH guard
 * from patcher.php. Run through build.php, not directly.
 *
 * Build-time only; never loaded by the plugin.
 *
 * @package ProductQrBarcode
 */

use Isolated\Symfony\Component\Finder\Finder;

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

return array(
	'prefix'                  => 'ProductQrBarcode\\Vendor',
	'finders'                 => array(
		Finder::create()
			->files()
			->in(
				array(
					__DIR__ . '/vendor/bacon/bacon-qr-code/src',
					__DIR__ . '/vendor/dasprid/enum/src',
					__DIR__ . '/vendor/picqer/php-barcode-generator/src',
				)
			)
			->name( '*.php' ),
	),
	'patchers'                => array(
		require __DIR__ . '/patcher.php',
	),
	'expose-global-constants' => false,
	'expose-global-classes'   => false,
	'expose-global-functions' => false,
);
