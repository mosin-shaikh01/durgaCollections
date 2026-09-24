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

		if ( is_admin() ) {
			SettingsPage::register();
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
	 *
	 * @return array<string, mixed>
	 */
	public static function default_settings(): array {
		return array(
			'settings_version' => 1,
			'barcodes_enabled' => false,
			'scan_base_url'    => '',
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
