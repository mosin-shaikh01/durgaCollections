<?php
/**
 * Phase 11 suite: hardening.
 *
 *   - Requirements and multisite (D18, D19): every branch with given versions; WooCommerce below the
 *     minimum in a separate process (a stub WooCommerce class and WC_VERSION 8.9.0, WooCommerce itself
 *     filtered out): the plugin does not boot.
 *   - Health check (D1–D5): each check with a planted fixture and the clean control, the rows listed,
 *     the Settings → Health check tab over HTTP for five roles, the Dashboard line for administrators,
 *     GET/HEAD never write, no cost values shown, the invariant checks on a temporary table prefix
 *     without constraints, timings at 5,000 sales / 2,000 items (50,000 with PQBG_STRESS=1).
 *   - The Undo button hides itself when the window ends (D7, the owner's version): a nonce'd style
 *     element sets the remaining seconds, the CSP allows exactly that nonce, no refresh; a late undo
 *     is refused (409, unchanged).
 *   - BulkLog::add() under its lock (D8): 8 processes at the same instant keep all 8 entries; a held
 *     lock delays the write by the timeout and never loses it.
 *   - The performance signal (D9): repeated slow renders or more than 300 sales a day, samples kept.
 *   - CSV formula neutralisation (F1) and its round trip through the importer.
 *   - Migrations from real v1, v2 and v3 tables (the DDL of 5f301be, 2413698 and 5495b05) to v4 with
 *     data on a temporary prefix (D15), without touching the live pqbg_db_version option.
 *   - Uninstall, both branches, executed for real on a cloned temporary prefix (D17): exactly the
 *     documented list is removed, nothing else.
 *   - Time and money (D20): midnight in Asia/Kolkata, a later timezone change, a manual offset, DST
 *     days, paise, very large amounts, plain CSV numbers.
 *   - Malformed input on every handler, page and scan route (F3): never a 500.
 *   - Scope: the sale path and the schema are untouched; the new classes add no hook or handler.
 *
 * Fixture sale rows are inserted directly into pqbg_sales (marked in `note`), as in the Phase 9B
 * and 10B suites. Everything created is removed at the end.
 *
 *   php tests/phase11-hardening.php
 *   php tests/phase11-hardening.php --worker <mode> ...   (internal)
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/bootstrap.php';

// ---------------------------------------------------------------------------------------- workers
if ( isset( $argv[1] ) && '--worker' === $argv[1] ) {
	$mode = $argv[2];

	if ( 'wcold' === $mode ) {
		// WooCommerce below the minimum: WooCommerce itself is filtered out of this process and a stub
		// with an old version stands in for it. Nothing is written.
		$GLOBALS['wp_filter']['option_active_plugins'][10][] = array(
			'function'      => static fn( $p ) => array_values( array_diff( (array) $p, array( 'woocommerce/woocommerce.php' ) ) ),
			'accepted_args' => 1,
		);
		eval( 'class WooCommerce {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- a stub class for this process only.
		define( 'WC_VERSION', '8.9.0' );
		pqbg_test_load_wp();
		echo wp_json_encode(
			array(
				'met'        => ProductQrBarcode\Requirements::met(),
				'errors'     => ProductQrBarcode\Requirements::errors(),
				'booted'     => false !== has_action( 'init', array( 'ProductQrBarcode\\Plugin', 'load_textdomain' ) ),
				'scan'       => false !== has_action( 'parse_request', array( 'ProductQrBarcode\\ScanRoute', 'handle' ) ),
				'lifecycle'  => false !== has_action( 'woocommerce_update_product', array( 'ProductQrBarcode\\CodeLifecycle', 'on_product_saved' ) ),
				'notice'     => false !== has_action( 'admin_notices', array( 'ProductQrBarcode\\Requirements', 'render_notice' ) ),
				'wc_version' => ProductQrBarcode\Requirements::woocommerce_version(),
			)
		), "\n";
		exit( 0 );
	}

	pqbg_test_load_wp();

	if ( 'holdlog' === $mode ) {
		global $wpdb;
		$ok = '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', ProductQrBarcode\BulkLog::lock_name() ) );
		echo $ok ? "held\n" : "not held\n";
		fflush( STDOUT );
		usleep( (int) ( (float) $argv[3] * 1000000 ) );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', ProductQrBarcode\BulkLog::lock_name() ) );
		exit( 0 );
	}

	if ( 'uninstall' === $mode ) {
		// Runs uninstall.php for real against a cloned temporary prefix (every table name switches).
		global $wpdb;
		$wpdb->set_prefix( $argv[3] );
		wp_cache_flush();
		wp_roles()->for_site();
		if ( '1' === $argv[4] ) {
			define( 'PQBG_UNINSTALL_DELETE_ALL_DATA', true );
		}
		define( 'WP_UNINSTALL_PLUGIN', pqbg_test_plugin_basename() );
		include WP_PLUGIN_DIR . '/product-qrcode-barcode-generator/uninstall.php';
		// Nothing else may touch the clone: WordPress's and WooCommerce's shutdown work is skipped.
		remove_all_actions( 'shutdown' );
		echo wp_json_encode( array( 'prefix' => $wpdb->prefix ) ), "\n";
		exit( 0 );
	}

	$start = (float) end( $argv );
	while ( microtime( true ) < $start ) {
		usleep( 2000 );
	}

	if ( 'bulklog' === $mode ) {
		ProductQrBarcode\BulkLog::add( ProductQrBarcode\BulkLog::TOOL_GENERATE, array( 'phase11_worker' => (int) $argv[3] ), (int) $argv[4] );
		echo wp_json_encode( array( 'ok' => (int) $argv[3] ) ), "\n";
	}
	exit( 0 );
}

pqbg_test_load_wp();
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/post.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

use ProductQrBarcode\{AdminUrl, BulkLog, CodeRepository, CostPrice, CsvUpload, DashboardAdmin, HealthCheck, Install, PerfSignal, Plugin, ReportPeriod, ReportsAdmin, Requirements, SaleRepository, SaleRequest, SaleService, SalePresenter, SalesExport, SalesQuery, ScanRoute, ScanScreen, ScanUrl, Schema};

global $wpdb;

$C          = Schema::codes_table();
$S          = Schema::sales_table();
$PM         = $wpdb->postmeta;
$NOTE       = 'pqbg-11-fixture';
$PERF_NOTE  = 'pqbg-11-perf';
$STRESS     = '1' === getenv( 'PQBG_STRESS' );
$MIG        = 'pqbg11m_';
$MIG2       = 'pqbg11f_';
$INV        = 'pqbg11i_';
$UNI        = 'pqbg11u_';
$max        = static fn( string $table, string $col ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX($col), 0) FROM $table" );
$start_c    = $max( $C, 'id' );
$start_s    = $max( $S, 'id' );
$as_mark    = pqbg_test_as_mark();
$start_post = $max( $wpdb->posts, 'ID' );
$start_meta = $max( $PM, 'meta_id' );
$posts_ai   = static fn() => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->posts ) );
$start_ai   = $posts_ai();
$base_cost  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $PM WHERE meta_key = %s", CostPrice::META_KEY ) );
$base_user  = (int) count_users()['total_users'];
$raw_option = static fn( string $name ) => $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
$saved      = array();
foreach ( array( Plugin::SETTINGS_OPTION, BulkLog::OPTION, PerfSignal::OPTION, 'pqbg_bulk_run', 'pqbg_svg_cache_index', Install::DB_VERSION_OPTION ) as $name ) {
	$saved[ $name ] = $raw_option( $name );
}
$svg_rows   = static fn() => $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_pqbg\\_svg\\_%' OR option_name LIKE '\\_transient\\_timeout\\_pqbg\\_svg\\_%'" );
$svg_before = $svg_rows();
$user_ids   = array();
$pw         = array();
$synthetic  = array();
$plant_meta = array();
$real_prefix = $wpdb->prefix;

add_filter( 'pre_wp_mail', '__return_false' );
add_filter(
	'wp_die_handler',
	static fn() => static function ( $message ) {
		throw new RuntimeException( 'wp_die: ' . wp_strip_all_tags( is_wp_error( $message ) ? $message->get_error_message() : (string) $message ) );
	}
);

// Leftovers of an interrupted earlier run (marked rows and temporary prefixes only).
$wpdb->query( $wpdb->prepare( "DELETE FROM $S WHERE note IN (%s, %s)", $NOTE, $PERF_NOTE ) );
$drop_prefix = static function ( string $prefix ) use ( $wpdb ): void {
	foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ) as $t ) {
		if ( str_starts_with( $t, $prefix ) ) { // Never anything but the temporary prefix.
			$wpdb->query( "DROP TABLE IF EXISTS `$t`" );
		}
	}
};
foreach ( array( $MIG, $MIG2, $INV, $UNI ) as $p ) {
	$drop_prefix( $p );
}

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
/** Inserts a fixture sale row (test data, marked). A completed row has its stock snapshot. */
$put = static function ( array $cols ) use ( $wpdb, $S, $NOTE ): int {
	$wpdb->insert(
		$S,
		array_merge(
			array(
				'request_id'      => wp_generate_uuid4(),
				'product_id'      => 1,
				'variation_id'    => 0,
				'seller_id'       => 1,
				'quantity'        => 1,
				'unit_price'      => '100',
				'line_total'      => '100',
				'currency'        => 'INR',
				'product_name'    => 'Fixture 11',
				'status'          => 'completed',
				'stock_before'    => 1,
				'stock_after'     => 0,
				'stock_holder_id' => 1,
				'created_at_gmt'  => gmdate( 'Y-m-d H:i:s', time() - 60 ),
				'payment_method'  => 'cash',
				'note'            => $NOTE,
			),
			$cols
		)
	);
	return (int) $wpdb->insert_id;
};
/** Checksum of everything a GET must not change (the plugin's tables, options, stock and cost meta). */
$state = static function () use ( $wpdb, $S ): string {
	return md5(
		implode(
			'|',
			array(
				(string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, status, COALESCE(stock_after, ''), COALESCE(voided_by, ''), COALESCE(failure_code, '')))), 0)) FROM $S" ),
				(string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT(post_id, meta_key, meta_value))), 0)) FROM {$wpdb->postmeta} WHERE meta_key IN ('_pqbg_cost_price', '_stock', '_stock_status', '_price')" ),
				(string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(ID), 0)) FROM {$wpdb->posts}" ),
				(string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(id), 0), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, status, COALESCE(active_product_id, '')))), 0)) FROM {$wpdb->prefix}pqbg_codes" ),
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
/** Runs worker processes of this file that start at the same moment; returns their JSON lines. */
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
/** Runs one worker and returns its last output line decoded (or the raw text). */
$worker = static function ( array $args ): array {
	$out = array();
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --worker ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1', $out );
	$json = array_values( array_filter( $out, static fn( $l ) => str_starts_with( trim( $l ), '{' ) ) );
	return ( array() !== $json ? json_decode( (string) end( $json ), true ) : null ) ?? array( 'raw' => implode( "\n", $out ) );
};
/** A simple product saved as $as (a code is assigned when $as manages codes). */
$make_simple = static function ( int $as, array $props = array() ): int {
	wp_set_current_user( $as );
	$p = new WC_Product_Simple();
	$p->set_name( 'PQBG 11 simple ' . wp_generate_password( 6, false ) );
	$p->set_status( 'publish' );
	$p->set_regular_price( '100' );
	$p->set_props( $props );
	$id = (int) $p->save();
	wp_set_current_user( 0 );
	return $id;
};
/** A variable product with parent-level stock (the parent holds the stock) and $n variations. */
$make_variable = static function ( int $as, int $n, int $parent_stock ): array {
	wp_set_current_user( $as );
	$opts = array_map( static fn( $i ) => 'S' . $i, range( 1, $n ) );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( $opts );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$p = new WC_Product_Variable();
	$p->set_name( 'PQBG 11 variable ' . wp_generate_password( 6, false ) );
	$p->set_status( 'publish' );
	$p->set_attributes( array( $attr ) );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( $parent_stock );
	$pid  = (int) $p->save();
	$vids = array();
	foreach ( $opts as $opt ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $pid );
		$v->set_attributes( array( 'size' => $opt ) );
		$v->set_regular_price( '100' );
		$v->set_manage_stock( false );
		$vids[] = (int) $v->save();
	}
	wp_set_current_user( 0 );
	return array( $pid, $vids );
};
/** Plants a raw postmeta row (bypassing every API; removed in the cleanup by meta_id). */
$plant = static function ( int $post_id, string $key, string $value ) use ( $wpdb, &$plant_meta ): int {
	$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $post_id, 'meta_key' => $key, 'meta_value' => $value ) );
	$plant_meta[] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
};
$code_of = static fn( int $id ) => (string) ( CodeRepository::find_active_for_product( $id )['code'] ?? '' );
/** Counts per check from HealthCheck::run(). */
$counts = static fn( array $r ) => array_map( static fn( $c ) => (int) $c['count'], $r );
/** Rows of one check whose `item` (or `sale`) is $id. */
$rows_of = static fn( array $r, string $check, string $key, int $id ) => array_values( array_filter( $r[ $check ]['rows'], static fn( $row ) => (int) ( $row[ $key ] ?? 0 ) === $id ) );
/** A file's contents with LF line endings (a checkout may convert them). */
$lf_md5 = static fn( string $file ) => md5( str_replace( "\r\n", "\n", (string) file_get_contents( $file ) ) );

try {
	// ------------------------------------------------------------------ users
	$make_user = static function ( string $role ) use ( &$user_ids, &$pw ): int {
		$login = 'pqbg11_' . $role . '_' . wp_generate_password( 5, false, false );
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
	$logged = true;
	foreach ( array( 'admin' => $A, 'sm' => $SM, 'seller' => $SE, 'customer' => $CU ) as $who => $uid ) {
		$logged = $login( $who, $logins[ $uid ], $pw[ $logins[ $uid ] ] ) && $logged;
	}
	pqbg_t( 'setup: administrator, shop manager, Store Seller and customer logged in over HTTP', $logged );

	// ------------------------------------------------------------------ D18, D19
	pqbg_section( 'requirements and multisite (D18, D19)' );
	$req = static fn( string $php, string $wp, string $wc, bool $ms ) => Requirements::errors_for( $php, $wp, $wc, $ms );
	pqbg_t( 'errors_for: PHP 8.2.0, WordPress 6.7, WooCommerce 9.0.0, single site → no error', array() === $req( '8.2.0', '6.7', '9.0.0', false ) );
	$e = $req( '8.1.30', '6.7', '9.0.0', false );
	pqbg_t( 'errors_for: PHP 8.1.30 → one error naming 8.2 and 8.1.30', 1 === count( $e ) && str_contains( $e[0], 'PHP 8.2' ) && str_contains( $e[0], '8.1.30' ), implode( ' | ', $e ) );
	$e = $req( '8.2.0', '6.6.2', '9.0.0', false );
	pqbg_t( 'errors_for: WordPress 6.6.2 → one error naming 6.7 and 6.6.2', 1 === count( $e ) && str_contains( $e[0], 'WordPress 6.7' ) && str_contains( $e[0], '6.6.2' ) );
	$e = $req( '8.2.0', '6.7', '', false );
	pqbg_t( 'errors_for: WooCommerce missing → "requires WooCommerce to be installed and active"', 1 === count( $e ) && str_contains( $e[0], 'requires WooCommerce to be installed and active' ) );
	$e = $req( '8.2.0', '6.7', '8.9.0', false );
	pqbg_t( 'errors_for: WooCommerce 8.9.0 → one error naming 9.0 and 8.9.0', 1 === count( $e ) && str_contains( $e[0], 'WooCommerce 9.0' ) && str_contains( $e[0], '8.9.0' ) );
	$e = $req( '8.2.0', '6.7', '9.0.0', true );
	pqbg_t( 'errors_for: multisite → refused ("single sites only")', 1 === count( $e ) && str_contains( $e[0], 'single sites only' ) );
	pqbg_t( 'errors_for: everything wrong → four errors', 4 === count( $req( '7.4.0', '6.0', '8.0.0', true ) ) );
	pqbg_t( 'this site: requirements met (single site, PHP 8.5, WordPress 7.1, WooCommerce 11.1)', array() === Requirements::errors() && ! is_multisite() );
	$install_src = (string) file_get_contents( PQBG_PLUGIN_DIR . 'includes/Install.php' );
	pqbg_t( 'activation refuses through Requirements::errors() only (the network-only rule is gone: any multisite is refused)', str_contains( $install_src, '$errors = Requirements::errors();' ) && ! str_contains( $install_src, 'cannot be network activated' ) );
	$wc = $worker( array( 'wcold' ) );
	pqbg_t( 'WooCommerce 8.9.0 (another process, a stub): requirements not met, the error names 9.0 and 8.9.0, the notice is hooked', isset( $wc['met'] ) && false === $wc['met'] && '8.9.0' === $wc['wc_version'] && str_contains( implode( ' ', $wc['errors'] ), 'WooCommerce 9.0' ) && str_contains( implode( ' ', $wc['errors'] ), '8.9.0' ) && true === $wc['notice'], wp_json_encode( $wc ) );
	pqbg_t( 'WooCommerce 8.9.0: the plugin does not boot (no textdomain, scan route or product hooks)', isset( $wc['booted'] ) && false === $wc['booted'] && false === $wc['scan'] && false === $wc['lifecycle'] );

	// ------------------------------------------------------------------ products for the plants
	pqbg_section( 'health check: fixtures' );
	$P1  = $make_simple( $A, array( 'manage_stock' => true, 'stock_quantity' => 4 ) );
	$P2  = $make_simple( $A, array( 'manage_stock' => true, 'stock_quantity' => 4 ) );
	$P3  = $make_simple( $A, array( 'manage_stock' => true, 'stock_quantity' => 4 ) );
	$P4  = $make_simple( $A );
	$P5  = $make_simple( $A );
	$P6  = $make_simple( $A );
	$PS  = $make_simple( $A, array( 'manage_stock' => true, 'stock_quantity' => 5 ) );
	list( $VP, $VV )   = $make_variable( $A, 2, 3 );
	list( $VP2, $VV2 ) = $make_variable( $A, 1, 3 );
	pqbg_t( 'fixtures: 7 simple products and 2 variable products with parent-level stock; every sellable item has an active code', '' !== $code_of( $P1 ) && '' !== $code_of( $P2 ) && '' !== $code_of( $P3 ) && '' !== $code_of( $PS ) && '' !== $code_of( $VV[0] ) && '' !== $code_of( $VV[1] ) && '' !== $code_of( $VV2[0] ) );
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'PQBG 11 page' ) );
	$ad   = wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'auto-draft', 'post_title' => 'PQBG 11 auto-draft' ) );
	$base = HealthCheck::run();
	$bc   = $counts( $base );
	// Phase 12 (false by design): the permalinks check (error) after schema.
	pqbg_t( 'health check: all ten checks run, with the documented severities (Phase 12: permalinks)', array( 'schema', 'permalinks', 'negative_stock', 'stock_after_null', 'stale_pending', 'code_items', 'active_codes', 'cost_meta', 'code_items_trash', 'sales_no_item' ) === array_keys( $base ) && 'error' === $base['negative_stock']['severity'] && 'warning' === $base['stock_after_null']['severity'] && 'warning' === $base['stale_pending']['severity'] && 'error' === $base['code_items']['severity'] && 'error' === $base['active_codes']['severity'] && 'warning' === $base['cost_meta']['severity'] && 'info' === $base['code_items_trash']['severity'] && 'info' === $base['sales_no_item']['severity'] && 'error' === $base['schema']['severity'] && 'error' === $base['permalinks']['severity'] && 0 === $base['permalinks']['count'] );
	pqbg_t( 'health check: the clean control finds nothing among this suite\'s fixtures', array() === $rows_of( $base, 'negative_stock', 'item', $P1 ) && array() === $rows_of( $base, 'code_items', 'item', $P2 ) && 0 === $bc['schema'] && 0 === $bc['active_codes'], wp_json_encode( $bc ) );
	pqbg_t( 'health check: the Dashboard set leaves the information checks out', array( 'schema', 'permalinks', 'negative_stock', 'stock_after_null', 'stale_pending', 'code_items', 'active_codes', 'cost_meta' ) === array_keys( HealthCheck::run( false ) ) );

	// ------------------------------------------------------------------ plants
	pqbg_section( 'health check: planted problems (D3)' );
	$wpdb->update( $PM, array( 'meta_value' => '-2' ), array( 'post_id' => $P1, 'meta_key' => '_stock' ) );
	$wpdb->update( $PM, array( 'meta_value' => '-1' ), array( 'post_id' => $VP, 'meta_key' => '_stock' ) );
	$sa_null  = $put( array( 'stock_after' => null, 'product_id' => $P1 ) );
	$pend_old = $put( array( 'status' => 'pending', 'stock_before' => null, 'stock_after' => null, 'created_at_gmt' => gmdate( 'Y-m-d H:i:s', time() - 16 * 60 ) ) );
	$pend_new = $put( array( 'status' => 'pending', 'stock_before' => null, 'stock_after' => null, 'created_at_gmt' => gmdate( 'Y-m-d H:i:s', time() - 14 * 60 ) ) );
	wp_set_object_terms( $P2, 'grouped', 'product_type' );
	wp_set_object_terms( $VP2, 'simple', 'product_type' );
	$miss = CodeRepository::create_active( 'TEST-PQBG-11MISS', 999999911, 0, $A );
	$pagc = CodeRepository::create_active( 'TEST-PQBG-11PAGE', (int) $page, 0, $A );
	$adc  = CodeRepository::create_active( 'TEST-PQBG-11AUTO', (int) $ad, 0, $A );
	wp_trash_post( $P3 );
	$plant( $P4, CostPrice::META_KEY, '777.777' );
	$plant( $P4, CostPrice::META_KEY, '10' );
	$plant( $P5, CostPrice::META_KEY, '-5' );
	$plant( $P6, CostPrice::META_KEY, 'abc' );
	$plant( (int) $page, CostPrice::META_KEY, '10' );
	$plant( $PS, CostPrice::META_KEY, '450.00' ); // A valid cost: never reported.
	$no_item = $put( array( 'product_id' => 999999912 ) );
	wp_cache_flush();
	$st_p = $state();
	$r    = HealthCheck::run();
	$rc   = $counts( $r );
	pqbg_t( 'negative stock: a simple item (-2) and the parent of a variation with parent-level stock (-1) are listed', 2 === $rc['negative_stock'] - $bc['negative_stock'] && '-2' === ( $rows_of( $r, 'negative_stock', 'item', $P1 )[0]['stock'] ?? '' ) && '-1' === ( $rows_of( $r, 'negative_stock', 'item', $VP )[0]['stock'] ?? '' ), wp_json_encode( $r['negative_stock'] ) );
	pqbg_t( 'completed sale without stock_after: listed (sale, item, date)', 1 === $rc['stock_after_null'] - $bc['stock_after_null'] && $P1 === ( $rows_of( $r, 'stock_after_null', 'sale', $sa_null )[0]['item'] ?? 0 ) );
	pqbg_t( 'pending sales: the one 16 minutes old is listed, the one 14 minutes old is not (15-minute rule)', 1 === $rc['stale_pending'] - $bc['stale_pending'] && array() !== $rows_of( $r, 'stale_pending', 'sale', $pend_old ) && array() === $rows_of( $r, 'stale_pending', 'sale', $pend_new ) );
	pqbg_t( 'the 15-minute rule with a given "now": the newer one is listed 2 minutes later', array() !== $rows_of( HealthCheck::run( true, time() + 120 ), 'stale_pending', 'sale', $pend_new ) );
	$reason = static fn( int $item ) => (string) ( $rows_of( $r, 'code_items', 'item', $item )[0]['reason'] ?? '' );
	pqbg_t( 'codes on unsuitable items: now grouped (type), parent no longer variable (parent), missing item, a page (not_product), an auto-draft', 5 === $rc['code_items'] - $bc['code_items'] && 'type' === $reason( $P2 ) && 'parent' === $reason( $VV2[0] ) && 'missing' === $reason( 999999911 ) && 'not_product' === $reason( (int) $page ) && 'auto_draft' === $reason( (int) $ad ), wp_json_encode( $r['code_items']['rows'] ) );
	pqbg_t( 'codes on trashed items: information only (the trashed product keeps its active code), not an error', 1 === $rc['code_items_trash'] - $bc['code_items_trash'] && array() !== $rows_of( $r, 'code_items_trash', 'item', $P3 ) && '' === $reason( $P3 ) );
	$cost_reasons = array_map( static fn( $row ) => $row['item'] . ':' . $row['reason'], $r['cost_meta']['rows'] );
	pqbg_t( 'cost meta: 3 decimals, a second row, negative, not a number, on a page → 5 findings; the valid 450.00 is not reported', 5 === $rc['cost_meta'] - $bc['cost_meta'] && array() === array_diff( array( "$P4:not_number", "$P4:duplicate", "$P5:not_number", "$P6:not_number", "$page:not_product" ), $cost_reasons ) && array() === $rows_of( $r, 'cost_meta', 'item', $PS ), implode( ', ', $cost_reasons ) );
	pqbg_t( 'cost meta: the result never contains a value (safe to show)', ! str_contains( wp_json_encode( $r['cost_meta'] ), '777' ) && ! str_contains( wp_json_encode( $r['cost_meta'] ), 'abc' ) );
	pqbg_t( 'sales of deleted items: information only, listed with the sale', 1 === $rc['sales_no_item'] - $bc['sales_no_item'] && array() !== $rows_of( $r, 'sales_no_item', 'sale', $no_item ) );
	$problems = HealthCheck::problems( $r ) - HealthCheck::problems( $base );
	pqbg_t( 'problems(): errors and warnings only (2 + 1 + 1 + 5 + 5 = 14 more), information not counted', 14 === $problems, (string) $problems );
	$ver = Install::DB_VERSION + 1;
	add_filter( 'pre_option_' . Install::DB_VERSION_OPTION, $fv = static fn() => $ver );
	$rs = HealthCheck::run();
	remove_filter( 'pre_option_' . Install::DB_VERSION_OPTION, $fv );
	pqbg_t( 'schema: a stored version that differs from the code is an error (the live option is untouched: a filter)', 1 === $rs['schema']['count'] && 'version' === $rs['schema']['rows'][0]['reason'] && $ver === $rs['schema']['rows'][0]['stored'] && Install::DB_VERSION === Install::stored_version() );
	pqbg_t( 'the checks changed nothing (plugin tables, options, stock and cost meta identical before and after four runs)', $st_p === $state() );

	// The invariant checks on a temporary prefix without constraints (the real table rejects these rows).
	$wpdb->query( "CREATE TABLE {$INV}pqbg_codes AS SELECT * FROM $C WHERE 0" );
	$wpdb->query( "CREATE TABLE {$INV}pqbg_sales AS SELECT * FROM $S WHERE 0" );
	$now_s = gmdate( 'Y-m-d H:i:s' );
	foreach ( array( array( 1, 'TEST-A1', 555, 555, 'active' ), array( 2, 'TEST-A2', 555, 555, 'active' ), array( 3, 'TEST-A3', 556, null, 'active' ), array( 4, 'TEST-A4', 557, 557, 'retired' ), array( 5, 'TEST-A5', 558, null, 'bogus' ), array( 6, 'TEST-A6', 559, 559, 'active' ) ) as $row ) {
		$wpdb->insert( "{$INV}pqbg_codes", array( 'id' => $row[0], 'code' => $row[1], 'kind' => 'product', 'product_id' => $row[2], 'parent_id' => 0, 'active_product_id' => $row[3], 'status' => $row[4], 'created_at_gmt' => $now_s, 'created_by' => 1 ) );
	}
	$wpdb->prefix = $INV;
	try {
		$ri = HealthCheck::run( false );
	} finally {
		$wpdb->prefix = $real_prefix;
	}
	$ir = array_map( static fn( $x ) => $x['item'] . ':' . $x['reason'], $ri['active_codes']['rows'] );
	pqbg_t( 'one active code per item (on a temporary prefix without constraints): 2 active codes on one item, active without active_product_id, retired with one, an unknown status → 4 findings; a correct row is not reported', 4 === $ri['active_codes']['count'] && array( '555:duplicate', '556:invariant', '557:invariant', '558:invariant' ) === $ir, implode( ', ', $ir ) );
	$drop_prefix( $INV );

	// ------------------------------------------------------------------ the tab over HTTP
	pqbg_section( 'health check: Settings → Health check over HTTP (D1, D5)' );
	$url = AdminUrl::health();
	$st1 = $state();
	$h   = $http( 'admin', 'GET', $url );
	pqbg_t( 'administrator: 200, heading, the tab row with Health check current', 200 === $h['code'] && str_contains( $h['body'], '<h1>Settings</h1>' ) && str_contains( $h['body'], 'aria-current="page">Health check</a>' ) && str_contains( $h['body'], $href( AdminUrl::settings() ) . ' class="nav-tab"' ) );
	// Phase 12 (false by design): ten sections (the permalinks check).
	pqbg_t( 'administrator: every check has its section (ten since Phase 12), and the problem count is shown', 10 === preg_match_all( '/<section class="pqbg-health pqbg-health--(error|warning|info)"/', $h['body'] ) && str_contains( $h['body'], number_format_i18n( HealthCheck::problems( $r ) ) . ' problems found.' ) );
	pqbg_t( 'administrator: planted rows are listed with links (product edit, sale)', str_contains( $h['body'], $href( AdminUrl::product_edit( $P1 ) ) ) && str_contains( $h['body'], $href( AdminUrl::sale( $pend_old ) ) ) && str_contains( $h['body'], $href( AdminUrl::product_edit( $VP2 ) ) ) && str_contains( $h['body'], 'Item #999999911' ) );
	pqbg_t( 'administrator: no cost value appears (777.777, -5, abc, 450.00)', ! str_contains( $h['body'], '777.777' ) && ! str_contains( $h['body'], '450.00' ) && ! preg_match( '/>\s*-5\s*</', $h['body'] ) );
	pqbg_t( 'HEAD: 200', 200 === $http( 'admin', 'HEAD', $url )['code'] );
	pqbg_t( 'GET/HEAD wrote nothing (plugin tables, options, stock and cost meta)', $st1 === $state() );
	pqbg_t( 'shop manager, Store Seller, customer: 403 (the Settings page is pqbg_manage_settings)', 403 === $http( 'sm', 'GET', $url )['code'] && 403 === $http( 'seller', 'GET', $url )['code'] && 403 === $http( 'customer', 'GET', $url )['code'] );
	$lo = $http( 'anon', 'GET', $url );
	pqbg_t( 'logged out: sent to the login page', 302 === $lo['code'] && str_contains( $lo['location'], 'wp-login.php' ) );
	pqbg_t( 'unknown tab, and tab[]=health: 404', 404 === $http( 'admin', 'GET', AdminUrl::settings( array( 'tab' => 'bogus' ) ) )['code'] && 404 === $http( 'admin', 'GET', AdminUrl::settings() . '&tab[]=health' )['code'] );
	$sp = $http( 'admin', 'GET', AdminUrl::settings() );
	pqbg_t( 'the Settings tab itself is unchanged (the form, and the tab row with Settings current)', 200 === $sp['code'] && str_contains( $sp['body'], 'aria-current="page">Settings</a>' ) && (bool) preg_match( '/name=[\'"]option_page[\'"] value=[\'"]pqbg_settings[\'"]/', $sp['body'] ) );
	$dash = $http( 'admin', 'GET', AdminUrl::dashboard() );
	pqbg_t( 'Dashboard, administrator: "The health check found N problems" with the Health check link (D2)', 200 === $dash['code'] && str_contains( $dash['body'], 'The health check found ' . number_format_i18n( HealthCheck::problems( HealthCheck::run( false ) ) ) . ' problems' ) && str_contains( $dash['body'], $href( AdminUrl::health() ) ) );
	$dsm = $http( 'sm', 'GET', AdminUrl::dashboard() );
	pqbg_t( 'Dashboard, shop manager: no health check line or link', 200 === $dsm['code'] && ! str_contains( $dsm['body'], 'health check' ) && ! str_contains( $dsm['body'], 'tab=health' ) );

	// ------------------------------------------------------------------ un-plant
	$wpdb->update( $PM, array( 'meta_value' => '4' ), array( 'post_id' => $P1, 'meta_key' => '_stock' ) );
	$wpdb->update( $PM, array( 'meta_value' => '3' ), array( 'post_id' => $VP, 'meta_key' => '_stock' ) );
	wp_set_object_terms( $P2, 'simple', 'product_type' );
	wp_set_object_terms( $VP2, 'variable', 'product_type' );
	wp_untrash_post( $P3 );
	wp_publish_post( $P3 );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $C WHERE code IN (%s, %s, %s)", 'TEST-PQBG-11MISS', 'TEST-PQBG-11PAGE', 'TEST-PQBG-11AUTO' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $S WHERE id IN (%d, %d, %d, %d)", $sa_null, $pend_old, $pend_new, $no_item ) );
	foreach ( $plant_meta as $mid ) {
		$wpdb->delete( $PM, array( 'meta_id' => $mid ) );
	}
	$plant_meta = array();
	wp_cache_flush();
	pqbg_t( 'after removing the plants: back to the clean control', $counts( HealthCheck::run() ) === $bc, wp_json_encode( $counts( HealthCheck::run() ) ) );
	$dash = $http( 'admin', 'GET', AdminUrl::dashboard() );
	pqbg_t( 'Dashboard, administrator: no health check line when nothing is found', 0 !== HealthCheck::problems( $base ) || ( 200 === $dash['code'] && ! str_contains( $dash['body'], 'The health check found' ) ) );

	// ------------------------------------------------------------------ D7
	pqbg_section( 'the Undo button hides itself when the window ends (D7)' );
	$code = $code_of( $PS );
	$sale = SaleService::sell( array( 'code' => $code, 'quantity' => 1, 'request_id' => wp_generate_uuid4(), 'seller_id' => $SE, 'payment_method' => 'cash' ) );
	$sid  = is_wp_error( $sale ) ? 0 : (int) $sale['sale']['id'];
	pqbg_t( 'a real sale by the Store Seller (stock 5 → 4)', $sid > 0 && 4 === SaleRepository::read_stock( $PS ) );
	$sale_url = add_query_arg( SaleRequest::SALE_ARG, $sid, ScanUrl::site_url( $code ) );
	$delay    = static fn( string $body ) => preg_match( '/<style nonce="([A-Za-z0-9_-]+)">\.pqbg-scan__undo,\.pqbg-scan__undo-expired\{animation-delay:(\d+)s\}<\/style>/', $body, $m ) ? array( $m[1], (int) $m[2] ) : array( '', -1 );
	$pg       = $http( 'seller', 'GET', $sale_url );
	list( $nonce, $secs ) = $delay( $pg['body'] );
	pqbg_t( 'sale page: the Undo form and, hidden until then, "Undo is no longer available"', 200 === $pg['code'] && str_contains( $pg['body'], '<form class="pqbg-scan__undo"' ) && str_contains( $pg['body'], '<p class="pqbg-scan__hint pqbg-scan__undo-expired">Undo is no longer available. Ask a manager to void the sale if needed.</p>' ) );
	pqbg_t( 'sale page: one nonce\'d style element; the delay equals the seconds left (598–600 s just after the sale)', '' !== $nonce && $secs >= 598 && $secs <= 600 && 1 === substr_count( $pg['body'], '<style' ), $secs . ' s' );
	pqbg_t( 'sale page: the CSP allows exactly that style nonce, still no script', ScanRoute::csp( $nonce ) === ( $pg['headers']['content-security-policy'] ?? '' ) && ! str_contains( $pg['headers']['content-security-policy'] ?? '', 'script' ) && ! str_contains( $pg['body'], '<script' ) );
	pqbg_t( 'sale page: no reload (no Refresh header, no meta refresh)', ! isset( $pg['headers']['refresh'] ) && ! preg_match( '/http-equiv\s*=\s*["\']?refresh/i', $pg['body'] ) );
	$n2 = $delay( $http( 'seller', 'GET', $sale_url )['body'] )[0];
	pqbg_t( 'every response gets a fresh nonce', '' !== $n2 && $n2 !== $nonce );
	$css = (string) file_get_contents( PQBG_PLUGIN_DIR . 'assets/pqbg-scan.css' );
	pqbg_t( 'stylesheet: a 0 s animation that fills forwards hides the form (visibility and height) and shows the note; the fallback delay is the whole window (600 s)', str_contains( $css, 'animation-fill-mode: forwards;' ) && str_contains( $css, 'animation-delay: 600s;' ) && (bool) preg_match( '/@keyframes pqbg-undo-expire \{\s*to \{\s*visibility: hidden;\s*height: 0;/', $css ) && (bool) preg_match( '/@keyframes pqbg-undo-note \{\s*to \{\s*visibility: visible;/', $css ) );
	$wpdb->update( $S, array( 'created_at_gmt' => gmdate( 'Y-m-d H:i:s', time() - 590 ) ), array( 'id' => $sid ) );
	$pg2           = $http( 'seller', 'GET', $sale_url );
	list( , $s2 )  = $delay( $pg2['body'] );
	$kept          = array(
		'pqbg_action' => $field( $pg2['body'], 'pqbg_action' ),
		'_pqbg_nonce' => $field( $pg2['body'], '_pqbg_nonce' ),
		'sale'        => $field( $pg2['body'], 'sale' ),
	);
	pqbg_t( '590 s after the sale: the delay is 9–10 s', $s2 >= 9 && $s2 <= 10, $s2 . ' s' );
	wp_set_current_user( $SE ); // The Undo form is only for the seller who sold.
	$view = ScanScreen::sale( SaleRepository::find( $sid ), $code );
	wp_set_current_user( 0 );
	pqbg_t( 'in-process: the view\'s "remaining" equals undo_until − now', is_array( $view['undo'] ) && abs( $view['undo']['remaining'] - ( SaleService::undo_until( SaleRepository::find( $sid ) ) - time() ) ) <= 1 && '' !== $view['style_nonce'] );
	$wpdb->update( $S, array( 'created_at_gmt' => gmdate( 'Y-m-d H:i:s', time() - 601 ) ), array( 'id' => $sid ) );
	$pg3 = $http( 'seller', 'GET', $sale_url );
	pqbg_t( 'after 10 minutes: no Undo form, no style element, the plain CSP', 200 === $pg3['code'] && ! str_contains( $pg3['body'], 'pqbg-scan__undo' ) && ! str_contains( $pg3['body'], '<style' ) && ScanRoute::csp() === ( $pg3['headers']['content-security-policy'] ?? '' ) );
	$late = $http( 'seller', 'POST', ScanUrl::site_url( $code ), $kept );
	pqbg_t( 'a late Undo with the kept form is refused: 409 "Undo is no longer available (10-minute limit)." (the owner kept 409)', 409 === $late['code'] && str_contains( $late['body'], 'Undo is no longer available (10-minute limit).' ) );
	pqbg_t( '...nothing changed: the sale is still completed and the stock is still 4', 'completed' === SaleRepository::find( $sid )['status'] && 4 === SaleRepository::read_stock( $PS ) );
	$entry = $http( 'seller', 'GET', ScanUrl::site_url() );
	$prod  = $http( 'seller', 'GET', ScanUrl::site_url( $code ) );
	pqbg_t( 'the entry page and a product page: no style element and the plain CSP', ScanRoute::csp() === ( $entry['headers']['content-security-policy'] ?? '' ) && ScanRoute::csp() === ( $prod['headers']['content-security-policy'] ?? '' ) && ! str_contains( $entry['body'], '<style' ) && ! str_contains( $prod['body'], '<style' ) );
	pqbg_t( 'security_headers() is unchanged for every other response (the CSP without a nonce)', ScanRoute::csp() === ScanRoute::security_headers()['Content-Security-Policy'] && "default-src 'none'; style-src 'self'; img-src 'self' https: data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'" === ScanRoute::csp() );

	// ------------------------------------------------------------------ D8
	pqbg_section( 'BulkLog::add() under its lock (D8)' );
	$before = count( BulkLog::all() );
	$res    = $run_workers( array_map( static fn( $i ) => array( 'bulklog', $i, $A ), range( 1, 8 ) ) );
	wp_cache_flush();
	$mine = array_filter( BulkLog::all(), static fn( $e ) => isset( $e['details']['phase11_worker'] ) );
	$got  = array_map( static fn( $e ) => (int) $e['details']['phase11_worker'], $mine );
	sort( $got );
	pqbg_t( '8 processes adding at the same instant: all 8 entries kept', range( 1, 8 ) === $got && 8 === count( array_filter( $res, static fn( $x ) => isset( $x['ok'] ) ) ), wp_json_encode( $got ) );
	pqbg_t( 'the log lock is free afterwards', '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', BulkLog::lock_name() ) ) );
	$log_lines = static function (): int {
		$n = 0;
		foreach ( (array) glob( trailingslashit( WC_LOG_DIR ) . '*product-qrcode-barcode-generator*.log' ) as $f ) {
			$n += substr_count( (string) file_get_contents( $f ), 'Bulk log written without its lock' );
		}
		return $n;
	};
	$w0    = $log_lines();
	$pipes = array();
	$hold  = proc_open( array( PHP_BINARY, __FILE__, '--worker', 'holdlog', '7' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	$held  = 'held' === trim( (string) fgets( $pipes[1] ) );
	$t0    = microtime( true );
	BulkLog::add( BulkLog::TOOL_GENERATE, array( 'phase11_while_held' => 1 ), $A );
	$waited = microtime( true ) - $t0;
	stream_get_contents( $pipes[1] );
	stream_get_contents( $pipes[2] );
	proc_close( $hold );
	wp_cache_flush();
	pqbg_t( 'with the lock held elsewhere: add() waits the 5 s timeout, then writes the entry anyway (an activity log never blocks a tool)', $held && $waited >= 4.5 && $waited < 6.9 && array() !== array_filter( BulkLog::all(), static fn( $e ) => isset( $e['details']['phase11_while_held'] ) ), sprintf( '%.2f s', $waited ) );
	pqbg_t( '...and says so in the WooCommerce log', $log_lines() > $w0 );

	// ------------------------------------------------------------------ D9
	pqbg_section( 'the performance signal (D9)' );
	pqbg_t( 'evaluate(): 2 of 10 slow renders → no warning; 3 of 10 → warning', array() === PerfSignal::evaluate( array( 2500, 2100, 100, 100, 100, 100, 100, 100, 100, 100 ), 10 ) && array( 3, 10 ) === ( PerfSignal::evaluate( array( 2500, 2100, 2001, 100, 100, 100, 100, 100, 100, 100 ), 10 )['slow'] ?? null ) );
	pqbg_t( 'evaluate(): exactly 2000 ms is not slow; only the last 10 renders count', array() === PerfSignal::evaluate( array( 2000, 2000, 2000 ), 0 ) && array() === PerfSignal::evaluate( array( 3000, 3000, 3000, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1 ), 0 ) );
	pqbg_t( 'evaluate(): 300 sales a day → no warning; 300.1 → warning', array() === PerfSignal::evaluate( array(), 300.0 ) && 300.1 === ( PerfSignal::evaluate( array(), 300.1 )['busy'] ?? null ) );
	pqbg_t( 'message(): empty without reasons; names the numbers and the README otherwise', '' === PerfSignal::message( array() ) && str_contains( PerfSignal::message( array( 'slow' => array( 3, 10 ) ) ), '3 of the last 10 Dashboard loads took more than 2 s' ) && str_contains( PerfSignal::message( array( 'busy' => 301.5 ) ), 'averaged 301.5 a day over the last 30 days (more than 300)' ) );
	delete_option( PerfSignal::OPTION );
	$t0 = time() - 3600;
	for ( $i = 1; $i <= 12; $i++ ) {
		$kept_samples = PerfSignal::record( $i * 1.4, $t0 + $i * 61 ); // A minute apart: every one is written.
	}
	pqbg_t( 'record(): keeps the last 10 samples in whole milliseconds, not autoloaded', array( 4, 6, 7, 8, 10, 11, 13, 14, 15, 17 ) === $kept_samples && $kept_samples === PerfSignal::samples() && in_array( $raw_option( PerfSignal::OPTION )['autoload'] ?? '', array( 'off', 'no' ), true ), wp_json_encode( $kept_samples ) );
	$raw_before = $raw_option( PerfSignal::OPTION );
	$throttled  = PerfSignal::record( 5000, $t0 + 12 * 61 + 59 );
	$raw_mid    = $raw_option( PerfSignal::OPTION );
	$after      = PerfSignal::record( 5000, $t0 + 12 * 61 + 60 );
	pqbg_t( 'record(): at most one write per 60 s (59 s after the last write: nothing written; 60 s after: written)', $kept_samples === $throttled && $raw_before === $raw_mid && 5000 === end( $after ) && 10 === count( $after ) && ( $t0 + 12 * 61 + 60 ) === (int) ( get_option( PerfSignal::OPTION )['at'] ?? 0 ) );
	$old_now = strtotime( '2020-06-30 00:00:00 UTC' );
	$vals    = array();
	for ( $i = 0; $i < 9001; $i++ ) {
		$vals[] = $wpdb->prepare( '(%s, 1, 0, 1, 1, 10, 10, %s, %s, %s, %s, 0, %s)', wp_generate_uuid4(), 'INR', 'Perf 11', 'completed', gmdate( 'Y-m-d H:i:s', $old_now - 1 - ( $i % ( 30 * 86400 - 2 ) ) ), $PERF_NOTE );
		if ( 1000 === count( $vals ) || 9000 === $i ) {
			$wpdb->query( "INSERT INTO $S (request_id, product_id, variation_id, seller_id, quantity, unit_price, line_total, currency, product_name, status, created_at_gmt, stock_after, note) VALUES " . implode( ',', $vals ) );
			$vals = array();
		}
	}
	$put( array( 'status' => 'voided', 'created_at_gmt' => gmdate( 'Y-m-d H:i:s', $old_now - 3600 ), 'note' => $PERF_NOTE ) );
	$put( array( 'created_at_gmt' => gmdate( 'Y-m-d H:i:s', $old_now - 31 * 86400 ), 'note' => $PERF_NOTE ) );
	pqbg_t( 'sales_per_day(): 9,001 completed sales in the 30 days → 300.03 a day (voided sales and older sales not counted)', abs( PerfSignal::sales_per_day( $old_now ) - 9001 / 30 ) < 0.0001, (string) PerfSignal::sales_per_day( $old_now ) );
	pqbg_t( 'admin_attention() with that "now": the busy warning', str_contains( wp_json_encode( DashboardAdmin::admin_attention( array(), $old_now ) ), 'averaged 300.0 a day' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $S WHERE note = %s ORDER BY id DESC LIMIT 1", $PERF_NOTE ) ); // The 31-day-old one.
	$wpdb->query( $wpdb->prepare( "DELETE FROM $S WHERE note = %s AND status = 'completed' ORDER BY id DESC LIMIT 1", $PERF_NOTE ) );
	pqbg_t( '...exactly 300 a day → no warning', array() === PerfSignal::evaluate( array(), PerfSignal::sales_per_day( $old_now ) ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $S WHERE note = %s", $PERF_NOTE ) );
	update_option( PerfSignal::OPTION, array( 'at' => 0, 'samples' => array( 2500, 2600, 2700 ) ), false );
	$d  = $http( 'admin', 'GET', AdminUrl::dashboard() );
	pqbg_t( 'Dashboard, administrator, after 3 slow renders: the warning ("3 of the last 4"), and this render was recorded', str_contains( $d['body'], '3 of the last 4 Dashboard loads took more than 2 s' ) && 4 === count( PerfSignal::samples() ) );
	$raw_d = $raw_option( PerfSignal::OPTION );
	$ds    = $http( 'sm', 'GET', AdminUrl::dashboard() );
	pqbg_t( 'Dashboard, shop manager: no warning (administrators only); a render within 60 s of the last write writes nothing (throttled)', 200 === $ds['code'] && ! str_contains( $ds['body'], 'Dashboard loads took' ) && $raw_d === $raw_option( PerfSignal::OPTION ) && 4 === count( PerfSignal::samples() ) );
	update_option( PerfSignal::OPTION, array( 'at' => 0, 'samples' => array( 2500, 2600 ) ), false );
	$d2 = $http( 'admin', 'GET', AdminUrl::dashboard() );
	pqbg_t( 'two slow renders only: no warning (never after a single slow page)', 200 === $d2['code'] && ! str_contains( $d2['body'], 'Dashboard loads took' ) );

	// ------------------------------------------------------------------ F1
	pqbg_section( 'CSV formula neutralisation (F1)' );
	$cases = array(
		'=1+1'          => "'=1+1",
		'+1'            => "'+1",
		'-1+1'          => "'-1+1",
		'@SUM(A1)'      => "'@SUM(A1)",
		"\t=1"          => "'\t=1",
		"\r=1"          => "'\r=1",
		"\n=1"          => "'\n=1",
		' =1'           => "' =1",
		"\u{00A0}=1"    => "'\u{00A0}=1",
		"\u{FF1D}1+1"   => "'\u{FF1D}1+1",
		"\u{FF0B}1"     => "'\u{FF0B}1",
		"\u{FF0D}1"     => "'\u{FF0D}1",
		"\u{FF20}x"     => "'\u{FF20}x",
		'-5'            => '-5',
		'12.50'         => '12.50',
		'Kurta = red'   => 'Kurta = red',
		'DC-ABCD'       => 'DC-ABCD',
		''              => '',
		"\xff=1"        => "\xff=1",
		" \xff=1"       => " \xff=1",
		" =\xff"        => "' =\xff",
	);
	$bad = array();
	foreach ( $cases as $in => $want ) {
		if ( SalesExport::neutralise( (string) $in ) !== $want ) {
			$bad[] = wp_json_encode( (string) $in, JSON_INVALID_UTF8_SUBSTITUTE );
		}
	}
	pqbg_t( 'neutralise(): = + - @, TAB, CR, LF, after spaces, full-width forms → a leading apostrophe; numbers, plain text and invalid UTF-8 without a trigger unchanged', array() === $bad, implode( ' ', $bad ) );
	$trip = array();
	foreach ( array( '=1+1', ' =1', "\u{FF1D}1", '@x', "\n=1", 'SKU-1', '-5x' ) as $in ) {
		$back = CsvUpload::unwrap( SalesExport::neutralise( $in ) );
		if ( trim( $in ) !== $back ) {
			$trip[] = wp_json_encode( $in ) . ' → ' . wp_json_encode( $back );
		}
	}
	pqbg_t( 'round trip: the importer removes exactly the apostrophe the export added', array() === $trip, implode( '; ', $trip ) );
	$csv_sale = $put( array( 'product_name' => ' =HYPERLINK("http://example.invalid")', 'sku' => "\u{FF1D}1+1", 'created_at_gmt' => '2025-02-10 06:00:00' ) );
	$list     = $http( 'admin', 'GET', AdminUrl::sales( array( 'range' => 'custom', 'from' => '2025-02-10', 'to' => '2025-02-10' ) ) );
	$csv      = $http( 'admin', 'GET', $link_of( $list['body'], SalesExport::ACTION ) );
	pqbg_t( 'the In-store sales CSV over HTTP: the name and SKU cells are neutralised', 200 === $csv['code'] && str_contains( $csv['body'], "\"' =HYPERLINK(\"\"http://example.invalid\"\")\"" ) && str_contains( $csv['body'], "'\u{FF1D}1+1" ), substr( $csv['body'], 0, 300 ) );
	$wpdb->delete( $S, array( 'id' => $csv_sale ) );

	// ------------------------------------------------------------------ D15
	pqbg_section( 'migrations: v1, v2 and v3 tables with data → v4 (D15)' );
	// The tables exactly as each version created them (Schema::statements() of 5f301be = v1 after the
	// rename, 2413698 = v2 (Phase 7), 5495b05 = v3 (Phase 9A)). pqbg_codes did not change.
	$codes_ddl = "CREATE TABLE {prefix}pqbg_codes (\nid bigint(20) unsigned NOT NULL AUTO_INCREMENT,\ncode varchar(32) NOT NULL,\nkind varchar(20) NOT NULL DEFAULT 'product',\nproduct_id bigint(20) unsigned NOT NULL,\nparent_id bigint(20) unsigned NOT NULL DEFAULT '0',\nactive_product_id bigint(20) unsigned NULL DEFAULT NULL,\nstatus varchar(20) NOT NULL DEFAULT 'active',\ncreated_at_gmt datetime NOT NULL,\ncreated_by bigint(20) unsigned NOT NULL DEFAULT '0',\nretired_at_gmt datetime NULL DEFAULT NULL,\nretired_by bigint(20) unsigned NULL DEFAULT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY code (code),\nUNIQUE KEY active_product_id (active_product_id),\nKEY product_status (product_id,status),\nKEY parent_id (parent_id),\nKEY status (status)\n) {collate};";
	$sales_cols = array(
		1 => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,\nrequest_id char(36) NOT NULL,\ncode_id bigint(20) unsigned NULL DEFAULT NULL,\nunit_id bigint(20) unsigned NULL DEFAULT NULL,\nproduct_id bigint(20) unsigned NOT NULL,\nvariation_id bigint(20) unsigned NOT NULL DEFAULT '0',\norder_id bigint(20) unsigned NULL DEFAULT NULL,\nseller_id bigint(20) unsigned NOT NULL,\nquantity int(10) unsigned NOT NULL DEFAULT '1',\nunit_price decimal(26,8) NOT NULL,\nregular_price decimal(26,8) NULL DEFAULT NULL,\nline_total decimal(26,8) NOT NULL,\ncurrency char(3) NOT NULL,\nproduct_name text NOT NULL,\nsku varchar(100) NULL DEFAULT NULL,\nattributes_json longtext NULL,\nstock_before int(11) NULL DEFAULT NULL,\nstock_after int(11) NULL DEFAULT NULL,\nsource varchar(20) NOT NULL DEFAULT 'scan',\nstatus varchar(20) NOT NULL DEFAULT 'completed',\nvoid_reason text NULL,\nvoided_by bigint(20) unsigned NULL DEFAULT NULL,\nvoided_at_gmt datetime NULL DEFAULT NULL,\nnote text NULL,\ncreated_at_gmt datetime NOT NULL,\n",
		2 => "stock_holder_id bigint(20) unsigned NULL DEFAULT NULL,\nfailure_code varchar(40) NULL DEFAULT NULL,\n",
		3 => "payment_method varchar(20) NULL DEFAULT NULL,\nunit_cost decimal(26,8) NULL DEFAULT NULL,\nseller_name varchar(250) NULL DEFAULT NULL,\n",
	);
	$sales_keys = array(
		1 => "PRIMARY KEY  (id),\nUNIQUE KEY request_id (request_id),\nKEY code_id (code_id),\nKEY product_variation (product_id,variation_id),\nKEY seller_created (seller_id,created_at_gmt),\nKEY status_created (status,created_at_gmt),\nKEY created_at_gmt (created_at_gmt),\nKEY order_id (order_id)",
		2 => ",\nKEY holder_status (stock_holder_id,status)",
		3 => ",\nKEY method_created (payment_method,created_at_gmt)",
	);
	$ddl_for = static function ( int $v, string $prefix ) use ( $codes_ddl, $sales_cols, $sales_keys, $wpdb ): array {
		$cols = '';
		$keys = '';
		for ( $i = 1; $i <= $v; $i++ ) {
			$cols .= $sales_cols[ $i ];
			$keys .= $sales_keys[ $i ];
		}
		$sales = "CREATE TABLE {prefix}pqbg_sales (\n{$cols}{$keys}\n) {collate};";
		return array_map( static fn( $s ) => str_replace( array( '{prefix}', '{collate}' ), array( $prefix, $wpdb->get_charset_collate() ), $s ), array( $codes_ddl, $sales ) );
	};
	/** SHOW CREATE TABLE lines, sorted, with the prefix and AUTO_INCREMENT removed (column and index order may differ after ALTERs). */
	$shape = static function ( string $prefix ) use ( $wpdb ): array {
		$out = array();
		foreach ( array( 'pqbg_codes', 'pqbg_sales' ) as $t ) {
			$sql = (string) ( $wpdb->get_row( "SHOW CREATE TABLE {$prefix}{$t}", ARRAY_N )[1] ?? '' );
			$sql = preg_replace( '/ AUTO_INCREMENT=\d+/', '', str_replace( $prefix, '{p}', $sql ) );
			$lines = array_map( static fn( $l ) => rtrim( trim( $l ), ',' ), explode( "\n", $sql ) );
			sort( $lines );
			$out[ $t ] = $lines;
		}
		return $out;
	};
	// A fresh v4 install on another temporary prefix: the reference shape.
	$wpdb->prefix = $MIG2;
	try {
		Schema::create_or_update();
		Schema::ensure_active_check();
		$fresh = $shape( $MIG2 );
	} finally {
		$wpdb->prefix = $real_prefix;
	}
	$drop_prefix( $MIG2 );
	$live_version = $raw_option( Install::DB_VERSION_OPTION );
	$chain_ok     = array();
	foreach ( array( 1, 2, 3 ) as $v ) {
		$drop_prefix( $MIG );
		foreach ( $ddl_for( $v, $MIG ) as $sql ) {
			$wpdb->query( $sql );
		}
		$wpdb->prefix = $MIG;
		try {
			Schema::ensure_active_check(); // As migrate_1 did at that version.
			$cols_now = $wpdb->get_col( "SHOW COLUMNS FROM {$MIG}pqbg_sales" );
			$wpdb->insert( "{$MIG}pqbg_codes", array( 'code' => 'DC-AAAA-BBBB-CCCC', 'kind' => 'product', 'product_id' => 11, 'parent_id' => 0, 'active_product_id' => 11, 'status' => 'active', 'created_at_gmt' => '2025-01-01 10:00:00', 'created_by' => 1 ) );
			$wpdb->insert( "{$MIG}pqbg_codes", array( 'code' => 'DC-DDDD-EEEE-FFFF', 'kind' => 'product', 'product_id' => 12, 'parent_id' => 10, 'active_product_id' => null, 'status' => 'retired', 'created_at_gmt' => '2025-01-01 10:00:00', 'created_by' => 1, 'retired_at_gmt' => '2025-01-02 10:00:00', 'retired_by' => 2 ) );
			$full = array(
				array( 'request_id' => '11111111-1111-4111-8111-111111111111', 'code_id' => 1, 'product_id' => 11, 'seller_id' => 3, 'quantity' => 2, 'unit_price' => '150.50000000', 'regular_price' => '199.00000000', 'line_total' => '301.00000000', 'currency' => 'INR', 'product_name' => 'Kurta ₹ "quoted"', 'sku' => 'K-1', 'attributes_json' => '{"Size":"M"}', 'stock_before' => 5, 'stock_after' => 3, 'source' => 'scan', 'status' => 'completed', 'note' => 'v' . $v, 'created_at_gmt' => '2025-01-03 18:29:59', 'stock_holder_id' => 11, 'failure_code' => null, 'payment_method' => 'upi', 'unit_cost' => '90.00000000', 'seller_name' => 'Seller Three' ),
				array( 'request_id' => '22222222-2222-4222-8222-222222222222', 'code_id' => 2, 'product_id' => 10, 'variation_id' => 12, 'seller_id' => 3, 'quantity' => 1, 'unit_price' => '0.10000000', 'line_total' => '0.10000000', 'currency' => 'INR', 'product_name' => 'Dupatta', 'stock_before' => 1, 'stock_after' => 1, 'status' => 'voided', 'void_reason' => 'undo', 'voided_by' => 3, 'voided_at_gmt' => '2025-01-03 18:35:00', 'created_at_gmt' => '2025-01-03 18:30:00', 'stock_holder_id' => 10, 'payment_method' => 'cash' ),
				array( 'request_id' => '33333333-3333-4333-8333-333333333333', 'product_id' => 11, 'seller_id' => 3, 'quantity' => 1, 'unit_price' => '150.50000000', 'line_total' => '150.50000000', 'currency' => 'INR', 'product_name' => 'Kurta', 'status' => 'failed', 'created_at_gmt' => '2025-01-04 09:00:00', 'failure_code' => 'sold_online' ),
			);
			foreach ( $full as $row ) {
				$wpdb->insert( "{$MIG}pqbg_sales", array_intersect_key( $row, array_flip( $cols_now ) ) );
			}
			$before_rows  = array( $wpdb->get_results( "SELECT * FROM {$MIG}pqbg_codes ORDER BY id", ARRAY_A ), $wpdb->get_results( "SELECT * FROM {$MIG}pqbg_sales ORDER BY id", ARRAY_A ) );
			$ver          = $v;
			$get_version  = static function () use ( &$ver ) {
				return $ver;
			};
			$set_version  = static function ( $new, $old ) use ( &$ver ) {
				$ver = (int) $new;
				return $old; // Nothing reaches the live option.
			};
			add_filter( 'pre_option_' . Install::DB_VERSION_OPTION, $get_version );
			add_filter( 'pre_update_option_' . Install::DB_VERSION_OPTION, $set_version, 10, 2 );
			Install::maybe_upgrade();
			$after_rows = array( $wpdb->get_results( "SELECT * FROM {$MIG}pqbg_codes ORDER BY id", ARRAY_A ), $wpdb->get_results( "SELECT * FROM {$MIG}pqbg_sales ORDER BY id", ARRAY_A ) );
			$kept_vals  = true;
			foreach ( $before_rows[1] as $i => $row ) {
				$kept_vals = $kept_vals && array_intersect_key( $after_rows[1][ $i ], $row ) === $row;
				foreach ( array_diff( array_keys( $after_rows[1][ $i ] ), array_keys( $row ) ) as $new_col ) {
					$kept_vals = $kept_vals && null === $after_rows[1][ $i ][ $new_col ];
				}
			}
			$again = $shape( $MIG );
			$ver_4 = $ver;
			Install::maybe_upgrade(); // Current: nothing to do.
			$ver = Install::DB_VERSION + 1;
			Install::maybe_upgrade(); // Newer than the code: nothing runs.
			$chain_ok[ $v ] = array(
				'v4'    => 4 === $ver_4,
				'shape' => $fresh === $again,
				'codes' => $before_rows[0] === $after_rows[0],
				'rows'  => $kept_vals && count( $before_rows[1] ) === count( $after_rows[1] ),
				'rerun' => $after_rows === array( $wpdb->get_results( "SELECT * FROM {$MIG}pqbg_codes ORDER BY id", ARRAY_A ), $wpdb->get_results( "SELECT * FROM {$MIG}pqbg_sales ORDER BY id", ARRAY_A ) ) && $again === $shape( $MIG ) && Install::DB_VERSION + 1 === $ver,
			);
		} finally {
			remove_all_filters( 'pre_option_' . Install::DB_VERSION_OPTION );
			remove_all_filters( 'pre_update_option_' . Install::DB_VERSION_OPTION );
			$wpdb->prefix = $real_prefix;
		}
		$drop_prefix( $MIG );
	}
	foreach ( array( 1, 2, 3 ) as $v ) {
		pqbg_t( "v{$v} → v4 through maybe_upgrade(): version 4; the tables have exactly the shape of a fresh v4 install; codes identical; every sales value kept and the new columns NULL; re-running and a newer version change nothing", isset( $chain_ok[ $v ] ) && ! in_array( false, $chain_ok[ $v ], true ), wp_json_encode( $chain_ok[ $v ] ?? null ) );
	}
	wp_cache_flush();
	pqbg_t( 'the live pqbg_db_version option was never written, and the live tables are untouched; no temporary tables left', $live_version === $raw_option( Install::DB_VERSION_OPTION ) && Schema::tables_exist() && array() === $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', 'pqbg11%' ) ) );

	// ------------------------------------------------------------------ D17
	pqbg_section( 'uninstall on a cloned temporary prefix, both branches (D17)' );
	$clone = static function () use ( $wpdb, $UNI, $real_prefix, $drop_prefix, $start_c ): void {
		$drop_prefix( $UNI );
		foreach ( array( 'options', 'usermeta', 'users', 'posts', 'postmeta', 'pqbg_codes', 'pqbg_sales' ) as $t ) {
			$wpdb->query( "CREATE TABLE {$UNI}{$t} LIKE {$real_prefix}{$t}" );
			$wpdb->query( "INSERT INTO {$UNI}{$t} SELECT * FROM {$real_prefix}{$t}" );
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$UNI}options SET option_name = %s WHERE option_name = %s", $UNI . 'user_roles', $real_prefix . 'user_roles' ) );
		foreach ( array( 'capabilities', 'user_level' ) as $k ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$UNI}usermeta SET meta_key = %s WHERE meta_key = %s", $UNI . $k, $real_prefix . $k ) );
		}
		// Everything the plugin can leave behind, plus controls that must survive.
		$opt = static function ( string $name, $value ) use ( $wpdb, $UNI ): void {
			$wpdb->replace( "{$UNI}options", array( 'option_name' => $name, 'option_value' => maybe_serialize( $value ), 'autoload' => 'off' ) );
		};
		$opt( 'pqbg_install_lock', time() . ':x' );
		$opt( 'pqbg_bulk_log', array( array( 'tool' => 'generate' ) ) );
		$opt( 'pqbg_bulk_run', array( 'status' => 'done' ) );
		$opt( 'pqbg_perf_samples', array( 100, 200 ) );
		$opt( 'pqbg_svg_cache_index', array( 'pqbg_svg_' . md5( 'x' ) => time() ) );
		$opt( '_transient_pqbg_svg_' . md5( 'x' ), '<svg/>' );
		$opt( '_transient_timeout_pqbg_svg_' . md5( 'x' ), time() + 3600 );
		$opt( '_transient_pqbg_save_failure_1', 'pqbg_x' );
		$opt( '_transient_timeout_pqbg_save_failure_1', time() + 3600 );
		$opt( 'zz_pqbg11_control', 'keep' );
		$opt( '_transient_zz_pqbg11_control', 'keep' );
		foreach ( array( 'pqbg_print_prefs' => 'a', 'pqbg_cost_import' => 'b', 'zz_pqbg11_control' => 'keep' ) as $k => $val ) {
			$wpdb->insert( "{$UNI}usermeta", array( 'user_id' => 1, 'meta_key' => $k, 'meta_value' => $val ) );
		}
		$post = (int) $wpdb->get_var( "SELECT MIN(ID) FROM {$UNI}posts" );
		foreach ( array( '_pqbg_cost_price' => '12.00', '_zz_pqbg11_control' => 'keep' ) as $k => $val ) {
			$wpdb->insert( "{$UNI}postmeta", array( 'post_id' => $post, 'meta_key' => $k, 'meta_value' => $val ) );
		}
		$wpdb->insert( "{$UNI}pqbg_codes", array( 'code' => 'TEST-PQBG-11UNI', 'kind' => 'product', 'product_id' => 5, 'parent_id' => 0, 'active_product_id' => 5, 'status' => 'active', 'created_at_gmt' => gmdate( 'Y-m-d H:i:s' ), 'created_by' => 1 ) );
	};
	$inventory = static function () use ( $wpdb, $UNI ): array {
		$roles = maybe_unserialize( (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$UNI}options WHERE option_name = %s", $UNI . 'user_roles' ) ) );
		return array(
			'options'  => array_column( $wpdb->get_results( "SELECT option_name, MD5(option_value) AS h FROM {$UNI}options WHERE option_name <> '{$UNI}user_roles'", ARRAY_A ), 'h', 'option_name' ),
			'usermeta' => array_column( $wpdb->get_results( "SELECT CONCAT(user_id, '|', meta_key, '|', umeta_id) AS k, MD5(meta_value) AS h FROM {$UNI}usermeta", ARRAY_A ), 'h', 'k' ),
			'postmeta' => array_column( $wpdb->get_results( "SELECT CONCAT(post_id, '|', meta_key, '|', meta_id) AS k, MD5(meta_value) AS h FROM {$UNI}postmeta", ARRAY_A ), 'h', 'k' ),
			'tables'   => $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $UNI ) . '%' ) ),
			'roles'    => is_array( $roles ) ? array_map( static fn( $r ) => array_keys( array_filter( (array) $r['capabilities'] ) ), $roles ) : array(),
		);
	};
	$keys_matching = static fn( array $list, string $re ) => array_values( array_filter( array_keys( $list ), static fn( $k ) => 1 === preg_match( $re, (string) $k ) ) );
	// The documented lists (README "Uninstall"): runtime state on every uninstall, data only with delete-all.
	$runtime_re = '/^(pqbg_install_lock|pqbg_rewrite_version|pqbg_svg_cache_index|pqbg_bulk_run|pqbg_perf_samples|_transient(_timeout)?_pqbg_(svg|save_failure)_.+)$/';
	$data_re    = '/^(pqbg_settings|pqbg_db_version|pqbg_bulk_log)$/';

	foreach ( array( 'default', 'delete-all' ) as $label ) {
		$all = 'delete-all' === $label ? '1' : '0';
		$clone();
		$inv0 = $inventory();
		$out  = $worker( array( 'uninstall', $UNI, (string) $all ) );
		$inv1 = $inventory();
		$gone_opts  = array_keys( array_diff_key( $inv0['options'], $inv1['options'] ) );
		$added_opts = array_keys( array_diff_key( $inv1['options'], $inv0['options'] ) );
		$changed    = array_keys( array_filter( array_intersect_key( $inv1['options'], $inv0['options'] ), static fn( $h, $k ) => $inv0['options'][ $k ] !== $h, ARRAY_FILTER_USE_BOTH ) );
		$gone_um    = array_values( array_unique( array_map( static fn( $k ) => explode( '|', $k )[1], array_keys( array_diff_key( $inv0['usermeta'], $inv1['usermeta'] ) ) ) ) );
		$gone_pm    = array_values( array_unique( array_map( static fn( $k ) => explode( '|', $k )[1], array_keys( array_diff_key( $inv0['postmeta'], $inv1['postmeta'] ) ) ) ) );
		$um_changed = array_intersect_key( $inv1['usermeta'], $inv0['usermeta'] ) !== array_intersect_key( $inv0['usermeta'], $inv1['usermeta'] );
		$pm_changed = array_intersect_key( $inv1['postmeta'], $inv0['postmeta'] ) !== array_intersect_key( $inv0['postmeta'], $inv1['postmeta'] );
		sort( $gone_opts );
		$want_opts = array_merge( $keys_matching( $inv0['options'], $runtime_re ), '1' === $all ? $keys_matching( $inv0['options'], $data_re ) : array() );
		sort( $want_opts );
		pqbg_t( "uninstall ($label) ran for real on the clone (the worker switched every table to {$UNI})", $UNI === ( $out['prefix'] ?? '' ), wp_json_encode( $out ) );
		pqbg_t( "uninstall ($label): exactly the documented options are removed (" . count( $want_opts ) . ' seeded or present), none added or changed (transients WordPress writes itself excepted)', $want_opts === $gone_opts && count( $want_opts ) >= ( '1' === $all ? 12 : 9 ) && array() === array_filter( $added_opts, static fn( $k ) => ! str_starts_with( $k, '_transient' ) && ! str_starts_with( $k, '_site_transient' ) ) && array() === $changed, 'gone ' . implode( ',', $gone_opts ) . ' | added ' . implode( ',', $added_opts ) . ' | changed ' . implode( ',', $changed ) );
		pqbg_t( "uninstall ($label): " . ( '1' === $all ? 'no pqbg option is left; the control option survives' : 'the data options (settings, version, log) and the control option survive' ), ( '1' === $all ? array() === $keys_matching( $inv1['options'], '/^pqbg/' ) : 3 === count( $keys_matching( $inv1['options'], $data_re ) ) ) && isset( $inv1['options']['zz_pqbg11_control'] ) );
		$want_um = '1' === $all ? array( 'pqbg_cost_import', 'pqbg_print_prefs' ) : array( 'pqbg_cost_import' );
		sort( $gone_um );
		pqbg_t( "uninstall ($label): user meta removed = " . implode( ' + ', $want_um ) . '; every other user meta unchanged', $want_um === $gone_um && ! $um_changed, implode( ',', $gone_um ) );
		pqbg_t( "uninstall ($label): post meta " . ( '1' === $all ? 'removed = every _pqbg_cost_price row, nothing else' : 'untouched (costs are data)' ), ( '1' === $all ? array_values( array_unique( $gone_pm ) ) === array( '_pqbg_cost_price' ) && 0 === count( $keys_matching( $inv1['postmeta'], '/\|_pqbg_cost_price\|/' ) ) : array() === $gone_pm ) && ! $pm_changed && 1 === count( $keys_matching( $inv1['postmeta'], '/\|_zz_pqbg11_control\|/' ) ) );
		$tables_gone = array_values( array_diff( $inv0['tables'], $inv1['tables'] ) );
		pqbg_t( "uninstall ($label): tables " . ( '1' === $all ? 'dropped = pqbg_codes and pqbg_sales only' : 'kept' ), ( '1' === $all ? array( "{$UNI}pqbg_codes", "{$UNI}pqbg_sales" ) : array() ) === $tables_gone );
		if ( '1' === $all ) {
			$left_caps = array_filter( $inv1['roles'], static fn( $caps ) => (bool) array_filter( $caps, static fn( $c ) => str_starts_with( (string) $c, 'pqbg_' ) ) );
			$others    = true;
			foreach ( $inv0['roles'] as $role => $caps ) {
				if ( 'pqbg_seller' !== $role ) {
					$others = $others && array_values( array_filter( $caps, static fn( $c ) => ! str_starts_with( (string) $c, 'pqbg_' ) ) ) === ( $inv1['roles'][ $role ] ?? null );
				}
			}
			pqbg_t( 'uninstall (delete-all): the Store Seller role and every pqbg_* capability are gone; every other capability of every role is unchanged', ! isset( $inv1['roles']['pqbg_seller'] ) && isset( $inv0['roles']['pqbg_seller'] ) && array() === $left_caps && $others );
		} else {
			pqbg_t( 'uninstall (default): roles and capabilities unchanged', $inv0['roles'] === $inv1['roles'] );
		}
		$drop_prefix( $UNI );
	}
	wp_cache_flush();
	pqbg_t( 'uninstall: the live site was never touched (settings, version, tables, roles, the Store Seller role), and no clone is left', is_array( get_option( Plugin::SETTINGS_OPTION ) ) && Install::DB_VERSION === Install::stored_version() && Schema::tables_exist() && null !== get_role( 'pqbg_seller' ) && get_role( 'administrator' )->has_cap( 'pqbg_manage_settings' ) && array() === $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $UNI ) . '%' ) ) );

	// ------------------------------------------------------------------ D20
	pqbg_section( 'time and money (D20)' );
	$day_rev = static function ( string $day ): float {
		return (float) SalesQuery::totals( SalesQuery::filters( array( 'range' => 'custom', 'from' => $day, 'to' => $day ) ) )['all']['revenue'];
	};
	$r15 = $day_rev( '2025-03-15' );
	$r16 = $day_rev( '2025-03-16' );
	$a_id = $put( array( 'line_total' => '100.10', 'unit_price' => '100.10', 'created_at_gmt' => '2025-03-15 18:29:59' ) );
	$b_id = $put( array( 'line_total' => '200.20', 'unit_price' => '200.20', 'created_at_gmt' => '2025-03-15 18:30:00' ) );
	pqbg_t( 'Asia/Kolkata: 23:59:59 counts on the 15th, 00:00:00 on the 16th (history totals)', abs( $day_rev( '2025-03-15' ) - $r15 - 100.10 ) < 0.001 && abs( $day_rev( '2025-03-16' ) - $r16 - 200.20 ) < 0.001 );
	$sum_day = static fn( string $day ) => (float) ReportsAdmin::dashboard_data( ReportPeriod::resolve( 'custom', $day, $day ), false )['now']['revenue'];
	$s15     = $sum_day( '2025-03-15' );
	pqbg_t( 'reports (the Summary/Dashboard calculation) agree with the history for both days', abs( $s15 - $day_rev( '2025-03-15' ) ) < 0.001 && abs( $sum_day( '2025-03-16' ) - $day_rev( '2025-03-16' ) ) < 0.001 );
	$line_of = static fn( int $id ) => SalesExport::line( SalesQuery::find( $id ), false );
	pqbg_t( 'CSV dates in the site timezone: 2025-03-15 23:59:59 and 2025-03-16 00:00:00', '2025-03-15 23:59:59' === $line_of( $a_id )[0] && '2025-03-16 00:00:00' === $line_of( $b_id )[0] );
	$tz_filter = static function ( string $tz, string $offset ) {
		add_filter( 'pre_option_timezone_string', $f1 = static fn() => $tz );
		add_filter( 'pre_option_gmt_offset', $f2 = static fn() => $offset );
		return array( $f1, $f2 );
	};
	$tz_off = static function ( array $f ): void {
		remove_filter( 'pre_option_timezone_string', $f[0] );
		remove_filter( 'pre_option_gmt_offset', $f[1] );
	};
	$f = $tz_filter( 'UTC', '0' );
	try {
		pqbg_t( 'the site timezone changed later (UTC, a filter; nothing written): the same stored sales move to UTC days, both on the 15th', abs( $day_rev( '2025-03-15' ) - $r15 - 300.30 ) < 0.001 && '2025-03-15 18:29:59' === $line_of( $a_id )[0] && '2025-03-15 18:30:00' === $line_of( $b_id )[0] );
	} finally {
		$tz_off( $f );
	}
	$f = $tz_filter( '', '5.5' );
	try {
		pqbg_t( 'a manual offset UTC+5:30 gives the same days as Asia/Kolkata', '+05:30' === wp_timezone_string() && abs( $day_rev( '2025-03-15' ) - $r15 - 100.10 ) < 0.001 && '2025-03-16 00:00:00' === $line_of( $b_id )[0] );
	} finally {
		$tz_off( $f );
	}
	$f = $tz_filter( 'Europe/London', '' );
	try {
		$contiguous = static function ( array $b, int $seconds ): bool {
			for ( $i = 1; $i < count( $b ); $i++ ) {
				if ( $b[ $i ]['start'] !== $b[ $i - 1 ]['end'] ) {
					return false;
				}
			}
			return array() !== $b && $seconds === end( $b )['end'] - $b[0]['start'];
		};
		$spring = ReportPeriod::buckets( ReportPeriod::resolve( 'custom', '2025-03-30', '2025-03-30' ), 'hour' );
		$autumn = ReportPeriod::buckets( ReportPeriod::resolve( 'custom', '2025-10-26', '2025-10-26' ), 'hour' );
		$week   = ReportPeriod::buckets( ReportPeriod::resolve( 'custom', '2025-03-27', '2025-04-02' ), 'day' );
		$two_h = array_values( array_filter( $autumn, static fn( $b ) => 7200 === $b['end'] - $b['start'] ) );
		pqbg_t( 'DST (Europe/London): the spring day has 23 hourly buckets (23 h); the autumn day 24, the repeated 01:00 hour being one 2-hour bucket (25 h); contiguous, so no sale falls outside a bucket', 23 === count( $spring ) && 24 === count( $autumn ) && $contiguous( $spring, 23 * 3600 ) && $contiguous( $autumn, 25 * 3600 ) && 1 === count( $two_h ) && '01:00' === $two_h[0]['label'], count( $spring ) . ' / ' . count( $autumn ) );
		pqbg_t( 'DST: a week across the change has 7 daily buckets, one per local day', 7 === count( $week ) && array( '2025-03-27', '2025-03-28', '2025-03-29', '2025-03-30', '2025-03-31', '2025-04-01', '2025-04-02' ) === array_column( $week, 'from' ) && $contiguous( $week, 7 * 86400 - 3600 ) );
	} finally {
		$tz_off( $f );
	}
	pqbg_t( 'the timezone filters are gone (Asia/Kolkata again)', 'Asia/Kolkata' === wp_timezone_string() );
	$paise = array( '0.10', '0.20', '0.30', '0.01', '99.99', '0.07', '12.34', '1.05' );
	$vals  = array();
	for ( $i = 0; $i < 1000; $i++ ) {
		$v      = $paise[ $i % count( $paise ) ];
		$vals[] = $wpdb->prepare( '(%s, 1, 0, 1, 1, %s, %s, %s, %s, %s, %s, 0, %s)', wp_generate_uuid4(), $v, $v, 'INR', 'Paise 11', 'completed', gmdate( 'Y-m-d H:i:s', strtotime( '2025-04-10 05:00:00 UTC' ) + $i ), $NOTE );
	}
	$wpdb->query( "INSERT INTO $S (request_id, product_id, variation_id, seller_id, quantity, unit_price, line_total, currency, product_name, status, created_at_gmt, stock_after, note) VALUES " . implode( ',', $vals ) );
	$sql_sum = (string) $wpdb->get_var( $wpdb->prepare( "SELECT CAST(SUM(line_total) AS DECIMAL(30,2)) FROM $S WHERE status = 'completed' AND created_at_gmt >= %s AND created_at_gmt < %s", '2025-04-09 18:30:00', '2025-04-10 18:30:00' ) );
	$php_sum = number_format( $day_rev( '2025-04-10' ), 2, '.', '' );
	$rep_sum = number_format( $sum_day( '2025-04-10' ), 2, '.', '' );
	pqbg_t( 'paise: 1,000 sales of 0.10/0.20/0.30/0.01/… — the history and report totals equal MariaDB\'s DECIMAL sum to the paisa', $sql_sum === $php_sum && $sql_sum === $rep_sum, "SQL $sql_sum, history $php_sum, reports $rep_sum" );
	$big = array();
	for ( $i = 0; $i < 5; $i++ ) {
		$big[] = $put( array( 'unit_price' => '9999999.99', 'quantity' => 999, 'line_total' => '9989999990.01', 'created_at_gmt' => '2025-05-05 06:00:00' ) );
	}
	$big_sum = number_format( $day_rev( '2025-05-05' ), 2, '.', '' );
	pqbg_t( 'large amounts: 5 × ₹99,99,999.99 × 999 = ₹49,949,999,950.05 exactly (history and reports)', '49949999950.05' === $big_sum && '49949999950.05' === number_format( $sum_day( '2025-05-05' ), 2, '.', '' ), $big_sum );
	$cells  = $line_of( $big[0] );
	$header = SalesExport::header( false );
	$ix     = static function ( string $name ) use ( $header ) {
		foreach ( $header as $i => $label ) {
			if ( str_starts_with( $label, $name . ' (' ) || $label === $name ) {
				return $i;
			}
		}
		return false;
	};
	pqbg_t( 'CSV: the unit price and total are plain numbers (9999999.99, 9989999990.01): no grouping, no currency sign', false !== $ix( 'Unit price' ) && '9999999.99' === $cells[ $ix( 'Unit price' ) ] && '9989999990.01' === $cells[ $ix( 'Total' ) ], wp_json_encode( array_slice( $cells, 0, 12 ) ) );
	$amount_cells = array();
	foreach ( array_merge( array( $a_id, $b_id ), $big ) as $id ) {
		$l = $line_of( $id );
		foreach ( array( 'Unit price', 'Total' ) as $col ) {
			$amount_cells[] = $l[ $ix( $col ) ];
		}
	}
	pqbg_t( 'CSV: every amount matches ^-?\d+\.\d{2}$ (no Indian or Western grouping, no ₹, no "-0.00")', array() === array_filter( $amount_cells, static fn( $c ) => 1 !== preg_match( '/^-?\d+\.\d{2}$/D', (string) $c ) || '-0.00' === $c ), implode( ' ', $amount_cells ) );
	pqbg_t( 'screens use WooCommerce\'s price format (e.g. 9,989,999,990.01)', str_contains( html_entity_decode( wp_strip_all_tags( SalePresenter::money( '9989999990.01' ) ) ), '9,989,999,990.01' ) );

	// ------------------------------------------------------------------ F3
	pqbg_section( 'malformed input on every entry point (F3): never a 500' );
	$actions = array( 'pqbg_generate', 'pqbg_regenerate', 'pqbg_code_image', 'pqbg_print_prepare', 'pqbg_print', 'pqbg_void_sale', 'pqbg_sales_csv', 'pqbg_report_csv', 'pqbg_report_print', 'pqbg_bulk_generate', 'pqbg_codes_csv', 'pqbg_cost_upload', 'pqbg_cost_apply', 'pqbg_cost_report', 'pqbg_cost_template' );
	$junk    = array(
		'item'        => array( '1' ),
		'_pqbg_nonce' => array( 'x' ),
		'_wpnonce'    => array( 'x' ),
		'type'        => array( 'qr' ),
		'mode'        => str_repeat( 'v', 3000 ),
		'sale'        => array( '1' ),
		'tab'         => array( 'x' ),
		'range'       => array( 'custom' ),
		'from'        => str_repeat( '9', 3000 ),
		'items'       => array( '1' ),
		'opt'         => 'x',
		'token'       => "\xff\xfe",
		'run'         => array( 'x' ),
		'reason'      => array( 'x' ),
		'expected'    => '-1',
		'paged'       => "\xff",
	);
	$fivexx = array();
	foreach ( array( 'admin', 'seller' ) as $who ) {
		foreach ( $actions as $action ) {
			foreach ( array( 'GET', 'POST' ) as $method ) {
				$u   = AdminUrl::admin_post( array( 'action' => $action ) );
				$res = 'GET' === $method ? $http( $who, 'GET', $u . '&' . http_build_query( $junk ) ) : $http( $who, 'POST', $u, array_merge( array( 'action' => $action ), $junk ) );
				if ( $res['code'] >= 500 || 0 === $res['code'] ) {
					$fivexx[] = "$who $method $action → {$res['code']}";
				}
			}
		}
	}
	pqbg_t( 'the 15 admin-post handlers, GET and POST, administrator and Store Seller, with array, overlong and invalid-UTF-8 values: no 500', array() === $fivexx, implode( '; ', $fivexx ) );
	$pages = array( AdminUrl::DASHBOARD, AdminUrl::SALES, AdminUrl::REPORTS, AdminUrl::BULK_TOOLS, AdminUrl::SETTINGS );
	$bad   = array();
	foreach ( $pages as $slug ) {
		$res = $http( 'admin', 'GET', AdminUrl::page( $slug ) . '&' . http_build_query( $junk ) . '&orderby[]=x&order[]=y&s[]=z&method[]=cash&status[]=x&seller[]=1' );
		if ( $res['code'] >= 500 || 0 === $res['code'] ) {
			$bad[] = "$slug → {$res['code']}";
		}
	}
	pqbg_t( 'the five plugin pages with the same junk plus array filters: no 500', array() === $bad, implode( '; ', $bad ) );
	$scan_bad = array();
	foreach (
		array(
			array( 'GET', ScanUrl::site_url() . '?code[]=x' ),
			array( 'GET', ScanUrl::site_url() . '?code=' . rawurlencode( "\xff\xfe" ) ),
			array( 'GET', home_url( '/scan/' . str_repeat( 'A', 1500 ) . '/' ) ),
			array( 'GET', home_url( '/scan/' . rawurlencode( "\xff" ) . '/' ) ),
			array( 'GET', ScanUrl::my_sales_url() . '?range[]=7d' ),
			array( 'GET', ScanUrl::site_url( $code ) . '?sale[]=1' ),
			array( 'POST', ScanUrl::site_url( $code ) ),
		) as $req_
	) {
		foreach ( array( 'seller', 'customer', 'anon' ) as $who ) {
			$res = 'POST' === $req_[0] ? $http( $who, 'POST', $req_[1], $junk + array( 'pqbg_action' => array( 'sell' ), 'quantity' => array( '1' ), 'request_id' => array( 'x' ), 'payment_method' => array( 'cash' ) ) ) : $http( $who, 'GET', $req_[1] );
			if ( $res['code'] >= 500 || 0 === $res['code'] ) {
				$scan_bad[] = "$who {$req_[0]} " . substr( $req_[1], 0, 80 ) . " → {$res['code']}";
			}
		}
	}
	pqbg_t( 'scan routes (entry box, code path, My sales, sale page, POST) with arrays, overlong and invalid UTF-8, for a seller, a customer and logged out: no 500', array() === $scan_bad, implode( '; ', $scan_bad ) );
	pqbg_t( 'nothing was sold or changed by the junk (sales and codes unchanged)', 4 === SaleRepository::read_stock( $PS ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $S WHERE id > %d AND product_id = %d", $start_s, $PS ) ) );

	// ------------------------------------------------------------------ performance
	pqbg_section( 'health check timings: 5,000 sales (50,000 with PQBG_STRESS=1) and 2,000 coded items' );
	$t0    = microtime( true );
	$simple_tt = (int) $wpdb->get_var( "SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'product_type' AND t.slug = 'simple'" );
	$now_s = gmdate( 'Y-m-d H:i:s' );
	for ( $i = 0; $i < 2000; $i++ ) {
		if ( 0 === $i % 250 ) {
			pqbg_test_stop_point();
		}
		$wpdb->insert( $wpdb->posts, array( 'post_author' => $A, 'post_date' => $now_s, 'post_date_gmt' => $now_s, 'post_modified' => $now_s, 'post_modified_gmt' => $now_s, 'post_title' => 'PQBG 11 synthetic ' . $i, 'post_status' => 'publish', 'post_type' => 'product', 'post_name' => 'pqbg-11-synthetic-' . $i, 'post_content' => '', 'post_excerpt' => '', 'to_ping' => '', 'pinged' => '', 'post_content_filtered' => '' ) );
		$id          = (int) $wpdb->insert_id;
		$synthetic[] = $id;
		$wpdb->query( $wpdb->prepare( "INSERT INTO $PM (post_id, meta_key, meta_value) VALUES (%d, '_manage_stock', 'yes'), (%d, '_stock', '5')", $id, $id ) );
		$wpdb->insert( $wpdb->term_relationships, array( 'object_id' => $id, 'term_taxonomy_id' => $simple_tt ) );
		$wpdb->insert( $C, array( 'code' => sprintf( 'TEST-PQBG-11-%05d', $i ), 'kind' => 'product', 'product_id' => $id, 'parent_id' => 0, 'active_product_id' => $id, 'status' => 'active', 'created_at_gmt' => $now_s, 'created_by' => $A ) );
	}
	$n_sales = $STRESS ? 50000 : 5000;
	mt_srand( 11 );
	$vals = array();
	for ( $i = 0; $i < $n_sales; $i++ ) {
		$r_      = mt_rand( 1, 100 );
		$status  = $r_ <= 90 ? 'completed' : ( $r_ <= 96 ? 'voided' : 'failed' );
		$it      = $synthetic[ mt_rand( 0, 1999 ) ];
		$vals[]  = $wpdb->prepare( '(%s, %d, 0, 1, 1, %s, %s, %s, %s, %s, %s, 1, 0, %d, %s)', wp_generate_uuid4(), $it, '100', '100', 'INR', 'Perf 11', $status, gmdate( 'Y-m-d H:i:s', time() - mt_rand( 0, 89 * 86400 ) ), $it, $PERF_NOTE );
		if ( 1000 === count( $vals ) || $i === $n_sales - 1 ) {
			pqbg_test_stop_point();
			$wpdb->query( "INSERT INTO $S (request_id, product_id, variation_id, seller_id, quantity, unit_price, line_total, currency, product_name, status, created_at_gmt, stock_before, stock_after, stock_holder_id, note) VALUES " . implode( ',', $vals ) );
			$vals = array();
		}
	}
	$wpdb->query( "ANALYZE TABLE $S" );
	$wpdb->query( "ANALYZE TABLE $C" );
	pqbg_t( sprintf( 'volume: 2,000 coded items and %s sales', number_format( $n_sales ) ), 2000 === count( $synthetic ) && $n_sales === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $S WHERE note = %s", $PERF_NOTE ) ), sprintf( '%.1f s', microtime( true ) - $t0 ) );
	$vol   = HealthCheck::run();
	pqbg_t( 'volume: the synthetic data is clean (no finding among it)', array() === array_filter( $vol['code_items']['rows'], static fn( $x ) => in_array( (int) $x['item'], $synthetic, true ) ) && $counts( $vol )['negative_stock'] === $bc['negative_stock'] );
	$full_ms = $best_ms( static fn() => HealthCheck::run() );
	$dash_ms = $best_ms( static fn() => HealthCheck::run( false ) );
	$attn_ms = $best_ms( static fn() => DashboardAdmin::admin_attention( PerfSignal::samples() ) );
	pqbg_t( sprintf( 'timing at %s sales: the Health check tab\'s checks under 1 s', number_format( $n_sales ) ), $full_ms < 1000, sprintf( '%.1f ms', $full_ms ) );
	pqbg_t( sprintf( 'timing at %s sales: the Dashboard\'s set (errors and warnings) under 150 ms (D2)', number_format( $n_sales ) ), $dash_ms < 150, sprintf( '%.1f ms; the whole admin attention incl. the 30-day count %.1f ms', $dash_ms, $attn_ms ) );
	$tab = array();
	for ( $i = 0; $i < 3; $i++ ) {
		$tab[] = $http( 'admin', 'GET', AdminUrl::health() )['time'];
	}
	sort( $tab );
	pqbg_t( 'the Health check tab over HTTP at this volume: 200, median under 3 s (sanity bound)', $tab[1] < 3, sprintf( '%.2f s', $tab[1] ) );

	// ------------------------------------------------------------------ scope
	pqbg_section( 'scope: the sale path, the schema and the new classes' );
	$hashes = array(
		'SaleService.php'    => 'ddc815561468a2915e04bd414b2f4e15',
		'SaleRepository.php' => '650d8f6c62fa198a08c4bf1962cb5160',
		'SaleRequest.php'    => 'd8915b0196661e08a9bc3af2b26ee535',
		'StockLock.php'      => '519d24355bf2a42982b5806a456d37cb',
		'Schema.php'         => '25a2deb97f206246460b2da78e39559c',
	);
	$changed_files = array_keys( array_filter( $hashes, static fn( $h, $f ) => $lf_md5( PQBG_PLUGIN_DIR . 'includes/' . $f ) !== $h, ARRAY_FILTER_USE_BOTH ) );
	pqbg_t( 'the sale path (SaleService, SaleRepository, SaleRequest, StockLock) and Schema are byte-identical to Phase 10B; DB_VERSION is still 4', array() === $changed_files && 4 === Install::DB_VERSION, implode( ', ', $changed_files ) );
	$src = array();
	foreach ( array( 'HealthCheck.php', 'HealthCheckAdmin.php', 'PerfSignal.php' ) as $f ) {
		// The code only: comments may name other classes (e.g. where a check's rule comes from).
		$src[ $f ] = implode( '', array_map( static fn( $t ) => is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t, token_get_all( (string) file_get_contents( PQBG_PLUGIN_DIR . 'includes/' . $f ) ) ) );
	}
	pqbg_t( 'the new classes add no hook, handler, REST/AJAX/nopriv route, shortcode or rewrite rule', ! preg_match( '/add_action\(|add_filter\(|admin_post_|wp_ajax_|register_rest_route|add_shortcode|add_rewrite/', implode( '', $src ) ) );
	pqbg_t( 'HealthCheck and HealthCheckAdmin never write (SELECT only)', ! preg_match( '/\b(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE)\b|update_option|add_option|delete_option|update_post_meta|set_transient|->insert\(|->update\(|->delete\(|->replace\(/', $src['HealthCheck.php'] . $src['HealthCheckAdmin.php'] ) );
	pqbg_t( 'PerfSignal writes only its own option (one add_option and one update_option of self::OPTION, nothing else)', 1 === substr_count( $src['PerfSignal.php'], 'add_option( self::OPTION, ' ) && 1 === substr_count( $src['PerfSignal.php'], 'update_option( self::OPTION, ' ) && 2 === preg_match_all( '/add_option\(|update_option\(/', $src['PerfSignal.php'] ) && ! preg_match( '/delete_option|update_post_meta|set_transient|->insert\(|->update\(|->delete\(|\b(INSERT|UPDATE|DELETE)\b/', $src['PerfSignal.php'] ) );
	pqbg_t( 'the new classes never reference the sale path', ! preg_match( '/SaleService::|SaleRepository::|SaleRequest::|StockLock::|wc_update_product_stock/', implode( '', $src ) ) );
	pqbg_t( 'the cost meta key is named only in CostPrice (and uninstall.php)', ! preg_match( '/_pqbg_cost_price/', implode( '', $src ) ) );
	pqbg_t( 'direct HTTP to the new files: empty output', '' === $http( 'anon', 'GET', PQBG_PLUGIN_URL . 'includes/HealthCheck.php' )['body'] && '' === $http( 'anon', 'GET', PQBG_PLUGIN_URL . 'includes/HealthCheckAdmin.php' )['body'] && '' === $http( 'anon', 'GET', PQBG_PLUGIN_URL . 'includes/PerfSignal.php' )['body'] );
} catch ( Throwable $e ) {
	pqbg_t( 'suite ran without an exception', false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
	// ------------------------------------------------------------------ cleanup
	pqbg_section( 'cleanup' );
	$wpdb->prefix = $real_prefix;
	remove_all_filters( 'pre_option_' . Install::DB_VERSION_OPTION );
	remove_all_filters( 'pre_update_option_' . Install::DB_VERSION_OPTION );
	remove_all_filters( 'pre_option_timezone_string' );
	remove_all_filters( 'pre_option_gmt_offset' );
	wp_set_current_user( 0 );
	$handles = array();
	foreach ( array( $MIG, $MIG2, $INV, $UNI ) as $p ) {
		$drop_prefix( $p );
	}
	// The synthetic items were written directly (no hooks), so they are removed the same way.
	foreach ( array_chunk( $synthetic, 500 ) as $chunk ) {
		$in = implode( ',', array_map( 'intval', $chunk ) );
		$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($in)" );
		$wpdb->query( "DELETE FROM $PM WHERE post_id IN ($in)" );
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ($in)" );
	}
	foreach ( $plant_meta as $mid ) {
		$wpdb->delete( $PM, array( 'meta_id' => $mid ) );
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
	pqbg_t( 'cleanup: sales, codes and posts back to the start; no temporary tables', $start_s === $max( $S, 'id' ) && $start_c === $max( $C, 'id' ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID > %d", $start_post ) ) && array() === $wpdb->get_col( "SHOW TABLES LIKE 'pqbg11%'" ) );
	pqbg_t( 'cleanup: no test users, cost meta or planted meta left', $base_user === (int) count_users()['total_users'] && $base_cost === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $PM WHERE meta_key = %s", CostPrice::META_KEY ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $PM WHERE meta_id > %d AND post_id <= %d", $start_meta, $start_post ) ) );
	pqbg_t( 'cleanup: settings, log, timing samples, run state, render-cache index and schema version byte-identical (or absent); no render-cache entries left', ! array_filter( $saved, static fn( $raw, $name ) => $raw !== $raw_option( $name ), ARRAY_FILTER_USE_BOTH ) && array() === array_diff( $svg_rows(), $svg_before ) );
	pqbg_t( 'cleanup: the posts AUTO_INCREMENT only moved by the posts this suite created (no jump)', $posts_ai() - $start_ai < 5000, $start_ai . ' → ' . $posts_ai() );
	pqbg_test_as_check( $as_mark );
}

pqbg_test_done();
