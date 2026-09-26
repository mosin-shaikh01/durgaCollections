<?php
/**
 * In-store sales history for managers (Phase 9A): WooCommerce → In-store sales.
 *
 * Screens (one hidden-query page, all GET and read-only):
 *   admin.php?page=pqbg-sales&{filters}              list, filters, totals, CSV link
 *   admin.php?page=pqbg-sales&sale={id}              sale detail and timeline
 *   admin.php?page=pqbg-sales&sale={id}&pqbg_view=void  void confirmation
 * Handler:
 *   POST admin-post.php?action=pqbg_void_sale        nonce pqbg_void_sale_{id}, pqbg_void_sale,
 *                                                    reason required; SaleService::void_sale(); 303
 *
 * Access: pqbg_view_all_sales (administrators and shop managers); voiding also
 * needs pqbg_void_sale; cost and profit only with pqbg_view_costs. Messages come
 * back as fixed codes in the URL (pqbg_msg), never as free text.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * Sales history screens and the void handler.
 */
final class SalesAdmin {

	const SLUG        = 'pqbg-sales';
	const VOID_ACTION = 'pqbg_void_sale';
	const MESSAGE_ARG = 'pqbg_msg';
	const VIEW_ARG    = 'pqbg_view';

	/** Longest void reason accepted. */
	const REASON_MAX = 500;

	/** @var array<string, mixed>|null The sale shown on the detail or void screen (validated on load). */
	private static ?array $sale = null;

	/**
	 * Hooks used on admin requests.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 99 );
		add_action( 'admin_post_' . self::VOID_ACTION, array( __CLASS__, 'handle_void' ) );
		add_action( 'admin_post_' . SalesExport::ACTION, array( SalesExport::class, 'handle' ) );
		add_filter( 'removable_query_args', array( __CLASS__, 'removable_query_args' ) );
	}

	/**
	 * Adds "In-store sales" under WooCommerce, right after Orders.
	 */
	public static function add_menu(): void {
		global $submenu;

		$position = null;

		foreach ( array_values( $submenu['woocommerce'] ?? array() ) as $index => $item ) {
			if ( in_array( $item[2] ?? '', array( 'wc-orders', 'edit.php?post_type=shop_order' ), true ) ) {
				$position = $index + 1;
				break;
			}
		}

		$hook = add_submenu_page(
			'woocommerce',
			__( 'In-store sales', 'product-qrcode-barcode-generator' ),
			__( 'In-store sales', 'product-qrcode-barcode-generator' ),
			Permissions::VIEW_ALL_SALES,
			self::SLUG,
			array( __CLASS__, 'render' ),
			$position
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'load' ) );
		}
	}

	/**
	 * URL of the list with filters.
	 *
	 * @param array<string, string> $args Query arguments.
	 */
	public static function list_url( array $args = array() ): string {
		return add_query_arg( array_map( 'rawurlencode', array_merge( array( 'page' => self::SLUG ), $args ) ), admin_url( 'admin.php' ) );
	}

	/**
	 * URL of a sale's detail screen.
	 *
	 * @param int $sale_id Sale ID.
	 */
	public static function detail_url( int $sale_id ): string {
		return self::list_url( array( 'sale' => (string) $sale_id ) );
	}

	/**
	 * URL of a sale's void confirmation.
	 *
	 * @param int $sale_id Sale ID.
	 */
	public static function void_url( int $sale_id ): string {
		return self::list_url(
			array(
				'sale'         => (string) $sale_id,
				self::VIEW_ARG => 'void',
			)
		);
	}

	/**
	 * load-{page}: checks the requested sale before any output, so a missing one is a clean 404.
	 */
	public static function load(): void {
		if ( ! Permissions::can_view_all_sales() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'product-qrcode-barcode-generator' ), 403 );
		}

		$id = self::sale_arg();

		if ( null === $id ) {
			return;
		}

		self::$sale = SalesQuery::find( $id );

		if ( null === self::$sale ) {
			wp_die( esc_html__( 'Sale not found.', 'product-qrcode-barcode-generator' ), esc_html__( 'Sale not found', 'product-qrcode-barcode-generator' ), array( 'response' => 404, 'back_link' => true ) );
		}

		if ( self::is_void_view() && ! Permissions::can_void_sale() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to void sales.', 'product-qrcode-barcode-generator' ), 403 );
		}
	}

	/**
	 * Renders the list, the detail or the void confirmation.
	 */
	public static function render(): void {
		if ( ! Permissions::can_view_all_sales() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'product-qrcode-barcode-generator' ), 403 );
		}

		echo '<div class="wrap pqbg-sales">';

		if ( null !== self::$sale && self::is_void_view() ) {
			self::render_void( self::$sale );
		} elseif ( null !== self::$sale ) {
			self::render_detail( self::$sale );
		} else {
			self::render_list();
		}

		echo '</div>';
	}

	/**
	 * POST admin-post.php?action=pqbg_void_sale.
	 */
	public static function handle_void(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( 'POST' !== $method ) {
			header( 'Allow: POST' );
			wp_die( esc_html__( 'This action must be submitted from the void screen.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 405 ) );
		}

		if ( ! Permissions::can_void_sale() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to void sales.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 403 ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified just below, before anything is used.
		$sale_id = isset( $_POST['sale'] ) && is_string( $_POST['sale'] ) && ctype_digit( $_POST['sale'] ) && strlen( $_POST['sale'] ) < 20 ? (int) $_POST['sale'] : 0;
		$nonce   = isset( $_POST[ Permissions::NONCE_FIELD ] ) && is_string( $_POST[ Permissions::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ Permissions::NONCE_FIELD ] ) ) : '';

		if ( $sale_id <= 0 || ! wp_verify_nonce( $nonce, Permissions::nonce_action( 'void_sale_' . $sale_id ) ) ) {
			wp_die( esc_html__( 'This form is no longer valid. Go back, reload the page and try again.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 403 ) );
		}

		$reason  = isset( $_POST['reason'] ) && is_string( $_POST['reason'] ) ? trim( wp_unslash( $_POST['reason'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- length-checked here, sanitize_textarea_field() in SaleService.
		$restock = isset( $_POST['restock'] ) && '1' === $_POST['restock'];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( null === SaleRepository::find( $sale_id ) ) {
			wp_die( esc_html__( 'Sale not found.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 404 ) );
		}

		if ( '' === $reason ) {
			self::redirect( self::void_url( $sale_id ), 'reason_required' );
		}

		if ( mb_strlen( $reason ) > self::REASON_MAX ) {
			self::redirect( self::void_url( $sale_id ), 'reason_long' );
		}

		$result = SaleService::void_sale( $sale_id, get_current_user_id(), $reason, $restock );

		if ( ! is_wp_error( $result ) ) {
			self::redirect( self::detail_url( $sale_id ), $restock ? 'voided_restocked' : 'voided' );
		}

		$map = array(
			'pqbg_not_voidable'     => array( self::detail_url( $sale_id ), 'not_voidable' ),
			'pqbg_busy'             => array( self::void_url( $sale_id ), 'busy' ),
			'pqbg_undo_unavailable' => array( self::void_url( $sale_id ), 'restock_unavailable' ),
			'pqbg_forbidden'        => array( self::detail_url( $sale_id ), 'forbidden' ),
		);
		$to  = $map[ $result->get_error_code() ] ?? array( self::void_url( $sale_id ), 'failed' );

		self::redirect( $to[0], $to[1] );
	}

	/**
	 * removable_query_args: drop the message argument from the address bar after display.
	 *
	 * @param string[] $args Arguments.
	 * @return string[]
	 */
	public static function removable_query_args( $args ): array {
		$args   = is_array( $args ) ? $args : array();
		$args[] = self::MESSAGE_ARG;

		return $args;
	}

	/**
	 * The list screen.
	 */
	private static function render_list(): void {
		$filters = SalesQuery::filters( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only view; every value is validated by SalesQuery::filters().
		$costs   = Permissions::can_view_costs();
		$range   = $filters['range'];
		$table   = new SalesListTable( $filters, $costs );

		$table->prepare_items();

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'In-store sales', 'product-qrcode-barcode-generator' ) . '</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( SalesExport::url( $filters ) ) . '">' . esc_html__( 'Export CSV', 'product-qrcode-barcode-generator' ) . '</a>';
		echo '<hr class="wp-header-end">';

		if ( $range['swapped'] ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'The start date was after the end date, so they were swapped.', 'product-qrcode-barcode-generator' ) . '</p></div>';
		}

		self::render_presets( $filters );
		self::render_filters( $filters );
		self::render_totals( SalesQuery::totals( $filters ), $costs );

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';

		foreach ( SalesQuery::args( $filters ) as $key => $value ) {
			if ( ! in_array( $key, array( 'orderby', 'order' ), true ) ) {
				echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
			}
		}

		$table->display();
		echo '</form>';
	}

	/**
	 * The date presets as links (other filters kept).
	 *
	 * @param array<string, mixed> $filters Filters.
	 */
	private static function render_presets( array $filters ): void {
		$labels = array(
			'today'      => __( 'Today', 'product-qrcode-barcode-generator' ),
			'yesterday'  => __( 'Yesterday', 'product-qrcode-barcode-generator' ),
			'last7'      => __( 'Last 7 days', 'product-qrcode-barcode-generator' ),
			'this_month' => __( 'This month', 'product-qrcode-barcode-generator' ),
			'last_month' => __( 'Last month', 'product-qrcode-barcode-generator' ),
		);
		$args   = SalesQuery::args( $filters );
		$links  = array();

		unset( $args['from'], $args['to'] );

		foreach ( $labels as $preset => $label ) {
			$current = $preset === $filters['range']['preset'];
			$links[] = '<li><a href="' . esc_url( self::list_url( array_merge( $args, array( 'range' => $preset ) ) ) ) . '"' . ( $current ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
		}

		echo '<ul class="subsubsub">' . implode( ' | ', $links ) . '</ul><div class="clear"></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	}

	/**
	 * The filter form (GET).
	 *
	 * @param array<string, mixed> $filters Filters.
	 */
	private static function render_filters( array $filters ): void {
		$range  = $filters['range'];
		$option = static fn( string $value, string $label, string $current ): string => '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="pqbg-sales__filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<input type="hidden" name="range" value="custom">';

		echo '<label>' . esc_html__( 'From', 'product-qrcode-barcode-generator' ) . ' <input type="date" name="from" value="' . esc_attr( $range['from'] ) . '"></label> ';
		echo '<label>' . esc_html__( 'To', 'product-qrcode-barcode-generator' ) . ' <input type="date" name="to" value="' . esc_attr( $range['to'] ) . '"></label> ';

		echo '<label>' . esc_html__( 'Seller', 'product-qrcode-barcode-generator' ) . ' <select name="seller">' . $option( '', __( 'All', 'product-qrcode-barcode-generator' ), (string) ( $filters['seller'] ?: '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $option escapes.

		foreach ( SalesQuery::sellers() as $id => $name ) {
			echo $option( (string) $id, SalePresenter::seller( array( 'seller_id' => $id, 'seller_name' => '' === $name ? null : $name ) ), (string) $filters['seller'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $option escapes.
		}

		echo '</select></label> ';
		echo '<label>' . esc_html__( 'Paid by', 'product-qrcode-barcode-generator' ) . ' <select name="method">' . $option( '', __( 'All', 'product-qrcode-barcode-generator' ), $filters['method'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $option escapes.

		foreach ( PaymentMethods::all() as $key => $label ) {
			echo $option( $key, $label, $filters['method'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $option escapes.
		}

		echo $option( SalesQuery::METHOD_NONE, PaymentMethods::label( null ), $filters['method'] ) . '</select></label> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $option escapes.
		echo '<label>' . esc_html__( 'Status', 'product-qrcode-barcode-generator' ) . ' <select name="status">' . $option( '', __( 'All', 'product-qrcode-barcode-generator' ), $filters['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $option escapes.

		foreach ( SalesQuery::STATUSES as $status ) {
			echo $option( $status, SalePresenter::status( $status ), $filters['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $option escapes.
		}

		echo '</select></label> ';
		echo '<label>' . esc_html__( 'Search', 'product-qrcode-barcode-generator' ) . ' <input type="search" name="s" value="' . esc_attr( $filters['search'] ) . '" placeholder="' . esc_attr__( 'Name, SKU or code', 'product-qrcode-barcode-generator' ) . '" maxlength="100"></label> ';
		submit_button( __( 'Filter', 'product-qrcode-barcode-generator' ), 'secondary', '', false );
		echo '<p class="description">' . esc_html__( 'The date presets above keep these filters. Dates are days in the site timezone.', 'product-qrcode-barcode-generator' ) . '</p>';
		echo '</form>';
	}

	/**
	 * The totals bar.
	 *
	 * @param array<string, mixed> $totals From SalesQuery::totals().
	 * @param bool                 $costs  Show cost and profit.
	 */
	private static function render_totals( array $totals, bool $costs ): void {
		$all    = $totals['all'];
		$parts  = array();

		foreach ( $totals['methods'] as $key => $total ) {
			$parts[] = PaymentMethods::label( '' === $key ? null : (string) $key ) . ' ' . SalePresenter::money( $total['revenue'] );
		}

		echo '<div class="pqbg-sales__totals notice notice-alt inline"><p><strong>';
		echo esc_html(
			sprintf(
				/* translators: 1: number of sales, 2: number of items, 3: revenue. */
				__( 'Completed in this view: %1$s sales · %2$s items · %3$s', 'product-qrcode-barcode-generator' ),
				number_format_i18n( $all['count'] ),
				number_format_i18n( $all['items'] ),
				SalePresenter::money( $all['revenue'] )
			)
		);
		echo '</strong>';

		if ( array() !== $parts ) {
			echo '<br>' . esc_html( implode( ' · ', $parts ) );
		}

		if ( $costs ) {
			echo '<br>' . esc_html(
				sprintf(
					/* translators: 1: cost, 2: profit. */
					__( 'Cost %1$s · Profit %2$s', 'product-qrcode-barcode-generator' ),
					SalePresenter::money( $all['cost'] ),
					SalePresenter::money( $all['profit'] )
				)
			);

			if ( $all['unknown'] > 0 ) {
				echo esc_html(
					' — ' . sprintf(
						/* translators: 1: number of lines, 2: their revenue. */
						_n( 'excludes %1$s line with unknown cost (%2$s)', 'excludes %1$s lines with unknown cost (%2$s)', $all['unknown'], 'product-qrcode-barcode-generator' ),
						number_format_i18n( $all['unknown'] ),
						SalePresenter::money( $all['unknown_revenue'] )
					)
				);
			}
		}

		echo '<br>' . esc_html(
			sprintf(
				/* translators: 1: voided count, 2: failed count. */
				__( 'Also in this view: %1$s voided · %2$s failed', 'product-qrcode-barcode-generator' ),
				number_format_i18n( $totals['voided'] ),
				number_format_i18n( $totals['failed'] )
			)
		);
		echo '</p></div>';
	}

	/**
	 * The sale detail screen.
	 *
	 * @param array<string, mixed> $sale Row.
	 */
	private static function render_detail( array $sale ): void {
		$id       = (int) $sale['id'];
		$currency = (string) $sale['currency'];
		$product  = (int) $sale['variation_id'] > 0 ? (int) $sale['variation_id'] : (int) $sale['product_id'];
		$rows     = array();

		/* translators: 1: sale number, 2: status. */
		echo '<h1 class="wp-heading-inline">' . esc_html( sprintf( __( 'Sale #%1$s — %2$s', 'product-qrcode-barcode-generator' ), $id, SalePresenter::status( (string) $sale['status'] ) ) ) . '</h1> ';

		if ( SaleRepository::STATUS_COMPLETED === $sale['status'] && Permissions::can_void_sale() ) {
			echo '<a class="page-title-action" href="' . esc_url( self::void_url( $id ) ) . '">' . esc_html__( 'Void sale', 'product-qrcode-barcode-generator' ) . '</a> ';
		}

		echo '<a class="page-title-action" href="' . esc_url( self::list_url() ) . '">' . esc_html__( 'Back to In-store sales', 'product-qrcode-barcode-generator' ) . '</a>';
		echo '<hr class="wp-header-end">';
		self::render_message();

		$rows[] = array( __( 'Product', 'product-qrcode-barcode-generator' ), SalePresenter::item( $sale ), self::product_link( (int) $sale['product_id'], $product ) );
		$rows[] = array( __( 'SKU', 'product-qrcode-barcode-generator' ), (string) $sale['sku'], '' );
		$rows[] = array( __( 'Code', 'product-qrcode-barcode-generator' ), null === $sale['code'] ? '—' : (string) $sale['code'], '' );
		$rows[] = array( __( 'Quantity', 'product-qrcode-barcode-generator' ), number_format_i18n( (int) $sale['quantity'] ), '' );
		$rows[] = array( __( 'Unit price', 'product-qrcode-barcode-generator' ), SalePresenter::money( $sale['unit_price'], $currency ) . ( null === $sale['regular_price'] ? '' : ' (' . sprintf( /* translators: %s: regular price. */ __( 'regular %s', 'product-qrcode-barcode-generator' ), SalePresenter::money( $sale['regular_price'], $currency ) ) . ')' ), '' );
		$rows[] = array( __( 'Total', 'product-qrcode-barcode-generator' ), SalePresenter::money( $sale['line_total'], $currency ) . ' ' . $currency, '' );
		$rows[] = array( __( 'Paid by', 'product-qrcode-barcode-generator' ), PaymentMethods::label( $sale['payment_method'] ), '' );

		if ( Permissions::can_view_costs() ) {
			$profit = SalePresenter::profit( $sale );
			$rows[] = array( __( 'Unit cost', 'product-qrcode-barcode-generator' ), null === $sale['unit_cost'] ? __( 'unknown', 'product-qrcode-barcode-generator' ) : SalePresenter::money( $sale['unit_cost'], $currency ), '' );
			$rows[] = array( __( 'Profit', 'product-qrcode-barcode-generator' ), null === $profit ? __( 'unknown', 'product-qrcode-barcode-generator' ) : SalePresenter::money( $profit, $currency ) . ( SaleRepository::STATUS_COMPLETED === $sale['status'] ? '' : ' ' . __( '(not counted: the sale is not completed)', 'product-qrcode-barcode-generator' ) ), '' );
		}

		$rows[] = array( __( 'Stock', 'product-qrcode-barcode-generator' ), self::stock_text( $sale ), '' );

		echo '<table class="widefat striped pqbg-sales__detail"><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr><th scope="row">' . esc_html( $row[0] ) . '</th><td>' . esc_html( $row[1] ) . ( '' !== $row[2] ? ' ' . $row[2] : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $row[2] is built with esc_url()/esc_html() in product_link().
		}

		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Timeline', 'product-qrcode-barcode-generator' ) . '</h2><table class="widefat striped pqbg-sales__timeline"><tbody>';
		/* translators: 1: date and time, 2: seller. */
		echo '<tr><th scope="row">' . esc_html__( 'Sold', 'product-qrcode-barcode-generator' ) . '</th><td>' . esc_html( sprintf( __( '%1$s by %2$s', 'product-qrcode-barcode-generator' ), SalePresenter::datetime( $sale['created_at_gmt'] ), SalePresenter::seller( $sale ) ) ) . '</td></tr>';

		if ( SaleRepository::STATUS_VOIDED === $sale['status'] ) {
			$reason = SaleService::VOID_REASON_UNDO === $sale['void_reason'] ? __( 'Undone by the seller', 'product-qrcode-barcode-generator' ) : (string) $sale['void_reason'];
			/* translators: 1: date and time, 2: user, 3: reason. */
			echo '<tr><th scope="row">' . esc_html__( 'Voided', 'product-qrcode-barcode-generator' ) . '</th><td>' . esc_html( sprintf( __( '%1$s by %2$s — “%3$s”', 'product-qrcode-barcode-generator' ), SalePresenter::datetime( $sale['voided_at_gmt'] ), SalePresenter::user_label( (int) $sale['voided_by'] ), $reason ) ) . '</td></tr>';
		}

		if ( null !== $sale['failure_code'] && '' !== $sale['failure_code'] ) {
			echo '<tr><th scope="row">' . esc_html__( 'Failure', 'product-qrcode-barcode-generator' ) . '</th><td><code>' . esc_html( (string) $sale['failure_code'] ) . '</code></td></tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: request ID, 2: source. */
				__( 'Request %1$s · source %2$s. Prices, names and cost are snapshots taken at the moment of sale.', 'product-qrcode-barcode-generator' ),
				(string) $sale['request_id'],
				(string) $sale['source']
			)
		) . '</p>';
	}

	/**
	 * The void confirmation screen.
	 *
	 * @param array<string, mixed> $sale Row.
	 */
	private static function render_void( array $sale ): void {
		$id       = (int) $sale['id'];
		$currency = (string) $sale['currency'];

		/* translators: %s: sale number. */
		echo '<h1>' . esc_html( sprintf( __( 'Void sale #%s?', 'product-qrcode-barcode-generator' ), $id ) ) . '</h1>';
		self::render_message();

		if ( SaleRepository::STATUS_COMPLETED !== $sale['status'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Only a completed sale can be voided.', 'product-qrcode-barcode-generator' ) . '</p></div>';
			echo '<p><a class="button" href="' . esc_url( self::detail_url( $id ) ) . '">' . esc_html__( 'Back to the sale', 'product-qrcode-barcode-generator' ) . '</a></p>';
			return;
		}

		/* translators: 1: item, 2: quantity, 3: unit price, 4: total, 5: payment method, 6: seller. */
		echo '<p><strong>' . esc_html( sprintf( __( '%1$s · %2$s × %3$s = %4$s · %5$s · %6$s', 'product-qrcode-barcode-generator' ), SalePresenter::item( $sale ), number_format_i18n( (int) $sale['quantity'] ), SalePresenter::money( $sale['unit_price'], $currency ), SalePresenter::money( $sale['line_total'], $currency ), PaymentMethods::label( $sale['payment_method'] ), SalePresenter::seller( $sale ) ) ) . '</strong></p>';

		$holder = (int) $sale['stock_holder_id'];
		$stock  = $holder > 0 ? SaleRepository::read_stock( $holder ) : null;

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::VOID_ACTION ) . '">';
		echo '<input type="hidden" name="sale" value="' . esc_attr( (string) $id ) . '">';
		echo '<input type="hidden" name="' . esc_attr( Permissions::NONCE_FIELD ) . '" value="' . esc_attr( wp_create_nonce( Permissions::nonce_action( 'void_sale_' . $id ) ) ) . '">';
		echo '<p><label for="pqbg-void-reason"><strong>' . esc_html__( 'Reason (required)', 'product-qrcode-barcode-generator' ) . '</strong></label><br>';
		echo '<textarea id="pqbg-void-reason" name="reason" rows="3" cols="60" maxlength="' . esc_attr( (string) self::REASON_MAX ) . '" required></textarea></p>';
		echo '<p><label><input type="checkbox" name="restock" value="1" checked> ';
		echo esc_html(
			null === $stock
				/* translators: %s: quantity. */
				? sprintf( __( 'Return %s to stock', 'product-qrcode-barcode-generator' ), number_format_i18n( (int) $sale['quantity'] ) )
				/* translators: 1: quantity, 2: stock now. */
				: sprintf( __( 'Return %1$s to stock (stock now %2$s)', 'product-qrcode-barcode-generator' ), number_format_i18n( (int) $sale['quantity'] ), number_format_i18n( $stock ) )
		);
		echo '</label></p>';
		echo '<p class="description">' . esc_html__( 'The sale stays in the history, marked as voided, with your name, the time and the reason. It no longer counts in totals.', 'product-qrcode-barcode-generator' ) . '</p>';
		submit_button( __( 'Void sale', 'product-qrcode-barcode-generator' ), 'primary', 'submit', false );
		echo ' <a class="button" href="' . esc_url( self::detail_url( $id ) ) . '">' . esc_html__( 'Cancel', 'product-qrcode-barcode-generator' ) . '</a>';
		echo '</form>';
	}

	/**
	 * A result message from the fixed list, if the URL names one.
	 */
	private static function render_message(): void {
		$code     = isset( $_GET[ self::MESSAGE_ARG ] ) && is_string( $_GET[ self::MESSAGE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::MESSAGE_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only, from a fixed list.
		$messages = array(
			'voided_restocked'    => array( 'success', __( 'Sale voided. The quantity was returned to stock.', 'product-qrcode-barcode-generator' ) ),
			'voided'              => array( 'success', __( 'Sale voided. Stock was not changed.', 'product-qrcode-barcode-generator' ) ),
			'not_voidable'        => array( 'warning', __( 'This sale was already voided or cannot be voided. Nothing was changed.', 'product-qrcode-barcode-generator' ) ),
			'reason_required'     => array( 'error', __( 'Enter a reason for voiding this sale.', 'product-qrcode-barcode-generator' ) ),
			/* translators: %d: maximum length. */
			'reason_long'         => array( 'error', sprintf( __( 'The reason can be at most %d characters.', 'product-qrcode-barcode-generator' ), self::REASON_MAX ) ),
			'busy'                => array( 'error', __( 'Someone is selling this item right now. Try again in a moment. Nothing was changed.', 'product-qrcode-barcode-generator' ) ),
			'restock_unavailable' => array( 'error', __( 'The quantity cannot be returned to stock because stock tracking changed for this product. Untick "Return to stock" to void the sale without changing stock. Nothing was changed.', 'product-qrcode-barcode-generator' ) ),
			'forbidden'           => array( 'error', __( 'You are not allowed to void sales.', 'product-qrcode-barcode-generator' ) ),
			'failed'              => array( 'error', __( 'The sale could not be voided. Nothing was changed.', 'product-qrcode-barcode-generator' ) ),
		);

		if ( isset( $messages[ $code ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $messages[ $code ][0] ) . ' is-dismissible"><p>' . esc_html( $messages[ $code ][1] ) . '</p></div>';
		}
	}

	/**
	 * "Stock 5 → 3 (product #12)" for a sale.
	 *
	 * @param array<string, mixed> $sale Row.
	 */
	private static function stock_text( array $sale ): string {
		$holder = (int) $sale['stock_holder_id'];
		$text   = null === $sale['stock_before'] ? '—' : number_format_i18n( (int) $sale['stock_before'] ) . ( null === $sale['stock_after'] ? '' : ' → ' . number_format_i18n( (int) $sale['stock_after'] ) );

		/* translators: %d: product ID holding the stock. */
		return $holder > 0 ? $text . ' ' . sprintf( __( '(stock held by #%d)', 'product-qrcode-barcode-generator' ), $holder ) : $text;
	}

	/**
	 * Edit link for the product, if it still exists and the user may edit it.
	 *
	 * @param int $parent_id Product (or the variation's parent).
	 * @param int $item_id   Item sold.
	 * @return string Escaped HTML, or ''.
	 */
	private static function product_link( int $parent_id, int $item_id ): string {
		$exists = in_array( get_post_type( $item_id ), array( 'product', 'product_variation' ), true ) && 'product' === get_post_type( $parent_id ) && 'trash' !== get_post_status( $parent_id );
		$link   = $exists && current_user_can( 'edit_post', $parent_id ) ? get_edit_post_link( $parent_id, 'raw' ) : '';

		if ( ! $exists ) {
			return '<em>' . esc_html__( '(product no longer exists)', 'product-qrcode-barcode-generator' ) . '</em>';
		}

		/* translators: %d: product ID. */
		return is_string( $link ) && '' !== $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( sprintf( __( 'Edit product #%d', 'product-qrcode-barcode-generator' ), $parent_id ) ) . '</a>' : '';
	}

	/**
	 * The ?sale= argument, or null.
	 */
	private static function sale_arg(): ?int {
		$raw = isset( $_GET['sale'] ) && is_string( $_GET['sale'] ) ? wp_unslash( $_GET['sale'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- digits only, checked below.

		return '' !== $raw && ctype_digit( $raw ) && strlen( $raw ) < 20 ? (int) $raw : null;
	}

	/**
	 * Whether the void confirmation was requested.
	 */
	private static function is_void_view() : bool {
		return isset( $_GET[ self::VIEW_ARG ] ) && 'void' === $_GET[ self::VIEW_ARG ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects a read-only view.
	}

	/**
	 * 303 redirect with a message code.
	 *
	 * @param string $url     Target.
	 * @param string $message Message code.
	 */
	private static function redirect( string $url, string $message ): void {
		wp_safe_redirect( add_query_arg( self::MESSAGE_ARG, $message, $url ), 303 );
		exit;
	}
}
