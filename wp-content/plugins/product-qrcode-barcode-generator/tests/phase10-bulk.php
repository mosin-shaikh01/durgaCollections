<?php
/**
 * Phase 10 suite: bulk and CSV tools.
 *
 * Which items qualify for bulk code generation (every status, trash, auto-draft,
 * importing, grouped, external, orphan and misplaced variations, enabled and disabled
 * variations, an item with a code, an item with only a retired code); generation in
 * batches, idempotency, a worker process that dies mid-batch and a run that continues
 * after it, two workers at once, stop / resume / dismiss / stale runs, print links;
 * the codes CSV (columns, filters, retired and deleted items, missing items, formula
 * injection, SKUs byte for byte, no cost data); the cost import (number parsing,
 * header and file rules, matching by ID and SKU, duplicates, preview writes nothing,
 * apply, "changed since preview", "already", resuming, re-applying, token binding,
 * expiry and the pruning of expired previews, the report and the template); the
 * QR & Barcodes tabs and every handler over real HTTP for an administrator, a second
 * administrator, a shop manager, a seller, a customer and a logged-out visitor
 * (uploads as multipart, the temporary file removed, nothing in uploads/); the audit
 * log and who sees which entries; the 2,000-item volume timings; scope; cleanup.
 *
 * Fixture products are created as user 0 (no pqbg_manage_codes), so saving them
 * assigns no code. Everything created is removed at the end.
 *
 *   php tests/phase10-bulk.php
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/bootstrap.php';

// ------------------------------------------------------------------ worker processes
if ( isset( $argv[1] ) && '--worker' === $argv[1] ) {
	pqbg_test_load_wp();
	add_filter( 'pre_wp_mail', '__return_false' );
	$mode = $argv[2];

	if ( 'crash' === $mode ) {
		// Dies right after its Nth code has been committed, before the batch saves its state.
		$limit = (int) $argv[5];
		add_filter(
			'query',
			static function ( $q ) use ( $limit ) {
				static $commits = 0;
				if ( $commits >= $limit ) {
					exit( 3 );
				}
				if ( 0 === stripos( ltrim( (string) $q ), 'COMMIT' ) ) {
					++$commits;
				}
				return $q;
			}
		);
		ProductQrBarcode\BulkGenerator::run_batch( $argv[3], (int) $argv[4], 60.0, 100 );
		exit( 0 );
	}

	if ( 'start' === $mode ) {
		// Another request starts a run.
		$r = ProductQrBarcode\BulkGenerator::start( ProductQrBarcode\BulkGenerator::STATUSES, (int) $argv[3] );
		echo wp_json_encode( array( 'id' => is_wp_error( $r ) ? '' : $r['id'] ) ), "\n";
		exit( 0 );
	}

	if ( 'log' === $mode ) {
		// Another request writes a log entry.
		ProductQrBarcode\BulkLog::add( ProductQrBarcode\BulkLog::TOOL_CODES_EXPORT, array( 'rows' => 1, 'marker' => 'other-request' ), (int) $argv[3] );
		echo wp_json_encode( array( 'ok' => true ) ), "\n";
		exit( 0 );
	}

	if ( 'dismiss' === $mode ) {
		// Another request forgets the run.
		$r = ProductQrBarcode\BulkGenerator::dismiss( $argv[3] );
		echo wp_json_encode( array( 'ok' => true === $r ) ), "\n";
		exit( 0 );
	}

	if ( 'gen' === $mode ) {
		$start = (float) $argv[6];
		while ( microtime( true ) < $start ) {
			usleep( 2000 );
		}
		$batches = 0;
		$locked  = 0;
		$status  = '';
		for ( $i = 0; $i < 200; $i++ ) {
			$r = ProductQrBarcode\BulkGenerator::run_batch( $argv[3], (int) $argv[4], 60.0, (int) $argv[5] );
			if ( is_wp_error( $r ) ) {
				if ( 'pqbg_bulk_locked' === $r->get_error_code() ) {
					++$locked;
					continue;
				}
				$status = $r->get_error_code();
				break;
			}
			++$batches;
			$status = (string) $r['status'];
			if ( 'running' !== $status ) {
				break;
			}
		}
		echo wp_json_encode( array( 'batches' => $batches, 'locked' => $locked, 'status' => $status ) ), "\n";
		exit( 0 );
	}

	exit( 1 );
}

pqbg_test_load_wp();
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/post.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use ProductQrBarcode\{BulkGenerator, BulkLog, CodeRepository, CodesExport, CostImport, CostPrice, CsvUpload, Permissions, PrintAdmin, PrintJob, ProductCodeService, SalesExport, ScanUrl, Schema, SettingsPage, ToolsAdmin};

global $wpdb;

$C          = Schema::codes_table();
$PM         = $wpdb->postmeta;
$max        = static fn( string $table, string $col ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX($col), 0) FROM $table" );
$start_c    = $max( $C, 'id' );
$as_mark    = pqbg_test_as_mark();
$start_post = $max( $wpdb->posts, 'ID' );
$posts_ai   = static fn() => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->posts ) );
$start_ai   = $posts_ai();
$base_cost  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $PM WHERE meta_key = %s", CostPrice::META_KEY ) );
$base_user  = (int) count_users()['total_users'];
$base_prev  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", CostImport::META ) );
$raw_option = static fn( string $name ) => $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
$saved      = array();
foreach ( array( BulkGenerator::OPTION, BulkLog::OPTION, 'pqbg_svg_cache_index' ) as $name ) {
	$saved[ $name ] = $raw_option( $name );
}
// Opening the Phase 8 print setup screen may fill its render cache; entries it adds are removed at the end.
$svg_rows   = static fn() => $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_pqbg\\_svg\\_%' OR option_name LIKE '\\_transient\\_timeout\\_pqbg\\_svg\\_%'" );
$svg_before = $svg_rows();
$tmp_dir    = (string) ini_get( 'upload_tmp_dir' );
$tmp_list   = static fn() => '' !== $tmp_dir && is_dir( $tmp_dir ) ? array_map( 'basename', (array) glob( $tmp_dir . DIRECTORY_SEPARATOR . 'php*' ) ) : array();
$tmp_before = $tmp_list();
$uploads    = wp_upload_dir();
$csv_in_up  = static fn() => count( (array) glob( $uploads['basedir'] . '/{,*/,*/*/}*.csv', GLOB_BRACE ) );
$up_before  = $csv_in_up();
$user_ids   = array();
$pw         = array();
$timings    = array();

add_filter( 'pre_wp_mail', '__return_false' );
add_filter(
	'wp_die_handler',
	static fn() => static function ( $message ) {
		throw new RuntimeException( 'wp_die: ' . wp_strip_all_tags( is_wp_error( $message ) ? $message->get_error_message() : (string) $message ) );
	}
);

// Cooperative stop: when the runner's watchdog creates PQBG_STOP_FILE (free memory below its limit),
// the suite throws at the next section or loop step, so the cleanup in `finally` still runs.
$stop_file = (string) getenv( 'PQBG_STOP_FILE' );
$guard     = static function () use ( $stop_file ): void {
	if ( '' !== $stop_file && file_exists( $stop_file ) ) {
		throw new RuntimeException( 'Stopped on request (PQBG_STOP_FILE): the machine is low on free memory.' );
	}
};
$sec = static function ( string $title ) use ( $guard ): void {
	$guard();
	pqbg_section( $title );
};

$code_row = static fn( int $id ) => CodeRepository::find_active_for_product( $id );
$active_n = static fn( int $id ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $C WHERE active_product_id = %d", $id ) );
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
$export = static function ( array $input ) use ( $csv_rows ): array {
	$fh = fopen( 'php://temp', 'w+' );
	CodesExport::write( $fh, CodesExport::filters( $input ) );
	rewind( $fh );
	$raw = (string) stream_get_contents( $fh );
	fclose( $fh );
	return array( 'raw' => $raw, 'rows' => $csv_rows( substr( $raw, 3 ) ) );
};
$by_item = static function ( array $rows ): array {
	$out = array();
	foreach ( array_slice( $rows, 1 ) as $r ) {
		$out[ (int) $r[0] ][] = $r;
	}
	return $out;
};
$time_ms = static function ( callable $fn ): float {
	$t = hrtime( true );
	$fn();
	return ( hrtime( true ) - $t ) / 1e6;
};
/** Builds a parsed file for CostImport from rows of cells (first row = header). */
$parsed = static function ( array $rows, string $name = 'costs.csv' ): array {
	$fh = fopen( 'php://temp', 'w+' );
	foreach ( $rows as $r ) {
		fputcsv( $fh, $r, ',', '"', '' );
	}
	rewind( $fh );
	$bytes = (string) stream_get_contents( $fh );
	fclose( $fh );
	return CsvUpload::parse( $bytes, $name );
};
/** Preview rows by line number. */
$by_line = static fn( array $rows ) => array_column( $rows, null, CostImport::F_LINE );

// HTTP client: a fresh connection per request (see the Phase 6 notes about this XAMPP's php8ts.dll).
$handles = array();
$http    = static function ( string $who, string $method, string $url, array|string|null $post = null ) use ( &$handles ): array {
	if ( ! isset( $handles[ $who ] ) ) {
		$handles[ $who ] = curl_init();
		curl_setopt( $handles[ $who ], CURLOPT_COOKIEFILE, '' );
	}
	$ch        = $handles[ $who ];
	$multipart = is_array( $post ) && array() !== array_filter( $post, static fn( $v ) => $v instanceof CURLFile );
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
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $multipart ? $post : ( is_array( $post ) ? http_build_query( $post ) : (string) $post ) );
	}
	$raw  = (string) curl_exec( $ch );
	$size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	// The request may have changed options, meta or codes in Apache: later in-process reads must come from the database.
	wp_cache_flush();
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
$tab_url  = static fn( string $tab = '', array $args = array() ) => add_query_arg( array_merge( array( 'page' => SettingsPage::SLUG ), '' === $tab ? array() : array( 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
$post_url = admin_url( 'admin-post.php' );
$field    = static fn( string $html, string $name ) => preg_match( '/name="' . preg_quote( $name, '/' ) . '" value="([^"]*)"/', $html, $m ) ? html_entity_decode( $m[1] ) : '';
$link_of  = static fn( string $html, string $action ) => preg_match( '/href="([^"]*action=' . preg_quote( $action, '/' ) . '[^"]*)"/', $html, $m ) ? html_entity_decode( $m[1] ) : '';
$abs      = static fn( string $loc ) => str_starts_with( $loc, '/' ) ? preg_replace( '#^(https?://[^/]+).*$#', '$1', home_url() ) . $loc : $loc;
$wrap     = static fn( string $body ) => (string) substr( $body, (int) strpos( $body, '<div class="wrap">' ) );

/** A simple product saved as user 0 (no code on save). */
$make_simple = static function ( array $props = array() ): int {
	wp_set_current_user( 0 );
	$p = new WC_Product_Simple();
	$p->set_name( 'PQBG 10 simple ' . wp_generate_password( 6, false ) );
	$p->set_status( 'publish' );
	$p->set_regular_price( '100' );
	$p->set_props( $props );
	return (int) $p->save();
};
/** A variable product with variations (as user 0); $vars: list of variation props. */
$make_variable = static function ( array $props, array $vars ) {
	wp_set_current_user( 0 );
	$opts = array_map( static fn( $i ) => 'S' . $i, range( 1, max( 1, count( $vars ) ) ) );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( $opts );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$p = new WC_Product_Variable();
	$p->set_name( 'PQBG 10 variable ' . wp_generate_password( 6, false ) );
	$p->set_status( 'publish' );
	$p->set_attributes( array( $attr ) );
	$p->set_props( $props );
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
	return array( $pid, $vids );
};
/** Runs worker processes of this file; returns their outputs. */
$run_workers = static function ( array $arg_sets ): array {
	$start = microtime( true ) + 2.5;
	$procs = array();
	$pipes = array();
	foreach ( $arg_sets as $i => $args ) {
		$procs[ $i ] = proc_open( array_merge( array( PHP_BINARY, __FILE__, '--worker' ), array_map( 'strval', $args ), array( sprintf( '%.4F', $start ) ) ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes[ $i ] );
	}
	$out = array();
	foreach ( $procs as $i => $p ) {
		$text      = stream_get_contents( $pipes[ $i ][1] ) . stream_get_contents( $pipes[ $i ][2] );
		$code      = proc_close( $p );
		$lines     = array_values( array_filter( array_map( 'trim', explode( "\n", $text ) ) ) );
		$out[ $i ] = ( json_decode( (string) end( $lines ), true ) ?? array( 'raw' => $text ) ) + array( 'exit' => $code );
	}
	return $out;
};
/** Runs a whole run in-process in small batches; returns the final state. */
$run_all = static function ( array $statuses, int $user, int $batch = 100 ) {
	$state = BulkGenerator::start( $statuses, $user );
	if ( is_wp_error( $state ) ) {
		return $state;
	}
	for ( $i = 0; $i < 1000 && 'running' === $state['status']; $i++ ) {
		$state = BulkGenerator::run_batch( (string) $state['id'], $user, 60.0, $batch );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
	}
	return $state;
};
$forget_run = static function () use ( $wpdb ): void {
	$wpdb->delete( $wpdb->options, array( 'option_name' => BulkGenerator::OPTION ) );
	wp_cache_delete( BulkGenerator::OPTION, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
};

try {
	// ------------------------------------------------------------------ users and sessions
	$make_user = static function ( string $role, string $display ) use ( &$user_ids, &$pw ): int {
		$login = 'pqbg10_' . $role . '_' . wp_generate_password( 5, false, false );
		$pass  = wp_generate_password( 20, true, false );
		$id    = wp_insert_user( array( 'user_login' => $login, 'user_pass' => $pass, 'user_email' => $login . '@example.invalid', 'role' => $role, 'display_name' => $display ) );
		$user_ids[ $login ] = (int) $id;
		$pw[ $login ]       = $pass;
		return (int) $id;
	};
	$A      = $make_user( 'administrator', 'PQBG10 Admin' );
	$A2     = $make_user( 'administrator', 'PQBG10 Second Admin' );
	$SM     = $make_user( 'shop_manager', 'PQBG10 Manager' );
	$SE     = $make_user( 'pqbg_seller', 'PQBG10 Seller' );
	$CU     = $make_user( 'customer', 'PQBG10 Customer' );
	$logins = array_flip( $user_ids );

	// ------------------------------------------------------------------ capabilities and tabs
	$sec( 'capabilities and tabs (D13 as changed: tabs in QR & Barcodes)' );
	pqbg_t( 'no new capability: the eight Phase 9A capabilities, unchanged role map', 8 === count( Permissions::all_caps() ) && ! array_diff( Permissions::all_caps(), array( 'pqbg_view_products', 'pqbg_sell', 'pqbg_view_own_sales', 'pqbg_view_all_sales', 'pqbg_void_sale', 'pqbg_manage_codes', 'pqbg_manage_settings', 'pqbg_view_costs' ) ) );
	pqbg_t( 'tabs: administrator Settings | Code tools | Import cost prices', array( 'settings', 'tools', 'costs' ) === ToolsAdmin::tabs( $A ) );
	pqbg_t( 'tabs: shop manager only Code tools', array( 'tools' ) === ToolsAdmin::tabs( $SM ) );
	pqbg_t( 'tabs: seller, customer and logged out none', array() === ToolsAdmin::tabs( $SE ) && array() === ToolsAdmin::tabs( $CU ) && array() === ToolsAdmin::tabs( 0 ) );
	pqbg_t( 'options.php capability for the settings group is still pqbg_manage_settings', 'pqbg_manage_settings' === SettingsPage::capability() );
	pqbg_t( 'the tools hooks are admin-only (not registered in this CLI request)', false === has_action( 'admin_post_' . ToolsAdmin::GENERATE ) && false === has_action( 'admin_post_' . CodesExport::ACTION ) && false === has_action( 'admin_post_' . ToolsAdmin::UPLOAD ) );

	// ------------------------------------------------------------------ fixture
	$sec( 'qualifying items (D1)' );
	$counts0   = BulkGenerator::counts();
	$s_pub     = $make_simple();
	$s_stock   = $make_simple( array( 'manage_stock' => true, 'stock_quantity' => 3 ) );
	$s_priv    = $make_simple( array( 'status' => 'private' ) );
	$s_draft   = $make_simple( array( 'status' => 'draft' ) );
	$s_pend    = $make_simple( array( 'status' => 'pending' ) );
	$s_fut     = $make_simple( array( 'status' => 'future', 'date_created' => time() + 30 * DAY_IN_SECONDS ) );
	$s_trash   = $make_simple();
	wp_trash_post( $s_trash );
	$s_auto    = (int) wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'auto-draft', 'post_title' => 'Auto Draft' ) );
	$s_imp     = (int) wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'importing', 'post_title' => 'PQBG 10 importing' ) );
	$s_coded   = $make_simple();
	( new ProductCodeService() )->get_or_create( $s_coded, $A );
	$coded_row = $code_row( $s_coded );
	wp_set_current_user( $A );
	$tmp_p     = new WC_Product_Simple();
	$tmp_p->set_name( 'PQBG 10 retired only' );
	$tmp_p->set_status( 'publish' );
	$s_retired = (int) $tmp_p->save(); // Saved as the administrator: gets a code on save…
	wp_set_current_user( 0 );
	$ret_row   = $code_row( $s_retired );
	CodeRepository::retire( (int) $ret_row['id'], $A ); // …which is then retired.
	list( $v_pub, $v_pub_vars )     = $make_variable( array(), array( array(), array(), array( 'status' => 'private' ) ) );
	list( $v_draft, $v_draft_vars ) = $make_variable( array( 'status' => 'draft' ), array( array(), array() ) );
	list( $v_trash, $v_trash_vars ) = $make_variable( array(), array( array(), array() ) );
	wp_trash_post( $v_trash );
	list( $v_none ) = $make_variable( array(), array() );
	$grouped  = new WC_Product_Grouped();
	$grouped->set_name( 'PQBG 10 grouped' );
	$grouped->set_status( 'publish' );
	$g_id     = (int) $grouped->save();
	$external = new WC_Product_External();
	$external->set_name( 'PQBG 10 external' );
	$external->set_status( 'publish' );
	$e_id     = (int) $external->save();
	$misplace = (int) wp_insert_post( array( 'post_type' => 'product_variation', 'post_status' => 'publish', 'post_parent' => $s_pub, 'post_title' => 'PQBG 10 variation under a simple product' ) );
	$orphan   = (int) wp_insert_post( array( 'post_type' => 'product_variation', 'post_status' => 'publish', 'post_parent' => 0, 'post_title' => 'PQBG 10 orphan variation' ) );
	wp_set_current_user( 0 );

	$Q        = array_merge( array( $s_pub, $s_stock, $s_priv, $s_draft, $s_pend, $s_fut, $s_retired ), $v_pub_vars, $v_draft_vars );
	$NOT      = array_merge( array( $s_trash, $s_auto, $s_imp, $s_coded, $v_pub, $v_draft, $v_trash, $v_none, $g_id, $e_id, $misplace, $orphan ), $v_trash_vars );
	$fixture  = array_values( array_filter( BulkGenerator::candidates( BulkGenerator::STATUSES, $start_post, 100000 ), static fn( $id ) => $id > $start_post ) );
	sort( $Q );
	pqbg_t( 'fixture: saving as user 0 assigned no code; the administrator\'s save did (then retired); one item was given a code on purpose', null !== $coded_row && null === $code_row( $s_retired ) && 'retired' === CodeRepository::find_by_code( (string) $ret_row['code'] )['status'] && ! array_filter( $Q, static fn( $id ) => null !== $code_row( $id ) ) );
	pqbg_t( 'fixture: the future product really is scheduled and the importing placeholder keeps its status', 'future' === get_post_status( $s_fut ) && 'importing' === get_post_status( $s_imp ) && 'auto-draft' === get_post_status( $s_auto ) );
	pqbg_t( 'qualifying set is exactly: simple (publish, private, draft, pending, future, with or without stock tracking, only a retired code) and the variations (enabled and disabled) of the published and draft variable products', $Q === $fixture, wp_json_encode( array_values( array_diff( $fixture, $Q ) ) ) . ' / missing ' . wp_json_encode( array_values( array_diff( $Q, $fixture ) ) ) );
	pqbg_t( 'never: trash, auto-draft, importing, an item with a code, variable parents, a variable without variations, variations of a trashed parent, grouped, external, a variation under a simple product, an orphan variation', array() === array_intersect( $NOT, $fixture ) );
	$counts1 = BulkGenerator::counts();
	$delta   = static fn( string $type, string $status ) => $counts1[ $type ][ $status ] - $counts0[ $type ][ $status ];
	pqbg_t( 'counts by type and status (a variation counts under its parent\'s status)', 3 === $delta( 'simple', 'publish' ) && 1 === $delta( 'simple', 'private' ) && 1 === $delta( 'simple', 'draft' ) && 1 === $delta( 'simple', 'pending' ) && 1 === $delta( 'simple', 'future' ) && 3 === $delta( 'variation', 'publish' ) && 2 === $delta( 'variation', 'draft' ) && 0 === $delta( 'variation', 'private' ), wp_json_encode( $counts1 ) );
	$drafts = array_values( array_filter( BulkGenerator::candidates( array( 'draft' ), $start_post, 1000 ), static fn( $id ) => $id > $start_post ) );
	pqbg_t( 'status selection: "draft" gives the draft simple product and the draft variable product\'s variations only', array_merge( array( $s_draft ), $v_draft_vars ) == $drafts, wp_json_encode( $drafts ) );
	pqbg_t( 'status selection: invalid or empty selections are refused; unknown values are dropped', is_wp_error( BulkGenerator::statuses( array() ) ) && is_wp_error( BulkGenerator::statuses( array( 'trash', 'auto-draft' ) ) ) && array( 'publish' ) === BulkGenerator::statuses( array( 'publish', 'trash', "x' OR 1=1" ) ) );
	pqbg_t( 'recheck: trashed, grouped, gone and outside the selection are skipped; a qualifying item passes', 'status' === BulkGenerator::recheck( $s_trash, BulkGenerator::STATUSES ) && 'ineligible' === BulkGenerator::recheck( $g_id, BulkGenerator::STATUSES ) && 'gone' === BulkGenerator::recheck( 999999999, BulkGenerator::STATUSES ) && 'status' === BulkGenerator::recheck( $s_draft, array( 'publish' ) ) && 'status' === BulkGenerator::recheck( $v_trash_vars[0], BulkGenerator::STATUSES ) && '' === BulkGenerator::recheck( $v_pub_vars[2], BulkGenerator::STATUSES ) );

	// ------------------------------------------------------------------ generation
	$sec( 'generation in batches, idempotency (D2)' );
	$forget_run();
	pqbg_t( 'a seller cannot start a run', is_wp_error( BulkGenerator::start( BulkGenerator::STATUSES, $SE ) ) && null === BulkGenerator::state() );
	$state = BulkGenerator::start( BulkGenerator::STATUSES, $A );
	pqbg_t( 'start: running, total = the qualifying count, nothing created yet', is_array( $state ) && 'running' === $state['status'] && count( $Q ) === $state['total'] && 0 === $state['created'] && null === $code_row( $s_pub ) );
	$again = BulkGenerator::start( BulkGenerator::STATUSES, $SM );
	pqbg_t( 'a second run cannot start while one is active', is_wp_error( $again ) && 'pqbg_bulk_busy' === $again->get_error_code() );
	$batches = 0;
	while ( is_array( $state ) && 'running' === $state['status'] && $batches < 50 ) {
		$state = BulkGenerator::run_batch( (string) $state['id'], $A, 60.0, 4 );
		++$batches;
	}
	pqbg_t( 'batches of 4: finished in 4 batches with every qualifying item coded once', is_array( $state ) && 'done' === $state['status'] && 4 === $batches && count( $Q ) === $state['created'] && 0 === $state['failed'] && 0 === $state['skipped'], wp_json_encode( is_array( $state ) ? array_intersect_key( $state, array_flip( array( 'status', 'created', 'had_code', 'skipped', 'failed', 'batches' ) ) ) : $state ) );
	pqbg_t( 'every qualifying item has exactly one active code, created by the acting user, in the DC- format', ! array_filter( $Q, static fn( $id ) => 1 !== $active_n( $id ) || (int) $code_row( $id )['created_by'] !== $A || ! ProductQrBarcode\CodeGenerator::is_valid_format( (string) $code_row( $id )['code'] ) ) );
	pqbg_t( 'no excluded item got a code', ! array_filter( array_diff( $NOT, array( $s_coded ) ), static fn( $id ) => 0 !== $active_n( $id ) ) );
	pqbg_t( 'the existing code is unchanged (same row)', (int) $code_row( $s_coded )['id'] === (int) $coded_row['id'] );
	pqbg_t( 'the retired code stays retired; that item got a new, different code', 'retired' === CodeRepository::find_by_code( (string) $ret_row['code'] )['status'] && (string) $code_row( $s_retired )['code'] !== (string) $ret_row['code'] );
	$ids = $state['created_ids'];
	sort( $ids );
	pqbg_t( 'the run remembers exactly the created items (for the print links)', $Q === $ids );
	$log = BulkLog::all()[0] ?? array();
	pqbg_t( 'audit log: "generate", done, created count, by the administrator', BulkLog::TOOL_GENERATE === ( $log['tool'] ?? '' ) && 'done' === $log['details']['outcome'] && count( $Q ) === $log['details']['created'] && $A === $log['user_id'] && 'PQBG10 Admin' === $log['user_name'] );
	$codes_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" );
	$state2       = $run_all( BulkGenerator::STATUSES, $A );
	pqbg_t( 'running again creates nothing and finishes in one batch', is_array( $state2 ) && 'done' === $state2['status'] && 0 === $state2['created'] && 1 === $state2['batches'] && $codes_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" ) );
	pqbg_t( 'a run belonging to another ID is refused', is_wp_error( BulkGenerator::run_batch( 'nope', $A ) ) );
	$raw = $raw_option( BulkGenerator::OPTION );
	pqbg_t( 'the run state and the log are not autoloaded', in_array( $raw['autoload'], array( 'no', 'off' ), true ) && in_array( $raw_option( BulkLog::OPTION )['autoload'], array( 'no', 'off' ), true ) );

	$sec( 'print links (D3)' );
	$chunks = BulkGenerator::print_chunks( array( 'created_ids' => range( 1, 650 ) ) );
	pqbg_t( 'created items are grouped for the print setup: 300 + 300 + 50', array( 300, 300, 50 ) === array_map( 'count', $chunks ) && PrintJob::MAX_ITEMS === 300 );
	$setup = wp_parse_url( PrintAdmin::setup_url( BulkGenerator::print_chunks( $state )[0] ), PHP_URL_QUERY );
	parse_str( (string) $setup, $setup_args );
	pqbg_t( 'a print link of the run is the Phase 8 setup URL for exactly those items, with its nonce', PrintAdmin::SLUG === ( $setup_args['page'] ?? '' ) && implode( ',', $state['created_ids'] ) === ( $setup_args['items'] ?? '' ) && '' !== ( $setup_args[ Permissions::NONCE_FIELD ] ?? '' ) );

	$sec( 'stop, resume, dismiss, stale runs' );
	$forget_run();
	$more = array();
	for ( $i = 0; $i < 6; $i++ ) {
		$more[] = $make_simple();
	}
	$st = BulkGenerator::start( BulkGenerator::STATUSES, $A );
	$st = BulkGenerator::run_batch( (string) $st['id'], $A, 60.0, 2 );
	$st = BulkGenerator::stop( (string) $st['id'] );
	pqbg_t( 'stop: stopped after 2 items; a stopped run does nothing on "continue"', 'stopped' === $st['status'] && 2 === $st['created'] && 2 === BulkGenerator::run_batch( (string) $st['id'], $A, 60.0, 2 )['created'] );
	pqbg_t( 'stop is logged', 'stopped' === ( BulkLog::all()[0]['details']['outcome'] ?? '' ) );
	pqbg_t( 'a stopped run is not active (only an active run blocks a new one)', ! BulkGenerator::is_active( $st ) );
	$st = BulkGenerator::resume( (string) $st['id'] );
	$st = BulkGenerator::run_batch( (string) $st['id'], $A, 60.0, 2 );
	pqbg_t( 'resume continues from the cursor', 'running' === $st['status'] && 4 === $st['created'] );
	// Abandoned (tab closed): no batch for longer than STALE.
	$st['updated'] = time() - BulkGenerator::STALE - 5;
	update_option( BulkGenerator::OPTION, $st, false );
	$stale = BulkGenerator::state();
	pqbg_t( 'a run without a batch for STALE seconds is no longer active', ! BulkGenerator::is_active( $stale ) && 'running' === $stale['status'] );
	$st = BulkGenerator::run_batch( (string) $st['id'], $A, 60.0, 100 );
	pqbg_t( 'an abandoned run can simply be continued: the remaining items get their codes', 'done' === $st['status'] && 6 === $st['created'] && ! array_filter( $more, static fn( $id ) => 1 !== $active_n( $id ) ) );
	pqbg_t( 'dismiss forgets a finished run (its log entry stays)', true === BulkGenerator::dismiss( (string) $st['id'] ) && null === BulkGenerator::state() );
	$m2 = $make_simple();
	$st = BulkGenerator::start( BulkGenerator::STATUSES, $A );
	$st['updated'] = time() - BulkGenerator::STALE - 5;
	update_option( BulkGenerator::OPTION, $st, false );
	$new = BulkGenerator::start( BulkGenerator::STATUSES, $SM );
	pqbg_t( 'a new run may replace an abandoned one; the abandoned one is logged', is_array( $new ) && $new['id'] !== $st['id'] && 'abandoned' === ( BulkLog::all()[0]['details']['outcome'] ?? '' ) );
	$new = BulkGenerator::run_batch( (string) $new['id'], $SM );
	pqbg_t( 'a shop manager may generate: the code is created by the shop manager', 'done' === $new['status'] && 1 === $new['created'] && $SM === (int) $code_row( $m2 )['created_by'] );
	$forget_run();

	$sec( 'a run started or forgotten by another request is seen (state() cache)' );
	$forget_run();
	$m3 = $make_simple(); // So the other request's run has something to do and stays "running".
	pqbg_t( 'this process has looked and cached "no run" (get_option → false)', false === get_option( BulkGenerator::OPTION, false ) && null === BulkGenerator::state() && false === get_option( BulkGenerator::OPTION, false ) );
	$w = $run_workers( array( array( 'start', $A ) ) );
	pqbg_t( 'control: a plain get_option() in this process still says "no run" (stale cache)', '' !== (string) ( $w[0]['id'] ?? '' ) && false === get_option( BulkGenerator::OPTION, false ), wp_json_encode( $w ) );
	$seen = BulkGenerator::state();
	pqbg_t( 'state() sees the run the other request started (same ID, running, by that user)', is_array( $seen ) && $w[0]['id'] === $seen['id'] && 'running' === $seen['status'] && $A === (int) $seen['user_id'] );
	pqbg_t( '…so this process cannot start a second run while it is active', is_wp_error( BulkGenerator::start( BulkGenerator::STATUSES, $A ) ) );
	BulkGenerator::stop( (string) $seen['id'] );
	pqbg_t( 'this process\'s view is cached again (get_option returns the stopped run)', is_array( get_option( BulkGenerator::OPTION, false ) ) );
	$w = $run_workers( array( array( 'dismiss', (string) $seen['id'] ) ) );
	$cached = wp_cache_get( BulkGenerator::OPTION, 'options' ); // WordPress caches the serialized value.
	pqbg_t( 'state() sees that the other request dismissed it (null), although this process\'s cache still holds the run', true === ( $w[0]['ok'] ?? false ) && false !== $cached && is_array( maybe_unserialize( $cached ) ) && null === BulkGenerator::state(), wp_json_encode( $w ) . ' cached: ' . gettype( $cached ) );
	$forget_run();
	( new ProductCodeService() )->get_or_create( $m3, $A );

	$sec( 'a worker that dies mid-batch, then the run continues' );
	$crash = array();
	for ( $i = 0; $i < 8; $i++ ) {
		$crash[] = $make_simple();
	}
	$st  = BulkGenerator::start( BulkGenerator::STATUSES, $A );
	$res = $run_workers( array( array( 'crash', (string) $st['id'], $A, 3 ) ) );
	wp_cache_flush();
	$after_crash = count( array_filter( $crash, static fn( $id ) => 1 === $active_n( $id ) ) );
	$st_crash    = BulkGenerator::state();
	pqbg_t( 'the worker died (exit 3) after committing exactly 3 codes; the batch never saved its state', 3 === (int) $res[0]['exit'] && 3 === $after_crash && 0 === (int) $st_crash['created'] && 0 === (int) $st_crash['cursor'], wp_json_encode( $res ) );
	$t       = microtime( true );
	$st_done = BulkGenerator::run_batch( (string) $st['id'], $A );
	pqbg_t( 'its lock died with it (the next batch does not wait for it)', is_array( $st_done ) && microtime( true ) - $t < BulkGenerator::LOCK_TIMEOUT );
	pqbg_t( 'the next batch gives the other 5 their codes; every item has exactly one', is_array( $st_done ) && 'done' === $st_done['status'] && 5 === $st_done['created'] && ! array_filter( $crash, static fn( $id ) => 1 !== $active_n( $id ) ) );
	$forget_run();

	$sec( 'two workers at once on the same run' );
	$conc = array();
	for ( $i = 0; $i < 30; $i++ ) {
		$conc[] = $make_simple();
	}
	$st  = BulkGenerator::start( BulkGenerator::STATUSES, $A );
	$res = $run_workers( array( array( 'gen', (string) $st['id'], $A, 3 ), array( 'gen', (string) $st['id'], $A, 3 ) ) );
	wp_cache_flush();
	$fin = BulkGenerator::state();
	pqbg_t( 'both workers ended with the run done (batches serialised by the lock)', 'done' === ( $res[0]['status'] ?? '' ) && 'done' === ( $res[1]['status'] ?? '' ), wp_json_encode( $res ) );
	pqbg_t( 'every item has exactly one code and the counts are exact (30 created, 0 had a code)', ! array_filter( $conc, static fn( $id ) => 1 !== $active_n( $id ) ) && 30 === (int) $fin['created'] && 0 === (int) $fin['had_code'] && 0 === (int) $fin['failed'], wp_json_encode( array_intersect_key( (array) $fin, array_flip( array( 'created', 'had_code', 'failed', 'batches' ) ) ) ) );
	pqbg_t( 'no duplicate active codes anywhere (unique index and one row per item)', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT active_product_id FROM $C WHERE active_product_id IS NOT NULL GROUP BY active_product_id HAVING COUNT(*) > 1) d" ) );
	$forget_run();

	// ------------------------------------------------------------------ codes CSV
	$sec( 'codes CSV (D4, D5)' );
	$inj = array(
		'eq'    => $make_simple( array( 'name' => '=HYPERLINK("http://evil.example","x")', 'sku' => '00123' ) ),
		'plus'  => $make_simple( array( 'name' => '+cmd|calc', 'sku' => '-5' ) ),
		'minus' => $make_simple( array( 'name' => '-2+3', 'sku' => '+91-SKU' ) ),
		'at'    => $make_simple( array( 'name' => '@SUM(A1)', 'sku' => 'pqbg10-at' ) ),
		'tab'   => $make_simple( array( 'sku' => 'pqbg10-tab' ) ),
		'cr'    => $make_simple( array( 'sku' => 'pqbg10-cr' ) ),
		'dev'   => $make_simple( array( 'name' => 'साड़ी ₹ Kurta', 'sku' => 'pqbg10-dev' ) ),
	);
	$wpdb->update( $wpdb->posts, array( 'post_title' => "\tTabbed" ), array( 'ID' => $inj['tab'] ) );
	$wpdb->update( $wpdb->posts, array( 'post_title' => "\rCarriage" ), array( 'ID' => $inj['cr'] ) );
	clean_post_cache( $inj['tab'] );
	clean_post_cache( $inj['cr'] );
	$svc = new ProductCodeService();
	foreach ( $inj as $id ) {
		$svc->get_or_create( $id, $A );
	}
	wp_set_current_user( $A );
	CostPrice::set( $inj['eq'], '98765.43' );
	CostPrice::set( $v_pub_vars[0], '87654.32' );
	wp_set_current_user( 0 );
	$e      = $export( array() );
	$rows   = $e['rows'];
	$items  = $by_item( $rows );
	$header = array( 'Item ID', 'Parent ID', 'Type', 'SKU', 'Product', 'Attributes', 'Product status', 'Code', 'Code status', 'Scan URL', 'Code created (' . wp_timezone_string() . ')', 'Retired at (' . wp_timezone_string() . ')' );
	pqbg_t( 'UTF-8 byte order mark and the exact header', str_starts_with( $e['raw'], "\xEF\xBB\xBF" ) && $header === $rows[0], wp_json_encode( $rows[0] ) );
	pqbg_t( 'default: active codes only, one row per coded item', isset( $items[ $s_pub ] ) && 1 === count( $items[ $s_pub ] ) && ! array_filter( array_slice( $rows, 1 ), static fn( $r ) => 'active' !== $r[8] ) );
	$r = $items[ $inj['eq'] ][0];
	pqbg_t( 'a simple product row: ID, parent 0, type, SKU "00123" byte for byte, status, code, scan URL from ScanUrl, created date in the site timezone', (string) $inj['eq'] === $r[0] && '0' === $r[1] && 'simple' === $r[2] && '00123' === $r[3] && 'Published' === $r[6] && $code_row( $inj['eq'] )['code'] === $r[7] && ScanUrl::for_code( $r[7] ) === $r[9] && get_date_from_gmt( $code_row( $inj['eq'] )['created_at_gmt'], 'Y-m-d H:i:s' ) === $r[10] && '' === $r[11] );
	$vr = $items[ $v_pub_vars[2] ][0];
	pqbg_t( 'a variation row: parent ID, type, the parent\'s name, its attributes, "variation disabled"', (string) $v_pub === $vr[1] && 'variation' === $vr[2] && get_the_title( $v_pub ) === $vr[4] && 'Size: S3' === $vr[5] && 'Published; variation disabled' === $vr[6] );
	$wpdb->update( $wpdb->posts, array( 'post_excerpt' => '' ), array( 'ID' => $v_draft_vars[1] ) );
	clean_post_cache( $v_draft_vars[1] );
	pqbg_t( 'attributes come from WooCommerce\'s stored summary, and are rebuilt from the variation when that summary is empty', 'Size: S3' === CodesExport::attributes( $v_pub_vars[2] ) && 'Size: S2' === CodesExport::attributes( $v_draft_vars[1] ) );
	pqbg_t( 'formula injection: = + @ tab CR and "-2+3" get a leading apostrophe', "'=HYPERLINK(\"http://evil.example\",\"x\")" === $items[ $inj['eq'] ][0][4] && "'+cmd|calc" === $items[ $inj['plus'] ][0][4] && "'-2+3" === $items[ $inj['minus'] ][0][4] && "'@SUM(A1)" === $items[ $inj['at'] ][0][4] && "'\tTabbed" === $items[ $inj['tab'] ][0][4] && "'\rCarriage" === $items[ $inj['cr'] ][0][4] );
	pqbg_t( 'a plain number stays a number (SKU "-5"), a SKU starting with + is neutralised', '-5' === $items[ $inj['plus'] ][0][3] && "'+91-SKU" === $items[ $inj['minus'] ][0][3] );
	pqbg_t( 'Devanagari and ₹ survive byte for byte', 'साड़ी ₹ Kurta' === $items[ $inj['dev'] ][0][4] );
	pqbg_t( 'no cost data at all: no cost header, not even the planted costs (exported in-process as the administrator)', ! preg_match( '/cost/i', implode( ',', $rows[0] ) ) && ! str_contains( $e['raw'], '98765' ) && ! str_contains( $e['raw'], '87654' ) );
	$ret = $by_item( $export( array( 'code_status' => 'retired' ) )['rows'] );
	pqbg_t( 'retired export: the retired code, "retired", no scan URL, the retired date', isset( $ret[ $s_retired ] ) && $ret_row['code'] === $ret[ $s_retired ][0][7] && 'retired' === $ret[ $s_retired ][0][8] && '' === $ret[ $s_retired ][0][9] && '' !== $ret[ $s_retired ][0][11] && ! isset( $ret[ $s_pub ] ) );
	$gone = $make_simple( array( 'name' => 'PQBG 10 to delete' ) );
	$svc->get_or_create( $gone, $A );
	$gone_code = $code_row( $gone )['code'];
	wc_get_product( $gone )->delete( true );
	$del = $by_item( $export( array( 'code_status' => 'all', 'product_status' => 'deleted' ) )['rows'] );
	pqbg_t( 'a deleted product\'s (retired) code: "(deleted)", status Deleted, no SKU, no URL; the deleted filter shows only such rows', isset( $del[ $gone ] ) && $gone_code === $del[ $gone ][0][7] && '(deleted)' === $del[ $gone ][0][4] && 'Deleted' === $del[ $gone ][0][6] && '' === $del[ $gone ][0][3] && '' === $del[ $gone ][0][9] && ! isset( $del[ $s_pub ] ) );
	$nocode = $make_simple( array( 'name' => 'PQBG 10 no code yet', 'status' => 'draft' ) );
	$mis    = $by_item( $export( array( 'missing' => '1' ) )['rows'] );
	pqbg_t( '"include items without a code": listed with an empty code and status "none", after the codes', isset( $mis[ $nocode ] ) && '' === $mis[ $nocode ][0][7] && 'none' === $mis[ $nocode ][0][8] && 'Draft' === $mis[ $nocode ][0][6] && ! isset( $items[ $nocode ] ) && ! isset( $mis[ $s_trash ] ) );
	$dr = $by_item( $export( array( 'product_status' => 'draft', 'missing' => '1' ) )['rows'] );
	pqbg_t( 'product status filter: draft only (simple drafts and the draft variable product\'s variations)', isset( $dr[ $s_draft ], $dr[ $v_draft_vars[0] ], $dr[ $nocode ] ) && ! isset( $dr[ $s_pub ] ) && ! isset( $dr[ $v_pub_vars[0] ] ) );
	$vo = $by_item( $export( array( 'type' => 'variation' ) )['rows'] );
	pqbg_t( 'type filter: variations only', isset( $vo[ $v_pub_vars[0] ] ) && ! isset( $vo[ $s_pub ] ) );
	pqbg_t( 'filters: unknown values fall back to the defaults', array( 'code_status' => 'active', 'missing' => false, 'product_status' => '', 'type' => '' ) === CodesExport::filters( array( 'code_status' => "x'", 'missing' => 'yes', 'product_status' => 'auto-draft', 'type' => array( 'simple' ) ) ) );

	// ------------------------------------------------------------------ cost import: parsing
	$sec( 'cost import: numbers (D7)' );
	$amount = static function ( string $in ) {
		$r = CostImport::parse_amount( $in );
		return is_wp_error( $r ) ? 'E:' . $r->get_error_code() : $r['action'] . ':' . $r['value'];
	};
	$cases = array(
		''                => 'blank:',
		'   '             => 'blank:',
		'clear'           => 'clear:',
		'CLEAR'           => 'clear:',
		'1200'            => 'set:1200.00',
		'1200.5'          => 'set:1200.50',
		'1,200.50'        => 'set:1200.50',
		'1,20,000'        => 'set:120000.00',
		'12,34,567.89'    => 'set:1234567.89',
		'1,234,567'       => 'set:1234567.00',
		'₹1200'           => 'set:1200.00',
		'₹ 1,200.50'      => 'set:1200.50',
		'Rs. 1200'        => 'set:1200.00',
		'Rs1200'          => 'set:1200.00',
		'INR 99'          => 'set:99.00',
		'1200 ₹'          => 'set:1200.00',
		'1200/-'          => 'set:1200.00',
		'Rs. 1,200/-'     => 'set:1200.00',
		"\u{00A0}450\u{00A0}" => 'set:450.00',
		'0'               => 'set:0.00',
		'0.00'            => 'set:0.00',
		'="450"'          => 'set:450.00',
		'1200.505'        => 'E:decimals',
		'-5'              => 'E:negative',
		"'-5"             => 'E:negative',
		'1,2'             => 'E:not_number',
		'12,00'           => 'E:not_number',
		'1,2345'          => 'E:not_number',
		'1.2e3'           => 'E:not_number',
		'abc'             => 'E:not_number',
		'12 00'           => 'E:not_number',
		'1234567890123'   => 'E:too_large',
		'.5'              => 'E:not_number',
		'$12'             => 'E:not_number',
	);
	$bad = array();
	foreach ( $cases as $in => $want ) {
		if ( $amount( (string) $in ) !== $want ) {
			$bad[] = wp_json_encode( $in ) . ' → ' . $amount( (string) $in ) . ' (want ' . $want . ')';
		}
	}
	pqbg_t( count( $cases ) . ' number cases: blank, clear, plain, Western and Indian grouping, ₹ / Rs / Rs. / INR before or after, /-, no-break spaces, 0, the ="…" wrapper; errors for more than 2 decimals (never rounded), negatives, bad grouping, scientific notation, text, too many digits', array() === $bad, implode( ' | ', $bad ) );
	pqbg_t( 'unwrap: ="00123" and our own apostrophe are undone; other apostrophes stay', '00123' === CsvUpload::unwrap( '="00123"' ) && '-5' === CsvUpload::unwrap( "'-5" ) && "'abc" === CsvUpload::unwrap( "'abc" ) && 'x' === CsvUpload::unwrap( "\u{00A0} x " ) );

	$sec( 'cost import: files (D10)' );
	$perr = static function ( string $bytes, string $name = 'f.csv' ): string {
		$r = CsvUpload::parse( $bytes, $name );
		return is_wp_error( $r ) ? $r->get_error_message() : 'ok';
	};
	$ok = CsvUpload::parse( "\xEF\xBB\xBFID,Cost price\r\n12,100\r\n\r\n,\r\n13,200\r\n", 'a.csv' );
	pqbg_t( 'BOM removed, CRLF, empty rows ignored, line numbers kept', ! is_wp_error( $ok ) && array( 'ID', 'Cost price' ) === $ok['header'] && 2 === count( $ok['rows'] ) && 2 === $ok['rows'][0]['line'] && 5 === $ok['rows'][1]['line'] && 'UTF-8' === $ok['encoding'] && 64 === strlen( $ok['sha256'] ) );
	$semi = CsvUpload::parse( "SKU;Cost price\nA;1,200.50\n", 's.csv' );
	pqbg_t( 'semicolon files are detected from the header', ! is_wp_error( $semi ) && ';' === $semi['delimiter'] && array( 'A', '1,200.50' ) === $semi['rows'][0]['cells'] );
	$w1252 = CsvUpload::parse( "SKU,Cost price\ncaf\xE9,10\n", 'w.csv' );
	pqbg_t( 'a Windows-1252 file is read as such and flagged', ! is_wp_error( $w1252 ) && 'Windows-1252' === $w1252['encoding'] && 'café' === $w1252['rows'][0]['cells'][0] );
	pqbg_t( 'refused: NUL bytes, empty, header only, too many columns, a cell over 1,000 characters, more than 5,000 rows, over 1 MB', str_contains( $perr( "ID,Cost\n1,\0\n" ), 'not a CSV' ) && str_contains( $perr( '' ), 'empty' ) && str_contains( $perr( "ID,Cost price\n" ), 'no data rows' ) && str_contains( $perr( str_repeat( 'a,', 21 ) . "a\n1\n" ), 'more than 20 columns' ) && str_contains( $perr( "ID,Cost price\n1," . str_repeat( 'x', 1001 ) . "\n" ), 'longer than 1000' ) && str_contains( $perr( "ID,Cost price\n" . str_repeat( "1,1\n", 5001 ) ), 'more than 5000 rows' ) && str_contains( $perr( str_repeat( 'x', CsvUpload::MAX_BYTES + 1 ) ), 'larger than' ) );
	pqbg_t( 'exactly 5,000 rows are accepted', ! is_wp_error( CsvUpload::parse( "ID,Cost price\n" . str_repeat( "1,1\n", 5000 ), 'max.csv' ) ) );
	$hdr = static function ( array $header ) use ( $parsed ): string {
		$r = CostImport::preview( $parsed( array( $header, array_fill( 0, count( $header ), '1' ) ) ) );
		return is_wp_error( $r ) ? $r->get_error_message() : 'ok';
	};
	pqbg_t( 'headers: "Cost price (₹)", "Item ID", "sku" and extra columns are fine; no cost column, no ID/SKU column or a duplicated column is refused', 'ok' === $hdr( array( 'Item ID', 'Parent ID', 'Name', 'Cost price (₹)' ) ) && 'ok' === $hdr( array( 'sku', 'COST' ) ) && str_contains( $hdr( array( 'ID', 'Price' ) ), '"Cost price" column' ) && str_contains( $hdr( array( 'Name', 'Cost price' ) ), '"ID" or a "SKU"' ) && str_contains( $hdr( array( 'ID', 'Cost price', 'Cost' ) ), 'more than one' ) );

	$sec( 'cost import: matching and preview (D7)' );
	wp_set_current_user( $A );
	CostPrice::set( $s_priv, '50.00' );
	wp_set_current_user( 0 );
	$pp = wc_get_product( $s_pub );
	$pp->set_sku( 'PQBG10-Pub' );
	$pp->save();
	$vv = wc_get_product( $v_pub_vars[1] );
	$vv->set_sku( 'pqbg10-var-1' );
	$vv->save();
	$file = $parsed(
		array(
			array( 'ID', 'SKU', 'Name', 'Cost price (₹)' ),
			array( (string) $s_pub, '', 'x', '1,200.50' ),         // 2 update by ID
			array( '', 'pqbg10-pub', 'x', '' ),                   // 3 blank, by SKU (case-insensitive) — but same item as line 2 → duplicate
			array( '', 'PQBG10-VAR-1', 'x', '₹300' ),             // 4 update a variation by SKU
			array( (string) $v_pub, '', 'x', '400' ),             // 5 variable parent: default
			array( (string) $s_priv, '', 'x', 'clear' ),          // 6 clear
			array( (string) $s_draft, '', 'x', 'clear' ),         // 7 clear without a cost: no change
			array( (string) $inj['eq'], '00123', 'x', '98765.43' ), // 8 same value: no change
			array( (string) $s_trash, '', 'x', '10' ),            // 9 trashed
			array( (string) $g_id, '', 'x', '10' ),               // 10 grouped
			array( (string) $misplace, '', 'x', '10' ),           // 11 variation under a simple product
			array( '999999999', '', 'x', '10' ),                  // 12 unknown ID
			array( '', '0042', 'x', '10' ),                       // 13 unknown digits-only SKU (leading-zero hint)
			array( '12a', '', 'x', '10' ),                        // 14 bad ID
			array( (string) $s_stock, 'PQBG10-VAR-1', 'x', '10' ), // 15 ID and SKU disagree
			array( (string) $s_pend, '', 'x', '10.123' ),         // 16 decimals
			array( (string) $s_fut, '', 'x', '-10' ),             // 17 negative
			array( '', '', 'x', '10' ),                           // 18 no key
			array( (string) $v_draft_vars[0], '', 'x', '' ),      // 19 blank
			array( (string) $v_draft_vars[1], '', 'x', '75.5' ),  // 20 update (applied)
		)
	);
	$before_meta = (string) $wpdb->get_var( $wpdb->prepare( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT(post_id, meta_value))), 0)) FROM $PM WHERE meta_key = %s", CostPrice::META_KEY ) );
	pqbg_t( 'a shop manager cannot build a preview', is_wp_error( CostImport::store_preview( $SM, $file ) ) && null === CostImport::load( $SM ) );
	$imp  = CostImport::store_preview( $A, $file );
	$L    = $by_line( $imp['rows'] );
	$want = array(
		2  => array( CostImport::ERROR, 'duplicate' ),
		3  => array( CostImport::ERROR, 'duplicate' ),
		4  => array( CostImport::UPDATE, '' ),
		5  => array( CostImport::UPDATE, '' ),
		6  => array( CostImport::CLEAR, '' ),
		7  => array( CostImport::NO_CHANGE, '' ),
		8  => array( CostImport::NO_CHANGE, '' ),
		9  => array( CostImport::ERROR, 'trashed' ),
		10 => array( CostImport::ERROR, 'bad_type' ),
		11 => array( CostImport::ERROR, 'orphan' ),
		12 => array( CostImport::ERROR, 'no_id' ),
		13 => array( CostImport::ERROR, 'no_sku' ),
		14 => array( CostImport::ERROR, 'bad_id' ),
		15 => array( CostImport::ERROR, 'mismatch' ),
		16 => array( CostImport::ERROR, 'decimals' ),
		17 => array( CostImport::ERROR, 'negative' ),
		18 => array( CostImport::ERROR, 'no_key' ),
		19 => array( CostImport::BLANK, '' ),
		20 => array( CostImport::UPDATE, '' ),
	);
	$wrong = array();
	foreach ( $want as $line => $w ) {
		if ( ! isset( $L[ $line ] ) || array( $L[ $line ][ CostImport::F_OUTCOME ], $L[ $line ][ CostImport::F_REASON ] ) !== $w ) {
			$wrong[] = $line . ': ' . wp_json_encode( $L[ $line ] ?? null );
		}
	}
	pqbg_t( 'every row gets the expected outcome and reason (19 rows)', array() === $wrong && 19 === count( $imp['rows'] ), implode( ' | ', $wrong ) );
	pqbg_t( 'matched items and values: the variation by SKU in another case → 300.00; the variable parent\'s default → 400.00; 75.5 → 75.50; clear shows the old value; the duplicate is the same item by ID and by SKU', $v_pub_vars[1] === $L[4][ CostImport::F_ID ] && '300.00' === $L[4][ CostImport::F_NEW ] && $v_pub === $L[5][ CostImport::F_ID ] && '400.00' === $L[5][ CostImport::F_NEW ] && '75.50' === $L[20][ CostImport::F_NEW ] && '50.00' === $L[6][ CostImport::F_CURRENT ] && '' === $L[6][ CostImport::F_NEW ] && '98765.43' === $L[8][ CostImport::F_CURRENT ] && $s_pub === $L[2][ CostImport::F_ID ] && $s_pub === $L[3][ CostImport::F_ID ] );
	pqbg_t( 'reasons: the duplicate names both lines; decimals names 2; a digits-only unknown SKU mentions Excel (leading zeros), another does not', '2, 3' === $L[2][ CostImport::F_PARAM ] && str_contains( CostImport::reason( 'duplicate', '2, 3' ), 'lines 2, 3' ) && str_contains( CostImport::reason( 'decimals', '2' ), 'More than 2 decimals' ) && str_contains( CostImport::reason( 'no_sku', '', '0042' ), 'Excel' ) && ! str_contains( CostImport::reason( 'no_sku', '', 'AB-1' ), 'Excel' ) );
	pqbg_t( 'counts: 3 updates, 1 clear, 2 no change, 1 blank, 12 errors', array( 'update' => 3, 'clear' => 1, 'nochange' => 2, 'blank' => 1, 'error' => 12 ) === $imp['counts'], wp_json_encode( $imp['counts'] ) );
	$after_meta = (string) $wpdb->get_var( $wpdb->prepare( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT(post_id, meta_value))), 0)) FROM $PM WHERE meta_key = %s", CostPrice::META_KEY ) );
	pqbg_t( 'the preview wrote no cost', $before_meta === $after_meta );
	$stored = get_user_meta( $A, CostImport::META, true );
	pqbg_t( 'stored in the uploader\'s own user meta, compressed, with a token and a 1-hour expiry; no product names stored', is_array( $stored ) && str_starts_with( (string) $stored['data'], 'z:' ) && 32 === strlen( (string) $stored['token'] ) && abs( (int) $stored['expires'] - time() - HOUR_IN_SECONDS ) < 10 && ! str_contains( (string) gzinflate( base64_decode( substr( $stored['data'], 2 ) ) ), 'PQBG 10' ) );
	pqbg_t( 'another administrator has no access to it (their own slot is empty; the token does not work for them)', null === CostImport::load( $A2 ) && is_wp_error( CostImport::start_apply( $A2, (string) $imp['token'], true ) ) && is_wp_error( CostImport::apply_chunk( $A2, (string) $imp['token'] ) ) && is_wp_error( CostImport::cancel( $A2, (string) $imp['token'] ) ) );
	pqbg_t( 'a shop manager cannot apply it, even with the token', is_wp_error( CostImport::start_apply( $SM, (string) $imp['token'], true ) ) && 'pqbg_forbidden' === CostImport::apply_chunk( $SM, (string) $imp['token'] )->get_error_code() );

	$sec( 'cost import: apply (D11)' );
	$na = CostImport::start_apply( $A, (string) $imp['token'], false );
	pqbg_t( 'with errors in the file, applying needs the acknowledgement', is_wp_error( $na ) && 'pqbg_import_ack' === $na->get_error_code() && 'preview' === CostImport::load( $A )['status'] );
	pqbg_t( 'a wrong token is refused', is_wp_error( CostImport::start_apply( $A, 'wrongtoken', true ) ) );
	// Before applying: one cost changes elsewhere (stale), one already has its new value.
	wp_set_current_user( $A );
	CostPrice::set( $v_pub, '399.00' );
	CostPrice::set( $v_pub_vars[1], '300.00' );
	wp_set_current_user( 0 );
	$st = CostImport::start_apply( $A, (string) $imp['token'], true );
	pqbg_t( 'acknowledged: applying', is_array( $st ) && 'applying' === $st['status'] );
	$st = CostImport::apply_chunk( $A, (string) $imp['token'], 3 );
	pqbg_t( 'a chunk of 3 rows stops at row 3 (an interrupted apply resumes from here)', 'applying' === $st['status'] && 3 === $st['position'] );
	wp_cache_flush();
	$st = CostImport::apply_chunk( $A, (string) $imp['token'], 100 );
	pqbg_t( 'the next request finishes it', 'applied' === $st['status'] && 19 === $st['position'] );
	pqbg_t( 'results: 2 applied (an update and the clear), 1 already had its value, 1 skipped because it changed after the preview, 0 failed', array( 'applied' => 2, 'already' => 1, 'changed' => 1, 'failed' => 0 ) === $st['results'], wp_json_encode( $st['results'] ) );
	pqbg_t( 'costs: 75.50 written; the clear removed the cost; the changed one kept 399.00; duplicates, errors and blanks untouched', '75.50' === CostPrice::get( $v_draft_vars[1] ) && '' === CostPrice::get( $s_priv ) && '399.00' === CostPrice::get( $v_pub ) && '' === CostPrice::get( $s_pub ) && '98765.43' === CostPrice::get( $inj['eq'] ) && '' === CostPrice::get( $s_pend ) && '' === CostPrice::get( $s_fut ) && '' === CostPrice::get( $v_draft_vars[0] ) );
	$lg = BulkLog::all()[0];
	pqbg_t( 'audit log: cost_import, applied, file name and SHA-256, counts only (no cost value)', BulkLog::TOOL_COST_IMPORT === $lg['tool'] && 'applied' === $lg['details']['outcome'] && 'costs.csv' === $lg['details']['file'] && $file['sha256'] === $lg['details']['sha256'] && 2 === $lg['details']['changed'] && 12 === $lg['details']['errors_skipped'] && 1 === $lg['details']['skipped_stale'] && 19 === $lg['details']['rows'] && ! str_contains( wp_json_encode( $lg ), '75.5' ) );
	pqbg_t( 'the applied import stays for its report for another hour', 'applied' === CostImport::load( $A )['status'] && CostImport::load( $A )['expires'] > time() + HOUR_IN_SECONDS - 10 );
	$fh = fopen( 'php://temp', 'w+' );
	CostImport::write_report( $fh, CostImport::load( $A ) );
	rewind( $fh );
	$rep_raw = (string) stream_get_contents( $fh );
	fclose( $fh );
	$rep = $csv_rows( substr( $rep_raw, 3 ) );
	$RL  = array_column( array_slice( $rep, 1 ), null, 0 );
	pqbg_t( 'report: BOM, a header and all 19 rows', str_starts_with( $rep_raw, "\xEF\xBB\xBF" ) && 20 === count( $rep ) && 'Line' === $rep[0][0] && 'Result' === $rep[0][8] );
	pqbg_t( 'report rows: an update with before/after and "Applied"; the stale row "Skipped: … changed after the preview"; an error with its reason', '50.00' === $RL['6'][4] && 'Applied' === $RL['6'][8] && str_contains( $RL['5'][8], 'changed after the preview' ) && 'Error' === $RL['9'][6] && str_contains( $RL['9'][7], 'trash' ) && 'Already had this value' === $RL['4'][8] );
	pqbg_t( 'report cells are neutralised (the product named =HYPERLINK… gets an apostrophe)', str_starts_with( $RL['8'][3], "'=" ) );
	CostImport::cancel( $A, (string) CostImport::load( $A )['token'] );
	pqbg_t( 'closing the import deletes it', null === CostImport::load( $A ) && '' === get_user_meta( $A, CostImport::META, true ) );
	$same = CostImport::store_preview( $A, $parsed( array( array( 'ID', 'Cost price' ), array( (string) $v_draft_vars[1], '75.50' ) ) ) );
	pqbg_t( 're-applying the same values: everything is "no change" and there is nothing to apply', array( 'update' => 0, 'clear' => 0, 'nochange' => 1, 'blank' => 0, 'error' => 0 ) === $same['counts'] && 'pqbg_import_nothing' === CostImport::start_apply( $A, (string) $same['token'], true )->get_error_code() );
	CostImport::cancel( $A, (string) $same['token'] );

	$sec( 'cost import: expiry and pruning (addition 1)' );
	$exp = CostImport::store_preview( $A2, $parsed( array( array( 'ID', 'Cost price' ), array( (string) $s_draft, '77777.77' ) ) ) );
	$raw_meta = get_user_meta( $A2, CostImport::META, true );
	$raw_meta['expires'] = time() - 5;
	update_user_meta( $A2, CostImport::META, $raw_meta );
	pqbg_t( 'an expired preview (holding a cost) is stored for the second administrator', is_array( get_user_meta( $A2, CostImport::META, true ) ) && str_contains( (string) gzinflate( base64_decode( substr( get_user_meta( $A2, CostImport::META, true )['data'], 2 ) ) ), '77777.77' ) );
	$live = CostImport::store_preview( $A, $parsed( array( array( 'ID', 'Cost price' ), array( (string) $s_draft, '12.00' ) ) ) );
	pqbg_t( 'prune() removes expired previews of every user and keeps live ones', 1 === CostImport::prune() && '' === get_user_meta( $A2, CostImport::META, true ) && null !== CostImport::load( $A ) );
	$raw_meta['expires'] = time() - 5;
	update_user_meta( $A2, CostImport::META, $raw_meta );
	pqbg_t( 'loading an expired preview deletes it', null === CostImport::load( $A2 ) && '' === get_user_meta( $A2, CostImport::META, true ) );
	CostImport::cancel( $A, (string) $live['token'] );

	$sec( 'cost template (D9)' );
	wp_set_current_user( $A );
	$fh = fopen( 'php://temp', 'w+' );
	$tn = CostImport::write_template( $fh );
	rewind( $fh );
	$tpl_raw = (string) stream_get_contents( $fh );
	fclose( $fh );
	wp_set_current_user( 0 );
	$tpl_all = $csv_rows( substr( $tpl_raw, 3 ) );
	$tpl     = $by_item( $tpl_all );
	pqbg_t( 'template: BOM and the header Item ID, Parent ID, Type, SKU, Product, Attributes, Cost price (₹)', str_starts_with( $tpl_raw, "\xEF\xBB\xBF" ) && array( 'Item ID', 'Parent ID', 'Type', 'SKU', 'Product', 'Attributes', 'Cost price (₹)' ) === $tpl_all[0] && $tn === count( $tpl_all ) - 1, wp_json_encode( $tpl_all[0] ) );
	pqbg_t( 'template rows: simple products, variable products (their default) and their variations with the current cost', '' === $tpl[ $s_pub ][0][6] && 'simple' === $tpl[ $s_pub ][0][2] && '75.50' === $tpl[ $v_draft_vars[1] ][0][6] && 'variable' === $tpl[ $v_pub ][0][2] && '399.00' === $tpl[ $v_pub ][0][6] && 'variation' === $tpl[ $v_pub_vars[0] ][0][2] && '87654.32' === $tpl[ $v_pub_vars[0] ][0][6] && (string) $v_pub === $tpl[ $v_pub_vars[0] ][0][1] );
	pqbg_t( 'template leaves out trash, grouped, external, orphan and misplaced variations', ! isset( $tpl[ $s_trash ] ) && ! isset( $tpl[ $g_id ] ) && ! isset( $tpl[ $e_id ] ) && ! isset( $tpl[ $misplace ] ) && ! isset( $tpl[ $orphan ] ) && ! isset( $tpl[ $v_trash ] ) );
	$round = CostImport::preview( CsvUpload::parse( $tpl_raw, 'cost-prices.csv' ) );
	pqbg_t( 'the template uploaded unchanged is a valid file with no changes and no errors', ! is_wp_error( $round ) && 0 === CostImport::counts( $round )[ CostImport::ERROR ] && 0 === CostImport::counts( $round )[ CostImport::UPDATE ] + CostImport::counts( $round )[ CostImport::CLEAR ], is_wp_error( $round ) ? $round->get_error_message() : wp_json_encode( CostImport::counts( $round ) ) );
	wp_set_current_user( $SM );
	$fh = fopen( 'php://temp', 'w+' );
	pqbg_t( 'a shop manager gets no template rows at all (in-process)', 0 === CostImport::write_template( $fh ) );
	fclose( $fh );
	wp_set_current_user( 0 );

	// ------------------------------------------------------------------ log visibility
	$sec( 'audit log visibility (D12)' );
	$tools_sm = array_unique( array_column( BulkLog::visible( 200, $SM ), 'tool' ) );
	$tools_a  = array_unique( array_column( BulkLog::visible( 200, $A ), 'tool' ) );
	pqbg_t( 'the shop manager sees generation entries but no cost entries', in_array( BulkLog::TOOL_GENERATE, $tools_sm, true ) && ! array_intersect( BulkLog::COST_TOOLS, $tools_sm ) );
	pqbg_t( 'the administrator sees the cost entries too; a seller sees nothing', in_array( BulkLog::TOOL_COST_IMPORT, $tools_a, true ) && array() === BulkLog::visible( 200, $SE ) );
	pqbg_t( 'the log keeps at most 200 entries', 200 === BulkLog::MAX );
	$n_before = count( BulkLog::all() ); // This process now holds the log in its cache.
	$w        = $run_workers( array( array( 'log', $SM ) ) );
	BulkLog::add( BulkLog::TOOL_CODES_EXPORT, array( 'rows' => 2, 'marker' => 'this-process' ), $A );
	$tops = array_slice( BulkLog::all(), 0, 2 );
	pqbg_t( 'an entry another request added meanwhile is kept when this process adds one (the log is re-read before writing)', true === ( $w[0]['ok'] ?? false ) && $n_before + 2 === count( BulkLog::all() ) && 'this-process' === ( $tops[0]['details']['marker'] ?? '' ) && 'other-request' === ( $tops[1]['details']['marker'] ?? '' ), wp_json_encode( $w ) );

	// ------------------------------------------------------------------ HTTP
	$sec( 'HTTP: the QR & Barcodes screen for every role' );
	pqbg_t( 'logins succeed', $login( 'admin', $logins[ $A ], $pw[ $logins[ $A ] ] ) && $login( 'admin2', $logins[ $A2 ], $pw[ $logins[ $A2 ] ] ) && $login( 'sm', $logins[ $SM ], $pw[ $logins[ $SM ] ] ) && $login( 'seller', $logins[ $SE ], $pw[ $logins[ $SE ] ] ) && $login( 'customer', $logins[ $CU ], $pw[ $logins[ $CU ] ] ) );
	$r = $http( 'admin', 'GET', $tab_url() );
	pqbg_t( 'administrator, no tab: the Settings tab (200) with all three tab links', 200 === $r['code'] && str_contains( $r['body'], "name='option_page' value='pqbg_settings'" ) && str_contains( $r['body'], 'tab=settings' ) && str_contains( $r['body'], 'tab=tools' ) && str_contains( $r['body'], 'tab=costs' ) && str_contains( $r['body'], 'nav-tab-active' ) );
	$r = $http( 'admin', 'GET', $tab_url( 'tools' ) );
	$admin_tools = $r['body'];
	pqbg_t( 'administrator, Code tools: 200 with the generate form, the export form and the recent runs (with cost entries)', 200 === $r['code'] && str_contains( $r['body'], 'Generate missing codes' ) && str_contains( $r['body'], 'name="action" value="pqbg_codes_csv"' ) && str_contains( $r['body'], 'Recent bulk runs' ) && str_contains( $r['body'], 'Cost price import' ) && ! str_contains( $r['body'], "name='option_page'" ) );
	pqbg_t( 'the tools stylesheet and script are loaded on this screen', str_contains( $r['body'], 'pqbg-tools.css' ) && str_contains( $r['body'], 'pqbg-tools.js' ) );
	$r = $http( 'admin', 'GET', $tab_url( 'costs' ) );
	pqbg_t( 'administrator, Import cost prices: 200 with the upload form and the template link', 200 === $r['code'] && str_contains( $r['body'], 'enctype="multipart/form-data"' ) && '' !== $link_of( $r['body'], ToolsAdmin::TEMPLATE ) );
	$admin_costs = $r['body'];
	pqbg_t( 'unknown tab: 404', 404 === $http( 'admin', 'GET', $tab_url( 'bogus' ) )['code'] );
	$r = $http( 'sm', 'GET', $tab_url() );
	$sm_page = $r['body'];
	pqbg_t( 'shop manager, no tab: 200, Code tools only', 200 === $r['code'] && str_contains( $r['body'], 'Generate missing codes' ) && ! str_contains( $r['body'], "name='option_page'" ) );
	pqbg_t( 'shop manager: no Settings tab, no cost tab, no link to either, no cost wording or value anywhere on the page', ! str_contains( $r['body'], 'tab=settings' ) && ! str_contains( $r['body'], 'tab=costs' ) && ! str_contains( $wrap( $r['body'] ), 'Import cost prices' ) && ! str_contains( $r['body'], ToolsAdmin::TEMPLATE ) && ! preg_match( '/cost price/i', $wrap( $r['body'] ) ) && ! str_contains( $r['body'], '98765' ) && ! str_contains( $r['body'], '1200.50' ) );
	pqbg_t( 'shop manager: the recent runs show no cost entries', ! str_contains( $r['body'], 'Cost price import' ) && ! str_contains( $r['body'], 'Cost price template' ) && str_contains( $r['body'], 'Generate missing codes' ) );
	pqbg_t( 'shop manager: the Settings tab URL is refused (403)', 403 === $http( 'sm', 'GET', $tab_url( 'settings' ) )['code'] );
	$r = $http( 'sm', 'GET', $tab_url( 'costs' ) );
	pqbg_t( 'shop manager: the cost tab URL is refused (403) and shows no upload form', 403 === $r['code'] && ! str_contains( $r['body'], 'multipart' ) );
	$r = $http( 'sm', 'GET', admin_url( 'index.php' ) );
	pqbg_t( 'shop manager: the WooCommerce menu has the QR & Barcodes item (it opens Code tools)', 200 === $r['code'] && str_contains( $r['body'], 'page=pqbg-settings' ) );
	foreach ( array( 'seller', 'customer' ) as $who ) {
		$r = $http( $who, 'GET', $tab_url( 'tools' ) );
		pqbg_t( "$who: no access to the screen at all (not 200, no form)", 200 !== $r['code'] && ! str_contains( $r['body'], 'Generate missing codes' ) && ! str_contains( $r['body'], 'pqbg_codes_csv' ), $r['code'] . ' ' . $r['location'] );
	}
	$r = $http( 'seller', 'GET', admin_url( 'index.php' ) );
	pqbg_t( 'seller: no QR & Barcodes menu item', ! str_contains( $r['body'], 'page=pqbg-settings' ) );
	$r = $http( 'anon', 'GET', $tab_url( 'tools' ) );
	pqbg_t( 'logged out: redirected to the login page', 302 === $r['code'] && str_contains( $r['location'], 'wp-login.php' ) );

	$sec( 'HTTP: generation' );
	$h1       = $make_simple( array( 'name' => 'PQBG 10 http one' ) );
	$h2       = $make_simple( array( 'name' => 'PQBG 10 http two' ) );
	$forget_run();
	$gen_nonce = $field( $http( 'admin', 'GET', $tab_url( 'tools' ) )['body'], Permissions::NONCE_FIELD );
	$gen_post  = static fn( string $who, array $data ) => $http( $who, 'POST', $post_url, array_merge( array( 'action' => ToolsAdmin::GENERATE ), $data ) );
	pqbg_t( 'the generate form carries a nonce', '' !== $gen_nonce );
	$r = $gen_post( 'admin', array( 'op' => 'start', 'statuses' => BulkGenerator::STATUSES, Permissions::NONCE_FIELD => $gen_nonce ) );
	pqbg_t( 'start without the confirmation box: 400, nothing created', 400 === $r['code'] && null === $code_row( $h1 ) && null === BulkGenerator::state() );
	$r = $gen_post( 'seller', array( 'op' => 'start', 'confirm' => '1', 'statuses' => BulkGenerator::STATUSES, Permissions::NONCE_FIELD => $gen_nonce ) );
	pqbg_t( 'seller with the administrator\'s nonce: 403, nothing created', 403 === $r['code'] && null === $code_row( $h1 ) );
	$r = $gen_post( 'customer', array( 'op' => 'start', 'confirm' => '1', 'statuses' => BulkGenerator::STATUSES, Permissions::NONCE_FIELD => $gen_nonce ) );
	pqbg_t( 'customer with the administrator\'s nonce: 403', 403 === $r['code'] && null === $code_row( $h1 ) );
	$r = $gen_post( 'anon', array( 'op' => 'start', 'confirm' => '1', 'statuses' => BulkGenerator::STATUSES, Permissions::NONCE_FIELD => $gen_nonce ) );
	pqbg_t( 'logged out: refused (no nopriv handler), nothing created', 200 !== $r['code'] && 303 !== $r['code'] && null === $code_row( $h1 ) );
	$r = $gen_post( 'admin', array( 'op' => 'start', 'confirm' => '1', 'statuses' => BulkGenerator::STATUSES, Permissions::NONCE_FIELD => 'bad' ) );
	pqbg_t( 'administrator with a bad nonce: 403', 403 === $r['code'] && null === $code_row( $h1 ) );
	pqbg_t( 'GET on the handler: 405', 405 === $http( 'admin', 'GET', add_query_arg( 'action', ToolsAdmin::GENERATE, $post_url ) )['code'] );
	// Exactly the items this run will code, in ID order (the two new ones and any other item still without a code).
	$expected_print = BulkGenerator::candidates( BulkGenerator::STATUSES, 0, 1000 );
	pqbg_t( 'before the run: the items without a code include both new ones', in_array( $h1, $expected_print, true ) && in_array( $h2, $expected_print, true ) );
	$r = $gen_post( 'admin', array( 'op' => 'start', 'confirm' => '1', 'statuses' => BulkGenerator::STATUSES, Permissions::NONCE_FIELD => $gen_nonce ) );
	pqbg_t( 'administrator: 303 back to Code tools with "finished" (both items in the first batch)', 303 === $r['code'] && str_contains( $r['location'], 'tab=tools' ) && str_contains( $r['location'], 'pqbg_msg=generate_done' ) && null !== $code_row( $h1 ) && null !== $code_row( $h2 ) );
	$r = $http( 'admin', 'GET', $abs( $r['location'] ) );
	$print = preg_match( '/href="([^"]*page=pqbg-print[^"]*)"/', $r['body'], $m ) ? html_entity_decode( $m[1] ) : '';
	pqbg_t( 'the summary shows "Finished", the message and a print link', 200 === $r['code'] && str_contains( $r['body'], 'Finished' ) && str_contains( $r['body'], 'Code generation finished.' ) && '' !== $print );
	$r = $http( 'admin', 'GET', $print );
	$print_items = $field( $r['body'], 'items' );
	pqbg_t( 'the print link opens the Phase 8 setup screen (200) whose hidden items field is exactly the items this run coded, in order', 200 === $r['code'] && implode( ',', $expected_print ) === $print_items && implode( ',', (array) BulkGenerator::state()['created_ids'] ) === $print_items && str_contains( $r['body'], 'name="action" value="pqbg_print_prepare"' ), $r['code'] . ' field "' . $print_items . '" expected "' . implode( ',', $expected_print ) . '"' );
	$run = BulkGenerator::state();
	$r   = $gen_post( 'sm', array( 'op' => 'dismiss', 'run' => (string) $run['id'], Permissions::NONCE_FIELD => $field( $sm_page, Permissions::NONCE_FIELD ) ) );
	pqbg_t( 'the shop manager (own nonce) may dismiss the summary', 303 === $r['code'] && null === BulkGenerator::state() );
	$h3 = $make_simple( array( 'name' => 'PQBG 10 http three' ) );
	$r  = $gen_post( 'sm', array( 'op' => 'start', 'confirm' => '1', 'statuses' => array( 'publish' ), Permissions::NONCE_FIELD => $field( $sm_page, Permissions::NONCE_FIELD ) ) );
	pqbg_t( 'the shop manager may generate over HTTP; the code is theirs', 303 === $r['code'] && $SM === (int) ( $code_row( $h3 )['created_by'] ?? 0 ) );
	$forget_run();

	$sec( 'HTTP: codes CSV' );
	$csv_link = add_query_arg( array( 'action' => CodesExport::ACTION, '_wpnonce' => $field( $admin_tools, '_wpnonce' ), 'code_status' => 'active' ), $post_url );
	$r        = $http( 'admin', 'GET', $csv_link );
	pqbg_t( 'administrator: 200 text/csv attachment with the BOM and no cost', 200 === $r['code'] && str_starts_with( (string) ( $r['headers']['content-type'] ?? '' ), 'text/csv' ) && str_contains( (string) ( $r['headers']['content-disposition'] ?? '' ), 'product-codes-' ) && str_starts_with( $r['body'], "\xEF\xBB\xBF" ) && ! str_contains( $r['body'], '98765' ) && str_contains( $r['body'], (string) $code_row( $s_pub )['code'] ) );
	pqbg_t( 'the export was logged', BulkLog::TOOL_CODES_EXPORT === ( BulkLog::all()[0]['tool'] ?? '' ) );
	$sm_csv = add_query_arg( array( 'action' => CodesExport::ACTION, '_wpnonce' => $field( $sm_page, '_wpnonce' ) ), $post_url );
	$r      = $http( 'sm', 'GET', $sm_csv );
	pqbg_t( 'shop manager (own link): 200, no cost', 200 === $r['code'] && str_contains( $r['body'], (string) $code_row( $s_pub )['code'] ) && ! str_contains( $r['body'], '98765' ) );
	pqbg_t( 'seller and customer with the administrator\'s link: 403', 403 === $http( 'seller', 'GET', $csv_link )['code'] && 403 === $http( 'customer', 'GET', $csv_link )['code'] );
	pqbg_t( 'logged out: no CSV', ! str_contains( $http( 'anon', 'GET', $csv_link )['body'], 'Item ID' ) );
	pqbg_t( 'bad nonce: 403; POST: 405; HEAD: 200 without a body', 403 === $http( 'admin', 'GET', add_query_arg( '_wpnonce', 'bad', $csv_link ) )['code'] && 405 === $http( 'admin', 'POST', $csv_link, array() )['code'] && 200 === ( $hd = $http( 'admin', 'HEAD', $csv_link ) )['code'] && '' === $hd['body'] );

	$sec( 'HTTP: cost import (upload as multipart, preview, apply, report, template)' );
	$up_nonce = $field( $admin_costs, Permissions::NONCE_FIELD );
	$tmpf     = static function ( string $bytes, string $ext = 'csv' ): string {
		$f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pqbg10-' . wp_generate_password( 8, false ) . '.' . $ext;
		file_put_contents( $f, $bytes );
		return $f;
	};
	$upload = static fn( string $who, string $file, string $nonce, string $name = 'costs.csv' ) => $http( $who, 'POST', $post_url, array( 'action' => ToolsAdmin::UPLOAD, Permissions::NONCE_FIELD => $nonce, 'pqbg_file' => new CURLFile( $file, 'text/csv', $name ) ) );
	$good   = $tmpf( "\xEF\xBB\xBFID,SKU,Cost price (₹)\r\n" . $s_draft . ",,\"1,500.00\"\r\n" . $s_pend . ",,₹250\r\n999999999,,5\r\n" );
	$files  = array( $good );
	pqbg_t( 'the upload form carries a nonce', '' !== $up_nonce );
	$r = $upload( 'sm', $good, $up_nonce );
	pqbg_t( 'shop manager uploading with the administrator\'s nonce: 403, no preview stored for anyone', 403 === $r['code'] && null === CostImport::load( $SM ) && null === CostImport::load( $A ) );
	$r = $upload( 'seller', $good, $up_nonce );
	pqbg_t( 'seller uploading: 403', 403 === $r['code'] );
	$r = $upload( 'admin', $files[] = $tmpf( "ID,Cost price\n1,1\n", 'txt' ), $up_nonce, 'costs.txt' );
	pqbg_t( 'a .txt file: 400 with the reason and a link back', 400 === $r['code'] && str_contains( $r['body'], 'Only .csv files' ) && str_contains( $r['body'], 'tab=costs' ) );
	$r = $upload( 'admin', $files[] = $tmpf( str_repeat( 'x', CsvUpload::MAX_BYTES + 10 ) ), $up_nonce );
	pqbg_t( 'a file over 1 MB: 400', 400 === $r['code'] && str_contains( $r['body'], 'larger than' ) );
	$r = $upload( 'admin', $files[] = $tmpf( "ID,Cost price\n1,\0\x01\x02\n" ), $up_nonce );
	pqbg_t( 'a binary file: 400', 400 === $r['code'] && str_contains( $r['body'], 'not a CSV' ) );
	$r = $upload( 'admin', $good, 'bad' );
	pqbg_t( 'a bad nonce: 403', 403 === $r['code'] && null === CostImport::load( $A ) );
	$r = $upload( 'admin', $good, $up_nonce );
	pqbg_t( 'administrator: 303 back to the cost tab; the preview is stored', 303 === $r['code'] && str_contains( $r['location'], 'tab=costs' ) && is_array( $pv = CostImport::load( $A ) ) && array( 'update' => 2, 'clear' => 0, 'nochange' => 0, 'blank' => 0, 'error' => 1 ) === $pv['counts'] );
	pqbg_t( 'the uploaded file is gone from PHP\'s temporary folder and no CSV landed in uploads/', array() === array_diff( $tmp_list(), $tmp_before ) && $up_before === $csv_in_up(), wp_json_encode( array_values( array_diff( $tmp_list(), $tmp_before ) ) ) );
	$r = $http( 'admin', 'GET', $tab_url( 'costs' ) );
	pqbg_t( 'the preview page: counts, the error with its reason, the acknowledgement box, Apply and Cancel, the report link', 200 === $r['code'] && str_contains( $r['body'], 'No product or variation has this ID.' ) && str_contains( $r['body'], 'name="ack"' ) && str_contains( $r['body'], 'Apply 2 changes' ) && '' !== $link_of( $r['body'], ToolsAdmin::REPORT ) );
	$prev_body   = $r['body'];
	$apply_nonce = $field( $prev_body, Permissions::NONCE_FIELD );
	$token       = $field( $prev_body, 'token' );
	$report      = $link_of( $prev_body, ToolsAdmin::REPORT );
	$apply       = static fn( string $who, array $data ) => $http( $who, 'POST', $post_url, array_merge( array( 'action' => ToolsAdmin::APPLY, 'token' => $token, Permissions::NONCE_FIELD => $apply_nonce ), $data ) );
	pqbg_t( 'the page carries the token of the stored preview', $token === (string) $pv['token'] );
	$r = $apply( 'sm', array( 'op' => 'start', 'ack' => '1' ) );
	pqbg_t( 'shop manager applying with the administrator\'s nonce and token: 403, nothing written', 403 === $r['code'] && '' === CostPrice::get( $s_pend ) && 'preview' === CostImport::load( $A )['status'] );
	$r = $apply( 'admin2', array( 'op' => 'start', 'ack' => '1' ) );
	pqbg_t( 'the second administrator with the first one\'s nonce and token: 403 (the nonce is bound to the user), nothing written', 403 === $r['code'] && '' === CostPrice::get( $s_pend ) );
	// The second administrator's own apply nonce comes from a preview of their own.
	$upload( 'admin2', $files[] = $tmpf( "ID,Cost price\n" . $s_fut . ",1\n" ), $field( $http( 'admin2', 'GET', $tab_url( 'costs' ) )['body'], Permissions::NONCE_FIELD ) );
	$a2_page  = $http( 'admin2', 'GET', $tab_url( 'costs' ) )['body'];
	$a2_nonce = $field( $a2_page, Permissions::NONCE_FIELD );
	$r        = $http( 'admin2', 'POST', $post_url, array( 'action' => ToolsAdmin::APPLY, 'token' => $token, Permissions::NONCE_FIELD => $a2_nonce, 'op' => 'start', 'ack' => '1' ) );
	pqbg_t( '…and with their own nonce but the first one\'s token: refused (the token belongs to another user), nothing written by either import', '' !== $a2_nonce && 400 === $r['code'] && str_contains( $r['body'], 'expired or was replaced' ) && '' === CostPrice::get( $s_pend ) && '' === CostPrice::get( $s_fut ) );
	$http( 'admin2', 'POST', $post_url, array( 'action' => ToolsAdmin::APPLY, 'token' => $field( $a2_page, 'token' ), Permissions::NONCE_FIELD => $a2_nonce, 'op' => 'cancel' ) );
	pqbg_t( 'the second administrator closes their own import', null === CostImport::load( $A2 ) );
	pqbg_t( 'the report: shop manager 403 with the administrator\'s link; the second administrator 403 (nonce)', 403 === $http( 'sm', 'GET', $report )['code'] && 403 === $http( 'admin2', 'GET', $report )['code'] );
	$r = $apply( 'admin', array( 'op' => 'start' ) );
	pqbg_t( 'administrator without the acknowledgement: 400, nothing written', 400 === $r['code'] && str_contains( $r['body'], 'Tick the box' ) && '' === CostPrice::get( $s_pend ) );
	$r = $apply( 'admin', array( 'op' => 'start', 'ack' => '1' ) );
	pqbg_t( 'administrator with the acknowledgement: 303 "imported"; costs written through CostPrice', 303 === $r['code'] && str_contains( $r['location'], 'pqbg_msg=import_applied' ) && '1500.00' === CostPrice::get( $s_draft ) && '250.00' === CostPrice::get( $s_pend ) );
	$r = $http( 'admin', 'GET', $report );
	pqbg_t( 'administrator: the report CSV (200) with all rows and results', 200 === $r['code'] && str_starts_with( $r['body'], "\xEF\xBB\xBF" ) && str_contains( $r['body'], 'Applied' ) && str_contains( $r['body'], 'No product or variation has this ID.' ) );
	$tpl_link = $link_of( $admin_costs, ToolsAdmin::TEMPLATE );
	$r        = $http( 'admin', 'GET', $tpl_link );
	pqbg_t( 'administrator: the template CSV (200) with the current costs', 200 === $r['code'] && str_contains( $r['body'], '1500.00' ) && str_contains( $r['body'], '98765.43' ), $r['code'] . ' ' . substr( $r['body'], 0, 120 ) );
	pqbg_t( 'the template download is logged', BulkLog::TOOL_COST_TEMPLATE === ( BulkLog::all()[0]['tool'] ?? '' ) );
	pqbg_t( 'template: shop manager, seller and customer with the administrator\'s link: 403, no cost in the body', ! array_filter( array( 'sm', 'seller', 'customer' ), static fn( $who ) => 403 !== ( $x = $http( $who, 'GET', $tpl_link ) )['code'] || str_contains( $x['body'], '98765' ) ) );
	pqbg_t( 'cost handlers: POST-only ones answer GET with 405, GET-only ones answer POST with 405', 405 === $http( 'admin', 'GET', add_query_arg( 'action', ToolsAdmin::APPLY, $post_url ) )['code'] && 405 === $http( 'admin', 'GET', add_query_arg( 'action', ToolsAdmin::UPLOAD, $post_url ) )['code'] && 405 === $http( 'admin', 'POST', $tpl_link, array() )['code'] );
	$r = $apply( 'admin', array( 'op' => 'cancel' ) );
	pqbg_t( 'closing the import: 303, the preview is deleted', 303 === $r['code'] && null === CostImport::load( $A ) );
	// An expired preview holding a cost is removed when the tools page loads (addition 1).
	CostImport::store_preview( $A2, $parsed( array( array( 'ID', 'Cost price' ), array( (string) $s_draft, '66666.66' ) ) ) );
	$raw_meta            = get_user_meta( $A2, CostImport::META, true );
	$raw_meta['expires'] = time() - 5;
	update_user_meta( $A2, CostImport::META, $raw_meta );
	$r = $http( 'sm', 'GET', $tab_url( 'tools' ) );
	wp_cache_delete( $A2, 'user_meta' );
	pqbg_t( 'the shop manager merely opening Code tools removes another user\'s expired preview (with its cost)', 200 === $r['code'] && '' === get_user_meta( $A2, CostImport::META, true ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s", CostImport::META, '%' . $wpdb->esc_like( '66666' ) . '%' ) ) );
	foreach ( $files as $f ) {
		wp_delete_file( $f );
	}

	$sec( 'HTTP: GET never writes (apart from pruning)' );
	$snap = static fn() => md5( (string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(MAX(id), 0)) FROM $C" ) . '|' . (string) $wpdb->get_var( $wpdb->prepare( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT(post_id, meta_value))), 0)) FROM $PM WHERE meta_key = %s", CostPrice::META_KEY ) ) . '|' . (string) $wpdb->get_var( "SELECT MD5(option_value) FROM {$wpdb->options} WHERE option_name = 'pqbg_bulk_run'" ) );
	$s0   = $snap();
	$http( 'admin', 'GET', $tab_url( 'tools' ) );
	$http( 'admin', 'GET', $tab_url( 'costs' ) );
	$http( 'sm', 'GET', $tab_url( 'tools' ) );
	pqbg_t( 'opening the tabs changes no code, cost or run state', $s0 === $snap() );

	// ------------------------------------------------------------------ volume
	$sec( 'volume: 2,000 items (500 simple + 150 variable × 10 variations)' );
	$forget_run();
	$t0   = microtime( true );
	$vol  = array();
	$vpar = array();
	memory_reset_peak_usage(); // The peak reported below is the volume part's alone.
	for ( $i = 0; $i < 500; $i++ ) {
		$vol[] = $make_simple( array( 'name' => 'PQBG 10 vol ' . $i, 'sku' => 'PQBG10-V-' . $i ) );
		if ( 0 === $i % 50 ) {
			$guard();
			wp_cache_flush(); // Keeps this process's memory flat while building.
		}
	}
	for ( $i = 0; $i < 150; $i++ ) {
		list( $pid, $vids ) = $make_variable( array( 'name' => 'PQBG 10 volvar ' . $i ), array_fill( 0, 10, array() ) );
		$vpar[]             = $pid;
		$vol                = array_merge( $vol, $vids );
		if ( 0 === $i % 10 ) {
			$guard();
			wp_cache_flush();
		}
	}
	$build = microtime( true ) - $t0;
	pqbg_t( '2,000 sellable items created without codes', 2000 === count( $vol ) && ! array_filter( array_slice( $vol, 0, 50 ), static fn( $id ) => null !== $code_row( $id ) ), sprintf( 'built in %.0f s', $build ) );
	// The product saves queued WooCommerce lookup jobs; remove them before timing (as in Phase 9B).
	pqbg_test_as_cleanup( $as_mark );
	wp_cache_flush();
	$mem0              = memory_get_usage();
	$timings['counts'] = $time_ms( static function () use ( &$vc ) { $vc = BulkGenerator::counts(); } );
	pqbg_t( 'counts and preview: 2,000 items without a code, under 1 s', 2000 <= array_sum( $vc['simple'] ) + array_sum( $vc['variation'] ) && $timings['counts'] < 1000, sprintf( '%.0f ms', $timings['counts'] ) );
	$st      = BulkGenerator::start( BulkGenerator::STATUSES, $A );
	$worst   = 0.0;
	$batches = 0;
	$t0      = microtime( true );
	while ( is_array( $st ) && 'running' === $st['status'] && $batches < 100 ) {
		$guard();
		wp_cache_flush();
		$bt    = hrtime( true );
		$st    = BulkGenerator::run_batch( (string) $st['id'], $A );
		$worst = max( $worst, ( hrtime( true ) - $bt ) / 1e6 );
		++$batches;
	}
	$timings['generate_all']   = ( microtime( true ) - $t0 ) * 1000;
	$timings['generate_batch'] = $worst;
	pqbg_t( 'every one of the 2,000 got exactly one code, in batches of 100', is_array( $st ) && 'done' === $st['status'] && 2000 <= $st['created'] && ! array_filter( $vol, static fn( $id ) => 1 !== $active_n( $id ) ), is_array( $st ) ? $st['created'] . ' created in ' . $batches . ' batches' : '' );
	pqbg_t( 'each batch of 100 under 5 s', $worst < 5000, sprintf( 'worst %.0f ms', $worst ) );
	pqbg_t( 'all 2,000 codes under 60 s', $timings['generate_all'] < 60000, sprintf( '%.1f s', $timings['generate_all'] / 1000 ) );
	$forget_run();
	wp_cache_flush();
	$rows_n = 0;
	$timings['export'] = $time_ms(
		static function () use ( &$rows_n ) {
			$fh     = fopen( 'php://temp', 'w+' );
			$rows_n = CodesExport::write( $fh, CodesExport::filters( array() ), true );
			fclose( $fh );
		}
	);
	pqbg_t( 'codes export of 2,000+ rows under 5 s', $rows_n >= 2000 && $timings['export'] < 5000, sprintf( '%d rows, %.0f ms', $rows_n, $timings['export'] ) );
	$cost_rows = array( array( 'ID', 'Cost price' ) );
	foreach ( $vol as $i => $id ) {
		$cost_rows[] = array( (string) $id, number_format( 100 + $i, 2, '.', ',' ) );
	}
	$big = $parsed( $cost_rows, 'volume.csv' );
	wp_cache_flush();
	$timings['cost_preview'] = $time_ms( static function () use ( &$big_imp, $A, $big ) { $big_imp = CostImport::store_preview( $A, $big ); } );
	pqbg_t( 'cost preview of 2,000 rows under 5 s (2,000 updates, stored within the 1 MB packet limit)', is_array( $big_imp ) && 2000 === $big_imp['counts']['update'] && $timings['cost_preview'] < 5000 && strlen( (string) get_user_meta( $A, CostImport::META, true )['data'] ) < CostImport::MAX_STORED, is_wp_error( $big_imp ) ? $big_imp->get_error_message() : sprintf( '%.0f ms, %d bytes stored', $timings['cost_preview'], strlen( (string) get_user_meta( $A, CostImport::META, true )['data'] ) ) );
	wp_cache_flush();
	$timings['cost_apply'] = $time_ms(
		static function () use ( &$ap, $A, $big_imp ) {
			$ap = CostImport::start_apply( $A, (string) $big_imp['token'], false );
			while ( is_array( $ap ) && 'applying' === $ap['status'] ) {
				$ap = CostImport::apply_chunk( $A, (string) $big_imp['token'] );
			}
		}
	);
	pqbg_t( 'cost apply of 2,000 rows under 20 s, every one applied', is_array( $ap ) && 'applied' === $ap['status'] && 2000 === $ap['results']['applied'] && '100.00' === CostPrice::get( $vol[0] ) && '2099.00' === CostPrice::get( $vol[1999] ) && $timings['cost_apply'] < 20000, sprintf( '%.0f ms', $timings['cost_apply'] ) );
	CostImport::cancel( $A, (string) $big_imp['token'] );
	$r = $http( 'admin', 'GET', $csv_link );
	$timings['export_http'] = $r['time'] * 1000;
	pqbg_t( 'the codes export over HTTP (2,000+ rows) answers 200', 200 === $r['code'] && substr_count( $r['body'], "\n" ) > 2000, sprintf( '%.0f ms', $timings['export_http'] ) );
	$peak = memory_get_peak_usage() / 1048576;
	pqbg_t( 'peak PHP memory of the volume part under 256 MB', $peak < 256, sprintf( '%.1f MB (memory_get_peak_usage since the volume part started)', $peak ) );
	$timings['volume_peak_mb'] = $peak;
	$timings['build_s']        = $build;
	echo 'TIMINGS: ' . wp_json_encode( array_map( static fn( $v ) => round( $v, 1 ), $timings ) ) . "\n";

	// ------------------------------------------------------------------ scope
	$sec( 'scope' );
	$src  = static function ( string $file ): string {
		$code = '';
		foreach ( token_get_all( (string) file_get_contents( PQBG_PLUGIN_DIR . $file ) ) as $t ) {
			$code .= is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t;
		}
		return $code;
	};
	$new  = array( 'includes/BulkGenerator.php', 'includes/BulkLog.php', 'includes/CodesExport.php', 'includes/CsvUpload.php', 'includes/CostImport.php', 'includes/ToolsAdmin.php' );
	$all  = implode( "\n", array_map( $src, $new ) );
	pqbg_t( 'no nopriv handlers, AJAX actions, REST routes, shortcodes or rewrite rules', ! preg_match( '/admin_post_nopriv|wp_ajax_|register_rest_route|add_shortcode|add_rewrite/', $all ) );
	pqbg_t( 'no raw $wpdb writes in the new classes (codes through ProductCodeService, costs through CostPrice::set(), options and user meta through the WordPress API)', ! preg_match( '/\$wpdb->(insert|update|query|delete|replace)\b/', $all ) );
	pqbg_t( 'codes are created only through ProductCodeService (no CodeRepository::create_active / replace_active / retire)', ! preg_match( '/create_active|replace_active|CodeRepository::retire/', $all ) && str_contains( $src( 'includes/BulkGenerator.php' ), '->get_or_create(' ) );
	pqbg_t( 'the cost meta key never appears in the new classes; costs are written only by CostPrice::set()', ! str_contains( $all, '_pqbg_cost_price' ) && ! preg_match( '/update_post_meta|add_post_meta|delete_post_meta/', $all ) && str_contains( $src( 'includes/CostImport.php' ), 'CostPrice::set(' ) );
	pqbg_t( 'the sale path is untouched (no reference to the sale classes or stock changes)', ! preg_match( '/SaleService|SaleRepository|wc_update_product_stock|StockLock::/', $all ) );
	pqbg_t( 'scan URLs only from ScanUrl, and the codes export has no cost reference', str_contains( $src( 'includes/CodesExport.php' ), 'ScanUrl::for_code(' ) && ! preg_match( '/cost/i', $src( 'includes/CodesExport.php' ) ) );
	pqbg_t( 'the only new hooks: 6 admin_post handlers and admin_enqueue_scripts in ToolsAdmin::register(), plus the page\'s load- hook in SettingsPage; no filters', 7 === preg_match_all( '/add_action\(/', $src( 'includes/ToolsAdmin.php' ) ) && ! preg_match( '/add_filter\(/', $all ) && ! preg_match( '/add_action\(/', implode( "\n", array_map( $src, array_diff( $new, array( 'includes/ToolsAdmin.php' ) ) ) ) ) && str_contains( $src( 'includes/SettingsPage.php' ), "add_action( 'load-' . \$hook, array( ToolsAdmin::class, 'load_page' ) )" ) );
	pqbg_t( 'ToolsAdmin is registered only for admin requests', (bool) preg_match( '/if \( is_admin\(\) \) \{.*ToolsAdmin::register\(\);.*\}/s', $src( 'includes/Plugin.php' ) ) );
	$un = $src( 'uninstall.php' );
	pqbg_t( 'uninstall: previews and the run state always removed; the log only with delete-all', (bool) preg_match( "/delete_metadata\( 'user', 0, 'pqbg_cost_import', '', true \);\s*delete_option\( 'pqbg_bulk_run' \);\s*if \( ! defined/", $un ) && strpos( $un, "delete_option( 'pqbg_bulk_log' )" ) > strpos( $un, 'PQBG_UNINSTALL_DELETE_ALL_DATA' ) );
	pqbg_t( 'direct HTTP to the new files: empty output', ! array_filter( array_merge( $new, array( 'assets/pqbg-tools.css' ) ), static fn( $f ) => str_ends_with( $f, '.php' ) && '' !== $http( 'anon', 'GET', PQBG_PLUGIN_URL . $f )['body'] ) );
} catch ( Throwable $e ) {
	pqbg_t( 'suite ran without an exception', false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
	// ------------------------------------------------------------------ cleanup
	pqbg_section( 'cleanup' );
	wp_set_current_user( 0 );
	$handles = array();
	$posts   = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d ORDER BY post_type = 'product_variation' DESC, ID DESC", $start_post ) );
	foreach ( $posts as $pid ) {
		$p = in_array( get_post_type( (int) $pid ), array( 'product', 'product_variation' ), true ) ? wc_get_product( (int) $pid ) : null;
		if ( $p ) {
			$p->delete( true );
		} else {
			wp_delete_post( (int) $pid, true );
		}
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM $PM WHERE post_id > %d", $start_post ) ); // Meta of posts WooCommerce could not load (placeholders).
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
	pqbg_t( 'cleanup: no render-cache entries added by this suite left', array() === array_diff( $svg_rows(), $svg_before ) );
	pqbg_t( 'cleanup: codes and posts back to the start', $start_c === $max( $C, 'id' ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID > %d", $start_post ) ) );
	pqbg_t( 'cleanup: no test users, cost meta or cost-import previews left', $base_user === (int) count_users()['total_users'] && $base_cost === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $PM WHERE meta_key = %s", CostPrice::META_KEY ) ) && $base_prev === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", CostImport::META ) ) );
	pqbg_t( 'cleanup: the run state and the log are back to what they were (byte-identical or absent)', ! array_filter( $saved, static fn( $raw, $name ) => $raw !== $raw_option( $name ), ARRAY_FILTER_USE_BOTH ) );
	pqbg_t( 'cleanup: no uploaded file left in PHP\'s temporary folder, no CSV in uploads/', array() === array_diff( $tmp_list(), $tmp_before ) && $up_before === $csv_in_up() );
	pqbg_t( 'cleanup: the posts AUTO_INCREMENT only moved by the posts this suite created (no jump)', $posts_ai() - $start_ai < 10000, $start_ai . ' → ' . $posts_ai() );
	pqbg_test_as_check( $as_mark );
}

pqbg_test_done();
