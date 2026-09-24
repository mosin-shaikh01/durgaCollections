<?php
/**
 * Environment requirement checks.
 *
 * Kept free of newer PHP syntax so it can still report a too-old PHP version
 * instead of failing to parse.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * Checks WordPress, PHP and WooCommerce versions against the supported minimums.
 */
class Requirements {

	const MIN_WP  = '6.7';
	const MIN_PHP = '8.2';
	const MIN_WC  = '9.0';

	/**
	 * Returns human-readable reasons the environment is unsupported (empty when all are met).
	 *
	 * @return string[]
	 */
	public static function errors() {
		global $wp_version;

		$errors = array();

		if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) ) {
			/* translators: 1: required PHP version, 2: current PHP version. */
			$errors[] = sprintf( __( 'Product QR Code and Barcode Generator requires PHP %1$s or newer. This site runs PHP %2$s.', 'product-qrcode-barcode-generator' ), self::MIN_PHP, PHP_VERSION );
		}

		if ( version_compare( $wp_version, self::MIN_WP, '<' ) ) {
			/* translators: 1: required WordPress version, 2: current WordPress version. */
			$errors[] = sprintf( __( 'Product QR Code and Barcode Generator requires WordPress %1$s or newer. This site runs WordPress %2$s.', 'product-qrcode-barcode-generator' ), self::MIN_WP, $wp_version );
		}

		if ( ! self::woocommerce_version() ) {
			$errors[] = __( 'Product QR Code and Barcode Generator requires WooCommerce to be installed and active.', 'product-qrcode-barcode-generator' );
		} elseif ( version_compare( self::woocommerce_version(), self::MIN_WC, '<' ) ) {
			/* translators: 1: required WooCommerce version, 2: current WooCommerce version. */
			$errors[] = sprintf( __( 'Product QR Code and Barcode Generator requires WooCommerce %1$s or newer. This site runs WooCommerce %2$s.', 'product-qrcode-barcode-generator' ), self::MIN_WC, self::woocommerce_version() );
		}

		return $errors;
	}

	/**
	 * Whether every requirement is met.
	 *
	 * @return bool
	 */
	public static function met() {
		return array() === self::errors();
	}

	/**
	 * Active WooCommerce version, or empty string when WooCommerce is not loaded.
	 *
	 * @return string
	 */
	public static function woocommerce_version() {
		return ( defined( 'WC_VERSION' ) && class_exists( 'WooCommerce', false ) ) ? (string) WC_VERSION : '';
	}

	/**
	 * Hooks an admin notice listing unmet requirements.
	 */
	public static function register_notice() {
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	/**
	 * Renders the notice for users who can manage plugins.
	 */
	public static function render_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$errors = self::errors();

		if ( array() === $errors ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Product QR Code and Barcode Generator is inactive.', 'product-qrcode-barcode-generator' ) . '</strong></p><ul>';
		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}
		echo '</ul></div>';
	}
}
