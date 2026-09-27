<?php
/**
 * In-store reports (Phase 9B): QR & Barcodes → In-store reports, right after In-store
 * sales (under WooCommerce until Phase 10B; the page and its URLs are unchanged).
 *
 *   admin.php?page=pqbg-reports                        the Summary tab (called "Dashboard"
 *                                                      until Phase 10B; &tab=dashboard still opens it)
 *   admin.php?page=pqbg-reports&tab={report}&{options}  a report (ReportData::REPORTS)
 *
 * Everything is GET and read-only. Access: pqbg_view_all_sales (administrators and
 * shop managers). Cost, profit, margin and stock value at cost exist only for
 * pqbg_view_costs (administrators): for anyone else those tabs, cards, charts,
 * columns and CSV columns are not built at all, and asking for them (the profit
 * tab, sorting by a cost column) is refused with 403 (decision D9).
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * Reports screens.
 */
final class ReportsAdmin {

	const SLUG = AdminUrl::REPORTS;

	/** The Summary tab (Phase 10B; the tab value was "dashboard" before, which still opens it). */
	const SUMMARY = 'summary';

	/** Presets offered on the Summary tab. */
	const SUMMARY_PRESETS = array( 'today', 'this_week', 'this_month' );

	/** @var array<string, mixed>|null Context of this request (validated on load). */
	private static ?array $ctx = null;

	/**
	 * Hooks used on admin requests.
	 */
	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_' . ReportsExport::ACTION, array( ReportsExport::class, 'handle' ) );
		add_action( 'admin_post_' . ReportPrint::ACTION, array( ReportPrint::class, 'handle' ) );
	}

	/**
	 * The reports stylesheet, on this page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue( $hook ): void {
		if ( AdminMenu::is_page( AdminUrl::REPORTS, (string) $hook ) ) {
			wp_enqueue_style( 'pqbg-reports', PQBG_PLUGIN_URL . 'assets/pqbg-reports.css', array(), PQBG_VERSION );
		}
	}

	/**
	 * Tabs: key => label. The profit tab exists only for pqbg_view_costs.
	 *
	 * @param bool $costs Whether the user may see costs.
	 * @return array<string, string>
	 */
	public static function tabs( bool $costs ): array {
		$tabs = array(
			'summary'    => __( 'Summary', 'product-qrcode-barcode-generator' ),
			'sales'      => __( 'Sales over time', 'product-qrcode-barcode-generator' ),
			'products'   => __( 'Products', 'product-qrcode-barcode-generator' ),
			'categories' => __( 'Categories', 'product-qrcode-barcode-generator' ),
			'sellers'    => __( 'Sellers', 'product-qrcode-barcode-generator' ),
			'peak'       => __( 'Peak times', 'product-qrcode-barcode-generator' ),
			'eod'        => __( 'End of day', 'product-qrcode-barcode-generator' ),
			'profit'     => __( 'Profit & margin', 'product-qrcode-barcode-generator' ),
			'voids'      => __( 'Voids & failed', 'product-qrcode-barcode-generator' ),
			'stock'      => __( 'Stock', 'product-qrcode-barcode-generator' ),
			'dead'       => __( 'Dead stock', 'product-qrcode-barcode-generator' ),
		);

		if ( ! $costs ) {
			unset( $tabs['profit'] );
		}

		return $tabs;
	}

	/**
	 * The validated context of a request (used by the screens, the CSV and the print page).
	 *
	 * @param array<string, mixed> $args  Unslashed query arguments.
	 * @param bool                 $costs Whether the user may see costs.
	 * @param int|null             $now   Unix time "now" (tests).
	 * @return array<string, mixed> tab, period, costs, group, view, mode, by, days, state, cat, metric,
	 *                              cost_request (true when a cost-only thing was asked for).
	 */
	public static function context( array $args, bool $costs, ?int $now = null ): array {
		$get  = static fn( string $key ): string => isset( $args[ $key ] ) && is_string( $args[ $key ] ) ? trim( $args[ $key ] ) : '';
		$tab  = $get( 'tab' );
		$tab  = 'dashboard' === $tab ? self::SUMMARY : $tab; // The Phase 9B name of the tab.
		$tab  = self::SUMMARY === $tab || in_array( $tab, ReportData::REPORTS, true ) ? $tab : self::SUMMARY;
		$cost = in_array( $tab, ReportData::COST_REPORTS, true ) || in_array( sanitize_key( $get( 'orderby' ) ), ReportData::COST_KEYS, true );

		if ( self::SUMMARY === $tab ) {
			$preset = in_array( $get( 'range' ), array_merge( self::SUMMARY_PRESETS, array( 'custom' ) ), true ) ? $get( 'range' ) : 'today';
		} else {
			$preset = $get( 'range' );
		}

		$default = in_array( $tab, array( self::SUMMARY, 'eod' ), true ) ? 'today' : 'this_month';
		$days    = $get( 'days' );

		return array(
			'tab'          => $tab,
			'period'       => ReportPeriod::resolve( $preset, $get( 'from' ), $get( 'to' ), $now, $default ),
			'costs'        => $costs,
			'group'        => in_array( $get( 'group' ), ReportPeriod::GROUPINGS, true ) ? $get( 'group' ) : '',
			'view'         => 'product' === $get( 'view' ) ? 'product' : 'item',
			'mode'         => 'slow' === $get( 'mode' ) ? 'slow' : 'best',
			'by'           => in_array( $get( 'by' ), array( 'product', 'category' ), true ) ? $get( 'by' ) : 'period',
			'days'         => ctype_digit( $days ) && in_array( (int) $days, array( 0, 30, 60, 90 ), true ) ? (int) $days : 30,
			'state'        => in_array( $get( 'state' ), array( 'low', 'out', 'instock', 'negative', 'nocode' ), true ) ? $get( 'state' ) : '',
			'cat'          => ctype_digit( $get( 'cat' ) ) && strlen( $get( 'cat' ) ) < 20 ? (int) $get( 'cat' ) : 0,
			'metric'       => 'revenue' === $get( 'metric' ) ? 'revenue' : 'count',
			'cost_request' => $cost,
		);
	}

	/**
	 * Query arguments that reproduce a context (links, the CSV and print URLs).
	 *
	 * @param array<string, mixed> $ctx    Context.
	 * @param array<string, string> $change Arguments to change.
	 * @return array<string, string>
	 */
	public static function args( array $ctx, array $change = array() ): array {
		$p    = $ctx['period'];
		$args = array(
			'tab'   => $ctx['tab'],
			'range' => $p['preset'],
		);

		if ( 'custom' === $p['preset'] ) {
			$args['from'] = $p['from'];
			$args['to']   = $p['to'];
		}

		$defaults = array(
			'group'  => '',
			'view'   => 'item',
			'mode'   => 'best',
			'by'     => 'period',
			'days'   => 30,
			'state'  => '',
			'cat'    => 0,
			'metric' => 'count',
		);

		foreach ( $defaults as $key => $default ) {
			if ( $ctx[ $key ] !== $default ) {
				$args[ $key ] = (string) $ctx[ $key ];
			}
		}

		$args = array_merge( $args, $change );

		if ( isset( $change['range'] ) && 'custom' !== $change['range'] ) {
			unset( $args['from'], $args['to'] );
		}

		return array_filter( $args, static fn( $v ) => '' !== $v );
	}

	/**
	 * load-{page}: access and the cost-only refusals, before any output.
	 */
	public static function load(): void {
		if ( ! Permissions::can_view_all_sales() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'product-qrcode-barcode-generator' ), 403 );
		}

		$costs = Permissions::can_view_costs();
		$ctx   = self::context( wp_unslash( $_GET ), $costs ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only view; every value is validated by context().

		if ( $ctx['cost_request'] && ! $costs ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to see costs and profit.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 403 ) );
		}

		self::$ctx = $ctx;
	}

	/**
	 * Renders the page.
	 */
	public static function render(): void {
		if ( ! Permissions::can_view_all_sales() || null === self::$ctx ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'product-qrcode-barcode-generator' ), 403 );
		}

		$ctx = self::$ctx;

		echo '<div class="wrap pqbg-reports">';
		AdminMenu::render_nav( AdminUrl::REPORTS );
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'In-store reports', 'product-qrcode-barcode-generator' ) . '</h1>';

		if ( self::SUMMARY !== $ctx['tab'] ) {
			echo ' <a class="page-title-action" href="' . esc_url( ReportsExport::url( self::args( $ctx ) ) ) . '">' . esc_html__( 'Export CSV', 'product-qrcode-barcode-generator' ) . '</a>';
		}

		if ( 'eod' === $ctx['tab'] ) {
			echo ' <a class="page-title-action" href="' . esc_url( ReportPrint::url( self::args( $ctx ) ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Print', 'product-qrcode-barcode-generator' ) . '</a>';
		}

		echo '<hr class="wp-header-end">';
		self::render_tabs( $ctx );
		self::render_rules( $ctx );

		if ( ! in_array( $ctx['tab'], array( 'stock', 'dead' ), true ) ) {
			self::render_period( $ctx );
		}

		switch ( $ctx['tab'] ) {
			case self::SUMMARY:
				self::render_summary( $ctx );
				break;
			case 'peak':
				self::render_peak( $ctx );
				break;
			case 'eod':
				self::render_eod( $ctx );
				break;
			default:
				self::render_report( $ctx );
		}

		echo '</div>';
	}

	/**
	 * The tab bar.
	 *
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_tabs( array $ctx ): void {
		echo '<nav class="nav-tab-wrapper pqbg-reports__tabs" aria-label="' . esc_attr__( 'Reports', 'product-qrcode-barcode-generator' ) . '">';

		foreach ( self::tabs( $ctx['costs'] ) as $key => $label ) {
			$args = array( 'tab' => $key );

			if ( ! in_array( $key, array( 'stock', 'dead' ), true ) && self::SUMMARY !== $key && self::SUMMARY !== $ctx['tab'] ) {
				$args = self::args( array_merge( $ctx, array( 'tab' => $key, 'group' => '', 'view' => 'item', 'mode' => 'best', 'by' => 'period', 'metric' => 'count' ) ) );
			}

			$current = $key === $ctx['tab'];
			echo '<a href="' . esc_url( AdminUrl::reports( $args ) ) . '" class="nav-tab' . ( $current ? ' nav-tab-active' : '' ) . '"' . ( $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}

		echo '</nav>';
	}

	/**
	 * The counting rules, stated on every screen.
	 *
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_rules( array $ctx ): void {
		$rules = array(
			__( 'In-store (scan) sales only — online orders are in WooCommerce → Analytics.', 'product-qrcode-barcode-generator' ),
			__( 'Revenue, items and sales count completed sales only; voided and failed sales are not in revenue.', 'product-qrcode-barcode-generator' ),
			__( 'Amounts are as recorded at the moment of sale. A "sale" is one scanned item with its quantity.', 'product-qrcode-barcode-generator' ),
			/* translators: %s: site timezone, e.g. Asia/Kolkata. */
			sprintf( __( 'Days are in the site timezone (%s).', 'product-qrcode-barcode-generator' ), wp_timezone_string() ),
		);

		if ( in_array( $ctx['tab'], array( 'stock', 'dead' ), true ) ) {
			$rules = array( __( 'Stock is the current stock; prices and costs are the current ones.', 'product-qrcode-barcode-generator' ) );
		}

		if ( $ctx['costs'] && ! in_array( $ctx['tab'], array( 'stock', 'dead', 'eod', 'voids', 'peak' ), true ) ) {
			$rules[] = ReportData::unknown_cost_rule();
		}

		echo '<div class="notice notice-info inline pqbg-reports__rules"><p>' . esc_html( implode( ' ', $rules ) ) . '</p></div>';
	}

	/**
	 * The period picker (presets and custom dates) and the comparison period.
	 *
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_period( array $ctx ): void {
		$p       = $ctx['period'];
		$labels  = ReportPeriod::labels();
		$presets = self::SUMMARY === $ctx['tab'] ? self::SUMMARY_PRESETS : ( 'eod' === $ctx['tab'] ? array( 'today', 'yesterday' ) : array_keys( $labels ) );
		$links   = array();

		foreach ( $presets as $preset ) {
			$current = $preset === $p['preset'];
			$links[] = '<li><a href="' . esc_url( AdminUrl::reports( self::args( $ctx, array( 'range' => $preset ) ) ) ) . '"' . ( $current ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $labels[ $preset ] ) . '</a></li>';
		}

		echo '<div class="pqbg-reports__period">';
		echo '<ul class="subsubsub">' . implode( ' | ', $links ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '<form method="get" action="' . esc_url( AdminUrl::admin_php() ) . '" class="pqbg-reports__custom">';

		foreach ( self::args( $ctx, array( 'range' => 'custom' ) ) as $key => $value ) {
			if ( ! in_array( $key, array( 'from', 'to', 'orderby', 'order', 'paged' ), true ) ) {
				echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
			}
		}

		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<label>' . esc_html__( 'From', 'product-qrcode-barcode-generator' ) . ' <input type="date" name="from" value="' . esc_attr( $p['from'] ) . '"></label> ';
		echo '<label>' . esc_html__( 'To', 'product-qrcode-barcode-generator' ) . ' <input type="date" name="to" value="' . esc_attr( $p['to'] ) . '"></label> ';
		submit_button( __( 'Apply', 'product-qrcode-barcode-generator' ), 'secondary', '', false );
		echo '</form>';
		echo '<p class="pqbg-reports__span"><strong>' . esc_html( ReportPeriod::span_label( $p['from'], $p['to'] ) ) . '</strong>';

		if ( self::SUMMARY === $ctx['tab'] ) {
			echo ' · ' . esc_html( self::compare_label( $p ) );
		}

		echo '</p>';

		if ( $p['swapped'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The start date was after the end date, so they were swapped.', 'product-qrcode-barcode-generator' ) . '</p></div>';
		}

		if ( $p['clamped'] ) {
			/* translators: %d: days. */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'A range can be at most %d days; the start date was moved forward.', 'product-qrcode-barcode-generator' ), ReportPeriod::MAX_DAYS ) ) . '</p></div>';
		}

		echo '</div>';
	}

	/**
	 * "Compared with: 1–26 Aug 2026 (up to 14:05)".
	 *
	 * @param array<string, mixed> $p Period.
	 */
	public static function compare_label( array $p ): string {
		$c    = $p['compare'];
		$text = sprintf(
			/* translators: %s: date range. */
			__( 'Compared with %s', 'product-qrcode-barcode-generator' ),
			ReportPeriod::span_label( $c['from'], $c['to'] )
		);

		if ( '' !== $c['partial_until'] ) {
			/* translators: %s: time of day. */
			$text .= ' ' . sprintf( __( '(up to %s, the same point in time)', 'product-qrcode-barcode-generator' ), substr( $c['partial_until'], 11 ) );
		}

		return $text;
	}

	/**
	 * The Summary tab (Phase 9B's owner dashboard, renamed in Phase 10B).
	 *
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_summary( array $ctx ): void {
		$costs   = $ctx['costs'];
		$data    = self::dashboard_data( $ctx['period'], $costs );
		$now     = $data['now'];
		$then    = $data['then'];
		$cards   = array(
			array( __( 'Revenue', 'product-qrcode-barcode-generator' ), SalePresenter::money( $now['revenue'] ), ReportsQuery::change( $now['revenue'], $then['revenue'] ), false ),
			array( __( 'Sales', 'product-qrcode-barcode-generator' ), number_format_i18n( $now['count'] ), ReportsQuery::change( $now['count'], $then['count'] ), false ),
			array( __( 'Items sold', 'product-qrcode-barcode-generator' ), number_format_i18n( $now['items'] ), ReportsQuery::change( $now['items'], $then['items'] ), false ),
			array( __( 'Average sale', 'product-qrcode-barcode-generator' ), null === $now['average'] ? '—' : SalePresenter::money( $now['average'] ), ReportsQuery::change( $now['average'], $then['average'] ), false ),
		);

		if ( $costs ) {
			$cards[] = array( __( 'Gross profit', 'product-qrcode-barcode-generator' ), SalePresenter::money( $now['profit'] ), ReportsQuery::change( $now['profit'], $then['profit'] ), false );
			$cards[] = array( __( 'Margin', 'product-qrcode-barcode-generator' ), null === $now['margin'] ? '—' : number_format_i18n( $now['margin'], 1 ) . '%', null === $now['margin'] || null === $then['margin'] ? null : round( $now['margin'] - $then['margin'], 1 ), true );
		}

		echo '<div class="pqbg-cards">';

		foreach ( $cards as $card ) {
			echo '<div class="pqbg-card"><div class="pqbg-card__label">' . esc_html( $card[0] ) . '</div><div class="pqbg-card__value">' . esc_html( $card[1] ) . '</div>';
			echo '<div class="pqbg-card__change">' . esc_html( self::change_text( $card[2], $card[3] ) ) . '</div></div>';
		}

		echo '</div>';

		if ( $costs && $now['unknown'] > 0 ) {
			echo '<p class="description pqbg-reports__unknown">' . esc_html( self::unknown_text( $now ) ) . '</p>';
		}

		// Payment split.
		$split = array();

		foreach ( $data['methods'] as $key => $m ) {
			$split[] = array(
				'label' => PaymentMethods::label( '' === $key ? null : (string) $key ),
				'value' => (float) $m['revenue'],
				/* translators: 1: amount, 2: number of sales. */
				'text'  => sprintf( __( '%1$s (%2$s)', 'product-qrcode-barcode-generator' ), SalePresenter::money( $m['revenue'] ), number_format_i18n( $m['count'] ) ),
			);
		}

		echo '<div class="pqbg-reports__grid">';

		// Sales over time.
		$points = array();

		foreach ( $data['buckets'] as $i => $b ) {
			$points[] = array(
				'label' => $b['label'],
				'value' => (float) $data['series'][ $i ]['revenue'],
				/* translators: 1: amount, 2: number of sales. */
				'text'  => sprintf( __( '%1$s (%2$s sales)', 'product-qrcode-barcode-generator' ), SalePresenter::money( $data['series'][ $i ]['revenue'] ), number_format_i18n( $data['series'][ $i ]['count'] ) ),
			);
		}

		echo '<section class="pqbg-panel pqbg-panel--wide"><h2>' . esc_html__( 'Sales over time', 'product-qrcode-barcode-generator' ) . '</h2>';
		echo ReportChart::columns( $points, __( 'Revenue over time', 'product-qrcode-barcode-generator' ), __( 'Revenue of completed sales per period.', 'product-qrcode-barcode-generator' ), array( __CLASS__, 'axis_money' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ReportChart escapes.
		self::data_table( array( __( 'Period', 'product-qrcode-barcode-generator' ), __( 'Revenue (sales)', 'product-qrcode-barcode-generator' ) ), array_map( static fn( $pt ) => array( $pt['label'], $pt['text'] ), $points ) );
		echo '</section>';

		echo '<section class="pqbg-panel"><h2>' . esc_html__( 'Paid by', 'product-qrcode-barcode-generator' ) . '</h2>';
		echo ReportChart::bars( $split, __( 'Revenue by payment method', 'product-qrcode-barcode-generator' ), __( 'Completed sales in this period, by how the customer paid.', 'product-qrcode-barcode-generator' ), 440 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ReportChart escapes.
		self::data_table( array( __( 'Paid by', 'product-qrcode-barcode-generator' ), __( 'Revenue (sales)', 'product-qrcode-barcode-generator' ) ), array_map( static fn( $s ) => array( $s['label'], $s['text'] ), $split ) );
		echo '</section>';

		// Top products and sellers.
		foreach ( array( 'products' => __( 'Top 5 products', 'product-qrcode-barcode-generator' ), 'sellers' => __( 'Top sellers', 'product-qrcode-barcode-generator' ) ) as $key => $title ) {
			echo '<section class="pqbg-panel"><h2>' . esc_html( $title ) . '</h2>';

			if ( array() === $data[ $key ] ) {
				echo '<p>' . esc_html__( 'No completed sales in this period.', 'product-qrcode-barcode-generator' ) . '</p>';
			} else {
				echo '<ol class="pqbg-top">';
				foreach ( $data[ $key ] as $row ) {
					/* translators: 1: name, 2: amount, 3: number of items. */
					echo '<li>' . esc_html( sprintf( __( '%1$s — %2$s (%3$s items)', 'product-qrcode-barcode-generator' ), $row['name'], SalePresenter::money( $row['revenue'] ), number_format_i18n( $row['items'] ) ) ) . '</li>';
				}
				echo '</ol>';
			}

			echo '</section>';
		}

		// Alerts.
		$alerts = array(
			/* translators: %s: number of items. */
			array( sprintf( _n( '%s item low on stock', '%s items low on stock', $data['low'], 'product-qrcode-barcode-generator' ), number_format_i18n( $data['low'] ) ), AdminUrl::reports( array( 'tab' => 'stock', 'state' => 'low' ) ), $data['low'] > 0 ),
			/* translators: %s: number of items. */
			array( sprintf( _n( '%s item out of stock', '%s items out of stock', $data['out'], 'product-qrcode-barcode-generator' ), number_format_i18n( $data['out'] ) ), AdminUrl::reports( array( 'tab' => 'stock', 'state' => 'out' ) ), $data['out'] > 0 ),
			/* translators: %s: number of sales. */
			array( sprintf( _n( '%s voided sale in this period', '%s voided sales in this period', $data['voided'], 'product-qrcode-barcode-generator' ), number_format_i18n( $data['voided'] ) ), AdminUrl::reports( self::args( array_merge( $ctx, array( 'tab' => 'voids' ) ) ) ), $data['voided'] > 0 ),
			/* translators: %s: number of items. */
			array( sprintf( _n( '%s active item without a QR code', '%s active items without a QR code', $data['nocode'], 'product-qrcode-barcode-generator' ), number_format_i18n( $data['nocode'] ) ), AdminUrl::reports( array( 'tab' => 'stock', 'state' => 'nocode' ) ), $data['nocode'] > 0 ),
		);

		echo '<section class="pqbg-panel pqbg-alerts"><h2>' . esc_html__( 'Alerts', 'product-qrcode-barcode-generator' ) . '</h2><ul>';

		foreach ( $alerts as $alert ) {
			echo '<li class="' . ( $alert[2] ? 'pqbg-alert pqbg-alert--on' : 'pqbg-alert' ) . '"><a href="' . esc_url( $alert[1] ) . '">' . esc_html( $alert[0] ) . '</a></li>';
		}

		echo '</ul>';
		echo '<p class="description"><a href="' . esc_url( AdminUrl::products() ) . '">' . esc_html__( 'Products list', 'product-qrcode-barcode-generator' ) . '</a> · ' . esc_html__( 'Stock alerts use the WooCommerce low and out-of-stock thresholds (published and private items).', 'product-qrcode-barcode-generator' ) . '</p>';
		echo '</section></div>';
	}

	/**
	 * Everything the Summary tab shows (and the plugin Dashboard's "today" figures, Phase 10B), in as few queries as possible: one grouped scan of the
	 * period (cards, payment split, chart, voids and top sellers), one of the comparison
	 * period, one per product, the stock holders and the missing codes.
	 *
	 * @param array<string, mixed> $period Period.
	 * @param bool                 $costs  Costs.
	 * @return array<string, mixed>
	 */
	public static function dashboard_data( array $period, bool $costs ): array {
		$buckets = ReportPeriod::buckets( $period, ReportPeriod::default_grouping( $period ) );
		$sum     = ReportsQuery::summary( ReportPeriod::where_filters( $period ), $costs, $buckets, true );
		$then    = ReportsQuery::summary( ReportPeriod::where_filters( $period, true ), $costs );
		$names   = SalesQuery::sellers();
		$sellers = array();

		foreach ( $sum['sellers'] as $id => $m ) {
			$name      = $names[ $id ] ?? '';
			$sellers[] = $m + array( 'name' => SalePresenter::seller( array( 'seller_id' => $id, 'seller_name' => '' === $name ? null : $name ) ) );
		}

		$holders = array_filter( StockQuery::holders( false ), static fn( $h ) => in_array( $h['status'], array( 'publish', 'private' ), true ) );

		return array(
			'now'      => $sum['all'],
			'then'     => $then['all'],
			'methods'  => $sum['methods'],
			'buckets'  => $buckets,
			'series'   => $sum['series'],
			'voided'   => $sum['voided'],
			'products' => ReportsQuery::top( ReportsQuery::products( ReportPeriod::where_filters( $period ), false, false, 5 ), 'revenue', 5 ),
			'sellers'  => ReportsQuery::top( $sellers, 'revenue', 5 ),
			'low'      => count( array_filter( $holders, static fn( $h ) => 'low' === $h['state'] ) ),
			'out'      => count( array_filter( $holders, static fn( $h ) => 'out' === $h['state'] ) ),
			'nocode'   => count( StockQuery::missing_codes() ),
		);
	}

	/**
	 * A report tab: options, chart, table, totals and notes.
	 *
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_report( array $ctx ): void {
		$tab = $ctx['tab'];

		self::render_options( $ctx );

		$data = ReportData::build( $tab, $ctx );

		if ( is_wp_error( $data ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $data->get_error_message() ) . '</p></div>';
			return;
		}

		self::render_chart( $ctx, $data );

		if ( 'voids' === $tab ) {
			self::render_void_summary( $data['voids'] );
		}

		if ( 'stock' === $tab && isset( $data['totals'] ) ) {
			self::render_stock_totals( $data['totals'], $ctx['costs'] );
		}

		self::table( $data );
	}

	/**
	 * View options of a report (grouping, toggles, filters).
	 *
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_options( array $ctx ): void {
		$switch = static function ( string $key, array $choices ) use ( $ctx ): void {
			$links = array();
			foreach ( $choices as $value => $label ) {
				$current = (string) $ctx[ $key ] === (string) $value;
				$links[] = $current ? '<strong aria-current="true">' . esc_html( $label ) . '</strong>' : '<a href="' . esc_url( AdminUrl::reports( self::args( $ctx, array( $key => (string) $value ) ) ) ) . '">' . esc_html( $label ) . '</a>';
			}
			echo '<p class="pqbg-reports__switch">' . implode( ' | ', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		};
		$groups = array(
			'hour'  => __( 'By hour', 'product-qrcode-barcode-generator' ),
			'day'   => __( 'By day', 'product-qrcode-barcode-generator' ),
			'week'  => __( 'By week', 'product-qrcode-barcode-generator' ),
			'month' => __( 'By month', 'product-qrcode-barcode-generator' ),
		);

		switch ( $ctx['tab'] ) {
			case 'sales':
				$group = ReportPeriod::grouping( $ctx['period'], $ctx['group'] );
				$avail = array_filter( $groups, static fn( $g ) => ReportPeriod::grouping( $ctx['period'], $g ) === $g, ARRAY_FILTER_USE_KEY );
				$switch( 'group', $avail + array() );
				if ( $group !== $ctx['group'] && '' !== $ctx['group'] ) {
					echo '<p class="description">' . esc_html__( 'That grouping does not suit this period, so the nearest one is shown.', 'product-qrcode-barcode-generator' ) . '</p>';
				}
				break;
			case 'products':
				$switch(
					'mode',
					array(
						'best' => __( 'Best sellers', 'product-qrcode-barcode-generator' ),
						'slow' => __( 'Slow sellers', 'product-qrcode-barcode-generator' ),
					)
				);
				if ( 'best' === $ctx['mode'] ) {
					$switch(
						'view',
						array(
							'item'    => __( 'By item (variation)', 'product-qrcode-barcode-generator' ),
							'product' => __( 'By product', 'product-qrcode-barcode-generator' ),
						)
					);
				}
				break;
			case 'profit':
				$switch(
					'by',
					array(
						'period'   => __( 'By period', 'product-qrcode-barcode-generator' ),
						'product'  => __( 'By item', 'product-qrcode-barcode-generator' ),
						'category' => __( 'By category', 'product-qrcode-barcode-generator' ),
					)
				);
				if ( 'period' === $ctx['by'] ) {
					$avail = array_filter( $groups, static fn( $g ) => ReportPeriod::grouping( $ctx['period'], $g ) === $g, ARRAY_FILTER_USE_KEY );
					$switch( 'group', $avail );
				}
				break;
			case 'dead':
				$switch(
					'days',
					array(
						30 => __( 'No sale in 30 days', 'product-qrcode-barcode-generator' ),
						60 => __( '60 days', 'product-qrcode-barcode-generator' ),
						90 => __( '90 days', 'product-qrcode-barcode-generator' ),
						0  => __( 'Never sold', 'product-qrcode-barcode-generator' ),
					)
				);
				break;
			case 'stock':
				echo '<form method="get" action="' . esc_url( AdminUrl::admin_php() ) . '" class="pqbg-reports__custom">';
				echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="stock">';
				echo '<label>' . esc_html__( 'Category', 'product-qrcode-barcode-generator' ) . ' ';
				wp_dropdown_categories(
					array(
						'taxonomy'        => 'product_cat',
						'name'            => 'cat',
						'selected'        => $ctx['cat'],
						'show_option_all' => __( 'All', 'product-qrcode-barcode-generator' ),
						'hierarchical'    => true,
						'hide_empty'      => false,
						'value_field'     => 'term_id',
					)
				);
				echo '</label> <label>' . esc_html__( 'Show', 'product-qrcode-barcode-generator' ) . ' <select name="state">';
				foreach ( array(
					''         => __( 'All', 'product-qrcode-barcode-generator' ),
					'low'      => __( 'Low stock', 'product-qrcode-barcode-generator' ),
					'out'      => __( 'Out of stock', 'product-qrcode-barcode-generator' ),
					'instock'  => __( 'In stock', 'product-qrcode-barcode-generator' ),
					'negative' => __( 'Below zero', 'product-qrcode-barcode-generator' ),
					'nocode'   => __( 'Active items without a QR code', 'product-qrcode-barcode-generator' ),
				) as $value => $label ) {
					echo '<option value="' . esc_attr( $value ) . '"' . selected( $ctx['state'], $value, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select></label> ';
				submit_button( __( 'Filter', 'product-qrcode-barcode-generator' ), 'secondary', '', false );
				echo '</form>';
				break;
		}
	}

	/**
	 * The chart of a report (with its data in the table below it).
	 *
	 * @param array<string, mixed> $ctx  Context.
	 * @param array<string, mixed> $data Dataset.
	 */
	private static function render_chart( array $ctx, array $data ): void {
		$chart = '';

		if ( 'sales' === $ctx['tab'] || ( 'profit' === $ctx['tab'] && 'period' === $ctx['by'] ) ) {
			$key    = 'profit' === $ctx['tab'] ? 'profit' : 'revenue';
			$points = array_map(
				static fn( $row ) => array(
					'label' => self::short_bucket( $row['period'] ),
					'value' => (float) ( $row[ $key ] ?? 0 ),
					'text'  => SalePresenter::money( (string) ( $row[ $key ] ?? '0' ) ),
				),
				$data['rows']
			);
			$chart  = ReportChart::columns(
				$points,
				'profit' === $key ? __( 'Gross profit over time', 'product-qrcode-barcode-generator' ) : __( 'Revenue over time', 'product-qrcode-barcode-generator' ),
				'profit' === $key ? __( 'Gross profit of completed sales with a known cost, per period (losses below the line).', 'product-qrcode-barcode-generator' ) : __( 'Revenue of completed sales per period. The numbers are in the table below.', 'product-qrcode-barcode-generator' ),
				array( __CLASS__, 'axis_money' )
			);
		} elseif ( in_array( $ctx['tab'], array( 'products', 'categories', 'sellers' ), true ) && ! ( 'products' === $ctx['tab'] && 'slow' === $ctx['mode'] ) ) {
			$rows  = 'categories' === $ctx['tab'] ? array_filter( $data['rows'], static fn( $r ) => ! str_starts_with( (string) $r['name'], '— ' ) ) : $data['rows'];
			$top   = ReportsQuery::top( array_values( $rows ), 'revenue', 10 );
			$items = array_map(
				static fn( $r ) => array(
					'label' => (string) $r['name'],
					'value' => (float) $r['revenue'],
					'text'  => SalePresenter::money( (string) $r['revenue'] ),
				),
				$top
			);

			if ( array() !== $items ) {
				$chart = ReportChart::bars( $items, __( 'Top 10 by revenue', 'product-qrcode-barcode-generator' ), __( 'The ten with the most revenue in this period. Every row is in the table below.', 'product-qrcode-barcode-generator' ) );
			}
		}

		if ( '' !== $chart ) {
			echo '<div class="pqbg-panel pqbg-panel--chart">' . $chart . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ReportChart escapes.
		}
	}

	/**
	 * Peak times: heatmap, the busiest hours and days, and the data.
	 *
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_peak( array $ctx ): void {
		$data   = ReportsQuery::peak( ReportPeriod::where_filters( $ctx['period'] ) );
		$metric = $ctx['metric'];
		$days   = ReportData::weekdays();
		$values = array();
		$tips   = array();

		echo '<p class="pqbg-reports__switch">';
		foreach ( array( 'count' => __( 'Number of sales', 'product-qrcode-barcode-generator' ), 'revenue' => __( 'Revenue', 'product-qrcode-barcode-generator' ) ) as $key => $label ) {
			echo $key === $metric ? '<strong aria-current="true">' . esc_html( $label ) . '</strong>' : '<a href="' . esc_url( AdminUrl::reports( self::args( $ctx, array( 'metric' => $key ) ) ) ) . '">' . esc_html( $label ) . '</a>';
			echo 'count' === $key ? ' | ' : '';
		}
		echo '</p>';

		foreach ( $days as $day => $label ) {
			for ( $hour = 0; $hour < 24; $hour++ ) {
				$cell                   = $data['grid'][ $day ][ $hour ];
				$values[ $day ][ $hour ] = 'revenue' === $metric ? (float) $cell['revenue'] : (float) $cell['count'];
				/* translators: 1: weekday, 2: hour range, 3: number of sales, 4: revenue. */
				$tips[ $day ][ $hour ] = sprintf( __( '%1$s %2$s: %3$s sales, %4$s', 'product-qrcode-barcode-generator' ), $label, sprintf( '%02d:00–%02d:00', $hour, ( $hour + 1 ) % 24 ), number_format_i18n( $cell['count'] ), SalePresenter::money( $cell['revenue'] ) );
			}
		}

		echo '<div class="pqbg-panel pqbg-panel--chart">';
		echo ReportChart::heatmap( $values, $tips, $days, 'revenue' === $metric ? __( 'Revenue by hour and weekday', 'product-qrcode-barcode-generator' ) : __( 'Sales by hour and weekday', 'product-qrcode-barcode-generator' ), __( 'Completed sales in this period by local hour of day (columns) and day of week (rows); darker is busier.', 'product-qrcode-barcode-generator' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ReportChart escapes.
		echo '</div>';

		$summary = self::busiest( $data, $days, $metric );
		echo '<div class="pqbg-panel"><h2>' . esc_html__( 'Busiest', 'product-qrcode-barcode-generator' ) . '</h2><p>' . esc_html( $summary ) . '</p></div>';

		$head = array_merge( array( __( 'Hour', 'product-qrcode-barcode-generator' ) ), array_values( $days ) );
		$rows = array();

		for ( $hour = 0; $hour < 24; $hour++ ) {
			$row = array( sprintf( '%02d:00', $hour ) );
			foreach ( array_keys( $days ) as $day ) {
				$cell  = $data['grid'][ $day ][ $hour ];
				$row[] = 'revenue' === $metric ? SalePresenter::money( $cell['revenue'] ) : number_format_i18n( $cell['count'] );
			}
			$rows[] = $row;
		}

		self::data_table( $head, $rows );
	}

	/**
	 * "Busiest: Sat 17:00 (24 sales) · Sun 12:00 (20) · Fri 18:00 (19). Busiest day: Saturday. Busiest hour: 17:00."
	 *
	 * @param array<string, mixed> $data   From ReportsQuery::peak().
	 * @param array<int, string>   $days   Weekday labels.
	 * @param string               $metric count|revenue.
	 */
	public static function busiest( array $data, array $days, string $metric ): string {
		$value = static fn( array $c ): float => 'revenue' === $metric ? (float) $c['revenue'] : (float) $c['count'];
		$show  = static fn( array $c ): string => 'revenue' === $metric ? SalePresenter::money( $c['revenue'] ) : sprintf( /* translators: %s: number of sales. */ _n( '%s sale', '%s sales', $c['count'], 'product-qrcode-barcode-generator' ), number_format_i18n( $c['count'] ) );
		$cells = array();

		foreach ( $data['grid'] as $day => $hours ) {
			foreach ( $hours as $hour => $c ) {
				if ( $value( $c ) > 0 ) {
					$cells[] = array( $day, $hour, $c );
				}
			}
		}

		if ( array() === $cells ) {
			return __( 'No completed sales in this period.', 'product-qrcode-barcode-generator' );
		}

		usort( $cells, static fn( $a, $b ) => ( $value( $b[2] ) <=> $value( $a[2] ) ) ?: ( ( $a[0] * 24 + $a[1] ) <=> ( $b[0] * 24 + $b[1] ) ) );

		$top = array_map( static fn( $c ) => sprintf( '%s %02d:00–%02d:00 (%s)', $days[ $c[0] ], $c[1], ( $c[1] + 1 ) % 24, $show( $c[2] ) ), array_slice( $cells, 0, 3 ) );
		$bd  = null; // Ties: the first in week order.
		$bh  = null;

		foreach ( array_keys( $days ) as $d ) {
			if ( null === $bd || $value( $data['days'][ $d ] ) > $value( $data['days'][ $bd ] ) ) {
				$bd = $d;
			}
		}

		for ( $h = 0; $h < 24; $h++ ) {
			if ( null === $bh || $value( $data['hours'][ $h ] ) > $value( $data['hours'][ $bh ] ) ) {
				$bh = $h;
			}
		}

		return sprintf(
			/* translators: 1: list of the busiest hours, 2: weekday, 3: hour. */
			__( 'Busiest hours: %1$s. Busiest day: %2$s. Busiest hour of the day: %3$s.', 'product-qrcode-barcode-generator' ),
			implode( ' · ', $top ),
			$days[ $bd ],
			sprintf( '%02d:00–%02d:00', $bh, ( $bh + 1 ) % 24 )
		);
	}

	/**
	 * End of day / payments.
	 *
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_eod( array $ctx ): void {
		$d = ReportsQuery::end_of_day( ReportPeriod::where_filters( $ctx['period'] ) );

		echo '<div class="pqbg-panel">';
		self::render_eod_body( $d, ReportPeriod::span_label( $ctx['period']['from'], $ctx['period']['to'] ) );
		echo '</div>';
	}

	/**
	 * The end-of-day content (shared with the print page).
	 *
	 * @param array<string, mixed> $d     From ReportsQuery::end_of_day().
	 * @param string               $label Period label.
	 */
	public static function render_eod_body( array $d, string $label ): void {
		$methods = array_keys( $d['methods'] );
		$shown   = array_values( array_filter( $methods, static fn( $m ) => '' !== $m || 0 !== $d['methods']['']['sales'] || 0.0 !== (float) $d['methods']['']['refunds'] ) );
		$name    = static fn( $m ) => PaymentMethods::label( '' === $m ? null : (string) $m );
		$net     = static fn( $m ) => PaymentMethods::CASH === $m ? __( 'Cash expected in drawer', 'product-qrcode-barcode-generator' ) : sprintf( /* translators: %s: payment method. */ __( '%s net collected', 'product-qrcode-barcode-generator' ), $name( $m ) );

		/* translators: %s: date or date range. */
		echo '<h2>' . esc_html( sprintf( __( 'End of day — %s', 'product-qrcode-barcode-generator' ), $label ) ) . '</h2>';
		echo '<div class="pqbg-cards">';

		foreach ( $shown as $m ) {
			echo '<div class="pqbg-card' . ( PaymentMethods::CASH === $m ? ' pqbg-card--main' : '' ) . '"><div class="pqbg-card__label">' . esc_html( $net( $m ) ) . '</div><div class="pqbg-card__value">' . esc_html( SalePresenter::money( $d['methods'][ $m ]['net'] ) ) . '</div>';

			if ( 0.0 !== (float) $d['methods'][ $m ]['refunds'] ) {
				/* translators: 1: revenue, 2: refunds. */
				echo '<div class="pqbg-card__change">' . esc_html( sprintf( __( '%1$s sales − %2$s refunds of earlier sales', 'product-qrcode-barcode-generator' ), SalePresenter::money( $d['methods'][ $m ]['revenue'] ), SalePresenter::money( $d['methods'][ $m ]['refunds'] ) ) ) . '</div>';
			}

			echo '</div>';
		}

		echo '</div><p class="description">' . esc_html( ReportData::refund_assumption() ) . '</p>';

		// Per payment method.
		echo '<h3>' . esc_html__( 'By payment method', 'product-qrcode-barcode-generator' ) . '</h3>';
		echo '<table class="widefat striped pqbg-eod"><thead><tr><th scope="col">' . esc_html__( 'Paid by', 'product-qrcode-barcode-generator' ) . '</th><th class="pqbg-num" scope="col">' . esc_html__( 'Sales', 'product-qrcode-barcode-generator' ) . '</th><th class="pqbg-num" scope="col">' . esc_html__( 'Items', 'product-qrcode-barcode-generator' ) . '</th><th class="pqbg-num" scope="col">' . esc_html__( 'Revenue', 'product-qrcode-barcode-generator' ) . '</th><th class="pqbg-num" scope="col">' . esc_html__( 'Refunds of earlier sales', 'product-qrcode-barcode-generator' ) . '</th><th class="pqbg-num" scope="col">' . esc_html__( 'Net collected', 'product-qrcode-barcode-generator' ) . '</th></tr></thead><tbody>';

		foreach ( $shown as $m ) {
			$x = $d['methods'][ $m ];
			echo '<tr><th scope="row">' . esc_html( $name( $m ) ) . '</th><td class="pqbg-num">' . esc_html( number_format_i18n( $x['sales'] ) ) . '</td><td class="pqbg-num">' . esc_html( number_format_i18n( $x['items'] ) ) . '</td><td class="pqbg-num">' . esc_html( SalePresenter::money( $x['revenue'] ) ) . '</td><td class="pqbg-num">' . esc_html( SalePresenter::money( $x['refunds'] ) ) . '</td><td class="pqbg-num"><strong>' . esc_html( SalePresenter::money( $x['net'] ) ) . '</strong></td></tr>';
		}

		$t = $d['total'];
		echo '</tbody><tfoot><tr><th scope="row">' . esc_html__( 'Total', 'product-qrcode-barcode-generator' ) . '</th><td class="pqbg-num">' . esc_html( number_format_i18n( $t['sales'] ) ) . '</td><td class="pqbg-num">' . esc_html( number_format_i18n( $t['items'] ) ) . '</td><td class="pqbg-num">' . esc_html( SalePresenter::money( $t['revenue'] ) ) . '</td><td class="pqbg-num">' . esc_html( SalePresenter::money( $t['refunds'] ) ) . '</td><td class="pqbg-num"><strong>' . esc_html( SalePresenter::money( $t['net'] ) ) . '</strong></td></tr></tfoot></table>';

		// Per seller (who sold) per method.
		echo '<h3>' . esc_html__( 'By seller (who sold) — net collected', 'product-qrcode-barcode-generator' ) . '</h3>';

		if ( array() === $d['sellers'] ) {
			echo '<p>' . esc_html__( 'No sales in this period.', 'product-qrcode-barcode-generator' ) . '</p>';
		} else {
			echo '<table class="widefat striped pqbg-eod"><thead><tr><th scope="col">' . esc_html__( 'Seller', 'product-qrcode-barcode-generator' ) . '</th>';
			foreach ( $shown as $m ) {
				echo '<th class="pqbg-num" scope="col">' . esc_html( $name( $m ) ) . '</th>';
			}
			echo '<th class="pqbg-num" scope="col">' . esc_html__( 'Total', 'product-qrcode-barcode-generator' ) . '</th></tr></thead><tbody>';

			foreach ( $d['sellers'] as $s ) {
				echo '<tr><th scope="row">' . esc_html( $s['name'] ) . '</th>';
				foreach ( $shown as $m ) {
					$cell = SalePresenter::money( $s['net'][ $m ] );
					if ( 0.0 !== (float) $s['refunds'][ $m ] ) {
						/* translators: 1: sales, 2: refunds. */
						$cell .= ' ' . sprintf( __( '(%1$s − %2$s)', 'product-qrcode-barcode-generator' ), SalePresenter::money( $s['revenue'][ $m ] ), SalePresenter::money( $s['refunds'][ $m ] ) );
					}
					echo '<td class="pqbg-num">' . esc_html( $cell ) . '</td>';
				}
				echo '<td class="pqbg-num"><strong>' . esc_html( SalePresenter::money( (string) array_sum( array_map( 'floatval', $s['net'] ) ) ) ) . '</strong></td></tr>';
			}

			echo '</tbody></table>';
			echo '<p class="description">' . esc_html__( 'Each sale belongs to the seller who sold it; a refund of an earlier sale is taken from that seller\'s figure. Who paid the refund out is listed below.', 'product-qrcode-barcode-generator' ) . '</p>';
		}

		// Refunds by the user who voided.
		if ( array() !== $d['refunds_by'] ) {
			echo '<h3>' . esc_html__( 'Refunds of earlier sales', 'product-qrcode-barcode-generator' ) . '</h3><ul class="pqbg-refunds">';
			foreach ( $d['refunds_by'] as $r ) {
				$parts = array();
				foreach ( $r['methods'] as $m => $amount ) {
					if ( 0.0 !== (float) $amount ) {
						$parts[] = $name( (string) $m ) . ' ' . SalePresenter::money( $amount );
					}
				}
				/* translators: 1: user, 2: amounts per method, 3: number of sales. */
				echo '<li>' . esc_html( sprintf( _n( 'Voided in this period by %1$s: %2$s (%3$s sale)', 'Voided in this period by %1$s: %2$s (%3$s sales)', $r['count'], 'product-qrcode-barcode-generator' ), $r['name'], implode( ', ', $parts ), number_format_i18n( $r['count'] ) ) ) . '</li>';
			}
			echo '</ul>';
		}

		foreach ( array( 'voided_own' => __( 'Voided sales made in this period (not in revenue)', 'product-qrcode-barcode-generator' ), 'voided_earlier' => __( 'Voided in this period, sold earlier (the refunds above)', 'product-qrcode-barcode-generator' ) ) as $key => $title ) {
			echo '<h3>' . esc_html( $title ) . '</h3>';

			if ( array() === $d[ $key ] ) {
				echo '<p>' . esc_html__( 'None.', 'product-qrcode-barcode-generator' ) . '</p>';
				continue;
			}

			echo '<table class="widefat striped pqbg-eod"><thead><tr>';
			foreach ( array( __( 'Sale #', 'product-qrcode-barcode-generator' ), __( 'Sold', 'product-qrcode-barcode-generator' ), __( 'Item', 'product-qrcode-barcode-generator' ), __( 'Total', 'product-qrcode-barcode-generator' ), __( 'Paid by', 'product-qrcode-barcode-generator' ), __( 'Sold by', 'product-qrcode-barcode-generator' ), __( 'Voided', 'product-qrcode-barcode-generator' ), __( 'Voided by', 'product-qrcode-barcode-generator' ), __( 'Reason', 'product-qrcode-barcode-generator' ), __( 'Returned to stock', 'product-qrcode-barcode-generator' ) ) as $h ) {
				echo '<th scope="col">' . esc_html( $h ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $d[ $key ] as $sale ) {
				$cells = array( '#' . $sale['id'], SalePresenter::datetime( $sale['created_at_gmt'] ), SalePresenter::item( $sale ), SalePresenter::money( $sale['line_total'], (string) $sale['currency'] ), PaymentMethods::label( $sale['payment_method'] ), SalePresenter::seller( $sale ), SalePresenter::datetime( $sale['voided_at_gmt'] ), SalePresenter::user_label( (int) $sale['voided_by'] ), ReportData::reason( $sale ), ReportData::restocked( $sale ) );
				echo '<tr>';
				foreach ( $cells as $cell ) {
					echo '<td>' . esc_html( $cell ) . '</td>';
				}
				echo '</tr>';
			}

			echo '</tbody></table>';
		}
	}

	/**
	 * Void counts per seller and failure counts per code.
	 *
	 * @param array<string, mixed> $v From ReportsQuery::voids().
	 */
	private static function render_void_summary( array $v ): void {
		if ( array() === $v['sellers'] ) {
			return;
		}

		$rows = array_map( static fn( $s ) => array( $s['name'], number_format_i18n( $s['voided'] ), number_format_i18n( $s['undone'] ), number_format_i18n( $s['failed'] ) ), $v['sellers'] );

		echo '<div class="pqbg-reports__grid"><section class="pqbg-panel"><h2>' . esc_html__( 'Per seller', 'product-qrcode-barcode-generator' ) . '</h2>';
		self::plain_table( array( __( 'Seller', 'product-qrcode-barcode-generator' ), __( 'Voided', 'product-qrcode-barcode-generator' ), __( 'of which undone by the seller', 'product-qrcode-barcode-generator' ), __( 'Failed', 'product-qrcode-barcode-generator' ) ), $rows );
		echo '</section>';

		if ( array() !== $v['failures'] ) {
			echo '<section class="pqbg-panel"><h2>' . esc_html__( 'Failed attempts by reason', 'product-qrcode-barcode-generator' ) . '</h2>';
			self::plain_table( array( __( 'Failure', 'product-qrcode-barcode-generator' ), __( 'Count', 'product-qrcode-barcode-generator' ) ), array_map( static fn( $code, $n ) => array( $code, number_format_i18n( $n ) ), array_keys( $v['failures'] ), $v['failures'] ) );
			echo '<p class="description">' . esc_html__( 'sold_online: the item sold online at the same moment; error or interrupted: the sale could not be completed. Failed attempts changed no stock.', 'product-qrcode-barcode-generator' ) . '</p></section>';
		}

		echo '</div>';
	}

	/**
	 * Stock totals as cards.
	 *
	 * @param array<string, mixed> $t     From StockQuery::totals().
	 * @param bool                 $costs Costs.
	 */
	private static function render_stock_totals( array $t, bool $costs ): void {
		$cards = array(
			array( __( 'Items', 'product-qrcode-barcode-generator' ), number_format_i18n( $t['items'] ) ),
			array( __( 'Units in stock', 'product-qrcode-barcode-generator' ), number_format_i18n( $t['units'] ) ),
			array( __( 'Value at price', 'product-qrcode-barcode-generator' ), SalePresenter::money( $t['value'] ) ),
		);

		if ( $costs ) {
			$cards[] = array( __( 'Value at cost', 'product-qrcode-barcode-generator' ), SalePresenter::money( $t['cost_value'] ) );
		}

		$cards[] = array( __( 'Low / out of stock', 'product-qrcode-barcode-generator' ), number_format_i18n( $t['low'] ) . ' / ' . number_format_i18n( $t['out'] ) );

		echo '<div class="pqbg-cards">';
		foreach ( $cards as $c ) {
			echo '<div class="pqbg-card"><div class="pqbg-card__label">' . esc_html( $c[0] ) . '</div><div class="pqbg-card__value">' . esc_html( $c[1] ) . '</div></div>';
		}
		echo '</div>';
	}

	/**
	 * A dataset as a sortable, paged table with its totals and notes.
	 *
	 * @param array<string, mixed> $data Dataset.
	 */
	private static function table( array $data ): void {
		$spec = array();

		foreach ( $data['columns'] as $key => $c ) {
			$spec[ $key ] = array( $c['label'], $c['sortable'] );
		}

		$numeric = array_keys( array_filter( $data['columns'], static fn( $c ) => in_array( $c['type'], array( 'int', 'money', 'pct' ), true ) ) );
		$first   = (string) array_key_first( $data['columns'] );
		$rows    = array();

		foreach ( $data['rows'] as $row ) {
			$cells = array();
			$sort  = array( '_order' => $row['_order'] ?? null );

			foreach ( $data['columns'] as $key => $c ) {
				$cells[ $key ] = self::cell( $c['type'], $row[ $key ] ?? null, $row['_notes'][ $key ] ?? '' );
				$sort[ $key ]  = $row[ $key ] ?? null;

				if ( $key === $first && ! empty( $row['_link'] ) ) {
					$cells[ $key ] = '<a href="' . esc_url( $row['_link'] ) . '">' . $cells[ $key ] . '</a>';
				}
			}

			$rows[] = array(
				'cells' => $cells,
				'sort'  => $sort,
			);
		}

		$table = new ReportTable( $spec, $rows, (string) $data['default_sort'], (string) $data['default_order'], 100, '', $numeric );
		$table->prepare_items();

		echo '<form method="get" action="' . esc_url( AdminUrl::admin_php() ) . '">';
		$table->display();
		echo '</form>';

		if ( null !== $data['total'] ) {
			echo '<table class="widefat pqbg-report-totals"><tbody><tr>';
			foreach ( $data['columns'] as $key => $c ) {
				$value = array_key_exists( $key, $data['total'] ) ? self::cell( $c['type'], $data['total'][ $key ], '' ) : '';
				echo '<td class="' . esc_attr( in_array( $key, $numeric, true ) ? 'pqbg-num' : '' ) . '" data-colname="' . esc_attr( $c['label'] ) . '">' . ( '' === $value ? '' : '<span class="pqbg-report-totals__label">' . esc_html( $c['label'] ) . '</span> <strong>' . $value . '</strong>' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cell() escapes.
			}
			echo '</tr></tbody></table>';
		}

		foreach ( $data['notes'] as $note ) {
			echo '<p class="description">' . esc_html( $note ) . '</p>';
		}
	}

	/**
	 * One cell as escaped HTML.
	 *
	 * @param string $type  Column type.
	 * @param mixed  $value Raw value.
	 * @param string $note  unknown|mixed|none for a missing money value.
	 */
	public static function cell( string $type, $value, string $note ): string {
		if ( null === $value ) {
			$labels = array(
				'mixed'   => __( 'mixed prices', 'product-qrcode-barcode-generator' ),
				'unknown' => __( 'unknown', 'product-qrcode-barcode-generator' ),
				'none'    => __( 'no variations', 'product-qrcode-barcode-generator' ),
			);

			return 'money' === $type && isset( $labels[ $note ] ) ? '<em>' . esc_html( $labels[ $note ] ) . '</em>' : '—';
		}

		switch ( $type ) {
			case 'int':
				return esc_html( number_format_i18n( (int) $value ) );
			case 'money':
				return esc_html( SalePresenter::money( (string) $value ) );
			case 'pct':
				return esc_html( number_format_i18n( (float) $value, 1 ) . '%' );
			case 'datetime':
				return esc_html( SalePresenter::datetime( (string) $value ) );
			case 'date':
				return esc_html( SalePresenter::datetime( (string) $value, (string) get_option( 'date_format' ) ) );
			default:
				return esc_html( (string) $value );
		}
	}

	/**
	 * A small data table in a <details> element (the accessible alternative of a chart).
	 *
	 * @param string[]                 $head   Header cells.
	 * @param array<int, array<int, string>> $rows Rows (plain text).
	 * @param bool                     $open   Open by default.
	 */
	private static function data_table( array $head, array $rows, bool $open = false ): void {
		echo '<details class="pqbg-data"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html__( 'Show the numbers', 'product-qrcode-barcode-generator' ) . '</summary>';
		self::plain_table( $head, $rows );
		echo '</details>';
	}

	/**
	 * A plain table (every cell escaped here).
	 *
	 * @param string[]                       $head Header cells.
	 * @param array<int, array<int, string>> $rows Rows.
	 */
	private static function plain_table( array $head, array $rows ): void {
		echo '<table class="widefat striped pqbg-data-table"><thead><tr>';
		foreach ( $head as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( array_values( $row ) as $i => $cell ) {
				echo 0 === $i ? '<th scope="row">' . esc_html( $cell ) . '</th>' : '<td>' . esc_html( $cell ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * "↑ 12.4 % vs previous period", "↓ 3.5 %", "— no comparison" (margin: points).
	 *
	 * @param float|null $change Percent (or points).
	 * @param bool       $points Whether the change is in percentage points.
	 */
	public static function change_text( ?float $change, bool $points ): string {
		if ( null === $change ) {
			return __( '— nothing to compare with', 'product-qrcode-barcode-generator' );
		}

		$arrow = $change > 0 ? '↑' : ( $change < 0 ? '↓' : '→' );
		$num   = number_format_i18n( abs( $change ), 1 );

		/* translators: 1: arrow, 2: number. */
		return $points ? sprintf( __( '%1$s %2$s pt', 'product-qrcode-barcode-generator' ), $arrow, $num ) : sprintf( __( '%1$s %2$s%%', 'product-qrcode-barcode-generator' ), $arrow, $num );
	}

	/**
	 * "Profit and margin leave out 12 sales (₹14,300.00) with an unknown cost."
	 *
	 * @param array<string, mixed> $m Metrics.
	 */
	public static function unknown_text( array $m ): string {
		/* translators: 1: number of sales, 2: their revenue. */
		return sprintf( _n( 'Profit and margin leave out %1$s sale (%2$s) with an unknown cost.', 'Profit and margin leave out %1$s sales (%2$s) with an unknown cost.', $m['unknown'], 'product-qrcode-barcode-generator' ), number_format_i18n( $m['unknown'] ), SalePresenter::money( $m['unknown_revenue'] ) );
	}

	/**
	 * An axis label for an amount ("₹1,500").
	 *
	 * @param float $value Amount.
	 */
	public static function axis_money( float $value ): string {
		$symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );

		return ( $value < 0 ? '−' : '' ) . $symbol . number_format_i18n( abs( $value ) );
	}

	/**
	 * A bucket label shortened for an axis ("1 Sep" from "1 Sep – 7 Sep 2026").
	 *
	 * @param string $label Label.
	 */
	private static function short_bucket( string $label ): string {
		$parts = explode( ' – ', $label );

		return $parts[0];
	}
}
