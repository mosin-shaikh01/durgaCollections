<?php
/**
 * Phase 7 suite: Mark as Sold + Sales.
 *
 * The sale form and the sale / undo flow over real HTTP as sellers, shop
 * managers and administrators; quantity bounds; every non-sellable state
 * refused server-side; unmanaged stock, empty and zero prices, backorders;
 * parent-level variation stock (decrement and lock on the parent); scheduled
 * sale prices; price normalisation; idempotency (sequential, concurrent, the
 * reloaded result page, an expired form); real concurrency with CLI worker
 * processes; the online-checkout race with a real WooCommerce order placed by
 * another process; failure injection before, during and after the stock
 * change; the SQL-override fallback in both branches and the WooCommerce
 * compatibility check; undo (own sale, 10-minute window, once, lock,
 * concurrency, GET) and void_sale(); permissions, nonces and tokens; no
 * WooCommerce orders; stock hooks, status, lookup table and notifications;
 * the v1 → v2 migration on a temporary table prefix; escaping, headers, GET
 * never writes; scope; timings.
 *
 * Creates products, variations, codes, sales rows, orders (online race only)
 * and users, and removes them all.
 *
 *   php tests/phase7-sales.php
 *   php tests/phase7-sales.php --worker <mode> ...   (internal: concurrency)
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/bootstrap.php';

// Worker processes: sell, undo, hold a stock lock, or place an online order.
if ( isset( $argv[1] ) && '--worker' === $argv[1] ) {
	pqbg_test_load_wp();
	add_filter( 'pre_wp_mail', '__return_false' ); // Local mail is not configured; a failing mail() takes about 2 s.
	$mode = $argv[2];

	if ( 'hold' === $mode ) {
		$ok = ProductQrBarcode\StockLock::acquire( (int) $argv[3], 0 );
		echo $ok ? "held\n" : "not held\n";
		fflush( STDOUT );
		usleep( (int) ( (float) $argv[4] * 1000000 ) );
		ProductQrBarcode\StockLock::release( (int) $argv[3] );
		exit( 0 );
	}

	if ( 'online' === $mode ) {
		$product = wc_get_product( (int) $argv[3] );
		$order   = wc_create_order( array( 'status' => 'pending' ) );
		$order->add_product( $product, (int) $argv[4] );
		$order->save();
		wc_reduce_stock_levels( $order->get_id() );
		echo wp_json_encode( array( 'order' => $order->get_id() ) ), "\n";
		exit( 0 );
	}

	$start = (float) end( $argv );
	while ( microtime( true ) < $start ) {
		usleep( 2000 );
	}

	if ( 'sell' === $mode ) {
		$r = ProductQrBarcode\SaleService::sell(
			array(
				'code'       => $argv[3],
				'quantity'   => (int) $argv[4],
				'request_id' => $argv[5],
				'seller_id'  => (int) $argv[6],
			)
		);
	} else {
		$r = ProductQrBarcode\SaleService::undo( (int) $argv[3], (int) $argv[4] );
	}

	echo wp_json_encode( is_wp_error( $r ) ? array( 'error' => $r->get_error_code() ) : array( 'status' => $r['status'], 'sale' => (int) $r['sale']['id'], 'row' => $r['sale']['status'] ) ), "\n";
	exit( 0 );
}

pqbg_test_load_wp();
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/post.php';

use ProductQrBarcode\{CodeRepository, Install, Permissions, Plugin, ProductCodeService, SaleRepository, SaleRequest, SaleService, ScanRoute, ScanScreen, ScanUrl, Schema, StockLock};

global $wpdb;

$C         = Schema::codes_table();
$S         = Schema::sales_table();
$max       = static fn( string $table, string $col ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX($col), 0) FROM $table" );
$start_c   = $max( $C, 'id' );
$start_s   = $max( $S, 'id' );
$as_mark  = pqbg_test_as_mark(); // Action Scheduler cleanup, see bootstrap.php.
$start_post = $max( $wpdb->posts, 'ID' );
$start_cmt = $max( $wpdb->comments, 'comment_ID' );
$start_ord = $max( $wpdb->prefix . 'wc_orders', 'id' );
$start_oi  = $max( $wpdb->prefix . 'woocommerce_order_items', 'order_item_id' );
$base_c    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" );
$base_s    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $S" );
$base_prod = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')" );
$base_user = (int) count_users()['total_users'];
$raw_option = static fn( string $name ) => $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
$saved     = array();
foreach ( array( Plugin::SETTINGS_OPTION, Install::DB_VERSION_OPTION, 'woocommerce_manage_stock', 'woocommerce_notify_low_stock_amount', 'woocommerce_coming_soon', 'rewrite_rules' ) as $name ) {
	$saved[ $name ] = $raw_option( $name );
}
$user_ids  = array();
$pw        = array();
$home      = untrailingslashit( home_url() );
$orders    = array();
$mig_prefix = $wpdb->prefix . 'pqbgmig_';
$cbs       = array();

add_filter( 'pre_wp_mail', '__return_false' ); // In-process only; see the worker.

$sync  = static fn() => wp_cache_flush();
$stock = static fn( int $id ) => SaleRepository::read_stock( $id );
$code_of = static function ( int $id ): string {
	$row = CodeRepository::find_active_for_product( $id );
	return is_array( $row ) ? (string) $row['code'] : '';
};
$url      = static fn( string $code = '' ) => ScanUrl::site_url( $code );
$rows_for = static fn( int $product_or_variation ) => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $S WHERE id > %d AND ( product_id = %d OR variation_id = %d ) ORDER BY id", $start_s, $product_or_variation, $product_or_variation ), ARRAY_A );
$sales_n  = static fn() => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $S WHERE id > %d", $start_s ) );
$sales_sum = static fn() => (string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, status, COALESCE(failure_code, ''), COALESCE(voided_by, 0), COALESCE(stock_after, -1)))), 0)) FROM $S" );
$orders_n = static fn() => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders" );
$stock_status = static function ( int $id ) use ( $wpdb ) {
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock_status'", $id ) );
};
$lookup_stock = static fn( int $id ) => $wpdb->get_row( $wpdb->prepare( "SELECT stock_quantity, stock_status FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id = %d", $id ), ARRAY_A );

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
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_FORBID_REUSE   => true,
			CURLOPT_NOBODY         => 'HEAD' === $method,
			CURLOPT_CUSTOMREQUEST  => in_array( $method, array( 'GET', 'POST', 'HEAD' ), true ) ? null : $method,
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
		'location' => $hdrs['location'] ?? '',
		'headers'  => $hdrs,
		'body'     => substr( $raw, $size ),
		'time'     => (float) curl_getinfo( $ch, CURLINFO_TOTAL_TIME ),
	);
};
$login = static function ( string $who, string $user, string $pass ) use ( $http ): array {
	$http( $who, 'GET', wp_login_url() );
	return $http( $who, 'POST', wp_login_url(), array( 'log' => $user, 'pwd' => $pass, 'wp-submit' => 'Log In', 'testcookie' => '1', 'redirect_to' => ScanUrl::site_url() ) );
};
$notices_in = static function ( string $body ): array {
	preg_match_all( '/<p class="pqbg-scan__notice[^"]*"[^>]*>(.*?)<\/p>/s', $body, $m );
	return array_map( static fn( $s ) => html_entity_decode( $s, ENT_QUOTES, 'UTF-8' ), $m[1] );
};
$has_notice = static fn( array $r, string $text ) => in_array( $text, $notices_in( $r['body'] ), true );
/** The sale form on a page: action, hidden fields, quantity options, number-field max. */
$form_of = static function ( string $body ): ?array {
	if ( ! preg_match( '/<form class="pqbg-scan__sell" method="post" action="([^"]*)">(.*?)<\/form>/s', $body, $m ) ) {
		return null;
	}
	$fields = array();
	preg_match_all( '/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $m[2], $h, PREG_SET_ORDER );
	foreach ( $h as $x ) {
		$fields[ $x[1] ] = html_entity_decode( $x[2], ENT_QUOTES, 'UTF-8' );
	}
	$options = array();
	preg_match_all( '/<option value="([0-9]+)"[^>]*>([^<]*)<\/option>/', $m[2], $o, PREG_SET_ORDER );
	foreach ( $o as $x ) {
		$options[ (int) $x[1] ] = html_entity_decode( $x[2], ENT_QUOTES, 'UTF-8' );
	}
	return array(
		'action'     => html_entity_decode( $m[1], ENT_QUOTES ),
		'fields'     => $fields,
		'options'    => $options,
		'number_max' => preg_match( '/<input class="pqbg-scan__quantity"[^>]*type="number"[^>]*max="([0-9]+)"/', $m[2], $n ) ? (int) $n[1] : null,
		'html'       => $m[0],
	);
};
$undo_form_of = static function ( string $body ): ?array {
	if ( ! preg_match( '/<form class="pqbg-scan__undo" method="post" action="([^"]*)">(.*?)<\/form>/s', $body, $m ) ) {
		return null;
	}
	$fields = array();
	preg_match_all( '/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $m[2], $h, PREG_SET_ORDER );
	foreach ( $h as $x ) {
		$fields[ $x[1] ] = html_entity_decode( $x[2], ENT_QUOTES, 'UTF-8' );
	}
	return array(
		'action' => html_entity_decode( $m[1], ENT_QUOTES ),
		'fields' => $fields,
	);
};
/** Opens the product page as $who and returns its sale form. */
$get_form = static function ( string $who, string $code ) use ( $http, $url, $form_of ): ?array {
	$r = $http( $who, 'GET', $url( $code ) );
	return 200 === $r['code'] ? $form_of( $r['body'] ) : null;
};
/** Submits a sale form (optionally changed) as $who. */
$post_form = static function ( string $who, string $code, array $form, $qty = '1', array $override = array() ) use ( $http, $url ): array {
	$fields = array_merge( $form['fields'], array( 'quantity' => $qty ), $override );
	$fields = array_filter( $fields, static fn( $v ) => null !== $v );
	return $http( $who, 'POST', $url( $code ), $fields );
};
$sale_id_of = static function ( array $r ): int {
	parse_str( (string) wp_parse_url( $r['location'], PHP_URL_QUERY ), $q );
	return isset( $q['sale'] ) ? (int) $q['sale'] : 0;
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

/** A published simple product with managed stock; codes are assigned on save because $as manages codes. */
$make_simple = static function ( int $as, array $props = array() ): int {
	wp_set_current_user( $as );
	$p = new WC_Product_Simple();
	$p->set_name( 'PQBG P7 simple ' . wp_generate_password( 6, false ) );
	$p->set_status( 'publish' );
	$p->set_regular_price( '1499' );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 5 );
	$p->set_low_stock_amount( 0 );
	$p->set_sku( 'PQBG-P7-' . wp_generate_password( 8, false ) );
	$p->set_props( $props );
	$id = (int) $p->save();
	wp_set_current_user( 0 );
	return $id;
};
/** A variable product with $n variations. $parent_stock null: variations manage their own stock ($own each). */
$make_variable = static function ( int $as, int $n, ?int $parent_stock, int $own = 5, string $status = 'publish' ): array {
	wp_set_current_user( $as );
	$opts = array_map( static fn( $i ) => 'S' . $i, range( 1, $n ) );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( $opts );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$p = new WC_Product_Variable();
	$p->set_name( 'PQBG P7 variable ' . wp_generate_password( 6, false ) );
	$p->set_status( $status );
	$p->set_attributes( array( $attr ) );
	if ( null !== $parent_stock ) {
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $parent_stock );
		$p->set_low_stock_amount( 0 );
	}
	$pid  = (int) $p->save();
	$vids = array();
	foreach ( $opts as $opt ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $pid );
		$v->set_attributes( array( 'size' => $opt ) );
		$v->set_regular_price( '799' );
		$v->set_sku( 'PQBG-P7-' . $pid . '-' . $opt );
		if ( null === $parent_stock ) {
			$v->set_manage_stock( true );
			$v->set_stock_quantity( $own );
			$v->set_low_stock_amount( 0 );
		}
		$vids[] = (int) $v->save();
	}
	wp_set_current_user( 0 );
	return array( $pid, $vids );
};
$set_status = static function ( int $id, string $status ) use ( $wpdb ) {
	$wpdb->update( $wpdb->posts, array( 'post_status' => $status ), array( 'ID' => $id ) );
	clean_post_cache( $id );
};
$update = static function ( int $id, array $props ) use ( &$A ) {
	wp_set_current_user( $A );
	clean_post_cache( $id );
	$p = wc_get_product( $id );
	$p->set_props( $props );
	$p->save();
	wp_set_current_user( 0 );
};

/** Runs worker processes of this file that start at the same moment; returns their JSON results. */
$run_workers = static function ( array $arg_sets ): array {
	$start = microtime( true ) + 2.5;
	$procs = array();
	$pipes = array();
	foreach ( $arg_sets as $i => $args ) {
		$procs[ $i ] = proc_open( array_merge( array( PHP_BINARY, __FILE__, '--worker' ), array_map( 'strval', $args ), array( sprintf( '%.4F', $start ) ) ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes[ $i ] );
	}
	$out = array();
	foreach ( $procs as $i => $p ) {
		$text = stream_get_contents( $pipes[ $i ][1] ) . stream_get_contents( $pipes[ $i ][2] );
		proc_close( $p );
		$lines     = array_values( array_filter( array_map( 'trim', explode( "\n", $text ) ) ) );
		$out[ $i ] = json_decode( (string) end( $lines ), true ) ?? array( 'raw' => $text );
	}
	return $out;
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
/** Places a real WooCommerce order in another process (reduces stock like an online checkout). */
$online_order = static function ( int $product, int $qty ) use ( &$orders ): void {
	$out = array();
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --worker online ' . $product . ' ' . $qty . ' 2>&1', $out );
	$json = json_decode( (string) end( $out ), true );
	if ( isset( $json['order'] ) ) {
		$orders[] = (int) $json['order'];
	}
};
/** WooCommerce log lines from this plugin, captured while $fn runs. */
$logged = static function ( callable $fn ): array {
	$lines = array();
	$cb    = static function ( $message, $level, $context ) use ( &$lines ) {
		if ( ( $context['source'] ?? '' ) === 'product-qrcode-barcode-generator' ) {
			$lines[] = $level . ':' . $message;
		}
		return $message;
	};
	add_filter( 'woocommerce_logger_log_message', $cb, 10, 3 );
	try {
		$fn();
	} finally {
		remove_filter( 'woocommerce_logger_log_message', $cb, 10 );
	}
	return array_values( array_unique( $lines ) ); // The filter runs once per log handler.
};
$stock_filters = static fn() => has_filter( SaleService::STOCK_QUERY_FILTER );
$sell_in = static function ( string $code, int $qty, int $seller, array $extra = array() ): array|WP_Error {
	return SaleService::sell( array_merge( array( 'code' => $code, 'quantity' => $qty, 'request_id' => wp_generate_uuid4(), 'seller_id' => $seller ), $extra ) );
};
$median = static function ( array $v ): float {
	sort( $v );
	return (float) $v[ intdiv( count( $v ), 2 ) ];
};

try {
	pqbg_section( 'setup' );
	foreach ( array( 'admin' => 'administrator', 'sm' => 'shop_manager', 'seller' => 'pqbg_seller', 'seller2' => 'pqbg_seller', 'nosell' => 'pqbg_seller', 'viewer' => 'subscriber', 'customer' => 'customer' ) as $who => $role ) {
		$pw[ $who ]       = wp_generate_password( 24, false );
		$user_ids[ $who ] = wp_insert_user( array( 'user_login' => "pqbg_p7_{$who}", 'user_pass' => $pw[ $who ], 'user_email' => "pqbg-p7-{$who}@example.invalid", 'role' => $role ) );
	}
	pqbg_t( 'temporary users created', 7 === count( array_filter( $user_ids, 'is_int' ) ) );
	$A  = $user_ids['admin'];
	$SE = $user_ids['seller'];
	$S2 = $user_ids['seller2'];
	$SM = $user_ids['sm'];
	( new WP_User( $user_ids['nosell'] ) )->add_cap( Permissions::SELL, false );
	( new WP_User( $user_ids['viewer'] ) )->add_cap( Permissions::VIEW_PRODUCTS );
	pqbg_t( 'capabilities: seller/sm/admin sell; "nosell" (pqbg_sell removed) and viewer only view; customer neither', Permissions::can_sell( $SE ) && Permissions::can_sell( $SM ) && Permissions::can_sell( $A ) && ! Permissions::can_sell( $user_ids['nosell'] ) && Permissions::can_view_products( $user_ids['nosell'] ) && ! Permissions::can_sell( $user_ids['viewer'] ) && Permissions::can_view_products( $user_ids['viewer'] ) && ! Permissions::can_view_products( $user_ids['customer'] ) );
	pqbg_t( 'void is manager-only (pqbg_void_sale exists; no new capability)', Permissions::can_void_sale( $SM ) && Permissions::can_void_sale( $A ) && ! Permissions::can_void_sale( $SE ) && 7 === count( Permissions::all_caps() ) );
	foreach ( array_keys( $user_ids ) as $who ) {
		$login( $who, "pqbg_p7_{$who}", $pw[ $who ] );
	}
	pqbg_t( 'site reachable, sellers logged in (entry page 200)', 200 === $http( 'seller', 'GET', $url() )['code'] );
	pqbg_t( 'no callbacks on the stock query filter before the suite (so "removed" checks are exact)', false === $stock_filters() );

	pqbg_section( 'schema version 2 and migration' );
	$cols = $wpdb->get_col( "SHOW COLUMNS FROM $S" );
	pqbg_t( 'the site is at DB version 2 (Install::DB_VERSION)', 2 === Install::DB_VERSION && 2 === Install::stored_version() );
	pqbg_t( 'pqbg_sales has stock_holder_id and failure_code, appended after every v1 column', array_slice( $cols, -2 ) === array( 'stock_holder_id', 'failure_code' ) && 27 === count( $cols ) );
	pqbg_t( 'holder_status index on (stock_holder_id, status)', array( 'stock_holder_id', 'status' ) === $wpdb->get_col( "SELECT Column_name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$S' AND INDEX_NAME = 'holder_status' ORDER BY SEQ_IN_INDEX" ) );
	pqbg_t( 'a normal boot does not re-run migrations (maybe_upgrade is a no-op at v2)', ( static function () {
		$ran = false;
		$cb  = static function () use ( &$ran ) {
			$ran = true;
		};
		add_action( 'dbdelta_queries', $cb );
		Install::maybe_upgrade();
		remove_action( 'dbdelta_queries', $cb );
		return ! $ran;
	} )() );
	// Migration on a temporary prefix: the real tables are never altered.
	$real_prefix  = $wpdb->prefix;
	$wpdb->prefix = $mig_prefix;
	try {
		$v2_sql = Schema::statements();
		$v1_sql = array_map( static fn( $sql ) => str_replace( array( "stock_holder_id bigint(20) unsigned NULL DEFAULT NULL,\n", "failure_code varchar(40) NULL DEFAULT NULL,\n", "KEY order_id (order_id),\nKEY holder_status (stock_holder_id,status)\n" ), array( '', '', "KEY order_id (order_id)\n" ), $sql ), $v2_sql );
		pqbg_t( 'migration: the v1 fixture is v2 without the two columns and the index', $v1_sql[0] === $v2_sql[0] && ! str_contains( $v1_sql[1], 'stock_holder_id' ) && ! str_contains( $v1_sql[1], 'failure_code' ) && str_contains( $v1_sql[1], "KEY order_id (order_id)\n)" ) );
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $v1_sql );
		$mS    = Schema::sales_table();
		$v1c   = $wpdb->get_col( "SHOW COLUMNS FROM $mS" );
		$wpdb->insert( $mS, array( 'request_id' => wp_generate_uuid4(), 'product_id' => 1, 'seller_id' => 1, 'unit_price' => '10', 'line_total' => '10', 'currency' => 'INR', 'product_name' => 'v1 row', 'created_at_gmt' => '2026-01-01 00:00:00' ) );
		$v1row = $wpdb->get_row( "SELECT * FROM $mS", ARRAY_A );
		pqbg_t( 'migration: v1 fixture tables created on the temporary prefix (25 sales columns, 1 row)', str_starts_with( $mS, $mig_prefix ) && 25 === count( $v1c ) && is_array( $v1row ) );
		$res  = Install::migrate_2();
		$v2c  = $wpdb->get_col( "SHOW COLUMNS FROM $mS" );
		$v2row = $wpdb->get_row( "SELECT * FROM $mS", ARRAY_A );
		pqbg_t( 'migration: upgrade v1 → v2 adds the columns and index', true === $res && array_slice( $v2c, -2 ) === array( 'stock_holder_id', 'failure_code' ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$mS' AND INDEX_NAME = 'holder_status' AND SEQ_IN_INDEX = 1" ) );
		pqbg_t( 'migration: the existing row is unchanged, with NULL in the new columns', array_intersect_key( $v2row, $v1row ) === $v1row && null === $v2row['stock_holder_id'] && null === $v2row['failure_code'] );
		pqbg_t( 'migration: re-running is a no-op (empty dbDelta log, same row)', true === Install::migrate_2() && array() === Schema::create_or_update() && $v2row === $wpdb->get_row( "SELECT * FROM $mS", ARRAY_A ) );
		Schema::drop_tables();
		$fresh = Install::migrate_1();
		$fresh2 = Install::migrate_2();
		pqbg_t( 'migration: fresh install (migrate_1 then migrate_2) gives the full v2 schema', true === $fresh && true === $fresh2 && $wpdb->get_col( "SHOW COLUMNS FROM $mS" ) === $cols );
		Schema::drop_tables();
		pqbg_t( 'migration: temporary tables dropped', ! Schema::tables_exist() );
	} finally {
		$wpdb->prefix = $real_prefix;
	}
	pqbg_t( 'the real tables still exist (prefix restored)', Schema::tables_exist() && Schema::sales_table() === $real_prefix . 'pqbg_sales' );
	$uninstall = (string) file_get_contents( PQBG_PLUGIN_DIR . 'uninstall.php' );
	pqbg_t( 'uninstall unchanged: keeps data by default, drops both tables only with PQBG_UNINSTALL_DELETE_ALL_DATA', str_contains( $uninstall, "if ( ! defined( 'PQBG_UNINSTALL_DELETE_ALL_DATA' ) || true !== PQBG_UNINSTALL_DELETE_ALL_DATA ) {\n\treturn;\n}" ) && str_contains( $uninstall, 'Schema::drop_tables();' ) );

	pqbg_section( 'compatibility: WooCommerce stock SQL (the atomic sale depends on it)' );
	$compat = $make_simple( $A, array( 'stock_quantity' => 10 ) );
	$seen   = array();
	$cap    = static function ( $sql, $id = 0, $new = null, $op = '' ) use ( &$seen ) {
		$seen[] = array( $sql, (int) $id, $new, $op );
		return $sql;
	};
	add_filter( SaleService::STOCK_QUERY_FILTER, $cap, 10, 4 );
	wc_update_product_stock( wc_get_product( $compat ), 2, 'decrease' );
	wc_update_product_stock( wc_get_product( $compat ), 3, 'increase' );
	remove_filter( SaleService::STOCK_QUERY_FILTER, $cap, 10 );
	pqbg_t( 'COMPATIBILITY: woocommerce_update_product_stock_query still fires once per stock change, with (sql, product id, new stock, operation)', 2 === count( $seen ) && $compat === $seen[0][1] && 'decrease' === $seen[0][3] && 8 === (int) $seen[0][2] && 'increase' === $seen[1][3] && 11 === (int) $seen[1][2], wp_json_encode( $seen ) );
	pqbg_t( 'COMPATIBILITY: the SQL it receives still has the expected shape (relative UPDATE of _stock)', 2 === count( $seen ) && SaleService::is_expected_stock_sql( $seen[0][0], $compat, 2, 'decrease' ) && SaleService::is_expected_stock_sql( $seen[1][0], $compat, 3, 'increase' ), (string) ( $seen[0][0] ?? '' ) );
	pqbg_t( 'the shape check rejects other holders, quantities, operations and SQL', ! SaleService::is_expected_stock_sql( (string) $seen[0][0], $compat + 1, 2, 'decrease' ) && ! SaleService::is_expected_stock_sql( (string) $seen[0][0], $compat, 3, 'decrease' ) && ! SaleService::is_expected_stock_sql( (string) $seen[0][0], $compat, 2, 'increase' ) && ! SaleService::is_expected_stock_sql( $seen[0][0] . ' LIMIT 1', $compat, 2, 'decrease' ) && ! SaleService::is_expected_stock_sql( "UPDATE {$wpdb->postmeta} SET meta_value = 5 WHERE post_id = $compat AND meta_key='_stock'", $compat, 2, 'decrease' ) );
	pqbg_t( 'stock after the two direct changes is 11', 11 === $stock( $compat ) );

	pqbg_section( 'price normalisation (D3)' );
	pqbg_t( '"1499", "1499.00" and 1499.0 normalise to the same value', '1499.00' === SaleService::normalize_price( '1499' ) && '1499.00' === SaleService::normalize_price( '1499.00' ) && '1499.00' === SaleService::normalize_price( 1499.0 ) && '1499.00' === SaleService::normalize_price( 1499 ) );
	pqbg_t( 'empty, null and non-numeric prices normalise to ""', '' === SaleService::normalize_price( '' ) && '' === SaleService::normalize_price( null ) && '' === SaleService::normalize_price( 'abc' ) );
	pqbg_t( 'line totals are rounded to the store decimals', '2998.00' === SaleService::line_total( '1499', 2 ) && '100.00' === SaleService::line_total( '33.333333', 3 ) );

	pqbg_section( 'product screen: the sale form' );
	$p1   = $make_simple( $A, array( 'stock_quantity' => 3 ) );
	$c1   = $code_of( $p1 );
	$r    = $http( 'seller', 'GET', $url( $c1 ) );
	$f    = $form_of( $r['body'] );
	pqbg_t( 'seller: the product screen has a sale form posting to the canonical code URL', 200 === $r['code'] && is_array( $f ) && $url( $c1 ) === $f['action'], $c1 );
	pqbg_t( 'form: quantity list 1..stock, each with its total (D1)', is_array( $f ) && array( 1, 2, 3 ) === array_keys( $f['options'] ) && '1 × ₹1,499.00 = ₹1,499.00' === $f['options'][1] && '3 × ₹1,499.00 = ₹4,497.00' === $f['options'][3], wp_json_encode( $f['options'] ?? null, JSON_UNESCAPED_UNICODE ) );
	pqbg_t( 'form: default quantity 1 is selected; one clearly labelled "Confirm sale" button; POST', is_array( $f ) && (bool) preg_match( '/<option value="1" selected=\'selected\'>/', $f['html'] ) && 1 === substr_count( $f['html'], '>Confirm sale</button>' ) && 1 === substr_count( $r['body'], '<button' ) - 1 );
	pqbg_t( 'form: hidden fields are exactly action, nonce, request_id, issued, seen_price, seen_stock, sig', is_array( $f ) && array( 'pqbg_action', '_pqbg_nonce', 'request_id', 'issued', 'seen_price', 'seen_stock', 'sig' ) === array_keys( $f['fields'] ) && 'sell' === $f['fields']['pqbg_action'] && '1499.00' === $f['fields']['seen_price'] && '3' === $f['fields']['seen_stock'] );
	$f2 = $get_form( 'seller', $c1 );
	pqbg_t( 'form: request_id is a UUID v4, new on every render, and the signature differs', is_array( $f ) && is_array( $f2 ) && wp_is_uuid( $f['fields']['request_id'], 4 ) && $f['fields']['request_id'] !== $f2['fields']['request_id'] && $f['fields']['sig'] !== $f2['fields']['sig'] && 64 === strlen( $f['fields']['sig'] ) );
	pqbg_t( 'form: the sale form is separate from the code box (Enter in the box never sells)', 2 === substr_count( $r['body'], '<form ' ) && (bool) preg_match( '/<form class="pqbg-scan__form" method="get"/', $r['body'] ) );
	pqbg_t( 'shop manager and administrator also get the form', null !== $get_form( 'sm', $c1 ) && null !== $get_form( 'admin', $c1 ) );
	$rv = $http( 'viewer', 'GET', $url( $c1 ) );
	$rn = $http( 'nosell', 'GET', $url( $c1 ) );
	pqbg_t( 'view-only user and a seller without pqbg_sell: the product screen with no sale controls', 200 === $rv['code'] && null === $form_of( $rv['body'] ) && ! str_contains( $rv['body'], 'Confirm sale' ) && 200 === $rn['code'] && null === $form_of( $rn['body'] ) && str_contains( $rn['body'], 'pqbg-scan__price' ) );
	pqbg_t( '...and no sale notices either (identical to the Phase 6 screen)', array() === $notices_in( $rv['body'] ) && array() === $notices_in( $rn['body'] ) );
	$big = $make_simple( $A, array( 'stock_quantity' => 150 ) );
	$fb  = $get_form( 'seller', $code_of( $big ) );
	pqbg_t( 'stock above 100: a number field (min 1, max = stock) with the unit price, no list', is_array( $fb ) && array() === $fb['options'] && 150 === $fb['number_max'] && str_contains( $fb['html'], 'min="1"' ) && str_contains( $fb['html'], 'Total = quantity × ₹1,499.00' ) );
	$unm = $make_simple( $A, array( 'manage_stock' => false, 'stock_status' => 'instock' ) );
	$ru  = $http( 'seller', 'GET', $url( $code_of( $unm ) ) );
	// The wording names the WooCommerce 11.1.2 checkboxes: "Track stock quantity for this product" (Inventory tab), "Manage stock?" (variation).
	pqbg_t( 'unmanaged stock: no sale form, and the exact message naming WooCommerce\'s "Track stock quantity for this product" checkbox', null === $form_of( $ru['body'] ) && $has_notice( $ru, "Stock tracking is off for this product. On the Inventory tab, tick 'Track stock quantity for this product' to sell from a scan." ), implode( ' | ', $notices_in( $ru['body'] ) ) );
	list( $uvp, $uvv ) = $make_variable( $A, 1, null );
	$update( $uvv[0], array( 'manage_stock' => false ) );
	$ruv = $http( 'seller', 'GET', $url( $code_of( $uvv[0] ) ) );
	pqbg_t( 'unmanaged variation stock (variation and parent): no sale form, the message names "Manage stock?" and the parent\'s checkbox', null === $form_of( $ruv['body'] ) && $has_notice( $ruv, "Stock tracking is off for this variation. Tick 'Manage stock?' on the variation, or 'Track stock quantity for this product' on the product's Inventory tab, to sell from a scan." ), implode( ' | ', $notices_in( $ruv['body'] ) ) );
	pqbg_t( '...a viewer does not see that message', ! str_contains( $http( 'viewer', 'GET', $url( $code_of( $unm ) ) )['body'], 'Stock tracking is off' ) );
	$nop = $make_simple( $A, array( 'regular_price' => '' ) );
	$zp  = $make_simple( $A, array( 'regular_price' => '0' ) );
	$rnp = $http( 'seller', 'GET', $url( $code_of( $nop ) ) );
	$rzp = $http( 'seller', 'GET', $url( $code_of( $zp ) ) );
	pqbg_t( 'empty price: no sale form, "This item has no price. Set a price before selling."', null === $form_of( $rnp['body'] ) && $has_notice( $rnp, 'This item has no price. Set a price before selling.' ) );
	pqbg_t( 'zero price (D7 changed): no sale form, "This item has no price (₹0). Set a price before selling."', null === $form_of( $rzp['body'] ) && $has_notice( $rzp, 'This item has no price (₹0). Set a price before selling.' ), implode( ' | ', $notices_in( $rzp['body'] ) ) );
	$oos = $make_simple( $A, array( 'stock_quantity' => 0 ) );
	$bo  = $make_simple( $A, array( 'stock_quantity' => 0, 'backorders' => 'yes' ) );
	$ro  = $http( 'seller', 'GET', $url( $code_of( $oos ) ) );
	$rbo = $http( 'seller', 'GET', $url( $code_of( $bo ) ) );
	pqbg_t( 'stock 0: no sale form, "Out of stock – cannot be sold."', null === $form_of( $ro['body'] ) && $has_notice( $ro, 'Out of stock – cannot be sold.' ) );
	pqbg_t( 'stock 0 with backorders allowed: still no sale form', 'onbackorder' === $stock_status( $bo ) && null === $form_of( $rbo['body'] ) && $has_notice( $rbo, 'Out of stock – cannot be sold.' ) );

	pqbg_section( 'a successful sale over HTTP' );
	$f     = $get_form( 'seller', $c1 );
	$hooks = array();
	$t0    = microtime( true );
	$r     = $post_form( 'seller', $c1, $f, '2' );
	$sid   = $sale_id_of( $r );
	$row   = SaleRepository::find( $sid );
	pqbg_t( 'POST → 303 to /scan/{CODE}/?sale={id} (Post/Redirect/Get)', 303 === $r['code'] && SaleRequest::sale_url( $c1, $sid ) === $r['location'] && $sid > $start_s, $r['code'] . ' ' . $r['location'] . ' ' . implode( ' | ', $notices_in( $r['body'] ) ) );
	pqbg_t( 'stock decremented 3 → 1 (WooCommerce object, status and lookup table agree)', 1 === $stock( $p1 ) && ( $sync() || true ) && 1 === wc_get_product( $p1 )->get_stock_quantity() && 'instock' === $stock_status( $p1 ) && 1 === (int) $lookup_stock( $p1 )['stock_quantity'] );
	pqbg_t( 'exactly one sale row', 1 === count( $rows_for( $p1 ) ) );
	$prod = wc_get_product( $p1 );
	pqbg_t( 'row: status completed, request_id from the form, code, seller, quantity', is_array( $row ) && 'completed' === $row['status'] && $f['fields']['request_id'] === $row['request_id'] && (int) CodeRepository::find_by_code( $c1 )['id'] === (int) $row['code_id'] && $SE === (int) $row['seller_id'] && 2 === (int) $row['quantity'] );
	pqbg_t( 'row: product_id = product, variation_id 0, stock_holder_id = product, source scan, no order, no failure', is_array( $row ) && $p1 === (int) $row['product_id'] && 0 === (int) $row['variation_id'] && $p1 === (int) $row['stock_holder_id'] && 'scan' === $row['source'] && null === $row['order_id'] && null === $row['failure_code'] && null === $row['voided_at_gmt'] );
	pqbg_t( 'row: prices (unit 1499, regular 1499, line total 2998, INR)', is_array( $row ) && '1499.00000000' === $row['unit_price'] && '1499.00000000' === $row['regular_price'] && '2998.00000000' === $row['line_total'] && 'INR' === $row['currency'] );
	pqbg_t( 'row: snapshots (name, SKU, no attributes, stock 3 → 1, time now)', is_array( $row ) && $prod->get_name() === $row['product_name'] && $prod->get_sku() === $row['sku'] && null === $row['attributes_json'] && 3 === (int) $row['stock_before'] && 1 === (int) $row['stock_after'] && abs( SaleService::created_ts( $row ) - time() ) < 30 );
	pqbg_t( 'the stock lock is free and the SQL filter is gone after the request (checked in-process)', StockLock::is_free( $p1 ) && false === $stock_filters() );
	$rs = $http( 'seller', 'GET', $r['location'] );
	pqbg_t( 'success page: 200, "Sold.", what was sold, quantity × price, total, new stock', 200 === $rs['code'] && $has_notice( $rs, 'Sold.' ) && str_contains( $rs['body'], esc_html( $prod->get_name() ) ) && str_contains( $rs['body'], '<dd>2 × ₹1,499.00</dd>' ) && str_contains( $rs['body'], '>₹2,998.00</dd>' ) && str_contains( $rs['body'], '<dt>Stock now</dt>' ) && (bool) preg_match( '/<dt>Stock now<\/dt>\s*<dd>1<\/dd>/', $rs['body'] ) );
	pqbg_t( 'success page: time in the site timezone (wp_date)', str_contains( $rs['body'], esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), SaleService::created_ts( $row ) ) ) ) );
	$uf = $undo_form_of( $rs['body'] );
	pqbg_t( 'success page: the Undo button with "Undo available until"', is_array( $uf ) && 'undo' === $uf['fields']['pqbg_action'] && (string) $sid === $uf['fields']['sale'] && str_contains( $rs['body'], 'Undo available until ' . esc_html( wp_date( get_option( 'time_format' ), SaleService::undo_until( $row ) ) ) ) );
	pqbg_t( 'success page: "Scan next item" box with autofocus, no sale form', str_contains( $rs['body'], '>Scan next item</label>' ) && (bool) preg_match( '/<input class="pqbg-scan__input"[^>]*autofocus/', $rs['body'] ) && null === $form_of( $rs['body'] ) );
	$before_reload = $sales_sum() . '|' . $stock( $p1 );
	$http( 'seller', 'GET', $r['location'] );
	$http( 'seller', 'HEAD', $r['location'] );
	pqbg_t( 'reloading the success page (GET, HEAD) changes nothing', $before_reload === $sales_sum() . '|' . $stock( $p1 ) );
	pqbg_t( 'security headers on the 303 and on the success page; no <script>', $headers_ok( $r ) && $headers_ok( $rs ) && ! str_contains( $rs['body'], '<script' ) );
	$again = $post_form( 'seller', $c1, $f, '2' );
	pqbg_t( 'idempotency: the same form submitted again → 303 to the SAME sale, no new row, no decrement', 303 === $again['code'] && $sid === $sale_id_of( $again ) && 1 === count( $rows_for( $p1 ) ) && 1 === $stock( $p1 ) );
	pqbg_t( 'the product screen now shows 1 left and a form with one option', array( 1 ) === array_keys( (array) ( $get_form( 'seller', $c1 )['options'] ?? array() ) ) );

	pqbg_section( 'quantity bounds' );
	$pq  = $make_simple( $A, array( 'stock_quantity' => 3 ) );
	$cq  = $code_of( $pq );
	$fq  = $get_form( 'seller', $cq );
	$bad = array();
	foreach ( array( '0', '-1', 'abc', '1.5', '', ' 1', '1e1', '99999999' ) as $q ) {
		$rq = $post_form( 'seller', $cq, $fq, $q );
		if ( 400 !== $rq['code'] || ! $has_notice( $rq, 'Choose a quantity between 1 and 3.' ) ) {
			$bad[] = $q . '→' . $rq['code'];
		}
	}
	$rq = $post_form( 'seller', $cq, $fq, null );
	pqbg_t( 'quantity 0, -1, "abc", "1.5", "", " 1", "1e1", huge, missing → 400 "Choose a quantity between 1 and 3."', array() === $bad && 400 === $rq['code'], implode( ',', $bad ) );
	$rq = $post_form( 'seller', $cq, $fq, '4' );
	pqbg_t( 'quantity above the stock → 400 "Only 3 in stock. Choose a quantity between 1 and 3."', 400 === $rq['code'] && $has_notice( $rq, 'Only 3 in stock. Choose a quantity between 1 and 3.' ) );
	pqbg_t( '...nothing sold, stock unchanged, the error page carries a fresh form', array() === $rows_for( $pq ) && 3 === $stock( $pq ) && null !== $form_of( $rq['body'] ) && $form_of( $rq['body'] )['fields']['request_id'] !== $fq['fields']['request_id'] );
	$rq = $post_form( 'seller', $cq, $fq, '3' );
	pqbg_t( 'exactly all remaining stock (3 of 3) → sold, stock 0, WooCommerce status outofstock', 303 === $rq['code'] && 0 === $stock( $pq ) && 'outofstock' === $stock_status( $pq ) && 0 === (int) $rows_for( $pq )[0]['stock_after'] );
	$rq2 = $http( 'seller', 'GET', $url( $cq ) );
	pqbg_t( '...the product screen then says out of stock, with no form', $has_notice( $rq2, 'Out of stock – cannot be sold.' ) && null === $form_of( $rq2['body'] ) );

	pqbg_section( 'refused server-side: stock, backorders, unmanaged, price' );
	$pb = $make_simple( $A, array( 'stock_quantity' => 1, 'backorders' => 'yes' ) );
	$fb = $get_form( 'seller', $code_of( $pb ) );
	wc_update_product_stock( $pb, 0, 'set' );
	$rb = $post_form( 'seller', $code_of( $pb ), $fb );
	pqbg_t( 'stock 0 with backorders allowed (form from when stock was 1) → 409 "Out of stock – cannot be sold.", nothing sold', 409 === $rb['code'] && $has_notice( $rb, 'Out of stock – cannot be sold.' ) && array() === $rows_for( $pb ) && 0 === $stock( $pb ) );
	$pch = $make_simple( $A, array( 'stock_quantity' => 3 ) );
	$fch = $get_form( 'seller', $code_of( $pch ) );
	wc_update_product_stock( $pch, 1, 'set' );
	$rch = $post_form( 'seller', $code_of( $pch ), $fch, '2' );
	pqbg_t( 'stock changed since the page was opened (3 → 1, asking 2) → 409 "Stock changed since you opened this page. Now 1 in stock."', 409 === $rch['code'] && $has_notice( $rch, 'Stock changed since you opened this page. Now 1 in stock.' ) && array() === $rows_for( $pch ) && 1 === $stock( $pch ) );
	$rch = $post_form( 'seller', $code_of( $pch ), $fch, '1' );
	pqbg_t( '...but 1 still fits: the same form sells 1 (a stock change alone does not block)', 303 === $rch['code'] && 0 === $stock( $pch ) );
	$pu = $make_simple( $A, array( 'stock_quantity' => 3 ) );
	$fu = $get_form( 'seller', $code_of( $pu ) );
	$update( $pu, array( 'manage_stock' => false ) );
	$ru = $post_form( 'seller', $code_of( $pu ), $fu );
	pqbg_t( 'stock tracking turned off after the form was opened → 409 with the message, nothing sold', 409 === $ru['code'] && $has_notice( $ru, "Stock tracking is off for this product. On the Inventory tab, tick 'Track stock quantity for this product' to sell from a scan." ) && array() === $rows_for( $pu ) );
	$pn = $make_simple( $A, array( 'stock_quantity' => 3 ) );
	$fn = $get_form( 'seller', $code_of( $pn ) );
	$update( $pn, array( 'regular_price' => '' ) );
	$rn = $post_form( 'seller', $code_of( $pn ), $fn );
	pqbg_t( 'price removed after the form was opened → 409 "This item has no price…", nothing sold', 409 === $rn['code'] && $has_notice( $rn, 'This item has no price. Set a price before selling.' ) && array() === $rows_for( $pn ) && 3 === $stock( $pn ) );
	$update( $pn, array( 'regular_price' => '0' ) );
	$rn = $post_form( 'seller', $code_of( $pn ), $fn );
	pqbg_t( 'price set to 0 after the form was opened → 409 "This item has no price (₹0)…", nothing sold', 409 === $rn['code'] && $has_notice( $rn, 'This item has no price (₹0). Set a price before selling.' ) && array() === $rows_for( $pn ) );
	$zero_in = $sell_in( $code_of( $zp ), 1, $SE );
	pqbg_t( 'service: zero price refused (pqbg_zero_price), empty price refused (pqbg_no_price)', is_wp_error( $zero_in ) && 'pqbg_zero_price' === $zero_in->get_error_code() && 'pqbg_no_price' === $sell_in( $code_of( $nop ), 1, $SE )->get_error_code() );
	$pp = $make_simple( $A, array( 'stock_quantity' => 3 ) );
	$fp = $get_form( 'seller', $code_of( $pp ) );
	$update( $pp, array( 'regular_price' => '1599' ) );
	$rp = $post_form( 'seller', $code_of( $pp ), $fp );
	pqbg_t( 'price changed after the form was opened (D3) → 409 "The price changed…", nothing sold', 409 === $rp['code'] && $has_notice( $rp, 'The price changed since you opened this page. Check the new price and confirm again.' ) && array() === $rows_for( $pp ) && 3 === $stock( $pp ) );
	pqbg_t( '...and the fresh form on that page shows the new price', str_contains( (string) ( $form_of( $rp['body'] )['options'][1] ?? '' ), '₹1,599.00' ) );
	$pnorm = $make_simple( $A, array( 'stock_quantity' => 3, 'regular_price' => '1499' ) );
	$fnorm = $get_form( 'seller', $code_of( $pnorm ) );
	update_post_meta( $pnorm, '_regular_price', '1499.00' );
	update_post_meta( $pnorm, '_price', '1499.00' );
	$rnorm = $post_form( 'seller', $code_of( $pnorm ), $fnorm );
	pqbg_t( 'the same price stored as "1499" then "1499.00" → no false "price changed", sold', 303 === $rnorm['code'], $rnorm['code'] . ' ' . implode( ' | ', $notices_in( $rnorm['body'] ) ) );
	$norm_in = $sell_in( $code_of( $pnorm ), 1, $SE, array( 'seen_price' => 1499.0 ) );
	$norm_in2 = $sell_in( $code_of( $pnorm ), 1, $SE, array( 'seen_price' => '1499' ) );
	pqbg_t( 'service: seen price 1499.0 (float) and "1499" against a stored "1499.00" → sold', is_array( $norm_in ) && 'completed' === $norm_in['status'] && is_array( $norm_in2 ) && 'completed' === $norm_in2['status'] );

	pqbg_section( 'scheduled sale price' );
	$psale = $make_simple( $A, array( 'stock_quantity' => 5, 'regular_price' => '2000', 'sale_price' => '1500', 'date_on_sale_from' => time() - DAY_IN_SECONDS, 'date_on_sale_to' => time() + DAY_IN_SECONDS ) );
	$pfut  = $make_simple( $A, array( 'stock_quantity' => 5, 'regular_price' => '2000', 'sale_price' => '1500', 'date_on_sale_from' => time() + DAY_IN_SECONDS, 'date_on_sale_to' => time() + 2 * DAY_IN_SECONDS ) );
	$sync();
	$rsl   = $post_form( 'seller', $code_of( $psale ), $get_form( 'seller', $code_of( $psale ) ) );
	$rfu   = $post_form( 'seller', $code_of( $pfut ), $get_form( 'seller', $code_of( $pfut ) ) );
	$rowsl = $rows_for( $psale )[0] ?? array();
	$rowfu = $rows_for( $pfut )[0] ?? array();
	pqbg_t( 'sale running now: unit_price = the active sale price 1500, regular_price 2000', 303 === $rsl['code'] && '1500.00000000' === ( $rowsl['unit_price'] ?? '' ) && '2000.00000000' === ( $rowsl['regular_price'] ?? '' ) && (string) wc_get_product( $psale )->get_price() === rtrim( rtrim( $rowsl['unit_price'], '0' ), '.' ) );
	pqbg_t( 'sale scheduled for tomorrow: unit_price = today\'s active price 2000', 303 === $rfu['code'] && '2000.00000000' === ( $rowfu['unit_price'] ?? '' ) );

	pqbg_section( 'variations: own stock and parent-level stock' );
	list( $vp, $vv ) = $make_variable( $A, 2, null, 4 );
	$cv  = $code_of( $vv[0] );
	$fv  = $get_form( 'seller', $cv );
	$hk  = array( 'product' => 0, 'variation' => 0 );
	$rv  = $post_form( 'seller', $cv, $fv, '1' );
	$rwv = $rows_for( $vv[0] )[0] ?? array();
	pqbg_t( 'own-stock variation: the variation is decremented (4 → 3); its sibling and the parent untouched', 303 === $rv['code'] && 3 === $stock( $vv[0] ) && 4 === $stock( $vv[1] ) && in_array( $stock( $vp ), array( null, 0 ), true ) );
	pqbg_t( 'own-stock variation row: product_id = parent, variation_id = variation, stock_holder_id = variation', $vp === (int) ( $rwv['product_id'] ?? 0 ) && $vv[0] === (int) ( $rwv['variation_id'] ?? 0 ) && $vv[0] === (int) ( $rwv['stock_holder_id'] ?? 0 ) );
	pqbg_t( 'variation snapshots: parent name, variation SKU, attributes {"Size":"S1"}, price 799', ( $rwv['product_name'] ?? '' ) === wc_get_product( $vp )->get_name() && ( $rwv['sku'] ?? '' ) === 'PQBG-P7-' . $vp . '-S1' && '{"Size":"S1"}' === ( $rwv['attributes_json'] ?? '' ) && '799.00000000' === ( $rwv['unit_price'] ?? '' ) );
	$rvs = $http( 'seller', 'GET', $rv['location'] );
	pqbg_t( 'variation success page shows the attributes', str_contains( $rvs['body'], '<p class="pqbg-scan__attributes">Size: S1</p>' ) );
	list( $pp2, $pv2 ) = $make_variable( $A, 2, 3 );
	$cpv = $code_of( $pv2[0] );
	$fpv = $get_form( 'seller', $cpv );
	pqbg_t( 'parent-level stock: the form uses the parent stock (3 options)', is_array( $fpv ) && array( 1, 2, 3 ) === array_keys( $fpv['options'] ) && '3' === $fpv['fields']['seen_stock'] );
	$h   = $hold_lock( $pp2, 7 );
	$t0  = microtime( true );
	$rbz = $post_form( 'seller', $cpv, $fpv, '1' );
	$wait = microtime( true ) - $t0;
	$end_hold( $h );
	pqbg_t( 'parent-level stock: the sale locks the PARENT — with the parent lock held elsewhere → 503 busy after ~5 s', $h[2] && 503 === $rbz['code'] && $has_notice( $rbz, 'Someone else is selling this item right now. Try again.' ) && $wait >= 4.5, sprintf( '%.1f s', $wait ) );
	pqbg_t( '...Retry-After: 2 and the security headers; nothing sold, stock unchanged', '2' === ( $rbz['headers']['retry-after'] ?? '' ) && $headers_ok( $rbz ) && array() === $rows_for( $pv2[0] ) && 3 === $stock( $pp2 ) );
	$h   = $hold_lock( $pv2[0], 7 );
	$rpv = $post_form( 'seller', $cpv, $fpv, '2' );
	$end_hold( $h );
	$rwp = $rows_for( $pv2[0] )[0] ?? array();
	pqbg_t( '...a lock on the variation itself does not block it (the variation does not hold the stock)', 303 === $rpv['code'] );
	pqbg_t( 'parent-level stock: the PARENT is decremented 3 → 1; the variation has no stock of its own', 1 === $stock( $pp2 ) && in_array( $stock( $pv2[0] ), array( null, 0 ), true ) );
	pqbg_t( 'parent-level row: stock_holder_id = parent, product_id = parent, variation_id = variation, stock 3 → 1', $pp2 === (int) ( $rwp['stock_holder_id'] ?? 0 ) && $pp2 === (int) ( $rwp['product_id'] ?? 0 ) && $pv2[0] === (int) ( $rwp['variation_id'] ?? 0 ) && 3 === (int) ( $rwp['stock_before'] ?? -1 ) && 1 === (int) ( $rwp['stock_after'] ?? -1 ) );
	$fsib = $get_form( 'seller', $code_of( $pv2[1] ) );
	pqbg_t( 'the sibling variation now offers only 1 (shared parent stock)', is_array( $fsib ) && array( 1 ) === array_keys( $fsib['options'] ) );

	pqbg_section( 'every non-sellable state is refused server-side' );
	$refusals = array();
	$refuse   = static function ( string $label, int $status, string $message, int $id, callable $change, ?string $code = null ) use ( &$refusals, $make_simple, $get_form, $post_form, $has_notice, $code_of, $rows_for, $stock, $notices_in, &$A ) {
		$code  = $code ?? $code_of( $id );
		$form  = $get_form( 'seller', $code );
		$before = $stock( $id );
		$change();
		$r     = $post_form( 'seller', $code, (array) $form );
		$ok    = is_array( $form ) && $status === $r['code'] && $has_notice( $r, $message ) && array() === $rows_for( $id ) && $before === $stock( $id );
		pqbg_t( "refused: {$label} → {$status} \"{$message}\"", $ok, $r['code'] . ' ' . implode( ' | ', $notices_in( $r['body'] ) ) );
	};
	$pd = $make_simple( $A );
	$refuse( 'draft', 409, 'Not published – cannot be sold yet.', $pd, static fn() => $set_status( $pd, 'draft' ) );
	$pe = $make_simple( $A );
	$refuse( 'pending review', 409, 'Not published – cannot be sold yet.', $pe, static fn() => $set_status( $pe, 'pending' ) );
	$pf = $make_simple( $A );
	$refuse( 'scheduled', 409, 'Not published – cannot be sold yet.', $pf, static fn() => $set_status( $pf, 'future' ) );
	$pt = $make_simple( $A );
	$refuse( 'trash', 409, 'This product is in the trash – cannot be sold.', $pt, static fn() => $set_status( $pt, 'trash' ) );
	list( $xp, $xv ) = $make_variable( $A, 3, null );
	$refuse( 'disabled (private) variation', 409, 'This variation is disabled – cannot be sold.', $xv[0], static fn() => $set_status( $xv[0], 'private' ) );
	$refuse( 'variation under a draft parent', 409, 'Not published – cannot be sold yet.', $xv[1], static fn() => $set_status( $xp, 'draft' ) );
	$set_status( $xp, 'publish' );
	$refuse( 'variation under a trashed parent', 409, 'This product is in the trash – cannot be sold.', $xv[2], static fn() => $set_status( $xp, 'trash' ) );
	$pr = $make_simple( $A );
	$old = $code_of( $pr );
	$refuse( 'retired code (regenerated after the form was opened)', 409, 'This label is out of date.', $pr, static fn() => ( new ProductCodeService() )->regenerate( $pr, $A ), $old );
	$pg = $make_simple( $A );
	$refuse( 'active code whose product vanished', 409, 'This label is no longer valid.', $pg, static fn() => $wpdb->delete( $wpdb->posts, array( 'ID' => $pg ) ) && clean_post_cache( $pg ) );
	$unknown = 'DC-' . implode( '-', str_split( strtoupper( substr( str_shuffle( str_repeat( '23456789ABCDEFGHJKMNPQRSTUVWXYZ', 3 ) ), 0, 12 ) ), 4 ) );
	$ruk     = $http( 'seller', 'POST', $url( $unknown ), array( 'pqbg_action' => 'sell', 'quantity' => '1' ) );
	pqbg_t( 'refused: unknown code → 404 "Code not found."', 404 === $ruk['code'] && $has_notice( $ruk, 'Code not found.' ) );
	$rin = $http( 'seller', 'POST', $home . '/scan/NOT-A-CODE/', array( 'pqbg_action' => 'sell', 'quantity' => '1' ) );
	pqbg_t( 'refused: invalid code → 400 "Not a valid product code."', 400 === $rin['code'] && $has_notice( $rin, 'Not a valid product code.' ) );
	$ppv = $make_simple( $A, array( 'status' => 'private' ) );
	$rpv2 = $post_form( 'seller', $code_of( $ppv ), $get_form( 'seller', $code_of( $ppv ) ) );
	list( $prp, $prv ) = $make_variable( $A, 1, null, 2, 'private' );
	$rprv = $post_form( 'seller', $code_of( $prv[0] ), $get_form( 'seller', $code_of( $prv[0] ) ) );
	pqbg_t( 'allowed: a private simple product and a variation under a private parent are sold', 303 === $rpv2['code'] && 303 === $rprv['code'] );

	pqbg_section( 'permissions, nonces and tokens' );
	$pa  = $make_simple( $A, array( 'stock_quantity' => 5 ) );
	$ca  = $code_of( $pa );
	$fa  = $get_form( 'seller', $ca );
	$chk = static function ( string $label, array $r, int $status, ?string $notice = null ) use ( $rows_for, $pa, $stock, $has_notice, $headers_ok, $notices_in ) {
		pqbg_t( $label, $status === $r['code'] && ( null === $notice || $has_notice( $r, $notice ) ) && array() === $rows_for( $pa ) && 5 === $stock( $pa ) && $headers_ok( $r ), $r['code'] . ' ' . implode( ' | ', $notices_in( $r['body'] ) ) );
	};
	$ranon = $http( 'anon', 'POST', $url( $ca ), array_merge( $fa['fields'], array( 'quantity' => '1' ) ) );
	$chk( 'logged out: POST → 302 to the login page (back to the product URL), nothing sold', $ranon, 302 );
	pqbg_t( '...the login redirect returns to the code URL, never replays the POST', str_starts_with( $ranon['location'], wp_login_url() ) && str_contains( rawurldecode( $ranon['location'] ), $url( $ca ) ) );
	$rc = $http( 'customer', 'POST', $url( $ca ), array_merge( $fa['fields'], array( 'quantity' => '1' ) ) );
	$chk( 'customer: POST → the fixed 403 (no product data)', $rc, 403, 'You do not have permission to view products.' );
	pqbg_t( '...byte-identical to the customer GET 403', $http( 'customer', 'GET', $url( $ca ) )['body'] === $rc['body'] );
	$chk( 'view-only user: POST (with the seller\'s form) → 403 "You do not have permission to sell."', $http( 'viewer', 'POST', $url( $ca ), array_merge( $fa['fields'], array( 'quantity' => '1' ) ) ), 403, 'You do not have permission to sell.' );
	$chk( 'seller with pqbg_sell removed: POST → 403 "You do not have permission to sell."', $http( 'nosell', 'POST', $url( $ca ), array_merge( $fa['fields'], array( 'quantity' => '1' ) ) ), 403, 'You do not have permission to sell.' );
	$chk( 'missing nonce → 403 "This form is no longer valid…"', $post_form( 'seller', $ca, $fa, '1', array( '_pqbg_nonce' => null ) ), 403, 'This form is no longer valid. Check the item and confirm again.' );
	$chk( 'invalid nonce → 403', $post_form( 'seller', $ca, $fa, '1', array( '_pqbg_nonce' => 'abc123abc1' ) ), 403, 'This form is no longer valid. Check the item and confirm again.' );
	$pother = $make_simple( $A );
	$other  = $get_form( 'seller', $code_of( $pother ) );
	$chk( 'another item\'s nonce → 403', $post_form( 'seller', $ca, $fa, '1', array( '_pqbg_nonce' => $other['fields']['_pqbg_nonce'] ) ), 403 );
	$chk( 'another user\'s form (seller2 submits the seller\'s form) → 403 (nonce is per session)', $post_form( 'seller2', $ca, $fa, '1' ), 403 );
	$chk( 'tampered seen_price (signature mismatch) → 400 "This form is not valid…"', $post_form( 'seller', $ca, $fa, '1', array( 'seen_price' => '1.00' ) ), 400, 'This form is not valid. Check the item and confirm again.' );
	$chk( 'tampered request_id → 400', $post_form( 'seller', $ca, $fa, '1', array( 'request_id' => wp_generate_uuid4() ) ), 400 );
	$chk( 'missing signature → 400', $post_form( 'seller', $ca, $fa, '1', array( 'sig' => null ) ), 400 );
	$chk( 'another item\'s token with this item\'s nonce → 400', $post_form( 'seller', $ca, $fa, '1', array_diff_key( $other['fields'], array( '_pqbg_nonce' => 1 ) ) ), 400 );
	$row_a = CodeRepository::find_by_code( $ca );
	$old_t = SaleRequest::issue_token( $SE, (int) $row_a['id'], '1499.00', 5, time() - SaleRequest::FORM_TTL - 60 );
	$chk( 'expired form (issued 31 min ago, valid signature) → 400 "This sale form has expired…"', $post_form( 'seller', $ca, $fa, '1', $old_t ), 400, 'This sale form has expired. Check the item and confirm again.' );
	$chk( 'unknown action → 400 "This request could not be understood."', $post_form( 'seller', $ca, $fa, '1', array( 'pqbg_action' => 'refund' ) ), 400, 'This request could not be understood.' );
	$chk( 'POST to a non-canonical URL (lowercase code) → 400, never redirected', $http( 'seller', 'POST', $home . '/scan/' . strtolower( $ca ) . '/', array_merge( $fa['fields'], array( 'quantity' => '1' ) ) ), 400 );
	$chk( 'POST with a query string → 400', $http( 'seller', 'POST', $url( $ca ) . '?x=1', array_merge( $fa['fields'], array( 'quantity' => '1' ) ) ), 400 );
	$r405 = $http( 'seller', 'POST', $url(), array( 'code' => $ca ) );
	pqbg_t( 'POST to the entry page → 405 Allow: GET, HEAD', 405 === $r405['code'] && 'GET, HEAD' === ( $r405['headers']['allow'] ?? '' ) && $headers_ok( $r405 ) );
	$rput = $http( 'seller', 'PUT', $url( $ca ) );
	pqbg_t( 'PUT to a code URL → 405 Allow: GET, HEAD, POST', 405 === $rput['code'] && 'GET, HEAD, POST' === ( $rput['headers']['allow'] ?? '' ) );
	$rok = $post_form( 'seller', $ca, $fa, '1' );
	pqbg_t( 'after all that, the original form still sells exactly once', 303 === $rok['code'] && 1 === count( $rows_for( $pa ) ) && 4 === $stock( $pa ) );
	$sale_a = $sale_id_of( $rok );
	$exp = SaleRequest::issue_token( $SE, (int) $row_a['id'], '1499.00', 5, time() - SaleRequest::FORM_TTL - 60 );
	$exp['request_id'] = $fa['fields']['request_id'];
	$exp['sig']        = ( static function () use ( $exp, $SE, $row_a ) {
		$m = new ReflectionMethod( SaleRequest::class, 'sign' );
		return $m->invoke( null, array_diff_key( $exp, array( 'sig' => 1 ) ), $SE, (int) $row_a['id'] );
	} )();
	$rexp = $post_form( 'seller', $ca, $fa, '1', $exp );
	pqbg_t( 'the same request_id re-sent after its form expired → still 303 to the original sale (outcome before expiry)', 303 === $rexp['code'] && $sale_a === $sale_id_of( $rexp ) && 1 === count( $rows_for( $pa ) ) );

	pqbg_section( 'the sale result page (?sale=)' );
	$sale_url = SaleRequest::sale_url( $ca, $sale_a );
	$r2       = $http( 'seller2', 'GET', $sale_url );
	pqbg_t( 'another seller → 303 (not 301) to the plain product URL', 303 === $r2['code'] && $url( $ca ) === $r2['location'] && $headers_ok( $r2 ) );
	pqbg_t( 'a nonexistent sale → 303; a sale of another code → 303', 303 === $http( 'seller', 'GET', SaleRequest::sale_url( $ca, 999999 ) )['code'] && 303 === $http( 'seller', 'GET', SaleRequest::sale_url( $code_of( $pother ), $sale_a ) )['code'] );
	pqbg_t( 'shop manager and administrator (view all sales) → 200, without an Undo button', 200 === ( $rsm = $http( 'sm', 'GET', $sale_url ) )['code'] && null === $undo_form_of( $rsm['body'] ) && 200 === $http( 'admin', 'GET', $sale_url )['code'] );
	pqbg_t( 'viewer (no sales capability) → 303; customer → the fixed 403', 303 === $http( 'viewer', 'GET', $sale_url )['code'] && 403 === $http( 'customer', 'GET', $sale_url )['code'] );
	pqbg_t( 'other query strings still get the Phase 6 301 (?sale=abc, ?sale=1&x=1)', 301 === $http( 'seller', 'GET', $url( $ca ) . '?sale=abc' )['code'] && 301 === $http( 'seller', 'GET', $sale_url . '&x=1' )['code'] );

	pqbg_section( 'undo over HTTP' );
	$pun = $make_simple( $A, array( 'stock_quantity' => 5 ) );
	$cun = $code_of( $pun );
	$rs  = $post_form( 'seller', $cun, $get_form( 'seller', $cun ), '2' );
	$sun = $sale_id_of( $rs );
	$page = $http( 'seller', 'GET', $rs['location'] );
	$uf  = $undo_form_of( $page['body'] );
	pqbg_t( 'sold 2 (5 → 3); the success page has the Undo form', 303 === $rs['code'] && 3 === $stock( $pun ) && is_array( $uf ) );
	$get_undo = $http( 'seller', 'GET', $url( $cun ) . '?' . http_build_query( $uf['fields'] ) );
	$get_undo2 = $http( 'seller', 'GET', $rs['location'] . '&pqbg_action=undo' );
	pqbg_t( 'GET cannot undo (the form fields as a query string → 301; ?sale=…&pqbg_action=undo → 301), nothing changed', 301 === $get_undo['code'] && 301 === $get_undo2['code'] && 3 === $stock( $pun ) && 'completed' === SaleRepository::find( $sun )['status'] );
	pqbg_t( 'another seller cannot undo it (their session has no valid nonce) → 403, nothing changed', 403 === $http( 'seller2', 'POST', $uf['action'], $uf['fields'] )['code'] && 3 === $stock( $pun ) );
	$t0 = microtime( true );
	$ru = $http( 'seller', 'POST', $uf['action'], $uf['fields'] );
	$vrow = SaleRepository::find( $sun );
	pqbg_t( 'undo → 303 back to the sale page; stock restored 3 → 5', 303 === $ru['code'] && $rs['location'] === $ru['location'] && 5 === $stock( $pun ) && $headers_ok( $ru ) );
	pqbg_t( 'the row is voided, not deleted (voided_by = seller, voided_at now, void_reason "undo")', 'voided' === $vrow['status'] && $SE === (int) $vrow['voided_by'] && abs( strtotime( $vrow['voided_at_gmt'] . ' UTC' ) - time() ) < 30 && 'undo' === $vrow['void_reason'] && 1 === count( $rows_for( $pun ) ) );
	$pv = $http( 'seller', 'GET', $rs['location'] );
	pqbg_t( 'the sale page now says it was undone, with no Undo button and no stock line', 200 === $pv['code'] && str_starts_with( (string) ( $notices_in( $pv['body'] )[0] ?? '' ), 'This sale was undone at ' ) && null === $undo_form_of( $pv['body'] ) && ! str_contains( $pv['body'], 'Stock now' ) );
	$ru2 = $http( 'seller', 'POST', $uf['action'], $uf['fields'] );
	pqbg_t( 'undo again (same form) → 409 "This sale was already undone.", stock unchanged', 409 === $ru2['code'] && $has_notice( $ru2, 'This sale was already undone.' ) && 5 === $stock( $pun ) );
	$rs  = $post_form( 'seller', $cun, $get_form( 'seller', $cun ), '1' );
	$sun2 = $sale_id_of( $rs );
	$wpdb->update( $S, array( 'created_at_gmt' => gmdate( 'Y-m-d H:i:s', time() - 601 ) ), array( 'id' => $sun2 ) );
	$page = $http( 'seller', 'GET', $rs['location'] );
	pqbg_t( 'after 10 minutes the Undo button is gone', 200 === $page['code'] && null === $undo_form_of( $page['body'] ) );
	$wpdb->update( $S, array( 'created_at_gmt' => gmdate( 'Y-m-d H:i:s', time() - 300 ) ), array( 'id' => $sun2 ) );
	$uf2 = $undo_form_of( $http( 'seller', 'GET', $rs['location'] )['body'] );
	$wpdb->update( $S, array( 'created_at_gmt' => gmdate( 'Y-m-d H:i:s', time() - 601 ) ), array( 'id' => $sun2 ) );
	$rlate = $http( 'seller', 'POST', $uf2['action'], $uf2['fields'] );
	pqbg_t( '...and an Undo form kept from before is refused server-side → 409 "Undo is no longer available (10-minute limit).", nothing changed', 409 === $rlate['code'] && $has_notice( $rlate, 'Undo is no longer available (10-minute limit).' ) && 4 === $stock( $pun ) && 'completed' === SaleRepository::find( $sun2 )['status'] );

	pqbg_section( 'undo and void in-process: window, ownership, lock, concurrency' );
	$pw_ = $make_simple( $A, array( 'stock_quantity' => 10 ) );
	$cw  = $code_of( $pw_ );
	$s1  = $sell_in( $cw, 1, $SE );
	$wpdb->update( $S, array( 'created_at_gmt' => gmdate( 'Y-m-d H:i:s', time() - 599 ) ), array( 'id' => $s1['sale']['id'] ) );
	$u1  = SaleService::undo( (int) $s1['sale']['id'], $SE );
	pqbg_t( 'undo at 9:59 → allowed', is_array( $u1 ) && 'voided' === $u1['sale']['status'] && 10 === $stock( $pw_ ) );
	$s2  = $sell_in( $cw, 1, $SE );
	$wpdb->update( $S, array( 'created_at_gmt' => gmdate( 'Y-m-d H:i:s', time() - 601 ) ), array( 'id' => $s2['sale']['id'] ) );
	$u2  = SaleService::undo( (int) $s2['sale']['id'], $SE );
	pqbg_t( 'undo at 10:01 → pqbg_undo_expired, nothing changed', is_wp_error( $u2 ) && 'pqbg_undo_expired' === $u2->get_error_code() && 9 === $stock( $pw_ ) && 'completed' === SaleRepository::find( (int) $s2['sale']['id'] )['status'] );
	$s3  = $sell_in( $cw, 1, $SE );
	$u3a = SaleService::undo( (int) $s3['sale']['id'], $S2 );
	$u3b = SaleService::undo( (int) $s3['sale']['id'], $SM );
	pqbg_t( 'undo is own-sale only: another seller and a shop manager get pqbg_not_own_sale', is_wp_error( $u3a ) && 'pqbg_not_own_sale' === $u3a->get_error_code() && is_wp_error( $u3b ) && 'pqbg_not_own_sale' === $u3b->get_error_code() && 8 === $stock( $pw_ ) );
	$u3c = SaleService::undo( (int) $s3['sale']['id'], $user_ids['nosell'] );
	pqbg_t( 'a user without pqbg_sell cannot undo (pqbg_forbidden)', is_wp_error( $u3c ) && 'pqbg_forbidden' === $u3c->get_error_code() );
	$h   = $hold_lock( $pw_, 7 );
	$u3d = SaleService::undo( (int) $s3['sale']['id'], $SE );
	$end_hold( $h );
	pqbg_t( 'undo uses the stock lock: with it held elsewhere → pqbg_busy, nothing changed', $h[2] && is_wp_error( $u3d ) && 'pqbg_busy' === $u3d->get_error_code() && 8 === $stock( $pw_ ) && 'completed' === SaleRepository::find( (int) $s3['sale']['id'] )['status'] );
	$res = $run_workers( array_fill( 0, 4, array( 'undo', (int) $s3['sale']['id'], $SE ) ) );
	$won = array_filter( $res, static fn( $x ) => 'voided' === ( $x['status'] ?? '' ) );
	pqbg_t( '4 concurrent undos of one sale → exactly one succeeds, the others pqbg_already_undone', 1 === count( $won ) && 3 === count( array_filter( $res, static fn( $x ) => 'pqbg_already_undone' === ( $x['error'] ?? '' ) ) ), wp_json_encode( $res ) );
	pqbg_t( '...stock restored exactly once (8 → 9)', 9 === $stock( $pw_ ) );
	pqbg_t( 'void_sale: a seller may not (pqbg_forbidden)', 'pqbg_forbidden' === SaleService::void_sale( (int) $s2['sale']['id'], $SE, 'x' )->get_error_code() );
	$v1  = SaleService::void_sale( (int) $s2['sale']['id'], $SM, 'Customer returned it' );
	$v1r = SaleRepository::find( (int) $s2['sale']['id'] );
	pqbg_t( 'void_sale: a shop manager voids another seller\'s old sale with restock (9 → 10)', is_array( $v1 ) && 'voided' === $v1r['status'] && $SM === (int) $v1r['voided_by'] && 'Customer returned it' === $v1r['void_reason'] && 10 === $stock( $pw_ ) );
	pqbg_t( 'void_sale: a voided sale cannot be voided again', 'pqbg_not_voidable' === SaleService::void_sale( (int) $s2['sale']['id'], $SM, 'again' )->get_error_code() && 10 === $stock( $pw_ ) );
	$s4  = $sell_in( $cw, 1, $SE );
	$v2  = SaleService::void_sale( (int) $s4['sale']['id'], $A, 'Paperwork only', false );
	pqbg_t( 'void_sale without restock: voided, stock unchanged (9)', is_array( $v2 ) && 'voided' === SaleRepository::find( (int) $s4['sale']['id'] )['status'] && 9 === $stock( $pw_ ) );
	pqbg_t( 'a sale that does not exist cannot be voided', 'pqbg_not_voidable' === SaleService::void_sale( 0, $SM, 'x' )->get_error_code() );

	pqbg_section( 'idempotency: concurrent submissions of one request' );
	$pi  = $make_simple( $A, array( 'stock_quantity' => 5 ) );
	$rid = wp_generate_uuid4();
	$res = $run_workers( array_fill( 0, 4, array( 'sell', $code_of( $pi ), 1, $rid, $SE ) ) );
	$ids = array_unique( array_column( $res, 'sale' ) );
	pqbg_t( 'the same request_id from 4 processes at once → all get the same completed sale', 4 === count( array_filter( $res, static fn( $x ) => 'completed' === ( $x['status'] ?? '' ) ) ) && 1 === count( $ids ), wp_json_encode( $res ) );
	pqbg_t( '...one row, one decrement (5 → 4)', 1 === count( $rows_for( $pi ) ) && 4 === $stock( $pi ) );
	$reuse = $sell_in( $code_of( $pi ), 1, $S2, array( 'request_id' => $rid ) );
	pqbg_t( 'another seller replaying that request_id → pqbg_bad_request, nothing changed', is_wp_error( $reuse ) && 'pqbg_bad_request' === $reuse->get_error_code() && 4 === $stock( $pi ) );

	pqbg_section( 'concurrency: N workers selling the last K items' );
	$pk  = $make_simple( $A, array( 'stock_quantity' => 3 ) );
	$res = $run_workers( array_map( static fn( $i ) => array( 'sell', $code_of( $pk ), 1, wp_generate_uuid4(), 0 === $i % 2 ? $SE : $S2 ), range( 0, 7 ) ) );
	$ok  = array_filter( $res, static fn( $x ) => 'completed' === ( $x['status'] ?? '' ) );
	pqbg_t( '8 workers, 3 in stock → exactly 3 completed sales, 5 × out of stock', 3 === count( $ok ) && 5 === count( array_filter( $res, static fn( $x ) => in_array( $x['error'] ?? '', array( 'pqbg_out_of_stock', 'pqbg_insufficient_stock' ), true ) ) ), wp_json_encode( $res ) );
	$kr = $rows_for( $pk );
	pqbg_t( '...stock ends at 0, never negative; 3 rows with stock_after 2, 1, 0', 0 === $stock( $pk ) && 3 === count( $kr ) && array( 2, 1, 0 ) === array_map( static fn( $x ) => (int) $x['stock_after'], $kr ) && array( 3, 2, 1 ) === array_map( static fn( $x ) => (int) $x['stock_before'], $kr ) );
	list( $kp, $kv ) = $make_variable( $A, 2, 3 );
	$res = $run_workers( array_map( static fn( $i ) => array( 'sell', $code_of( $kv[ $i % 2 ] ), 1, wp_generate_uuid4(), $SE ), range( 0, 7 ) ) );
	pqbg_t( 'parent-level stock: 8 workers across two sibling variations, 3 in the parent → exactly 3 sales, parent 0', 3 === count( array_filter( $res, static fn( $x ) => 'completed' === ( $x['status'] ?? '' ) ) ) && 0 === $stock( $kp ) && 3 === count( $rows_for( $kp ) ), wp_json_encode( $res ) );
	pqbg_t( 'no pending rows and every lock free after the workers', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $S WHERE id > %d AND status = 'pending'", $start_s ) ) && StockLock::is_free( $pk ) && StockLock::is_free( $kp ) );

	pqbg_section( 'the online-checkout race (a real WooCommerce order from another process)' );
	$po   = $make_simple( $A, array( 'stock_quantity' => 2 ) );
	$co   = $code_of( $po );
	$ord0 = $orders_n();
	$race = static function () use ( $po, $online_order ) {
		static $done = false;
		if ( ! $done ) {
			$done = true;
			$online_order( $po, 1 );
		}
	};
	add_action( 'woocommerce_product_before_set_stock', $race );
	$set_hooks = 0;
	$count_set = static function () use ( &$set_hooks ) {
		++$set_hooks;
	};
	add_action( 'woocommerce_product_set_stock', $count_set );
	$ro = $sell_in( $co, 2, $SE );
	remove_action( 'woocommerce_product_before_set_stock', $race );
	remove_action( 'woocommerce_product_set_stock', $count_set );
	$orow = $rows_for( $po )[0] ?? array();
	pqbg_t( 'an online order took 1 between our read (2) and our decrement of 2 → compensated', is_array( $ro ) && 'failed' === $ro['status'] && 1 === count( $orders ) && $ord0 + 1 === $orders_n(), wp_json_encode( is_array( $ro ) ? $ro['status'] : $ro->get_error_code() ) );
	pqbg_t( '...final stock 1 = start 2 − online 1 (our decrement fully restored)', 1 === $stock( $po ) && 'instock' === $stock_status( $po ) );
	pqbg_t( '...the row is failed / sold_online (no completed sale), stock_after never written', 'failed' === ( $orow['status'] ?? '' ) && 'sold_online' === $orow['failure_code'] && null === $orow['stock_after'] );
	pqbg_t( '...WooCommerce ran both stock changes normally (set_stock fired for the decrement and the compensation)', 2 <= $set_hooks );
	wp_set_current_user( $SE );
	$po2  = $make_simple( $A, array( 'stock_quantity' => 2 ) );
	wp_set_current_user( $SE );
	$crow = CodeRepository::find_by_code( $code_of( $po2 ) );
	$race2 = static function () use ( $po2, $online_order ) {
		static $done = false;
		if ( ! $done ) {
			$done = true;
			$online_order( $po2, 1 );
		}
	};
	add_action( 'woocommerce_product_before_set_stock', $race2 );
	$hr = SaleRequest::handle( $code_of( $po2 ), array_merge( array( 'pqbg_action' => 'sell', '_pqbg_nonce' => wp_create_nonce( Permissions::nonce_action( 'sell_' . $crow['id'] ) ), 'quantity' => '2' ), SaleRequest::issue_token( $SE, (int) $crow['id'], '1499.00', 2 ) ) );
	remove_action( 'woocommerce_product_before_set_stock', $race2 );
	wp_set_current_user( 0 );
	pqbg_t( 'the seller sees 409 "This item just sold online. Stock was not changed."', 409 === $hr['status'] && 'This item just sold online. Stock was not changed.' === $hr['view']['notices'][0][1] && 1 === $stock( $po2 ) );
	pqbg_t( 'the online orders are the only WooCommerce orders created by this suite', $ord0 + 2 === $orders_n() && 2 === count( $orders ) );

	pqbg_section( 'failure injection' );
	$pz  = $make_simple( $A, array( 'stock_quantity' => 5 ) );
	$cz  = $code_of( $pz );
	$inject = static function ( string $hook, callable $sell ) {
		$thrower = static function () {
			throw new RuntimeException( 'injected' );
		};
		add_action( $hook, $thrower );
		try {
			return $sell();
		} finally {
			remove_action( $hook, $thrower );
		}
	};
	$z1 = $inject( 'woocommerce_product_before_set_stock', static fn() => $sell_in( $cz, 1, $SE ) );
	$zr = $rows_for( $pz );
	pqbg_t( 'exception BEFORE the stock UPDATE → failed/error, stock untouched (5)', is_array( $z1 ) && 'failed' === $z1['status'] && 'error' === end( $zr )['failure_code'] && 5 === $stock( $pz ) );
	pqbg_t( '...filter removed, lock released', false === $stock_filters() && StockLock::is_free( $pz ) );
	$z2 = $inject( 'woocommerce_updated_product_stock', static fn() => $sell_in( $cz, 1, $SE ) );
	$zr = $rows_for( $pz );
	pqbg_t( 'exception right AFTER the stock UPDATE → compensated, failed/error, stock restored (5)', is_array( $z2 ) && 'failed' === $z2['status'] && 'error' === end( $zr )['failure_code'] && 5 === $stock( $pz ) );
	$z3 = $inject( 'woocommerce_product_set_stock', static fn() => $sell_in( $cz, 2, $SE ) );
	$zr = $rows_for( $pz );
	pqbg_t( 'exception after the product save (third-party set_stock hook) → compensated, stock restored (5)', is_array( $z3 ) && 'failed' === $z3['status'] && 'error' === end( $zr )['failure_code'] && 5 === $stock( $pz ) && 'instock' === $stock_status( $pz ) );
	pqbg_t( '...filter removed and lock released after every exception', false === $stock_filters() && StockLock::is_free( $pz ) );
	pqbg_t( '...3 failed rows, no completed ones', 3 === count( $zr ) && array( 'failed' ) === array_values( array_unique( array_column( $zr, 'status' ) ) ) );
	$broken = static function ( $q ) {
		return str_contains( $q, 'SET stock_before' ) ? 'SELECT broken FROM nowhere' : $q;
	};
	add_filter( 'query', $broken );
	$quiet = $wpdb->suppress_errors( true );
	$z4    = $sell_in( $cz, 1, $SE );
	$wpdb->suppress_errors( $quiet );
	remove_filter( 'query', $broken );
	$failed_row = $rows_for( $pz )[0];
	pqbg_t( 'a failed sale cannot be undone or voided', 'pqbg_not_undoable' === SaleService::undo( (int) $failed_row['id'], $SE )->get_error_code() && 'pqbg_not_voidable' === SaleService::void_sale( (int) $failed_row['id'], $SM, 'x' )->get_error_code() );
	pqbg_t( 'a broken snapshot UPDATE after completion does not undo the sale (completed, stock 4; snapshots are informational)', is_array( $z4 ) && 'completed' === $z4['status'] && 4 === $stock( $pz ) );
	$wpdb->query( 'START TRANSACTION' );
	$z5 = $sell_in( $cz, 1, $SE );
	$wpdb->query( 'ROLLBACK' );
	pqbg_t( 'inside a caller\'s open transaction → refused (pqbg_in_transaction), nothing written', is_wp_error( $z5 ) && 'pqbg_in_transaction' === $z5->get_error_code() && 4 === $stock( $pz ) && 4 === count( $rows_for( $pz ) ) );
	$stale = SaleRepository::insert_pending( array( 'request_id' => ( $stale_rid = wp_generate_uuid4() ), 'code_id' => 0, 'product_id' => $pz, 'variation_id' => 0, 'seller_id' => $SE, 'quantity' => 1, 'unit_price' => '1499', 'line_total' => '1499', 'currency' => 'INR', 'product_name' => 'stale', 'source' => 'scan', 'stock_holder_id' => $pz ) );
	$z6 = $sell_in( $cz, 1, $SE );
	pqbg_t( 'a stale pending row (a crashed process) is recovered as failed/interrupted by the next sale; stock untouched by it', is_int( $stale ) && 'failed' === SaleRepository::find( $stale )['status'] && 'interrupted' === SaleRepository::find( $stale )['failure_code'] && is_array( $z6 ) && 'completed' === $z6['status'] && 3 === $stock( $pz ) );
	$z7 = $sell_in( $cz, 1, $SE, array( 'request_id' => $stale_rid ) );
	pqbg_t( '...and its request_id replayed → the failed outcome, nothing sold', is_array( $z7 ) && 'failed' === $z7['status'] && 3 === $stock( $pz ) );

	pqbg_section( 'SQL override fallback (another plugin replaces the stock SQL)' );
	$px = $make_simple( $A, array( 'stock_quantity' => 5 ) );
	$cx = $code_of( $px );
	$plain = static function ( $q ) use ( $px, $wpdb ) {
		if ( str_contains( $q, 'pm.meta_value = pm.meta_value' ) && str_contains( $q, "pm.post_id = $px" ) ) {
			preg_match( '/pm\.meta_value = pm\.meta_value ([+-][0-9.]+)/', $q, $m );
			return "UPDATE {$wpdb->postmeta} SET meta_value = meta_value {$m[1]} WHERE post_id = $px AND meta_key='_stock'";
		}
		return $q;
	};
	$log = $logged(
		static function () use ( $plain, &$x1, $sell_in, $cx, $SE ) {
			add_filter( 'query', $plain );
			$x1 = $sell_in( $cx, 2, $SE );
			remove_filter( 'query', $plain );
		}
	);
	$xr = $rows_for( $px );
	pqbg_t( 'fallback, decrement happened: row still pending after WooCommerce → fresh _stock shows the drop → completed (conditional UPDATE)', is_array( $x1 ) && 'completed' === $x1['status'] && 'completed' === end( $xr )['status'] && 3 === $stock( $px ) );
	pqbg_t( '...a warning is logged with error codes only', array( 'warning:Sale: pqbg_stock_marker_missing (applied)' ) === $log, wp_json_encode( $log ) );
	$noop = static function ( $q ) use ( $px ) {
		return str_contains( $q, 'pm.meta_value = pm.meta_value' ) && str_contains( $q, "pm.post_id = $px" ) ? 'SELECT 1' : $q;
	};
	$log = $logged(
		static function () use ( $noop, &$x2, $sell_in, $cx, $SE ) {
			add_filter( 'query', $noop );
			$x2 = $sell_in( $cx, 1, $SE );
			remove_filter( 'query', $noop );
		}
	);
	$xr = $rows_for( $px );
	pqbg_t( 'fallback, decrement did NOT happen: row still pending, stock unchanged → failed/error', is_array( $x2 ) && 'failed' === $x2['status'] && 'error' === end( $xr )['failure_code'] && 3 === $stock( $px ) );
	pqbg_t( '...warnings logged with error codes only', in_array( 'warning:Sale: pqbg_stock_marker_missing (not_applied)', $log, true ) && array() === array_filter( $log, static fn( $l ) => ! preg_match( '/^warning:Sale: pqbg_[a-z_]+( \([A-Za-z_]+\))?$/', $l ) ), wp_json_encode( $log ) );
	$shape = static fn( $sql ) => $sql . ' LIMIT 1';
	add_filter( SaleService::STOCK_QUERY_FILTER, $shape, 5 );
	$log = $logged(
		static function () use ( &$x3, $sell_in, $cx, $SE ) {
			$x3 = $sell_in( $cx, 1, $SE );
		}
	);
	remove_filter( SaleService::STOCK_QUERY_FILTER, $shape, 5 );
	pqbg_t( 'SQL of an unexpected shape is left alone (never replaced) and the fallback completes the sale', is_array( $x3 ) && 'completed' === $x3['status'] && 2 === $stock( $px ) && in_array( 'warning:Sale: pqbg_stock_sql_unexpected (applied)', $log, true ), wp_json_encode( $log ) );
	$log = $logged(
		static function () use ( &$x4, $sell_in, $cx, $SE ) {
			$x4 = $sell_in( $cx, 1, $SE );
		}
	);
	pqbg_t( 'a normal sale uses the atomic statement: completed, no fallback warning', is_array( $x4 ) && 'completed' === $x4['status'] && array() === $log && 1 === $stock( $px ) );
	pqbg_t( 'the stock query filter is gone after success, failure and exceptions', false === $stock_filters() );

	pqbg_section( 'WooCommerce integration' );
	$pw2 = $make_simple( $A, array( 'stock_quantity' => 2 ) );
	$fired = array();
	foreach ( array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_low_stock', 'woocommerce_no_stock', 'woocommerce_product_set_stock_status' ) as $hk ) {
		$cbs[ $hk ] = static function () use ( &$fired, $hk ) {
			$fired[] = $hk;
		};
		add_action( $hk, $cbs[ $hk ] );
	}
	update_post_meta( $pw2, '_low_stock_amount', '1' );
	$sync();
	$ord = $orders_n();
	$w1  = $sell_in( $code_of( $pw2 ), 1, $SE );
	$f1  = $fired;
	$fired = array();
	$w2  = $sell_in( $code_of( $pw2 ), 1, $SE );
	$f2  = $fired;
	$st2 = $stock_status( $pw2 ) . '/' . $lookup_stock( $pw2 )['stock_status'];
	$fired = array();
	$w3  = SaleService::undo( (int) $w2['sale']['id'], $SE );
	$f3  = $fired;
	pqbg_t( 'sale 2 → 1: woocommerce_product_set_stock fired and a low-stock notification (D4), once', 1 === count( array_keys( $f1, 'woocommerce_product_set_stock', true ) ) && 1 === count( array_keys( $f1, 'woocommerce_low_stock', true ) ) && ! in_array( 'woocommerce_no_stock', $f1, true ), implode( ',', $f1 ) );
	pqbg_t( 'sale 1 → 0: status changed to outofstock and a no-stock notification, once', 1 === count( array_keys( $f2, 'woocommerce_no_stock', true ) ) && in_array( 'woocommerce_product_set_stock_status', $f2, true ) && 'outofstock/outofstock' === $st2, implode( ',', $f2 ) . ' / ' . $st2 );
	pqbg_t( 'undo 0 → 1: stock hooks fire, back to instock, and NO low/no-stock notification', in_array( 'woocommerce_product_set_stock', $f3, true ) && ! in_array( 'woocommerce_no_stock', $f3, true ) && ! in_array( 'woocommerce_low_stock', $f3, true ) && 'instock' === $stock_status( $pw2 ) && 1 === (int) $lookup_stock( $pw2 )['stock_quantity'], implode( ',', $f3 ) );
	list( $wvp, $wvv ) = $make_variable( $A, 1, null, 3 );
	$fired = array();
	$sell_in( $code_of( $wvv[0] ), 1, $SE );
	pqbg_t( 'an own-stock variation fires woocommerce_variation_set_stock', in_array( 'woocommerce_variation_set_stock', $fired, true ) && ! in_array( 'woocommerce_product_set_stock', $fired, true ), implode( ',', $fired ) );
	foreach ( array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_low_stock', 'woocommerce_no_stock', 'woocommerce_product_set_stock_status' ) as $hk ) {
		remove_action( $hk, $cbs[ $hk ] );
	}
	pqbg_t( 'no WooCommerce order is created by sales or undo (only the 2 simulated online orders exist)', $ord === $orders_n() && 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id > %d", $start_ord ) ) );
	pqbg_t( 'total_sales (a WooCommerce report counter) is not touched by our sales', 0 === (int) get_post_meta( $p1, 'total_sales', true ) );

	pqbg_section( 'escaping' );
	$evil = '<script>alert(1)</script> "Sari" & <b>bold</b>';
	$pe2  = $make_simple( $A, array( 'name' => $evil, 'stock_quantity' => 3, 'sku' => 'PQBG-P7-<i>x</i>' ) );
	$re   = $http( 'seller', 'GET', $url( $code_of( $pe2 ) ) );
	$rse  = $post_form( 'seller', $code_of( $pe2 ), $form_of( $re['body'] ) );
	$rsp  = $http( 'seller', 'GET', $rse['location'] );
	pqbg_t( 'product screen with the form: no script, the name escaped', ! str_contains( $re['body'], '<script>alert' ) && str_contains( $re['body'], esc_html( $evil ) ) && null !== $form_of( $re['body'] ) );
	pqbg_t( 'sale page: the snapshot name and SKU escaped, no script, no raw <b>/<i>', 303 === $rse['code'] && ! str_contains( $rsp['body'], '<script>alert' ) && ! str_contains( $rsp['body'], '<b>bold' ) && ! str_contains( $rsp['body'], '<i>x' ) && str_contains( $rsp['body'], esc_html( $evil ) ) );
	$update( $pe2, array( 'name' => 'Renamed later' ) );
	pqbg_t( 'history stays correct: after a rename, the sale page still shows the name at the time of sale', str_contains( $http( 'seller', 'GET', $rse['location'] )['body'], esc_html( $evil ) ) );
	$all = array( $re, $rsp, $rb, $rch, $ru ?? $rbz, $rbz, $rq, $rok );
	pqbg_t( 'no <script> on any Phase 7 screen', array() === array_filter( $all, static fn( $r ) => str_contains( (string) $r['body'], '<script' ) ) );
	pqbg_t( 'security headers on every Phase 7 response type (303, 400, 403, 409, 503, sale page)', $headers_ok( $rse ) && $headers_ok( $rq ) && $headers_ok( $rb ) && $headers_ok( $rbz ) && $headers_ok( $rsp ) && $headers_ok( $rlate ) );

	pqbg_section( 'GET never changes state' );
	$pgt = $make_simple( $A, array( 'stock_quantity' => 3 ) );
	$fg  = $get_form( 'seller', $code_of( $pgt ) );
	$snap = $sales_sum() . '|' . $stock( $pgt );
	$http( 'seller', 'GET', $url( $code_of( $pgt ) ) . '?' . http_build_query( array_merge( $fg['fields'], array( 'quantity' => '1' ) ) ) );
	$http( 'seller', 'HEAD', $url( $code_of( $pgt ) ) );
	$http( 'seller', 'GET', $url( $code_of( $pgt ) ) );
	$http( 'seller', 'GET', $url() . '?code=' . $code_of( $pgt ) );
	$http( 'seller', 'GET', $rse['location'] );
	pqbg_t( 'GET/HEAD of the product page, the form as a query string, the entry box and a sale page: no sale, no stock change', $snap === $sales_sum() . '|' . $stock( $pgt ) );

	pqbg_section( 'scope' );
	$src_of  = static fn( array $files ) => implode( "\n", array_map( static fn( $f ) => implode( '', array_map( static fn( $t ) => is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t, token_get_all( (string) file_get_contents( $f ) ) ) ), $files ) );
	$all_src = $src_of( glob( PQBG_PLUGIN_DIR . 'includes/*.php' ) );
	pqbg_t( 'no transactions in the sale code (no START TRANSACTION, COMMIT or ROLLBACK)', ! preg_match( '/START TRANSACTION|COMMIT|ROLLBACK|wc_transaction_query/i', $src_of( array( PQBG_PLUGIN_DIR . 'includes/SaleService.php', PQBG_PLUGIN_DIR . 'includes/SaleRepository.php', PQBG_PLUGIN_DIR . 'includes/SaleRequest.php', PQBG_PLUGIN_DIR . 'includes/StockLock.php' ) ) ) );
	pqbg_t( 'no WooCommerce order creation, REST routes, AJAX or nopriv handlers anywhere', ! preg_match( '/wc_create_order|new WC_Order|register_rest_route|wp_ajax_|admin_post_nopriv/', $all_src ) );
	pqbg_t( 'stock is changed only through wc_update_product_stock, only in SaleService', 1 === count( array_filter( glob( PQBG_PLUGIN_DIR . 'includes/*.php' ), static fn( $f ) => str_contains( $src_of( array( $f ) ), 'wc_update_product_stock' ) ) ) && str_contains( $src_of( array( PQBG_PLUGIN_DIR . 'includes/SaleService.php' ) ), 'wc_update_product_stock' ) && ! preg_match( '/set_stock_quantity|update_post_meta/', $all_src ) );
	pqbg_t( 'sales rows are written only by SaleRepository (no DELETE of sales anywhere; Install only migrates the table)', ! preg_match( '/DELETE\s+FROM\s+\{?\$table/i', $src_of( array( PQBG_PLUGIN_DIR . 'includes/SaleRepository.php' ) ) ) && ! str_contains( $src_of( array_diff( glob( PQBG_PLUGIN_DIR . 'includes/*.php' ), array( PQBG_PLUGIN_DIR . 'includes/SaleRepository.php', PQBG_PLUGIN_DIR . 'includes/Schema.php', PQBG_PLUGIN_DIR . 'includes/Install.php' ) ) ), 'sales_table()' ) );
	pqbg_t( 'the template still has no JavaScript', ! str_contains( (string) file_get_contents( PQBG_PLUGIN_DIR . 'templates/pqbg-scan.php' ), '<script' ) );
	pqbg_t( 'direct HTTP to the new PHP files: empty output', array() === array_filter( array( 'includes/SaleService.php', 'includes/SaleRepository.php', 'includes/SaleRequest.php', 'includes/StockLock.php' ), static fn( $f ) => '' !== $http( 'anon', 'GET', PQBG_PLUGIN_URL . $f )['body'] ) );

	pqbg_section( 'timings' );
	$pt2 = $make_simple( $A, array( 'stock_quantity' => 50 ) );
	$ct2 = $code_of( $pt2 );
	$ts  = array();
	$tu  = array();
	for ( $i = 0; $i < 5; $i++ ) {
		$r   = $post_form( 'seller', $ct2, $get_form( 'seller', $ct2 ) );
		$ts[] = $r['time'];
		$u   = $undo_form_of( $http( 'seller', 'GET', $r['location'] )['body'] );
		$tu[] = $http( 'seller', 'POST', $u['action'], $u['fields'] )['time'];
	}
	$ti = array();
	$tv = array();
	for ( $i = 0; $i < 5; $i++ ) {
		$t0   = microtime( true );
		$si   = $sell_in( $ct2, 1, $SE );
		$ti[] = microtime( true ) - $t0;
		$t0   = microtime( true );
		SaleService::undo( (int) $si['sale']['id'], $SE );
		$tv[] = microtime( true ) - $t0;
	}
	printf( "   sale over HTTP (POST → 303), median of 5: %d ms\n", $median( $ts ) * 1000 );
	printf( "   undo over HTTP (POST → 303), median of 5: %d ms\n", $median( $tu ) * 1000 );
	printf( "   sale in-process (SaleService::sell), median of 5: %.1f ms\n", $median( $ti ) * 1000 );
	printf( "   undo in-process (SaleService::undo), median of 5: %.1f ms\n", $median( $tv ) * 1000 );
	pqbg_t( 'sale and undo each under 3 s over HTTP (sanity bound only)', $median( $ts ) < 3 && $median( $tu ) < 3 );
	pqbg_t( 'stock back to 50 after 10 sales and 10 undos', 50 === $stock( $pt2 ) );
} catch ( Throwable $e ) {
	pqbg_t( 'suite ran without an exception', false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
	pqbg_section( 'cleanup' );
	$wpdb->prefix = $real_prefix ?? $wpdb->prefix;
	foreach ( array( 'pqbg_codes', 'pqbg_sales' ) as $t ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$mig_prefix}{$t}" );
	}
	foreach ( $orders as $oid ) {
		$o = wc_get_order( $oid );
		if ( $o ) {
			$o->delete( true );
		}
	}
	$end_post = $max( $wpdb->posts, 'ID' );
	$ids      = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d ORDER BY post_type = 'product', ID DESC", $start_post ) ) );
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id > %d", $start_post ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_relationships} WHERE object_id > %d", $start_post ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id > %d", $start_post ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wc_product_attributes_lookup WHERE product_id > %d OR product_or_parent_id > %d", $start_post, $start_post ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id > %d", $start_cmt ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->comments} WHERE comment_ID > %d", $start_cmt ) );
	foreach ( $user_ids as $uid ) {
		if ( is_int( $uid ) ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d", $uid ) ) as $pid ) {
				wp_delete_post( (int) $pid, true );
			}
			wp_delete_user( $uid );
		}
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM $S WHERE id > %d", $start_s ) );
	$wpdb->query( "ALTER TABLE $S AUTO_INCREMENT = 1" );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $C WHERE id > %d", $start_c ) );
	$wpdb->query( "ALTER TABLE $C AUTO_INCREMENT = 1" );
	$all_new     = range( $start_post + 1, max( $start_post + 1, $end_post ) );
	// Every Action Scheduler job for an ID allocated during the suite, including deleted products (bootstrap.php).
	$removed_as = pqbg_test_as_cleanup( $as_mark );
	echo '   removed ' . count( $ids ) . ' post(s), ' . count( $orders ) . ' order(s) and ' . $removed_as . " Action Scheduler job(s)\n";
	$sync();
	pqbg_t( 'sales table back to its starting row count (AUTO_INCREMENT reset)', $base_s === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $S" ) );
	pqbg_t( 'codes table back to its starting row count', $base_c === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" ) );
	pqbg_t( 'products and variations back to the starting count', $base_prod === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')" ) );
	pqbg_t( 'no posts, meta, term relationships or comments left above the starting IDs', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID > %d", $start_post ) ) + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id > %d", $start_post ) ) + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id > %d", $start_post ) ) + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_ID > %d", $start_cmt ) ) );
	pqbg_t( 'test orders removed (orders, items, addresses, operational data)', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id > %d", $start_ord ) ) + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_id > %d", $start_oi ) ) + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_addresses WHERE order_id > %d", $start_ord ) ) + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_operational_data WHERE order_id > %d", $start_ord ) ) );
	pqbg_test_as_check( $as_mark );
	pqbg_t( 'options unchanged (settings, DB version, stock options, Coming Soon, rewrite rules)', array() === array_filter( array_keys( $saved ), static fn( $n ) => $saved[ $n ] !== $raw_option( $n ) ) );
	pqbg_t( 'temporary migration tables gone', null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $mig_prefix ) . '%' ) ) );
	pqbg_t( 'temporary users removed', $base_user === (int) count_users()['total_users'] && ! get_user_by( 'login', 'pqbg_p7_seller' ) );
	pqbg_t( 'no stock query filter left behind', false === has_filter( SaleService::STOCK_QUERY_FILTER ) );
}

pqbg_test_done();
