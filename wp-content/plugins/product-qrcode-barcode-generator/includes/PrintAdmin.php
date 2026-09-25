<?php
/**
 * Label printing in wp-admin: the entry points, the print setup screen and the
 * POST that remembers the options.
 *
 *   Products → (hidden) page=pqbg-print   GET   setup screen: what will print, what is
 *                                               skipped and why, layout and options
 *   admin-post.php  pqbg_print_prepare    POST  saves the user's options (user meta),
 *                                               then 303 → the print page (PrintPage)
 *   Products list bulk action "Print QR labels" → the setup screen
 *
 * Entry points in the product panel (AdminProductPanel) link to setup_url().
 * Everything requires Permissions::MANAGE_CODES and a nonce bound to the product
 * selection. The setup screen is read-only; codes are never generated here.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Print setup screen and entry points.
 */
final class PrintAdmin {

	const SLUG = 'pqbg-print';

	const PREPARE = 'pqbg_print_prepare';

	const BULK_ACTION = 'pqbg_print_labels';

	/** Set by the prepare handler when the saved options cannot be printed. */
	const INVALID_ARG = 'pqbg_invalid';

	/**
	 * The setup screen's selection, validated on its load-{hook} action before any output.
	 *
	 * @var int[]|null
	 */
	private static $ids = null;

	/**
	 * Hooks the screen, the handler and the bulk action. Admin requests only.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_head', array( __CLASS__, 'hide_page' ) );
		add_action( 'admin_post_' . self::PREPARE, array( __CLASS__, 'handle_prepare' ) );
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		add_filter( 'removable_query_args', array( __CLASS__, 'removable_query_args' ) );
	}

	/**
	 * URL of the setup screen for a selection of products and/or variations.
	 *
	 * @param int[] $ids Selection.
	 */
	public static function setup_url( array $ids ): string {
		return add_query_arg(
			array(
				'post_type'              => 'product',
				'page'                   => self::SLUG,
				'items'                  => implode( ',', array_map( 'intval', $ids ) ),
				Permissions::NONCE_FIELD => wp_create_nonce( PrintJob::nonce_action( 'print_view', $ids ) ),
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Registers the hidden setup screen under Products.
	 */
	public static function add_page(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=product',
			__( 'Print QR labels', 'product-qrcode-barcode-generator' ),
			__( 'Print QR labels', 'product-qrcode-barcode-generator' ),
			Permissions::MANAGE_CODES,
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'load_page' ) );
		}
	}

	/**
	 * Keeps the setup screen out of the menu (it is reached from the entry points).
	 */
	public static function hide_page(): void {
		remove_submenu_page( 'edit.php?post_type=product', self::SLUG );
	}

	/**
	 * Validates the request before any output, so refusals get their real status code.
	 */
	public static function load_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the nonce is checked below.
		$ids   = PrintJob::parse_ids( isset( $_GET['items'] ) ? sanitize_text_field( wp_unslash( $_GET['items'] ) ) : '' );
		$nonce = isset( $_GET[ Permissions::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_GET[ Permissions::NONCE_FIELD ] ) ) : '';
		// phpcs:enable

		if ( ! Permissions::can_manage_codes() ) {
			wp_die( esc_html__( 'You are not allowed to print product labels.', 'product-qrcode-barcode-generator' ), 403 );
		}

		if ( is_wp_error( $ids ) && 'pqbg_print_too_many_items' === $ids->get_error_code() ) {
			wp_die( esc_html( $ids->get_error_message() ), 400 );
		}

		if ( is_wp_error( $ids ) || ! wp_verify_nonce( $nonce, PrintJob::nonce_action( 'print_view', $ids ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Go back to the products and try again.', 'product-qrcode-barcode-generator' ), 403 );
		}

		self::$ids = $ids;
	}

	/**
	 * The setup screen (GET, no side effects).
	 */
	public static function render_page(): void {
		if ( null === self::$ids || ! Permissions::can_manage_codes() ) {
			wp_die( esc_html__( 'The link you followed has expired. Go back to the products and try again.', 'product-qrcode-barcode-generator' ), 403 );
		}

		$ids      = self::$ids;
		$prefs    = PrintJob::prefs( get_current_user_id() );
		$resolved = PrintJob::resolve( $ids );
		$options  = PrintJob::options( $prefs );
		$problem  = is_wp_error( $options ) ? $options : self::check( $resolved, $options );
		$base     = ScanUrl::base();
		$modules  = self::modules();
		$fields   = is_wp_error( $options ) ? $prefs['fields'] : $options['fields'];
		$barcode  = Settings::is_barcode_enabled();
		$items    = $resolved['items'];
		$drafts   = count( array_filter( $items, static fn( $i ) => 'publish' !== $i['status'] ) );

		echo '<div class="wrap pqbg-print-setup"><h1>' . esc_html__( 'Print QR labels', 'product-qrcode-barcode-generator' ) . '</h1>';

		if ( Settings::is_local_url( $base ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'QR codes currently point to a local address. Do not print labels until the production URL is set.', 'product-qrcode-barcode-generator' ) . ' ' . esc_html__( 'You can still print test labels; they are marked "TEST – NOT FOR USE".', 'product-qrcode-barcode-generator' ) . '</p></div>';
		} elseif ( Settings::is_http_url( $base ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Labels should use an https:// scan URL in production.', 'product-qrcode-barcode-generator' ) . '</p></div>';
		}

		// Problems with the remembered options (also shown after a refused "Preview and print").
		if ( is_wp_error( $problem ) ) {
			echo '<div class="notice notice-error inline pqbg-print-error"><p>' . esc_html( $problem->get_error_message() ) . '</p></div>';
		}

		// What will be printed.
		$labels = is_wp_error( $options ) ? null : PrintJob::labels( $items, $options );
		echo '<div class="pqbg-print-summary">';
		/* translators: 1: items with a code, 2: selected products. */
		echo '<p><strong>' . esc_html( sprintf( _n( '%1$d item ready to print', '%1$d items ready to print', count( $items ), 'product-qrcode-barcode-generator' ), count( $items ) ) ) . '</strong>';

		if ( is_array( $labels ) ) {
			/* translators: %d: labels. */
			echo ' · ' . esc_html( sprintf( _n( '%d label with these options', '%d labels with these options', $labels['total'], 'product-qrcode-barcode-generator' ), $labels['total'] ) );
		}

		echo '</p>';

		if ( $drafts > 0 ) {
			/* translators: %d: items. */
			echo '<p class="pqbg-muted">' . esc_html( sprintf( _n( '%d item is not published: its label scans to "Not published – cannot be sold yet" until you publish it.', '%d items are not published: their labels scan to "Not published – cannot be sold yet" until you publish them.', $drafts, 'product-qrcode-barcode-generator' ), $drafts ) ) . '</p>';
		}

		if ( array() !== $resolved['skipped'] ) {
			echo '<ul class="pqbg-print-skipped">';

			foreach ( $resolved['skipped'] as $skip ) {
				echo '<li class="pqbg-skip pqbg-skip--' . esc_attr( $skip['reason'] ) . '">' . esc_html( PrintJob::reason_text( $skip['reason'] ) ) . ': ' . esc_html( '' !== $skip['name'] ? $skip['name'] : '#' . $skip['id'] ) . ' <span class="pqbg-muted">#' . esc_html( (string) $skip['id'] ) . '</span>';

				if ( '' !== $skip['edit_url'] ) {
					echo ' <a href="' . esc_url( $skip['edit_url'] ) . '">' . esc_html__( 'Open product', 'product-qrcode-barcode-generator' ) . '</a>';
				}

				echo '</li>';
			}

			echo '</ul>';
		}

		echo '</div>';

		// The options form.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pqbg-print-form">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::PREPARE ) . '" />';
		echo '<input type="hidden" name="items" value="' . esc_attr( implode( ',', $ids ) ) . '" />';
		wp_nonce_field( PrintJob::nonce_action( 'print_prepare', $ids ), Permissions::NONCE_FIELD );

		echo '<h2>' . esc_html__( 'Layout', 'product-qrcode-barcode-generator' ) . '</h2><fieldset class="pqbg-layouts">';

		foreach ( PrintLayout::presets() as $id => $spec ) {
			$fit  = PrintLayout::fit( $spec, $modules, $fields, $barcode, Settings::is_local_url( $base ) );
			$note = is_wp_error( $fit )
				? __( 'too small for your scan URL', 'product-qrcode-barcode-generator' )
				/* translators: %s: QR size in mm. */
				: sprintf( __( 'QR code %s mm', 'product-qrcode-barcode-generator' ), PrintLayout::mm( $fit['qr'], 1 ) );

			echo '<label class="pqbg-layout"><input type="radio" name="opt[layout]" value="' . esc_attr( $id ) . '"' . checked( $prefs['layout'], $id, false ) . ' /> ' . esc_html( PrintLayout::name( $spec ) ) . ' <span class="pqbg-muted">— ' . esc_html( $note ) . '</span>';

			if ( '' !== $spec['warning'] ) {
				echo ' <span class="pqbg-muted">(' . esc_html__( 'edge-to-edge sheet', 'product-qrcode-barcode-generator' ) . ')</span>';
			}

			echo '</label><br />';
		}

		echo '<label class="pqbg-layout"><input type="radio" name="opt[layout]" value="custom"' . checked( $prefs['layout'], 'custom', false ) . ' /> ' . esc_html__( 'Custom layout (mm):', 'product-qrcode-barcode-generator' ) . '</label>';
		echo '<div class="pqbg-custom">';
		echo '<label>' . esc_html__( 'Type', 'product-qrcode-barcode-generator' ) . ' <select name="opt[custom][type]">';
		echo '<option value="sheet"' . selected( $prefs['custom']['type'], 'sheet', false ) . '>' . esc_html__( 'Label sheet', 'product-qrcode-barcode-generator' ) . '</option>';
		echo '<option value="thermal"' . selected( $prefs['custom']['type'], 'thermal', false ) . '>' . esc_html__( 'Thermal printer (one label per page)', 'product-qrcode-barcode-generator' ) . '</option></select></label> ';
		echo '<label>' . esc_html__( 'Thermal printer resolution', 'product-qrcode-barcode-generator' ) . ' <select name="opt[custom][dpi]">';
		echo '<option value="203"' . selected( $prefs['custom']['dpi'], '203', false ) . '>203 dpi</option><option value="300"' . selected( $prefs['custom']['dpi'], '300', false ) . '>300 dpi</option></select></label><br />';

		foreach ( PrintLayout::custom_fields() as $key => $field ) {
			echo '<label class="pqbg-custom-field">' . esc_html( $field[0] ) . ' <input type="text" inputmode="decimal" size="6" name="opt[custom][' . esc_attr( $key ) . ']" value="' . esc_attr( $prefs['custom'][ $key ] ) . '" /></label> ';
		}

		echo '<p class="description">' . esc_html__( 'For a thermal printer only the label width and height are used.', 'product-qrcode-barcode-generator' ) . '</p></div></fieldset>';

		echo '<h2>' . esc_html__( 'Options', 'product-qrcode-barcode-generator' ) . '</h2>';
		echo '<p><label>' . esc_html__( 'Start at position', 'product-qrcode-barcode-generator' ) . ' <input type="number" min="1" max="500" name="opt[start]" value="' . esc_attr( $prefs['start'] ) . '" class="small-text" /></label> <span class="description">' . esc_html__( 'Label sheets only: skip the labels already used on a partly used sheet (counted row by row from the top left).', 'product-qrcode-barcode-generator' ) . '</span></p>';

		echo '<fieldset><legend>' . esc_html__( 'Copies', 'product-qrcode-barcode-generator' ) . '</legend>';
		echo '<label><input type="radio" name="opt[copies_mode]" value="fixed"' . checked( $prefs['copies_mode'], 'fixed', false ) . ' /> <input type="number" min="1" max="' . esc_attr( (string) PrintJob::MAX_COPIES ) . '" name="opt[copies]" value="' . esc_attr( $prefs['copies'] ) . '" class="small-text" /> ' . esc_html__( 'per item', 'product-qrcode-barcode-generator' ) . '</label><br />';
		echo '<label><input type="radio" name="opt[copies_mode]" value="stock"' . checked( $prefs['copies_mode'], 'stock', false ) . ' /> ' . esc_html__( 'One label per unit in stock (items that track their own stock; others get 1 label)', 'product-qrcode-barcode-generator' ) . '</label>';
		/* translators: %d: maximum labels. */
		echo '<p class="description">' . esc_html( sprintf( __( 'At most %d labels per print job.', 'product-qrcode-barcode-generator' ), PrintJob::MAX_LABELS ) ) . '</p></fieldset>';

		echo '<fieldset><legend>' . esc_html__( 'Show on the label', 'product-qrcode-barcode-generator' ) . '</legend>';
		$labels_text = array(
			'name'       => __( 'Product name', 'product-qrcode-barcode-generator' ),
			'attributes' => __( 'Variation attributes', 'product-qrcode-barcode-generator' ),
			'sku'        => __( 'SKU', 'product-qrcode-barcode-generator' ),
			'price'      => __( 'Price', 'product-qrcode-barcode-generator' ),
			'store'      => __( 'Store name', 'product-qrcode-barcode-generator' ),
		);

		foreach ( $labels_text as $key => $text ) {
			echo '<label><input type="checkbox" name="opt[fields][]" value="' . esc_attr( $key ) . '"' . checked( in_array( $key, $prefs['fields'], true ), true, false ) . ' /> ' . esc_html( $text ) . '</label> ';
		}

		echo '<label><input type="checkbox" checked disabled /> ' . esc_html__( 'Code (always printed)', 'product-qrcode-barcode-generator' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Printed prices go out of date when you change them; the QR always shows the live price.', 'product-qrcode-barcode-generator' ) . '</p>';

		if ( $barcode ) {
			/* translators: %s: width in mm. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Barcodes are on: a barcode is printed on labels that are at least %s mm wide inside their margins.', 'product-qrcode-barcode-generator' ), PrintLayout::mm( PrintLayout::BARCODE_MAX_MODULES * PrintLayout::BARCODE_X_MM ) ) ) . '</p>';
		}

		echo '</fieldset>';

		/* translators: %s: maximum offset in mm. */
		echo '<p><label>' . esc_html__( 'Printer offset (label sheets only): right', 'product-qrcode-barcode-generator' ) . ' <input type="text" inputmode="decimal" size="4" name="opt[dx]" value="' . esc_attr( $prefs['dx'] ) . '" /> mm</label> <label>' . esc_html__( 'down', 'product-qrcode-barcode-generator' ) . ' <input type="text" inputmode="decimal" size="4" name="opt[dy]" value="' . esc_attr( $prefs['dy'] ) . '" /> mm</label> <span class="description">' . esc_html( sprintf( __( 'Moves everything by up to ±%s mm if your printer prints slightly off; negative values move left or up.', 'product-qrcode-barcode-generator' ), PrintLayout::mm( PrintLayout::MAX_OFFSET_MM ) ) ) . '</span></p>';

		echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__( 'Preview and print', 'product-qrcode-barcode-generator' ) . '</button> ';
		echo '<a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '">' . esc_html__( 'Cancel', 'product-qrcode-barcode-generator' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Your choices are remembered for next time (for your user only).', 'product-qrcode-barcode-generator' ) . '</p>';
		echo '</form></div>';
	}

	/**
	 * POST: remembers the options, then sends the user to the print page (or back to the setup screen).
	 */
	public static function handle_prepare(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( 'POST' !== $method ) {
			header( 'Allow: POST' );
			wp_die( esc_html__( 'Method not allowed.', 'product-qrcode-barcode-generator' ), 405 );
		}

		if ( ! Permissions::can_manage_codes() ) {
			wp_die( esc_html__( 'You are not allowed to print product labels.', 'product-qrcode-barcode-generator' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce is checked right below, before anything is used.
		$ids   = PrintJob::parse_ids( isset( $_POST['items'] ) ? sanitize_text_field( wp_unslash( $_POST['items'] ) ) : '' );
		$nonce = isset( $_POST[ Permissions::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ Permissions::NONCE_FIELD ] ) ) : '';
		$raw   = isset( $_POST['opt'] ) && is_array( $_POST['opt'] ) ? map_deep( wp_unslash( $_POST['opt'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- map_deep().
		// phpcs:enable

		if ( is_wp_error( $ids ) || ! wp_verify_nonce( $nonce, PrintJob::nonce_action( 'print_prepare', $ids ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Go back to the products and try again.', 'product-qrcode-barcode-generator' ), 403 );
		}

		PrintJob::save_prefs( get_current_user_id(), $raw );

		$options = PrintJob::options( $raw );
		$problem = is_wp_error( $options ) ? $options : self::check( PrintJob::resolve( $ids ), $options );

		$target = null === $problem ? PrintPage::url( $ids, $options ) : add_query_arg( self::INVALID_ARG, '1', self::setup_url( $ids ) );

		wp_safe_redirect( $target, 303 );
		exit;
	}

	/**
	 * Adds "Print QR labels" to the products list's bulk actions.
	 *
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string>
	 */
	public static function bulk_actions( $actions ): array {
		$actions = (array) $actions;

		if ( Permissions::can_manage_codes() ) {
			$actions[ self::BULK_ACTION ] = __( 'Print QR labels', 'product-qrcode-barcode-generator' );
		}

		return $actions;
	}

	/**
	 * Sends the selected products to the setup screen. WordPress has already checked the bulk-posts nonce.
	 *
	 * @param string $redirect Default redirect.
	 * @param string $action   Bulk action.
	 * @param int[]  $post_ids Selected products.
	 * @return string
	 */
	public static function handle_bulk_action( $redirect, $action, $post_ids ) {
		if ( self::BULK_ACTION !== $action || ! Permissions::can_manage_codes() ) {
			return $redirect;
		}

		$ids = array_values( array_filter( array_map( 'intval', (array) $post_ids ), static fn( $id ) => $id > 0 ) );

		// One more than the maximum is enough for the setup screen to say "too many".
		return self::setup_url( array_slice( $ids, 0, PrintJob::MAX_ITEMS + 1 ) );
	}

	/**
	 * Removes the "invalid options" flag from the address bar after display.
	 *
	 * @param string[] $args Removable args.
	 * @return string[]
	 */
	public static function removable_query_args( $args ): array {
		$args   = (array) $args;
		$args[] = self::INVALID_ARG;

		return $args;
	}

	/**
	 * Whether a job can be printed with these options, without rendering it: the job
	 * limit and the layout fit (with the scan URL's real QR size).
	 *
	 * @param array{items: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>} $resolved PrintJob::resolve().
	 * @param array<string, mixed>                                                                    $options  Validated options.
	 */
	private static function check( array $resolved, array $options ): ?WP_Error {
		$labels = PrintJob::labels( $resolved['items'], $options );

		if ( is_wp_error( $labels ) ) {
			return $labels;
		}

		$fit = PrintLayout::fit( $options['spec'], self::modules(), $options['fields'], Settings::is_barcode_enabled(), Settings::is_local_url( ScanUrl::base() ) );

		return is_wp_error( $fit ) ? $fit : null;
	}

	/**
	 * QR modules per side (quiet zone included) for the current scan base URL.
	 *
	 * Every code has the same length, so the placeholder code gives the same QR
	 * version as the real ones; it is only measured, never printed.
	 */
	private static function modules(): int {
		$svg = PrintCache::qr( ScanUrl::EXAMPLE_CODE );

		PrintCache::flush();

		return is_wp_error( $svg ) ? 0 : PrintLayout::qr_modules( $svg );
	}
}
