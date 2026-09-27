<?php
/**
 * QR & Barcodes → Dashboard (Phase 10B): today at a glance and shortcuts.
 *
 *   admin.php?page=pqbg-dashboard   GET, pqbg_view_all_sales; read-only except the timing sample (Phase 11)
 *
 * An overview, not an analysis screen (that is In-store reports): what needs attention,
 * today's in-store sales, products and codes, recent bulk runs, the scan setup and quick
 * links. Every figure comes from existing code, never a second calculation:
 *   - today's sales, payment methods, voids, low/out of stock and "Published products
 *     without a code": ReportsAdmin::dashboard_data() for ReportPeriod "today", the same
 *     function and period as In-store reports → Summary → Today;
 *   - recent bulk runs: BulkLog::visible() (cost-import entries only for pqbg_view_costs);
 *   - the run in progress: BulkGenerator::state();
 *   - setup: ScanUrl, Settings, ScanRoute, PaymentMethods;
 *   - Phase 11, administrators (pqbg_manage_settings) only: the health check's error and
 *     warning count (HealthCheck) and the performance signal (PerfSignal). Every render
 *     records how long its figures took (PerfSignal::record(), the only write on this page).
 * Profit, margin and anything about cost only for pqbg_view_costs; products, codes, stock
 * and bulk runs only for pqbg_manage_codes; the Settings links only for pqbg_manage_settings.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin Dashboard.
 */
final class DashboardAdmin {

	/** Bulk runs listed. */
	const RUNS = 5;

	/**
	 * Hooks used on admin requests.
	 */
	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * load-{page}: access before any output.
	 */
	public static function load(): void {
		if ( ! Permissions::can_view_all_sales() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * The card styles of the reports and the Dashboard's own layout, on this page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue( $hook_suffix ): void {
		if ( AdminMenu::is_page( AdminUrl::DASHBOARD, (string) $hook_suffix ) ) {
			wp_enqueue_style( 'pqbg-reports', PQBG_PLUGIN_URL . 'assets/pqbg-reports.css', array(), PQBG_VERSION );
			wp_enqueue_style( 'pqbg-dashboard', PQBG_PLUGIN_URL . 'assets/pqbg-dashboard.css', array( 'pqbg-reports' ), PQBG_VERSION );
		}
	}

	/**
	 * Today's figures: exactly In-store reports → Summary for "today".
	 *
	 * @param bool     $costs Include profit and margin.
	 * @param int|null $now   Unix time "now" (tests).
	 * @return array<string, mixed> period, now (metrics), methods, voided, low, out, nocode
	 */
	public static function data( bool $costs, ?int $now = null ): array {
		$period = ReportPeriod::resolve( 'today', '', '', $now );
		$d      = ReportsAdmin::dashboard_data( $period, $costs );

		return array(
			'period'  => $period,
			'now'     => $d['now'],
			'methods' => $d['methods'],
			'voided'  => (int) $d['voided'],
			'low'     => (int) $d['low'],
			'out'     => (int) $d['out'],
			'nocode'  => (int) $d['nocode'],
		);
	}

	/**
	 * What needs attention, for this user: [message, link URL or '', link text].
	 *
	 * @param bool $codes    The user may manage codes (prints labels).
	 * @param bool $settings The user may change settings.
	 * @return array<int, array{0: string, 1: string, 2: string}>
	 */
	public static function attention( bool $codes, bool $settings ): array {
		$items   = array();
		$base    = ScanUrl::base();
		$to_set  = $settings ? AdminUrl::settings() : '';
		$setting = __( 'Scan base URL settings', 'product-qrcode-barcode-generator' );

		if ( $codes && Settings::is_local_url( $base ) ) {
			/* translators: %s: scan base URL. */
			$items[] = array( sprintf( __( 'QR codes currently point to a local address (%s). Do not print labels until the production URL is set.', 'product-qrcode-barcode-generator' ), $base ), $to_set, $setting );
		} elseif ( $codes && Settings::is_http_url( $base ) ) {
			$items[] = array( __( 'Labels should use an https:// scan URL in production.', 'product-qrcode-barcode-generator' ), $to_set, $setting );
		}

		if ( ! ScanRoute::is_available() ) {
			$items[] = array( __( 'Scan links do not work with the current permalink setting. Choose another structure under Settings → Permalinks.', 'product-qrcode-barcode-generator' ), '', '' );
		}

		$conflicts = count( ScanRoute::conflicts() );

		if ( $conflicts > 0 ) {
			/* translators: 1: number of items, 2: scan page address. */
			$items[] = array( sprintf( _n( '%1$s item uses an address at or below %2$s, which the scan page takes over.', '%1$s items use an address at or below %2$s, which the scan page takes over.', $conflicts, 'product-qrcode-barcode-generator' ), number_format_i18n( $conflicts ), ScanUrl::site_url() ), '', '' );
		}

		$state = $codes ? BulkGenerator::state() : null;

		if ( null !== $state && BulkGenerator::RUNNING === $state['status'] ) {
			$items[] = array( BulkGenerator::is_active( $state ) ? __( 'Code generation is running.', 'product-qrcode-barcode-generator' ) : __( 'Code generation was interrupted (no batch for several minutes).', 'product-qrcode-barcode-generator' ), AdminUrl::bulk_tools( ToolsAdmin::TAB_TOOLS ), __( 'Continue in Bulk tools', 'product-qrcode-barcode-generator' ) );
		} elseif ( null !== $state && BulkGenerator::STOPPED === $state['status'] ) {
			$items[] = array( __( 'Code generation was stopped before it finished.', 'product-qrcode-barcode-generator' ), AdminUrl::bulk_tools( ToolsAdmin::TAB_TOOLS ), __( 'Continue in Bulk tools', 'product-qrcode-barcode-generator' ) );
		}

		return $items;
	}

	/**
	 * What needs attention for administrators only (Phase 11): the health check's errors and
	 * warnings (HealthCheck::run() without the information checks) and the performance signal.
	 *
	 * @param int[]    $samples Dashboard compute times, newest last (PerfSignal).
	 * @param int|null $now     Unix time (tests).
	 * @return array<int, array{0: string, 1: string, 2: string}>
	 */
	public static function admin_attention( array $samples, ?int $now = null ): array {
		$items    = array();
		$problems = HealthCheck::problems( HealthCheck::run( false, $now ) );

		if ( $problems > 0 ) {
			/* translators: %s: number of problems. */
			$items[] = array( sprintf( _n( 'The health check found %s problem in the plugin\'s data.', 'The health check found %s problems in the plugin\'s data.', $problems, 'product-qrcode-barcode-generator' ), number_format_i18n( $problems ) ), AdminUrl::health(), __( 'Open Health check', 'product-qrcode-barcode-generator' ) );
		}

		$message = PerfSignal::message( PerfSignal::evaluate( $samples, PerfSignal::sales_per_day( $now ) ) );

		if ( '' !== $message ) {
			$items[] = array( $message, '', '' );
		}

		return $items;
	}

	/**
	 * Renders the page.
	 */
	public static function render(): void {
		if ( ! Permissions::can_view_all_sales() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'product-qrcode-barcode-generator' ), 403 );
		}

		$costs    = Permissions::can_view_costs();
		$codes    = Permissions::can_manage_codes();
		$settings = Permissions::can_manage_settings();
		$start    = microtime( true );
		$data     = self::data( $costs );
		$samples  = PerfSignal::record( ( microtime( true ) - $start ) * 1000 );

		echo '<div class="wrap pqbg-reports pqbg-dashboard">';
		AdminMenu::render_nav( AdminUrl::DASHBOARD );
		echo '<h1>' . esc_html__( 'Dashboard', 'product-qrcode-barcode-generator' ) . '</h1>';
		/* translators: %s: link to In-store reports. */
		echo '<p class="pqbg-dashboard__intro">' . wp_kses( sprintf( __( 'Today at a glance. For trends and analysis, open %s.', 'product-qrcode-barcode-generator' ), '<a href="' . esc_url( AdminUrl::reports() ) . '">' . esc_html__( 'In-store reports', 'product-qrcode-barcode-generator' ) . '</a>' ), array( 'a' => array( 'href' => true ) ) ) . '</p>';

		self::render_attention( array_merge( self::attention( $codes, $settings ), $settings ? self::admin_attention( $samples ) : array() ) );
		self::render_today( $data, $costs );

		echo '<div class="pqbg-dashboard__grid">';

		if ( $codes ) {
			self::render_products( $data );
			self::render_runs();
		}

		self::render_setup( $settings );
		self::render_links( $codes, $costs, $settings );
		echo '</div></div>';
	}

	/**
	 * Needs attention (only when something does).
	 *
	 * @param array<int, array{0: string, 1: string, 2: string}> $items Items.
	 */
	private static function render_attention( array $items ): void {
		if ( array() === $items ) {
			return;
		}

		echo '<section class="pqbg-panel pqbg-dashboard__attention" aria-labelledby="pqbg-dash-attention"><h2 id="pqbg-dash-attention">' . esc_html__( 'Needs attention', 'product-qrcode-barcode-generator' ) . '</h2><ul>';

		foreach ( $items as $item ) {
			echo '<li>' . esc_html( $item[0] ) . ( '' !== $item[1] ? ' <a href="' . esc_url( $item[1] ) . '">' . esc_html( $item[2] ) . '</a>' : '' ) . '</li>';
		}

		echo '</ul></section>';
	}

	/**
	 * Today in the shop.
	 *
	 * @param array<string, mixed> $data  From data().
	 * @param bool                 $costs Profit and margin.
	 */
	private static function render_today( array $data, bool $costs ): void {
		$now   = $data['now'];
		$cards = array(
			array( __( 'Revenue', 'product-qrcode-barcode-generator' ), SalePresenter::money( $now['revenue'] ) ),
			array( __( 'Sales', 'product-qrcode-barcode-generator' ), number_format_i18n( $now['count'] ) ),
			array( __( 'Items sold', 'product-qrcode-barcode-generator' ), number_format_i18n( $now['items'] ) ),
		);

		if ( $costs ) {
			$cards[] = array( __( 'Gross profit', 'product-qrcode-barcode-generator' ), SalePresenter::money( $now['profit'] ) );
			$cards[] = array( __( 'Margin', 'product-qrcode-barcode-generator' ), null === $now['margin'] ? '—' : number_format_i18n( $now['margin'], 1 ) . '%' );
		}

		echo '<section class="pqbg-dashboard__today" aria-labelledby="pqbg-dash-today"><h2 id="pqbg-dash-today">' . esc_html__( 'Today in the shop', 'product-qrcode-barcode-generator' ) . '</h2>';
		/* translators: 1: date, 2: timezone. */
		echo '<p class="description">' . esc_html( sprintf( __( '%1$s · in-store sales, completed only · %2$s', 'product-qrcode-barcode-generator' ), ReportPeriod::span_label( $data['period']['from'], $data['period']['to'] ), wp_timezone_string() ) ) . '</p>';
		echo '<dl class="pqbg-cards">';

		foreach ( $cards as $card ) {
			echo '<div class="pqbg-card"><dt class="pqbg-card__label">' . esc_html( $card[0] ) . '</dt><dd class="pqbg-card__value">' . esc_html( $card[1] ) . '</dd></div>';
		}

		echo '</dl>';

		if ( $costs && $now['unknown'] > 0 ) {
			echo '<p class="description">' . esc_html( ReportsAdmin::unknown_text( $now ) ) . '</p>';
		}

		$rows = array();

		foreach ( $data['methods'] as $key => $m ) {
			if ( $m['count'] > 0 ) {
				$rows[] = '<tr><th scope="row">' . esc_html( PaymentMethods::label( '' === $key ? null : (string) $key ) ) . '</th><td class="pqbg-num">' . esc_html( SalePresenter::money( $m['revenue'] ) ) . '</td><td class="pqbg-num">' . esc_html( number_format_i18n( $m['count'] ) ) . '</td></tr>';
			}
		}

		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No completed in-store sales yet today.', 'product-qrcode-barcode-generator' ) . '</p>';
		} else {
			echo '<table class="widefat striped pqbg-dashboard__methods"><caption class="screen-reader-text">' . esc_html__( 'Today by payment method', 'product-qrcode-barcode-generator' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Paid by', 'product-qrcode-barcode-generator' ) . '</th><th scope="col" class="pqbg-num">' . esc_html__( 'Revenue', 'product-qrcode-barcode-generator' ) . '</th><th scope="col" class="pqbg-num">' . esc_html__( 'Sales', 'product-qrcode-barcode-generator' ) . '</th></tr></thead><tbody>' . implode( '', $rows ) . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows escaped above.
		}

		/* translators: %s: number of sales. */
		echo '<p>' . esc_html( sprintf( _n( 'Voided today: %s sale.', 'Voided today: %s sales.', $data['voided'], 'product-qrcode-barcode-generator' ), number_format_i18n( $data['voided'] ) ) ) . ( $data['voided'] > 0 ? ' <a href="' . esc_url( AdminUrl::reports( array( 'tab' => 'voids', 'range' => 'today' ) ) ) . '">' . esc_html__( 'See voids', 'product-qrcode-barcode-generator' ) . '</a>' : '' ) . '</p>';
		echo '<p class="pqbg-dashboard__more"><a href="' . esc_url( AdminUrl::reports( array( 'tab' => 'eod', 'range' => 'today' ) ) ) . '">' . esc_html__( 'End of day', 'product-qrcode-barcode-generator' ) . '</a> · <a href="' . esc_url( AdminUrl::sales( array( 'range' => 'today' ) ) ) . '">' . esc_html__( 'Today\'s sales', 'product-qrcode-barcode-generator' ) . '</a> · <a href="' . esc_url( AdminUrl::reports() ) . '">' . esc_html__( 'In-store reports', 'product-qrcode-barcode-generator' ) . '</a></p>';
		echo '</section>';
	}

	/**
	 * Products and codes.
	 *
	 * @param array<string, mixed> $data From data().
	 */
	private static function render_products( array $data ): void {
		$rows = array(
			array( __( 'Published products without a code', 'product-qrcode-barcode-generator' ), $data['nocode'], AdminUrl::bulk_tools( ToolsAdmin::TAB_TOOLS ), __( 'Generate missing codes', 'product-qrcode-barcode-generator' ) ),
			array( __( 'Low on stock', 'product-qrcode-barcode-generator' ), $data['low'], AdminUrl::reports( array( 'tab' => 'stock', 'state' => 'low' ) ), __( 'See the items', 'product-qrcode-barcode-generator' ) ),
			array( __( 'Out of stock', 'product-qrcode-barcode-generator' ), $data['out'], AdminUrl::reports( array( 'tab' => 'stock', 'state' => 'out' ) ), __( 'See the items', 'product-qrcode-barcode-generator' ) ),
		);

		echo '<section class="pqbg-panel" aria-labelledby="pqbg-dash-products"><h2 id="pqbg-dash-products">' . esc_html__( 'Products and codes', 'product-qrcode-barcode-generator' ) . '</h2><dl class="pqbg-dashboard__list">';

		foreach ( $rows as $row ) {
			echo '<div><dt>' . esc_html( $row[0] ) . '</dt><dd><strong>' . esc_html( number_format_i18n( $row[1] ) ) . '</strong>' . ( $row[1] > 0 ? ' <a href="' . esc_url( $row[2] ) . '">' . esc_html( $row[3] ) . '</a>' : '' ) . '</dd></div>';
		}

		echo '</dl><p class="description">' . esc_html__( 'Published products and variations only. Bulk tools → Code tools also counts drafts, private, pending and scheduled items, so its number can be higher. Stock uses the WooCommerce low and out-of-stock thresholds (published and private items).', 'product-qrcode-barcode-generator' ) . '</p></section>';
	}

	/**
	 * Recent bulk runs.
	 */
	private static function render_runs(): void {
		$entries = BulkLog::visible( self::RUNS, get_current_user_id() );

		echo '<section class="pqbg-panel" aria-labelledby="pqbg-dash-runs"><h2 id="pqbg-dash-runs">' . esc_html__( 'Recent bulk runs', 'product-qrcode-barcode-generator' ) . '</h2>';

		if ( array() === $entries ) {
			echo '<p>' . esc_html__( 'None yet.', 'product-qrcode-barcode-generator' ) . '</p>';
		} else {
			echo '<ul class="pqbg-dashboard__runs">';

			foreach ( $entries as $entry ) {
				$when = strtotime( (string) $entry['time'] . ' UTC' );
				/* translators: 1: date and time, 2: tool, 3: user. */
				echo '<li>' . esc_html( sprintf( __( '%1$s — %2$s (%3$s)', 'product-qrcode-barcode-generator' ), false === $when ? (string) $entry['time'] : wp_date( 'Y-m-d H:i', $when ), BulkLog::label( (string) $entry['tool'] ), '' !== (string) $entry['user_name'] ? (string) $entry['user_name'] : '#' . (int) $entry['user_id'] ) ) . '</li>';
			}

			echo '</ul>';
		}

		echo '<p><a href="' . esc_url( AdminUrl::bulk_tools( ToolsAdmin::TAB_TOOLS ) ) . '">' . esc_html__( 'Bulk tools', 'product-qrcode-barcode-generator' ) . '</a></p></section>';
	}

	/**
	 * The scan setup (read-only).
	 *
	 * @param bool $settings Show the link to Settings.
	 */
	private static function render_setup( bool $settings ): void {
		$methods = array_map( array( PaymentMethods::class, 'label' ), PaymentMethods::enabled() );

		echo '<section class="pqbg-panel" aria-labelledby="pqbg-dash-setup"><h2 id="pqbg-dash-setup">' . esc_html__( 'Setup', 'product-qrcode-barcode-generator' ) . '</h2><dl class="pqbg-dashboard__list">';
		echo '<div><dt>' . esc_html__( 'Scan links point to', 'product-qrcode-barcode-generator' ) . '</dt><dd><code>' . esc_html( ScanUrl::base() ) . '</code></dd></div>';
		echo '<div><dt>' . esc_html__( 'Example scan link', 'product-qrcode-barcode-generator' ) . '</dt><dd><code>' . esc_html( ScanUrl::example() ) . '</code></dd></div>';
		echo '<div><dt>' . esc_html__( 'Barcodes', 'product-qrcode-barcode-generator' ) . '</dt><dd>' . esc_html( Settings::is_barcode_enabled() ? __( 'On (Code 128, for hardware scanners)', 'product-qrcode-barcode-generator' ) : __( 'Off (QR codes only)', 'product-qrcode-barcode-generator' ) ) . '</dd></div>';
		echo '<div><dt>' . esc_html__( 'Payment methods offered', 'product-qrcode-barcode-generator' ) . '</dt><dd>' . esc_html( implode( ', ', $methods ) ) . '</dd></div>';
		echo '</dl>';

		if ( $settings ) {
			echo '<p><a href="' . esc_url( AdminUrl::settings() ) . '">' . esc_html__( 'Change in Settings', 'product-qrcode-barcode-generator' ) . '</a></p>';
		}

		echo '</section>';
	}

	/**
	 * Quick links.
	 *
	 * @param bool $codes    Code and label links.
	 * @param bool $costs    The cost import link.
	 * @param bool $settings The Settings link.
	 */
	private static function render_links( bool $codes, bool $costs, bool $settings ): void {
		$links = array( array( ScanUrl::site_url(), __( 'Open the scan page (sell on a phone)', 'product-qrcode-barcode-generator' ) ) );

		if ( $codes ) {
			$links[] = array( AdminUrl::products(), __( 'Print labels (Products → select → "Print QR labels")', 'product-qrcode-barcode-generator' ) );
			$links[] = array( AdminUrl::bulk_tools( ToolsAdmin::TAB_TOOLS ), __( 'Generate missing codes', 'product-qrcode-barcode-generator' ) );
		}

		$links[] = array( AdminUrl::reports( array( 'tab' => 'eod', 'range' => 'today' ) ), __( 'End of day', 'product-qrcode-barcode-generator' ) );
		$links[] = array( AdminUrl::sales( array( 'range' => 'today' ) ), __( 'Today\'s sales', 'product-qrcode-barcode-generator' ) );

		if ( $costs ) {
			$links[] = array( AdminUrl::bulk_tools( ToolsAdmin::TAB_COSTS ), __( 'Import cost prices', 'product-qrcode-barcode-generator' ) );
		}

		if ( $settings ) {
			$links[] = array( AdminUrl::settings(), __( 'Settings', 'product-qrcode-barcode-generator' ) );
		}

		echo '<section class="pqbg-panel" aria-labelledby="pqbg-dash-links"><h2 id="pqbg-dash-links">' . esc_html__( 'Quick links', 'product-qrcode-barcode-generator' ) . '</h2><ul class="pqbg-dashboard__links">';

		foreach ( $links as $link ) {
			echo '<li><a class="button" href="' . esc_url( $link[0] ) . '">' . esc_html( $link[1] ) . '</a></li>';
		}

		echo '</ul></section>';
	}
}
