<?php
/**
 * Product code UI on the CLASSIC WooCommerce product screens (the block-based
 * product editor is not supported):
 *
 *   - "QR & Barcode" meta box on the product edit screen
 *   - the code inside each variation's panel
 *   - a "Code" column on the products list
 *   - the regeneration confirmation page (hidden admin page, GET, no side effects)
 *
 * Everything requires Permissions::MANAGE_CODES. Actions post to AdminActions.
 *
 * Performance: the edit screen renders at most ONE QR code synchronously (a
 * simple product's). Variation QR codes are loaded on click from
 * AdminActions::handle_image(). The list column shows text only.
 *
 * Retired codes appear only as text in the history; they are never rendered.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WC_Product;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Admin product screens.
 */
final class AdminProductPanel {

	const CONFIRM_SLUG = 'pqbg-regenerate';

	/**
	 * POST forms printed outside the product form (forms cannot be nested), keyed by id.
	 *
	 * @var array<string, string>
	 */
	private static $forms = array();

	/**
	 * The confirmation page's item, validated on its load-{hook} action before any output.
	 *
	 * @var array{product: WC_Product, row: array<string, string>}|null
	 */
	private static $confirm = null;

	/**
	 * Active codes for the products on the current list page.
	 *
	 * @var array<int, array<string, string>>|null
	 */
	private static $list_codes = null;

	/**
	 * Hooks the UI. Admin requests only.
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'render_variation_code' ), 10, 3 );
		add_filter( 'manage_product_posts_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'add_confirm_page' ) );
		add_action( 'admin_head', array( __CLASS__, 'hide_confirm_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_footer', array( __CLASS__, 'print_forms' ) );
		add_action( 'admin_notices', array( __CLASS__, 'result_notice' ) );
		add_filter( 'removable_query_args', array( __CLASS__, 'removable_query_args' ) );
	}

	/**
	 * Adds the meta box for users who can manage codes.
	 */
	public static function add_meta_box(): void {
		if ( ! Permissions::can_manage_codes() ) {
			return;
		}

		add_meta_box( 'pqbg-codes', __( 'QR & Barcode', 'product-qrcode-barcode-generator' ), array( __CLASS__, 'render_meta_box' ), 'product', 'normal', 'default' );
	}

	/**
	 * Renders the meta box.
	 *
	 * @param WP_Post $post Product post.
	 */
	public static function render_meta_box( $post ): void {
		if ( ! Permissions::can_manage_codes() || ! $post instanceof WP_Post ) {
			return;
		}

		$product = wc_get_product( $post->ID );

		echo '<div class="pqbg-panel">';

		if ( ! $product instanceof WC_Product || 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Save the product to assign its product code.', 'product-qrcode-barcode-generator' ) . '</p></div>';
			return;
		}

		if ( $product->is_type( 'simple' ) ) {
			self::render_simple( $product );
		} elseif ( $product->is_type( 'variable' ) ) {
			self::render_variable( $product );
		} else {
			echo '<p>' . esc_html__( 'This product type has no product code: grouped and external products are not sold as a single item in the shop. Codes belong to simple products and to each variation of a variable product.', 'product-qrcode-barcode-generator' ) . '</p>';
		}

		self::render_history( $product->get_id(), $product->is_type( 'variable' ) );

		echo '</div>';
	}

	/**
	 * Simple product: code, one QR (and barcode if enabled), actions.
	 *
	 * @param WC_Product $product Product.
	 */
	private static function render_simple( WC_Product $product ): void {
		$id  = $product->get_id();
		$row = CodeRepository::find_active_for_product( $id );

		if ( null === $row ) {
			echo '<p class="pqbg-no-code">' . esc_html__( 'No code yet.', 'product-qrcode-barcode-generator' ) . '</p>';
			echo '<p>' . self::generate_button( $id ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in generate_button().
			return;
		}

		$code = $row['code'];

		echo '<p class="pqbg-code-line">' . esc_html__( 'Code:', 'product-qrcode-barcode-generator' ) . ' <code class="pqbg-code">' . esc_html( $code ) . '</code> <span class="pqbg-status">' . esc_html__( 'Active', 'product-qrcode-barcode-generator' ) . '</span></p>';

		echo '<div class="pqbg-images">';

		$qr = ( new QrRenderer() )->render( $code );
		echo '<div class="pqbg-qr">' . ( is_wp_error( $qr ) ? esc_html( $qr->get_error_message() ) : $qr ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Svg output is fixed markup with the escaped code.

		if ( Settings::is_barcode_enabled() ) {
			$barcode = ( new BarcodeRenderer() )->render( $code );
			echo '<div class="pqbg-barcode">' . ( is_wp_error( $barcode ) ? esc_html( $barcode->get_error_message() ) : $barcode ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above.
		}

		echo '</div><p class="pqbg-actions">';
		echo self::action_links( $id, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in action_links().
		echo '</p>';
	}

	/**
	 * Variable product: a table of its variations; no parent code and no images on load.
	 *
	 * @param WC_Product $product Variable product.
	 */
	private static function render_variable( WC_Product $product ): void {
		$variations = self::variations( $product->get_id() );

		echo '<p>' . esc_html__( 'A variable product has no code of its own: each variation has its own code.', 'product-qrcode-barcode-generator' ) . '</p>';

		if ( array() === $variations ) {
			echo '<p>' . esc_html__( 'No variations yet. Variations get their codes when they are saved.', 'product-qrcode-barcode-generator' ) . '</p>';
			return;
		}

		$codes = CodeRepository::find_active_for_products( array_keys( $variations ) );

		echo '<table class="widefat striped pqbg-variations"><thead><tr>';
		echo '<th>' . esc_html__( 'Variation', 'product-qrcode-barcode-generator' ) . '</th>';
		echo '<th>' . esc_html__( 'SKU', 'product-qrcode-barcode-generator' ) . '</th>';
		echo '<th>' . esc_html__( 'Code', 'product-qrcode-barcode-generator' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'product-qrcode-barcode-generator' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'product-qrcode-barcode-generator' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $variations as $vid => $variation ) {
			$row = $codes[ $vid ] ?? null;

			echo '<tr data-pqbg-item="' . esc_attr( (string) $vid ) . '">';
			echo '<td>' . esc_html( self::variation_label( $variation ) ) . ' <span class="pqbg-muted">#' . esc_html( (string) $vid ) . '</span></td>';
			echo '<td>' . esc_html( '' !== $variation->get_sku( 'edit' ) ? $variation->get_sku( 'edit' ) : '—' ) . '</td>';

			if ( null === $row ) {
				echo '<td>—</td><td>' . esc_html__( 'No code', 'product-qrcode-barcode-generator' ) . '</td>';
				echo '<td>' . self::generate_button( $vid ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in generate_button().
			} else {
				echo '<td><code class="pqbg-code">' . esc_html( $row['code'] ) . '</code></td><td>' . esc_html__( 'Active', 'product-qrcode-barcode-generator' ) . '</td>';
				echo '<td>' . self::action_links( $vid, true ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in action_links().
			}

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Read-only history of retired codes (for a variable product: all its variations, including deleted ones).
	 *
	 * @param int  $product_id  Product ID.
	 * @param bool $is_variable Whether to show the item column.
	 */
	private static function render_history( int $product_id, bool $is_variable ): void {
		$rows = CodeRepository::find_retired_for_product_or_parent( $product_id );

		if ( array() === $rows ) {
			return;
		}

		/* translators: %d: number of retired codes. */
		echo '<details class="pqbg-history"><summary>' . esc_html( sprintf( __( 'Code history (%d retired)', 'product-qrcode-barcode-generator' ), count( $rows ) ) ) . '</summary>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Code', 'product-qrcode-barcode-generator' ) . '</th>';

		if ( $is_variable ) {
			echo '<th>' . esc_html__( 'Item', 'product-qrcode-barcode-generator' ) . '</th>';
		}

		/* translators: %s: site timezone, e.g. Asia/Kolkata or +05:30. */
		echo '<th>' . esc_html( sprintf( __( 'Retired at (%s)', 'product-qrcode-barcode-generator' ), wp_timezone_string() ) ) . '</th>';
		echo '<th>' . esc_html__( 'Retired by', 'product-qrcode-barcode-generator' ) . '</th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr class="pqbg-history-row"><td><code>' . esc_html( $row['code'] ) . '</code></td>';

			if ( $is_variable ) {
				$item = (int) $row['product_id'];
				$type = get_post_type( $item );

				if ( $item === $product_id ) {
					$label = __( 'This product', 'product-qrcode-barcode-generator' );
				} elseif ( 'product_variation' === $type ) {
					/* translators: %d: variation ID. */
					$label = sprintf( __( 'Variation #%d', 'product-qrcode-barcode-generator' ), $item );
				} else {
					/* translators: %d: variation ID. */
					$label = sprintf( __( 'Variation #%d (deleted)', 'product-qrcode-barcode-generator' ), $item );
				}

				echo '<td>' . esc_html( $label ) . '</td>';
			}

			echo '<td>' . esc_html( self::format_gmt( (string) $row['retired_at_gmt'] ) ) . '</td>';
			echo '<td class="pqbg-retired-by">' . esc_html( self::user_label( (int) $row['retired_by'] ) ) . '</td></tr>';
		}

		echo '</tbody></table></details>';
	}

	/**
	 * A stored GMT datetime in the site timezone, using the site's date and time formats.
	 *
	 * @param string $gmt "Y-m-d H:i:s" in UTC.
	 */
	public static function format_gmt( string $gmt ): string {
		$timestamp = strtotime( $gmt . ' UTC' );

		if ( '' === $gmt || false === $timestamp ) {
			return '—';
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Who retired a code: "System" for 0, "User #ID (deleted)" for a user that no longer exists.
	 *
	 * @param int $user_id User ID.
	 */
	public static function user_label( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return __( 'System', 'product-qrcode-barcode-generator' );
		}

		$user = get_userdata( $user_id );

		/* translators: %d: user ID. */
		return $user ? $user->display_name : sprintf( __( 'User #%d (deleted)', 'product-qrcode-barcode-generator' ), $user_id );
	}

	/**
	 * The code inside a variation's panel: text and on-demand QR links, never an image on load.
	 *
	 * @param int     $loop           Variation index.
	 * @param array   $variation_data Unused.
	 * @param WP_Post $variation      Variation post.
	 */
	public static function render_variation_code( $loop, $variation_data, $variation ): void {
		if ( ! Permissions::can_manage_codes() || ! $variation instanceof WP_Post ) {
			return;
		}

		$id  = (int) $variation->ID;
		$row = CodeRepository::find_active_for_product( $id );

		echo '<p class="form-row form-row-full pqbg-variation-code">' . esc_html__( 'Product code:', 'product-qrcode-barcode-generator' ) . ' ';

		if ( null === $row ) {
			echo esc_html__( 'No code yet (it is assigned when the variation and its product are saved).', 'product-qrcode-barcode-generator' ) . '</p>';
			return;
		}

		echo '<code class="pqbg-code">' . esc_html( $row['code'] ) . '</code> · ';
		echo self::view_link( $id ) . ' · '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in view_link().
		echo '<a href="' . esc_url( AdminActions::image_url( $id, 'qr', 'download' ) ) . '">' . esc_html__( 'Download QR', 'product-qrcode-barcode-generator' ) . '</a>';
		echo '<span class="pqbg-qr-slot"></span></p>';
	}

	/**
	 * Adds the Code column after SKU (or before the date) for users who can manage codes.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function add_column( $columns ): array {
		$columns = (array) $columns;

		if ( ! Permissions::can_manage_codes() ) {
			return $columns;
		}

		$after  = isset( $columns['sku'] ) ? 'sku' : 'name';
		$result = array();

		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;

			if ( $key === $after ) {
				$result['pqbg_code'] = __( 'Code', 'product-qrcode-barcode-generator' );
			}
		}

		if ( ! isset( $result['pqbg_code'] ) ) {
			$result['pqbg_code'] = __( 'Code', 'product-qrcode-barcode-generator' );
		}

		return $result;
	}

	/**
	 * Renders the Code column: text only, one batched query for the whole page.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Product ID.
	 */
	public static function render_column( $column, $post_id ): void {
		if ( 'pqbg_code' !== $column ) {
			return;
		}

		if ( null === self::$list_codes ) {
			global $wp_query;

			$ids              = isset( $wp_query->posts ) ? wp_list_pluck( (array) $wp_query->posts, 'ID' ) : array();
			self::$list_codes = CodeRepository::find_active_for_products( array_map( 'intval', $ids ) );
		}

		$row = self::$list_codes[ (int) $post_id ] ?? CodeRepository::find_active_for_product( (int) $post_id );

		echo null === $row ? '<span aria-hidden="true">—</span>' : '<code class="pqbg-code">' . esc_html( $row['code'] ) . '</code>';
	}

	/**
	 * Registers the hidden confirmation page under Products (removed from the menu in admin_head).
	 */
	public static function add_confirm_page(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=product',
			__( 'Regenerate product code', 'product-qrcode-barcode-generator' ),
			__( 'Regenerate product code', 'product-qrcode-barcode-generator' ),
			Permissions::MANAGE_CODES,
			self::CONFIRM_SLUG,
			array( __CLASS__, 'render_confirm_page' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'load_confirm_page' ) );
		}
	}

	/**
	 * Keeps the confirmation page out of the menu (it is only reached from a Regenerate link).
	 */
	public static function hide_confirm_page(): void {
		remove_submenu_page( 'edit.php?post_type=product', self::CONFIRM_SLUG );
	}

	/**
	 * URL of the confirmation page for an item.
	 *
	 * @param int $item_id Product or variation ID.
	 */
	public static function confirm_url( int $item_id ): string {
		return add_query_arg(
			array(
				'post_type'              => 'product',
				'page'                   => self::CONFIRM_SLUG,
				'item'                   => $item_id,
				Permissions::NONCE_FIELD => wp_create_nonce( AdminActions::nonce_action( 'regenerate_confirm', $item_id ) ),
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Validates the confirmation request before any output, so refusals get their real status code.
	 */
	public static function load_confirm_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the nonce is checked below.
		$item_id = isset( $_GET['item'] ) ? absint( wp_unslash( $_GET['item'] ) ) : 0;
		$nonce   = isset( $_GET[ Permissions::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_GET[ Permissions::NONCE_FIELD ] ) ) : '';
		// phpcs:enable

		if ( ! Permissions::can_manage_codes() || $item_id <= 0 || ! wp_verify_nonce( $nonce, AdminActions::nonce_action( 'regenerate_confirm', $item_id ) ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Reload the product page and try again.', 'product-qrcode-barcode-generator' ), 403 );
		}

		$product = wc_get_product( $item_id );
		$row     = CodeRepository::find_active_for_product( $item_id );

		if ( ! $product instanceof WC_Product || null === $row || ! ProductCodeService::is_eligible( $item_id ) ) {
			wp_die( esc_html__( 'This item has no active product code.', 'product-qrcode-barcode-generator' ), 404 );
		}

		self::$confirm = array(
			'product' => $product,
			'row'     => $row,
		);
	}

	/**
	 * Confirmation page (GET, no side effects). Its form POSTs to AdminActions::handle_regenerate().
	 */
	public static function render_confirm_page(): void {
		if ( null === self::$confirm || ! Permissions::can_manage_codes() ) {
			wp_die( esc_html__( 'The link you followed has expired. Reload the product page and try again.', 'product-qrcode-barcode-generator' ), 403 );
		}

		$product = self::$confirm['product'];
		$row     = self::$confirm['row'];
		$item_id = $product->get_id();

		$back = admin_url( 'post.php?post=' . ( $product->is_type( 'variation' ) ? $product->get_parent_id() : $item_id ) . '&action=edit' );
		$name = $product->is_type( 'variation' ) ? wc_get_formatted_variation( $product, true, true, false ) : '';

		echo '<div class="wrap pqbg-confirm"><h1>' . esc_html__( 'Regenerate product code', 'product-qrcode-barcode-generator' ) . '</h1>';
		echo '<p>' . esc_html__( 'Product:', 'product-qrcode-barcode-generator' ) . ' <strong>' . esc_html( $product->is_type( 'variation' ) ? get_the_title( $product->get_parent_id() ) : $product->get_name( 'edit' ) ) . '</strong>';
		echo '' !== $name ? ' — ' . esc_html( $name ) : '';
		echo ' <span class="pqbg-muted">#' . esc_html( (string) $item_id ) . '</span></p>';
		echo '<p>' . esc_html__( 'Current code:', 'product-qrcode-barcode-generator' ) . ' <code class="pqbg-code">' . esc_html( $row['code'] ) . '</code></p>';
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Printed labels with the old code will stop working.', 'product-qrcode-barcode-generator' ) . '</strong> ';
		echo esc_html__( 'The current code is retired permanently and a new code is created. The old code stays in the history and is never used again.', 'product-qrcode-barcode-generator' ) . '</p></div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AdminActions::REGENERATE ) . '" />';
		echo '<input type="hidden" name="item" value="' . esc_attr( (string) $item_id ) . '" />';
		echo '<input type="hidden" name="expected" value="' . esc_attr( (string) $row['id'] ) . '" />';
		wp_nonce_field( AdminActions::nonce_action( 'regenerate_code', $item_id ), Permissions::NONCE_FIELD );
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Regenerate code', 'product-qrcode-barcode-generator' ) . '</button> ';
		echo '<a class="button" href="' . esc_url( $back ) . '">' . esc_html__( 'Cancel', 'product-qrcode-barcode-generator' ) . '</a></p></form></div>';
	}

	/**
	 * Styles and the click-to-load script, on product screens only.
	 *
	 * @param string $hook_suffix Admin page hook.
	 */
	public static function enqueue( $hook_suffix ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! Permissions::can_manage_codes() || ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'pqbg-admin', PQBG_PLUGIN_URL . 'assets/admin.css', array(), PQBG_VERSION );

		if ( 'post.php' === $hook_suffix || 'post-new.php' === $hook_suffix ) {
			wp_enqueue_script( 'pqbg-admin', PQBG_PLUGIN_URL . 'assets/admin.js', array(), PQBG_VERSION, true );
		}
	}

	/**
	 * Prints the POST forms that buttons inside the product form submit via their form attribute.
	 */
	public static function print_forms(): void {
		foreach ( self::$forms as $html ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaping in generate_button().
		}

		self::$forms = array();
	}

	/**
	 * Result of a generate/regenerate request, after the redirect.
	 */
	public static function result_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only, whitelisted values.
		$key = isset( $_GET[ AdminActions::MESSAGE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ AdminActions::MESSAGE_ARG ] ) ) : '';

		if ( '' === $key || ! Permissions::can_manage_codes() ) {
			return;
		}

		$messages = array(
			'generated'                   => array( 'success', __( 'Product code generated.', 'product-qrcode-barcode-generator' ) ),
			'regenerated'                 => array( 'success', __( 'Product code regenerated. The old code is retired; labels printed with it no longer work.', 'product-qrcode-barcode-generator' ) ),
			'pqbg_code_changed'           => array( 'warning', __( 'The product code was changed by another request, so nothing was regenerated. Check the current code.', 'product-qrcode-barcode-generator' ) ),
			'pqbg_no_active_code'         => array( 'warning', __( 'This item has no active code to regenerate.', 'product-qrcode-barcode-generator' ) ),
			'pqbg_ineligible_product'     => array( 'error', __( 'This product type cannot have a product code.', 'product-qrcode-barcode-generator' ) ),
			'pqbg_ineligible_status'      => array( 'error', __( 'Save the product first; unsaved products do not get a product code.', 'product-qrcode-barcode-generator' ) ),
			'pqbg_code_generation_failed' => array( 'error', __( 'A product code could not be generated. Nothing was changed. Try again.', 'product-qrcode-barcode-generator' ) ),
		);

		$message = $messages[ $key ] ?? array( 'error', __( 'The product code could not be changed. Nothing was changed.', 'product-qrcode-barcode-generator' ) );

		echo '<div class="notice notice-' . esc_attr( $message[0] ) . ' is-dismissible pqbg-result"><p>' . esc_html( $message[1] ) . '</p></div>';
	}

	/**
	 * Removes the result argument from the address bar after display.
	 *
	 * @param string[] $args Removable args.
	 * @return string[]
	 */
	public static function removable_query_args( $args ): array {
		$args   = (array) $args;
		$args[] = AdminActions::MESSAGE_ARG;

		return $args;
	}

	/**
	 * Download / regenerate actions for an item with an active code.
	 *
	 * The Regenerate link leads to the confirmation page, which reads the current code ID fresh.
	 *
	 * @param int  $item_id   Product or variation ID.
	 * @param bool $with_view Include the click-to-load "View QR" link.
	 */
	private static function action_links( int $item_id, bool $with_view ): string {
		$links = array();

		if ( $with_view ) {
			$links[] = self::view_link( $item_id );
		}

		$links[] = '<a class="button pqbg-download-qr" href="' . esc_url( AdminActions::image_url( $item_id, 'qr', 'download' ) ) . '">' . esc_html__( 'Download QR (SVG)', 'product-qrcode-barcode-generator' ) . '</a>';

		if ( Settings::is_barcode_enabled() ) {
			$links[] = '<a class="button pqbg-download-barcode" href="' . esc_url( AdminActions::image_url( $item_id, 'barcode', 'download' ) ) . '">' . esc_html__( 'Download barcode (SVG)', 'product-qrcode-barcode-generator' ) . '</a>';
		}

		$links[] = '<a class="button pqbg-regenerate" href="' . esc_url( self::confirm_url( $item_id ) ) . '">' . esc_html__( 'Regenerate…', 'product-qrcode-barcode-generator' ) . '</a>';

		return implode( ' ', $links ) . ( $with_view ? '<span class="pqbg-qr-slot"></span>' : '' );
	}

	/**
	 * Click-to-load QR link (a plain link opening the SVG without JavaScript).
	 *
	 * @param int $item_id Product or variation ID.
	 */
	private static function view_link( int $item_id ): string {
		return '<a class="pqbg-view-qr" target="_blank" rel="noopener" href="' . esc_url( AdminActions::image_url( $item_id, 'qr', 'view' ) ) . '">' . esc_html__( 'View QR', 'product-qrcode-barcode-generator' ) . '</a>';
	}

	/**
	 * A Generate button plus its POST form, printed later in the footer.
	 *
	 * @param int $item_id Product or variation ID.
	 */
	private static function generate_button( int $item_id ): string {
		$form_id = 'pqbg-f-generate-' . $item_id;

		self::$forms[ $form_id ] = '<form id="' . esc_attr( $form_id ) . '" class="pqbg-hidden-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( AdminActions::GENERATE ) . '" />'
			. '<input type="hidden" name="item" value="' . esc_attr( (string) $item_id ) . '" />'
			. wp_nonce_field( AdminActions::nonce_action( 'generate_code', $item_id ), Permissions::NONCE_FIELD, false, false )
			. '</form>';

		return '<button type="submit" class="button pqbg-generate" form="' . esc_attr( $form_id ) . '">' . esc_html__( 'Generate code', 'product-qrcode-barcode-generator' ) . '</button>';
	}

	/**
	 * A variable product's variations (any status except trash and auto-draft), keyed by ID.
	 *
	 * @param int $parent_id Variable product ID.
	 * @return array<int, WC_Product>
	 */
	private static function variations( int $parent_id ): array {
		// One query for the posts and one for their meta (get_posts primes both caches),
		// instead of two per variation when each product object loads itself.
		$posts = get_posts(
			array(
				'post_parent'      => $parent_id,
				'post_type'        => 'product_variation',
				'post_status'      => array( 'publish', 'private' ),
				'numberposts'      => -1,
				'orderby'          => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'suppress_filters' => true,
			)
		);

		$result = array();

		foreach ( $posts as $post ) {
			$object = wc_get_product( $post );

			if ( $object instanceof WC_Product && $object->is_type( 'variation' ) ) {
				$result[ $object->get_id() ] = $object;
			}
		}

		return $result;
	}

	/**
	 * "Colour: Red, Size: M", or "Any" when a variation matches any value.
	 *
	 * @param WC_Product $variation Variation.
	 */
	private static function variation_label( WC_Product $variation ): string {
		$label = wc_get_formatted_variation( $variation, true, true, false );

		return '' !== trim( wp_strip_all_tags( $label ) ) ? wp_strip_all_tags( $label ) : __( 'Any', 'product-qrcode-barcode-generator' );
	}
}
