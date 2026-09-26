<?php
/**
 * Runtime bootstrap and settings access.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin once all plugins are loaded.
 */
final class Plugin {

	const SETTINGS_OPTION = 'pqbg_settings';

	/**
	 * Runs on `plugins_loaded`. Does nothing beyond an admin notice when requirements are unmet.
	 */
	public static function boot(): void {
		if ( ! Requirements::met() ) {
			Requirements::register_notice();
			return;
		}

		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );

		Install::maybe_upgrade();

		// Every request: products are also saved over REST, by the importer and by cron.
		CodeLifecycle::register();

		// Cost price: kept out of WooCommerce meta_data, REST, exports and imports on every request (Phase 9A).
		CostPrice::register();

		// Front-end scan page /scan/{CODE}/ and /scan/my-sales/, and the login redirects back to it.
		ScanRoute::register();

		if ( is_admin() ) {
			ScanRoute::register_admin();
			SettingsPage::register();
			AdminProductPanel::register();
			AdminActions::register();

			// Label printing: setup screen, bulk action and the print page (admin-post.php only).
			PrintAdmin::register();
			PrintPage::register();

			// In-store sales history, sale detail, void and CSV (Phase 9A); cost price fields.
			SalesAdmin::register();
			CostPrice::register_admin();
		}
	}

	/**
	 * Loads translations from the plugin's languages directory.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'product-qrcode-barcode-generator', false, dirname( plugin_basename( PQBG_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Default settings. Later phases add keys here; stored values for unknown keys are ignored.
	 * New keys need no migration because stored settings are always merged over these defaults.
	 *
	 * - barcodes_enabled: render Code 128 barcodes for hardware scanners (QR codes are always on).
	 * - scan_base_url:    absolute base for scan URLs; '' means use home_url(). See Settings.
	 * - payment_methods:  payment methods the sale form offers (Phase 9A). See PaymentMethods.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_settings(): array {
		return array(
			'settings_version' => 1,
			'barcodes_enabled' => false,
			'scan_base_url'    => '',
			'payment_methods'  => PaymentMethods::DEFAULT_ENABLED,
		);
	}

	/**
	 * Stored settings merged over the defaults, restricted to known keys.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		$stored = get_option( self::SETTINGS_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$defaults = self::default_settings();

		return array_intersect_key( wp_parse_args( $stored, $defaults ), $defaults );
	}
}
