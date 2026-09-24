<?php
/**
 * Plugin Name:          Product QR Code and Barcode Generator
 * Description:          Product QR/barcode inventory for Durga Collections: secure product codes, QR code and optional barcode rendering, seller role and capabilities.
 * Version:              0.1.0
 * Author:               Durga Collections
 * Text Domain:          product-qrcode-barcode-generator
 * Domain Path:          /languages
 * Requires at least:    6.7
 * Requires PHP:         8.2
 * Requires Plugins:     woocommerce
 * WC requires at least: 9.0
 * WC tested up to:      11.1
 * License:              GPL-2.0-or-later
 * Update URI:           false
 *
 * Update URI: the plugin name and slug are generic, so this stops WordPress
 * from ever offering a wordpress.org plugin with the same slug as an update.
 * @package ProductQrBarcode
 */

defined( 'ABSPATH' ) || exit;

define( 'PQBG_VERSION', '0.1.0' );
define( 'PQBG_PLUGIN_FILE', __FILE__ );
define( 'PQBG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PQBG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * PSR-4 style autoloader: ProductQrBarcode\Foo => includes/Foo.php.
 * Only simple class names are accepted so a class string can never be
 * turned into an arbitrary include path.
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'ProductQrBarcode\\';

		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}

		/*
		 * Bundled libraries, scoped under ProductQrBarcode\Vendor\ by build/build.php.
		 * PHP only autoloads a class when it is first used, so a library is never
		 * loaded unless a renderer actually needs it.
		 */
		$vendor = array(
			'Vendor\\BaconQrCode\\'     => 'vendor-prefixed/bacon/bacon-qr-code/src/',
			'Vendor\\DASPRiD\\Enum\\'   => 'vendor-prefixed/dasprid/enum/src/',
			'Vendor\\Picqer\\Barcode\\' => 'vendor-prefixed/picqer/php-barcode-generator/src/',
		);

		$relative = substr( $class_name, strlen( $prefix ) );
		$base     = 'includes/';

		foreach ( $vendor as $namespace => $dir ) {
			if ( 0 === strncmp( $relative, $namespace, strlen( $namespace ) ) ) {
				$relative = substr( $relative, strlen( $namespace ) );
				$base     = $dir;
				break;
			}
		}

		if ( ! preg_match( '/^[A-Za-z0-9_]+(\\\\[A-Za-z0-9_]+)*$/', $relative ) ) {
			return;
		}

		$file = PQBG_PLUGIN_DIR . $base . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'ProductQrBarcode\\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ProductQrBarcode\\Install', 'deactivate' ) );

/*
 * Declare HPOS compatibility. The plugin never touches orders directly, but
 * WooCommerce treats undeclared plugins as incompatible with HPOS.
 * Feature ID verified against WooCommerce 11.1.2
 * (src/Internal/DataStores/Orders/CustomOrdersTableController.php).
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PQBG_PLUGIN_FILE, true );
		}
	}
);

// Boot after all plugins are loaded: this file loads before WooCommerce alphabetically.
add_action( 'plugins_loaded', array( 'ProductQrBarcode\\Plugin', 'boot' ) );
