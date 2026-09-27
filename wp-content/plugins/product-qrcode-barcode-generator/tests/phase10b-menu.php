<?php
/**
 * Phase 10B suite: the "QR & Barcodes" menu, the plugin Dashboard and one source for admin URLs.
 *
 * The menu tree per role over real HTTP (administrator, shop manager, Store Seller,
 * customer, logged out), its position, icon, order and labels; nothing left under
 * WooCommerce; the hidden Products screens stay hidden; menu highlighting on sub-screens;
 * the shared tab row per role; every old admin.php?page=pqbg-settings address redirects
 * (302, GET/HEAD) with its query arguments, and In-store sales / reports addresses keep
 * working; the reports' "dashboard" tab value opens Summary; every page and handler
 * refuses the wrong role (403); links in notices, the product panel, the products-list
 * bulk action, print pages, sale screens, handler redirects and the Dashboard point to
 * the new URLs, and no CSV contains an admin URL; the Dashboard's figures equal In-store
 * reports → Summary (today) and the Phase 9A totals, and cost/profit are hidden from shop
 * managers; attention items; GET/HEAD never write; accessibility markup; Dashboard timings
 * at 5,000 sales (50,000 with PQBG_STRESS=1); the AdminUrl / screen-ID scope checks; cleanup.
 *
 * Fixture sale rows are inserted directly into pqbg_sales (marked in `note`), as in the
 * Phase 9B suite. Everything created is removed at the end.
 *
 *   php tests/phase10b-menu.php
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/bootstrap.php';

pqbg_test_load_wp();
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/post.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use ProductQrBarcode\{AdminActions, AdminMenu, AdminUrl, BulkGenerator, BulkLog, CodeRepository, CodesExport, CostPrice, DashboardAdmin, Permissions, Plugin, PrintAdmin, PrintPage, ReportPeriod, ReportPrint, ReportsAdmin, ReportsExport, SalePresenter, SalesAdmin, SalesExport, SalesQuery, ScanUrl, Schema, Settings, ToolsAdmin};

global $wpdb;

$C          = Schema::codes_table();
$S          = Schema::sales_table();
$PM         = $wpdb->postmeta;
$NOTE       = 'pqbg-10b-fixture';
$PERF_NOTE  = 'pqbg-10b-perf';
$STRESS     = '1' === getenv( 'PQBG_STRESS' );
$max        = static fn( string $table, string $col ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX($col), 0) FROM $table" );
$start_c    = $max( $C, 'id' );
$start_s    = $max( $S, 'id' );
$as_mark    = pqbg_test_as_mark();
$start_post = $max( $wpdb->posts, 'ID' );
$posts_ai   = static fn() => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->posts ) );
$start_ai   = $posts_ai();
$base_cost  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $PM WHERE meta_key = %s", CostPrice::META_KEY ) );
$base_user  = (int) count_users()['total_users'];
$raw_option = static fn( string $name ) => $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
$saved      = array();
foreach ( array( Plugin::SETTINGS_OPTION, BulkGenerator::OPTION, BulkLog::OPTION, 'pqbg_svg_cache_index' ) as $name ) {
	$saved[ $name ] = $raw_option( $name );
}
$svg_rows   = static fn() => $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_pqbg\\_svg\\_%' OR option_name LIKE '\\_transient\\_timeout\\_pqbg\\_svg\\_%'" );
$svg_before = $svg_rows();
$user_ids   = array();
$pw         = array();

add_filter( 'pre_wp_mail', '__return_false' );
add_filter(
	'wp_die_handler',
	static fn() => static function ( $message ) {
		throw new RuntimeException( 'wp_die: ' . wp_strip_all_tags( is_wp_error( $message ) ? $message->get_error_message() : (string) $message ) );
	}
);

// Cooperative stop (the runner's memory watchdog creates PQBG_STOP_FILE): throw, so the cleanup still runs.
$stop_file = (string) getenv( 'PQBG_STOP_FILE' );
$sec       = static function ( string $title ) use ( $stop_file ): void {
	if ( '' !== $stop_file && file_exists( $stop_file ) ) {
		throw new RuntimeException( 'Stopped on request (PQBG_STOP_FILE): the machine is low on free memory.' );
	}
	pqbg_section( $title );
};

// Leftovers of an interrupted earlier run (marked rows only).
$wpdb->query( $wpdb->prepare( "DELETE FROM $S WHERE note IN (%s, %s)", $NOTE, $PERF_NOTE ) );

$median = static function ( array $v ): float {
	sort( $v );
	return (float) $v[ intdiv( count( $v ), 2 ) ];
};
$best_ms = static function ( callable $fn, int $n = 3 ): float {
	$best = INF;
	for ( $i = 0; $i < $n; $i++ ) {
		wp_cache_flush();
		$t    = hrtime( true );
		$fn();
		$best = min( $best, ( hrtime( true ) - $t ) / 1e6 );
	}
	return $best;
};
/** Inserts a fixture sale row (test data, marked). */
$put = static function ( array $cols ) use ( $wpdb, $S, $NOTE ): int {
	$wpdb->insert(
		$S,
		array_merge(
			array(
				'request_id'     => wp_generate_uuid4(),
				'product_id'     => 1,
				'variation_id'   => 0,
				'seller_id'      => 1,
				'quantity'       => 1,
				'unit_price'     => '100',
				'line_total'     => '100',
				'currency'       => 'INR',
				'product_name'   => 'Fixture 10B',
				'status'         => 'completed',
				'created_at_gmt' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
				'payment_method' => 'cash',
				'note'           => $NOTE,
			),
			$cols
		)
	);
	return (int) $wpdb->insert_id;
};
/** Checksum of everything a GET must not change. */
$state = static function () use ( $wpdb, $S ): string {
	return md5(
		implode(
			'|',
			array(
				(string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, status, COALESCE(payment_method, ''), COALESCE(unit_cost, ''), COALESCE(voided_by, '')))), 0)) FROM $S" ),
				(string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT(post_id, meta_key, meta_value))), 0)) FROM {$wpdb->postmeta} WHERE meta_key IN ('_pqbg_cost_price', '_stock', '_stock_status', '_price')" ),
				(string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', MAX(ID)) FROM {$wpdb->posts}" ),
				(string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(id), 0)) FROM {$wpdb->prefix}pqbg_codes" ),
				(string) $wpdb->get_var( "SELECT GROUP_CONCAT(CONCAT(option_name, '=', MD5(option_value)) ORDER BY option_name) FROM {$wpdb->options} WHERE option_name LIKE 'pqbg%'" ),
			)
		)
	);
};

// HTTP client: a fresh connection per request (see the Phase 6 notes about this XAMPP's php8ts.dll).
$handles = array();
$http    = static function ( string $who, string $method, string $url, array|string|null $post = null ) use ( &$handles ): array {
	if ( ! isset( $handles[ $who ] ) ) {
		$handles[ $who ] = curl_init();
		curl_setopt( $handles[ $who ], CURLOPT_COOKIEFILE, '' );
	}
	$ch = $handles[ $who ];
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 300,
			CURLOPT_FORBID_REUSE   => true,
			CURLOPT_NOBODY         => 'HEAD' === $method,
			CURLOPT_POST           => 'POST' === $method,
			CURLOPT_HTTPGET        => 'GET' === $method,
		)
	);
	if ( 'POST' === $method ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, is_array( $post ) ? http_build_query( $post ) : (string) $post );
	}
	$raw  = (string) curl_exec( $ch );
	$size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	wp_cache_flush(); // Apache may have changed options or rows: later in-process reads come from the database.
	$hdrs = array();
	foreach ( preg_split( '/\r?\n/', substr( $raw, 0, $size ) ) as $line ) {
		if ( preg_match( '/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m ) ) {
			$hdrs[ strtolower( $m[1] ) ] = trim( $m[2] );
		}
	}
	curl_setopt( $ch, CURLOPT_NOBODY, false );
	return array(
		'code'     => (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ),
		'location' => html_entity_decode( $hdrs['location'] ?? '' ),
		'headers'  => $hdrs,
		'body'     => substr( $raw, $size ),
		'time'     => (float) curl_getinfo( $ch, CURLINFO_TOTAL_TIME ),
	);
};
$login = static function ( string $who, string $user, string $pass ) use ( $http ): bool {
	$http( $who, 'GET', wp_login_url() );
	$r = $http( $who, 'POST', wp_login_url(), array( 'log' => $user, 'pwd' => $pass, 'wp-submit' => 'Log In', 'testcookie' => '1', 'redirect_to' => admin_url() ) );
	return 302 === $r['code'];
};
$field   = static fn( string $html, string $name ) => preg_match( '/name="' . preg_quote( $name, '/' ) . '" value="([^"]*)"/', $html, $m ) ? html_entity_decode( $m[1] ) : '';
$link_of = static fn( string $html, string $action ) => preg_match( '/href="([^"]*action=' . preg_quote( $action, '/' ) . '[^"]*)"/', $html, $m ) ? html_entity_decode( $m[1] ) : '';
$href    = static fn( string $url ) => 'href="' . esc_url( $url ) . '"';
/** The query arguments of a URL. */
$query = static function ( string $url ): array {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
	return $q;
};
/** A block of HTML from a marker up to the first closing tag after it. */
$block = static function ( string $html, string $marker, string $until ): string {
	$from = strpos( $html, $marker );
	if ( false === $from ) {
		return '';
	}
	$rest = substr( $html, $from );
	$end  = strpos( $rest, $until );
	return false === $end ? $rest : substr( $rest, 0, $end );
};
/** Top-level admin menu item IDs, in order. */
$top_ids = static function ( string $html ): array {
	preg_match_all( '/<li class="[^"]*\bmenu-top\b[^"]*" id="([^"]+)"/', $html, $m );
	return $m[1];
};
/** The QR & Barcodes sub-items: slug => label, in order. */
$qr_items = static function ( string $html ) use ( $block ): array {
	$menu = $block( $html, 'id="toplevel_page_' . AdminUrl::DASHBOARD . '"', '</ul>' );
	preg_match_all( '/<li[^>]*><a href=[\'"]admin\.php\?page=([a-z0-9_-]+)[\'"][^>]*>([^<]*)<\/a>/', $menu, $m );
	return array_combine( $m[1], array_map( 'html_entity_decode', $m[2] ) );
};
/** The shared tab row: list of [href, label, active]. */
$plugin_nav = static function ( string $html ) use ( $block ): array {
	$nav = $block( $html, '<nav class="nav-tab-wrapper wp-clearfix pqbg-plugin-nav"', '</nav>' );
	preg_match_all( '/<a href="([^"]+)" class="nav-tab( nav-tab-active)?"( aria-current="page")?>([^<]+)<\/a>/', $nav, $m, PREG_SET_ORDER );
	return array_map( static fn( $x ) => array( html_entity_decode( $x[1] ), html_entity_decode( $x[4] ), '' !== $x[2] && '' !== $x[3] ), $m );
};
/** Whether the QR & Barcodes sub-item of a slug is highlighted as current. */
$current_item = static function ( string $html, string $slug ) use ( $block ): bool {
	$menu = $block( $html, 'id="toplevel_page_' . AdminUrl::DASHBOARD . '"', '</ul>' );
	return (bool) preg_match( '/<li class="[^"]*\bcurrent\b[^"]*"><a href=[\'"]admin\.php\?page=' . preg_quote( $slug, '/' ) . '[\'"][^>]*aria-current="page"/', $menu )
		&& (bool) preg_match( '/<li class="[^"]*\bwp-has-current-submenu\b[^"]*" id="toplevel_page_' . preg_quote( AdminUrl::DASHBOARD, '/' ) . '"/', $html );
};
/** A simple product with stock, saved as $as (a code is assigned when $as manages codes). */
$make_simple = static function ( int $as, array $props = array() ): int {
	wp_set_current_user( $as );
	$p = new WC_Product_Simple();
	$p->set_name( 'PQBG 10B simple ' . wp_generate_password( 6, false ) );
	$p->set_status( 'publish' );
	$p->set_regular_price( '100' );
	$p->set_props( $props );
	$id = (int) $p->save();
	wp_set_current_user( 0 );
	return $id;
};
$make_variable = static function ( int $as, array $vars ): array {
	wp_set_current_user( $as );
	$opts = array_map( static fn( $i ) => 'S' . $i, range( 1, count( $vars ) ) );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( $opts );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$p = new WC_Product_Variable();
	$p->set_name( 'PQBG 10B variable ' . wp_generate_password( 6, false ) );
	$p->set_status( 'publish' );
	$p->set_attributes( array( $attr ) );
	$pid  = (int) $p->save();
	$vids = array();
	foreach ( $vars as $i => $vp ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $pid );
		$v->set_attributes( array( 'size' => $opts[ $i ] ) );
		$v->set_regular_price( '100' );
		$v->set_props( $vp );
		$vids[] = (int) $v->save();
	}
	wp_set_current_user( 0 );
	return array( $pid, $vids );
};

try {
	// ------------------------------------------------------------------ users
	$make_user = static function ( string $role ) use ( &$user_ids, &$pw ): int {
		$login = 'pqbg10b_' . $role . '_' . wp_generate_password( 5, false, false );
		$pass  = wp_generate_password( 20, true, false );
		$id    = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => $pass,
				'user_email'   => $login . '@example.invalid',
				'role'         => $role,
				'display_name' => $login,
			)
		);
		$user_ids[ $login ] = (int) $id;
		$pw[ $login ]       = $pass;
		return (int) $id;
	};
	$A      = $make_user( 'administrator' );
	$SM     = $make_user( 'shop_manager' );
	$SE     = $make_user( 'pqbg_seller' );
	$CU     = $make_user( 'customer' );
	$logins = array_flip( $user_ids );

	// ------------------------------------------------------------------ in-process
	$sec( 'menu definition and the old-URL map (in-process)' );
	$pages = AdminMenu::pages();
	pqbg_t( 'five pages in order: Dashboard, In-store sales, In-store reports, Bulk tools, Settings', array( AdminUrl::DASHBOARD, AdminUrl::SALES, AdminUrl::REPORTS, AdminUrl::BULK_TOOLS, AdminUrl::SETTINGS ) === array_keys( $pages ) && array( 'Dashboard', 'In-store sales', 'In-store reports', 'Bulk tools', 'Settings' ) === array_column( $pages, 'label' ) );
	pqbg_t( 'capabilities unchanged: view_all_sales ×3, manage_codes (Bulk tools), manage_settings (Settings)', array( Permissions::VIEW_ALL_SALES, Permissions::VIEW_ALL_SALES, Permissions::VIEW_ALL_SALES, Permissions::MANAGE_CODES, Permissions::MANAGE_SETTINGS ) === array_column( $pages, 'cap' ) );
	pqbg_t( 'slugs: the Phase 9A/9B/4 slugs are kept (their URLs do not change)', 'pqbg-sales' === AdminUrl::SALES && 'pqbg-reports' === AdminUrl::REPORTS && 'pqbg-settings' === AdminUrl::SETTINGS && SalesAdmin::SLUG === AdminUrl::SALES && ReportsAdmin::SLUG === AdminUrl::REPORTS && PrintAdmin::SLUG === AdminUrl::PRINT );
	pqbg_t( 'the menu hooks are admin-only (not registered in this CLI request)', false === has_action( 'admin_menu', array( AdminMenu::class, 'add_pages' ) ) && false === has_action( 'admin_menu', array( AdminMenu::class, 'redirect_old_url' ) ) );
	$targets = static function ( int $user ) use ( $query ): array {
		$out = array();
		foreach ( array( '' => array(), 'settings' => array( 'settings-updated' => 'true' ), 'tools' => array( 'pqbg_msg' => 'generate_done', 'x' => 'a b&c' ), 'costs' => array( 'token' => 'AbC123' ), 'bogus' => array() ) as $tab => $args ) {
			$t           = AdminMenu::old_url_target( array_merge( array( 'page' => AdminUrl::SETTINGS ), '' === $tab ? array() : array( 'tab' => $tab ), $args ), $user );
			$out[ $tab ] = null === $t ? null : $query( $t );
		}
		return $out;
	};
	$ta = $targets( $A );
	$ts = $targets( $SM );
	pqbg_t(
		'administrator: no tab stays (Settings); &tab=settings → Settings (tab dropped, args kept); tools/costs → Bulk tools with every argument; unknown tab stays (404)',
		null === $ta[''] && array( 'page' => AdminUrl::SETTINGS, 'settings-updated' => 'true' ) === $ta['settings'] && array( 'page' => AdminUrl::BULK_TOOLS, 'tab' => 'tools', 'pqbg_msg' => 'generate_done', 'x' => 'a b&c' ) === $ta['tools'] && array( 'page' => AdminUrl::BULK_TOOLS, 'tab' => 'costs', 'token' => 'AbC123' ) === $ta['costs'] && null === $ta['bogus'],
		wp_json_encode( $ta )
	);
	pqbg_t(
		'shop manager: no tab → Bulk tools (they used to land on Code tools); tools → Bulk tools; settings and costs stay (WordPress / the page answer 403)',
		array( 'page' => AdminUrl::BULK_TOOLS ) === $ts[''] && array( 'page' => AdminUrl::BULK_TOOLS, 'tab' => 'tools', 'pqbg_msg' => 'generate_done', 'x' => 'a b&c' ) === $ts['tools'] && null === $ts['settings'] && null === $ts['costs'] && null === $ts['bogus'],
		wp_json_encode( $ts )
	);
	pqbg_t( 'seller and customer: never redirected (they keep getting 403)', array() === array_filter( $targets( $SE ) ) && array() === array_filter( $targets( $CU ) ) );
	pqbg_t( 'array arguments survive the redirect', array( 'page' => AdminUrl::BULK_TOOLS, 'tab' => 'tools', 'ids' => array( '1', '2' ) ) === $query( (string) AdminMenu::old_url_target( array( 'page' => AdminUrl::SETTINGS, 'tab' => 'tools', 'ids' => array( '1', '2' ) ), $A ) ) );
	pqbg_t( 'the reports\' old "dashboard" tab value opens Summary; Summary is the default; no report tab is called "Dashboard"', 'summary' === ReportsAdmin::context( array( 'tab' => 'dashboard' ), false )['tab'] && 'summary' === ReportsAdmin::context( array(), false )['tab'] && 'Summary' === ReportsAdmin::tabs( true )['summary'] && ! in_array( 'Dashboard', ReportsAdmin::tabs( true ), true ) && ! isset( ReportsAdmin::tabs( true )['dashboard'] ) );
	pqbg_t( 'AdminUrl builds the expected addresses', admin_url( 'admin.php?page=pqbg-dashboard' ) === AdminUrl::dashboard() && admin_url( 'admin.php?page=pqbg-sales&sale=7&pqbg_view=void' ) === AdminUrl::sale_void( 7 ) && admin_url( 'admin.php?page=pqbg-bulk-tools&tab=costs' ) === AdminUrl::bulk_tools( 'costs' ) && admin_url( 'edit.php?post_type=product&product_cat=a%20b' ) === AdminUrl::products( array( 'product_cat' => 'a b' ) ) && admin_url( 'post.php?post=5&action=edit' ) === AdminUrl::product_edit( 5 ) );

	// ------------------------------------------------------------------ fixtures
	$sec( 'fixtures' );
	$p_code   = $make_simple( $A, array( 'manage_stock' => true, 'stock_quantity' => 1 ) ); // Coded (admin saves), low stock.
	$p_nocode = $make_simple( 0 );                                                          // Published, no code.
	pqbg_t( 'a coded product and one without a code', null !== CodeRepository::find_active_for_product( $p_code ) && null === CodeRepository::find_active_for_product( $p_nocode ) );
	$d0 = DashboardAdmin::data( true );
	$f  = array(
		'cash1' => $put( array( 'payment_method' => 'cash', 'line_total' => '250', 'unit_price' => '125', 'quantity' => 2, 'unit_cost' => '100' ) ),
		'cash2' => $put( array( 'payment_method' => 'cash', 'line_total' => '100', 'unit_cost' => '60' ) ),
		'upi'   => $put( array( 'payment_method' => 'upi', 'line_total' => '300', 'unit_price' => '300', 'unit_cost' => '123.45' ) ),
		'card'  => $put( array( 'payment_method' => 'card', 'line_total' => '400', 'unit_price' => '400' ) ), // Unknown cost.
		'void'  => $put( array( 'payment_method' => 'cash', 'line_total' => '1000', 'unit_price' => '1000', 'status' => 'voided', 'voided_by' => $SM, 'voided_at_gmt' => gmdate( 'Y-m-d H:i:s' ), 'void_reason' => 'x' ) ),
		'fail'  => $put( array( 'payment_method' => 'upi', 'line_total' => '50', 'unit_price' => '50', 'status' => 'failed', 'failure_code' => 'sold_online' ) ),
	);
	$d1 = DashboardAdmin::data( true );
	$dd = static fn( string $k ) => round( (float) $d1['now'][ $k ] - (float) $d0['now'][ $k ], 2 );

	// ------------------------------------------------------------------ Dashboard figures
	$sec( 'Dashboard figures reconcile with In-store reports → Summary and the Phase 9A totals' );
	$today = ReportPeriod::resolve( 'today' );
	$sum   = ReportsAdmin::dashboard_data( $today, true );
	pqbg_t( 'every Dashboard figure is the Summary\'s "today" figure (same function, same period)', $sum['now'] === $d1['now'] && $sum['methods'] === $d1['methods'] && $sum['voided'] === $d1['voided'] && $sum['low'] === $d1['low'] && $sum['out'] === $d1['out'] && $sum['nocode'] === $d1['nocode'] );
	$tot = SalesQuery::totals( ReportPeriod::where_filters( $today ) );
	$mok = true;
	foreach ( $tot['methods'] as $key => $m ) {
		$mok = $mok && abs( (float) $m['revenue'] - (float) ( $d1['methods'][ $key ]['revenue'] ?? 0 ) ) < 0.005 && (int) $m['count'] === (int) ( $d1['methods'][ $key ]['count'] ?? 0 );
	}
	pqbg_t( 'and equals the Phase 9A totals for today (revenue, count, per payment method, voided)', abs( (float) $tot['all']['revenue'] - (float) $d1['now']['revenue'] ) < 0.005 && (int) $tot['all']['count'] === $d1['now']['count'] && $mok && (int) $tot['voided'] === $d1['voided'] );
	pqbg_t( 'the fixtures add exactly: revenue 1,050, 4 sales, 5 items, 1 void (the failed sale is not counted)', 1050.0 === $dd( 'revenue' ) && 4 === $d1['now']['count'] - $d0['now']['count'] && 5 === $d1['now']['items'] - $d0['now']['items'] && 1 === $d1['voided'] - $d0['voided'] );
	pqbg_t( 'profit leaves out the unknown-cost sale (known revenue 650, cost 383.45 → profit +266.55)', 266.55 === $dd( 'profit' ) && 1 === $d1['now']['unknown'] - $d0['now']['unknown'] );
	pqbg_t( 'without costs no cost key exists at all', ! array_intersect_key( DashboardAdmin::data( false )['now'], array_flip( array( 'profit', 'margin', 'cost', 'known_revenue' ) ) ) );
	pqbg_t( 'products: the unpublished-code count and the low stock include the fixtures (9B definitions)', $d1['nocode'] >= 1 && $d1['low'] >= 1 );

	// ------------------------------------------------------------------ HTTP: menus
	$sec( 'HTTP: the QR & Barcodes menu for every role' );
	pqbg_t( 'logins succeed', $login( 'admin', $logins[ $A ], $pw[ $logins[ $A ] ] ) && $login( 'sm', $logins[ $SM ], $pw[ $logins[ $SM ] ] ) && $login( 'seller', $logins[ $SE ], $pw[ $logins[ $SE ] ] ) && $login( 'customer', $logins[ $CU ], $pw[ $logins[ $CU ] ] ) );
	$home = array();
	foreach ( array( 'admin', 'sm' ) as $who ) {
		$home[ $who ] = $http( $who, 'GET', AdminUrl::dashboard() );
	}
	$ids = $top_ids( $home['admin']['body'] );
	$pos = array_search( 'toplevel_page_' . AdminUrl::DASHBOARD, $ids, true );
	pqbg_t( 'administrator: the Dashboard opens (200) and "QR & Barcodes" is a top-level item directly below Products', 200 === $home['admin']['code'] && false !== $pos && 'menu-posts-product' === ( $ids[ $pos - 1 ] ?? '' ), implode( ' ', array_slice( $ids, max( 0, (int) $pos - 3 ), 6 ) ) );
	$top = $block( $home['admin']['body'], 'id="toplevel_page_' . AdminUrl::DASHBOARD . '"', '</ul>' );
	pqbg_t( 'the item uses the generic grid dashicon and the label "QR & Barcodes"', str_contains( $top, AdminMenu::ICON ) && str_contains( $top, "<div class='wp-menu-name'>QR &#038; Barcodes</div>" ) );
	pqbg_t( 'administrator: sub-items exactly Dashboard, In-store sales, In-store reports, Bulk tools, Settings', array( AdminUrl::DASHBOARD => 'Dashboard', AdminUrl::SALES => 'In-store sales', AdminUrl::REPORTS => 'In-store reports', AdminUrl::BULK_TOOLS => 'Bulk tools', AdminUrl::SETTINGS => 'Settings' ) === $qr_items( $home['admin']['body'] ), wp_json_encode( $qr_items( $home['admin']['body'] ) ) );
	$sids = $top_ids( $home['sm']['body'] );
	$spos = array_search( 'toplevel_page_' . AdminUrl::DASHBOARD, $sids, true );
	pqbg_t( 'shop manager: the same place; sub-items Dashboard, In-store sales, In-store reports, Bulk tools (no Settings)', 200 === $home['sm']['code'] && false !== $spos && 'menu-posts-product' === ( $sids[ $spos - 1 ] ?? '' ) && array( AdminUrl::DASHBOARD => 'Dashboard', AdminUrl::SALES => 'In-store sales', AdminUrl::REPORTS => 'In-store reports', AdminUrl::BULK_TOOLS => 'Bulk tools' ) === $qr_items( $home['sm']['body'] ), wp_json_encode( $qr_items( $home['sm']['body'] ) ) );
	$leftover = array();
	foreach ( array( 'admin', 'sm' ) as $who ) {
		$wc = $block( $home[ $who ]['body'], 'id="toplevel_page_woocommerce"', '</ul>' );
		$pr = $block( $home[ $who ]['body'], 'id="menu-posts-product"', '</ul>' );
		if ( '' === $wc || str_contains( $wc, 'pqbg' ) || str_contains( $pr, 'pqbg' ) ) {
			$leftover[] = $who;
		}
	}
	pqbg_t( 'nothing left under WooCommerce, and the hidden Products screens (print setup, Regenerate) stay out of the menu', array() === $leftover, implode( ', ', $leftover ) );
	$pqbg_links = preg_match_all( '/href=[\'"][^\'"]*page=pqbg-[a-z-]+/', $block( $home['admin']['body'], '<ul id="adminmenu"', '<div id="wpcontent"' ) );
	pqbg_t( 'the admin sidebar links to plugin pages only from QR & Barcodes (the top-level link plus 5 sub-items)', 6 === $pqbg_links, (string) $pqbg_links );
	foreach ( array( 'seller', 'customer' ) as $who ) {
		$r = $http( $who, 'GET', admin_url( 'index.php' ) );
		pqbg_t( "$who: wp-admin sends them away (WooCommerce's redirect) and no plugin menu appears", 302 === $r['code'] && ! str_contains( $r['body'], 'pqbg' ), $r['code'] . ' ' . $r['location'] );
	}
	$r = $http( 'anon', 'GET', AdminUrl::dashboard() );
	pqbg_t( 'logged out: the Dashboard address leads to the login page', 302 === $r['code'] && str_contains( $r['location'], 'wp-login.php' ) );

	// ------------------------------------------------------------------ HTTP: pages, tabs, highlighting
	$sec( 'HTTP: every page, the shared tab row and highlighting' );
	$all_pages = array(
		AdminUrl::DASHBOARD  => AdminUrl::dashboard(),
		AdminUrl::SALES      => AdminUrl::sales(),
		AdminUrl::REPORTS    => AdminUrl::reports(),
		AdminUrl::BULK_TOOLS => AdminUrl::bulk_tools(),
		AdminUrl::SETTINGS   => AdminUrl::settings(),
	);
	$bodies = array();
	$bad    = array();
	foreach ( array( 'admin', 'sm' ) as $who ) {
		foreach ( $all_pages as $slug => $url ) {
			$r                        = $http( $who, 'GET', $url );
			$bodies[ $who ][ $slug ]  = $r;
			// D8: for a shop manager admin.php?page=pqbg-settings is also the old no-tab address, which redirects to Bulk tools.
			$want                     = 'sm' === $who && AdminUrl::SETTINGS === $slug ? 302 : 200;
			if ( $want !== $r['code'] || ( 302 === $want && AdminUrl::bulk_tools() !== $r['location'] ) || preg_match( '/(Fatal error|Warning|Notice|Deprecated)<\/b>:/', $r['body'] ) ) {
				$bad[] = "$who $slug {$r['code']}";
			}
		}
	}
	pqbg_t( 'administrator: every page 200; shop manager: every page 200 except Settings, which sends them to Bulk tools (302, D8); no PHP errors', array() === $bad, implode( ', ', $bad ) );
	$nav_bad = array();
	$expect  = array(
		'admin' => array( AdminUrl::DASHBOARD => 'Dashboard', AdminUrl::SALES => 'In-store sales', AdminUrl::REPORTS => 'In-store reports', AdminUrl::BULK_TOOLS => 'Bulk tools', AdminUrl::SETTINGS => 'Settings' ),
		'sm'    => array( AdminUrl::DASHBOARD => 'Dashboard', AdminUrl::SALES => 'In-store sales', AdminUrl::REPORTS => 'In-store reports', AdminUrl::BULK_TOOLS => 'Bulk tools' ),
	);
	foreach ( $expect as $who => $tabs ) {
		foreach ( $tabs as $slug => $label ) {
			$nav  = $plugin_nav( $bodies[ $who ][ $slug ]['body'] );
			$want = array();
			foreach ( $tabs as $s => $l ) {
				$want[] = array( AdminUrl::page( $s ), $l, $s === $slug );
			}
			if ( $want !== $nav ) {
				$nav_bad[] = "$who on $slug: " . wp_json_encode( $nav );
			}
			if ( ! $current_item( $bodies[ $who ][ $slug ]['body'], $slug ) ) {
				$nav_bad[] = "$who on $slug: sidebar item not current";
			}
		}
	}
	pqbg_t( 'the shared tab row on every page: one tab per page the user may open, in menu order, links from AdminUrl, the current page marked (aria-current); the sidebar item is highlighted', array() === $nav_bad, implode( ' | ', $nav_bad ) );
	pqbg_t( 'page-internal tabs are a second row below it (reports: Summary …; Bulk tools: Code tools | Import cost prices, shop manager only Code tools)', strpos( $bodies['admin'][ AdminUrl::REPORTS ]['body'], 'pqbg-plugin-nav' ) < strpos( $bodies['admin'][ AdminUrl::REPORTS ]['body'], 'pqbg-reports__tabs' ) && strpos( $bodies['admin'][ AdminUrl::BULK_TOOLS ]['body'], 'pqbg-plugin-nav' ) < strpos( $bodies['admin'][ AdminUrl::BULK_TOOLS ]['body'], 'pqbg-page-tabs' ) && str_contains( $block( $bodies['admin'][ AdminUrl::BULK_TOOLS ]['body'], 'pqbg-page-tabs', '</nav>' ), 'Import cost prices' ) && ! str_contains( $block( $bodies['sm'][ AdminUrl::BULK_TOOLS ]['body'], 'pqbg-page-tabs', '</nav>' ), 'Import cost prices' ) );
	$rep_tabs = $block( $bodies['admin'][ AdminUrl::REPORTS ]['body'], 'pqbg-reports__tabs', '</nav>' );
	pqbg_t( 'In-store reports: the first tab is "Summary" (active by default); only one "Dashboard" in the plugin (the menu item and the tab row, not the reports)', str_contains( $rep_tabs, 'nav-tab-active" aria-current="page">Summary</a>' ) && ! str_contains( $rep_tabs, '>Dashboard<' ) );
	$r = $http( 'admin', 'GET', AdminUrl::reports( array( 'tab' => 'dashboard', 'range' => 'this_week' ) ) );
	pqbg_t( 'the old reports address &tab=dashboard still opens Summary (200, Summary active, its range kept)', 200 === $r['code'] && str_contains( $block( $r['body'], 'pqbg-reports__tabs', '</nav>' ), 'nav-tab-active" aria-current="page">Summary</a>' ) && (bool) preg_match( '/<a href="[^"]*range=this_week"[^>]*class="current"/', $r['body'] ) );
	$csv_link = $link_of( $http( 'admin', 'GET', AdminUrl::reports( array( 'tab' => 'products' ) ) )['body'], ReportsExport::ACTION ); // The nonce of the administrator's session.
	pqbg_t( 'the Summary (also as &tab=dashboard) has no CSV: 400', '' !== $csv_link && 400 === $http( 'admin', 'GET', add_query_arg( 'tab', 'dashboard', $csv_link ) )['code'] && 400 === $http( 'admin', 'GET', add_query_arg( 'tab', 'summary', $csv_link ) )['code'] );

	// Hidden sub-screens keep the right item highlighted.
	$hl = array(
		'sale detail'            => array( 'sm', AdminUrl::sale( $f['cash1'] ), AdminUrl::SALES ),
		'void screen'            => array( 'sm', AdminUrl::sale_void( $f['cash2'] ), AdminUrl::SALES ),
		'a report tab'           => array( 'sm', AdminUrl::reports( array( 'tab' => 'stock', 'state' => 'low' ) ), AdminUrl::REPORTS ),
		'Import cost prices tab' => array( 'admin', AdminUrl::bulk_tools( ToolsAdmin::TAB_COSTS ), AdminUrl::BULK_TOOLS ),
		'Code tools with a message' => array( 'sm', AdminUrl::bulk_tools( ToolsAdmin::TAB_TOOLS, array( ToolsAdmin::MESSAGE_ARG => 'generate_done' ) ), AdminUrl::BULK_TOOLS ),
		'"Settings saved."'      => array( 'admin', AdminUrl::settings( array( 'settings-updated' => 'true' ) ), AdminUrl::SETTINGS ),
	);
	$hl_bad = array();
	foreach ( $hl as $label => $x ) {
		$r = $http( $x[0], 'GET', $x[1] );
		if ( 200 !== $r['code'] || ! $current_item( $r['body'], $x[2] ) || ! in_array( true, array_column( $plugin_nav( $r['body'] ), 2 ), true ) || AdminUrl::page( $x[2] ) !== ( array_values( array_filter( $plugin_nav( $r['body'] ), static fn( $t ) => $t[2] ) )[0][0] ?? '' ) ) {
			$hl_bad[] = "$label {$r['code']}";
		}
	}
	pqbg_t( 'sub-screens highlight their item in the sidebar and the tab row (sale detail, void, report tabs, Bulk tools tabs, Settings saved)', array() === $hl_bad, implode( ', ', $hl_bad ) );

	// ------------------------------------------------------------------ HTTP: old URLs
	$sec( 'HTTP: old addresses' );
	$old = static fn( array $args ) => add_query_arg( array_map( 'rawurlencode', array_merge( array( 'page' => 'pqbg-settings' ), $args ) ), admin_url( 'admin.php' ) );
	$rd  = static function ( array $r, array $want ) use ( $query ): bool {
		return 302 === $r['code'] && str_starts_with( $r['location'], admin_url( 'admin.php?' ) ) && $want === $query( $r['location'] );
	};
	$cases = array(
		array( 'admin', array( 'tab' => 'settings', 'settings-updated' => 'true' ), array( 'page' => AdminUrl::SETTINGS, 'settings-updated' => 'true' ) ),
		array( 'admin', array( 'tab' => 'tools', 'pqbg_msg' => 'generate_done', 'x' => 'a b' ), array( 'page' => AdminUrl::BULK_TOOLS, 'tab' => 'tools', 'pqbg_msg' => 'generate_done', 'x' => 'a b' ) ),
		array( 'admin', array( 'tab' => 'costs', 'token' => 'AbC123', 'pqbg_auto' => '1' ), array( 'page' => AdminUrl::BULK_TOOLS, 'tab' => 'costs', 'token' => 'AbC123', 'pqbg_auto' => '1' ) ),
		array( 'sm', array(), array( 'page' => AdminUrl::BULK_TOOLS ) ),
		array( 'sm', array( 'tab' => 'tools', 'pqbg_msg' => 'generate_stopped' ), array( 'page' => AdminUrl::BULK_TOOLS, 'tab' => 'tools', 'pqbg_msg' => 'generate_stopped' ) ),
	);
	$old_bad = array();
	foreach ( $cases as $c ) {
		$r = $http( $c[0], 'GET', $old( $c[1] ) );
		$h = $http( $c[0], 'HEAD', $old( $c[1] ) );
		if ( ! $rd( $r, $c[2] ) || ! $rd( $h, $c[2] ) || 200 !== $http( $c[0], 'GET', $r['location'] )['code'] ) {
			$old_bad[] = $c[0] . ' ' . wp_json_encode( $c[1] ) . " → {$r['code']} {$r['location']}";
		}
	}
	pqbg_t( 'every old QR & Barcodes address redirects (302, GET and HEAD) to its new page with every query argument, and the target opens (200)', array() === $old_bad, implode( ' | ', $old_bad ) );
	$r = $http( 'admin', 'GET', $old( array() ) );
	pqbg_t( 'administrator: admin.php?page=pqbg-settings is Settings itself (200, the form, no redirect)', 200 === $r['code'] && str_contains( $r['body'], "name='option_page' value='pqbg_settings'" ) );
	pqbg_t( 'unknown tab: administrator 404, shop manager 403 (not redirected)', 404 === $http( 'admin', 'GET', $old( array( 'tab' => 'bogus' ) ) )['code'] && 403 === $http( 'sm', 'GET', $old( array( 'tab' => 'bogus' ) ) )['code'] );
	pqbg_t( 'shop manager: the old Settings and cost tabs are still refused (403), not redirected', 403 === $http( 'sm', 'GET', $old( array( 'tab' => 'settings' ) ) )['code'] && 403 === $http( 'sm', 'GET', $old( array( 'tab' => 'costs', 'token' => 'x' ) ) )['code'] );
	$never = array();
	foreach ( array( 'seller', 'customer' ) as $who ) {
		foreach ( array( array(), array( 'tab' => 'tools' ), array( 'tab' => 'costs' ) ) as $args ) {
			$r = $http( $who, 'GET', $old( $args ) );
			if ( 302 === $r['code'] && str_contains( $r['location'], 'pqbg' ) ) {
				$never[] = "$who " . wp_json_encode( $args );
			}
		}
	}
	pqbg_t( 'seller and customer: never redirected to a plugin page (403 as before)', array() === $never, implode( ', ', $never ) );
	$r = $http( 'anon', 'GET', $old( array( 'tab' => 'tools' ) ) );
	pqbg_t( 'logged out: the login page, with the old address (and its tab) as the destination', 302 === $r['code'] && str_contains( $r['location'], 'wp-login.php' ) && str_contains( rawurldecode( $r['location'] ), 'page=pqbg-settings&tab=tools' ) );
	$r = $http( 'admin', 'POST', $old( array( 'tab' => 'tools' ) ), array( 'x' => '1' ) );
	pqbg_t( 'a POST is never redirected', 302 !== $r['code'] );
	$kept = array(
		AdminUrl::sales( array( 'range' => 'custom', 'from' => '2025-01-01', 'to' => '2025-01-31', 'method' => 'upi', 'status' => 'completed', 'orderby' => 'total', 'order' => 'asc' ) ),
		AdminUrl::sale( $f['upi'] ),
		AdminUrl::reports( array( 'tab' => 'products', 'range' => 'custom', 'from' => '2025-03-10', 'to' => '2025-03-16', 'view' => 'product' ) ),
		AdminUrl::reports( array( 'tab' => 'stock', 'state' => 'nocode' ) ),
		AdminUrl::reports( array( 'tab' => 'eod', 'range' => 'yesterday' ) ),
	);
	$kept_bad = array();
	foreach ( $kept as $url ) {
		$r = $http( 'sm', 'GET', $url );
		if ( 200 !== $r['code'] ) {
			$kept_bad[] = "{$r['code']} $url";
		}
	}
	$filtered = $http( 'sm', 'GET', $kept[0] );
	pqbg_t( 'In-store sales and reports addresses with their arguments are unchanged and still open (200) under the new menu', array() === $kept_bad && str_contains( $filtered['body'], 'value="2025-01-01"' ) && (bool) preg_match( '/<option value="upi" selected=\'selected\'>/', $filtered['body'] ), implode( ' | ', $kept_bad ) );

	// ------------------------------------------------------------------ HTTP: 403 matrix
	$sec( 'HTTP: every page and handler refuses the wrong role' );
	$deny = array();
	foreach ( array( 'seller', 'customer' ) as $who ) {
		foreach ( array_merge( $all_pages, array( 'costs' => AdminUrl::bulk_tools( ToolsAdmin::TAB_COSTS ), 'void' => AdminUrl::sale_void( $f['cash1'] ) ) ) as $slug => $url ) {
			$r = $http( $who, 'GET', $url );
			if ( 403 !== $r['code'] || str_contains( $r['body'], 'pqbg-plugin-nav' ) ) {
				$deny[] = "$who $slug {$r['code']}";
			}
		}
	}
	foreach ( array( AdminUrl::settings( array( 'tab' => ToolsAdmin::TAB_SETTINGS ) ), AdminUrl::settings( array( 'tab' => 'bogus' ) ), AdminUrl::bulk_tools( ToolsAdmin::TAB_COSTS ) ) as $url ) {
		$r = $http( 'sm', 'GET', $url );
		if ( 403 !== $r['code'] || str_contains( $r['body'], 'option_page' ) || str_contains( $r['body'], 'multipart' ) ) {
			$deny[] = "sm $url {$r['code']}";
		}
	}
	pqbg_t( 'pages: seller and customer 403 on every page (no tab row); shop manager 403 on Settings (every address that is not redirected to Bulk tools) and Import cost prices', array() === $deny, implode( ', ', $deny ) );
	$handlers = array(
		array( 'GET', SalesExport::ACTION ),
		array( 'POST', SalesAdmin::VOID_ACTION ),
		array( 'GET', ReportsExport::ACTION ),
		array( 'GET', ReportPrint::ACTION ),
		array( 'POST', ToolsAdmin::GENERATE ),
		array( 'GET', CodesExport::ACTION ),
		array( 'POST', ToolsAdmin::UPLOAD ),
		array( 'POST', ToolsAdmin::APPLY ),
		array( 'GET', ToolsAdmin::REPORT ),
		array( 'GET', ToolsAdmin::TEMPLATE ),
		array( 'POST', PrintAdmin::PREPARE ),
		array( 'GET', PrintPage::ACTION ),
		array( 'POST', AdminActions::GENERATE ),
		array( 'POST', AdminActions::REGENERATE ),
		array( 'GET', AdminActions::IMAGE ),
	);
	$before = $state();
	$hbad   = array();
	foreach ( array( 'seller', 'customer' ) as $who ) {
		foreach ( $handlers as $h ) {
			$args = array( 'action' => $h[1], 'item' => (string) $p_code, 'items' => (string) $p_code, 'sale' => (string) $f['cash1'], 'op' => 'start', 'confirm' => '1', 'reason' => 'x', '_wpnonce' => 'x', Permissions::NONCE_FIELD => 'x', 'type' => 'qr', 'mode' => 'view' );
			$r    = 'GET' === $h[0] ? $http( $who, 'GET', AdminUrl::admin_post( array_map( 'rawurlencode', $args ) ) ) : $http( $who, 'POST', AdminUrl::admin_post(), $args );
			if ( 403 !== $r['code'] ) {
				$hbad[] = "$who {$h[1]} {$r['code']}";
			}
		}
	}
	foreach ( array( array( 'POST', ToolsAdmin::UPLOAD ), array( 'POST', ToolsAdmin::APPLY ), array( 'GET', ToolsAdmin::REPORT ), array( 'GET', ToolsAdmin::TEMPLATE ) ) as $h ) {
		$args = array( 'action' => $h[1], 'op' => 'start', 'token' => 'x', '_wpnonce' => 'x', Permissions::NONCE_FIELD => 'x' );
		$r    = 'GET' === $h[0] ? $http( 'sm', 'GET', AdminUrl::admin_post( $args ) ) : $http( 'sm', 'POST', AdminUrl::admin_post(), $args );
		if ( 403 !== $r['code'] ) {
			$hbad[] = "sm {$h[1]} {$r['code']}";
		}
	}
	pqbg_t( 'handlers: seller and customer 403 on all 15 admin-post handlers; shop manager 403 on the 4 cost handlers; nothing changed', array() === $hbad && $before === $state(), implode( ', ', $hbad ) );

	// ------------------------------------------------------------------ HTTP: links
	$sec( 'HTTP: every link points to the new addresses' );
	$local = Settings::is_local_url( ScanUrl::base() );
	$idx   = $http( 'admin', 'GET', admin_url( 'index.php' ) );
	pqbg_t( 'the scan base URL warning links to Settings (administrators; this site is local)', ! $local || ( str_contains( $idx['body'], $href( AdminUrl::settings() ) . '>Scan base URL settings</a>' ) ) );
	$summ = $http( 'admin', 'GET', AdminUrl::reports() );
	pqbg_t( 'the Summary\'s alerts link to the reports (low, out, missing codes) at their new place', str_contains( $summ['body'], $href( AdminUrl::reports( array( 'tab' => 'stock', 'state' => 'nocode' ) ) ) ) && str_contains( $summ['body'], $href( AdminUrl::reports( array( 'tab' => 'stock', 'state' => 'low' ) ) ) ) );
	$edit  = $http( 'admin', 'GET', AdminUrl::product_edit( $p_code ) );
	$print = preg_match( '/<a class="button pqbg-print-label" href="([^"]+)"/', $edit['body'], $m ) ? html_entity_decode( $m[1] ) : '';
	$regen = preg_match( '/<a class="button pqbg-regenerate" href="([^"]+)"/', $edit['body'], $m ) ? html_entity_decode( $m[1] ) : '';
	pqbg_t( 'product panel: Print label and Regenerate link to the hidden Products screens, which open (200)', str_starts_with( $print, admin_url( 'edit.php?post_type=product&page=' . AdminUrl::PRINT . '&items=' . $p_code . '&' ) ) && str_starts_with( $regen, admin_url( 'edit.php?post_type=product&page=' . AdminUrl::REGENERATE . '&item=' . $p_code . '&' ) ) && 200 === $http( 'admin', 'GET', $print )['code'] && 200 === $http( 'admin', 'GET', $regen )['code'] );
	$list  = $http( 'admin', 'GET', AdminUrl::products() );
	$bulk  = $http( 'admin', 'GET', AdminUrl::products( array( 'action' => PrintAdmin::BULK_ACTION, 'post' => array( (string) $p_code ), '_wpnonce' => $field( $list['body'], '_wpnonce' ) ) ) );
	pqbg_t( 'products list bulk action "Print QR labels" → the print setup screen', 302 === $bulk['code'] && str_starts_with( $bulk['location'], admin_url( 'edit.php?post_type=product&page=' . AdminUrl::PRINT . '&items=' . $p_code . '&' ) ), $bulk['code'] . ' ' . $bulk['location'] );
	$setup = $http( 'admin', 'GET', $print );
	pqbg_t( 'print setup: Cancel goes back to the Products list', str_contains( $setup['body'], $href( AdminUrl::products() ) . '>Cancel</a>' ) );
	$eod = $http( 'sm', 'GET', AdminUrl::reports( array( 'tab' => 'eod' ) ) );
	$pp  = $http( 'sm', 'GET', $link_of( $eod['body'], ReportPrint::ACTION ) );
	pqbg_t( 'end-of-day print page: "Back to In-store reports" goes to the End of day tab', 200 === $pp['code'] && str_contains( $pp['body'], $href( AdminUrl::reports( array( 'tab' => 'eod', 'range' => 'today' ) ) ) ) );
	$det = $http( 'sm', 'GET', AdminUrl::sale( $f['cash2'] ) );
	pqbg_t( 'sale detail: "Void sale" and "Back to In-store sales" links', str_contains( $det['body'], $href( AdminUrl::sale_void( $f['cash2'] ) ) ) && str_contains( $det['body'], $href( AdminUrl::sales() ) ) );
	$vp = $http( 'sm', 'GET', AdminUrl::sale_void( $f['cash2'] ) );
	$vr = $http( 'sm', 'POST', AdminUrl::admin_post(), array( 'action' => SalesAdmin::VOID_ACTION, 'sale' => (string) $f['cash2'], Permissions::NONCE_FIELD => $field( $vp['body'], Permissions::NONCE_FIELD ), 'reason' => '' ) );
	pqbg_t( 'void handler: its redirect goes back to the void screen (a missing reason; nothing voided)', 303 === $vr['code'] && $vr['location'] === add_query_arg( SalesAdmin::MESSAGE_ARG, 'reason_required', AdminUrl::sale_void( $f['cash2'] ) ) && 'completed' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $S WHERE id = %d", $f['cash2'] ) ), $vr['code'] . ' ' . $vr['location'] );
	$bt = $bodies['sm'][ AdminUrl::BULK_TOOLS ]['body'];
	$gr = $http( 'sm', 'POST', AdminUrl::admin_post(), array( 'action' => ToolsAdmin::GENERATE, 'op' => 'bogus', Permissions::NONCE_FIELD => $field( $bt, Permissions::NONCE_FIELD ) ) );
	pqbg_t( 'Bulk tools handler: an error page links back to Bulk tools → Code tools', 400 === $gr['code'] && str_contains( html_entity_decode( $gr['body'] ), AdminUrl::bulk_tools( ToolsAdmin::TAB_TOOLS ) ), (string) $gr['code'] );
	$dash_a = $bodies['admin'][ AdminUrl::DASHBOARD ]['body'];
	$dash_s = $bodies['sm'][ AdminUrl::DASHBOARD ]['body'];
	$quick  = array( ScanUrl::site_url(), AdminUrl::products(), AdminUrl::bulk_tools( ToolsAdmin::TAB_TOOLS ), AdminUrl::reports( array( 'tab' => 'eod', 'range' => 'today' ) ), AdminUrl::sales( array( 'range' => 'today' ) ), AdminUrl::bulk_tools( ToolsAdmin::TAB_COSTS ), AdminUrl::settings() );
	pqbg_t( 'Dashboard quick links (administrator): scan page, Products (print labels), generate codes, end of day, today\'s sales, import cost prices, Settings', ! array_filter( $quick, static fn( $u ) => ! str_contains( $dash_a, 'class="button" ' . $href( $u ) ) ) );
	pqbg_t( 'Dashboard quick links (shop manager): no Import cost prices and no Settings anywhere on the page', ! str_contains( $dash_s, 'tab=costs' ) && ! str_contains( $dash_s, 'page=pqbg-settings' ) && str_contains( $dash_s, 'class="button" ' . $href( AdminUrl::bulk_tools( ToolsAdmin::TAB_TOOLS ) ) ) );
	$csvs = array(
		'sales'   => $http( 'sm', 'GET', $link_of( $bodies['sm'][ AdminUrl::SALES ]['body'], SalesExport::ACTION ) ),
		'report'  => $http( 'sm', 'GET', $link_of( $http( 'sm', 'GET', AdminUrl::reports( array( 'tab' => 'products' ) ) )['body'], ReportsExport::ACTION ) ),
		'codes'   => $http( 'sm', 'GET', AdminUrl::admin_post( array( 'action' => CodesExport::ACTION, '_wpnonce' => $field( $bt, '_wpnonce' ), 'code_status' => 'all', 'missing' => '1' ) ) ),
		'costtpl' => $http( 'admin', 'GET', $link_of( $http( 'admin', 'GET', AdminUrl::bulk_tools( ToolsAdmin::TAB_COSTS ) )['body'], ToolsAdmin::TEMPLATE ) ),
	);
	pqbg_t( 'no CSV contains an admin URL (sales, report, codes, cost template)', ! array_filter( $csvs, static fn( $r ) => 200 !== $r['code'] || str_contains( $r['body'], 'wp-admin' ) ), wp_json_encode( array_map( static fn( $r ) => $r['code'], $csvs ) ) );

	// ------------------------------------------------------------------ HTTP: Dashboard
	$sec( 'HTTP: the Dashboard per role' );
	pqbg_t( 'administrator: today\'s revenue, sales and profit as in In-store reports → Summary (today)', str_contains( $dash_a, esc_html( SalePresenter::money( $d1['now']['revenue'] ) ) ) && str_contains( $summ['body'], esc_html( SalePresenter::money( $d1['now']['revenue'] ) ) ) && str_contains( $dash_a, 'Gross profit' ) && str_contains( $dash_a, esc_html( SalePresenter::money( $d1['now']['profit'] ) ) ) && str_contains( $dash_a, 'with an unknown cost' ) );
	pqbg_t( 'per payment method and voided today', str_contains( $dash_a, 'pqbg-dashboard__methods' ) && str_contains( $dash_a, esc_html( SalePresenter::money( $d1['methods']['upi']['revenue'] ) ) ) && str_contains( $dash_a, 'Voided today: ' . number_format_i18n( $d1['voided'] ) ) );
	$wrap_s = (string) substr( $dash_s, (int) strpos( $dash_s, '<div class="wrap' ) );
	pqbg_t( 'shop manager: the same revenue, but no profit, margin, cost wording or cost-derived value', str_contains( $dash_s, esc_html( SalePresenter::money( $d1['now']['revenue'] ) ) ) && ! str_contains( $wrap_s, 'Gross profit' ) && ! str_contains( $wrap_s, '>Margin<' ) && ! preg_match( '/\bcost\b/i', wp_strip_all_tags( $wrap_s ) ) && ! str_contains( $wrap_s, esc_html( SalePresenter::money( $d1['now']['profit'] ) ) ) );
	pqbg_t( '"Published products without a code" with the 9B count and a link to Bulk tools → Code tools; low and out of stock link to the reports', str_contains( $dash_a, '<dt>Published products without a code</dt><dd><strong>' . number_format_i18n( $d1['nocode'] ) . '</strong> <a ' . $href( AdminUrl::bulk_tools( ToolsAdmin::TAB_TOOLS ) ) . '>Generate missing codes</a>' ) && str_contains( $dash_a, 'also counts drafts' ) && str_contains( $dash_a, $href( AdminUrl::reports( array( 'tab' => 'stock', 'state' => 'low' ) ) ) ) );
	pqbg_t( 'the Summary\'s alert shows the same missing-code count', str_contains( $summ['body'], number_format_i18n( $d1['nocode'] ) . ' active item' ) );
	pqbg_t( 'attention: the local-address warning for both roles (they print labels); the Settings link only for the administrator', ! $local || ( str_contains( $dash_a, 'Do not print labels until the production URL is set.' ) && str_contains( $dash_s, 'Do not print labels until the production URL is set.' ) && str_contains( $block( $dash_a, 'pqbg-dashboard__attention', '</section>' ), $href( AdminUrl::settings() ) ) && ! str_contains( $block( $dash_s, 'pqbg-dashboard__attention', '</section>' ), 'pqbg-settings' ) ) );
	BulkLog::add( BulkLog::TOOL_GENERATE, array( 'created' => 1 ), $A );
	BulkLog::add( BulkLog::TOOL_COST_IMPORT, array( 'applied' => 1 ), $A );
	$run = BulkGenerator::start( BulkGenerator::STATUSES, $A );
	$ra  = $http( 'admin', 'GET', AdminUrl::dashboard() );
	$rs  = $http( 'sm', 'GET', AdminUrl::dashboard() );
	pqbg_t( 'a code-generation run in progress appears under "Needs attention" with a link to continue in Bulk tools', ! is_wp_error( $run ) && str_contains( $ra['body'], 'Code generation is running.' ) && str_contains( $block( $ra['body'], 'pqbg-dashboard__attention', '</section>' ), $href( AdminUrl::bulk_tools( ToolsAdmin::TAB_TOOLS ) ) ) );
	pqbg_t( 'recent bulk runs: the cost import entry only for the administrator', str_contains( $ra['body'], esc_html( BulkLog::label( BulkLog::TOOL_COST_IMPORT ) ) ) && ! str_contains( $rs['body'], esc_html( BulkLog::label( BulkLog::TOOL_COST_IMPORT ) ) ) && str_contains( $rs['body'], esc_html( BulkLog::label( BulkLog::TOOL_GENERATE ) ) ) );
	if ( ! is_wp_error( $run ) ) {
		BulkGenerator::stop( (string) $run['id'] );
	}
	pqbg_t( 'a stopped run is shown as stopped', str_contains( $http( 'admin', 'GET', AdminUrl::dashboard() )['body'], 'Code generation was stopped before it finished.' ) );
	if ( ! is_wp_error( $run ) ) {
		BulkGenerator::dismiss( (string) $run['id'] );
	}

	$sec( 'GET and HEAD never write' );
	$before = $state();
	foreach ( array( 'admin', 'sm' ) as $who ) {
		foreach ( $all_pages as $url ) {
			$http( $who, 'GET', $url );
			$http( $who, 'HEAD', $url );
		}
		$http( $who, 'GET', $old( array( 'tab' => 'tools' ) ) );
	}
	pqbg_t( 'every page and old address (GET and HEAD, both roles) changes nothing (sales, costs, stock, posts, codes, options)', $before === $state() );

	// ------------------------------------------------------------------ accessibility
	$sec( 'accessibility and narrow screens' );
	$a11y = array();
	foreach ( $bodies['admin'] as $slug => $r ) {
		if ( 1 !== substr_count( $r['body'], '<h1' ) ) {
			$a11y[] = "$slug: " . substr_count( $r['body'], '<h1' ) . ' h1';
		}
		if ( ! str_contains( $r['body'], '<nav class="nav-tab-wrapper wp-clearfix pqbg-plugin-nav" aria-label="QR &amp; Barcodes">' ) ) {
			$a11y[] = "$slug: no labelled tab row";
		}
		if ( ! str_contains( $r['body'], 'pqbg-menu.css' ) ) {
			$a11y[] = "$slug: no pqbg-menu.css";
		}
	}
	pqbg_t( 'every plugin page: exactly one h1, the labelled tab row, the shared stylesheet', array() === $a11y, implode( ', ', $a11y ) );
	$wrap_a = (string) substr( $dash_a, (int) strpos( $dash_a, '<div class="wrap' ) );
	preg_match_all( '/<section class="[^"]*" aria-labelledby="([^"]+)"><h2 id="([^"]+)">/', $wrap_a, $sm_ );
	preg_match_all( '/<a [^>]*>\s*<\/a>/', $wrap_a, $empty_links );
	pqbg_t( 'Dashboard: each section is labelled by its h2 (attention, products, runs, setup, links); figures are a description list; no empty link', count( $sm_[1] ) >= 5 && $sm_[1] === $sm_[2] && str_contains( $wrap_a, '<dl class="pqbg-cards">' ) && str_contains( $wrap_a, '<h2 id="pqbg-dash-today">' ) && array() === $empty_links[0] );
	pqbg_t( 'Dashboard assets: pqbg-reports.css and pqbg-dashboard.css on the Dashboard only', str_contains( $dash_a, 'pqbg-dashboard.css' ) && ! str_contains( $bodies['admin'][ AdminUrl::SALES ]['body'], 'pqbg-dashboard.css' ) );
	$css = (string) file_get_contents( PQBG_PLUGIN_DIR . 'assets/pqbg-menu.css' ) . file_get_contents( PQBG_PLUGIN_DIR . 'assets/pqbg-dashboard.css' );
	pqbg_t( 'the new CSS never removes the focus outline, and has narrow-screen rules (782 px: one column, wrapping tabs, full-width 44 px buttons)', ! preg_match( '/outline\s*:\s*(none|0)/i', $css ) && str_contains( $css, '@media (max-width: 782px)' ) && str_contains( $css, 'grid-template-columns: 1fr' ) && str_contains( $css, 'flex-wrap: wrap' ) && str_contains( $css, 'min-height: 44px' ) );

	// ------------------------------------------------------------------ performance
	$sec( 'performance: the Dashboard at 5,000 sales (50,000 with PQBG_STRESS=1) and 1,000 sellable items' );
	$t0    = microtime( true );
	$items = array();
	for ( $i = 0; $i < 300; $i++ ) {
		$items[] = array( $make_simple( $A, array( 'manage_stock' => true, 'stock_quantity' => $i % 7, 'regular_price' => (string) ( 300 + $i ) ) ), 0 );
		if ( 0 === $i % 50 ) {
			$sec( 'performance: building items' );
		}
	}
	for ( $i = 0; $i < 70; $i++ ) {
		list( $pp_, $vv ) = $make_variable( $A, array_fill( 0, 10, array( 'manage_stock' => true, 'stock_quantity' => 3, 'regular_price' => '499' ) ) );
		foreach ( $vv as $v ) {
			$items[] = array( $pp_, $v );
		}
		wp_cache_flush();
	}
	pqbg_t( '1,000 sellable items created (300 simple, 70 × 10 variations)', 1000 === count( $items ), sprintf( '%.1f s', microtime( true ) - $t0 ) );
	printf( "   (removed %d background jobs of the test products before timing)\n", pqbg_test_as_cleanup( $as_mark ) );
	$sellers = array( $SE, $SM, 900001, 900002, 900003 );
	$fill    = static function ( int $n ) use ( $wpdb, $S, $SM, $PERF_NOTE, $sellers, $items ): int {
		mt_srand( 10 );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $S WHERE note = %s", $PERF_NOTE ) );
		$methods = array( 'cash', 'cash', 'cash', 'upi', 'upi', 'card', 'other', '' );
		$vals    = array();
		$now_ts  = time();
		for ( $i = 0; $i < $n; $i++ ) {
			$r      = mt_rand( 1, 100 );
			$status = $r <= 90 ? 'completed' : ( $r <= 96 ? 'voided' : 'failed' );
			$q      = mt_rand( 1, 3 );
			$p      = mt_rand( 300, 5000 );
			$it     = $items[ mt_rand( 0, 999 ) ];
			$c      = mt_rand( 1, 10 ) <= 7 ? number_format( $p * 0.6, 2, '.', '' ) : '';
			$vals[] = $wpdb->prepare( '(%s, %d, %d, %d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)', wp_generate_uuid4(), $it[0], $it[1], $sellers[ mt_rand( 0, 4 ) ], $q, $p, $p * $q, 'INR', 'Perf item ' . $it[0], $status, gmdate( 'Y-m-d H:i:s', $now_ts - mt_rand( 0, 89 * 86400 ) ), $methods[ mt_rand( 0, 7 ) ], $c, 'Perf Seller', $PERF_NOTE );
			if ( 1000 === count( $vals ) || $i === $n - 1 ) {
				$wpdb->query( "INSERT INTO $S (request_id, product_id, variation_id, seller_id, quantity, unit_price, line_total, currency, product_name, status, created_at_gmt, payment_method, unit_cost, seller_name, note) VALUES " . implode( ',', $vals ) );
				$vals = array();
			}
		}
		$wpdb->query( $wpdb->prepare( "UPDATE $S SET payment_method = NULLIF(payment_method, ''), unit_cost = NULLIF(unit_cost, 0), voided_by = IF(status = 'voided', %d, NULL), voided_at_gmt = IF(status = 'voided', created_at_gmt, NULL), void_reason = IF(status = 'voided', 'perf', NULL), failure_code = IF(status = 'failed', 'sold_online', NULL) WHERE note = %s", $SM, $PERF_NOTE ) );
		$wpdb->query( "ANALYZE TABLE $S" );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $S WHERE note = %s", $PERF_NOTE ) );
	};
	$med = static function ( string $url ) use ( $http, $median ): float {
		$t = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$t[] = $http( 'admin', 'GET', $url )['time'] * 1000;
		}
		return $median( $t );
	};
	$timing = static function ( string $volume ) use ( $best_ms, $med ): array {
		$in    = $best_ms( static fn() => array( DashboardAdmin::data( true ), DashboardAdmin::attention( true, true ) ) );
		$empty = $med( AdminUrl::sales( array( 'range' => 'custom', 'from' => '2000-01-01', 'to' => '2000-01-01' ) ) );
		$page  = $med( AdminUrl::dashboard() );
		printf( "   TIMING Dashboard %s: in-process %.1f ms (best of 3); HTTP %.1f ms (median of 3), %+.0f ms over an empty wp-admin page (%.1f ms)\n", $volume, $in, $page, $page - $empty, $empty );
		return array( $in, $page - $empty );
	};
	$t0 = microtime( true );
	pqbg_t( 'realistic volume: 5,000 synthetic sales over 90 days', 5000 === $fill( 5000 ), sprintf( '%.1f s', microtime( true ) - $t0 ) );
	pqbg_t( 'reconciliation at volume: the Dashboard still equals Summary (today) and the Phase 9A totals', DashboardAdmin::data( true )['now'] === ReportsAdmin::dashboard_data( ReportPeriod::resolve( 'today' ), true )['now'] && abs( (float) SalesQuery::totals( ReportPeriod::where_filters( ReportPeriod::resolve( 'today' ) ) )['all']['revenue'] - (float) DashboardAdmin::data( true )['now']['revenue'] ) < 0.005 );
	$real = $timing( '5,000 sales' );
	pqbg_t( 'realistic volume: the Dashboard under 1 s (in-process, and added to an empty wp-admin page over HTTP)', $real[0] < 1000 && $real[1] < 1000, sprintf( '%.0f ms / +%.0f ms', $real[0], $real[1] ) );
	if ( $STRESS ) {
		$t0 = microtime( true );
		pqbg_t( 'stress volume: 50,000 synthetic sales over 90 days', 50000 === $fill( 50000 ), sprintf( '%.1f s', microtime( true ) - $t0 ) );
		$big = $timing( '50,000 sales' );
		pqbg_t( 'stress volume: the Dashboard under 2 s (in-process, and added over HTTP)', $big[0] < 2000 && $big[1] < 2000, sprintf( '%.0f ms / +%.0f ms', $big[0], $big[1] ) );
	} else {
		echo "   (the 50,000-sale stress check is opt-in: PQBG_STRESS=1; it runs in Phase 11 and before launch)\n";
	}

	// ------------------------------------------------------------------ scope
	$sec( 'scope' );
	$code_only = static function ( string $file ): string {
		$code = '';
		foreach ( token_get_all( (string) file_get_contents( $file ) ) as $t ) {
			$code .= is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t;
		}
		return $code;
	};
	$php = array_merge( glob( PQBG_PLUGIN_DIR . 'includes/*.php' ), glob( PQBG_PLUGIN_DIR . 'templates/*.php' ) );
	$src = array();
	foreach ( $php as $file ) {
		$src[ basename( $file ) ] = $code_only( $file );
	}
	$others = array_diff_key( $src, array( 'AdminUrl.php' => 1 ) );
	$hits   = array();
	foreach ( $others as $name => $code ) {
		$code = preg_replace( '/\b(?:add|remove)_submenu_page\s*\([^;]*;/', '', $code ); // The hidden screens' parent slug 'edit.php?post_type=product' is a menu slug, not a link.
		if ( preg_match( '/\badmin_url\s*\(|\bmenu_page_url\s*\(|\bself_admin_url\s*\(|\bnetwork_admin_url\s*\(|page=pqbg|[\'"](?:admin-post|options)\.php[\'"?]|(?:admin|edit|post)\.php\?/', $code, $m ) ) {
			$hits[] = "$name: {$m[0]}";
		}
	}
	pqbg_t( 'D7: outside AdminUrl no admin_url(), menu_page_url(), "admin.php?page", "page=pqbg" or hand-written wp-admin file names (links, form actions and redirect targets all come from AdminUrl)', array() === $hits, implode( ', ', $hits ) );
	$slugs = array();
	foreach ( $others as $name => $code ) {
		$code = preg_replace( '/wp_enqueue_(?:style|script)\s*\([^;]*;/', '', $code );             // Asset handles share names with pages.
		$code = str_replace( "'plural'   => 'pqbg-sales'", '', $code );                             // The list table's plural name (a CSS class).
		if ( preg_match( '/[\'"]pqbg-(?:dashboard|sales|reports|bulk-tools|settings|print|regenerate)[\'"]/', $code, $m ) ) {
			$slugs[] = "$name: {$m[0]}";
		}
	}
	pqbg_t( 'page slugs are written only in AdminUrl (every other class uses its constants)', array() === $slugs, implode( ', ', $slugs ) );
	$ids_hits = array();
	foreach ( array_merge( $php, glob( PQBG_PLUGIN_DIR . 'assets/*.{css,js}', GLOB_BRACE ) ) as $file ) {
		$text = str_ends_with( $file, '.php' ) ? $code_only( $file ) : (string) file_get_contents( $file );
		if ( preg_match( '/woocommerce_page_|_page_pqbg|toplevel_page_/', $text, $m ) ) {
			$ids_hits[] = basename( $file ) . ": {$m[0]}";
		}
	}
	pqbg_t( 'no hard-coded screen IDs (woocommerce_page_…, …_page_pqbg…, toplevel_page_…) anywhere in PHP, CSS or JS; pages are recognised by the stored hook suffix', array() === $ids_hits && str_contains( $src['AdminMenu.php'], 'self::$hooks[ $slug ] = $hook;' ), implode( ', ', $ids_hits ) );
	$menus = array();
	foreach ( $src as $name => $code ) {
		preg_match_all( '/\b(add_menu_page|add_submenu_page)\s*\(\s*([^,]+),/', $code, $mm, PREG_SET_ORDER );
		foreach ( $mm as $x ) {
			$menus[] = $name . ' ' . $x[1] . ' ' . trim( $x[2] );
		}
	}
	sort( $menus );
	pqbg_t( 'menu registration: the top-level menu and its sub-items only in AdminMenu; the two hidden Products screens as before; nothing under WooCommerce', array( 'AdminMenu.php add_menu_page $title', 'AdminMenu.php add_submenu_page AdminUrl::DASHBOARD', 'AdminProductPanel.php add_submenu_page \'edit.php?post_type=product\'', 'PrintAdmin.php add_submenu_page \'edit.php?post_type=product\'' ) === $menus, implode( ' | ', $menus ) );
	$redirects = array();
	foreach ( $src as $name => $code ) {
		if ( preg_match( '/\bwp_redirect\s*\(/', $code ) ) {
			$redirects[] = "$name: wp_redirect";
		}
		if ( preg_match( '/\bwp_safe_redirect\s*\(/', $code ) ) {
			$redirects[] = $name;
		}
	}
	sort( $redirects );
	pqbg_t( 'redirects: only wp_safe_redirect, only in the known places (their targets come from AdminUrl or, for the scan page, ScanUrl; tested above)', array( 'AdminActions.php', 'AdminMenu.php', 'PrintAdmin.php', 'SalesAdmin.php', 'ScanRoute.php', 'ToolsAdmin.php' ) === $redirects, implode( ', ', $redirects ) );
	$new = $src['AdminUrl.php'] . $src['AdminMenu.php'] . $src['DashboardAdmin.php'];
	pqbg_t( 'the new classes never write and add no AJAX/REST/nopriv handler, shortcode or rewrite rule', ! preg_match( '/\$wpdb|update_option|add_option|delete_option|update_post_meta|update_user_meta|set_transient|->save\(|admin_post_|wp_ajax_|register_rest_route|add_shortcode|add_rewrite/', $new ) );
	pqbg_t( 'hooks: AdminMenu adds admin_menu (pages; old addresses, last), admin_enqueue_scripts and each page\'s load- hook; DashboardAdmin only admin_enqueue_scripts', 4 === preg_match_all( '/add_action\(/', $src['AdminMenu.php'] ) && str_contains( $src['AdminMenu.php'], "add_action( 'admin_menu', array( __CLASS__, 'redirect_old_url' ), PHP_INT_MAX );" ) && 1 === preg_match_all( '/add_action\(/', $src['DashboardAdmin.php'] ) && ! preg_match( '/add_filter\(/', $new ) );
	pqbg_t( 'the menu is registered only for admin requests, after the page classes', (bool) preg_match( '/if \( is_admin\(\) \) \{.*ToolsAdmin::register\(\);.*AdminMenu::register\(\);\s*DashboardAdmin::register\(\);.*\}/s', $src['Plugin.php'] ) );
	pqbg_t( 'the sale path is untouched (the new classes never reference it)', ! preg_match( '/SaleService|SaleRepository|SaleRequest|StockLock|wc_update_product_stock/', $new ) );
	pqbg_t( 'costs on the Dashboard only behind pqbg_view_costs (no CostPrice or cost meta)', ! preg_match( '/CostPrice|_pqbg_cost_price/', $src['DashboardAdmin.php'] ) && str_contains( $src['DashboardAdmin.php'], '$costs    = Permissions::can_view_costs();' ) );
	pqbg_t( 'direct HTTP to the new files: empty output', '' === $http( 'anon', 'GET', PQBG_PLUGIN_URL . 'includes/AdminUrl.php' )['body'] && '' === $http( 'anon', 'GET', PQBG_PLUGIN_URL . 'includes/AdminMenu.php' )['body'] && '' === $http( 'anon', 'GET', PQBG_PLUGIN_URL . 'includes/DashboardAdmin.php' )['body'] );
} catch ( Throwable $e ) {
	pqbg_t( 'suite ran without an exception', false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
	// ------------------------------------------------------------------ cleanup
	pqbg_section( 'cleanup' );
	wp_set_current_user( 0 );
	$handles = array();
	$wpdb->query( $wpdb->prepare( "DELETE FROM $S WHERE id > %d OR note IN (%s, %s)", $start_s, $NOTE, $PERF_NOTE ) );
	$wpdb->query( 'ALTER TABLE ' . $S . ' AUTO_INCREMENT = ' . ( $start_s + 1 ) );
	$posts = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d ORDER BY post_type = 'product_variation' DESC, ID DESC", $start_post ) );
	foreach ( $posts as $pid ) {
		$p = in_array( get_post_type( (int) $pid ), array( 'product', 'product_variation' ), true ) ? wc_get_product( (int) $pid ) : null;
		if ( $p ) {
			$p->delete( true );
		} else {
			wp_delete_post( (int) $pid, true );
		}
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM $PM WHERE post_id > %d", $start_post ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $C WHERE id > %d", $start_c ) );
	$wpdb->query( 'ALTER TABLE ' . $C . ' AUTO_INCREMENT = ' . ( $start_c + 1 ) );
	foreach ( $user_ids as $uid ) {
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d", $uid ) ) as $own ) {
			wp_delete_post( (int) $own, true );
		}
		wp_delete_user( $uid );
	}
	foreach ( array_diff( $svg_rows(), $svg_before ) as $name ) {
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
	}
	foreach ( $saved as $name => $raw ) {
		if ( null === $raw ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		} else {
			$wpdb->replace( $wpdb->options, array( 'option_name' => $name, 'option_value' => $raw['option_value'], 'autoload' => $raw['autoload'] ) );
		}
	}
	wp_cache_flush();
	pqbg_test_as_cleanup( $as_mark );
	pqbg_t( 'cleanup: sales, codes and posts back to the start', $start_s === $max( $S, 'id' ) && $start_c === $max( $C, 'id' ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID > %d", $start_post ) ) );
	pqbg_t( 'cleanup: no test users or cost meta left', $base_user === (int) count_users()['total_users'] && $base_cost === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $PM WHERE meta_key = %s", CostPrice::META_KEY ) ) );
	pqbg_t( 'cleanup: settings, run state, log and render-cache index byte-identical (or absent); no render-cache entries left', ! array_filter( $saved, static fn( $raw, $name ) => $raw !== $raw_option( $name ), ARRAY_FILTER_USE_BOTH ) && array() === array_diff( $svg_rows(), $svg_before ) );
	pqbg_t( 'cleanup: the posts AUTO_INCREMENT only moved by the posts this suite created (no jump)', $posts_ai() - $start_ai < 5000, $start_ai . ' → ' . $posts_ai() );
	pqbg_test_as_check( $as_mark );
}

pqbg_test_done();
