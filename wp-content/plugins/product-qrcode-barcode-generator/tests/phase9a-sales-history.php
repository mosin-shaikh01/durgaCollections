<?php
/**
 * Phase 9A suite: sales history, payment method and cost price.
 *
 * Schema v3 migration and the pqbg_view_costs capability; the payment method on
 * the sale form and in the sale flow (required, nothing pre-selected, disabled
 * and invalid methods refused, the single-method case, idempotency with a
 * changed method, the pending journal row); the cost price field (validation,
 * inheritance, saving over HTTP and AJAX, shop managers' saves never touching
 * it), its snapshot at the moment of sale, and that it is never exposed (edit
 * screen and variations AJAX as a shop manager, WooCommerce REST products and
 * variations, Store API, storefront, product CSV export and import, Duplicate,
 * WXR export and import, scan, sale and My sales pages, labels, history and CSV
 * for shop managers); deletion paths leave no orphaned cost meta; the seller name
 * snapshot and deleted users; history filters (site-timezone day boundaries),
 * sorting, pagination, totals and profit with unknown costs; the CSV (BOM,
 * formula injection, columns by capability, streaming); voiding (reason, restock
 * on and off, lock, second void, permissions); My sales (own sales only, ranges,
 * totals per method, no cost); permissions for every screen and handler over
 * real HTTP; escaping; headers; GET never writes; the 50,000-row performance
 * dataset with EXPLAIN; scope.
 *
 * Fixture sale rows are inserted directly into pqbg_sales (test data only, all
 * marked in `note`); real sales go through SaleService. Everything created is
 * removed at the end.
 *
 *   php tests/phase9a-sales-history.php
 *   php tests/phase9a-sales-history.php --worker hold <holder> <seconds>   (internal)
 *
 * Optional: PQBG_WXR_IMPORTER = path to wordpress-importer.php of the official
 * WordPress Importer plugin, kept OUTSIDE the site (never activated); without it
 * the real WXR import check is skipped.
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/bootstrap.php';

// Worker: holds a stock lock (to test "busy" on void).
if ( isset( $argv[1] ) && '--worker' === $argv[1] ) {
	pqbg_test_load_wp();
	$ok = ProductQrBarcode\StockLock::acquire( (int) $argv[3], 0 );
	echo $ok ? "held\n" : "not held\n";
	fflush( STDOUT );
	usleep( (int) ( (float) $argv[4] * 1000000 ) );
	ProductQrBarcode\StockLock::release( (int) $argv[3] );
	exit( 0 );
}

pqbg_test_load_wp();
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/post.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use ProductQrBarcode\{CodeRepository, CostPrice, Install, PaymentMethods, Permissions, Plugin, PrintCache, PrintJob, PrintPage, SaleRepository, SaleService, SalePresenter, SalesExport, SalesQuery, ScanRoute, ScanScreen, ScanUrl, Schema, Settings};

global $wpdb;

$C          = Schema::codes_table();
$S          = Schema::sales_table();
$PM         = $wpdb->postmeta;
$KEY        = CostPrice::META_KEY;
$NOTE       = 'pqbg-9a-fixture';
$PERF_NOTE  = 'pqbg-9a-perf';
$max        = static fn( string $table, string $col ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX($col), 0) FROM $table" );
$start_c    = $max( $C, 'id' );
$start_s    = $max( $S, 'id' );
$as_mark    = pqbg_test_as_mark(); // Action Scheduler cleanup, see bootstrap.php.
$start_post = $max( $wpdb->posts, 'ID' );
$start_user = $max( $wpdb->users, 'ID' );
$posts_ai   = static fn() => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->posts ) );
$start_ai   = $posts_ai();
$base_cost  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $PM WHERE meta_key = %s", $KEY ) );
$base_user  = (int) count_users()['total_users'];
$raw_option = static fn( string $name ) => $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
$saved      = array();
foreach ( array( Plugin::SETTINGS_OPTION, Install::DB_VERSION_OPTION, 'woocommerce_meta_box_errors', PrintCache::INDEX_OPTION ) as $name ) {
	$saved[ $name ] = $raw_option( $name );
}
$user_ids   = array();
$pw         = array();
$home       = untrailingslashit( home_url() );
$mig_prefix = $wpdb->prefix . 'pqbg9am_';
$timings    = array();

add_filter( 'pre_wp_mail', '__return_false' ); // Local mail is not configured; a failing mail() takes about 2 s.
// wp_die() in an in-process call (e.g. an importer) must never exit before the cleanup in `finally`.
add_filter(
	'wp_die_handler',
	static fn() => static function ( $message ) {
		throw new RuntimeException( 'wp_die: ' . wp_strip_all_tags( is_wp_error( $message ) ? $message->get_error_message() : (string) $message ) );
	}
);

// Leftovers of an interrupted earlier run (marked rows only).
$wpdb->query( $wpdb->prepare( "DELETE FROM $S WHERE note IN (%s, %s)", $NOTE, $PERF_NOTE ) );

$sync    = static fn() => wp_cache_flush();
$stock   = static fn( int $id ) => SaleRepository::read_stock( $id );
$code_of = static function ( int $id ): string {
	$row = CodeRepository::find_active_for_product( $id );
	return is_array( $row ) ? (string) $row['code'] : '';
};
$url      = static fn( string $code = '' ) => ScanUrl::site_url( $code );
$mine_url = static fn( string $range = '' ) => ScanUrl::my_sales_url( $range );
$hist_url = static fn( array $args = array() ) => add_query_arg( array_map( 'rawurlencode', array_merge( array( 'page' => 'pqbg-sales' ), $args ) ), admin_url( 'admin.php' ) );
$row      = static fn( int $id ) => SaleRepository::find( $id );
$cost_rows = static fn( int $post_id ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $PM WHERE post_id = %d AND meta_key = %s", $post_id, $KEY ) );
$raw_cost  = static fn( int $post_id ) => $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM $PM WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 1", $post_id, $KEY ) );
$orphans   = static fn() => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $PM pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.ID IS NULL", $KEY ) );
$set_methods = static function ( ?array $methods ) {
	$s = get_option( Plugin::SETTINGS_OPTION, array() );
	$s = is_array( $s ) ? $s : array();
	if ( null === $methods ) {
		unset( $s['payment_methods'] );
	} else {
		$s['payment_methods'] = $methods;
	}
	update_option( Plugin::SETTINGS_OPTION, $s, false );
};
/** Checksum of everything a GET must not change. */
$state = static function () use ( $wpdb, $S, $PM, $KEY ): string {
	return md5(
		implode(
			'|',
			array(
				(string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, status, COALESCE(payment_method, ''), COALESCE(unit_cost, ''), COALESCE(seller_name, ''), COALESCE(voided_by, ''), COALESCE(void_reason, ''), COALESCE(stock_after, '')))), 0)) FROM $S" ),
				(string) $wpdb->get_var( $wpdb->prepare( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT(post_id, meta_value))), 0)) FROM $PM WHERE meta_key IN (%s, '_stock', '_stock_status', '_price')", $KEY ) ),
				(string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', MAX(ID)) FROM {$wpdb->posts}" ),
				(string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Plugin::SETTINGS_OPTION ) ),
				(string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders" ),
			)
		)
	);
};

// HTTP client: a fresh connection per request (see the Phase 6 notes about this XAMPP's php8ts.dll).
$handles = array();
$http    = static function ( string $who, string $method, string $url, array|string|null $post = null, array $headers = array() ) use ( &$handles ): array {
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
			CURLOPT_CUSTOMREQUEST  => in_array( $method, array( 'GET', 'POST', 'HEAD' ), true ) ? null : $method,
			CURLOPT_POST           => 'POST' === $method,
			CURLOPT_HTTPGET        => 'GET' === $method,
			CURLOPT_HTTPHEADER     => $headers,
		)
	);
	if ( 'POST' === $method || ( null !== $post && ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) ) {
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
	curl_setopt( $ch, CURLOPT_HTTPHEADER, array() );
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
$notices_in = static function ( string $body ): array {
	preg_match_all( '/<p class="pqbg-scan__notice[^"]*"[^>]*>(.*?)<\/p>/s', $body, $m );
	return array_map( static fn( $s ) => html_entity_decode( $s, ENT_QUOTES, 'UTF-8' ), $m[1] );
};
$has_notice = static fn( array $r, string $text ) => in_array( $text, $notices_in( $r['body'] ), true );
/** The sale form on a page: hidden fields, radios, the selected quantity. */
$form_of = static function ( string $body ): ?array {
	if ( ! preg_match( '/<form class="pqbg-scan__sell" method="post" action="([^"]*)">(.*?)<\/form>/s', $body, $m ) ) {
		return null;
	}
	$fields = array();
	preg_match_all( '/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $m[2], $h, PREG_SET_ORDER );
	foreach ( $h as $x ) {
		$fields[ $x[1] ] = html_entity_decode( $x[2], ENT_QUOTES, 'UTF-8' );
	}
	preg_match_all( '/<input type="radio" name="payment_method" value="([^"]*)"([^>]*)>/', $m[2], $r, PREG_SET_ORDER );
	$radios = array();
	foreach ( $r as $x ) {
		$radios[ $x[1] ] = array(
			'checked'  => str_contains( $x[2], 'checked' ),
			'required' => str_contains( $x[2], 'required' ),
		);
	}
	return array(
		'fields'   => $fields,
		'radios'   => $radios,
		'selected' => preg_match( '/<option value="([0-9]+)" selected/', $m[2], $s ) ? (int) $s[1] : null,
		'html'     => $m[0],
	);
};
$get_form = static function ( string $who, string $code ) use ( $http, $url, $form_of ): ?array {
	$r = $http( $who, 'GET', $url( $code ) );
	return 200 === $r['code'] ? $form_of( $r['body'] ) : null;
};
$post_form = static function ( string $who, string $code, array $form, array $fields ) use ( $http, $url ): array {
	$data = array_filter( array_merge( $form['fields'], array( 'quantity' => '1' ), $fields ), static fn( $v ) => null !== $v );
	return $http( $who, 'POST', $url( $code ), $data );
};
$sale_id_of = static function ( array $r ): int {
	parse_str( (string) wp_parse_url( $r['location'], PHP_URL_QUERY ), $q );
	return isset( $q['sale'] ) ? (int) $q['sale'] : 0;
};
/** Value of the first <input name="$name"> in $html. */
$field = static function ( string $html, string $name ): string {
	preg_match_all( '/<input\b[^>]*>/i', $html, $m );
	foreach ( $m[0] as $tag ) {
		if ( preg_match( '/\bname=["\']' . preg_quote( $name, '/' ) . '["\']/', $tag ) && preg_match( '/\bvalue=["\']([^"\']*)["\']/', $tag, $v ) ) {
			return html_entity_decode( $v[1], ENT_QUOTES );
		}
	}
	return '';
};
$from = static function ( string $html, string $marker ): string {
	$pos = strpos( $html, $marker );
	return false === $pos ? '' : substr( $html, $pos );
};
$js_nonce = static function ( string $html, string $key ): string {
	return preg_match( '/"' . preg_quote( $key, '/' ) . '":"([a-f0-9]+)"/', $html, $m ) ? $m[1] : '';
};
/** Sale IDs listed in the history table, in order. */
$listed = static function ( string $body ): array {
	preg_match_all( '/<td class=\'id column-id[^>]*><a href="[^"]*sale=([0-9]+)">\1<\/a>/', $body, $m );
	return array_map( 'intval', $m[1] );
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
$expected_headers = ScanRoute::security_headers();
$headers_ok       = static function ( array $r ) use ( $expected_headers ): bool {
	foreach ( $expected_headers as $name => $value ) {
		if ( ( $r['headers'][ strtolower( $name ) ] ?? null ) !== $value ) {
			return false;
		}
	}
	return true;
};

/** A published simple product with managed stock (codes are assigned on save because $as manages codes). */
$make_simple = static function ( int $as, array $props = array() ): int {
	wp_set_current_user( $as );
	$p = new WC_Product_Simple();
	$p->set_name( 'PQBG 9A simple ' . wp_generate_password( 6, false ) );
	$p->set_status( 'publish' );
	$p->set_regular_price( '1499' );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 20 );
	$p->set_low_stock_amount( 0 );
	$p->set_sku( 'PQBG-9A-' . wp_generate_password( 8, false ) );
	$p->set_props( $props );
	$id = (int) $p->save();
	wp_set_current_user( 0 );
	return $id;
};
/** A variable product with $n variations that manage their own stock. */
$make_variable = static function ( int $as, int $n ): array {
	wp_set_current_user( $as );
	$opts = array_map( static fn( $i ) => 'S' . $i, range( 1, $n ) );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( $opts );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$p = new WC_Product_Variable();
	$p->set_name( 'PQBG 9A variable ' . wp_generate_password( 6, false ) );
	$p->set_status( 'publish' );
	$p->set_attributes( array( $attr ) );
	$pid  = (int) $p->save();
	$vids = array();
	foreach ( $opts as $opt ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $pid );
		$v->set_attributes( array( 'size' => $opt ) );
		$v->set_regular_price( '799' );
		$v->set_sku( 'PQBG-9A-' . $pid . '-' . $opt );
		$v->set_manage_stock( true );
		$v->set_stock_quantity( 10 );
		$v->set_low_stock_amount( 0 );
		$vids[] = (int) $v->save();
	}
	wp_set_current_user( 0 );
	return array( $pid, $vids );
};
$sell_in = static function ( string $code, int $qty, int $seller, array $extra = array() ): array|WP_Error {
	return SaleService::sell( array_merge( array( 'code' => $code, 'quantity' => $qty, 'request_id' => wp_generate_uuid4(), 'seller_id' => $seller, 'payment_method' => 'cash' ), $extra ) );
};
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
	$wpdb->insert( $S, $data );
	return (int) $wpdb->insert_id;
};
/** UTC "Y-m-d H:i:s" of a site-timezone (Asia/Kolkata) wall-clock time. */
$ist = static fn( string $local ) => ( new DateTimeImmutable( $local, wp_timezone() ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
$median = static function ( array $v ): float {
	sort( $v );
	return (float) $v[ intdiv( count( $v ), 2 ) ];
};
/** Milliseconds for $fn, best of $n. */
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
/** Starts a worker that holds a stock lock for $seconds; returns once the lock is held. */
$hold_lock = static function ( int $holder, float $seconds ) {
	$pipes = array();
	$proc  = proc_open( array( PHP_BINARY, __FILE__, '--worker', 'hold', (string) $holder, (string) $seconds ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	$first = trim( (string) fgets( $pipes[1] ) );
	return array( $proc, $pipes, 'held' === $first );
};
$end_hold = static function ( array $h ): void {
	stream_get_contents( $h[1][1] );
	stream_get_contents( $h[1][2] );
	proc_close( $h[0] );
};

try {
	// ------------------------------------------------------------------ users
	$make_user = static function ( string $role, string $display = '' ) use ( &$user_ids, &$pw ): int {
		$login = 'pqbg9a_' . $role . '_' . wp_generate_password( 5, false, false );
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
		if ( str_contains( $display, '<' ) ) {
			// wp_insert_user() sanitises display names; write it raw to test output escaping.
			$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->users, array( 'display_name' => $display ), array( 'ID' => $id ) );
			clean_user_cache( (int) $id );
		}
		$user_ids[ $login ] = (int) $id;
		$pw[ $login ]       = $pass;
		return (int) $id;
	};
	$A      = $make_user( 'administrator', 'PQBG Admin' );
	$SM     = $make_user( 'shop_manager', 'PQBG Manager' );
	$SMNV   = $make_user( 'shop_manager', 'PQBG Manager NoVoid' );
	$SE     = $make_user( 'pqbg_seller', '<img src=x onerror=alert(1)> Priya' );
	$SE2    = $make_user( 'pqbg_seller', 'Ravi Two' );
	$SE3    = $make_user( 'pqbg_seller', 'Gone Seller' );
	$CU     = $make_user( 'customer', 'PQBG Customer' );
	( new WP_User( $SMNV ) )->add_cap( Permissions::VOID_SALE, false ); // A manager who may see the history but not void.
	$logins = array_flip( $user_ids );
	foreach ( array( 'admin' => $A, 'sm' => $SM, 'smnv' => $SMNV, 'seller' => $SE, 'seller2' => $SE2, 'customer' => $CU ) as $who => $id ) {
		$login( $who, $logins[ $id ], $pw[ $logins[ $id ] ] );
	}

	// ------------------------------------------------------------------ migration
	pqbg_section( 'schema v3 migration and the pqbg_view_costs capability' );
	pqbg_t( 'DB_VERSION is 3 and the site is migrated', 3 === Install::DB_VERSION && 3 === Install::stored_version() );
	pqbg_t( 'migrations() ends with migrate_3', array( 1, 2, 3 ) === array_keys( Install::migrations() ) );
	$cols = $wpdb->get_col( "SHOW COLUMNS FROM $S" );
	$full = $wpdb->get_results( "SHOW FULL COLUMNS FROM $S", ARRAY_A );
	$type = array_column( $full, 'Type', 'Field' );
	$null = array_column( $full, 'Null', 'Field' );
	pqbg_t( 'v3 columns: payment_method varchar(20), unit_cost decimal(26,8), seller_name varchar(250), all NULL-able and last', array_slice( $cols, -3 ) === array( 'payment_method', 'unit_cost', 'seller_name' ) && 'varchar(20)' === $type['payment_method'] && 'decimal(26,8)' === $type['unit_cost'] && 'varchar(250)' === $type['seller_name'] && 'YES' === $null['payment_method'] && 'YES' === $null['unit_cost'] && 'YES' === $null['seller_name'] );
	$idx = $wpdb->get_col( $wpdb->prepare( "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'method_created' ORDER BY SEQ_IN_INDEX", $S ) );
	pqbg_t( 'index method_created (payment_method, created_at_gmt)', array( 'payment_method', 'created_at_gmt' ) === $idx );

	$real_prefix  = $wpdb->prefix;
	$wpdb->prefix = $mig_prefix;
	try {
		$v3_sql = Schema::statements();
		$v2_sql = array_map( static fn( $sql ) => str_replace( array( "payment_method varchar(20) NULL DEFAULT NULL,\n", "unit_cost decimal(26,8) NULL DEFAULT NULL,\n", "seller_name varchar(250) NULL DEFAULT NULL,\n", "KEY holder_status (stock_holder_id,status),\nKEY method_created (payment_method,created_at_gmt)\n" ), array( '', '', '', "KEY holder_status (stock_holder_id,status)\n" ), $sql ), $v3_sql );
		pqbg_t( 'migration: the v2 fixture is v3 without the three columns and the index', $v2_sql[0] === $v3_sql[0] && ! str_contains( $v2_sql[1], 'payment_method' ) && ! str_contains( $v2_sql[1], 'seller_name' ) && ! str_contains( $v2_sql[1], 'unit_cost' ) && str_contains( $v2_sql[1], "KEY holder_status (stock_holder_id,status)\n)" ) );
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $v2_sql );
		$mS  = Schema::sales_table();
		$v2c = $wpdb->get_col( "SHOW COLUMNS FROM $mS" );
		$wpdb->insert( $mS, array( 'request_id' => wp_generate_uuid4(), 'product_id' => 1, 'seller_id' => 1, 'unit_price' => '10', 'line_total' => '10', 'currency' => 'INR', 'product_name' => 'v2 row', 'created_at_gmt' => '2026-01-01 00:00:00', 'stock_holder_id' => 1 ) );
		$v2row = $wpdb->get_row( "SELECT * FROM $mS", ARRAY_A );
		pqbg_t( 'migration: v2 fixture on the temporary prefix (27 sales columns, 1 row)', str_starts_with( $mS, $mig_prefix ) && 27 === count( $v2c ) && is_array( $v2row ) );
		$res   = Install::migrate_3();
		$v3c   = $wpdb->get_col( "SHOW COLUMNS FROM $mS" );
		$v3row = $wpdb->get_row( "SELECT * FROM $mS", ARRAY_A );
		pqbg_t( 'migration: upgrade v2 → v3 adds the three columns and method_created', true === $res && array_slice( $v3c, -3 ) === array( 'payment_method', 'unit_cost', 'seller_name' ) && 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'method_created'", $mS ) ) );
		pqbg_t( 'migration: the existing row is unchanged, NULL ("not recorded"/"unknown") in the new columns', array_intersect_key( $v3row, $v2row ) === $v2row && null === $v3row['payment_method'] && null === $v3row['unit_cost'] && null === $v3row['seller_name'] );
		pqbg_t( 'migration: re-running is a no-op (empty dbDelta log, same row)', true === Install::migrate_3() && array() === Schema::create_or_update() && $v3row === $wpdb->get_row( "SELECT * FROM $mS", ARRAY_A ) );
		Schema::drop_tables();
		$fresh = true === Install::migrate_1() && true === Install::migrate_2() && true === Install::migrate_3();
		pqbg_t( 'migration: fresh install (migrate_1..3) gives the full v3 schema', $fresh && $wpdb->get_col( "SHOW COLUMNS FROM $mS" ) === $cols );
		Schema::drop_tables();
	} finally {
		$wpdb->prefix = $real_prefix;
	}
	pqbg_t( 'migration: no temporary tables left, real tables untouched', array() === $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $mig_prefix ) . '%' ) ) && $wpdb->get_col( "SHOW COLUMNS FROM $S" ) === $cols );
	$un = (string) file_get_contents( PQBG_PLUGIN_DIR . 'uninstall.php' );
	pqbg_t( 'uninstall: the default path still returns before any data deletion (only the delete-all branch drops tables and cost meta)', strpos( $un, "true !== PQBG_UNINSTALL_DELETE_ALL_DATA" ) < strpos( $un, 'drop_tables' ) && strpos( $un, "true !== PQBG_UNINSTALL_DELETE_ALL_DATA" ) < strpos( $un, "'_pqbg_cost_price'" ) );
	pqbg_t( 'capability: pqbg_view_costs in all_caps, administrator only in role_map', in_array( 'pqbg_view_costs', Permissions::all_caps(), true ) && in_array( 'pqbg_view_costs', Permissions::role_map()['administrator'], true ) && ! in_array( 'pqbg_view_costs', Permissions::role_map()['shop_manager'], true ) && ! in_array( 'pqbg_view_costs', Permissions::role_map()[ Permissions::SELLER_ROLE ], true ) );
	pqbg_t( 'capability: admin yes; shop manager, seller, customer, logged out no', Permissions::can_view_costs( $A ) && ! Permissions::can_view_costs( $SM ) && ! Permissions::can_view_costs( $SE ) && ! Permissions::can_view_costs( $CU ) && ! Permissions::can_view_costs( 0 ) );
	pqbg_t( 'capability: stored on the administrator role only', ! empty( get_role( 'administrator' )->capabilities['pqbg_view_costs'] ) && empty( get_role( 'shop_manager' )->capabilities['pqbg_view_costs'] ) && empty( get_role( 'pqbg_seller' )->capabilities['pqbg_view_costs'] ) && empty( get_role( 'customer' )->capabilities['pqbg_view_costs'] ) );
	// Removal: remove_all() iterates all_caps(), checked above; run it on a throwaway role copy.
	add_role( 'pqbg9a_tmp', 'tmp', array( 'pqbg_view_costs' => true, 'read' => true ) );
	$r_before = get_role( 'pqbg9a_tmp' )->capabilities;
	foreach ( Permissions::all_caps() as $cap ) {
		get_role( 'pqbg9a_tmp' )->remove_cap( $cap );
	}
	pqbg_t( 'capability: removing all_caps() takes pqbg_view_costs away (the delete-all uninstall path)', ! empty( $r_before['pqbg_view_costs'] ) && ! isset( get_role( 'pqbg9a_tmp' )->capabilities['pqbg_view_costs'] ) );
	remove_role( 'pqbg9a_tmp' );

	// ------------------------------------------------------------------ payment settings
	pqbg_section( 'payment methods: setting' );
	$set_methods( null );
	pqbg_t( 'defaults: cash, upi, card enabled; other disabled', array( 'cash', 'upi', 'card' ) === PaymentMethods::enabled() && ! PaymentMethods::is_enabled( 'other' ) );
	pqbg_t( 'labels: Cash, UPI, Card, Other; NULL → "Not recorded"', array( 'cash' => 'Cash', 'upi' => 'UPI', 'card' => 'Card', 'other' => 'Other' ) === PaymentMethods::all() && 'Not recorded' === PaymentMethods::label( null ) && 'Not recorded' === PaymentMethods::label( '' ) );
	$stored_before = get_option( Plugin::SETTINGS_OPTION );
	$out           = Settings::sanitize( array( 'payment_methods_present' => '1', 'payment_methods' => array( 'other', 'cash', 'bitcoin', 'cash', array( 'x' ) ) ) );
	pqbg_t( 'sanitize: known keys only, display order, no duplicates', array( 'cash', 'other' ) === $out['payment_methods'] );
	$GLOBALS['wp_settings_errors'] = array();
	$out = Settings::sanitize( array( 'payment_methods_present' => '1' ) );
	$err = wp_list_pluck( get_settings_errors( Plugin::SETTINGS_OPTION ), 'code' );
	pqbg_t( 'sanitize: nothing ticked → refused with an error, previous choice kept', ( is_array( $stored_before ) && isset( $stored_before['payment_methods'] ) ? $stored_before['payment_methods'] : PaymentMethods::DEFAULT_ENABLED ) === $out['payment_methods'] && in_array( 'pqbg_no_payment_method', $err, true ) );
	$out = Settings::sanitize( array( 'barcodes_enabled' => '0' ) );
	pqbg_t( 'sanitize: a form without the payment field leaves the methods alone', PaymentMethods::DEFAULT_ENABLED === $out['payment_methods'] );
	$set_methods( array( 'bitcoin' ) );
	pqbg_t( 'a broken stored value falls back to the defaults (never empty)', PaymentMethods::DEFAULT_ENABLED === PaymentMethods::enabled() );
	$set_methods( null );
	// Over HTTP (administrators only, the Phase 4 page).
	$page = $http( 'admin', 'GET', admin_url( 'admin.php?page=pqbg-settings' ) );
	pqbg_t( 'settings page: "In-store sales" section with the 4 checkboxes (cash, upi, card ticked)', 200 === $page['code'] && str_contains( $page['body'], 'In-store sales' ) && 4 === preg_match_all( '/name="pqbg_settings\[payment_methods\]\[\]"/', $page['body'] ) && 3 === preg_match_all( '/name="pqbg_settings\[payment_methods\]\[\]" value="(cash|upi|card)" checked/', $page['body'] ) );
	$opt_post = static function ( array $methods ) use ( $http, $page, $field ): array {
		$form = substr( $page['body'], strpos( $page['body'], 'action="' . esc_url( admin_url( 'options.php' ) ) ) );
		$data = 'option_page=pqbg_settings&action=update&_wpnonce=' . rawurlencode( $field( $form, '_wpnonce' ) ) . '&_wp_http_referer=' . rawurlencode( $field( $form, '_wp_http_referer' ) ) . '&pqbg_settings%5Bbarcodes_enabled%5D=0&pqbg_settings%5Bscan_base_url%5D=&pqbg_settings%5Bpayment_methods_present%5D=1';
		foreach ( $methods as $m ) {
			$data .= '&pqbg_settings%5Bpayment_methods%5D%5B%5D=' . rawurlencode( $m );
		}
		return $http( 'admin', 'POST', admin_url( 'options.php' ), $data );
	};
	$r = $opt_post( array( 'cash', 'other' ) );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( Plugin::SETTINGS_OPTION, 'options' );
	pqbg_t( 'settings page: saving cash + other works', 302 === $r['code'] && array( 'cash', 'other' ) === PaymentMethods::enabled() );
	$r = $opt_post( array() );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( Plugin::SETTINGS_OPTION, 'options' );
	// options.php redirects (to a site-relative path) with settings-updated; the errors are shown there.
	$after = $http( 'admin', 'GET', str_starts_with( $r['location'], '/' ) ? preg_replace( '#^(https?://[^/]+).*$#', '$1', home_url() ) . $r['location'] : $r['location'] );
	pqbg_t( 'settings page: unticking everything is refused, the message is shown and the previous choice kept', 302 === $r['code'] && array( 'cash', 'other' ) === PaymentMethods::enabled() && str_contains( $after['body'], 'At least one payment method must stay enabled' ), $r['code'] . ' ' . $r['location'] . ' ' . wp_json_encode( PaymentMethods::enabled() ) . ' ' . substr( wp_strip_all_tags( $from( $after['body'], '<div class="wrap">' ) ), 0, 300 ) );
	pqbg_t( 'settings page: shop manager cannot open or save it (capability pqbg_manage_settings)', 200 !== $http( 'sm', 'GET', admin_url( 'admin.php?page=pqbg-settings' ) )['code'] );
	$set_methods( null );

	// ------------------------------------------------------------------ products
	$P1 = $make_simple( $A, array( 'name' => 'PQBG 9A Kurta One' ) );
	$P2 = $make_simple( $A, array( 'name' => 'PQBG 9A Saree Two' ) );
	$P3 = $make_simple( $A, array( 'name' => 'PQBG 9A Dupatta Three' ) );
	$PN = $make_simple( $A, array( 'name' => 'PQBG 9A Plain' ) );
	list( $V, $VV ) = $make_variable( $A, 3 );
	$sync();
	CostPrice::set( $P1, '777.77' );
	CostPrice::set( $V, '456.78' );
	CostPrice::set( $VV[0], '412.34' );
	update_post_meta( $P1, '_pqbg9a_marker', 'marker-9a' ); // Control: an ordinary protected meta that exports and imports DO carry.
	$c1 = $code_of( $P1 );
	$cv1 = $code_of( $VV[1] );
	pqbg_t( 'fixtures: products with codes and planted costs (777.77, default 456.78, own 412.34)', '' !== $c1 && '' !== $cv1 && '777.77' === $raw_cost( $P1 ) && '456.78' === $raw_cost( $V ) && '412.34' === $raw_cost( $VV[0] ) );

	// ------------------------------------------------------------------ the sale form
	pqbg_section( 'payment method: the sale form (HTTP, seller)' );
	$f = $get_form( 'seller', $c1 );
	pqbg_t( 'the form has a required "Paid by" radio group: cash, upi, card, in that order', is_array( $f ) && array( 'cash', 'upi', 'card' ) === array_keys( $f['radios'] ) && ! in_array( false, array_column( $f['radios'], 'required' ), true ) && str_contains( $f['html'], '<legend class="pqbg-scan__label">Paid by</legend>' ) );
	pqbg_t( 'nothing is pre-selected', is_array( $f ) && ! in_array( true, array_column( $f['radios'], 'checked' ), true ) );
	pqbg_t( 'no JavaScript on the page', ! str_contains( $http( 'seller', 'GET', $url( $c1 ) )['body'], '<script' ) );
	$set_methods( array( 'upi' ) );
	$f1 = $get_form( 'seller', $c1 );
	pqbg_t( 'only one method enabled → it is pre-selected', is_array( $f1 ) && array( 'upi' ) === array_keys( $f1['radios'] ) && $f1['radios']['upi']['checked'] );
	$set_methods( array( 'cash', 'upi', 'card', 'other' ) );
	$f4 = $get_form( 'seller', $c1 );
	pqbg_t( 'all four enabled → four radios, none selected', is_array( $f4 ) && array( 'cash', 'upi', 'card', 'other' ) === array_keys( $f4['radios'] ) && ! in_array( true, array_column( $f4['radios'], 'checked' ), true ) );
	$set_methods( null );

	$n0    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $S" );
	$st0   = $stock( $P1 );
	$f     = $get_form( 'seller', $c1 );
	$r     = $post_form( 'seller', $c1, $f, array( 'quantity' => '2' ) );
	$again = $form_of( $r['body'] );
	pqbg_t( 'no method → 400 "Choose how the customer paid."', 400 === $r['code'] && $has_notice( $r, 'Choose how the customer paid.' ) );
	pqbg_t( '…the form is shown again with the quantity kept (2) and still nothing selected, and a new request ID', is_array( $again ) && 2 === $again['selected'] && ! in_array( true, array_column( $again['radios'], 'checked' ), true ) && $again['fields']['request_id'] !== $f['fields']['request_id'] );
	pqbg_t( '…nothing recorded, stock unchanged', $n0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $S" ) && $st0 === $stock( $P1 ) );
	$r = $post_form( 'seller', $c1, $f, array( 'payment_method' => 'bitcoin' ) );
	pqbg_t( 'invalid method → 400 "That payment method is not available. Choose another."', 400 === $r['code'] && $has_notice( $r, 'That payment method is not available. Choose another.' ) && $n0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $S" ) );
	$r = $post_form( 'seller', $c1, $f, array( 'payment_method' => 'other' ) );
	pqbg_t( 'disabled method (other) → 400, nothing recorded', 400 === $r['code'] && $has_notice( $r, 'That payment method is not available. Choose another.' ) && $n0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $S" ) && $st0 === $stock( $P1 ) );
	$r = $post_form( 'seller', $c1, $f, array( 'payment_method' => array( 'cash' ) ) );
	pqbg_t( 'an array instead of a string → 400', 400 === $r['code'] && $n0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $S" ) );
	$r     = $post_form( 'seller', $c1, $f, array( 'quantity' => 'abc', 'payment_method' => 'upi' ) );
	$again = $form_of( $r['body'] );
	pqbg_t( 'a quantity error keeps the chosen method (upi checked on the new form)', 400 === $r['code'] && is_array( $again ) && $again['radios']['upi']['checked'] && ! $again['radios']['cash']['checked'] );
	// Disabled between the form and the submission.
	$fd = $get_form( 'seller', $c1 );
	$set_methods( array( 'cash', 'card' ) );
	$r = $post_form( 'seller', $c1, $fd, array( 'payment_method' => 'upi' ) );
	pqbg_t( 'a method disabled after the form was opened → 400 at the moment of sale, nothing recorded', 400 === $r['code'] && $has_notice( $r, 'That payment method is not available. Choose another.' ) && $n0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $S" ) );
	$set_methods( null );

	$r    = $post_form( 'seller', $c1, $f, array( 'quantity' => '2', 'payment_method' => 'upi' ) );
	$sid  = $sale_id_of( $r );
	$srow = $row( $sid );
	pqbg_t( 'upi → 303 to the sale page, one completed row', 303 === $r['code'] && $sid > 0 && 'completed' === $srow['status'] && $n0 + 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $S" ) );
	pqbg_t( 'the row: payment_method upi, unit_cost 777.77 (snapshot), seller_name = display name at that moment', 'upi' === $srow['payment_method'] && '777.77000000' === $srow['unit_cost'] && '<img src=x onerror=alert(1)> Priya' === $srow['seller_name'] );
	$sp = $http( 'seller', 'GET', $r['location'] );
	pqbg_t( 'the sale page shows "Paid by: UPI"', 200 === $sp['code'] && (bool) preg_match( '/<dt>Paid by<\/dt>\s*<dd>UPI<\/dd>/', $sp['body'] ) );
	pqbg_t( 'the sale page never shows the cost', ! str_contains( $sp['body'], '777.77' ) );
	$r2 = $post_form( 'seller', $c1, $f, array( 'quantity' => '2', 'payment_method' => 'card' ) );
	pqbg_t( 'resubmitting the same form with another method → the ORIGINAL sale, unchanged (upi)', 303 === $r2['code'] && $sid === $sale_id_of( $r2 ) && 'upi' === $row( $sid )['payment_method'] && $n0 + 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $S" ) );
	$r3 = $post_form( 'seller', $c1, $f, array( 'quantity' => '2', 'payment_method' => null ) );
	pqbg_t( '…and without any method → the original sale too (the request ID decides first)', 303 === $r3['code'] && $sid === $sale_id_of( $r3 ) );

	pqbg_section( 'payment method: SaleService (in-process)' );
	$e = SaleService::sell( array( 'code' => $c1, 'quantity' => 1, 'request_id' => wp_generate_uuid4(), 'seller_id' => $SE ) );
	pqbg_t( 'sell() without payment_method → pqbg_payment_required, nothing written', is_wp_error( $e ) && 'pqbg_payment_required' === $e->get_error_code() && $n0 + 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $S" ) );
	$e = $sell_in( $c1, 1, $SE, array( 'payment_method' => 'other' ) );
	pqbg_t( 'sell() with a disabled method → pqbg_payment_invalid', is_wp_error( $e ) && 'pqbg_payment_invalid' === $e->get_error_code() );
	$e = $sell_in( $c1, 1, $SE, array( 'payment_method' => 'CASH' ) );
	pqbg_t( 'keys are exact (CASH refused)', is_wp_error( $e ) && 'pqbg_payment_invalid' === $e->get_error_code() );
	$req  = wp_generate_uuid4();
	$seen = null;
	$spy  = static function ( $sql ) use ( &$seen, $wpdb, $S, &$req ) {
		$seen = $wpdb->get_row( $wpdb->prepare( "SELECT status, payment_method, unit_cost, seller_name FROM $S WHERE request_id = %s", $req ), ARRAY_A );
		return $sql;
	};
	add_filter( SaleService::STOCK_QUERY_FILTER, $spy, 10 );
	$ok = $sell_in( $c1, 1, $SE, array( 'request_id' => $req, 'payment_method' => 'card' ) );
	remove_filter( SaleService::STOCK_QUERY_FILTER, $spy, 10 );
	pqbg_t( 'the pending journal row already carries the method, the cost and the seller name (read during the stock change)', is_array( $seen ) && 'pending' === $seen['status'] && 'card' === $seen['payment_method'] && '777.77000000' === $seen['unit_cost'] && '<img src=x onerror=alert(1)> Priya' === $seen['seller_name'] );
	$again = $sell_in( $c1, 1, $SE, array( 'request_id' => $req, 'payment_method' => 'cash' ) );
	pqbg_t( 'same request ID, another method → the original outcome, row unchanged (card)', is_array( $ok ) && is_array( $again ) && (int) $ok['sale']['id'] === (int) $again['sale']['id'] && 'card' === $row( (int) $ok['sale']['id'] )['payment_method'] );
	$set_methods( array( 'upi' ) );
	$again = $sell_in( $c1, 1, $SE, array( 'request_id' => $req, 'payment_method' => 'card' ) );
	pqbg_t( '…even after that method was disabled (idempotency is checked first)', is_array( $again ) && (int) $ok['sale']['id'] === (int) $again['sale']['id'] );
	$set_methods( null );
	// ------------------------------------------------------------------ cost price
	pqbg_section( 'cost price: validation and inheritance' );
	$norm = static function ( $v ) {
		$r = CostPrice::normalize( $v );
		return is_wp_error( $r ) ? 'ERR' : $r;
	};
	$cases = array(
		array( '', '' ),
		array( '0', '0.00' ),
		array( '12.5', '12.50' ),
		array( '1499.00', '1499.00' ),
		array( ' 7 ', '7.00' ),
		array( '123456789012', '123456789012.00' ),
		array( 12.5, '12.50' ),
		array( '1,499.00', 'ERR' ),
		array( '-1', 'ERR' ),
		array( 'abc', 'ERR' ),
		array( '1.234', 'ERR' ),
		array( '1e3', 'ERR' ),
		array( '1234567890123', 'ERR' ),
		array( '12.', '12.00' ),
		array( '.5', 'ERR' ),
		array( array( '5' ), 'ERR' ),
		array( null, 'ERR' ),
	);
	$bad = array();
	foreach ( $cases as $c ) {
		if ( $norm( $c[0] ) !== $c[1] ) {
			$bad[] = wp_json_encode( $c[0] ) . '→' . $norm( $c[0] );
		}
	}
	pqbg_t( 'normalize(): empty, or a decimal ≥ 0 with at most 2 decimals (17 cases)', array() === $bad, implode( ' ', $bad ) );
	$sync();
	pqbg_t( 'effective cost: simple own 777.77; simple without → null (unknown)', '777.77' === CostPrice::effective( wc_get_product( $P1 ) ) && null === CostPrice::effective( wc_get_product( $PN ) ) );
	pqbg_t( 'effective cost: variation own 412.34 beats the parent default; without own → default 456.78', '412.34' === CostPrice::effective( wc_get_product( $VV[0] ), wc_get_product( $V ) ) && '456.78' === CostPrice::effective( wc_get_product( $VV[1] ), wc_get_product( $V ) ) );
	CostPrice::set( $V, '' );
	$sync();
	pqbg_t( 'effective cost: no own and no default → null; set( \'\' ) deletes the meta', null === CostPrice::effective( wc_get_product( $VV[1] ), wc_get_product( $V ) ) && 0 === $cost_rows( $V ) );
	CostPrice::set( $V, '456.78' );

	pqbg_section( 'cost price: saving (in-process hooks)' );
	include_once WC_ABSPATH . 'includes/admin/class-wc-admin-meta-boxes.php';
	$PS = $make_simple( $A, array( 'name' => 'PQBG 9A Save Test' ) );
	$sync();
	$save_as = static function ( int $user, int $id, array $post ) {
		wp_set_current_user( $user );
		$_POST = $post;
		CostPrice::save_product( wc_get_product( $id ) );
		$_POST = array();
		wp_set_current_user( 0 );
	};
	$save_as( $A, $PS, array( 'pqbg_cost_price' => '250' ) );
	pqbg_t( 'administrator: simple product field saved (250 → 250.00)', '250.00' === $raw_cost( $PS ) );
	WC_Admin_Meta_Boxes::$meta_box_errors = array();
	$save_as( $A, $PS, array( 'pqbg_cost_price' => '-5' ) );
	pqbg_t( 'administrator: an invalid value keeps the previous one and adds an admin error', '250.00' === $raw_cost( $PS ) && 1 === count( WC_Admin_Meta_Boxes::$meta_box_errors ) && str_contains( WC_Admin_Meta_Boxes::$meta_box_errors[0], 'The cost price must be empty or a number' ) );
	WC_Admin_Meta_Boxes::$meta_box_errors = array();
	$save_as( $A, $PS, array( 'other' => '1' ) );
	pqbg_t( 'administrator: a form without the field leaves the cost alone', '250.00' === $raw_cost( $PS ) );
	$save_as( $SM, $PS, array( 'pqbg_cost_price' => '1' ) );
	pqbg_t( 'shop manager: a posted value is ignored (no pqbg_view_costs)', '250.00' === $raw_cost( $PS ) );
	$save_as( $A, $PS, array( 'pqbg_cost_price' => '' ) );
	pqbg_t( 'administrator: emptying the field deletes the cost (unknown)', 0 === $cost_rows( $PS ) );
	$save_as( $A, $V, array( 'pqbg_cost_price_default' => '456.78', 'pqbg_cost_price' => '1' ) );
	pqbg_t( 'variable product: the default field is the one saved (the simple field is ignored)', '456.78' === $raw_cost( $V ) );
	wp_set_current_user( $A );
	$_POST = array( 'pqbg_cost_price' => array( 1 => '10', 2 => '20' ) );
	CostPrice::save_variation( wc_get_product( $VV[2] ), 2 );
	$_POST = array();
	wp_set_current_user( 0 );
	pqbg_t( 'variation: saved from its own index only', '20.00' === $raw_cost( $VV[2] ) && 0 === $cost_rows( $VV[1] ) );
	CostPrice::set( $VV[2], '' );

	pqbg_section( 'cost price: the edit screen and variations over HTTP' );
	$edit_url  = static fn( int $id ) => admin_url( "post.php?post={$id}&action=edit" );
	$classic_save = static function ( string $who, int $id, array $args ) use ( $http, $edit_url, $field, $from ): array {
		$page = $http( $who, 'GET', $edit_url( $id ) );
		$form = $from( $page['body'], '<form name="post"' );
		$data = array_merge(
			array(
				'_wpnonce'               => $field( $form, '_wpnonce' ),
				'_wp_http_referer'       => $field( $form, '_wp_http_referer' ),
				'user_ID'                => $field( $form, 'user_ID' ),
				'action'                 => 'editpost',
				'originalaction'         => 'editpost',
				'post_author'            => $field( $form, 'post_author' ),
				'post_type'              => 'product',
				'original_post_status'   => $field( $form, 'original_post_status' ),
				'post_ID'                => $id,
				'post_title'             => 'PQBG 9A classic ' . $id,
				'content'                => '',
				'woocommerce_meta_nonce' => $field( $form, 'woocommerce_meta_nonce' ),
				'product-type'           => 'simple',
				'_regular_price'         => '10',
				'publish'                => 'Publish',
			),
			$args
		);
		return $http( $who, 'POST', admin_url( 'post.php' ), array_filter( $data, static fn( $v ) => null !== $v ) );
	};
	CostPrice::set( $PS, '321.09' );
	$ea = $http( 'admin', 'GET', $edit_url( $PS ) );
	$es = $http( 'sm', 'GET', $edit_url( $PS ) );
	pqbg_t( 'administrator: "Cost price (₹)" field with the value, inside the pricing block (show_if_simple)', 200 === $ea['code'] && (bool) preg_match( '/<p class="form-field pqbg_cost_price_field show_if_simple[^"]*">\s*<label for="pqbg_cost_price">Cost price \(₹\)<\/label>/', $ea['body'] ) && str_contains( $ea['body'], 'value="321.09"' ) );
	pqbg_t( 'administrator: the variable-product "Default cost price (₹)" field is on the page too (show_if_variable)', str_contains( $ea['body'], 'id="pqbg_cost_price_default"' ) && str_contains( $ea['body'], 'Default cost price (₹)' ) );
	pqbg_t( 'shop manager: the edit screen has no cost field and no cost value', 200 === $es['code'] && ! str_contains( $es['body'], 'pqbg_cost_price' ) && ! str_contains( $es['body'], '321.09' ) && ! str_contains( $es['body'], 'Cost price' ) );
	$r = $classic_save( 'sm', $PS, array( 'pqbg_cost_price' => '1.00', 'pqbg_cost_price_default' => '2.00' ) );
	$sync();
	pqbg_t( 'shop manager: a full classic save (even with the fields forged) keeps the cost', 302 === $r['code'] && '321.09' === $raw_cost( $PS ) && 1 === $cost_rows( $PS ) );
	$r = $classic_save( 'admin', $PS, array( 'pqbg_cost_price' => '654.32' ) );
	$sync();
	pqbg_t( 'administrator: the classic save stores the new cost', 302 === $r['code'] && '654.32' === $raw_cost( $PS ) );
	$r = $classic_save( 'admin', $PS, array( 'pqbg_cost_price' => '12,34' ) );
	$sync();
	$ep = $http( 'admin', 'GET', $edit_url( $PS ) );
	pqbg_t( 'administrator: an invalid value keeps the cost and the error is shown after the redirect', 302 === $r['code'] && '654.32' === $raw_cost( $PS ) && str_contains( $ep['body'], 'The cost price must be empty or a number' ) );
	$ev_a = $http( 'admin', 'GET', $edit_url( $V ) );
	$ev_s = $http( 'sm', 'GET', $edit_url( $V ) );
	$lv   = static fn( string $who, string $page ) => $http( $who, 'POST', admin_url( 'admin-ajax.php' ), array( 'action' => 'woocommerce_load_variations', 'security' => $js_nonce( $page, 'load_variations_nonce' ), 'product_id' => $V, 'attributes' => array(), 'page' => 1, 'per_page' => 15 ) );
	$la   = $lv( 'admin', $ev_a['body'] );
	$ls   = $lv( 'sm', $ev_s['body'] );
	pqbg_t( 'variations AJAX, administrator: a cost field per variation with its value and the default as placeholder', 200 === $la['code'] && 3 === preg_match_all( '/name="pqbg_cost_price\[[0-9]+\]"/', $la['body'] ) && str_contains( $la['body'], 'value="412.34"' ) && str_contains( $la['body'], 'placeholder="Default: ₹456.78"' ) );
	pqbg_t( 'variations AJAX, shop manager: no cost field, no cost values', 200 === $ls['code'] && str_contains( $ls['body'], 'variable_post_id' ) && ! str_contains( $ls['body'], 'pqbg_cost_price' ) && ! str_contains( $ls['body'], '412.34' ) && ! str_contains( $ls['body'], '456.78' ) );
	$sv = static fn( string $who, string $page, string $cost ) => $http( $who, 'POST', admin_url( 'admin-ajax.php' ), array( 'action' => 'woocommerce_save_variations', 'security' => $js_nonce( $page, 'save_variations_nonce' ), 'product_id' => $V, 'product-type' => 'variable', 'variable_post_id' => array( $VV[0] ), 'variable_menu_order' => array( 0 ), 'variable_regular_price' => array( '799' ), 'variable_sku' => array( 'PQBG-9A-' . $V . '-S1' ), 'variable_enabled' => array( 0 => 'on' ), 'variable_manage_stock' => array( 0 => 'on' ), 'variable_stock' => array( '10' ), 'variable_original_stock' => array( '10' ), 'attribute_size' => array( 'S1' ), 'pqbg_cost_price' => array( 0 => $cost ) ) );
	$r = $sv( 'sm', $ev_s['body'], '1.00' );
	$sync();
	pqbg_t( 'variations AJAX save, shop manager (field forged): the variation\'s cost is unchanged', 200 === $r['code'] && '412.34' === $raw_cost( $VV[0] ) );
	$r = $sv( 'admin', $ev_a['body'], '413.00' );
	$sync();
	pqbg_t( 'variations AJAX save, administrator: saved', 200 === $r['code'] && '413.00' === $raw_cost( $VV[0] ) );
	CostPrice::set( $VV[0], '412.34' );

	pqbg_section( 'cost price: snapshot at the moment of sale' );
	$sync();
	$s1 = $sell_in( $cv1, 1, $SE );
	$s2 = $sell_in( $code_of( $VV[0] ), 1, $SE );
	$s3 = $sell_in( $code_of( $PN ), 1, $SE );
	$id1 = is_array( $s1 ) ? (int) $s1['sale']['id'] : 0;
	pqbg_t( 'variation without own cost → the parent default (456.78) is snapshotted', $id1 > 0 && '456.78000000' === $row( $id1 )['unit_cost'] );
	pqbg_t( 'variation with own cost → 412.34', is_array( $s2 ) && '412.34000000' === $row( (int) $s2['sale']['id'] )['unit_cost'], is_wp_error( $s2 ) ? $s2->get_error_code() : wp_json_encode( is_array( $s2 ) ? $row( (int) $s2['sale']['id'] ) : null ) );
	pqbg_t( 'no cost anywhere → NULL (unknown), not 0', is_array( $s3 ) && null === $row( (int) $s3['sale']['id'] )['unit_cost'] );
	CostPrice::set( $V, '500' );
	$s4 = $sell_in( $cv1, 1, $SE );
	pqbg_t( 'changing the default later: the past sale keeps 456.78, the next one records 500.00', '456.78000000' === $row( $id1 )['unit_cost'] && is_array( $s4 ) && '500.00000000' === $row( (int) $s4['sale']['id'] )['unit_cost'] );
	CostPrice::set( $V, '456.78' );
	CostPrice::set( $P1, '700' );
	pqbg_t( 'changing a product\'s cost never touches its past sales (still 777.77)', '777.77000000' === $row( $sid )['unit_cost'] );
	CostPrice::set( $P1, '777.77' );
	CostPrice::set( $PN, '0' );
	$s5 = $sell_in( $code_of( $PN ), 1, $SE );
	pqbg_t( 'a cost of 0 is recorded as 0.00 (known), distinct from unknown', is_array( $s5 ) && '0.00000000' === $row( (int) $s5['sale']['id'] )['unit_cost'] );
	CostPrice::set( $PN, '' );

	pqbg_section( 'cost price: never exposed' );
	$rest_nonce = static fn( string $who ) => trim( $http( $who, 'GET', admin_url( 'admin-ajax.php?action=rest-nonce' ) )['body'] );
	$nonces     = array(
		'sm'    => $rest_nonce( 'sm' ),
		'admin' => $rest_nonce( 'admin' ),
	);
	$rest = static function ( string $who, string $method, string $route, ?array $body = null ) use ( $http, &$nonces ): array {
		$headers = array( 'X-WP-Nonce: ' . $nonces[ $who ], 'Content-Type: application/json' );
		if ( 'GET' === $method ) {
			return $http( $who, 'GET', rest_url( $route ), null, $headers );
		}
		$headers[] = 'X-HTTP-Method-Override: ' . $method;
		return $http( $who, 'POST', rest_url( $route ), (string) wp_json_encode( $body ?? array() ), $headers );
	};
	$meta_keys = static fn( array $r ) => array_column( (array) ( json_decode( $r['body'], true )['meta_data'] ?? array() ), 'key' );
	$g = $rest( 'sm', 'GET', 'wc/v3/products/' . $P1 );
	pqbg_t( 'WC REST v3 product as shop manager: 200, meta_data carries other protected meta but not the cost', 200 === $g['code'] && in_array( '_pqbg9a_marker', $meta_keys( $g ), true ) && ! in_array( $KEY, $meta_keys( $g ), true ) && ! str_contains( $g['body'], '777.77' ) );
	$g = $rest( 'sm', 'GET', 'wc/v3/products/' . $V . '/variations' );
	pqbg_t( 'WC REST v3 variations as shop manager: no cost values', 200 === $g['code'] && 3 === count( (array) json_decode( $g['body'], true ) ) && ! str_contains( $g['body'], '412.34' ) && ! str_contains( $g['body'], '456.78' ) && ! str_contains( $g['body'], $KEY ) );
	$g = $rest( 'sm', 'GET', 'wc/v3/products/' . $V );
	pqbg_t( 'WC REST v3 variable product as shop manager: no default cost', 200 === $g['code'] && ! str_contains( $g['body'], '456.78' ) && ! str_contains( $g['body'], $KEY ) );
	$g = $rest( 'sm', 'GET', 'wc/v3/products?per_page=100&search=' . rawurlencode( 'PQBG 9A' ) );
	pqbg_t( 'WC REST v3 product list as shop manager: no cost values', 200 === $g['code'] && ! str_contains( $g['body'], '777.77' ) && ! str_contains( $g['body'], $KEY ) );
	$g = $rest( 'admin', 'GET', 'wc/v3/products/' . $P1 );
	pqbg_t( 'WC REST v3 as administrator: not in REST either (D6)', 200 === $g['code'] && ! str_contains( $g['body'], '777.77' ) && ! str_contains( $g['body'], $KEY ) );
	$p = $rest( 'sm', 'PUT', 'wc/v3/products/' . $P1, array( 'meta_data' => array( array( 'key' => $KEY, 'value' => '1.11' ) ) ) );
	$sync();
	pqbg_t( 'WC REST v3 PUT meta_data as shop manager: the request succeeds but the cost is unchanged (no extra row)', 200 === $p['code'] && '777.77' === $raw_cost( $P1 ) && 1 === $cost_rows( $P1 ) );
	$p = $rest( 'admin', 'PUT', 'wc/v3/products/' . $P1, array( 'meta_data' => array( array( 'key' => $KEY, 'value' => '2.22' ) ) ) );
	$p2 = $rest( 'sm', 'PUT', 'wc/v3/products/' . $V . '/variations/' . $VV[0], array( 'meta_data' => array( array( 'key' => $KEY, 'value' => '3.33' ) ) ) );
	$sync();
	pqbg_t( 'WC REST v3 PUT as administrator, and on a variation: unchanged too', 200 === $p['code'] && 200 === $p2['code'] && '777.77' === $raw_cost( $P1 ) && '412.34' === $raw_cost( $VV[0] ) && 1 === $cost_rows( $VV[0] ) );
	$p = $rest( 'admin', 'POST', 'wc/v3/products', array( 'name' => 'PQBG 9A REST new', 'regular_price' => '5', 'meta_data' => array( array( 'key' => $KEY, 'value' => '4.44' ), array( 'key' => '_pqbg9a_marker', 'value' => 'x' ) ) ) );
	$new = (int) ( json_decode( $p['body'], true )['id'] ?? 0 );
	pqbg_t( 'WC REST v3 create with the cost in meta_data: the product is created without it (the other meta is kept)', 201 === $p['code'] && $new > 0 && 0 === $cost_rows( $new ) && 'x' === get_post_meta( $new, '_pqbg9a_marker', true ) );
	$g = $http( 'admin', 'GET', rest_url( 'wc/store/v1/products/' . $P1 ) );
	$g2 = $http( 'admin', 'GET', rest_url( 'wc/store/v1/products/' . $V ) );
	pqbg_t( 'Store API product and variable product: no cost', 200 === $g['code'] && 200 === $g2['code'] && ! str_contains( $g['body'], '777.77' ) && ! str_contains( $g2['body'], '456.78' ) && ! str_contains( $g2['body'], '412.34' ) );
	$sf  = $http( 'admin', 'GET', get_permalink( $P1 ) );
	$sf2 = $http( 'admin', 'GET', get_permalink( $V ) );
	pqbg_t( 'storefront product pages (incl. the variation JSON): no cost', 200 === $sf['code'] && 200 === $sf2['code'] && str_contains( $sf2['body'], 'data-product_variations' ) && ! str_contains( $sf['body'], '777.77' ) && ! str_contains( $sf2['body'], '456.78' ) && ! str_contains( $sf2['body'], '412.34' ) );
	// Product CSV exporter (as a shop manager, in-process).
	require_once WC_ABSPATH . 'includes/export/class-wc-product-csv-exporter.php';
	$exporter = new class() extends WC_Product_CSV_Exporter {
		/** The CSV as a string. */
		public function csv(): string {
			$this->prepare_data_to_export();
			return $this->export_column_headers() . $this->get_csv_data();
		}
	};
	wp_set_current_user( $SM );
	$exporter->enable_meta_export( true );
	$exporter->set_product_ids_to_export( array( $P1, $V, $VV[0], $VV[1] ) );
	$exporter->set_limit( 100 );
	$ecsv = $exporter->csv();
	wp_set_current_user( 0 );
	pqbg_t( 'WooCommerce product CSV export with meta: other protected meta exported, the cost not', str_contains( $ecsv, 'Meta: _pqbg9a_marker' ) && ! str_contains( $ecsv, $KEY ) && ! str_contains( $ecsv, '777.77' ) && ! str_contains( $ecsv, '412.34' ) && ! str_contains( $ecsv, '456.78' ) );
	// Duplicate.
	require_once WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php';
	wp_set_current_user( $SM );
	$dup = ( new WC_Admin_Duplicate_Product() )->product_duplicate( wc_get_product( $P1 ) );
	wp_set_current_user( 0 );
	$dup_id = $dup instanceof WC_Product ? $dup->get_id() : 0;
	pqbg_t( 'Duplicate: the copy has the other meta but no cost (documented: Duplicate does not copy the cost)', $dup_id > 0 && 'marker-9a' === get_post_meta( $dup_id, '_pqbg9a_marker', true ) && 0 === $cost_rows( $dup_id ) );
	// WXR export (Tools → Export) as a shop manager, over HTTP.
	$wx = $http( 'sm', 'GET', admin_url( 'export.php?download=true&content=all' ) );
	pqbg_t( 'WXR export as shop manager: 200 XML with the products and their other protected meta, but no cost', 200 === $wx['code'] && str_contains( $wx['body'], '<rss' ) && str_contains( $wx['body'], 'PQBG 9A Kurta One' ) && str_contains( $wx['body'], '<wp:meta_key><![CDATA[_pqbg9a_marker]]></wp:meta_key>' ) && ! str_contains( $wx['body'], $KEY ) && ! str_contains( $wx['body'], '777.77' ) && ! str_contains( $wx['body'], '456.78' ) );
	$wx = $http( 'admin', 'GET', admin_url( 'export.php?download=true&content=all' ) );
	pqbg_t( 'WXR export as administrator: no cost either (skipped for everyone)', 200 === $wx['code'] && str_contains( $wx['body'], '_pqbg9a_marker' ) && ! str_contains( $wx['body'], $KEY ) && ! str_contains( $wx['body'], '777.77' ) );
	// WXR import as a shop manager (the official WordPress Importer, loaded in-process from outside the site).
	$importer = (string) getenv( 'PQBG_WXR_IMPORTER' );
	if ( '' === $importer || ! is_file( $importer ) ) {
		pqbg_skip( 'WXR import as shop manager cannot write the cost', 'PQBG_WXR_IMPORTER is not set (path to wordpress-importer.php)' );
	} else {
		if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
			define( 'WP_LOAD_IMPORTERS', true );
		}
		require_once $importer;
		$title = 'PQBG 9A WXR import ' . wp_generate_password( 6, false );
		// wp:post_id becomes wp_insert_post()'s import_id, which is used only when that ID is free: an existing ID
		// ($P1) keeps the import on the normal AUTO_INCREMENT. A free, large ID would move wp_posts' AUTO_INCREMENT
		// up to it for good (it happened during development; see progress.md).
		$wxr   = '<?xml version="1.0" encoding="UTF-8" ?><rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/"><channel><title>t</title><wp:wxr_version>1.2</wp:wxr_version><wp:base_site_url>' . esc_url( home_url() ) . '</wp:base_site_url><wp:base_blog_url>' . esc_url( home_url() ) . '</wp:base_blog_url>'
			. '<item><title>' . $title . '</title><dc:creator><![CDATA[nobody]]></dc:creator><content:encoded><![CDATA[]]></content:encoded><excerpt:encoded><![CDATA[]]></excerpt:encoded><wp:post_id>' . $P1 . '</wp:post_id><wp:post_date>2026-01-02 03:04:05</wp:post_date><wp:post_date_gmt>2026-01-01 21:34:05</wp:post_date_gmt><wp:post_name>pqbg-9a-wxr</wp:post_name><wp:status>draft</wp:status><wp:post_parent>0</wp:post_parent><wp:menu_order>0</wp:menu_order><wp:post_type>product</wp:post_type><wp:post_password></wp:post_password><wp:is_sticky>0</wp:is_sticky>'
			. '<wp:postmeta><wp:meta_key><![CDATA[' . $KEY . ']]></wp:meta_key><wp:meta_value><![CDATA[555.55]]></wp:meta_value></wp:postmeta><wp:postmeta><wp:meta_key><![CDATA[_pqbg9a_marker]]></wp:meta_key><wp:meta_value><![CDATA[wxr]]></wp:meta_value></wp:postmeta></item></channel></rss>';
		$file = get_temp_dir() . 'pqbg9a-' . wp_generate_password( 8, false ) . '.xml';
		file_put_contents( $file, $wxr );
		wp_set_current_user( $SM );
		$imp                    = new WP_Import();
		$imp->fetch_attachments = false;
		ob_start();
		$imp->import( $file );
		ob_end_clean();
		wp_set_current_user( 0 );
		unlink( $file );
		$wxr_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s", $title ) );
		pqbg_t( 'WXR import as shop manager (WordPress Importer): the post and its other meta are imported, the cost is not', $wxr_id > 0 && 'wxr' === get_post_meta( $wxr_id, '_pqbg9a_marker', true ) && 0 === $cost_rows( $wxr_id ) );
	}
	// WooCommerce product CSV import as a shop manager.
	require_once WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';
	$csvf = get_temp_dir() . 'pqbg9a-' . wp_generate_password( 8, false ) . '.csv'; // The importer checks the extension.
	$sku  = 'PQBG-9A-CSV-' . wp_generate_password( 6, false );
	$fh   = fopen( $csvf, 'w' );
	fputcsv( $fh, array( 'type', 'sku', 'name', 'published', 'regular_price', 'meta:' . $KEY, 'meta:_pqbg9a_marker' ), ',', '"', '' );
	fputcsv( $fh, array( 'simple', $sku, 'PQBG 9A CSV import', '1', '9', '333.33', 'csv' ), ',', '"', '' );
	fclose( $fh );
	wp_set_current_user( $SM );
	( new WC_Product_CSV_Importer( $csvf, array( 'parse' => true, 'update_existing' => false, 'prevent_timeouts' => false, 'lines' => -1 ) ) )->import();
	wp_set_current_user( 0 );
	unlink( $csvf );
	$csv_id = (int) wc_get_product_id_by_sku( $sku );
	pqbg_t( 'WooCommerce product CSV import as shop manager: other meta imported, the cost not', $csv_id > 0 && 'csv' === get_post_meta( $csv_id, '_pqbg9a_marker', true ) && 0 === $cost_rows( $csv_id ) );
	// Scan screens and labels.
	$sc_s = $http( 'seller', 'GET', $url( $c1 ) );
	$sc_a = $http( 'admin', 'GET', $url( $c1 ) );
	$sc_v = $http( 'admin', 'GET', $url( $code_of( $VV[0] ) ) );
	pqbg_t( 'scan pages (seller and admin, simple and variation): no cost', 200 === $sc_s['code'] && 200 === $sc_a['code'] && 200 === $sc_v['code'] && ! str_contains( $sc_s['body'] . $sc_a['body'], '777.77' ) && ! str_contains( $sc_v['body'], '412.34' ) && ! str_contains( $sc_v['body'], '456.78' ) );
	$labels = PrintPage::build( array( $P1, $V ), PrintJob::options( PrintJob::defaults() ), true );
	$lhtml  = is_array( $labels ) ? PrintPage::render( $labels ) : '';
	pqbg_t( 'labels (print page with every field): no cost', str_contains( $lhtml, 'PQBG 9A Kurta One' ) && ! str_contains( $lhtml, '777.77' ) && ! str_contains( $lhtml, '412.34' ) && ! str_contains( $lhtml, '456.78' ) );

	pqbg_section( 'cost price: guards and deletion paths' );
	wp_set_current_user( $A );
	pqbg_t( 'write guard: update_post_meta() by anyone but CostPrice::set() is refused (even an administrator)', false === update_post_meta( $P1, $KEY, '9.99' ) && false === add_post_meta( $P1, $KEY, '9.99' ) && '777.77' === $raw_cost( $P1 ) && 1 === $cost_rows( $P1 ) );
	wp_set_current_user( $SM );
	pqbg_t( 'delete guard: a logged-in user without pqbg_view_costs cannot delete one item\'s cost', false === delete_post_meta( $P1, $KEY ) && '777.77' === $raw_cost( $P1 ) );
	wp_set_current_user( $A );
	CostPrice::set( $PN, '5' );
	pqbg_t( 'delete guard: an administrator can', true === delete_post_meta( $PN, $KEY ) && 0 === $cost_rows( $PN ) );
	wp_set_current_user( 0 );
	CostPrice::set( $PN, '5' );
	pqbg_t( 'delete guard: requests without a user (cron, CLI, uninstall) can', true === delete_post_meta( $PN, $KEY ) && 0 === $cost_rows( $PN ) );
	// Permanent deletion by a shop manager (meta deleted by ID, never hooked).
	$DS = $make_simple( $A, array( 'name' => 'PQBG 9A delete me' ) );
	CostPrice::set( $DS, '11' );
	wp_set_current_user( $SM );
	wc_get_product( $DS )->delete( true );
	wp_set_current_user( 0 );
	pqbg_t( 'permanently deleting a simple product (as shop manager) removes its cost row', null === get_post( $DS ) && 0 === $cost_rows( $DS ) );
	$DT = $make_simple( $A, array( 'name' => 'PQBG 9A trash me' ) );
	CostPrice::set( $DT, '12' );
	wp_set_current_user( $SM );
	wp_trash_post( $DT );
	$kept_in_trash = 1 === $cost_rows( $DT );
	wp_delete_post( $DT, true );
	wp_set_current_user( 0 );
	pqbg_t( 'trash keeps the cost; deleting from the trash removes it', $kept_in_trash && 0 === $cost_rows( $DT ) );
	list( $DV, $DVV ) = $make_variable( $A, 3 );
	CostPrice::set( $DV, '30' );
	CostPrice::set( $DVV[0], '31' );
	CostPrice::set( $DVV[1], '32' );
	CostPrice::set( $DVV[2], '33' );
	$edv = $http( 'sm', 'GET', $edit_url( $DV ) );
	$rm  = $http( 'sm', 'POST', admin_url( 'admin-ajax.php' ), array( 'action' => 'woocommerce_remove_variations', 'security' => $js_nonce( $edv['body'], 'delete_variations_nonce' ), 'variation_ids' => array( $DVV[0] ) ) );
	$sync();
	pqbg_t( 'removing a variation in the editor (AJAX, shop manager) removes its cost row', 200 === $rm['code'] && null === get_post( $DVV[0] ) && 0 === $cost_rows( $DVV[0] ) && 1 === $cost_rows( $DVV[1] ) );
	$r = $classic_save( 'admin', $DV, array( 'product-type' => 'simple', 'pqbg_cost_price' => '30.00' ) );
	$sync();
	pqbg_t( 'variable → simple: WooCommerce deletes the variations and their cost rows go with them', 302 === $r['code'] && wc_get_product( $DV )->is_type( 'simple' ) && null === get_post( $DVV[1] ) && null === get_post( $DVV[2] ) && 0 === $cost_rows( $DVV[1] ) + $cost_rows( $DVV[2] ) );
	pqbg_t( '…and the parent keeps its value, now as the simple product\'s cost (D5)', '30.00' === CostPrice::get( $DV ), var_export( $raw_cost( $DV ), true ) );
	pqbg_t( 'no orphaned cost meta anywhere (every row belongs to an existing post)', 0 === $orphans() );
	// ------------------------------------------------------------------ seller name
	pqbg_section( 'seller name snapshot and deleted users' );
	wp_update_user( array( 'ID' => $SE2, 'display_name' => 'Ravi Two' ) );
	$g1 = $sell_in( $code_of( $P3 ), 1, $SE2 );
	$g1 = is_array( $g1 ) ? (int) $g1['sale']['id'] : 0;
	wp_update_user( array( 'ID' => $SE2, 'display_name' => 'Ravi Renamed' ) );
	SalePresenter::flush();
	pqbg_t( 'the snapshot is the name at the moment of sale and survives a rename', 'Ravi Two' === $row( $g1 )['seller_name'] && 'Ravi Two' === SalePresenter::seller( $row( $g1 ) ) );
	wp_update_user( array( 'ID' => $SE2, 'display_name' => 'Ravi Two' ) );
	$g3  = $sell_in( $code_of( $P3 ), 1, $SE3 );
	$g3  = is_array( $g3 ) ? (int) $g3['sale']['id'] : 0;
	$lg  = $put( array( 'seller_id' => $SE3, 'seller_name' => null, 'payment_method' => null, 'product_name' => 'Legacy row', 'created_at_gmt' => '2024-01-01 00:00:00' ) );
	$vs0 = $put( array( 'seller_id' => $SE2, 'status' => 'voided', 'voided_by' => 0, 'voided_at_gmt' => '2024-01-01 01:00:00', 'void_reason' => 'system void', 'created_at_gmt' => '2024-01-01 00:30:00', 'product_name' => 'System voided' ) );
	wp_delete_user( $SE3 );
	unset( $user_ids[ $logins[ $SE3 ] ] );
	SalePresenter::flush();
	pqbg_t( 'a deleted seller shows the snapshot plus "(deleted user)"', 'Gone Seller (deleted user)' === SalePresenter::seller( $row( $g3 ) ) );
	pqbg_t( 'a deleted seller without a snapshot (legacy row) shows "User #ID (deleted)"', sprintf( 'User #%d (deleted)', $SE3 ) === SalePresenter::seller( $row( $lg ) ) );
	pqbg_t( 'voided_by 0 shows "System"; a missing user "User #ID (deleted)"', 'System' === SalePresenter::user_label( 0 ) && sprintf( 'User #%d (deleted)', $SE3 ) === SalePresenter::user_label( $SE3 ) );
	pqbg_t( 'a legacy row\'s payment method is "Not recorded"', 'Not recorded' === PaymentMethods::label( $row( $lg )['payment_method'] ) );

	// ------------------------------------------------------------------ history: queries
	pqbg_section( 'history: date ranges in the site timezone (Asia/Kolkata)' );
	pqbg_t( 'the site timezone is Asia/Kolkata (the boundaries below assume +05:30)', 'Asia/Kolkata' === wp_timezone_string() );
	$now  = strtotime( '2026-03-15 01:00:00 +05:30' );
	$want = array(
		'today'      => array( '2026-03-14 18:30:00', '2026-03-15 18:30:00' ),
		'yesterday'  => array( '2026-03-13 18:30:00', '2026-03-14 18:30:00' ),
		'last7'      => array( '2026-03-08 18:30:00', '2026-03-15 18:30:00' ),
		'this_month' => array( '2026-02-28 18:30:00', '2026-03-31 18:30:00' ),
		'last_month' => array( '2026-01-31 18:30:00', '2026-02-28 18:30:00' ),
	);
	$bad = array();
	foreach ( $want as $preset => $w ) {
		$r = SalesQuery::range( $preset, '', '', $now );
		if ( array( $r['start'], $r['end'] ) !== $w ) {
			$bad[] = "$preset: {$r['start']}–{$r['end']}";
		}
	}
	pqbg_t( 'presets at 2026-03-15 01:00 IST: exact UTC bounds for today, yesterday, last 7 days, this month, last month', array() === $bad, implode( '; ', $bad ) );
	$r = SalesQuery::range( 'today', '', '', strtotime( '2026-03-14 18:29:59 UTC' ) );
	$r2 = SalesQuery::range( 'today', '', '', strtotime( '2026-03-14 18:30:00 UTC' ) );
	pqbg_t( '"today" flips at 00:00 IST (18:30 UTC), not at UTC midnight', '2026-03-13 18:30:00' === $r['start'] && '2026-03-14 18:30:00' === $r2['start'] && '2026-03-14' === $r['from'] && '2026-03-15' === $r2['from'] );
	$r = SalesQuery::range( 'custom', '2025-03-12', '2025-03-10' );
	pqbg_t( 'custom: both days inclusive; from after to is swapped (and flagged)', '2025-03-09 18:30:00' === $r['start'] && '2025-03-12 18:30:00' === $r['end'] && $r['swapped'] && '2025-03-10' === $r['from'] && '2025-03-12' === $r['to'] );
	$r = SalesQuery::range( 'custom', '2025-02-30', 'nonsense' );
	pqbg_t( 'custom with invalid dates → today', 'today' === $r['preset'] );
	$r = SalesQuery::range( 'custom', '2025-03-10', '' );
	pqbg_t( 'custom with one date → that single day', '2025-03-09 18:30:00' === $r['start'] && '2025-03-10 18:30:00' === $r['end'] );
	$f = SalesQuery::filters( array( 'range' => 'bogus', 'seller' => '1 OR 1=1', 'method' => 'bitcoin', 'status' => 'pending', 'orderby' => 'sleep(1)', 'order' => 'DESC;', 'paged' => '-3', 's' => str_repeat( 'x', 300 ) ) );
	pqbg_t( 'filters(): unknown or malicious values fall back to "any" / defaults', 'today' === $f['range']['preset'] && 0 === $f['seller'] && '' === $f['method'] && '' === $f['status'] && 'date' === $f['orderby'] && 'desc' === $f['order'] && 1 === $f['paged'] && 100 === mb_strlen( $f['search'] ) );

	pqbg_section( 'history: filters, sorting, totals and profit (fixed dataset, 10–12 March 2025)' );
	$code_row = CodeRepository::find_by_code( $c1 );
	$d        = array();
	$d['r1']  = $put( array( 'seller_id' => $SE, 'seller_name' => 'S One', 'payment_method' => 'cash', 'quantity' => 2, 'unit_price' => '100', 'line_total' => '200', 'unit_cost' => '60', 'product_name' => 'Alpha Kurta', 'sku' => 'ALPHA-1', 'created_at_gmt' => $ist( '2025-03-10 10:00:00' ) ) );
	$d['r2']  = $put( array( 'seller_id' => $SE, 'seller_name' => 'S One', 'payment_method' => 'upi', 'unit_price' => '300', 'line_total' => '300', 'unit_cost' => null, 'product_name' => 'Beta Saree', 'sku' => 'BETA-2', 'created_at_gmt' => $ist( '2025-03-10 23:59:59' ) ) );
	$d['r3']  = $put( array( 'seller_id' => $SE2, 'seller_name' => 'S Two', 'payment_method' => 'card', 'unit_price' => '500', 'line_total' => '500', 'unit_cost' => '450', 'product_name' => 'Gamma Dupatta', 'sku' => 'GAMMA-3', 'code_id' => (int) $code_row['id'], 'created_at_gmt' => $ist( '2025-03-11 00:00:00' ) ) );
	$d['r4']  = $put( array( 'seller_id' => $SE2, 'seller_name' => 'S Two', 'payment_method' => 'cash', 'quantity' => 3, 'unit_price' => '100', 'line_total' => '300', 'unit_cost' => '120', 'product_name' => 'Alpha Kurta', 'sku' => 'ALPHA-1', 'created_at_gmt' => $ist( '2025-03-11 12:00:00' ) ) );
	$d['r5']  = $put( array( 'seller_id' => $SE, 'seller_name' => 'S One', 'payment_method' => 'cash', 'unit_price' => '1000', 'line_total' => '1000', 'unit_cost' => '400', 'product_name' => 'Delta Voided', 'status' => 'voided', 'voided_by' => $SM, 'voided_at_gmt' => $ist( '2025-03-11 13:30:00' ), 'void_reason' => 'wrong size', 'created_at_gmt' => $ist( '2025-03-11 13:00:00' ) ) );
	$d['r6']  = $put( array( 'seller_id' => $SE2, 'seller_name' => 'S Two', 'payment_method' => 'upi', 'unit_price' => '700', 'line_total' => '700', 'product_name' => 'Epsilon Failed', 'status' => 'failed', 'failure_code' => 'sold_online', 'created_at_gmt' => $ist( '2025-03-11 14:00:00' ) ) );
	$d['r7']  = $put( array( 'seller_id' => $SE, 'seller_name' => null, 'payment_method' => null, 'unit_price' => '250', 'line_total' => '250', 'unit_cost' => null, 'product_name' => 'Zeta Legacy', 'created_at_gmt' => $ist( '2025-03-12 09:00:00' ) ) );
	$d['r8']  = $put( array( 'seller_id' => $SE, 'unit_price' => '999', 'line_total' => '999', 'product_name' => 'Before range', 'created_at_gmt' => $ist( '2025-03-09 23:59:59' ) ) );
	$d['r9']  = $put( array( 'seller_id' => $SE, 'unit_price' => '888', 'line_total' => '888', 'product_name' => 'After range', 'created_at_gmt' => $ist( '2025-03-13 00:00:00' ) ) );
	$F   = static fn( array $extra = array() ) => SalesQuery::filters( array_merge( array( 'range' => 'custom', 'from' => '2025-03-10', 'to' => '2025-03-12' ), $extra ) );
	$ids = static fn( array $f ) => array_map( 'intval', array_column( SalesQuery::rows( $f, 100 ), 'id' ) );
	$set = static fn( string ...$k ) => array_map( static fn( $x ) => $d[ $x ], $k );
	$same = static function ( array $a, array $b ): bool {
		sort( $a );
		sort( $b );
		return $a === $b;
	};
	pqbg_t( 'range 10–12 March: 7 rows, the rows at 23:59:59 on the 9th and 00:00 on the 13th (IST) excluded', $same( $ids( $F() ), $set( 'r1', 'r2', 'r3', 'r4', 'r5', 'r6', 'r7' ) ) && 7 === SalesQuery::count( $F() ) );
	pqbg_t( 'day boundaries: 10 March holds r1 and r2 (23:59:59 IST); r3 at 00:00:00 IST belongs to the 11th', $same( $ids( $F( array( 'to' => '2025-03-10' ) ) ), $set( 'r1', 'r2' ) ) && $same( $ids( $F( array( 'from' => '2025-03-11', 'to' => '2025-03-11' ) ) ), $set( 'r3', 'r4', 'r5', 'r6' ) ) );
	pqbg_t( 'seller filter', $same( $ids( $F( array( 'seller' => (string) $SE ) ) ), $set( 'r1', 'r2', 'r5', 'r7' ) ) );
	pqbg_t( 'payment filter: cash; "not recorded" (NULL)', $same( $ids( $F( array( 'method' => 'cash' ) ) ), $set( 'r1', 'r4', 'r5' ) ) && $same( $ids( $F( array( 'method' => 'none' ) ) ), $set( 'r7' ) ) );
	pqbg_t( 'status filter: voided; failed; completed', $same( $ids( $F( array( 'status' => 'voided' ) ) ), $set( 'r5' ) ) && $same( $ids( $F( array( 'status' => 'failed' ) ) ), $set( 'r6' ) ) && $same( $ids( $F( array( 'status' => 'completed' ) ) ), $set( 'r1', 'r2', 'r3', 'r4', 'r7' ) ) );
	pqbg_t( 'search: product name (case-insensitive substring), SKU, and the product code (typed or as a scan URL)', $same( $ids( $F( array( 's' => 'alpha' ) ) ), $set( 'r1', 'r4' ) ) && $same( $ids( $F( array( 's' => 'GAMMA-3' ) ) ), $set( 'r3' ) ) && $same( $ids( $F( array( 's' => $c1 ) ) ), $set( 'r3' ) ) && $same( $ids( $F( array( 's' => strtolower( $c1 ) ) ) ), $set( 'r3' ) ) && $same( $ids( $F( array( 's' => ScanUrl::for_code( $c1 ) ) ) ), $set( 'r3' ) ) );
	pqbg_t( 'search: LIKE wildcards are literal (% and _ match nothing here)', array() === $ids( $F( array( 's' => '%' ) ) ) && array() === $ids( $F( array( 's' => '_' ) ) ) );
	pqbg_t( 'combined filters (seller 2 + cash + completed)', $same( $ids( $F( array( 'seller' => (string) $SE2, 'method' => 'cash', 'status' => 'completed' ) ) ), $set( 'r4' ) ) );
	pqbg_t( 'sort: date desc by default; total asc with id as the tie-break', $set( 'r7', 'r6', 'r5', 'r4', 'r3', 'r2', 'r1' ) === $ids( $F() ) && $set( 'r1', 'r7', 'r2', 'r4', 'r3', 'r6', 'r5' ) === $ids( $F( array( 'orderby' => 'total', 'order' => 'asc' ) ) ) );
	pqbg_t( 'sort: product asc, quantity desc, sale # asc', $set( 'r1', 'r4', 'r2', 'r5', 'r6', 'r3', 'r7' ) === $ids( $F( array( 'orderby' => 'product', 'order' => 'asc' ) ) ) && $d['r4'] === $ids( $F( array( 'orderby' => 'qty' ) ) )[0] && $set( 'r1', 'r2', 'r3', 'r4', 'r5', 'r6', 'r7' ) === $ids( $F( array( 'orderby' => 'id', 'order' => 'asc' ) ) ) );
	$t = SalesQuery::totals( $F() );
	pqbg_t( 'totals: completed only — 5 sales, 8 items, ₹1,550.00', 5 === $t['all']['count'] && 8 === $t['all']['items'] && '1550.00' === $t['all']['revenue'] );
	pqbg_t( 'totals per method: cash 500 (2), upi 300, card 500, not recorded 250; display order', array( 'cash', 'upi', 'card', '' ) === array_map( 'strval', array_keys( $t['methods'] ) ) && '500.00' === $t['methods']['cash']['revenue'] && 2 === $t['methods']['cash']['count'] && '300.00' === $t['methods']['upi']['revenue'] && '500.00' === $t['methods']['card']['revenue'] && '250.00' === $t['methods']['']['revenue'] );
	pqbg_t( 'cost and profit exclude unknown-cost lines: cost 930, profit 70 (incl. the −60 loss), 2 unknown lines worth ₹550', '930.00' === $t['all']['cost'] && '70.00' === $t['all']['profit'] && 3 === $t['all']['known'] && 2 === $t['all']['unknown'] && '550.00' === $t['all']['unknown_revenue'] );
	pqbg_t( 'per-method profit: cash 20 (r1 +80, r4 −60); upi profit 0 over 0 known lines', '20.00' === $t['methods']['cash']['profit'] && 0 === $t['methods']['upi']['known'] && 1 === $t['methods']['upi']['unknown'] );
	pqbg_t( 'the view\'s voided and failed counts', 1 === $t['voided'] && 1 === $t['failed'] );
	$tv = SalesQuery::totals( $F( array( 'status' => 'voided' ) ) );
	pqbg_t( 'status filter "voided": no completed totals, 1 voided', 0 === $tv['all']['count'] && '0' === $tv['all']['revenue'] && 1 === $tv['voided'] );
	$tm = SalesQuery::totals( $F( array( 'method' => 'cash' ) ) );
	pqbg_t( 'totals follow the other filters (cash: 2 sales, ₹500.00, 1 voided)', 2 === $tm['all']['count'] && '500.00' === $tm['all']['revenue'] && 1 === $tm['voided'] );
	pqbg_t( 'line profit: known → total − qty × cost; unknown → null', '80.00' === SalePresenter::profit( $row( $d['r1'] ) ) && '-60.00' === SalePresenter::profit( $row( $d['r4'] ) ) && null === SalePresenter::profit( $row( $d['r2'] ) ) );
	// ------------------------------------------------------------------ history: HTTP
	pqbg_section( 'history: the screens over HTTP' );
	$range_args = array( 'range' => 'custom', 'from' => '2025-03-10', 'to' => '2025-03-12' );
	$ha = $http( 'admin', 'GET', $hist_url( $range_args ) );
	$hs = $http( 'sm', 'GET', $hist_url( $range_args ) );
	pqbg_t( 'administrator and shop manager: 200', 200 === $ha['code'] && 200 === $hs['code'] && str_contains( $ha['body'], '<h1 class="wp-heading-inline">In-store sales</h1>' ) );
	pqbg_t( 'the table lists the 7 rows newest first (filters in the URL)', $set( 'r7', 'r6', 'r5', 'r4', 'r3', 'r2', 'r1' ) === $listed( $ha['body'] ) && $listed( $ha['body'] ) === $listed( $hs['body'] ) );
	pqbg_t( 'totals bar: "Completed in this view: 5 sales · 8 items · ₹1,550.00" and per method', str_contains( $ha['body'], 'Completed in this view: 5 sales · 8 items · ₹1,550.00' ) && str_contains( $ha['body'], 'Cash ₹500.00 · UPI ₹300.00 · Card ₹500.00 · Not recorded ₹250.00' ) && str_contains( $ha['body'], 'Also in this view: 1 voided · 1 failed' ) );
	pqbg_t( 'administrator: cost and profit, with the unknown-cost lines named', str_contains( $ha['body'], 'Cost ₹930.00 · Profit ₹70.00 — excludes 2 lines with unknown cost (₹550.00)' ) && str_contains( $ha['body'], '>Unit cost<' ) && str_contains( $ha['body'], '₹450.00' ) && str_contains( $ha['body'], '-₹60.00' ) && str_contains( $ha['body'], '>unknown<' ) );
	pqbg_t( 'shop manager: same rows and totals, but no cost, profit or unit cost anywhere', str_contains( $hs['body'], 'Completed in this view: 5 sales · 8 items · ₹1,550.00' ) && ! str_contains( $hs['body'], 'Cost ₹' ) && ! str_contains( $hs['body'], 'Profit' ) && ! str_contains( $hs['body'], 'Unit cost' ) && ! str_contains( $hs['body'], '₹450.00' ) );
	$hx = $http( 'admin', 'GET', $hist_url( $range_args + array( 'orderby' => 'total', 'order' => 'asc' ) ) );
	pqbg_t( 'sorting from the URL (total ascending)', $set( 'r1', 'r7', 'r2', 'r4', 'r3', 'r6', 'r5' ) === $listed( $hx['body'] ) );
	$hx = $http( 'admin', 'GET', $hist_url( $range_args + array( 'orderby' => "id`; DROP TABLE x; --", 'order' => 'sideways' ) ) );
	pqbg_t( 'a malicious sort is ignored (date, newest first)', 200 === $hx['code'] && $set( 'r7', 'r6', 'r5', 'r4', 'r3', 'r2', 'r1' ) === $listed( $hx['body'] ) );
	$hx = $http( 'admin', 'GET', $hist_url( $range_args + array( 'method' => 'none' ) ) );
	pqbg_t( 'filter from the URL (Paid by: Not recorded)', $set( 'r7' ) === $listed( $hx['body'] ) && str_contains( $hx['body'], '<option value="none" selected=\'selected\'>Not recorded</option>' ) );
	for ( $i = 0; $i < 120; $i++ ) {
		$put( array( 'seller_id' => $SE2, 'seller_name' => 'Pager', 'product_name' => 'Page item ' . $i, 'created_at_gmt' => $ist( '2025-04-15 10:00:00' ) ) );
	}
	$pa   = array( 'range' => 'custom', 'from' => '2025-04-15', 'to' => '2025-04-15' );
	$pg1  = $http( 'admin', 'GET', $hist_url( $pa ) );
	$pg3  = $http( 'admin', 'GET', $hist_url( $pa + array( 'paged' => '3' ) ) );
	$pg99 = $http( 'admin', 'GET', $hist_url( $pa + array( 'paged' => '99' ) ) );
	pqbg_t( 'pagination: 50 per page, 120 rows → 50 / … / 20; a page past the end redirects to the last page (WP_List_Table), filters kept', 50 === count( $listed( $pg1['body'] ) ) && 20 === count( $listed( $pg3['body'] ) ) && 302 === $pg99['code'] && str_contains( $pg99['location'], 'paged=3' ) && str_contains( $pg99['location'], 'from=2025-04-15' ) && str_contains( $pg1['body'], '120 items' ), count( $listed( $pg1['body'] ) ) . '/' . count( $listed( $pg3['body'] ) ) . '/' . count( $listed( $pg99['body'] ) ) . ' ' . ( preg_match( '/[0-9]+ items/', $pg1['body'], $mm9 ) ? $mm9[0] : '-' ) );
	pqbg_t( 'pagination links keep the filters', (bool) preg_match( '/href=[\'"][^\'"]*paged=2[^\'"]*[\'"]/', $pg1['body'] ) && (bool) preg_match( '/href=[\'"][^\'"]*range=custom[^\'"]*paged=2|href=[\'"][^\'"]*paged=2[^\'"]*range=custom/', html_entity_decode( $pg1['body'] ) ) );
	$dt = $http( 'admin', 'GET', $hist_url( array( 'sale' => (string) $d['r5'] ) ) );
	pqbg_t( 'detail: status, every snapshot and the timeline (voided by whom, when, why)', 200 === $dt['code'] && str_contains( $dt['body'], 'Sale #' . $d['r5'] . ' — Voided' ) && str_contains( $dt['body'], 'Delta Voided' ) && str_contains( $dt['body'], 'by PQBG Manager — “wrong size”' ) && str_contains( $dt['body'], '>Paid by<' ) && str_contains( $dt['body'], '(product no longer exists)' ) );
	pqbg_t( 'detail: a voided sale has no "Void sale" button; the administrator sees unit cost and profit ("not counted")', ! str_contains( $dt['body'], 'pqbg_view=void' ) && str_contains( $dt['body'], '>Unit cost<' ) && str_contains( $dt['body'], '(not counted: the sale is not completed)' ) );
	$dt3 = $http( 'sm', 'GET', $hist_url( array( 'sale' => (string) $d['r3'] ) ) );
	pqbg_t( 'detail as shop manager: the product code, "Void sale", and no cost', 200 === $dt3['code'] && str_contains( $dt3['body'], $c1 ) && str_contains( $dt3['body'], 'pqbg_view=void' ) && ! str_contains( $dt3['body'], 'Unit cost' ) && ! str_contains( $dt3['body'], '₹450.00' ) );
	$dt6 = $http( 'admin', 'GET', $hist_url( array( 'sale' => (string) $d['r6'] ) ) );
	pqbg_t( 'detail: a failed sale shows its failure code', str_contains( $dt6['body'], '<code>sold_online</code>' ) && str_contains( $dt6['body'], 'Sale #' . $d['r6'] . ' — Failed' ) );
	$dg = $http( 'admin', 'GET', $hist_url( array( 'sale' => (string) $g1 ) ) );
	pqbg_t( 'detail: a link to the product while it exists', str_contains( $dg['body'], 'Edit product #' . $P3 ) );
	$dlg = $http( 'admin', 'GET', $hist_url( array( 'sale' => (string) $g3 ) ) );
	$dvs = $http( 'admin', 'GET', $hist_url( array( 'sale' => (string) $vs0 ) ) );
	pqbg_t( 'detail: deleted seller "(deleted user)"; voided by 0 → "System"', str_contains( $dlg['body'], 'by Gone Seller (deleted user)' ) && str_contains( $dvs['body'], 'by System — “system void”' ) );
	pqbg_t( 'detail: an unknown sale is a clean 404', 404 === $http( 'admin', 'GET', $hist_url( array( 'sale' => '999999999' ) ) )['code'] );
	pqbg_t( 'detail: a malformed sale ID shows the list', 200 === ( $x = $http( 'admin', 'GET', $hist_url( array( 'sale' => '12abc' ) ) ) )['code'] && str_contains( $x['body'], 'Completed in this view' ) );
	$today = $http( 'admin', 'GET', $hist_url() );
	pqbg_t( 'default view is Today (the preset link is current)', 200 === $today['code'] && (bool) preg_match( '/<a href="[^"]*range=today"[^>]*class="current"/', $today['body'] ) );
	pqbg_t( 'escaping: the seller\'s HTML display name is shown as text', str_contains( $today['body'], '&lt;img src=x onerror=alert(1)&gt; Priya' ) && ! str_contains( $today['body'], '<img src=x onerror' ) );
	$menu = $from( $today['body'], 'id="toplevel_page_woocommerce"' );
	$menu = substr( $menu, 0, (int) strpos( $menu, '</ul>' ) );
	preg_match_all( '/href=[\'"](?:admin\.php\?page=|[^\'"]*page=)([a-z0-9_-]+)/', $menu, $mm );
	$pos = array_search( 'wc-orders', $mm[1], true );
	pqbg_t( 'menu: WooCommerce → In-store sales, right after Orders', false !== $pos && 'pqbg-sales' === ( $mm[1][ $pos + 1 ] ?? '' ) && str_contains( $menu, 'In-store sales' ) );

	// ------------------------------------------------------------------ CSV
	pqbg_section( 'CSV export' );
	$csv_of = static function ( array $filters, bool $costs ): string {
		$fh = fopen( 'php://temp', 'w+' );
		SalesExport::write( $fh, $filters, $costs );
		rewind( $fh );
		$out = stream_get_contents( $fh );
		fclose( $fh );
		return (string) $out;
	};
	$ca = $csv_of( $F(), true );
	$cs = $csv_of( $F(), false );
	$ra = $csv_rows( substr( $ca, 3 ) );
	$rs = $csv_rows( substr( $cs, 3 ) );
	pqbg_t( 'UTF-8 with a byte order mark', "\xEF\xBB\xBF" === substr( $ca, 0, 3 ) && "\xEF\xBB\xBF" === substr( $cs, 0, 3 ) );
	pqbg_t( 'administrator: 20 columns ending with Unit cost (₹), Cost (₹), Profit (₹); shop manager: 17, no cost column', 20 === count( $ra[0] ) && array( 'Unit cost (₹)', 'Cost (₹)', 'Profit (₹)' ) === array_slice( $ra[0], -3 ) && 17 === count( $rs[0] ) && ! preg_grep( '/cost|profit/i', $rs[0] ) );
	pqbg_t( 'the date column is in the site timezone', 'Date (Asia/Kolkata)' === $ra[0][0] );
	pqbg_t( 'exactly the filtered rows, in the view\'s order (7)', 8 === count( $ra ) && array_map( 'strval', $set( 'r7', 'r6', 'r5', 'r4', 'r3', 'r2', 'r1' ) ) === array_column( array_slice( $ra, 1 ), 1 ) );
	$byid = array_column( array_slice( $ra, 1 ), null, 1 );
	pqbg_t( 'row values: r3 at 2025-03-11 00:00:00 (IST), code, plain decimals', '2025-03-11 00:00:00' === $byid[ $d['r3'] ][0] && $c1 === $byid[ $d['r3'] ][6] && '500.00' === $byid[ $d['r3'] ][9] && 'Card' === $byid[ $d['r3'] ][11] && '450.00' === $byid[ $d['r3'] ][17] && '50.00' === $byid[ $d['r3'] ][19] );
	pqbg_t( 'a loss stays a number (-60.00, not neutralised); unknown cost → empty cells', '-60.00' === $byid[ $d['r4'] ][19] && '360.00' === $byid[ $d['r4'] ][18] && '' === $byid[ $d['r2'] ][17] && '' === $byid[ $d['r2'] ][19] );
	pqbg_t( 'voided row: who, when and why; its profit is not given', 'PQBG Manager' === $byid[ $d['r5'] ][14] && 'wrong size' === $byid[ $d['r5'] ][15] && '' === $byid[ $d['r5'] ][19] && 'Voided' === $byid[ $d['r5'] ][2] );
	pqbg_t( 'legacy row: "Not recorded"; failed row: its failure code', 'Not recorded' === $byid[ $d['r7'] ][11] && 'sold_online' === $byid[ $d['r6'] ][16] );
	$inj = array( '=1+2', '+SUM(A1)', '-2+3', '@cmd', "\tTAB", "\rCR", 'normal -5', '-3.50' );
	foreach ( $inj as $i => $name ) {
		$put( array( 'seller_id' => $SE2, 'seller_name' => 0 === $i ? '@seller' : 'S Two', 'product_name' => $name, 'sku' => 0 === $i ? '=x' : null, 'created_at_gmt' => $ist( '2025-05-01 10:0' . $i . ':00' ), 'status' => 1 === $i ? 'voided' : 'completed', 'void_reason' => 1 === $i ? '=evil()' : null, 'voided_by' => 1 === $i ? $SM : null, 'voided_at_gmt' => 1 === $i ? $ist( '2025-05-01 11:00:00' ) : null ) );
	}
	$ci   = $csv_rows( substr( $csv_of( SalesQuery::filters( array( 'range' => 'custom', 'from' => '2025-05-01', 'to' => '2025-05-01', 'orderby' => 'id', 'order' => 'asc' ) ), true ), 3 ) );
	$names = array_column( array_slice( $ci, 1 ), 3 );
	pqbg_t( 'formula injection: = + - @ tab and CR at the start get a leading apostrophe', array( "'=1+2", "'+SUM(A1)", "'-2+3", "'@cmd", "'\tTAB", "'\rCR", 'normal -5', '-3.50' ) === $names, wp_json_encode( $names ) );
	pqbg_t( '…also in the SKU, the seller and the void reason', "'=x" === $ci[1][5] && "'@seller" === $ci[1][12] && "'=evil()" === $ci[2][15] );
	pqbg_t( 'neutralise(): numbers stay numbers, text is prefixed', '-120.00' === SalesExport::neutralise( '-120.00' ) && '42' === SalesExport::neutralise( '42' ) && "'-" === SalesExport::neutralise( '-' ) && "'-1e5" === SalesExport::neutralise( '-1e5' ) && 'a=b' === SalesExport::neutralise( 'a=b' ) );
	$chunks = 0;
	$rows_n = SalesQuery::each_chunk( $F(), static function ( array $rows ) use ( &$chunks ) {
		++$chunks;
	} );
	pqbg_t( 'streaming: rows are read in chunks (7 rows → 1 chunk; 50,000 rows below)', 7 === $rows_n && 1 === $chunks );
	preg_match( '/<a class="page-title-action" href="([^"]+)">Export CSV<\/a>/', $ha['body'], $m );
	$csv_url_a = html_entity_decode( $m[1] ?? '' );
	preg_match( '/<a class="page-title-action" href="([^"]+)">Export CSV<\/a>/', $hs['body'], $m );
	$csv_url_s = html_entity_decode( $m[1] ?? '' );
	$h = $http( 'admin', 'GET', $csv_url_a );
	pqbg_t( 'HTTP: the "Export CSV" link of the current view downloads it (nonce, GET)', 200 === $h['code'] && str_contains( $csv_url_a, 'action=pqbg_sales_csv' ) && str_contains( $csv_url_a, '_wpnonce=' ) && str_contains( $csv_url_a, 'from=2025-03-10' ) && "\xEF\xBB\xBF" === substr( $h['body'], 0, 3 ) && 8 === count( $csv_rows( substr( $h['body'], 3 ) ) ) );
	pqbg_t( 'HTTP: headers — text/csv UTF-8, attachment with the range in the name, nosniff, no-store', 'text/csv; charset=utf-8' === ( $h['headers']['content-type'] ?? '' ) && 'attachment; filename="in-store-sales-2025-03-10-to-2025-03-12.csv"' === ( $h['headers']['content-disposition'] ?? '' ) && 'nosniff' === ( $h['headers']['x-content-type-options'] ?? '' ) && str_contains( $h['headers']['cache-control'] ?? '', 'no-store' ) );
	$h = $http( 'sm', 'GET', $csv_url_s );
	pqbg_t( 'HTTP: shop manager gets the same rows without the cost columns', 200 === $h['code'] && 17 === count( $csv_rows( substr( $h['body'], 3 ) )[0] ) && ! str_contains( $h['body'], '450.00' ) && ! str_contains( $h['body'], '777.77' ) );
	pqbg_t( 'HTTP: a bad or missing nonce → 403', 403 === $http( 'admin', 'GET', add_query_arg( '_wpnonce', 'abc', $csv_url_a ) )['code'] && 403 === $http( 'admin', 'GET', remove_query_arg( '_wpnonce', $csv_url_a ) )['code'] );
	pqbg_t( 'HTTP: seller and customer → 403 (even with a manager\'s link)', 403 === $http( 'seller', 'GET', $csv_url_a )['code'] && 403 === $http( 'customer', 'GET', $csv_url_a )['code'] );
	$h = $http( 'anon', 'GET', $csv_url_a );
	pqbg_t( 'HTTP: logged out → no data (no nopriv handler)', 200 !== $h['code'] && ! str_contains( $h['body'], 'Alpha Kurta' ) );
	$h = $http( 'admin', 'POST', $csv_url_a, array( 'x' => '1' ) );
	pqbg_t( 'HTTP: POST → 405', 405 === $h['code'] );
	$h = $http( 'admin', 'HEAD', $csv_url_a );
	pqbg_t( 'HTTP: HEAD → 200 with the CSV headers, no body', 200 === $h['code'] && '' === $h['body'] && 'text/csv; charset=utf-8' === ( $h['headers']['content-type'] ?? '' ) );
	// ------------------------------------------------------------------ void
	pqbg_section( 'void over HTTP (shop manager)' );
	$PV = $make_simple( $A, array( 'name' => 'PQBG 9A Void Item' ) );
	$sync();
	$cv = $code_of( $PV );
	$vs = array();
	foreach ( array( 'a', 'b', 'c', 'd', 'e' ) as $k ) {
		$x        = $sell_in( $cv, 1, $SE );
		$vs[ $k ] = is_array( $x ) ? (int) $x['sale']['id'] : 0;
	}
	$void_url = static fn( int $id ) => $hist_url( array( 'sale' => (string) $id, 'pqbg_view' => 'void' ) );
	$vp       = $http( 'sm', 'GET', $void_url( $vs['a'] ) );
	$vnonce   = $field( $vp['body'], '_pqbg_nonce' );
	pqbg_t( 'void screen: required reason (max 500), "Return 1 to stock (stock now 15)" ticked by default, POST form', 200 === $vp['code'] && (bool) preg_match( '/<textarea id="pqbg-void-reason" name="reason" rows="3" cols="60" maxlength="500" required><\/textarea>/', $vp['body'] ) && str_contains( $vp['body'], '<input type="checkbox" name="restock" value="1" checked> Return 1 to stock (stock now 15)' ) && str_contains( $vp['body'], 'method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' ) && '' !== $vnonce );
	$void = static fn( string $who, int $id, string $nonce, array $extra ) => $http( $who, 'POST', admin_url( 'admin-post.php' ), array_merge( array( 'action' => 'pqbg_void_sale', 'sale' => (string) $id, '_pqbg_nonce' => $nonce ), $extra ) );
	$st   = $stock( $PV );
	$r    = $void( 'sm', $vs['a'], $vnonce, array( 'reason' => '', 'restock' => '1' ) );
	pqbg_t( 'empty reason → 303 back to the void screen ("Enter a reason"), nothing changed', 303 === $r['code'] && str_contains( $r['location'], 'pqbg_view=void' ) && str_contains( $r['location'], 'pqbg_msg=reason_required' ) && 'completed' === $row( $vs['a'] )['status'] && $st === $stock( $PV ) );
	$r = $void( 'sm', $vs['a'], $vnonce, array( 'reason' => "   \n ", 'restock' => '1' ) );
	pqbg_t( 'whitespace-only reason → refused the same way', str_contains( $r['location'], 'pqbg_msg=reason_required' ) && 'completed' === $row( $vs['a'] )['status'] );
	$r = $void( 'sm', $vs['a'], $vnonce, array( 'reason' => str_repeat( 'x', 501 ), 'restock' => '1' ) );
	pqbg_t( '501 characters → refused ("at most 500"), nothing changed', str_contains( $r['location'], 'pqbg_msg=reason_long' ) && 'completed' === $row( $vs['a'] )['status'] );
	$shown = $http( 'sm', 'GET', $r['location'] );
	pqbg_t( '…and the void screen shows that message', str_contains( $shown['body'], 'The reason can be at most 500 characters.' ) );
	$r = $void( 'sm', $vs['a'], 'abc', array( 'reason' => 'x', 'restock' => '1' ) );
	pqbg_t( 'bad nonce → 403, nothing changed', 403 === $r['code'] && 'completed' === $row( $vs['a'] )['status'] );
	$r = $void( 'sm', $vs['b'], $vnonce, array( 'reason' => 'x', 'restock' => '1' ) );
	pqbg_t( 'another sale\'s nonce → 403', 403 === $r['code'] && 'completed' === $row( $vs['b'] )['status'] );
	$r    = $void( 'sm', $vs['a'], $vnonce, array( 'reason' => 'Returned & refunded "quoted"', 'restock' => '1' ) );
	$va   = $row( $vs['a'] );
	pqbg_t( 'valid void with restock → 303 to the detail; row voided by the manager with the reason; stock restored', 303 === $r['code'] && str_contains( $r['location'], 'pqbg_msg=voided_restocked' ) && ! str_contains( $r['location'], 'pqbg_view' ) && 'voided' === $va['status'] && $SM === (int) $va['voided_by'] && 'Returned & refunded "quoted"' === $va['void_reason'] && $st + 1 === $stock( $PV ) );
	$dp = $http( 'sm', 'GET', $r['location'] );
	pqbg_t( 'the detail shows the notice and the timeline, escaped', str_contains( $dp['body'], 'Sale voided. The quantity was returned to stock.' ) && str_contains( $dp['body'], 'by PQBG Manager — “Returned &amp; refunded &quot;quoted&quot;”' ) );
	$r = $void( 'sm', $vs['a'], $vnonce, array( 'reason' => 'again', 'restock' => '1' ) );
	pqbg_t( 'a second void is refused cleanly (303, "already voided"), nothing changed', 303 === $r['code'] && str_contains( $r['location'], 'pqbg_msg=not_voidable' ) && $va === $row( $vs['a'] ) && $st + 1 === $stock( $PV ) );
	pqbg_t( 'the void screen of a voided sale offers no form', str_contains( ( $x = $http( 'sm', 'GET', $void_url( $vs['a'] ) ) )['body'], 'Only a completed sale can be voided.' ) && ! str_contains( $x['body'], '<textarea' ) );
	$st = $stock( $PV );
	$vp = $http( 'sm', 'GET', $void_url( $vs['b'] ) );
	$r  = $void( 'sm', $vs['b'], $field( $vp['body'], '_pqbg_nonce' ), array( 'reason' => 'no restock' ) );
	pqbg_t( 'void with "Return to stock" unticked → voided, stock unchanged', str_contains( $r['location'], 'pqbg_msg=voided' ) && 'voided' === $row( $vs['b'] )['status'] && $st === $stock( $PV ) );
	$vp   = $http( 'sm', 'GET', $void_url( $vs['c'] ) );
	$hold = $hold_lock( $PV, 8 );
	$r    = $void( 'sm', $vs['c'], $field( $vp['body'], '_pqbg_nonce' ), array( 'reason' => 'busy', 'restock' => '1' ) );
	$end_hold( $hold );
	pqbg_t( 'the stock lock is held by another process → "try again", nothing changed (the service\'s lock)', $hold[2] && str_contains( $r['location'], 'pqbg_msg=busy' ) && 'completed' === $row( $vs['c'] )['status'] && $st === $stock( $PV ) );
	wp_set_current_user( $A );
	$pv = wc_get_product( $PV );
	$pv->set_manage_stock( false );
	$pv->save();
	wp_set_current_user( 0 );
	$vp = $http( 'sm', 'GET', $void_url( $vs['d'] ) );
	$vn = $field( $vp['body'], '_pqbg_nonce' );
	$r  = $void( 'sm', $vs['d'], $vn, array( 'reason' => 'tracking off', 'restock' => '1' ) );
	pqbg_t( 'stock tracking turned off → restock refused with advice, nothing changed', str_contains( $r['location'], 'pqbg_msg=restock_unavailable' ) && 'completed' === $row( $vs['d'] )['status'] );
	$r = $void( 'sm', $vs['d'], $vn, array( 'reason' => 'tracking off' ) );
	pqbg_t( '…and voiding without restock then works', str_contains( $r['location'], 'pqbg_msg=voided' ) && 'voided' === $row( $vs['d'] )['status'] );
	wp_set_current_user( $A );
	$pv = wc_get_product( $PV );
	$pv->set_manage_stock( true );
	$pv->set_stock_quantity( 20 );
	$pv->save();
	wp_set_current_user( 0 );
	pqbg_t( 'a sale of any age can be voided (the 2025 fixture row)', str_contains( $http( 'sm', 'GET', $void_url( $d['r1'] ) )['body'], '<textarea' ) );
	pqbg_t( 'GET on the handler → 405', 405 === $http( 'sm', 'GET', admin_url( 'admin-post.php?action=pqbg_void_sale&sale=' . $vs['e'] . '&reason=x&_pqbg_nonce=' . $vn ) )['code'] && 'completed' === $row( $vs['e'] )['status'] );
	pqbg_t( 'seller and customer → 403 on the handler', 403 === $void( 'seller', $vs['e'], 'x', array( 'reason' => 'x' ) )['code'] && 403 === $void( 'customer', $vs['e'], 'x', array( 'reason' => 'x' ) )['code'] && 'completed' === $row( $vs['e'] )['status'] );
	pqbg_t( 'logged out → refused (no nopriv handler)', 200 !== $void( 'anon', $vs['e'], 'x', array( 'reason' => 'x' ) )['code'] && 'completed' === $row( $vs['e'] )['status'] );
	$nv = $http( 'smnv', 'GET', $hist_url( array( 'sale' => (string) $vs['e'] ) ) );
	pqbg_t( 'a manager without pqbg_void_sale: the detail has no "Void sale"; the void screen and handler → 403', 200 === $nv['code'] && ! str_contains( $nv['body'], 'pqbg_view=void' ) && 403 === $http( 'smnv', 'GET', $void_url( $vs['e'] ) )['code'] && 403 === $void( 'smnv', $vs['e'], 'x', array( 'reason' => 'x' ) )['code'] );
	$vp = $http( 'admin', 'GET', $void_url( $vs['e'] ) );
	$r  = $void( 'admin', $vs['e'], $field( $vp['body'], '_pqbg_nonce' ), array( 'reason' => 'admin void', 'restock' => '1' ) );
	pqbg_t( 'an administrator can void too', str_contains( $r['location'], 'pqbg_msg=voided_restocked' ) && 'voided' === $row( $vs['e'] )['status'] && $A === (int) $row( $vs['e'] )['voided_by'] );
	$vl = $http( 'admin', 'GET', $hist_url( array( 'status' => 'voided' ) ) );
	pqbg_t( 'voided rows stay in the history (status filter "Voided" today)', count( array_intersect( array( $vs['a'], $vs['b'], $vs['d'], $vs['e'] ), $listed( $vl['body'] ) ) ) === 4 );

	// ------------------------------------------------------------------ My sales
	pqbg_section( 'My sales (seller, front end)' );
	$put( array( 'seller_id' => $SE, 'seller_name' => 'x', 'product_name' => 'Yesterday Item', 'unit_price' => '123', 'line_total' => '123', 'created_at_gmt' => $ist( wp_date( 'Y-m-d', strtotime( '-1 day' ) ) . ' 12:00:00' ) ) );
	$put( array( 'seller_id' => $SE, 'seller_name' => 'x', 'product_name' => 'Three Days Item', 'payment_method' => 'upi', 'created_at_gmt' => $ist( wp_date( 'Y-m-d', strtotime( '-3 days' ) ) . ' 12:00:00' ) ) );
	$put( array( 'seller_id' => $SE, 'seller_name' => 'x', 'product_name' => 'Failed Today Item', 'status' => 'failed', 'failure_code' => 'error' ) );
	$put( array( 'seller_id' => $SE, 'seller_name' => 'x', 'product_name' => '<script>alert("9a")</script> Kurta & "Co"', 'unit_cost' => '777.77' ) );
	$mt = $http( 'seller', 'GET', $mine_url() );
	pqbg_t( '200 with every security header, no JavaScript', 200 === $mt['code'] && $headers_ok( $mt ) && ! str_contains( $mt['body'], '<script>alert' ) && 0 === preg_match( '/<script\b/', $mt['body'] ) );
	pqbg_t( 'title "Today – {date}" and the three range tabs (Today current)', str_contains( $mt['body'], 'Today – ' . wp_date( get_option( 'date_format' ) ) ) && (bool) preg_match( '/pqbg-scan__tab--current" href="[^"]*\/scan\/my-sales\/" aria-current="page">Today</', $mt['body'] ) && str_contains( $mt['body'], '/scan/my-sales/?range=yesterday' ) && str_contains( $mt['body'], '/scan/my-sales/?range=7d' ) );
	pqbg_t( 'own completed and voided sales listed; failed attempts, other days and other sellers not', str_contains( $mt['body'], 'PQBG 9A Kurta One' ) && str_contains( $mt['body'], 'PQBG 9A Void Item' ) && str_contains( $mt['body'], 'pqbg-scan__line--voided' ) && ! str_contains( $mt['body'], 'Failed Today Item' ) && ! str_contains( $mt['body'], 'Yesterday Item' ) && ! str_contains( $mt['body'], 'PQBG 9A Dupatta Three' ) && ! str_contains( $mt['body'], 'Page item' ) );
	pqbg_t( 'never cost or profit', ! str_contains( $mt['body'], '777.77' ) && ! str_contains( $mt['body'], '412.34' ) && ! str_contains( $mt['body'], '456.78' ) && ! preg_match( '/cost|profit/i', wp_strip_all_tags( $mt['body'] ) ) );
	pqbg_t( 'escaping of product names', str_contains( $mt['body'], '&lt;script&gt;alert(&quot;9a&quot;)&lt;/script&gt; Kurta &amp; &quot;Co&quot;' ) );
	// Summary against an independent query.
	$tr    = SalesQuery::range( 'today' );
	$exp   = $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE(payment_method, '') m, COUNT(*) n, SUM(quantity) q, SUM(line_total) t FROM $S WHERE seller_id = %d AND status = 'completed' AND created_at_gmt >= %s AND created_at_gmt < %s GROUP BY payment_method", $SE, $tr['start'], $tr['end'] ), OBJECT_K );
	$voids = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $S WHERE seller_id = %d AND status = 'voided' AND created_at_gmt >= %s AND created_at_gmt < %s", $SE, $tr['start'], $tr['end'] ) );
	preg_match_all( '/<tr[^>]*>\s*<th scope="row">([^<]*)<\/th>\s*<td>([^<]*)<\/td>\s*<td class="pqbg-scan__amount">([^<]*)<\/td>/', $mt['body'], $sm_rows, PREG_SET_ORDER );
	$summary = array();
	foreach ( $sm_rows as $x ) {
		$summary[ html_entity_decode( $x[1] ) ] = array( html_entity_decode( $x[2] ), html_entity_decode( $x[3] ) );
	}
	$fmt  = static fn( $o ) => null === $o ? array( '', '–' ) : array( sprintf( '%s %s · %s %s', number_format_i18n( $o->n ), 1 === (int) $o->n ? 'sale' : 'sales', number_format_i18n( $o->q ), 1 === (int) $o->q ? 'item' : 'items' ), SalePresenter::money( $o->t ) );
	$tot  = (object) array(
		'n' => array_sum( array_map( static fn( $o ) => (int) $o->n, $exp ) ),
		'q' => array_sum( array_map( static fn( $o ) => (int) $o->q, $exp ) ),
		't' => array_sum( array_map( static fn( $o ) => (float) $o->t, $exp ) ),
	);
	pqbg_t( 'summary per payment method (Cash, UPI, Card) and Total match an independent query', $fmt( $exp['cash'] ?? null ) === ( $summary['Cash'] ?? null ) && $fmt( $exp['upi'] ?? null ) === ( $summary['UPI'] ?? null ) && $fmt( $exp['card'] ?? null ) === ( $summary['Card'] ?? null ) && $fmt( $tot ) === ( $summary['Total'] ?? null ), wp_json_encode( $summary ) );
	pqbg_t( 'the voided count', $voids > 0 && str_contains( $mt['body'], 'Voided: ' . $voids ) );
	pqbg_t( 'each line links to its sale page', str_contains( $mt['body'], esc_url( add_query_arg( 'sale', $sid, $url( $c1 ) ) ) ) );
	$my = $http( 'seller', 'GET', $mine_url( 'yesterday' ) );
	pqbg_t( 'Yesterday: only yesterday\'s sale', 200 === $my['code'] && str_contains( $my['body'], 'Yesterday Item' ) && ! str_contains( $my['body'], 'PQBG 9A Kurta One' ) && str_contains( $my['body'], 'Yesterday – ' ) && str_contains( $my['body'], '₹123.00' ) );
	$m7 = $http( 'seller', 'GET', $mine_url( '7d' ) );
	pqbg_t( 'Last 7 days: today, yesterday and 3 days ago', 200 === $m7['code'] && str_contains( $m7['body'], 'Three Days Item' ) && str_contains( $m7['body'], 'Yesterday Item' ) && str_contains( $m7['body'], 'PQBG 9A Kurta One' ) && str_contains( $m7['body'], 'Last 7 days – ' ) );
	$m2 = $http( 'seller2', 'GET', $mine_url() );
	pqbg_t( 'another seller sees only their own (Dupatta Three, none of seller 1\'s)', 200 === $m2['code'] && str_contains( $m2['body'], 'PQBG 9A Dupatta Three' ) && ! str_contains( $m2['body'], 'PQBG 9A Kurta One' ) && ! str_contains( $m2['body'], 'Void Item' ) );
	$mx = $http( 'seller', 'GET', $mine_url() . '?seller=' . $SE2 );
	pqbg_t( 'no user ID is taken from the request (?seller= → 301 to the canonical page)', 301 === $mx['code'] && $mine_url() === $mx['location'] );
	pqbg_t( 'in-process: only the given seller\'s rows', ! array_filter( ScanScreen::my_sales( $SE2, '7d' )['mine']['lines'], static fn( $l ) => str_contains( $l['item'], 'Kurta One' ) ) );
	$canon = array(
		array( $home . '/scan/my-sales', $mine_url() ),
		array( $mine_url() . '?range=today', $mine_url() ),
		array( $mine_url() . '?range=bogus', $mine_url() ),
		array( $mine_url() . '?range=7d&x=1', $mine_url( '7d' ) ),
	);
	$bad = array();
	foreach ( $canon as $c ) {
		$x = $http( 'seller', 'GET', $c[0] );
		if ( 301 !== $x['code'] || $c[1] !== $x['location'] ) {
			$bad[] = $c[0] . ' → ' . $x['code'] . ' ' . $x['location'];
		}
	}
	pqbg_t( 'canonical URLs: 301 for a missing slash, today\'s argument, unknown ranges and arguments', array() === $bad, implode( '; ', $bad ) );
	$x = $http( 'seller', 'POST', $mine_url(), array( 'a' => 'b' ) );
	pqbg_t( 'POST → 405 Allow: GET, HEAD', 405 === $x['code'] && 'GET, HEAD' === ( $x['headers']['allow'] ?? '' ) && $headers_ok( $x ) );
	$x = $http( 'seller', 'HEAD', $mine_url() );
	pqbg_t( 'HEAD → 200, no body', 200 === $x['code'] && '' === $x['body'] );
	$x = $http( 'anon', 'GET', $mine_url( '7d' ) );
	pqbg_t( 'logged out → 302 to the login page, back to My sales', 302 === $x['code'] && str_contains( $x['location'], 'wp-login.php' ) && str_contains( $x['location'], rawurlencode( $mine_url() ) ) );
	$xc = $http( 'customer', 'GET', $mine_url() );
	$xs = $http( 'customer', 'GET', $url() );
	pqbg_t( 'customer → the same byte-identical 403 as the scan page', 403 === $xc['code'] && $xc['body'] === $xs['body'] && $headers_ok( $xc ) && ! str_contains( $xc['body'], 'my-sales' ) );
	$xm = $http( 'sm', 'GET', $mine_url() );
	pqbg_t( 'shop manager: their own (empty) page', 200 === $xm['code'] && str_contains( $xm['body'], 'No sales in this period.' ) );
	( new WP_User( $SE2 ) )->add_cap( Permissions::VIEW_OWN_SALES, false );
	$xn = $http( 'seller2', 'GET', $mine_url() );
	( new WP_User( $SE2 ) )->remove_cap( Permissions::VIEW_OWN_SALES );
	pqbg_t( 'a user who may scan but not see own sales → 403 "You do not have permission to view sales."', 403 === $xn['code'] && str_contains( $xn['body'], 'You do not have permission to view sales.' ) && ! str_contains( $xn['body'], 'Dupatta' ) );
	$sp = $http( 'seller', 'GET', $url() );
	pqbg_t( 'the scan page header links to My sales; My sales links back to Scan', str_contains( $sp['body'], '<a class="pqbg-scan__nav" href="' . esc_url( $mine_url() ) . '">My sales</a>' ) && str_contains( $mt['body'], '<a class="pqbg-scan__nav" href="' . esc_url( $url() ) . '">Scan</a>' ) );
	pqbg_t( 'the reserved segment can never be a product code', ! ProductQrBarcode\CodeGenerator::is_valid_format( strtoupper( ScanUrl::MY_SALES ) ) && 400 === $http( 'seller', 'GET', $home . '/scan/MY-SALES/' )['code'] );

	// ------------------------------------------------------------------ permissions matrix
	pqbg_section( 'permissions: every screen and handler over HTTP' );
	$screens = array(
		'list'   => $hist_url(),
		'detail' => $hist_url( array( 'sale' => (string) $d['r1'] ) ),
		'void'   => $void_url( $d['r1'] ),
	);
	$bad = array();
	foreach ( $screens as $name => $u ) {
		foreach ( array( 'admin' => 200, 'sm' => 200 ) as $who => $code ) {
			if ( $code !== $http( $who, 'GET', $u )['code'] ) {
				$bad[] = "$who $name";
			}
		}
		foreach ( array( 'seller', 'customer', 'anon' ) as $who ) {
			$x = $http( $who, 'GET', $u );
			if ( 200 === $x['code'] || str_contains( $x['body'], 'Alpha Kurta' ) || str_contains( $x['body'], 'In-store sales</h1>' ) ) {
				$bad[] = "$who $name ({$x['code']})";
			}
		}
	}
	pqbg_t( 'history, detail, void screen: admin and shop manager 200; seller, customer, logged out never see them', array() === $bad, implode( ', ', $bad ) );
	pqbg_t( 'logged out: the history redirects to the login page', str_contains( $http( 'anon', 'GET', $hist_url() )['location'], 'wp-login.php' ) );

	// ------------------------------------------------------------------ GET never writes
	pqbg_section( 'GET and HEAD never write' );
	$before = $state();
	foreach ( array( 'admin', 'sm' ) as $who ) {
		$http( $who, 'GET', $hist_url() );
		$http( $who, 'GET', $hist_url( $range_args + array( 'method' => 'cash', 'orderby' => 'total' ) ) );
		$http( $who, 'GET', $hist_url( array( 'sale' => (string) $d['r4'] ) ) );
		$http( $who, 'GET', $void_url( $d['r4'] ) );
		$http( $who, 'HEAD', $void_url( $d['r4'] ) );
	}
	$http( 'admin', 'GET', $csv_url_a );
	$http( 'admin', 'HEAD', $csv_url_a );
	$http( 'admin', 'GET', admin_url( 'admin-post.php?action=pqbg_void_sale&sale=' . $d['r4'] . '&reason=x&restock=1' ) );
	foreach ( array( '', 'yesterday', '7d' ) as $rg ) {
		$http( 'seller', 'GET', $mine_url( $rg ) );
	}
	$http( 'seller', 'HEAD', $mine_url() );
	pqbg_t( 'history, detail, void screen, CSV, a GET on the void handler and My sales change nothing (sales, costs, stock, prices, settings, orders)', $before === $state() && 'completed' === $row( $d['r4'] )['status'] );
	// ------------------------------------------------------------------ performance
	pqbg_section( 'performance: 50,000 sales' );
	mt_srand( 9 );
	$sellers  = array_merge( array( $SE2 ), range( 900001, 900009 ) );
	$methods  = array_merge( array_fill( 0, 45, 'cash' ), array_fill( 0, 35, 'upi' ), array_fill( 0, 15, 'card' ), array_fill( 0, 3, 'other' ), array( null, null ) );
	$t0       = microtime( true );
	$vals     = array();
	$now_ts   = time();
	for ( $i = 0; $i < 50000; $i++ ) {
		$r      = mt_rand( 1, 100 );
		$status = $r <= 90 ? 'completed' : ( $r <= 96 ? 'voided' : 'failed' );
		$m      = $methods[ mt_rand( 0, 99 ) ];
		$q      = mt_rand( 1, 3 );
		$p      = mt_rand( 300, 5000 );
		$c      = mt_rand( 1, 10 ) <= 7 ? number_format( $p * 0.6, 2, '.', '' ) : null;
		$vals[] = $wpdb->prepare( '(%s, 1, 0, %d, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)', wp_generate_uuid4(), $sellers[ mt_rand( 0, 9 ) ], $q, $p, $p * $q, 'INR', 'Perf Product ' . mt_rand( 1, 2000 ), 'PERF-' . mt_rand( 1, 2000 ), $status, gmdate( 'Y-m-d H:i:s', $now_ts - mt_rand( 0, 89 * 86400 ) ), (string) $m, (string) $c, 'Perf Seller', 'voided' === $status ? 'perf' : '', $PERF_NOTE );
		if ( 1000 === count( $vals ) ) {
			$wpdb->query( "INSERT INTO $S (request_id, product_id, variation_id, seller_id, quantity, unit_price, line_total, currency, product_name, sku, status, created_at_gmt, payment_method, unit_cost, seller_name, void_reason, note) VALUES " . implode( ',', $vals ) );
			$vals = array();
		}
	}
	$wpdb->query( $wpdb->prepare( "UPDATE $S SET payment_method = NULLIF(payment_method, ''), unit_cost = NULLIF(unit_cost, 0), void_reason = NULLIF(void_reason, '') WHERE note = %s", $PERF_NOTE ) );
	$wpdb->query( "ANALYZE TABLE $S" );
	$perf_n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $S WHERE note = %s", $PERF_NOTE ) );
	pqbg_t( '50,000 synthetic sales inserted (10 sellers, cash/UPI/card/other/not recorded, 90/6/4 % completed/voided/failed, 70 % with a cost, 90 days)', 50000 === $perf_n, sprintf( '%.1f s', microtime( true ) - $t0 ) );
	$from90 = wp_date( 'Y-m-d', strtotime( '-89 days' ) );
	$to90   = wp_date( 'Y-m-d' );
	$f_m    = SalesQuery::filters( array( 'range' => 'this_month' ) );
	$f_90   = SalesQuery::filters( array( 'range' => 'custom', 'from' => $from90, 'to' => $to90 ) );
	$f_7c   = SalesQuery::filters( array( 'range' => 'last7', 'method' => 'card' ) );
	$f_90c  = SalesQuery::filters( array( 'range' => 'custom', 'from' => $from90, 'to' => $to90, 'method' => 'card' ) );
	$f_90n  = SalesQuery::filters( array( 'range' => 'custom', 'from' => $from90, 'to' => $to90, 'method' => 'none' ) );
	$f_90s  = SalesQuery::filters( array( 'range' => 'custom', 'from' => $from90, 'to' => $to90, 'seller' => (string) $SE2 ) );
	$f_90q  = SalesQuery::filters( array( 'range' => 'custom', 'from' => $from90, 'to' => $to90, 's' => 'Perf Product 12' ) );
	$f_90t  = SalesQuery::filters( array( 'range' => 'custom', 'from' => $from90, 'to' => $to90, 'orderby' => 'total' ) );
	$list   = static fn( array $f ) => static function () use ( $f ) {
		SalesQuery::rows( $f, 50 );
		SalesQuery::count( $f );
	};
	$tot    = static fn( array $f ) => static fn() => SalesQuery::totals( $f );
	$timings['list: this month']              = $time_ms( $list( $f_m ) );
	$timings['list: 90 days (all rows)']      = $time_ms( $list( $f_90 ) );
	$timings['list: last 7 days + card']      = $time_ms( $list( $f_7c ) );
	$timings['list: 90 days + card']          = $time_ms( $list( $f_90c ) );
	$timings['list: 90 days + not recorded']  = $time_ms( $list( $f_90n ) );
	$timings['list: 90 days + seller']        = $time_ms( $list( $f_90s ) );
	$timings['list: 90 days + search']        = $time_ms( $list( $f_90q ) );
	$timings['list: 90 days sorted by total'] = $time_ms( $list( $f_90t ) );
	$timings['totals: this month']            = $time_ms( $tot( $f_m ) );
	$timings['totals: 90 days (all rows)']    = $time_ms( $tot( $f_90 ) );
	$timings['totals: 90 days + card']        = $time_ms( $tot( $f_90c ) );
	$timings['totals: 90 days + seller']      = $time_ms( $tot( $f_90s ) );
	foreach ( $timings as $k => $v ) {
		echo sprintf( "   TIMING %-34s %8.1f ms\n", $k, $v );
	}
	$slow = array_filter( $timings, static fn( $v ) => $v >= 1000 );
	pqbg_t( 'every list page (rows + count) and every totals query < 1 s in-process', array() === $slow, wp_json_encode( $slow ) );
	$explain = static function ( array $f, string $what ) use ( $wpdb, $S ): array {
		$where = SalesQuery::where( $f );
		$sql   = 'totals' === $what
			? "SELECT s.status, s.payment_method, COUNT(*), SUM(s.line_total) FROM $S s WHERE $where GROUP BY s.status, s.payment_method" // The shape of SalesQuery::totals().
			: "SELECT s.* FROM $S s WHERE $where ORDER BY s.created_at_gmt DESC, s.id DESC LIMIT 50";
		return (array) $wpdb->get_row( "EXPLAIN $sql", ARRAY_A );
	};
	$ex = array(
		'list 90d'           => $explain( $f_90, 'list' ),
		'list 90d + card'    => $explain( $f_90c, 'list' ),
		'list 90d + none'    => $explain( $f_90n, 'list' ),
		'list 90d + seller'  => $explain( $f_90s, 'list' ),
		'totals 90d'         => $explain( $f_90, 'totals' ),
		'totals 90d + card'  => $explain( $f_90c, 'totals' ),
	);
	foreach ( $ex as $k => $e ) {
		echo sprintf( "   EXPLAIN %-20s key=%-16s rows=%-7s %s\n", $k, $e['key'] ?? '', $e['rows'] ?? '', $e['Extra'] ?? '' );
	}
	pqbg_t( 'EXPLAIN: the payment filter uses method_created (list and totals, card and not recorded)', 'method_created' === $ex['list 90d + card']['key'] && 'method_created' === $ex['list 90d + none']['key'] && 'method_created' === $ex['totals 90d + card']['key'] );
	pqbg_t( 'EXPLAIN: the seller filter uses seller_created; no list query and no filtered totals query scans the whole table', 'seller_created' === $ex['list 90d + seller']['key'] && ! array_filter( $ex, static fn( $e, $k ) => 'ALL' === ( $e['type'] ?? '' ) && 'totals 90d' !== $k, ARRAY_FILTER_USE_BOTH ) ); // Totals over the whole table may scan it: that is the cheapest plan there.
	// HTTP.
	$hh = array();
	foreach ( array( 'empty range (baseline: wp-admin itself)' => array( 'range' => 'custom', 'from' => '2000-01-01', 'to' => '2000-01-01' ), 'this month' => array( 'range' => 'this_month' ), '90 days' => array( 'range' => 'custom', 'from' => $from90, 'to' => $to90 ), '90 days + card' => array( 'range' => 'custom', 'from' => $from90, 'to' => $to90, 'method' => 'card' ) ) as $k => $a ) {
		$ts = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ts[] = $http( 'admin', 'GET', $hist_url( $a ) )['time'] * 1000;
		}
		$hh[ $k ] = $median( $ts );
		echo sprintf( "   TIMING HTTP history (admin), %-16s %8.1f ms (median of 3)\n", $k, $hh[ $k ] );
	}
	pqbg_t( 'HTTP history page with totals, admin (sanity < 3 s)', max( $hh ) < 3000 );
	$ms = array();
	for ( $i = 0; $i < 3; $i++ ) {
		$x    = $http( 'seller2', 'GET', $mine_url( '7d' ) );
		$ms[] = $x['time'] * 1000;
	}
	echo sprintf( "   TIMING HTTP My sales, last 7 days (~%d rows of this seller) %8.1f ms (median of 3)\n", SalesQuery::count( array( 'start' => SalesQuery::range( 'last7' )['start'], 'end' => SalesQuery::range( 'last7' )['end'], 'seller' => $SE2, 'statuses' => array( 'completed', 'voided' ) ) ), $median( $ms ) );
	pqbg_t( 'HTTP My sales with hundreds of lines: capped at 300 lines, the rest summarised (sanity < 3 s)', 200 === $x['code'] && 300 === substr_count( $x['body'], '<li class="pqbg-scan__line ' ) && str_contains( $x['body'], 'older sales in this period are not listed' ) && $median( $ms ) < 3000 );
	// CSV of every row.
	$n90    = SalesQuery::count( $f_90 );
	$chunks = 0;
	SalesQuery::each_chunk( $f_90, static function () use ( &$chunks ) {
		++$chunks;
	} );
	$same_order = static function ( array $f ) use ( $wpdb, $S ): bool {
		$seen = array();
		SalesQuery::each_chunk( $f, static function ( array $rows ) use ( &$seen ) {
			foreach ( $rows as $r ) {
				$seen[] = (int) $r['id'];
			}
		} );
		$col    = SalesQuery::SORTS[ $f['orderby'] ];
		$dir    = 'asc' === $f['order'] ? 'ASC' : 'DESC';
		$direct = array_map( 'intval', $wpdb->get_col( "SELECT s.id FROM $S s WHERE " . SalesQuery::where( $f ) . " ORDER BY $col $dir, s.id $dir" ) );
		return $seen === $direct;
	};
	pqbg_t( 'keyset paging (5,000 IDs per page): exactly the rows and order of one direct query — by date, by total (many ties) and by product ascending', $same_order( $f_90 ) && $same_order( $f_90t ) && $same_order( SalesQuery::filters( array( 'range' => 'custom', 'from' => $from90, 'to' => $to90, 'orderby' => 'product', 'order' => 'asc' ) ) ) );
	$tmp = fopen( 'php://temp/maxmemory:0', 'w+' );
	$wpdb->flush(); // Otherwise the last big result above counts in the baseline and is freed during the export.
	gc_collect_cycles();
	memory_reset_peak_usage();
	$m0      = memory_get_usage();
	$t       = hrtime( true );
	$written = SalesExport::write( $tmp, $f_90, true );
	$csv_ms  = ( hrtime( true ) - $t ) / 1e6;
	$peak    = memory_get_peak_usage() - $m0;
	$size    = ftell( $tmp );
	fclose( $tmp );
	echo sprintf( "   TIMING CSV in-process, %d rows: %.0f ms, %.1f MB written, memory peak +%.1f MB, %d chunks of %d\n", $written, $csv_ms, $size / 1048576, $peak / 1048576, $chunks, SalesQuery::CHUNK );
	pqbg_t( 'CSV of every row (50,000+): all rows written, read in 1,000-row chunks, memory peak under 16 MB (streamed)', $written === $n90 && $n90 >= 50000 && (int) ceil( $n90 / 1000 ) === $chunks && $peak < 16 * 1048576, sprintf( '+%.1f MB', $peak / 1048576 ) );
	$ph = $http( 'admin', 'GET', $hist_url( array( 'range' => 'custom', 'from' => $from90, 'to' => $to90 ) ) );
	preg_match( '/<a class="page-title-action" href="([^"]+)">Export CSV<\/a>/', $ph['body'], $m );
	$x = $http( 'admin', 'GET', html_entity_decode( $m[1] ?? '' ) );
	echo sprintf( "   TIMING CSV over HTTP, %d rows: %.0f ms, %.1f MB\n", $n90, $x['time'] * 1000, strlen( $x['body'] ) / 1048576 );
	pqbg_t( 'CSV over HTTP of every row: 200, header + one line per row', 200 === $x['code'] && $n90 + 1 === count( $csv_rows( substr( $x['body'], 3 ) ) ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $S WHERE note = %s", $PERF_NOTE ) );
	pqbg_t( 'performance rows removed', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $S WHERE note = %s", $PERF_NOTE ) ) );

	// ------------------------------------------------------------------ scope
	pqbg_section( 'scope' );
	$src    = static function ( string $file ): string {
		$code = '';
		foreach ( token_get_all( (string) file_get_contents( PQBG_PLUGIN_DIR . $file ) ) as $t ) {
			$code .= is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t;
		}
		return $code;
	};
	$files  = array_map( static fn( $f ) => substr( $f, strlen( PQBG_PLUGIN_DIR ) ), array_merge( glob( PQBG_PLUGIN_DIR . 'includes/*.php' ), glob( PQBG_PLUGIN_DIR . 'templates/*.php' ), array( PQBG_PLUGIN_DIR . 'uninstall.php' ) ) );
	$with   = static fn( string $needle ) => array_values( array_filter( $files, static fn( $f ) => str_contains( $src( $f ), $needle ) ) );
	pqbg_t( 'the cost meta key appears only in CostPrice and uninstall.php', array( 'includes/CostPrice.php', 'uninstall.php' ) === $with( '_pqbg_cost_price' ), implode( ',', $with( '_pqbg_cost_price' ) ) );
	$no_cost = array( 'includes/ScanScreen.php', 'includes/ScanRoute.php', 'includes/SaleRequest.php', 'includes/PrintJob.php', 'includes/PrintPage.php', 'includes/PrintLayout.php', 'templates/pqbg-scan.php', 'templates/pqbg-print.php' );
	pqbg_t( 'scan, sale, My sales and label code never read a cost (no unit_cost, CostPrice or can_view_costs)', ! array_filter( $no_cost, static fn( $f ) => (bool) preg_match( '/unit_cost|CostPrice|can_view_costs|\bcost\b/i', $src( $f ) ) ) );
	pqbg_t( 'the history classes only read (no $wpdb insert/update/query/delete/replace)', ! array_filter( array( 'includes/SalesQuery.php', 'includes/SalePresenter.php', 'includes/SalesExport.php', 'includes/SalesListTable.php', 'includes/SalesAdmin.php' ), static fn( $f ) => (bool) preg_match( '/\$wpdb->(insert|update|query|delete|replace)\b/', $src( $f ) ) ) );
	pqbg_t( 'cost and profit in the history only behind can_view_costs() (list, detail, CSV)', str_contains( $src( 'includes/SalesAdmin.php' ), 'Permissions::can_view_costs()' ) && str_contains( $src( 'includes/SalesExport.php' ), 'Permissions::can_view_costs()' ) && str_contains( $src( 'includes/SalesListTable.php' ), 'if ( $this->costs )' ) );
	pqbg_t( 'the My sales path is built only by ScanUrl', array( 'includes/ScanUrl.php' ) === $with( "'my-sales'" ) );
	$all = implode( "\n", array_map( $src, $files ) );
	pqbg_t( 'still no nopriv handlers, AJAX actions, REST routes, shortcodes or rewrite endpoints', ! preg_match( '/admin_post_nopriv|wp_ajax_|register_rest_route|add_shortcode|add_rewrite_endpoint/', $all ) );
	pqbg_t( 'the approved hooks (D12) are registered, by CostPrice only', 10 === has_filter( 'woocommerce_data_store_wp_post_read_meta', array( CostPrice::class, 'hide_from_meta_data' ) ) && 10 === has_filter( 'add_post_metadata', array( CostPrice::class, 'guard_write' ) ) && 10 === has_filter( 'update_post_metadata', array( CostPrice::class, 'guard_write' ) ) && 10 === has_filter( 'delete_post_metadata', array( CostPrice::class, 'guard_delete' ) ) && 10 === has_filter( 'wxr_export_skip_postmeta', array( CostPrice::class, 'skip_in_wxr' ) ) && false === has_filter( 'delete_post_metadata_by_mid' ) );
	pqbg_t( 'the edit-screen hooks are admin-only (not registered in this CLI request)', false === has_action( 'woocommerce_admin_process_product_object', array( CostPrice::class, 'save_product' ) ) && false === has_action( 'woocommerce_product_options_pricing', array( CostPrice::class, 'render_simple_field' ) ) );
	pqbg_t( 'sales rows are still written only by SaleRepository (SaleService passes the new snapshots to insert_pending)', ! preg_match( '/\$wpdb->(insert|update|query)[^;]*sales_table/', implode( "\n", array_map( $src, array_diff( $files, array( 'includes/SaleRepository.php', 'includes/Install.php', 'includes/Schema.php' ) ) ) ) ) );

	// ------------------------------------------------------------------ the delete-all uninstall statement (D7), last
	pqbg_section( 'uninstall: delete-all removes every cost row (D7)' );
	if ( 0 === $base_cost ) {
		wp_set_current_user( $SM );
		delete_metadata( 'post', 0, $KEY, '', true ); // The exact statement in uninstall.php's delete-all branch.
		wp_set_current_user( 0 );
		pqbg_t( 'the bulk removal is never blocked by the guard (run as a logged-in shop manager): 0 cost rows left', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $PM WHERE meta_key = %s", $KEY ) ) );
	} else {
		pqbg_skip( 'delete-all removes every cost row', "the site has $base_cost real cost rows; not touched" );
	}
	pqbg_t( 'uninstall.php runs exactly that statement, inside the delete-all branch', (bool) preg_match( "/PQBG_UNINSTALL_DELETE_ALL_DATA[^;]*\\)\s*\\{\s*return;\s*\\}.*delete_metadata\\( 'post', 0, '_pqbg_cost_price', '', true \\);/s", (string) file_get_contents( PQBG_PLUGIN_DIR . 'uninstall.php' ) ) );
} catch ( Throwable $e ) {
	pqbg_t( 'suite ran without an exception', false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
	// ------------------------------------------------------------------ cleanup
	pqbg_section( 'cleanup' );
	wp_set_current_user( 0 );
	$_POST = array();
	foreach ( $handles as $h ) {
		unset( $h );
	}
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
	$wpdb->query( $wpdb->prepare( "DELETE FROM $C WHERE id > %d", $start_c ) );
	$wpdb->query( 'ALTER TABLE ' . $C . ' AUTO_INCREMENT = ' . ( $start_c + 1 ) );
	foreach ( $user_ids as $uid ) {
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d", $uid ) ) as $own ) {
			wp_delete_post( (int) $own, true );
		}
		wp_delete_user( $uid );
	}
	remove_role( 'pqbg9a_tmp' );
	foreach ( $saved as $name => $raw ) {
		if ( null === $raw ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		} else {
			$wpdb->replace( $wpdb->options, array( 'option_name' => $name, 'option_value' => $raw['option_value'], 'autoload' => $raw['autoload'] ) );
		}
	}
	if ( null === $saved[ PrintCache::INDEX_OPTION ] ) {
		PrintCache::clear_all(); // The label check filled the render cache; it was empty before.
	}
	wp_cache_flush();
	pqbg_test_as_cleanup( $as_mark );
	pqbg_t( 'cleanup: sales, codes and posts back to the start', $start_s === $max( $S, 'id' ) && $start_c === $max( $C, 'id' ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID > %d", $start_post ) ) );
	pqbg_t( 'cleanup: no test cost meta, no orphans, users back to the start', $base_cost === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $PM WHERE meta_key = %s", $KEY ) ) && 0 === $orphans() && $base_user === (int) count_users()['total_users'] );
	pqbg_t( 'cleanup: options byte-identical (settings, DB version, meta-box errors, render-cache index)', ! array_filter( $saved, static fn( $raw, $name ) => $raw !== $raw_option( $name ), ARRAY_FILTER_USE_BOTH ) );
	pqbg_t( 'cleanup: the posts AUTO_INCREMENT only moved by the posts this suite created (no import_id jump)', $posts_ai() - $start_ai < 2000, $start_ai . ' → ' . $posts_ai() );
	pqbg_t( 'cleanup: no stock-query filter or temporary tables left', false === has_filter( SaleService::STOCK_QUERY_FILTER ) && array() === $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $mig_prefix ) . '%' ) ) );
	pqbg_test_as_check( $as_mark );
}

pqbg_test_done();
