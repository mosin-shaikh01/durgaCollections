<?php
/**
 * QR & Barcodes → Settings (Phase 4; its own page since Phase 10B): admin.php?page=pqbg-settings.
 *
 * Needs Permissions::MANAGE_SETTINGS (administrators). Until Phase 10B this page was
 * WooCommerce → QR & Barcodes with the tabs Settings | Code tools | Import cost prices;
 * the tools are now QR & Barcodes → Bulk tools (ToolsAdmin), and the old ?tab= addresses
 * redirect there (AdminMenu). A shop manager asking for Settings gets 403.
 *
 * Settings uses the Settings API: the form posts to options.php, which checks the
 * `pqbg_settings-options` nonce and, through the option_page_capability
 * filter below, the MANAGE_SETTINGS capability. Values are cleaned by
 * Settings::sanitize(), which only changes the fields on this form.
 *
 * Sections: QR codes and barcodes; In-store sales; Receipts and UPI payment QR (Phase 16,
 * with a notice when the UPI QR is set up but not shown, and why).
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

	const SLUG    = AdminUrl::SETTINGS;
	const SECTION = 'pqbg_codes_section';

	/** Phase 9A: in-store sales settings. */
	const SALES_SECTION = 'pqbg_sales_section';

	/** Phase 16: receipts and the UPI payment QR. */
	const RECEIPT_SECTION = 'pqbg_receipt_section';
	const UPI_SECTION     = 'pqbg_upi_section';

	/** Settings API option group; equals the option name so options.php uses "pqbg_settings-options" as the nonce action. */
	const GROUP = Plugin::SETTINGS_OPTION;

	/**
	 * Hooks the screen, the setting and the notice. Called on admin requests only.
	 */
	public static function register(): void {
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
	 * load-{page}: administrators only (403 before any output). The only tab is Health check
	 * (Phase 11, &tab=health); any other tab left in the address is unknown (the Phase 10 tabs
	 * redirect in AdminMenu before this runs) and gets 404.
	 * Expired cost import previews are pruned here too, as on every Phase 10 tab.
	 */
	public static function load(): void {
		if ( ! Permissions::can_manage_settings() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 403 ) );
		}

		if ( null === self::tab() ) {
			wp_die( esc_html__( 'This page does not exist.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 404 ) );
		}

		CostImport::prune();
	}

	/**
	 * The requested tab: '' (Settings) or HealthCheckAdmin::TAB; null for anything else.
	 */
	public static function tab(): ?string {
		$tab = isset( $_GET['tab'] ) ? $_GET['tab'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- navigation only; only compared with fixed strings.

		return '' === $tab || HealthCheckAdmin::TAB === $tab ? $tab : null;
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

		add_settings_field(
			'pqbg_code_prefix',
			__( 'Code prefix', 'product-qrcode-barcode-generator' ),
			array( __CLASS__, 'render_code_prefix_field' ),
			self::SLUG,
			self::SECTION,
			array( 'label_for' => 'pqbg_code_prefix' )
		);

		add_settings_section( self::SALES_SECTION, __( 'In-store sales', 'product-qrcode-barcode-generator' ), '__return_null', self::SLUG );

		add_settings_field(
			'pqbg_payment_methods',
			__( 'Payment methods offered', 'product-qrcode-barcode-generator' ),
			array( __CLASS__, 'render_payment_methods_field' ),
			self::SLUG,
			self::SALES_SECTION
		);

		add_settings_section( self::RECEIPT_SECTION, __( 'Receipts', 'product-qrcode-barcode-generator' ), array( __CLASS__, 'render_receipt_section' ), self::SLUG );

		$receipt_fields = array(
			'receipt_shop_name'   => __( 'Shop name', 'product-qrcode-barcode-generator' ),
			'receipt_address'     => __( 'Address', 'product-qrcode-barcode-generator' ),
			'receipt_phone'       => __( 'Phone', 'product-qrcode-barcode-generator' ),
			'receipt_gstin'       => __( 'GSTIN (optional)', 'product-qrcode-barcode-generator' ),
			'receipt_footer'      => __( 'Footer text', 'product-qrcode-barcode-generator' ),
			'receipt_show_seller' => __( 'Seller on receipts', 'product-qrcode-barcode-generator' ),
			'receipt_paper'       => __( 'Default receipt paper', 'product-qrcode-barcode-generator' ),
		);

		foreach ( $receipt_fields as $key => $title ) {
			add_settings_field( 'pqbg_' . $key, $title, array( __CLASS__, 'render_receipt_field' ), self::SLUG, self::RECEIPT_SECTION, array( 'label_for' => 'pqbg_' . $key, 'key' => $key ) );
		}

		add_settings_section( self::UPI_SECTION, __( 'UPI payment QR', 'product-qrcode-barcode-generator' ), array( __CLASS__, 'render_upi_section' ), self::SLUG );

		add_settings_field( 'pqbg_upi_id', __( 'UPI ID', 'product-qrcode-barcode-generator' ), array( __CLASS__, 'render_upi_field' ), self::SLUG, self::UPI_SECTION, array( 'label_for' => 'pqbg_upi_id', 'key' => 'upi_id' ) );
		add_settings_field( 'pqbg_upi_payee_name', __( 'Payee name', 'product-qrcode-barcode-generator' ), array( __CLASS__, 'render_upi_field' ), self::SLUG, self::UPI_SECTION, array( 'label_for' => 'pqbg_upi_payee_name', 'key' => 'upi_payee_name' ) );
	}

	/**
	 * Receipts section intro (Phase 16).
	 */
	public static function render_receipt_section(): void {
		echo '<p>' . esc_html__( 'Sellers can print or share a receipt for each in-store sale. This is a receipt, not a GST tax invoice. Cost and profit never appear on it.', 'product-qrcode-barcode-generator' ) . '</p>';
	}

	/**
	 * One receipt field (Phase 16).
	 *
	 * @param array{key: string} $args Field arguments.
	 */
	public static function render_receipt_field( array $args ): void {
		$key      = $args['key'];
		$settings = Settings::get();
		$name     = Plugin::SETTINGS_OPTION . '[' . $key . ']';
		$id       = 'pqbg_' . $key;
		$value    = is_string( $settings[ $key ] ?? null ) ? $settings[ $key ] : '';

		switch ( $key ) {
			case 'receipt_address':
			case 'receipt_footer':
				list( $max, $lines ) = Settings::RECEIPT_TEXTS[ $key ];
				echo '<textarea class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="' . esc_attr( (string) $lines ) . '" maxlength="' . esc_attr( (string) $max ) . '">' . esc_textarea( $value ) . '</textarea>';
				/* translators: 1: maximum number of lines, 2: maximum number of characters. */
				echo '<p class="description">' . esc_html( sprintf( __( 'Up to %1$d lines, %2$d characters.', 'product-qrcode-barcode-generator' ), $lines, $max ) ) . ( 'receipt_footer' === $key ? ' ' . esc_html__( 'For example: "Thank you for shopping with us." or "Prices include GST."', 'product-qrcode-barcode-generator' ) : '' ) . '</p>';
				break;

			case 'receipt_show_seller':
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />';
				echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( true === $settings['receipt_show_seller'], true, false ) . ' /> ';
				echo esc_html__( 'Show the seller\'s first name on receipts', 'product-qrcode-barcode-generator' ) . '</label>';
				break;

			case 'receipt_paper':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
				foreach ( Receipt::papers() as $paper => $label ) {
					echo '<option value="' . esc_attr( $paper ) . '"' . selected( Receipt::default_paper(), $paper, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select><p class="description">' . esc_html__( 'The layout a receipt opens with; the seller can switch on the receipt page. Choose 80 mm or 58 mm for a thermal receipt printer.', 'product-qrcode-barcode-generator' ) . '</p>';
				break;

			default:
				$max = 'receipt_phone' === $key ? Settings::PHONE_MAX : ( 'receipt_gstin' === $key ? 15 : Settings::RECEIPT_TEXTS['receipt_shop_name'][0] );
				echo '<input type="text" class="regular-text' . ( 'receipt_gstin' === $key ? ' code' : '' ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" maxlength="' . esc_attr( (string) $max ) . '"' . ( 'receipt_shop_name' === $key ? ' placeholder="' . esc_attr( html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) ) . '"' : '' ) . ( 'receipt_phone' === $key ? ' inputmode="tel"' : '' ) . ( 'receipt_gstin' === $key ? ' autocapitalize="characters" spellcheck="false"' : '' ) . ' />';

				if ( 'receipt_shop_name' === $key ) {
					echo '<p class="description">' . esc_html__( 'Leave empty to use the site title.', 'product-qrcode-barcode-generator' ) . '</p>';
				} elseif ( 'receipt_gstin' === $key ) {
					echo '<p class="description">' . esc_html__( 'Printed only when set. The last character is checked.', 'product-qrcode-barcode-generator' ) . '</p>';
				}
		}
	}

	/**
	 * UPI section intro with the current state (Phase 16).
	 */
	public static function render_upi_section(): void {
		echo '<p>' . esc_html__( 'When the seller chooses UPI, the sell screen shows a payment QR with the amount for the customer to scan, and the seller confirms the sale after checking the customer\'s payment success screen. The plugin cannot see whether a payment arrived. Leave both fields empty to turn this off.', 'product-qrcode-barcode-generator' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'A business (merchant) UPI ID is recommended: some UPI apps limit or warn about payments with an amount to personal UPI IDs.', 'product-qrcode-barcode-generator' ) . '</p>';

		if ( ! UpiPayment::is_configured() ) {
			return;
		}

		$reason = UpiPayment::inactive_reason();

		echo '' === $reason
			? '<div class="notice notice-success inline"><p>' . esc_html__( 'The UPI payment QR is on.', 'product-qrcode-barcode-generator' ) . '</p></div>'
			: '<div class="notice notice-warning inline pqbg-upi-inactive"><p>' . esc_html__( 'The UPI payment QR is set up but not shown:', 'product-qrcode-barcode-generator' ) . ' ' . esc_html( $reason ) . '</p></div>';
	}

	/**
	 * UPI ID or payee name input (Phase 16).
	 *
	 * @param array{key: string} $args Field arguments.
	 */
	public static function render_upi_field( array $args ): void {
		$key   = $args['key'];
		$value = 'upi_id' === $key ? UpiPayment::id() : UpiPayment::payee();

		echo '<input type="text" class="regular-text' . ( 'upi_id' === $key ? ' code' : '' ) . '" id="' . esc_attr( 'pqbg_' . $key ) . '" name="' . esc_attr( Plugin::SETTINGS_OPTION . '[' . $key . ']' ) . '" value="' . esc_attr( $value ) . '" maxlength="' . ( 'upi_id' === $key ? '320' : '50' ) . '" autocomplete="off" spellcheck="false" />';
		echo '<p class="description">' . ( 'upi_id' === $key
			? esc_html__( 'For example: shopname@okaxis. Shown only in the QR and on the sell screen.', 'product-qrcode-barcode-generator' )
			: esc_html__( 'The name the customer\'s UPI app shows: English letters, digits, spaces and . & \' - (2 to 50 characters).', 'product-qrcode-barcode-generator' ) ) . '</p>';
	}

	/**
	 * Renders the page.
	 */
	public static function render(): void {
		if ( ! Permissions::can_manage_settings() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'product-qrcode-barcode-generator' ), 403 );
		}

		$tab = (string) self::tab();

		echo '<div class="wrap">';
		AdminMenu::render_nav( AdminUrl::SETTINGS );
		echo '<h1>' . esc_html__( 'Settings', 'product-qrcode-barcode-generator' ) . '</h1>';

		// The page's own tabs (second row), as on Bulk tools.
		$tabs = array(
			''                    => array( __( 'Settings', 'product-qrcode-barcode-generator' ), AdminUrl::settings() ),
			HealthCheckAdmin::TAB => array( __( 'Health check', 'product-qrcode-barcode-generator' ), AdminUrl::health() ),
		);
		echo '<nav class="nav-tab-wrapper wp-clearfix pqbg-page-tabs" aria-label="' . esc_attr__( 'Settings', 'product-qrcode-barcode-generator' ) . '">';
		foreach ( $tabs as $key => $item ) {
			$current = (string) $key === $tab;
			echo '<a href="' . esc_url( $item[1] ) . '" class="nav-tab' . ( $current ? ' nav-tab-active' : '' ) . '"' . ( $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $item[0] ) . '</a>';
		}
		echo '</nav>';

		if ( HealthCheckAdmin::TAB === $tab ) {
			HealthCheckAdmin::render();
			echo '</div>';
			return;
		}

		// Pages outside Settings must print Settings API messages themselves. No filter:
		// options.php files "Settings saved." under "general", validation errors under pqbg_settings.
		settings_errors();

		echo '<form method="post" action="' . esc_url( AdminUrl::options() ) . '">';
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
	 * Payment method checkboxes. The hidden marker tells Settings::sanitize() that the field was on the form.
	 */
	public static function render_payment_methods_field(): void {
		$name    = Plugin::SETTINGS_OPTION . '[payment_methods][]';
		$enabled = PaymentMethods::enabled();

		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Payment methods offered', 'product-qrcode-barcode-generator' ) . '</legend>';
		echo '<input type="hidden" name="' . esc_attr( Plugin::SETTINGS_OPTION . '[payment_methods_present]' ) . '" value="1" />';

		foreach ( PaymentMethods::all() as $key => $label ) {
			echo '<label style="margin-right:1.5em"><input type="checkbox" id="pqbg_payment_' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $key ) . '"' . checked( in_array( $key, $enabled, true ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}

		echo '</fieldset><p class="description">' . esc_html__( 'The sale form asks the seller to choose one of these. At least one must stay enabled. Split or mixed payments are not supported: record the sale under the main method.', 'product-qrcode-barcode-generator' ) . '</p>';
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
	 * Code prefix input (Phase 15). New codes only; the barcode warning when it applies.
	 */
	public static function render_code_prefix_field(): void {
		echo '<input type="text" class="small-text code" id="pqbg_code_prefix" name="' . esc_attr( Plugin::SETTINGS_OPTION . '[code_prefix]' ) . '"'
			. ' value="' . esc_attr( Settings::get_code_prefix() ) . '" maxlength="' . esc_attr( (string) CodeGenerator::PREFIX_MAX ) . '"'
			. ' autocapitalize="characters" autocomplete="off" spellcheck="false" aria-describedby="pqbg_code_prefix_help" />';

		/* translators: 1: minimum length, 2: maximum length, 3: example code. */
		echo '<p class="description" id="pqbg_code_prefix_help">' . esc_html( sprintf( __( '%1$d to %2$d letters or digits, starting with a letter. Used for new codes only, for example %3$s. Existing codes, and labels already printed, keep working and never change.', 'product-qrcode-barcode-generator' ), CodeGenerator::PREFIX_MIN, CodeGenerator::PREFIX_MAX, CodeGenerator::example_code() ) ) . '</p>';

		$warning = Settings::prefix_barcode_warning();

		if ( '' !== $warning ) {
			echo '<div class="notice notice-warning inline pqbg-prefix-warning"><p>' . esc_html( $warning ) . '</p></div>';
		}
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
		echo ' <a href="' . esc_url( AdminUrl::settings() ) . '">' . esc_html__( 'Scan base URL settings', 'product-qrcode-barcode-generator' ) . '</a></p></div>';
	}
}
