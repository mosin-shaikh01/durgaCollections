<?php
/**
 * Phase 9B suite: in-store reports and the owner dashboard.
 *
 * Every counting rule against hand-computed values on small fixed datasets
 * (completed vs voided vs failed, unknown costs, a deleted product and a deleted
 * seller, a product in two categories and a sub-category); site-timezone
 * boundaries (18:29:59 / 18:30:00 UTC) for every grouping, the peak grid and the
 * end of day; comparison periods (month lengths, leap year, same point in time,
 * week start); each report reconciling with the Phase 9A totals; the peak grid and
 * busiest summary; the end of day per method and per seller with "net collected"
 * (refunds of earlier sales, a same-day void never subtracted twice); stock values
 * and thresholds (own, parent and global), shared stock (one price / mixed), below
 * zero; dead stock (30/60/90/never, new items, online orders by status from real
 * WooCommerce orders); missing codes; schema v4 (void_restock) and the migration;
 * permissions over real HTTP for every tab, CSV and the print page (administrator,
 * shop manager, seller, customer, logged out) with cost/profit absent for the shop
 * manager; CSV rules; escaping (HTML and SVG); security headers; GET/HEAD never
 * write; the 5,000-row timings (50,000 with PQBG_STRESS=1) with EXPLAIN; scope; cleanup.
 *
 * Fixture sale rows are inserted directly into pqbg_sales (marked in `note`);
 * real sales go through SaleService. Everything created is removed at the end.
 *
 *   php tests/phase9b-reports.php
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

use ProductQrBarcode\{AdminMenu, CodeRepository, CostPrice, Install, PaymentMethods, Permissions, Plugin, ReportChart, ReportData, ReportPeriod, ReportPrint, ReportsAdmin, ReportsExport, ReportsQuery, SaleRepository, SaleService, SalesExport, SalesQuery, ScanRoute, Schema, StockQuery};

global $wpdb;

$C          = Schema::codes_table();
$S          = Schema::sales_table();
$PM         = $wpdb->postmeta;
$NOTE       = 'pqbg-9b-fixture';
$PERF_NOTE  = 'pqbg-9b-perf';
$max        = static fn( string $table, string $col ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX($col), 0) FROM $table" );
$start_c    = $max( $C, 'id' );
$start_s    = $max( $S, 'id' );
$as_mark    = pqbg_test_as_mark(); // Action Scheduler cleanup, see bootstrap.php.
$start_post = $max( $wpdb->posts, 'ID' );
$start_ord  = $max( $wpdb->prefix . 'wc_orders', 'id' );
$start_oi   = $max( $wpdb->prefix . 'woocommerce_order_items', 'order_item_id' );
$start_term = $max( $wpdb->terms, 'term_id' );
$start_cmt  = $max( $wpdb->comments, 'comment_ID' );
$posts_ai   = static fn() => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->posts ) );
$start_ai   = $posts_ai();
$base_cost  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $PM WHERE meta_key = %s", CostPrice::META_KEY ) );
$base_user  = (int) count_users()['total_users'];
$raw_option = static fn( string $name ) => $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
$saved      = array();
foreach ( array( Plugin::SETTINGS_OPTION, Install::DB_VERSION_OPTION, 'start_of_week', 'woocommerce_notify_low_stock_amount', 'woocommerce_notify_no_stock_amount', 'product_cat_children' ) as $name ) {
	$saved[ $name ] = $raw_option( $name );
}
$user_ids   = array();
$pw         = array();
$orders     = array();
$mig_prefix = $wpdb->prefix . 'pqbg9bm_';

add_filter( 'pre_wp_mail', '__return_false' ); // Local mail is not configured; a failing mail() takes about 2 s.
add_filter(
	'wp_die_handler',
	static fn() => static function ( $message ) {
		throw new RuntimeException( 'wp_die: ' . wp_strip_all_tags( is_wp_error( $message ) ? $message->get_error_message() : (string) $message ) );
	}
);

// Leftovers of an interrupted earlier run (marked rows only).
$wpdb->query( $wpdb->prepare( "DELETE FROM $S WHERE note IN (%s, %s)", $NOTE, $PERF_NOTE ) );

$code_of = static function ( int $id ): string {
	$row = CodeRepository::find_active_for_product( $id );
	return is_array( $row ) ? (string) $row['code'] : '';
};
/** UTC "Y-m-d H:i:s" of a site-timezone (Asia/Kolkata) wall-clock time. */
$ist = static fn( string $local ) => ( new DateTimeImmutable( $local, wp_timezone() ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
/** Unix time of a site-timezone wall-clock time. */
$ts = static fn( string $local ) => ( new DateTimeImmutable( $local, wp_timezone() ) )->getTimestamp();
/** Inserts a fixture sale row (test data, marked). */
$put = static function ( array $cols ) use ( $wpdb, $S, $NOTE ): int {
	$data = array_merge(
		array(
			'request_id'     => wp_generate_uuid4(),
			'product_id'     => 1,
			'variation_id'   => 0,
			'seller_id'      => 1,
			'quantity'       => 1,
			'unit_price'     => '100',
			'line_total'     => '100',
			'currency'       => 'INR',
			'product_name'   => 'Fixture',
			'status'         => 'completed',
			'created_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
			'payment_method' => 'cash',
			'note'           => $NOTE,
		),
		$cols
	);
	if ( ! isset( $cols['unit_price'] ) && isset( $cols['line_total'] ) ) {
		$data['unit_price'] = (string) ( (float) $cols['line_total'] / (int) $data['quantity'] );
	}
	$wpdb->insert( $S, $data );
	return (int) $wpdb->insert_id;
};
$F       = static fn( string $from, string $to ) => ReportPeriod::where_filters( ReportPeriod::resolve( 'custom', $from, $to ) );
$ctx     = static fn( string $from, string $to, bool $costs, array $extra = array() ) => array_merge( array( 'period' => ReportPeriod::resolve( 'custom', $from, $to ), 'costs' => $costs ), $extra );
$by      = static fn( array $rows, string $key ) => array_column( $rows, null, $key );
$median  = static function ( array $v ): float {
	sort( $v );
	return (float) $v[ intdiv( count( $v ), 2 ) ];
};
$time_ms = static function ( callable $fn, int $n = 3 ): float {
	$best = INF;
	for ( $i = 0; $i < $n; $i++ ) {
		wp_cache_flush();
		$t    = hrtime( true );
		$fn();
		$best = min( $best, ( hrtime( true ) - $t ) / 1e6 );
	}
	return $best;
};
$csv_rows = static function ( string $csv ): array {
	$fh = fopen( 'php://temp', 'w+' );
	fwrite( $fh, $csv );
	rewind( $fh );
	$out = array();
	while ( false !== ( $line = fgetcsv( $fh, 0, ',', '"', '' ) ) ) {
		$out[] = $line;
	}
	fclose( $fh );
	return $out;
};
$csv_of = static function ( array $data ): string {
	$fh = fopen( 'php://temp', 'w+' );
	ReportsExport::write( $fh, $data );
	rewind( $fh );
	$out = (string) stream_get_contents( $fh );
	fclose( $fh );
	return $out;
};
/** Checksum of everything a GET must not change. */
$state = static function () use ( $wpdb, $S ): string {
	return md5(
		implode(
			'|',
			array(
				(string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, status, COALESCE(payment_method, ''), COALESCE(unit_cost, ''), COALESCE(voided_by, ''), COALESCE(void_restock, ''), COALESCE(stock_after, '')))), 0)) FROM $S" ),
				(string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT(post_id, meta_key, meta_value))), 0)) FROM {$wpdb->postmeta} WHERE meta_key IN ('_pqbg_cost_price', '_stock', '_stock_status', '_price', '_manage_stock')" ),
				(string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', MAX(ID)) FROM {$wpdb->posts}" ),
				(string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(id), 0)) FROM {$wpdb->prefix}pqbg_codes" ),
				(string) $wpdb->get_var( "SELECT GROUP_CONCAT(CONCAT(option_name, '=', MD5(option_value)) ORDER BY option_name) FROM {$wpdb->options} WHERE option_name LIKE 'pqbg%'" ),
				(string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders" ),
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
$rep_url = static fn( array $args = array() ) => add_query_arg( array_map( 'rawurlencode', array_merge( array( 'page' => 'pqbg-reports' ), $args ) ), admin_url( 'admin.php' ) );
$link_of = static function ( string $body, string $action ): string {
	return preg_match( '/href="([^"]*action=' . preg_quote( $action, '/' ) . '[^"]*)"/', $body, $m ) ? html_entity_decode( $m[1] ) : '';
};

/** A product (codes are assigned on save because $as manages codes). */
$make_simple = static function ( int $as, array $props = array(), array $cats = array() ): int {
	wp_set_current_user( $as );
	$p = new WC_Product_Simple();
	$p->set_name( 'PQBG 9B simple ' . wp_generate_password( 6, false ) );
	$p->set_status( 'publish' );
	$p->set_regular_price( '100' );
	$p->set_manage_stock( false );
	$p->set_props( $props );
	if ( array() !== $cats ) {
		$p->set_category_ids( $cats );
	}
	$id = (int) $p->save();
	wp_set_current_user( 0 );
	return $id;
};
/** A variable product; $vars: list of variation props. */
$make_variable = static function ( int $as, array $props, array $vars, array $cats = array() ): array {
	wp_set_current_user( $as );
	$opts = array_map( static fn( $i ) => 'S' . $i, range( 1, count( $vars ) ) );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( $opts );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$p = new WC_Product_Variable();
	$p->set_name( 'PQBG 9B variable ' . wp_generate_password( 6, false ) );
	$p->set_status( 'publish' );
	$p->set_attributes( array( $attr ) );
	$p->set_props( $props );
	if ( array() !== $cats ) {
		$p->set_category_ids( $cats );
	}
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
$backdate = static function ( int $id, int $days ) use ( $wpdb ): void {
	$d = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	$wpdb->update( $wpdb->posts, array( 'post_date_gmt' => $d, 'post_date' => get_date_from_gmt( $d ) ), array( 'ID' => $id ) );
	clean_post_cache( $id );
};

try {
	// ------------------------------------------------------------------ users
	$make_user = static function ( string $role, string $display = '' ) use ( &$user_ids, &$pw ): int {
		$login = 'pqbg9b_' . $role . '_' . wp_generate_password( 5, false, false );
		$pass  = wp_generate_password( 20, true, false );
		$id    = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => $pass,
				'user_email'   => $login . '@example.invalid',
				'role'         => $role,
				'display_name' => '' === $display ? $login : $display,
			)
		);
		$user_ids[ $login ] = (int) $id;
		$pw[ $login ]       = $pass;
		return (int) $id;
	};
	$A    = $make_user( 'administrator', 'PQBG Admin' );
	$SM   = $make_user( 'shop_manager', 'PQBG Manager' );
	$SE   = $make_user( 'pqbg_seller', 'Asha One' );
	$SE2  = $make_user( 'pqbg_seller', 'Gone Seller' );
	$SE3  = $make_user( 'pqbg_seller', 'Ravi Three' );
	$CU   = $make_user( 'customer', 'PQBG Customer' );
	$logins = array_flip( $user_ids );
	foreach ( array( 'admin' => $A, 'sm' => $SM, 'seller' => $SE3, 'customer' => $CU ) as $who => $id ) {
		$login( $who, $logins[ $id ], $pw[ $logins[ $id ] ] );
	}

	// ------------------------------------------------------------------ schema v4
	pqbg_section( 'schema v4: void_restock and migrate_4' );
	pqbg_t( 'DB_VERSION is 4, migrations end with migrate_4, the site is migrated', 4 === Install::DB_VERSION && array( 1, 2, 3, 4 ) === array_keys( Install::migrations() ) && 4 === Install::stored_version() );
	$full = $wpdb->get_results( "SHOW FULL COLUMNS FROM $S", ARRAY_A );
	$cols = array_column( $full, 'Field' );
	$type = array_column( $full, 'Type', 'Field' );
	$nul  = array_column( $full, 'Null', 'Field' );
	pqbg_t( 'void_restock tinyint(1) NULL, last column', 'void_restock' === end( $cols ) && 'tinyint(1)' === $type['void_restock'] && 'YES' === $nul['void_restock'] );
	$real_prefix  = $wpdb->prefix;
	$wpdb->prefix = $mig_prefix;
	try {
		$v4_sql = Schema::statements();
		$v3_sql = array_map( static fn( $sql ) => str_replace( "void_restock tinyint(1) NULL DEFAULT NULL,\n", '', $sql ), $v4_sql );
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $v3_sql );
		$mS    = Schema::sales_table();
		$wpdb->insert( $mS, array( 'request_id' => wp_generate_uuid4(), 'product_id' => 1, 'seller_id' => 1, 'unit_price' => '10', 'line_total' => '10', 'currency' => 'INR', 'product_name' => 'v3 row', 'created_at_gmt' => '2026-01-01 00:00:00', 'status' => 'voided', 'void_reason' => 'x', 'voided_by' => 1 ) );
		$v3row = $wpdb->get_row( "SELECT * FROM $mS", ARRAY_A );
		pqbg_t( 'migration: v3 fixture on a temporary prefix (30 sales columns, 1 voided row)', str_starts_with( $mS, $mig_prefix ) && 30 === count( $wpdb->get_col( "SHOW COLUMNS FROM $mS" ) ) && is_array( $v3row ) );
		$res   = Install::migrate_4();
		$v4row = $wpdb->get_row( "SELECT * FROM $mS", ARRAY_A );
		pqbg_t( 'migration: v3 → v4 adds void_restock; the old void keeps NULL ("not recorded"), nothing else changes', true === $res && array_key_exists( 'void_restock', $v4row ) && null === $v4row['void_restock'] && array_intersect_key( $v4row, $v3row ) === $v3row );
		pqbg_t( 'migration: re-running is a no-op', true === Install::migrate_4() && array() === Schema::create_or_update() && $v4row === $wpdb->get_row( "SELECT * FROM $mS", ARRAY_A ) );
		Schema::drop_tables();
	} finally {
		$wpdb->prefix = $real_prefix;
	}
	pqbg_t( 'migration: the temporary tables are gone and the real ones untouched', array() === $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $mig_prefix ) . '%' ) ) && Schema::tables_exist() );

	// Real sales, voids and an undo through SaleService.
	$rp = $make_simple( $A, array( 'manage_stock' => true, 'stock_quantity' => 10, 'low_stock_amount' => 0, 'regular_price' => '300' ) );
	$s1 = SaleService::sell( array( 'code' => $code_of( $rp ), 'quantity' => 1, 'request_id' => wp_generate_uuid4(), 'seller_id' => $SE, 'payment_method' => 'cash' ) );
	$s2 = SaleService::sell( array( 'code' => $code_of( $rp ), 'quantity' => 1, 'request_id' => wp_generate_uuid4(), 'seller_id' => $SE, 'payment_method' => 'upi' ) );
	$s3 = SaleService::sell( array( 'code' => $code_of( $rp ), 'quantity' => 2, 'request_id' => wp_generate_uuid4(), 'seller_id' => $SE, 'payment_method' => 'card' ) );
	$v1 = SaleService::void_sale( (int) $s1['sale']['id'], $SM, 'Returned', true );
	$v2 = SaleService::void_sale( (int) $s2['sale']['id'], $SM, 'Paperwork', false );
	$u3 = SaleService::undo( (int) $s3['sale']['id'], $SE );
	$rs = static fn( array $r ) => SaleRepository::find( (int) $r['sale']['id'] );
	pqbg_t( 'void with restock records void_restock = 1, without = 0, an undo = 1; completed sales keep NULL', is_array( $v1 ) && is_array( $v2 ) && is_array( $u3 ) && '1' === (string) $rs( $s1 )['void_restock'] && '0' === (string) $rs( $s2 )['void_restock'] && '1' === (string) $rs( $s3 )['void_restock'] );
	pqbg_t( 'stock: 10 − 1 − 1 − 2 + 1 (restock) + 2 (undo) = 9 (the void without restock kept its decrement)', 9 === SaleRepository::read_stock( $rp ) );
	pqbg_t( '"Returned to stock": Yes / No / Yes; an old void without the flag "Not recorded", an old undo "Yes (undo)"', 'Yes' === ReportData::restocked( $rs( $s1 ) ) && 'No' === ReportData::restocked( $rs( $s2 ) ) && 'Yes' === ReportData::restocked( $rs( $s3 ) ) && 'Not recorded' === ReportData::restocked( array( 'void_restock' => null, 'void_reason' => 'x' ) ) && 'Yes (undo)' === ReportData::restocked( array( 'void_restock' => null, 'void_reason' => 'undo' ) ) );

	// ------------------------------------------------------------------ periods
	pqbg_section( 'periods: presets, comparison and buckets (site timezone, week start)' );
	$now = $ts( '2026-09-26 14:05:00' ); // A Saturday.
	$same = true;
	foreach ( array( 'today', 'yesterday', 'last7', 'last_month' ) as $preset ) {
		$a    = ReportPeriod::resolve( $preset, '', '', $now );
		$b    = SalesQuery::range( $preset, '', '', $now );
		$same = $same && $a['start'] === $b['start'] && $a['end'] === $b['end'] && $a['from'] === $b['from'] && $a['to'] === $b['to'];
	}
	$a    = ReportPeriod::resolve( 'custom', '2025-03-12', '2025-03-10', $now );
	$b    = SalesQuery::range( 'custom', '2025-03-12', '2025-03-10', $now );
	pqbg_t( 'the presets shared with the sales history give identical bounds (today, yesterday, last7, last_month, custom swapped)', $same && $a['start'] === $b['start'] && $a['end'] === $b['end'] && $a['swapped'] && $b['swapped'] );
	$a = ReportPeriod::resolve( 'this_month', '', '', $now );
	$b = SalesQuery::range( 'this_month', '', '', $now );
	pqbg_t( 'this month: the same start; the report runs to the end of today ("to date"), the history to the end of the month (no sale can be later)', $a['start'] === $b['start'] && '2026-09-26 18:30:00' === $a['end'] && '2026-09-30 18:30:00' === $b['end'] && $a['to_date'] );
	$p = ReportPeriod::resolve( 'today', '', '', $now );
	pqbg_t( 'today (Sat 26 Sep 2026 14:05 IST): 25 Sep 18:30 → 26 Sep 18:30 UTC; compared with yesterday 00:00 → 14:05', '2026-09-25 18:30:00' === $p['start'] && '2026-09-26 18:30:00' === $p['end'] && '2026-09-24 18:30:00' === $p['compare']['start'] && $ist( '2026-09-25 14:05:00' ) === $p['compare']['end'] && '2026-09-25 14:05' === $p['compare']['partial_until'] );
	$p = ReportPeriod::resolve( 'this_week', '', '', $now );
	pqbg_t( 'this week (Monday start): Mon 21 Sep → today; compared with Mon 14 Sep 00:00 → Sat 19 Sep 14:05', '2026-09-21' === $p['from'] && '2026-09-26' === $p['to'] && $ist( '2026-09-14 00:00:00' ) === $p['compare']['start'] && $ist( '2026-09-19 14:05:00' ) === $p['compare']['end'] );
	$p = ReportPeriod::resolve( 'last_week', '', '', $now );
	pqbg_t( 'last week: 14–20 Sep, compared with the whole week before (7–13 Sep)', '2026-09-14' === $p['from'] && '2026-09-20' === $p['to'] && '2026-09-07' === $p['compare']['from'] && '2026-09-13' === $p['compare']['to'] && '' === $p['compare']['partial_until'] && $ist( '2026-09-14 00:00:00' ) === $p['compare']['end'] );
	update_option( 'start_of_week', 0 );
	$p = ReportPeriod::resolve( 'this_week', '', '', $now );
	update_option( 'start_of_week', 1 );
	pqbg_t( 'the week start follows Settings → General (Sunday start: Sun 20 Sep → today)', '2026-09-20' === $p['from'] );
	$p = ReportPeriod::resolve( 'this_month', '', '', $ts( '2026-03-31 15:20:00' ) );
	pqbg_t( 'this month on 31 March 2026: compared with the whole of February (1–28 Feb)', '2026-02-01' === $p['compare']['from'] && '2026-02-28' === $p['compare']['to'] && $ist( '2026-03-01 00:00:00' ) === $p['compare']['end'] && '' === $p['compare']['partial_until'] );
	$p = ReportPeriod::resolve( 'this_month', '', '', $ts( '2024-03-31 15:20:00' ) );
	pqbg_t( '…on 31 March 2024 (leap year): the whole of February (1–29 Feb)', '2024-02-29' === $p['compare']['to'] && $ist( '2024-03-01 00:00:00' ) === $p['compare']['end'] );
	$p = ReportPeriod::resolve( 'this_month', '', '', $ts( '2026-03-29 09:00:00' ) );
	pqbg_t( '…on 29 March 2026: February has no 29th → the whole of February', '2026-02-28' === $p['compare']['to'] && '' === $p['compare']['partial_until'] );
	$p = ReportPeriod::resolve( 'this_month', '', '', $ts( '2026-05-30 10:00:00' ) );
	pqbg_t( 'this month on 30 May 10:00: compared with 1 April 00:00 → 30 April 10:00', $ist( '2026-04-01 00:00:00' ) === $p['compare']['start'] && $ist( '2026-04-30 10:00:00' ) === $p['compare']['end'] && '2026-04-30' === $p['compare']['to'] );
	$p = ReportPeriod::resolve( 'this_month', '', '', $ts( '2026-05-31 10:00:00' ) );
	pqbg_t( '…on 31 May: the whole of April (30 days)', '2026-04-30' === $p['compare']['to'] && $ist( '2026-05-01 00:00:00' ) === $p['compare']['end'] );
	$p = ReportPeriod::resolve( 'last_month', '', '', $ts( '2026-03-10 10:00:00' ) );
	pqbg_t( 'last month (February 2026) compared with January (whole months, 28 vs 31 days)', '2026-02-01' === $p['from'] && '2026-02-28' === $p['to'] && '2026-01-01' === $p['compare']['from'] && '2026-01-31' === $p['compare']['to'] );
	$p = ReportPeriod::resolve( 'last7', '', '', $now );
	$q = ReportPeriod::resolve( 'custom', '2025-03-10', '2025-03-12', $now );
	pqbg_t( 'last 7 days and a custom range compare with the same number of days immediately before', '2026-09-20' === $p['from'] && '2026-09-13' === $p['compare']['from'] && '2026-09-19' === $p['compare']['to'] && '2025-03-07' === $q['compare']['from'] && '2025-03-09' === $q['compare']['to'] && 3 === $q['days'] );
	$p = ReportPeriod::resolve( 'last12m', '', '', $now );
	pqbg_t( 'last 12 months: 27 Sep 2025 → 26 Sep 2026 (365 days)', '2025-09-27' === $p['from'] && '2026-09-26' === $p['to'] && 365 === $p['days'] );
	$p = ReportPeriod::resolve( 'custom', '2015-01-01', '2026-01-01', $now );
	pqbg_t( 'a custom range longer than 1,830 days is cut (start moved forward) and flagged', $p['clamped'] && ReportPeriod::MAX_DAYS === $p['days'] && '2026-01-01' === $p['to'] );
	pqbg_t( 'unknown preset → the fallback; bad custom dates → the fallback', 'today' === ReportPeriod::resolve( 'bogus', '', '', $now )['preset'] && 'this_month' === ReportPeriod::resolve( 'custom', 'x', '2025-02-30', $now, 'this_month' )['preset'] );
	$pm = ReportPeriod::resolve( 'custom', '2025-03-01', '2025-03-31', $now );
	$bw = ReportPeriod::buckets( $pm, 'week' );
	pqbg_t( 'weeks of March 2025 (Monday start): 1–2, 3–9, 10–16, 17–23, 24–30, 31 (cut by the period, never widened)', array( '2025-03-01', '2025-03-03', '2025-03-10', '2025-03-17', '2025-03-24', '2025-03-31' ) === array_column( $bw, 'from' ) && array( '2025-03-02', '2025-03-09', '2025-03-16', '2025-03-23', '2025-03-30', '2025-03-31' ) === array_column( $bw, 'to' ) );
	$bm = ReportPeriod::buckets( ReportPeriod::resolve( 'custom', '2025-01-15', '2025-03-10', $now ), 'month' );
	pqbg_t( 'months: 15–31 Jan, Feb, 1–10 Mar; boundaries at local midnight (18:30 UTC)', array( '2025-01-15', '2025-02-01', '2025-03-01' ) === array_column( $bm, 'from' ) && $ts( '2025-02-01 00:00:00' ) === $bm[1]['start'] && '2025-01-31 18:30:00' === gmdate( 'Y-m-d H:i:s', $bm[1]['start'] ) );
	$bh = ReportPeriod::buckets( ReportPeriod::resolve( 'custom', '2025-06-30', '2025-06-30', $now ), 'hour' );
	pqbg_t( 'hours of one day: 24 buckets, the first at 18:30 UTC the day before', 24 === count( $bh ) && '2025-06-29 18:30:00' === gmdate( 'Y-m-d H:i:s', $bh[0]['start'] ) );
	pqbg_t( 'groupings: hours only for one day; days up to 400 days, then weeks; the defaults', 'day' === ReportPeriod::grouping( $pm, 'hour' ) && 'week' === ReportPeriod::grouping( ReportPeriod::resolve( 'custom', '2024-01-01', '2025-06-30', $now ), 'day' ) && 'hour' === ReportPeriod::default_grouping( ReportPeriod::resolve( 'today', '', '', $now ) ) && 'day' === ReportPeriod::default_grouping( $pm ) && 'week' === ReportPeriod::default_grouping( ReportPeriod::resolve( 'last90', '', '', $now ) ) && 'month' === ReportPeriod::default_grouping( ReportPeriod::resolve( 'custom', '2024-01-01', '2025-06-30', $now ) ) );

	// ------------------------------------------------------------------ fixture A (10–16 March 2025)
	pqbg_section( 'fixture A: the counting rules on a fixed week (10–16 March 2025, hand-computed)' );
	$women = (int) wp_insert_term( 'PQBG9B Women', 'product_cat' )['term_id'];
	$kurta = (int) wp_insert_term( 'PQBG9B Kurtas', 'product_cat', array( 'parent' => $women ) )['term_id'];
	$saleC = (int) wp_insert_term( 'PQBG9B Sale & Offers', 'product_cat' )['term_id'];
	$kids  = (int) wp_insert_term( 'PQBG9B Kids', 'product_cat' )['term_id'];
	$P1    = $make_simple( $A, array(), array( $kurta ) );
	$P2    = $make_simple( $A, array(), array( $women, $saleC ) );
	list( $PV, $PVV ) = $make_variable( $A, array(), array( array(), array() ), array( $kids ) );
	list( $V1, $V2 )  = $PVV;
	$GONE = 999999001; // A product that does not exist (deleted).
	$sz   = static fn( string $s ) => wp_json_encode( array( 'Size' => $s ) );
	$d    = array();
	$d['r1']  = $put( array( 'product_id' => $P1, 'seller_id' => $SE, 'seller_name' => 'Asha One', 'payment_method' => 'cash', 'quantity' => 2, 'line_total' => '200', 'unit_cost' => '60', 'product_name' => 'Alpha Kurta', 'created_at_gmt' => $ist( '2025-03-10 10:00:00' ) ) );
	$d['r2']  = $put( array( 'product_id' => $P2, 'seller_id' => $SE, 'seller_name' => 'Asha One', 'payment_method' => 'upi', 'line_total' => '300', 'product_name' => 'Beta Saree', 'created_at_gmt' => $ist( '2025-03-10 23:59:59' ) ) );
	$d['r3']  = $put( array( 'product_id' => $PV, 'variation_id' => $V1, 'seller_id' => $SE2, 'seller_name' => 'Gone Seller', 'payment_method' => 'card', 'line_total' => '500', 'unit_cost' => '450', 'product_name' => 'Gamma Dress', 'attributes_json' => $sz( 'S' ), 'created_at_gmt' => $ist( '2025-03-11 00:00:00' ) ) );
	$d['r4']  = $put( array( 'product_id' => $P1, 'seller_id' => $SE2, 'seller_name' => 'Gone Seller', 'payment_method' => 'cash', 'quantity' => 3, 'line_total' => '300', 'unit_cost' => '120', 'product_name' => 'Alpha Kurta', 'created_at_gmt' => $ist( '2025-03-11 12:00:00' ) ) );
	$d['r5']  = $put( array( 'product_id' => $P1, 'seller_id' => $SE, 'seller_name' => 'Asha One', 'payment_method' => 'cash', 'line_total' => '1000', 'unit_cost' => '400', 'product_name' => 'Alpha Kurta', 'status' => 'voided', 'voided_by' => $SM, 'voided_at_gmt' => $ist( '2025-03-11 13:30:00' ), 'void_reason' => 'wrong size', 'void_restock' => 1, 'created_at_gmt' => $ist( '2025-03-11 13:00:00' ) ) );
	$d['r6']  = $put( array( 'product_id' => $PV, 'variation_id' => $V2, 'seller_id' => $SE2, 'seller_name' => 'Gone Seller', 'payment_method' => 'upi', 'line_total' => '700', 'product_name' => 'Gamma Dress', 'attributes_json' => $sz( 'M' ), 'status' => 'failed', 'failure_code' => 'sold_online', 'created_at_gmt' => $ist( '2025-03-11 14:00:00' ) ) );
	$d['r7']  = $put( array( 'product_id' => $GONE, 'seller_id' => $SE, 'seller_name' => 'Asha One', 'payment_method' => null, 'line_total' => '250', 'product_name' => '<script>alert(1)</script> Zeta', 'created_at_gmt' => $ist( '2025-03-12 09:00:00' ) ) );
	$d['r8']  = $put( array( 'product_id' => $PV, 'variation_id' => $V2, 'seller_id' => $SE, 'seller_name' => 'Asha One', 'payment_method' => 'other', 'quantity' => 2, 'line_total' => '300', 'unit_cost' => '100', 'product_name' => 'Gamma Dress', 'attributes_json' => $sz( 'M' ), 'created_at_gmt' => $ist( '2025-03-16 18:00:00' ) ) );
	$d['r10'] = $put( array( 'product_id' => $P2, 'seller_id' => $SE, 'seller_name' => 'Asha One', 'payment_method' => 'cash', 'line_total' => '10', 'product_name' => 'Beta Saree', 'created_at_gmt' => '2025-03-12 18:29:59' ) );
	$d['r11'] = $put( array( 'product_id' => $P2, 'seller_id' => $SE, 'seller_name' => 'Asha One', 'payment_method' => 'cash', 'line_total' => '20', 'product_name' => 'Beta Saree', 'created_at_gmt' => '2025-03-12 18:30:00' ) );
	$d['p1']  = $put( array( 'product_id' => $P1, 'seller_id' => $SE, 'seller_name' => 'Asha One', 'payment_method' => 'cash', 'line_total' => '940', 'unit_cost' => '470', 'product_name' => 'Alpha Kurta', 'created_at_gmt' => $ist( '2025-03-05 12:00:00' ) ) );
	wp_delete_user( $SE2 ); // The seller leaves; the snapshot name stays.
	unset( $user_ids[ $logins[ $SE2 ] ] );
	\ProductQrBarcode\SalePresenter::flush();
	$wk  = $F( '2025-03-10', '2025-03-16' );
	$tot = SalesQuery::totals( $wk );
	$sum = ReportsQuery::summary( $wk, true );
	pqbg_t( 'completed only: 8 sales, 12 items, ₹1,880 (r5 voided and r6 failed are not in revenue)', 8 === $sum['all']['count'] && 12 === $sum['all']['items'] && '1880.00' === $sum['all']['revenue'] && 1 === $sum['voided'] && 1 === $sum['failed'] );
	pqbg_t( 'profit only where the cost is known: known revenue 1,300, cost 1,130, profit 170, margin 13.1 % (170 ÷ 1,300, not ÷ 1,880)', '1300.00' === $sum['all']['known_revenue'] && '1130.00' === $sum['all']['cost'] && '170.00' === $sum['all']['profit'] && 13.1 === $sum['all']['margin'] );
	pqbg_t( 'unknown cost is never zero: 4 sales, ₹580 disclosed separately', 4 === $sum['all']['unknown'] && '580.00' === $sum['all']['unknown_revenue'] );
	pqbg_t( 'average sale = revenue ÷ sales = 235', '235.00' === $sum['all']['average'] );
	pqbg_t( 'per method: cash 530 (4), UPI 300, card 500, other 300, not recorded 250', '530.00' === $sum['methods']['cash']['revenue'] && 4 === $sum['methods']['cash']['count'] && '300.00' === $sum['methods']['upi']['revenue'] && '500.00' === $sum['methods']['card']['revenue'] && '300.00' === $sum['methods']['other']['revenue'] && '250.00' === $sum['methods']['']['revenue'] );
	pqbg_t( 'without costs, no cost key exists at all (cost, profit, margin, known_revenue)', array() === array_intersect( array( 'cost', 'profit', 'margin', 'known_revenue', 'known' ), array_keys( ReportsQuery::summary( $wk, false )['all'] ) ) );
	pqbg_t( 'reconciles with the Phase 9A totals (count, items, revenue, cost, profit, unknown, voided, failed)', $tot['all']['count'] === $sum['all']['count'] && $tot['all']['items'] === $sum['all']['items'] && (float) $tot['all']['revenue'] === (float) $sum['all']['revenue'] && (float) $tot['all']['cost'] === (float) $sum['all']['cost'] && (float) $tot['all']['profit'] === (float) $sum['all']['profit'] && $tot['all']['unknown'] === $sum['all']['unknown'] && $tot['voided'] === $sum['voided'] && $tot['failed'] === $sum['failed'] );

	// Sales over time.
	$ds = ReportData::build( 'sales', $ctx( '2025-03-10', '2025-03-16', true, array( 'group' => 'day' ) ) );
	$rev = array_column( $ds['rows'], 'revenue' );
	pqbg_t( 'sales over time by day (IST): Mon 500, Tue 800, Wed 260 (r10 at 18:29:59 UTC), Thu 20 (r11 at 18:30:00 UTC), Fri 0, Sat 0, Sun 300', array( '500.00', '800.00', '260.00', '20.00', '0.00', '0.00', '300.00' ) === $rev, implode( ',', $rev ) );
	pqbg_t( '…Tuesday also shows its voided and failed attempt; the column sums equal the totals', 1 === $ds['rows'][1]['voided'] && 1 === $ds['rows'][1]['failed'] && '1880.00' === ReportsQuery::money( (string) array_sum( array_map( 'floatval', $rev ) ) ) && 8 === array_sum( array_column( $ds['rows'], 'count' ) ) && '1880.00' === $ds['total']['revenue'] );
	pqbg_t( '…profit per day where known: Mon 80, Tue −10 (a loss), Sun 100', '80.00' === $ds['rows'][0]['profit'] && '-10.00' === $ds['rows'][1]['profit'] && '100.00' === $ds['rows'][6]['profit'] && '0.00' === $ds['rows'][2]['profit'] );
	$dsn = ReportData::build( 'sales', $ctx( '2025-03-10', '2025-03-16', false, array( 'group' => 'day' ) ) );
	pqbg_t( 'without costs the columns are absent (not blank)', ! array_intersect( array_keys( $dsn['columns'] ), array( 'profit', 'margin', 'unknown', 'unknown_revenue', 'cost' ) ) && ! isset( $dsn['rows'][0]['profit'] ) );

	// Products.
	$dp = ReportData::build( 'products', $ctx( '2025-03-10', '2025-03-16', true ) );
	$pr = $by( $dp['rows'], 'name' );
	pqbg_t( 'products by item: Alpha Kurta 5 items ₹500 (2 sales), profit 20 (80 − 60), margin 4.0 %', isset( $pr['Alpha Kurta'] ) && 5 === $pr['Alpha Kurta']['items'] && '500.00' === $pr['Alpha Kurta']['revenue'] && 2 === $pr['Alpha Kurta']['count'] && '20.00' === $pr['Alpha Kurta']['profit'] && 4.0 === $pr['Alpha Kurta']['margin'] );
	pqbg_t( '…variations separately: "Gamma Dress – Size: S" ₹500 (margin 10 %), "– Size: M" ₹300 (33.3 %); Beta Saree ₹330 with 3 sales of unknown cost', '500.00' === ( $pr['Gamma Dress – Size: S']['revenue'] ?? '' ) && 10.0 === $pr['Gamma Dress – Size: S']['margin'] && 33.3 === $pr['Gamma Dress – Size: M']['margin'] && '330.00' === $pr['Beta Saree']['revenue'] && 3 === $pr['Beta Saree']['unknown'] && null === $pr['Beta Saree']['margin'] );
	pqbg_t( '…a deleted product appears from its snapshot name with "(deleted)" and no edit link', isset( $pr['<script>alert(1)</script> Zeta (deleted)'] ) && '' === $pr['<script>alert(1)</script> Zeta (deleted)']['_link'] && '250.00' === $pr['<script>alert(1)</script> Zeta (deleted)']['revenue'] );
	pqbg_t( '…share of revenue: Alpha Kurta 26.6 % (500 ÷ 1,880); rows sum to the total', 26.6 === $pr['Alpha Kurta']['share'] && '1880.00' === ReportsQuery::money( (string) array_sum( array_map( static fn( $r ) => (float) $r['revenue'], $dp['rows'] ) ) ) && 12 === array_sum( array_column( $dp['rows'], 'items' ) ) );
	$dpp = $by( ReportData::build( 'products', $ctx( '2025-03-10', '2025-03-16', true, array( 'view' => 'product' ) ) )['rows'], 'name' );
	pqbg_t( 'products by product: Gamma Dress ₹800, 3 items, 2 sales, profit 150, margin 18.8 %', '800.00' === ( $dpp['Gamma Dress']['revenue'] ?? '' ) && 3 === $dpp['Gamma Dress']['items'] && 2 === $dpp['Gamma Dress']['count'] && '150.00' === $dpp['Gamma Dress']['profit'] && 18.8 === $dpp['Gamma Dress']['margin'] );
	pqbg_t( 'best sellers sort by revenue (default), ties by name', 'revenue' === $dp['default_sort'] && 'Alpha Kurta' === ReportsQuery::top( $dp['rows'], 'revenue', 1 )[0]['name'] );

	// Categories.
	$dc = ReportData::build( 'categories', $ctx( '2025-03-10', '2025-03-16', true ) );
	$cr = array_map( static fn( $r ) => array( $r['name'], $r['revenue'], $r['products'] ), $dc['rows'] );
	pqbg_t(
		'categories in tree order with sub-categories included: Kids 800, Sale & Offers 330, Women 830 (Alpha via Kurtas + Beta), — Kurtas 500, deleted products 250',
		array( array( 'PQBG9B Kids', '800.00', 1 ), array( 'PQBG9B Sale & Offers', '330.00', 1 ), array( 'PQBG9B Women', '830.00', 2 ), array( '— PQBG9B Kurtas', '500.00', 1 ), array( 'Deleted products (category unknown)', '250.00', 1 ) ) === $cr,
		wp_json_encode( $cr )
	);
	pqbg_t( '…a product in two categories counts fully in each (top-level rows 2,210 > total 1,880); the total counts each sale once; the note says so', '1880.00' === $dc['total']['revenue'] && 1 === count( array_filter( $dc['notes'], static fn( $n ) => str_contains( $n, 'more than one category' ) ) ) && 2210.0 === 800.0 + 330.0 + 830.0 + 250.0 );
	pqbg_t( '…category profit: Women 20 (Alpha; Beta unknown), Kids 150', '20.00' === $dc['rows'][2]['profit'] && '150.00' === $dc['rows'][0]['profit'] && 3 === $dc['rows'][2]['unknown'] );

	// Sellers.
	$dl = $by( ReportData::build( 'sellers', $ctx( '2025-03-10', '2025-03-16', true ) )['rows'], 'name' );
	pqbg_t( 'sellers: Asha One 6 sales, 8 items, ₹1,080, 1 voided, void rate 14.3 % (1 ÷ 7)', 6 === ( $dl['Asha One']['count'] ?? 0 ) && 8 === $dl['Asha One']['items'] && '1080.00' === $dl['Asha One']['revenue'] && 1 === $dl['Asha One']['voided'] && 14.3 === $dl['Asha One']['void_rate'] && '180.00' === $dl['Asha One']['average'] );
	pqbg_t( '…a deleted seller by the name snapshot: "Gone Seller (deleted user)" 2 sales ₹800, 1 failed (not a void), void rate 0 %', isset( $dl['Gone Seller (deleted user)'] ) && '800.00' === $dl['Gone Seller (deleted user)']['revenue'] && 1 === $dl['Gone Seller (deleted user)']['failed'] && 0 === $dl['Gone Seller (deleted user)']['voided'] && 0.0 === $dl['Gone Seller (deleted user)']['void_rate'] && '-10.00' === $dl['Gone Seller (deleted user)']['profit'] );
	pqbg_t( '…sellers sum to the totals', '1880.00' === ReportsQuery::money( (string) array_sum( array_map( static fn( $r ) => (float) $r['revenue'], $dl ) ) ) );

	// Peak times.
	$pk = ReportsQuery::peak( $wk );
	pqbg_t( 'peak grid (IST): Mon 10:00 and 23:00, Tue 00:00 (r3 at 18:30 UTC Mon) and 12:00, Wed 09:00 and 23:00 (18:29:59 UTC), Thu 00:00 (18:30:00 UTC), Sun 18:00', 1 === $pk['grid'][1][10]['count'] && 1 === $pk['grid'][1][23]['count'] && 1 === $pk['grid'][2][0]['count'] && '500.00' === $pk['grid'][2][0]['revenue'] && 1 === $pk['grid'][2][12]['count'] && 1 === $pk['grid'][3][9]['count'] && 1 === $pk['grid'][3][23]['count'] && 1 === $pk['grid'][4][0]['count'] && 1 === $pk['grid'][7][18]['count'] && 8 === array_sum( array_map( static fn( $d ) => array_sum( array_column( $d, 'count' ) ), $pk['grid'] ) ) );
	$days = ReportData::weekdays();
	$bz   = ReportsAdmin::busiest( $pk, $days, 'revenue' );
	pqbg_t( 'busiest by revenue: Tue 00:00 (₹500) first; busiest day Tuesday (₹800)', str_starts_with( $bz, 'Busiest hours: Tue 00:00–01:00 (₹500.00)' ) && str_contains( $bz, 'Busiest day: Tue.' ), $bz );
	$bc = ReportsAdmin::busiest( $pk, $days, 'count' );
	pqbg_t( 'busiest by count: ties in week order (Mon 10:00, Mon 23:00, Tue 00:00); busiest hour 00:00 (2 sales)', str_starts_with( $bc, 'Busiest hours: Mon 10:00–11:00 (1 sale) · Mon 23:00–00:00 (1 sale) · Tue 00:00–01:00 (1 sale)' ) && str_contains( $bc, 'Busiest hour of the day: 00:00–01:00' ), $bc );

	// Voids & failed.
	$dv = ReportData::build( 'voids', $ctx( '2025-03-10', '2025-03-16', false ) );
	$vr = $by( $dv['rows'], 'sale' );
	pqbg_t( 'voids & failed: r5 (voided by PQBG Manager, "wrong size", returned to stock Yes) and r6 (failed, sold_online); nothing else', 2 === count( $dv['rows'] ) && 'PQBG Manager' === $vr[ $d['r5'] ]['voided_by'] && 'wrong size' === $vr[ $d['r5'] ]['reason'] && 'Yes' === $vr[ $d['r5'] ]['restocked'] && 'Asha One' === $vr[ $d['r5'] ]['seller'] && 'sold_online' === $vr[ $d['r6'] ]['failure'] && 'Failed' === $vr[ $d['r6'] ]['status'] );
	pqbg_t( '…per seller and per failure code; reconciles with the history counts (status filter)', array( 'sold_online' => 1 ) === $dv['voids']['failures'] && SalesQuery::count( array_merge( $wk, array( 'status' => 'voided' ) ) ) + SalesQuery::count( array_merge( $wk, array( 'status' => 'failed' ) ) ) === count( $dv['rows'] ) );

	// Profit & margin.
	pqbg_t( 'the profit report is refused without costs (WP_Error pqbg_forbidden)', is_wp_error( ReportData::build( 'profit', $ctx( '2025-03-10', '2025-03-16', false ) ) ) && 'pqbg_forbidden' === ReportData::build( 'profit', $ctx( '2025-03-10', '2025-03-16', false ) )->get_error_code() );
	$dpf = ReportData::build( 'profit', $ctx( '2025-03-10', '2025-03-16', true, array( 'by' => 'product' ) ) );
	pqbg_t( 'profit by item: columns revenue, known revenue, cost, profit, margin, unknown; Alpha Kurta cost 480; unknown-cost rule stated', array( 'name', 'sku', 'revenue', 'known_revenue', 'cost', 'profit', 'margin', 'unknown', 'unknown_revenue' ) === array_keys( $dpf['columns'] ) && '480.00' === $by( $dpf['rows'], 'name' )['Alpha Kurta']['cost'] && in_array( ReportData::unknown_cost_rule(), $dpf['notes'], true ) );
	$dpp2 = ReportData::build( 'profit', $ctx( '2025-03-10', '2025-03-16', true ) );
	pqbg_t( 'profit by period: total 170, margin 13.1 %, unknown 4 sales ₹580', '170.00' === $dpp2['total']['profit'] && 13.1 === $dpp2['total']['margin'] && 4 === $dpp2['total']['unknown'] && '580.00' === $dpp2['total']['unknown_revenue'] );

	// Dashboard (custom week vs the week before: 3–9 March has p1 only).
	$dash = ReportsAdmin::dashboard_data( ReportPeriod::resolve( 'custom', '2025-03-10', '2025-03-16' ), true );
	pqbg_t( 'dashboard: the comparison week has ₹940 (1 sale, profit 470, margin 50 %)', '940.00' === $dash['then']['revenue'] && 1 === $dash['then']['count'] && '470.00' === $dash['then']['profit'] && 50.0 === $dash['then']['margin'] );
	pqbg_t( 'dashboard changes: revenue ↑ 100 %, sales ↑ 700 %, items ↑ 1,100 %, average ↓ 75 %, profit ↓ 63.8 %, margin ↓ 36.9 pt', 100.0 === ReportsQuery::change( $dash['now']['revenue'], $dash['then']['revenue'] ) && 700.0 === ReportsQuery::change( $dash['now']['count'], $dash['then']['count'] ) && 1100.0 === ReportsQuery::change( $dash['now']['items'], $dash['then']['items'] ) && -75.0 === ReportsQuery::change( $dash['now']['average'], $dash['then']['average'] ) && -63.8 === ReportsQuery::change( $dash['now']['profit'], $dash['then']['profit'] ) && '↓ 36.9 pt' === ReportsAdmin::change_text( round( $dash['now']['margin'] - $dash['then']['margin'], 1 ), true ) && '↑ 100.0%' === ReportsAdmin::change_text( 100.0, false ) );
	pqbg_t( 'dashboard: top products Alpha Kurta then Gamma Dress – Size: S (₹500 each, ties by name), top sellers from the same scan', array( 'Alpha Kurta', 'Gamma Dress – Size: S' ) === array_slice( array_column( $dash['products'], 'name' ), 0, 2 ) && array( 'Asha One', 'Gone Seller (deleted user)' ) === array_column( $dash['sellers'], 'name' ) && '1080.00' === $dash['sellers'][0]['revenue'] && 1 === $dash['voided'] );
	pqbg_t( 'dashboard: 7 day buckets of the week summing to the cards; payment split = per-method totals', 7 === count( $dash['buckets'] ) && '1880.00' === ReportsQuery::money( (string) array_sum( array_map( static fn( $m ) => (float) $m['revenue'], $dash['series'] ) ) ) && '530.00' === $dash['methods']['cash']['revenue'] );
	pqbg_t( 'change: "nothing to compare with" when the previous value is 0', null === ReportsQuery::change( '10', '0' ) && str_contains( ReportsAdmin::change_text( null, false ), 'nothing to compare' ) );

	// ------------------------------------------------------------------ fixture B: boundaries for every grouping
	pqbg_section( 'fixture B: 18:29:59 / 18:30:00 UTC boundaries for day, week, month, hour, peak and end of day' );
	$put( array( 'seller_id' => $SE, 'seller_name' => 'Asha One', 'line_total' => '5', 'created_at_gmt' => '2025-06-15 18:29:59' ) );  // Sun 15 Jun 23:59:59 IST.
	$put( array( 'seller_id' => $SE, 'seller_name' => 'Asha One', 'line_total' => '7', 'created_at_gmt' => '2025-06-15 18:30:00' ) );  // Mon 16 Jun 00:00 IST.
	$put( array( 'seller_id' => $SE, 'seller_name' => 'Asha One', 'line_total' => '11', 'created_at_gmt' => '2025-06-30 18:29:59' ) ); // Mon 30 Jun 23:59:59 IST.
	$put( array( 'seller_id' => $SE, 'seller_name' => 'Asha One', 'line_total' => '13', 'created_at_gmt' => '2025-06-30 18:30:00' ) ); // Tue 1 Jul 00:00 IST.
	$series = static function ( string $group ) use ( $ctx ) {
		$data = ReportData::build( 'sales', $ctx( '2025-06-15', '2025-07-01', false, array( 'group' => $group ) ) );
		return array_values( array_filter( array_map( static fn( $r ) => array( $r['period'], $r['revenue'] ), $data['rows'] ), static fn( $x ) => '0.00' !== $x[1] ) );
	};
	pqbg_t( 'by day: 15 Jun 5, 16 Jun 7, 30 Jun 11, 1 Jul 13', array( array( 'Sun 15 Jun 2025', '5.00' ), array( 'Mon 16 Jun 2025', '7.00' ), array( 'Mon 30 Jun 2025', '11.00' ), array( 'Tue 1 Jul 2025', '13.00' ) ) === $series( 'day' ), wp_json_encode( $series( 'day' ) ) );
	pqbg_t( 'by week (Monday start): 15 Jun (Sunday, end of its week) 5; 16–22 Jun 7; 30 Jun – 1 Jul 24', array( array( 'Sun 15 Jun 2025', '5.00' ), array( '16 Jun – 22 Jun 2025', '7.00' ), array( '30 Jun – 1 Jul 2025', '24.00' ) ) === $series( 'week' ), wp_json_encode( $series( 'week' ) ) );
	$mo = $series( 'month' );
	pqbg_t( 'by month: June (15–30) 23, July (1) 13', 2 === count( $mo ) && '23.00' === $mo[0][1] && str_starts_with( $mo[0][0], 'June 2025' ) && '13.00' === $mo[1][1] && str_starts_with( $mo[1][0], 'July 2025' ), wp_json_encode( $mo ) );
	$hr = ReportData::build( 'sales', $ctx( '2025-06-30', '2025-06-30', false, array( 'group' => 'hour' ) ) );
	pqbg_t( 'by hour (30 Jun): the 23:59:59 sale is in 23:00–24:00; the 00:00 sale of 1 July is not in the day', 24 === count( $hr['rows'] ) && '11.00' === $hr['rows'][23]['revenue'] && '11.00' === $hr['total']['revenue'] );
	$pk2 = ReportsQuery::peak( $F( '2025-06-15', '2025-07-01' ) );
	pqbg_t( 'peak grid: Sun 23:00, Mon 00:00, Mon 23:00, Tue 00:00', 1 === $pk2['grid'][7][23]['count'] && 1 === $pk2['grid'][1][0]['count'] && 1 === $pk2['grid'][1][23]['count'] && 1 === $pk2['grid'][2][0]['count'] );
	$eb = ReportsQuery::end_of_day( $F( '2025-06-16', '2025-06-16' ) );
	pqbg_t( 'end of day 16 Jun: only the 00:00 sale (7), not the 23:59:59 one of the 15th', '7.00' === $eb['methods']['cash']['revenue'] && 1 === $eb['methods']['cash']['sales'] );

	// ------------------------------------------------------------------ end of day (14–15 April 2025)
	pqbg_section( 'end of day: net collected per method and per seller (15 April 2025, hand-computed)' );
	$SEb = $SE3; // A second, existing seller for this fixture.
	$e   = array();
	$e['e1'] = $put( array( 'seller_id' => $SE, 'seller_name' => 'Asha One', 'payment_method' => 'cash', 'line_total' => '1000', 'status' => 'voided', 'voided_by' => $SM, 'voided_at_gmt' => $ist( '2025-04-15 10:00:00' ), 'void_reason' => 'returned', 'void_restock' => 1, 'created_at_gmt' => $ist( '2025-04-14 12:00:00' ) ) );
	$e['e2'] = $put( array( 'seller_id' => $SEb, 'seller_name' => 'Ravi Three', 'payment_method' => 'upi', 'line_total' => '400', 'status' => 'voided', 'voided_by' => $A, 'voided_at_gmt' => $ist( '2025-04-15 11:00:00' ), 'void_reason' => 'refund', 'void_restock' => 0, 'created_at_gmt' => $ist( '2025-04-14 13:00:00' ) ) );
	$e['e3'] = $put( array( 'seller_id' => $SE, 'seller_name' => 'Asha One', 'payment_method' => 'cash', 'line_total' => '150', 'status' => 'voided', 'voided_by' => $SM, 'voided_at_gmt' => $ist( '2025-04-14 15:00:00' ), 'void_reason' => 'same day 14th', 'created_at_gmt' => $ist( '2025-04-14 14:00:00' ) ) );
	$e['t1'] = $put( array( 'seller_id' => $SE, 'seller_name' => 'Asha One', 'payment_method' => 'cash', 'line_total' => '500', 'created_at_gmt' => $ist( '2025-04-15 10:30:00' ) ) );
	$e['t2'] = $put( array( 'seller_id' => $SE, 'seller_name' => 'Asha One', 'payment_method' => 'cash', 'line_total' => '250', 'quantity' => 2, 'created_at_gmt' => $ist( '2025-04-15 11:30:00' ) ) );
	$e['t3'] = $put( array( 'seller_id' => $SEb, 'seller_name' => 'Ravi Three', 'payment_method' => 'cash', 'line_total' => '700', 'created_at_gmt' => $ist( '2025-04-15 12:00:00' ) ) );
	$e['t4'] = $put( array( 'seller_id' => $SEb, 'seller_name' => 'Ravi Three', 'payment_method' => 'upi', 'line_total' => '300', 'created_at_gmt' => $ist( '2025-04-15 12:30:00' ) ) );
	$e['t5'] = $put( array( 'seller_id' => $SE, 'seller_name' => 'Asha One', 'payment_method' => 'card', 'line_total' => '1200', 'created_at_gmt' => $ist( '2025-04-15 13:00:00' ) ) );
	$e['t6'] = $put( array( 'seller_id' => $SE, 'seller_name' => 'Asha One', 'payment_method' => 'cash', 'line_total' => '90', 'status' => 'voided', 'voided_by' => $SM, 'voided_at_gmt' => $ist( '2025-04-15 14:10:00' ), 'void_reason' => 'own sale today', 'created_at_gmt' => $ist( '2025-04-15 14:00:00' ) ) );
	$e['t7'] = $put( array( 'seller_id' => $SEb, 'seller_name' => 'Ravi Three', 'payment_method' => 'cash', 'line_total' => '999', 'status' => 'failed', 'failure_code' => 'sold_online', 'created_at_gmt' => $ist( '2025-04-15 15:00:00' ) ) );
	$e['t8'] = $put( array( 'seller_id' => $SE, 'seller_name' => 'Asha One', 'payment_method' => 'cash', 'line_total' => '60', 'created_at_gmt' => '2025-04-14 18:29:59' ) ); // 14th 23:59:59 IST.
	$e['t9'] = $put( array( 'seller_id' => $SE, 'seller_name' => 'Asha One', 'payment_method' => 'cash', 'line_total' => '40', 'created_at_gmt' => '2025-04-14 18:30:00' ) ); // 15th 00:00 IST.
	$eod = ReportsQuery::end_of_day( $F( '2025-04-15', '2025-04-15' ) );
	$mm  = $eod['methods'];
	pqbg_t( 'cash: revenue 1,490 (500 + 250 + 700 + 40), refunds of earlier sales 1,000 (e1) → "Cash expected in drawer" 490', '1490.00' === $mm['cash']['revenue'] && '1000.00' === $mm['cash']['refunds'] && '490.00' === $mm['cash']['net'] && 4 === $mm['cash']['sales'] && 5 === $mm['cash']['items'] );
	pqbg_t( 'the same-day void t6 (₹90) is not in revenue and is NOT subtracted again; the failed t7 and the 14th\'s voids (e3) are ignored', '490.00' === $mm['cash']['net'] && 1 === count( $eod['voided_own'] ) && (int) $eod['voided_own'][0]['id'] === $e['t6'] && array( $e['e1'], $e['e2'] ) === array_map( 'intval', array_column( $eod['voided_earlier'], 'id' ) ) );
	pqbg_t( 'UPI net collected 300 − 400 = −100; card 1,200; other 0; total revenue 2,990, refunds 1,400, net 1,590', '-100.00' === $mm['upi']['net'] && '1200.00' === $mm['card']['net'] && '0.00' === $mm['other']['net'] && '2990.00' === $eod['total']['revenue'] && '1400.00' === $eod['total']['refunds'] && '1590.00' === $eod['total']['net'] );
	$sa = null;
	$sb = null;
	foreach ( $eod['sellers'] as $sid => $s ) {
		$sa = $sid === $SE ? $s : $sa;
		$sb = $sid === $SEb ? $s : $sb;
	}
	pqbg_t( 'per seller (who sold): Asha One cash 790 − 1,000 = −210, card 1,200; Ravi Three cash 700, UPI 300 − 400 = −100; totals 990 + 600 = 1,590', is_array( $sa ) && is_array( $sb ) && '790.00' === $sa['revenue']['cash'] && '1000.00' === $sa['refunds']['cash'] && '-210.00' === $sa['net']['cash'] && '1200.00' === $sa['net']['card'] && '700.00' === $sb['net']['cash'] && '-100.00' === $sb['net']['upi'] && 990.0 === array_sum( array_map( 'floatval', $sa['net'] ) ) && 600.0 === array_sum( array_map( 'floatval', $sb['net'] ) ) );
	pqbg_t( '"Voided today by": PQBG Manager cash 1,000 (1 sale), PQBG Admin UPI 400 (1 sale)', 'PQBG Manager' === $eod['refunds_by'][ $SM ]['name'] && '1000.00' === $eod['refunds_by'][ $SM ]['methods']['cash'] && 1 === $eod['refunds_by'][ $SM ]['count'] && '400.00' === $eod['refunds_by'][ $A ]['methods']['upi'] );
	pqbg_t( 'the day\'s revenue reconciles with the Phase 9A totals for the same day', $eod['total']['revenue'] === SalesQuery::totals( $F( '2025-04-15', '2025-04-15' ) )['all']['revenue'] );
	$de   = ReportData::build( 'eod', $ctx( '2025-04-15', '2025-04-15', false ) );
	$ecsv = $csv_rows( substr( $csv_of( $de ), 3 ) );
	$find = static fn( string $section, string $name, string $method ) => array_values( array_filter( $ecsv, static fn( $r ) => $r[0] === $section && $r[1] === $name && $r[2] === $method ) )[0] ?? null;
	pqbg_t( 'CSV: the per-method rows with revenue, refunds and net collected (cash 1,490 / 1,000 / 490)', array( 'Payment method', '', 'Cash', '4', '5', '1490.00', '1000.00', '490.00' ) === array_slice( $find( 'Payment method', '', 'Cash' ) ?? array(), 0, 8 ) );
	pqbg_t( 'CSV: per seller (who sold) and "Voided in this period by" rows; the voided sales with sale #, reason and returned to stock', '-210.00' === ( $find( 'Seller (who sold)', 'Asha One', 'Cash' )[7] ?? '' ) && '400.00' === ( $find( 'Voided in this period by', 'PQBG Admin', 'UPI' )[6] ?? '' ) && 'Yes' === ( array_values( array_filter( $ecsv, static fn( $r ) => ( $r[8] ?? '' ) === (string) $e['e1'] ) )[0][14] ?? '' ) && 'No' === ( array_values( array_filter( $ecsv, static fn( $r ) => ( $r[8] ?? '' ) === (string) $e['e2'] ) )[0][14] ?? '' ) );
	pqbg_t( 'CSV: the totals row last (net 1,590) and the refund assumption stated with the report', array( 'Total', '', '', '6', '7', '2990.00', '1400.00', '1590.00' ) === array_slice( end( $ecsv ), 0, 8 ) && in_array( ReportData::refund_assumption(), $de['notes'], true ) );

	// ------------------------------------------------------------------ stock and dead stock
	pqbg_section( 'stock: values, thresholds, shared stock, below zero, costs' );
	update_option( 'woocommerce_notify_low_stock_amount', 2 );
	update_option( 'woocommerce_notify_no_stock_amount', 0 );
	$st  = array();
	$st['S1'] = $make_simple( $A, array( 'manage_stock' => true, 'stock_quantity' => 10, 'regular_price' => '100' ), array( $kurta ) );
	$st['S2'] = $make_simple( $A, array( 'manage_stock' => true, 'stock_quantity' => 2, 'regular_price' => '50' ) );
	$st['S3'] = $make_simple( $A, array( 'manage_stock' => true, 'stock_quantity' => 0, 'regular_price' => '80' ) );
	$st['S4'] = $make_simple( $A, array( 'manage_stock' => true, 'stock_quantity' => 5, 'low_stock_amount' => 10, 'regular_price' => '200', 'status' => 'private' ) );
	$st['S5'] = $make_simple( $A, array( 'manage_stock' => true, 'stock_quantity' => -3, 'backorders' => 'yes', 'regular_price' => '90', 'status' => 'draft' ) );
	$st['S6'] = $make_simple( $A, array( 'manage_stock' => false, 'regular_price' => '90' ) ); // Not managed: not listed.
	$st['S7'] = $make_simple( $A, array( 'manage_stock' => true, 'stock_quantity' => 9, 'regular_price' => '90' ) );
	wp_trash_post( $st['S7'] ); // Trashed: not listed.
	list( $VP, $VPv ) = $make_variable( $A, array( 'manage_stock' => true, 'stock_quantity' => 6 ), array( array( 'regular_price' => '300' ), array( 'regular_price' => '300' ) ) );
	list( $VM, $VMv ) = $make_variable( $A, array( 'manage_stock' => true, 'stock_quantity' => 4 ), array( array( 'regular_price' => '300' ), array( 'regular_price' => '400' ) ) );
	list( $VO, $VOv ) = $make_variable( $A, array(), array( array( 'manage_stock' => true, 'stock_quantity' => 7, 'regular_price' => '120' ) ) );
	CostPrice::set( $st['S1'], '60' );
	CostPrice::set( $st['S4'], '987.65' ); // A planted cost value searched for in the shop manager's pages.
	CostPrice::set( $VP, '100' );
	CostPrice::set( $VO, '70' );
	$mine = array_merge( array_values( array_diff_key( $st, array_flip( array( 'S6', 'S7' ) ) ) ), array( $VP, $VM, $VOv[0] ) );
	$hold = static fn( bool $costs ) => array_column( array_filter( StockQuery::holders( $costs ), static fn( $h ) => in_array( $h['id'], $mine, true ) ), null, 'id' );
	$h    = $hold( true );
	pqbg_t( 'holders: the 5 simple products that manage stock (publish, private, draft), both shared-stock parents and the variation with its own stock; not the unmanaged or trashed ones', 8 === count( $h ) && ! isset( $h[ $st['S6'] ] ) && ! isset( $h[ $st['S7'] ] ) && 'shared' === $h[ $VP ]['kind'] && 'variation' === $h[ $VOv[0] ]['kind'] && 'simple' === $h[ $st['S1'] ]['kind'] );
	pqbg_t( 'states with the WooCommerce thresholds: S1 in stock (10), S2 low (2 ≤ store 2), S3 out (0), S4 low (5 ≤ its own 10), S5 out and below zero', 'instock' === $h[ $st['S1'] ]['state'] && 'low' === $h[ $st['S2'] ]['state'] && 'out' === $h[ $st['S3'] ]['state'] && 'low' === $h[ $st['S4'] ]['state'] && 10 === $h[ $st['S4'] ]['low'] && 'out' === $h[ $st['S5'] ]['state'] && $h[ $st['S5'] ]['negative'] );
	pqbg_t( 'values at price: S1 1,000, S2 100, S3 0, S4 1,000, S5 0 (below zero valued at 0), shared VP 6 × 300 = 1,800, mixed VM not valued, VO 840', '1000.00' === $h[ $st['S1'] ]['value'] && '100.00' === $h[ $st['S2'] ]['value'] && '0.00' === $h[ $st['S3'] ]['value'] && '1000.00' === $h[ $st['S4'] ]['value'] && '0.00' === $h[ $st['S5'] ]['value'] && '1800.00' === $h[ $VP ]['value'] && null === $h[ $VM ]['value'] && 'mixed' === $h[ $VM ]['price_note'] && '840.00' === $h[ $VOv[0] ]['value'] );
	pqbg_t( 'values at cost: S1 600, S4 4,938.25, VP (parent default 100) 600, VO (the parent default for the variation) 490; S2 and VM without a cost', '600.00' === $h[ $st['S1'] ]['cost_value'] && '4938.25' === $h[ $st['S4'] ]['cost_value'] && '600.00' === $h[ $VP ]['cost_value'] && '490.00' === $h[ $VOv[0] ]['cost_value'] && null === $h[ $st['S2'] ]['cost_value'] && null === $h[ $VM ]['cost_value'] );
	$t = StockQuery::totals( array_values( $h ), true );
	pqbg_t( 'totals: 34 units (negative counts 0), value 4,740, 1 item not priced (VM, 4 units); at cost 6,628.25 with 4 items without a cost (S2, S3, S5, VM) disclosed', 34 === $t['units'] && '4740.00' === $t['value'] && 1 === $t['unpriced'] && 4 === $t['unpriced_units'] && '6628.25' === $t['cost_value'] && 4 === $t['uncosted'] && 2 === $t['low'] && 2 === $t['out'] && 1 === $t['negative'], wp_json_encode( $t ) );
	$hn = $hold( false );
	pqbg_t( 'without costs no cost key exists on a holder', ! isset( $hn[ $st['S1'] ]['cost'] ) && ! isset( $hn[ $st['S1'] ]['cost_value'] ) && ! array_key_exists( 'cost_value', StockQuery::totals( array_values( $hn ), false ) ) );
	$post_of = static fn( array $r ) => (int) ( wp_parse_args( (string) wp_parse_url( (string) $r['_link'], PHP_URL_QUERY ) )['post'] ?? 0 );
	wp_set_current_user( $A ); // Edit links are built only for users who may edit the product.
	$dsw = ReportData::build( 'stock', array( 'costs' => true, 'state' => '', 'cat' => $women, 'period' => ReportPeriod::resolve( 'today' ) ) );
	pqbg_t( 'category filter includes sub-categories (Women → only S1, in Kurtas, among the stock fixtures)', array( $st['S1'] ) === array_values( array_intersect( array_map( $post_of, $dsw['rows'] ), $mine ) ) );
	$ids_of = static fn( string $state ) => array_values( array_intersect( array_map( $post_of, ReportData::build( 'stock', array( 'costs' => false, 'state' => $state, 'cat' => 0 ) )['rows'] ), $mine ) );
	$lows   = $ids_of( 'low' );
	sort( $lows );
	$want   = array( $st['S2'], $st['S4'] );
	sort( $want );
	pqbg_t( 'state filters: low (S2, S4), out (S3, S5), below zero (S5)', $want === $lows && 2 === count( $ids_of( 'out' ) ) && array( $st['S5'] ) === $ids_of( 'negative' ) );
	wp_set_current_user( 0 );

	pqbg_section( 'missing codes (active items without a QR code)' );
	$nc1 = $make_simple( 0, array() ); // Saved by nobody: no code.
	$nc2 = $make_simple( 0, array( 'status' => 'draft' ) ); // Draft: not active.
	list( $ncp, $ncv ) = $make_variable( 0, array(), array( array(), array() ) );
	$miss = array_column( StockQuery::missing_codes(), null, 'id' );
	pqbg_t( 'a published simple product and the published variations of a published variable product without a code are listed; the draft, the variable parent and coded items are not', isset( $miss[ $nc1 ] ) && ! isset( $miss[ $nc2 ] ) && isset( $miss[ $ncv[0] ] ) && isset( $miss[ $ncv[1] ] ) && ! isset( $miss[ $ncp ] ) && ! isset( $miss[ $P1 ] ) && ! isset( $miss[ $st['S1'] ] ) );

	pqbg_section( 'dead stock: 30 / 60 / 90 days / never, new items, online orders by status' );
	foreach ( array_merge( array_values( $st ), array( $VP, $VM, $VO, $VOv[0] ) ) as $pid ) {
		$backdate( $pid, 365 );
	}
	$backdate( $st['S2'], 3 ); // New: added 3 days ago.
	$put( array( 'product_id' => $st['S1'], 'seller_id' => $SE, 'seller_name' => 'Asha One', 'line_total' => '100', 'quantity' => 4, 'created_at_gmt' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ) );
	$put( array( 'product_id' => $st['S4'], 'seller_id' => $SE, 'seller_name' => 'Asha One', 'line_total' => '200', 'created_at_gmt' => gmdate( 'Y-m-d H:i:s', time() - 45 * DAY_IN_SECONDS ) ) );
	$put( array( 'product_id' => $VP, 'variation_id' => $VPv[1], 'seller_id' => $SE, 'seller_name' => 'Asha One', 'line_total' => '300', 'created_at_gmt' => gmdate( 'Y-m-d H:i:s', time() - 70 * DAY_IN_SECONDS ) ) );
	$put( array( 'product_id' => $VO, 'variation_id' => $VOv[0], 'seller_id' => $SE, 'seller_name' => 'Asha One', 'line_total' => '120', 'created_at_gmt' => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ) ) );
	$put( array( 'product_id' => $VM, 'variation_id' => $VMv[0], 'seller_id' => $SE, 'seller_name' => 'Asha One', 'line_total' => '300', 'status' => 'voided', 'voided_by' => $SM, 'voided_at_gmt' => gmdate( 'Y-m-d H:i:s' ), 'void_reason' => 'x', 'created_at_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) ); // A void is not a sale.
	$order = static function ( int $product, string $status, int $days_ago ) use ( &$orders ): int {
		$o = wc_create_order();
		$o->add_product( wc_get_product( $product ), 1 );
		$o->set_date_created( time() - $days_ago * DAY_IN_SECONDS );
		$o->save();
		$o->set_status( $status );
		$o->save();
		$orders[] = $o->get_id();
		return $o->get_id();
	};
	$order( $VOv[0], 'processing', 5 );  // Counted: VO is not dead.
	$order( $VMv[1], 'cancelled', 3 );   // Not counted.
	$order( $VMv[1], 'pending', 3 );     // Not counted.
	$order( $st['S3'], 'completed', 4 ); // Out of stock anyway.
	$order( $VPv[0], 'on-hold', 80 );    // Counted (on hold), but older than the in-store sale.
	// Online orders reduced stock on processing/completed/on-hold; put the fixture stock back.
	foreach ( array( $VOv[0] => 7, $st['S3'] => 0, $VP => 6 ) as $pid => $qty ) {
		wc_update_product_stock( wc_get_product( $pid ), $qty, 'set' );
	}
	$online = StockQuery::online_last_sales();
	pqbg_t( 'online orders: processing, completed and on hold count, cancelled and pending do not (read from the order line items, HPOS table)', isset( $online[ $VO . ':' . $VOv[0] ] ) && isset( $online[ $st['S3'] . ':0' ] ) && isset( $online[ $VP . ':' . $VPv[0] ] ) && ! isset( $online[ $VM . ':' . $VMv[1] ] ) );
	$h    = $hold( false );
	$dead = static fn( int $days ) => array_map( static fn( $r ) => $r['id'], StockQuery::dead( array_values( $h ), $days )['rows'] );
	$n30  = StockQuery::dead( array_values( $h ), 30 );
	$sorted = static function ( array $a ): array {
		sort( $a );
		return $a;
	};
	pqbg_t( '30 days: S4 (45 days), VP (in store 70 days, online 80), VM (never; its only sale was voided) — not S1 (10 days), not VO (online 5 days), S2 counted as new', $sorted( array( $st['S4'], $VP, $VM ) ) === $sorted( $dead( 30 ) ) && 1 === $n30['new'] );
	pqbg_t( '60 days: VP and VM; 90 days: VM only', $sorted( array( $VP, $VM ) ) === $sorted( $dead( 60 ) ) && array( $VM ) === $dead( 90 ) );
	pqbg_t( 'never sold: S2 (new, listed with its age) and VM; the in-store and online dates are shown', $sorted( array( $st['S2'], $VM ) ) === $sorted( $dead( 0 ) ) && 3 === $by( StockQuery::dead( array_values( $h ), 0 )['rows'], 'id' )[ $st['S2'] ]['age_days'] && '' !== $by( StockQuery::dead( array_values( $h ), 30 )['rows'], 'id' )[ $VP ]['last_online'] );
	$dd = ReportData::build( 'dead', array( 'costs' => false, 'days' => 30 ) );
	pqbg_t( 'the report states that online orders are counted', in_array( ReportData::online_rule(), $dd['notes'], true ) && str_contains( ReportData::online_rule(), 'online orders (processing, completed, on hold)' ) );
	wp_set_current_user( $A );
	$slow = ReportData::build( 'products', array_merge( $ctx( wp_date( 'Y-m-d', time() - 29 * DAY_IN_SECONDS ), wp_date( 'Y-m-d' ), false ), array( 'mode' => 'slow' ) ) );
	wp_set_current_user( 0 );
	$sl   = array_column( array_filter( $slow['rows'], static fn( $r ) => in_array( $post_of( $r ), array( $st['S1'], $st['S4'] ), true ) ), 'items', 'stock' );
	pqbg_t( 'slow sellers: items in stock with the fewest sold in the period first (S4: 0 in the last 30 days; S1: 4)', 'items' === $slow['default_sort'] && 'asc' === $slow['default_order'] && array( 5 => 0, 10 => 4 ) == $sl, wp_json_encode( $sl ) );

	// ------------------------------------------------------------------ CSV
	pqbg_section( 'CSV rules' );
	$pc = $csv_of( ReportData::build( 'products', $ctx( '2025-03-10', '2025-03-16', true ) ) );
	$pn = $csv_of( ReportData::build( 'products', $ctx( '2025-03-10', '2025-03-16', false ) ) );
	$ra = $csv_rows( substr( $pc, 3 ) );
	$rn = $csv_rows( substr( $pn, 3 ) );
	pqbg_t( 'UTF-8 with a byte order mark; amounts with the currency symbol in the header', "\xEF\xBB\xBF" === substr( $pc, 0, 3 ) && in_array( 'Revenue (₹)', $ra[0], true ) && in_array( 'Share of revenue (%)', $ra[0], true ) );
	pqbg_t( 'columns by capability: administrator has profit/margin/unknown-cost columns, a shop manager none', (bool) preg_grep( '/profit|margin|unknown cost/i', $ra[0] ) && ! preg_grep( '/profit|margin|cost/i', $rn[0] ) && ! str_contains( $pn, '20.00,4.0' ) );
	$rows_a = array_column( array_slice( $ra, 1 ), null, 0 );
	$scsv = $csv_rows( substr( $csv_of( ReportData::build( 'sellers', $ctx( '2025-03-10', '2025-03-16', true ) ) ), 3 ) );
	pqbg_t( 'plain decimals; a loss stays a number (Gone Seller profit -10.00); rows in the screen\'s order; the totals row last', isset( $rows_a['<script>alert(1)</script> Zeta (deleted)'] ) && '250.00' === $rows_a['<script>alert(1)</script> Zeta (deleted)'][4] && 'Gross profit (₹)' === $scsv[0][5] && '-10.00' === ( $scsv[2][5] ?? '' ) && 'Total' === end( $scsv )[0] && '1880.00' === end( $scsv )[3] );
	$put( array( 'seller_id' => $SE, 'seller_name' => '@seller', 'product_name' => '=HYPERLINK("x")', 'line_total' => '1', 'created_at_gmt' => $ist( '2025-05-01 10:00:00' ) ) );
	$inj = $csv_rows( substr( $csv_of( ReportData::build( 'products', $ctx( '2025-05-01', '2025-05-01', false ) ) ), 3 ) );
	$sin = $csv_rows( substr( $csv_of( ReportData::build( 'sellers', $ctx( '2025-05-01', '2025-05-01', false ) ) ), 3 ) );
	pqbg_t( 'formula injection neutralised in product and seller names (leading apostrophe)', "'=HYPERLINK(\"x\") (deleted)" === $inj[1][0] && "'@seller" === $sin[1][0] );
	pqbg_t( 'dates in the site timezone in the voids CSV (2025-03-11 13:00:00 for r5)', '2025-03-11 13:00:00' === ( array_values( array_filter( $csv_rows( substr( $csv_of( ReportData::build( 'voids', $ctx( '2025-03-10', '2025-03-16', false ) ) ), 3 ) ), static fn( $r ) => $r[0] === (string) $d['r5'] ) )[0][1] ?? '' ) );
	pqbg_t( 'file names: in-store-report-{report}-{from}[-to-{to}].csv; stock reports today\'s date', 'in-store-report-products-2025-03-10-to-2025-03-16.csv' === ReportsExport::filename( array_merge( $ctx( '2025-03-10', '2025-03-16', false ), array( 'tab' => 'products' ) ) ) && 'in-store-report-stock-' . wp_date( 'Y-m-d' ) . '.csv' === ReportsExport::filename( array_merge( $ctx( '2025-03-10', '2025-03-16', false ), array( 'tab' => 'stock' ) ) ) );

	// ------------------------------------------------------------------ charts
	pqbg_section( 'charts: SVG, accessible, escaped' );
	$svg = ReportChart::columns( array( array( 'label' => 'A<b>', 'value' => 10.0, 'text' => '₹10' ), array( 'label' => 'B', 'value' => -5.0, 'text' => '−₹5' ) ), 'T<script>', 'D&"', array( ReportsAdmin::class, 'axis_money' ) );
	$dom = new DOMDocument();
	pqbg_t( 'the SVG is well-formed XML with role="img", <title> and <desc>, and every text is escaped', $dom->loadXML( $svg ) && 'img' === $dom->documentElement->getAttribute( 'role' ) && str_contains( $svg, '<title id="' ) && str_contains( $svg, 'T&lt;script&gt;' ) && str_contains( $svg, 'A&lt;b&gt;' ) && ! str_contains( $svg, '<script' ) );
	pqbg_t( 'a negative value is drawn below the zero line with the loss colour; marks are paths with a rounded data end', str_contains( $svg, 'pqbg-chart__mark pqbg-chart__mark--neg' ) && 2 === substr_count( $svg, '<path class="pqbg-chart__mark' ) && str_contains( $svg, 'Q' ) );
	$bars = ReportChart::bars( array( array( 'label' => str_repeat( 'Long name ', 10 ), 'value' => 3.0, 'text' => '₹3' ) ), 'Bars', 'd', 440 );
	pqbg_t( 'bar labels are shortened with an ellipsis (the full name stays in the tooltip)', $dom->loadXML( $bars ) && str_contains( $bars, '…</text>' ) && str_contains( $bars, '<title>' . str_repeat( 'Long name ', 10 ) ) );
	$heat = ReportChart::heatmap( array_fill( 1, 7, array_fill( 0, 24, 0.0 ) ), array(), ReportData::weekdays(), 'H', 'd' );
	pqbg_t( 'heatmap: 168 focusable cells plus the legend, no colour without data (step 0)', $dom->loadXML( $heat ) && 168 === substr_count( $heat, 'class="pqbg-chart__item" tabindex="0"' ) && 168 + 1 === substr_count( $heat, 'pqbg-heat--0' ) );
	pqbg_t( 'clean axis ticks including 0', array( 0.0, 500.0, 1000.0, 1500.0, 2000.0 ) === ReportChart::ticks( 0, 1880 ) && in_array( 0.0, ReportChart::ticks( -60, 170 ), true ) );

	// ------------------------------------------------------------------ HTTP
	pqbg_section( 'HTTP: every tab, CSV and the print page; permissions (admin, shop manager, seller, customer, logged out)' );
	// Phase 10B (approved): the Dashboard tab is called Summary (&tab=dashboard still opens it; tested in the 10B suite).
	$tabs   = array( 'summary', 'sales', 'products', 'categories', 'sellers', 'peak', 'eod', 'profit', 'voids', 'stock', 'dead' );
	$week   = array( 'range' => 'custom', 'from' => '2025-03-10', 'to' => '2025-03-16' );
	$pages  = array();
	$bad    = array();
	foreach ( $tabs as $tab ) {
		$args = 'eod' === $tab ? array( 'tab' => $tab, 'range' => 'custom', 'from' => '2025-04-15', 'to' => '2025-04-15' ) : array_merge( array( 'tab' => $tab ), in_array( $tab, array( 'stock', 'dead' ), true ) ? array() : $week );
		foreach ( array( 'admin', 'sm' ) as $who ) {
			$r = $http( $who, 'GET', $rep_url( $args ) );
			$pages[ $who ][ $tab ] = $r;
			$want = 'sm' === $who && 'profit' === $tab ? 403 : 200;
			if ( $want !== $r['code'] || ( 200 === $want && ! str_contains( $r['body'], 'pqbg-reports' ) ) || preg_match( '/(Fatal error|Warning|Notice|Deprecated)<\/b>:/', $r['body'] ) ) {
				$bad[] = "$who $tab {$r['code']}";
			}
		}
	}
	pqbg_t( 'administrator: every tab 200; shop manager: every tab 200 except Profit & margin (403); no PHP errors on any page', array() === $bad, implode( ', ', $bad ) );
	$bad = array();
	foreach ( array( 'seller', 'customer', 'anon' ) as $who ) {
		foreach ( array( 'summary', 'products', 'eod', 'stock' ) as $tab ) {
			$r = $http( $who, 'GET', $rep_url( array( 'tab' => $tab ) ) );
			if ( 200 === $r['code'] || str_contains( $r['body'], 'pqbg-reports' ) || str_contains( $r['body'], 'Alpha Kurta' ) ) {
				$bad[] = "$who $tab {$r['code']}";
			}
		}
	}
	pqbg_t( 'seller, customer and logged out never see a report (redirect or 403)', array() === $bad, implode( ', ', $bad ) );
	pqbg_t( 'logged out → the login page', str_contains( $http( 'anon', 'GET', $rep_url() )['location'], 'wp-login.php' ) );
	$ab = $pages['admin'];
	$sb = $pages['sm'];
	pqbg_t( 'menu: "In-store reports" right after "In-store sales" for both (Phase 10B: in the QR & Barcodes menu); the Profit & margin tab only for the administrator', (bool) preg_match( '/page=pqbg-sales[\'"][^>]*>In-store sales<\/a><\/li>\s*<li[^>]*><a href=[\'"]admin\.php\?page=pqbg-reports[\'"]/', $ab['summary']['body'] ) && str_contains( $ab['summary']['body'], '>Profit &amp; margin<' ) && ! str_contains( $sb['summary']['body'], 'Profit &amp; margin' ) && str_contains( $sb['summary']['body'], 'In-store reports' ) );
	$leak = array();
	foreach ( $sb as $tab => $r ) {
		if ( 'profit' === $tab ) {
			continue;
		}
		foreach ( array( 'Gross profit', '>Margin<', 'Value at cost', 'unknown cost', '987.65', '4,938.25', 'Cost price' ) as $needle ) {
			if ( str_contains( $r['body'], $needle ) ) {
				$leak[] = "$tab: $needle";
			}
		}
	}
	pqbg_t( 'shop manager: no profit, margin, cost, value at cost, unknown-cost note or planted cost anywhere (cards, columns, charts, notes)', array() === $leak, implode( ', ', $leak ) );
	pqbg_t( 'administrator sees them: the profit card, the value at cost and the planted cost value', str_contains( $ab['summary']['body'], 'Gross profit' ) && str_contains( $ab['stock']['body'], 'Value at cost' ) && str_contains( $ab['stock']['body'], '4,938.25' ) );
	$r = $http( 'sm', 'GET', $rep_url( array_merge( $week, array( 'tab' => 'products', 'orderby' => 'profit' ) ) ) );
	$r2 = $http( 'sm', 'GET', $rep_url( array( 'tab' => 'stock', 'orderby' => 'cost_value' ) ) );
	pqbg_t( 'shop manager asking to sort by a cost column → 403 (refused, not ignored)', 403 === $r['code'] && 403 === $r2['code'] );
	pqbg_t( 'figures on the pages: sales tab ₹1,880.00 total; end of day "Cash expected in drawer" ₹490.00; dashboard compares with 3–9 Mar', str_contains( $ab['sales']['body'], '₹1,880.00' ) && (bool) preg_match( '/Cash expected in drawer<\/div><div class="pqbg-card__value">₹490.00/', $ab['eod']['body'] ) && str_contains( $ab['summary']['body'], 'Compared with 3 Mar – 9 Mar 2025' ) );
	pqbg_t( 'escaping: the <script> product name, the category "&" and chart labels are escaped (HTML and SVG)', ! str_contains( $ab['products']['body'], '<script>alert(1)' ) && str_contains( $ab['products']['body'], '&lt;script&gt;alert(1)&lt;/script&gt; Zeta (deleted)' ) && str_contains( $ab['categories']['body'], 'PQBG9B Sale &amp; Offers' ) && ! str_contains( $ab['categories']['body'], '&amp;amp;' ) && ! str_contains( $ab['summary']['body'], '<script>alert(1)' ) );
	pqbg_t( 'every report page states the counting rules (in-store only, completed only, site timezone)', ! array_filter( $ab, static fn( $r ) => 200 === $r['code'] && ! str_contains( $r['body'], 'pqbg-reports__rules' ) ) && str_contains( $ab['summary']['body'], 'online orders are in WooCommerce → Analytics' ) );

	// CSV over HTTP.
	$csv_a = $link_of( $ab['products']['body'], 'pqbg_report_csv' );
	$csv_s = $link_of( $sb['products']['body'], 'pqbg_report_csv' );
	$h1    = $http( 'admin', 'GET', $csv_a );
	pqbg_t( 'CSV link of the view (GET, nonce, the same filters) downloads it with the right headers', 200 === $h1['code'] && str_contains( $csv_a, '_wpnonce=' ) && str_contains( $csv_a, 'from=2025-03-10' ) && "\xEF\xBB\xBF" === substr( $h1['body'], 0, 3 ) && 'text/csv; charset=utf-8' === ( $h1['headers']['content-type'] ?? '' ) && 'attachment; filename="in-store-report-products-2025-03-10-to-2025-03-16.csv"' === ( $h1['headers']['content-disposition'] ?? '' ) && 'nosniff' === ( $h1['headers']['x-content-type-options'] ?? '' ) && str_contains( $h1['headers']['cache-control'] ?? '', 'no-store' ) );
	$h2 = $http( 'sm', 'GET', $csv_s );
	pqbg_t( 'shop manager CSV: the same rows, no cost columns', 200 === $h2['code'] && count( $csv_rows( substr( $h1['body'], 3 ) ) ) === count( $csv_rows( substr( $h2['body'], 3 ) ) ) && ! preg_grep( '/profit|margin|cost/i', $csv_rows( substr( $h2['body'], 3 ) )[0] ) );
	$bad = array();
	foreach ( $tabs as $tab ) {
		if ( 'summary' === $tab ) {
			continue;
		}
		$ua = $link_of( $ab[ $tab ]['body'], 'pqbg_report_csv' );
		$xa = $http( 'admin', 'GET', $ua );
		if ( 200 !== $xa['code'] || "\xEF\xBB\xBF" !== substr( $xa['body'], 0, 3 ) ) {
			$bad[] = "admin $tab {$xa['code']}";
		}
		if ( 'profit' === $tab ) {
			$xs = $http( 'sm', 'GET', $ua );
			if ( 403 !== $xs['code'] ) {
				$bad[] = "sm profit {$xs['code']}";
			}
			continue;
		}
		$us = $link_of( $sb[ $tab ]['body'], 'pqbg_report_csv' );
		$xs = $http( 'sm', 'GET', $us );
		if ( 200 !== $xs['code'] || preg_grep( '/profit|margin|cost/i', $csv_rows( substr( $xs['body'], 3 ) )[0] ?? array() ) ) {
			$bad[] = "sm $tab {$xs['code']}";
		}
	}
	pqbg_t( 'every report\'s CSV: administrator 200; shop manager 200 without cost columns, the profit CSV 403 even with an administrator\'s link', array() === $bad, implode( ', ', $bad ) );
	pqbg_t( 'CSV: bad or missing nonce → 403; seller and customer → 403; logged out → no data; POST → 405; HEAD → headers only', 403 === $http( 'admin', 'GET', add_query_arg( '_wpnonce', 'abc', $csv_a ) )['code'] && 403 === $http( 'admin', 'GET', remove_query_arg( '_wpnonce', $csv_a ) )['code'] && 403 === $http( 'seller', 'GET', $csv_a )['code'] && 403 === $http( 'customer', 'GET', $csv_a )['code'] && 200 !== $http( 'anon', 'GET', $csv_a )['code'] && ! str_contains( $http( 'anon', 'GET', $csv_a )['body'], 'Alpha Kurta' ) && 405 === $http( 'admin', 'POST', $csv_a, array( 'x' => '1' ) )['code'] && '' === $http( 'admin', 'HEAD', $csv_a )['body'] );
	pqbg_t( 'CSV: a shop manager\'s cost sort in the URL → 403', 403 === $http( 'sm', 'GET', add_query_arg( 'orderby', 'profit', $csv_s ) )['code'] );

	// Print page.
	$pu = $link_of( $ab['eod']['body'], 'pqbg_report_print' );
	$pa = $http( 'admin', 'GET', $pu );
	$ps = $http( 'sm', 'GET', $link_of( $sb['eod']['body'], 'pqbg_report_print' ) );
	$ok = true;
	foreach ( array_merge( ScanRoute::security_headers(), ReportPrint::headers() ) as $name => $value ) {
		$ok = $ok && ( $pa['headers'][ strtolower( $name ) ] ?? null ) === $value;
	}
	pqbg_t( 'print page: administrator and shop manager 200, the day\'s figures (cash expected ₹490.00, UPI net −₹100.00), Print button', 200 === $pa['code'] && 200 === $ps['code'] && str_contains( $pa['body'], 'Cash expected in drawer' ) && str_contains( $pa['body'], '₹490.00' ) && str_contains( $pa['body'], 'id="pqbg-print-button"' ) && str_contains( $pa['body'], 'Voided in this period by PQBG Manager: Cash ₹1,000.00 (1 sale)' ) );
	pqbg_t( 'print page: security headers and a CSP with no inline style or script; no inline style attributes; only pqbg-print.js', $ok && ! preg_match( '/\sstyle=/', $pa['body'] ) && ! preg_match( '/<style/', $pa['body'] ) && 1 === preg_match_all( '/<script/', $pa['body'] ) && str_contains( $pa['body'], 'assets/pqbg-print.js' ) && ! str_contains( $pa['body'], 'wp-includes' ) );
	pqbg_t( 'print page: bad nonce 403, seller and customer 403, logged out no data, POST 405', 403 === $http( 'admin', 'GET', add_query_arg( '_wpnonce', 'abc', $pu ) )['code'] && 403 === $http( 'seller', 'GET', $pu )['code'] && 403 === $http( 'customer', 'GET', $pu )['code'] && ! str_contains( $http( 'anon', 'GET', $pu )['body'], 'Cash expected' ) && 405 === $http( 'admin', 'POST', $pu, array( 'x' => '1' ) )['code'] );

	pqbg_section( 'GET and HEAD never write' );
	$before = $state();
	foreach ( $tabs as $tab ) {
		$http( 'admin', 'GET', $rep_url( array( 'tab' => $tab ) ) );
		$http( 'sm', 'HEAD', $rep_url( array( 'tab' => $tab ) ) );
		if ( 'summary' !== $tab ) {
			$http( 'admin', 'GET', $link_of( $ab[ $tab ]['body'], 'pqbg_report_csv' ) );
		}
	}
	$http( 'admin', 'GET', $pu );
	$http( 'admin', 'HEAD', $pu );
	pqbg_t( 'every tab, CSV and the print page (GET and HEAD) change nothing (sales, costs, stock, prices, posts, codes, options, orders)', $before === $state() );

	// ------------------------------------------------------------------ performance
	pqbg_section( 'performance: 5,000 sales (50,000 with PQBG_STRESS=1) and 1,000 sellable items' );
	$t0    = microtime( true );
	$items = array();
	$cats  = array();
	for ( $i = 0; $i < 10; $i++ ) {
		$cats[] = (int) wp_insert_term( 'PQBG9B Perf ' . $i, 'product_cat', 0 === $i ? array() : array( 'parent' => $cats[0] ) )['term_id'];
	}
	for ( $i = 0; $i < 300; $i++ ) {
		$pid     = $make_simple( $A, array( 'manage_stock' => true, 'stock_quantity' => $i % 7, 'regular_price' => (string) ( 300 + $i ) ), array( $cats[ $i % 10 ] ) );
		$items[] = array( $pid, 0 );
	}
	for ( $i = 0; $i < 70; $i++ ) {
		list( $pp, $vv ) = $make_variable( $A, array(), array_fill( 0, 10, array( 'manage_stock' => true, 'stock_quantity' => 3, 'regular_price' => '499' ) ), array( $cats[ $i % 10 ] ) );
		foreach ( $vv as $v ) {
			$items[] = array( $pp, $v );
		}
	}
	pqbg_t( '1,000 sellable items created (300 simple, 70 × 10 variations, 10 categories)', 1000 === count( $items ), sprintf( '%.1f s', microtime( true ) - $t0 ) );
	// The 1,070 product saves queued WooCommerce attribute-lookup jobs, which Apache's queue runner
	// would process during the timings; they concern only these test products, so remove them first.
	$removed = pqbg_test_as_cleanup( $as_mark );
	printf( "   (removed %d background jobs of the test products before timing)\n", $removed );
	$methods = array_merge( array_fill( 0, 45, 'cash' ), array_fill( 0, 35, 'upi' ), array_fill( 0, 15, 'card' ), array_fill( 0, 3, 'other' ), array( null, null ) );
	$sellers = array_merge( array( $SE, $SE3 ), range( 900001, 900008 ) );
	/** Inserts $n synthetic sales over the last 90 days (the same mix at every volume). */
	$fill = static function ( int $n ) use ( $wpdb, $S, $SM, $PERF_NOTE, $methods, $sellers, $items ): int {
		mt_srand( 9 );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $S WHERE note = %s", $PERF_NOTE ) );
		$vals   = array();
		$now_ts = time();
		for ( $i = 0; $i < $n; $i++ ) {
			$r      = mt_rand( 1, 100 );
			$status = $r <= 90 ? 'completed' : ( $r <= 96 ? 'voided' : 'failed' );
			$q      = mt_rand( 1, 3 );
			$p      = mt_rand( 300, 5000 );
			$it     = $items[ mt_rand( 0, 999 ) ];
			$c      = mt_rand( 1, 10 ) <= 7 ? number_format( $p * 0.6, 2, '.', '' ) : '';
			$vals[] = $wpdb->prepare( '(%s, %d, %d, %d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)', wp_generate_uuid4(), $it[0], $it[1], $sellers[ mt_rand( 0, 9 ) ], $q, $p, $p * $q, 'INR', 'Perf item ' . $it[0], $status, gmdate( 'Y-m-d H:i:s', $now_ts - mt_rand( 0, 89 * 86400 ) ), (string) $methods[ mt_rand( 0, 99 ) ], $c, 'Perf Seller', $PERF_NOTE );
			if ( 1000 === count( $vals ) || $i === $n - 1 ) {
				$wpdb->query( "INSERT INTO $S (request_id, product_id, variation_id, seller_id, quantity, unit_price, line_total, currency, product_name, status, created_at_gmt, payment_method, unit_cost, seller_name, note) VALUES " . implode( ',', $vals ) );
				$vals = array();
			}
		}
		$wpdb->query( $wpdb->prepare( "UPDATE $S SET payment_method = NULLIF(payment_method, ''), unit_cost = NULLIF(unit_cost, 0), voided_by = IF(status = 'voided', %d, NULL), voided_at_gmt = IF(status = 'voided', created_at_gmt, NULL), void_reason = IF(status = 'voided', 'perf', NULL), failure_code = IF(status = 'failed', 'sold_online', NULL) WHERE note = %s", $SM, $PERF_NOTE ) );
		$wpdb->query( "ANALYZE TABLE $S" );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $S WHERE note = %s", $PERF_NOTE ) );
	};
	$p90 = ReportPeriod::resolve( 'last90' );
	$p12 = ReportPeriod::resolve( 'last12m' );
	/** In-process timings (best of 3, object cache flushed) of the dashboard and every report. */
	$time_all = static function ( string $volume ) use ( $time_ms, $p90, $p12 ): array {
		$out = array();
		foreach ( array( '90 days' => $p90, '12 months' => $p12 ) as $label => $per ) {
			$tm = array( 'summary' => $time_ms( static fn() => ReportsAdmin::dashboard_data( $per, true ) ) );
			foreach ( array( 'sales', 'products', 'categories', 'sellers', 'peak', 'eod', 'profit', 'voids' ) as $rep ) {
				$tm[ $rep ] = $time_ms( static fn() => ReportData::build( $rep, array( 'period' => $per, 'costs' => true, 'by' => 'product' ) ) );
			}
			$tm['slow sellers'] = $time_ms( static fn() => ReportData::build( 'products', array( 'period' => $per, 'costs' => false, 'mode' => 'slow' ) ) );
			$tm['stock']        = $time_ms( static fn() => ReportData::build( 'stock', array( 'costs' => true, 'state' => '', 'cat' => 0 ) ) );
			$tm['dead stock']   = $time_ms( static fn() => ReportData::build( 'dead', array( 'costs' => true, 'days' => 30 ) ) );
			foreach ( $tm as $k => $ms ) {
				printf( "   TIMING in-process %-13s %-9s %-12s %8.1f ms\n", $volume, $label, $k, $ms );
			}
			arsort( $tm );
			$out[ $label ] = array( (float) reset( $tm ), (string) key( $tm ) );
		}
		return $out;
	};

	// Realistic volume: ~55 sales a day. The original targets apply here.
	$t0 = microtime( true );
	pqbg_t( 'realistic volume: 5,000 synthetic sales over 90 days (~55 a day; the same mix of sellers, methods, statuses and items)', 5000 === $fill( 5000 ), sprintf( '%.1f s', microtime( true ) - $t0 ) );
	$real = $time_all( '5,000 sales' );
	pqbg_t( 'realistic volume, in-process: the Summary and every report under 1 s for 90 days', $real['90 days'][0] < 1000, sprintf( 'slowest %s %.0f ms', $real['90 days'][1], $real['90 days'][0] ) );
	pqbg_t( 'realistic volume, in-process: the Summary and every report under 2 s for 12 months', $real['12 months'][0] < 2000, sprintf( 'slowest %s %.0f ms', $real['12 months'][1], $real['12 months'][0] ) );

	$med = static function ( string $url ) use ( $http, $median ): float {
		$t = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$t[] = $http( 'admin', 'GET', $url )['time'] * 1000;
		}
		return $median( $t );
	};
	/** HTTP timings of the heaviest pages over an empty wp-admin page (median of 3). */
	$http_over = static function ( string $volume ) use ( $med, $rep_url, $p90, $p12 ): array {
		$empty = $med( admin_url( 'admin.php?page=pqbg-sales&range=custom&from=2000-01-01&to=2000-01-01' ) );
		printf( "   TIMING HTTP empty wp-admin page (baseline) %8.1f ms (median of 3)\n", $empty );
		$over = array();
		foreach ( array( '90 days' => $p90, '12 months' => $p12 ) as $label => $per ) {
			foreach ( array( 'summary', 'sales', 'products', 'sellers', 'peak', 'profit' ) as $tab ) {
				$ms = $med( $rep_url( array( 'tab' => $tab, 'range' => 'custom', 'from' => $per['from'], 'to' => $per['to'] ) ) );
				printf( "   TIMING HTTP %s %-9s %-10s %8.1f ms (median of 3; %+.0f ms over the empty page)\n", $volume, $label, $tab, $ms, $ms - $empty );
				$over[ $label ][] = $ms - $empty;
			}
		}
		return $over;
	};
	$over = $http_over( '5,000 sales' );
	pqbg_t( 'realistic volume, HTTP: each page adds under 1 s (90 days) and under 2 s (12 months) to an empty wp-admin page', max( $over['90 days'] ) < 1000 && max( $over['12 months'] ) < 2000, sprintf( 'max +%.0f / +%.0f ms', max( $over['90 days'] ), max( $over['12 months'] ) ) );

	// Stress volume: 50,000 sales in 90 days (~550 a day), the Phase 9A dataset. A regression guard at
	// 2 s for both ranges (decision of 2026-09-26: the dashboard's 1 s for 90 days is not met at this volume).
	// Phase 10B (owner's decision): opt-in with PQBG_STRESS=1; it runs in Phase 11 (hardening) and once before launch.
	if ( '1' === getenv( 'PQBG_STRESS' ) ) {
		$t0 = microtime( true );
		pqbg_t( 'stress volume: 50,000 synthetic sales over 90 days (90/6/4 % completed/voided/failed, 70 % with a cost)', 50000 === $fill( 50000 ), sprintf( '%.1f s', microtime( true ) - $t0 ) );
		$big = $time_all( '50,000 sales' );
		pqbg_t( 'stress volume (regression guard), in-process: the Summary and every report under 2 s for 90 days and for 12 months', $big['90 days'][0] < 2000 && $big['12 months'][0] < 2000, sprintf( 'slowest %s %.0f ms / %s %.0f ms', $big['90 days'][1], $big['90 days'][0], $big['12 months'][1], $big['12 months'][0] ) );
		$over = $http_over( '50,000 sales' );
		pqbg_t( 'stress volume (regression guard), HTTP: each page adds under 2 s to an empty wp-admin page (90 days and 12 months)', max( $over['90 days'] ) < 2000 && max( $over['12 months'] ) < 2000, sprintf( 'max +%.0f / +%.0f ms', max( $over['90 days'] ), max( $over['12 months'] ) ) );
	} else {
		echo "   (the 50,000-sale stress checks are opt-in: PQBG_STRESS=1; they run in Phase 11 and before launch)\n";
	}
	$explain = static function ( string $sql ) use ( $wpdb ): string {
		$x = $wpdb->get_row( 'EXPLAIN ' . $sql, ARRAY_A );
		return sprintf( 'key=%s rows=%s %s', $x['key'] ?? 'NULL', $x['rows'] ?? '?', $x['Extra'] ?? '' );
	};
	$w90 = SalesQuery::where( ReportPeriod::where_filters( $p90 ) );
	foreach ( array(
		'summary (status × method × seller)' => "SELECT s.status, s.payment_method, s.seller_id, COUNT(*) FROM $S s WHERE $w90 GROUP BY s.status, s.payment_method, s.seller_id",
		'products (completed)'               => "SELECT s.product_id, s.variation_id, COUNT(*) FROM $S s WHERE $w90 AND s.status = 'completed' GROUP BY s.product_id, s.variation_id",
		'end of day (one day)'               => "SELECT s.seller_id, s.payment_method, COUNT(*) FROM $S s WHERE " . SalesQuery::where( ReportPeriod::where_filters( ReportPeriod::resolve( 'today' ) ) ) . " AND s.status = 'completed' GROUP BY s.seller_id, s.payment_method",
		'refunds (voided in the day)'        => $wpdb->prepare( "SELECT * FROM $S s WHERE s.status = 'voided' AND s.voided_at_gmt >= %s AND s.voided_at_gmt < %s AND s.created_at_gmt < %s", ReportPeriod::resolve( 'today' )['start'], ReportPeriod::resolve( 'today' )['end'], ReportPeriod::resolve( 'today' )['start'] ),
	) as $label => $sql ) {
		printf( "   EXPLAIN %-36s %s\n", $label, $explain( $sql ) );
	}
	pqbg_t( 'no new index was needed (schema v4 adds only the void_restock column; 10 indexes as in v3)', 10 === count( array_unique( $wpdb->get_col( "SHOW INDEX FROM $S", 2 ) ) ) );
	pqbg_t( 'reconciliation on every synthetic row (5,000, or 50,000 with PQBG_STRESS=1): Summary cards = Phase 9A totals; products, sellers and categories sum to the same revenue', ( static function () use ( $p90 ) {
		$f  = ReportPeriod::where_filters( $p90 );
		$t  = SalesQuery::totals( $f )['all']['revenue'];
		$ds = ReportsAdmin::dashboard_data( $p90, true )['now']['revenue'];
		$pr = ReportsQuery::money( (string) array_sum( array_map( static fn( $r ) => (float) $r['revenue'], ReportsQuery::products( $f, false ) ) ) );
		$sl = ReportsQuery::money( (string) array_sum( array_map( static fn( $r ) => (float) $r['revenue'], ReportsQuery::sellers( $f, false ) ) ) );
		$ct = ReportsQuery::categories( $f, false )['total']['revenue'];
		return abs( (float) $t - (float) $ds ) < 0.01 && abs( (float) $t - (float) $pr ) < 0.01 && abs( (float) $t - (float) $sl ) < 0.01 && abs( (float) $t - (float) $ct ) < 0.01;
	} )() );

	// ------------------------------------------------------------------ scope
	pqbg_section( 'scope' );
	$src   = static function ( string $file ): string {
		$code = '';
		foreach ( token_get_all( (string) file_get_contents( PQBG_PLUGIN_DIR . $file ) ) as $t ) {
			$code .= is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t;
		}
		return $code;
	};
	$new   = array( 'includes/ReportPeriod.php', 'includes/ReportsQuery.php', 'includes/StockQuery.php', 'includes/ReportData.php', 'includes/ReportsAdmin.php', 'includes/ReportTable.php', 'includes/ReportChart.php', 'includes/ReportsExport.php', 'includes/ReportPrint.php', 'templates/pqbg-report-print.php' );
	$all   = implode( "\n", array_map( $src, $new ) );
	pqbg_t( 'the report classes never write ($wpdb insert/update/query/delete/replace, update_option, update_post_meta, wc_update_product_stock, save())', ! preg_match( '/\$wpdb->(insert|update|query|delete|replace)\b|update_option|update_post_meta|add_post_meta|delete_post_meta|wc_update_product_stock|->save\(|set_transient/', $all ) );
	pqbg_t( 'orders are only read (no order creation, update or status change)', ! preg_match( '/wc_create_order|wc_get_order|->set_status|->update_status/', $all ) && str_contains( $src( 'includes/StockQuery.php' ), 'OrderUtil::get_table_for_orders()' ) );
	pqbg_t( 'costs only through CostPrice::get() and only behind the $costs flag (no cost meta key in the report classes)', ! str_contains( $all, '_pqbg_cost_price' ) && str_contains( $src( 'includes/StockQuery.php' ), 'if ( $costs )' ) );
	pqbg_t( 'still no nopriv handlers, AJAX actions, REST routes, shortcodes or rewrite rules', ! preg_match( '/admin_post_nopriv|wp_ajax_|register_rest_route|add_shortcode|add_rewrite/', $all ) );
	pqbg_t( 'the report hooks are admin-only (not registered in this CLI request) and the only hooks are admin_enqueue_scripts and two admin_post handlers (Phase 10B: the menu and the load- hook moved to AdminMenu)', false === has_action( 'admin_menu', array( AdminMenu::class, 'add_pages' ) ) && 3 === preg_match_all( '/add_action\(/', $src( 'includes/ReportsAdmin.php' ) ) && str_contains( $src( 'includes/AdminMenu.php' ), "'load'   => array( ReportsAdmin::class, 'load' )" ) && ! preg_match( '/add_filter\(/', $all ) && ! preg_match( '/add_action\(/', implode( "\n", array_map( $src, array_diff( $new, array( 'includes/ReportsAdmin.php' ) ) ) ) ) );
	pqbg_t( 'the print page loads no WordPress head/footer and no inline script', ! preg_match( '/wp_head|wp_footer|wp_enqueue/', $src( 'templates/pqbg-report-print.php' ) ) );

} catch ( Throwable $e ) {
	pqbg_t( 'suite ran without an exception', false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
	// ------------------------------------------------------------------ cleanup
	pqbg_section( 'cleanup' );
	wp_set_current_user( 0 );
	$handles = array();
	foreach ( $orders as $oid ) {
		$o = wc_get_order( $oid );
		if ( $o ) {
			$o->delete( true );
		}
	}
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_ID > %d", $start_cmt ) ) as $cid ) {
		wp_delete_comment( (int) $cid, true ); // Order notes of the test orders.
	}
	foreach ( array( 'wc_order_stats', 'wc_order_product_lookup', 'wc_order_tax_lookup', 'wc_order_coupon_lookup' ) as $lookup ) {
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $lookup ) ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}{$lookup} WHERE order_id > %d", $start_ord ) );
		}
	}
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
	$wpdb->query( $wpdb->prepare( "DELETE FROM $C WHERE id > %d", $start_c ) );
	$wpdb->query( 'ALTER TABLE ' . $C . ' AUTO_INCREMENT = ' . ( $start_c + 1 ) );
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT t.term_id FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'product_cat' WHERE t.term_id > %d", $start_term ) ) as $term ) {
		wp_delete_term( (int) $term, 'product_cat' );
	}
	foreach ( $user_ids as $uid ) {
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d", $uid ) ) as $own ) {
			wp_delete_post( (int) $own, true );
		}
		wp_delete_user( $uid );
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
	pqbg_t( 'cleanup: orders, order items, order notes and Analytics lookup rows of the test orders removed (HPOS)', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id > %d", $start_ord ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_id > %d", $start_oi ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_ID > %d", $start_cmt ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_stats WHERE order_id > %d", $start_ord ) ) );
	pqbg_t( 'cleanup: no test categories, cost meta or users left', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'product_cat' AND term_id > %d", $start_term ) ) && $base_cost === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $PM WHERE meta_key = %s", CostPrice::META_KEY ) ) && $base_user === (int) count_users()['total_users'] );
	pqbg_t( 'cleanup: options byte-identical (settings, DB version, week start, stock thresholds, category cache)', ! array_filter( $saved, static fn( $raw, $name ) => $raw !== $raw_option( $name ), ARRAY_FILTER_USE_BOTH ) );
	pqbg_t( 'cleanup: the posts AUTO_INCREMENT only moved by the posts this suite created (no jump)', $posts_ai() - $start_ai < 5000, $start_ai . ' → ' . $posts_ai() );
	pqbg_t( 'cleanup: no temporary tables left', array() === $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $mig_prefix ) . '%' ) ) );
	pqbg_test_as_check( $as_mark );
}

pqbg_test_done();
