<?php
/**
 * Admin settings screen: WooCommerce > QR & Barcodes.
 *
 * Administrators only (Permissions::MANAGE_SETTINGS). Shop Managers and Store
 * Sellers get neither the menu item nor the page, including by direct URL.
 *
 * Uses the Settings API: the form posts to options.php, which checks the
 * `pqbg_settings-options` nonce and, through the option_page_capability
 * filter below, the MANAGE_SETTINGS capability. Values are cleaned by
 * Settings::sanitize(), which only changes the fields on this form.
 *
 * Also shows the scan URL warnings (local address, or http:// on a public host) to users who can manage settings.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * Settings page and admin notice.
 */
final class SettingsPage {

	const SLUG    = 'pqbg-settings';
	const SECTION = 'pqbg_codes_section';

	/** Settings API option group; equals the option name so options.php uses "pqbg_settings-options" as the nonce action. */
	const GROUP = Plugin::SETTINGS_OPTION;

	/**
	 * Hooks the screen, the setting and the notice. Called on admin requests only.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_filter( 'option_page_capability_' . self::GROUP, array( __CLASS__, 'capability' ) );
		add_action( 'admin_notices', array( __CLASS__, 'scan_url_notice' ) );
	}

	/**
	 * Capability options.php requires to save this group.
	 */
	public static function capability(): string {
		return Permissions::MANAGE_SETTINGS;
	}

	/**
	 * Adds the page under the WooCommerce menu.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Product QR Code and Barcode Generator', 'product-qrcode-barcode-generator' ),
			__( 'QR & Barcodes', 'product-qrcode-barcode-generator' ),
			Permissions::MANAGE_SETTINGS,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Registers the option, section and fields with the Settings API.
	 */
	public static function register_setting(): void {
		register_setting(
			self::GROUP,
			Plugin::SETTINGS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);

		add_settings_section( self::SECTION, __( 'QR codes and barcodes', 'product-qrcode-barcode-generator' ), array( __CLASS__, 'render_section' ), self::SLUG );

		add_settings_field(
			'pqbg_barcodes_enabled',
			__( 'Enable barcodes (for hardware scanners)', 'product-qrcode-barcode-generator' ),
			array( __CLASS__, 'render_barcodes_field' ),
			self::SLUG,
			self::SECTION,
			array( 'label_for' => 'pqbg_barcodes_enabled' )
		);

		add_settings_field(
			'pqbg_scan_base_url',
			__( 'Scan base URL', 'product-qrcode-barcode-generator' ),
			array( __CLASS__, 'render_base_url_field' ),
			self::SLUG,
			self::SECTION,
			array( 'label_for' => 'pqbg_scan_base_url' )
		);
	}

	/**
	 * Renders the page.
	 */
	public static function render(): void {
		if ( ! Permissions::can_manage_settings() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'product-qrcode-barcode-generator' ), 403 );
		}

		echo '<div class="wrap"><h1>' . esc_html( get_admin_page_title() ) . '</h1>';

		// Pages outside Settings must print Settings API messages themselves. No filter:
		// options.php files "Settings saved." under "general", validation errors under pqbg_settings.
		settings_errors();

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( self::GROUP );
		do_settings_sections( self::SLUG );
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Section intro.
	 */
	public static function render_section(): void {
		echo '<p>' . esc_html__( 'QR codes are always generated and are the main way to scan a product with a phone camera. A QR code contains only the scan URL for the product code, never product details.', 'product-qrcode-barcode-generator' ) . '</p>';
	}

	/**
	 * Barcode checkbox. The hidden field makes an unchecked box submit an explicit "0".
	 */
	public static function render_barcodes_field(): void {
		$name = Plugin::SETTINGS_OPTION . '[barcodes_enabled]';

		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />';
		echo '<label><input type="checkbox" id="pqbg_barcodes_enabled" name="' . esc_attr( $name ) . '" value="1"' . checked( Settings::is_barcode_enabled(), true, false ) . ' /> ';
		echo esc_html__( 'Also produce Code 128 barcodes', 'product-qrcode-barcode-generator' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Off by default. Turn this on only if staff use hardware barcode scanners. The barcode holds the same product code as the QR code, so no codes need to be regenerated.', 'product-qrcode-barcode-generator' ) . '</p>';
	}

	/**
	 * Scan base URL input, with the effective URL and an example.
	 */
	public static function render_base_url_field(): void {
		$stored = Settings::get()['scan_base_url'];
		$stored = is_string( $stored ) ? $stored : '';

		echo '<input type="url" class="regular-text code" id="pqbg_scan_base_url" name="' . esc_attr( Plugin::SETTINGS_OPTION . '[scan_base_url]' ) . '"'
			. ' value="' . esc_attr( $stored ) . '" maxlength="' . esc_attr( (string) Settings::MAX_URL_LENGTH ) . '"'
			. ' placeholder="' . esc_attr( untrailingslashit( home_url() ) ) . '" />';

		echo '<p class="description">' . esc_html__( 'Leave empty to use the site URL. Otherwise enter an absolute http:// or https:// address; any trailing slash, query string or fragment is removed. Codes are stored, URLs are not, so changing this never changes any product code.', 'product-qrcode-barcode-generator' ) . '</p>';

		echo '<p>' . esc_html__( 'Effective scan base URL:', 'product-qrcode-barcode-generator' ) . ' <code>' . esc_html( ScanUrl::base() ) . '</code>';
		echo Settings::has_scan_base_url_override() ? '' : ' ' . esc_html__( '(site URL)', 'product-qrcode-barcode-generator' );
		echo '<br />' . esc_html__( 'QR codes will contain:', 'product-qrcode-barcode-generator' ) . ' <code>' . esc_html( ScanUrl::example() ) . '</code></p>';
	}

	/**
	 * Warns users who can manage settings about the effective scan base URL.
	 *
	 * At most one notice is shown: the local-address warning takes priority;
	 * otherwise a public host on plain http:// gets the https warning. Both are
	 * warnings only; neither blocks saving.
	 */
	public static function scan_url_notice(): void {
		if ( ! Permissions::can_manage_settings() ) {
			return;
		}

		$base = ScanUrl::base();

		if ( Settings::is_local_url( $base ) ) {
			$message = __( 'QR codes currently point to a local address. Do not print labels until the production URL is set.', 'product-qrcode-barcode-generator' );
		} elseif ( Settings::is_http_url( $base ) ) {
			$message = __( 'Labels should use an https:// scan URL in production.', 'product-qrcode-barcode-generator' );
		} else {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Product QR Code and Barcode Generator:', 'product-qrcode-barcode-generator' ) . '</strong> ';
		echo esc_html( $message );
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Scan base URL settings', 'product-qrcode-barcode-generator' ) . '</a></p></div>';
	}
}
