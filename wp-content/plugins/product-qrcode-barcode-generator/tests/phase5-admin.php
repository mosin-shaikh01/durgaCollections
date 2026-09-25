<?php
/**
 * Phase 5 suite: admin code management.
 *
 * Automatic assignment on every product save path (classic edit screen, AJAX
 * variations, Quick Edit, Bulk Edit, REST, CSV import, Duplicate), the
 * lifecycle (trash, untrash, delete, Empty Trash, type changes), atomic and
 * concurrent regeneration, the admin UI and handlers over real HTTP, downloads
 * with round-trip decoding, barcodes disabled, the 40-variation performance
 * budget, and scope.
 *
 * HTTP checks log in as temporary users against home_url(). Round-trip checks
 * need the decoder (see tests/README.md) and SKIP without it.
 *
 * Creates products, variations, code rows, users and a temporary directory,
 * and removes them all. pqbg_settings is restored to its exact stored value.
 *
 *   php tests/phase5-admin.php
 *   php tests/phase5-admin.php --worker <item> <user> <expected|0> <start>   (internal: concurrency)
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/bootstrap.php';

// Concurrency worker: regenerates one item at an agreed start time and prints the result as JSON.
if ( isset( $argv[1] ) && '--worker' === $argv[1] ) {
	pqbg_test_load_wp();
	$start = (float) $argv[5];
	while ( microtime( true ) < $start ) {
		usleep( 2000 );
	}
	$expected = (int) $argv[4];
	$result   = ( new ProductQrBarcode\ProductCodeService() )->regenerate( (int) $argv[2], (int) $argv[3], $expected > 0 ? $expected : null );
	echo wp_json_encode( is_wp_error( $result ) ? array( 'error' => $result->get_error_code() ) : array( 'code' => $result['code'] ) ), "\n";
	exit( 0 );
}

pqbg_test_load_wp();
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/post.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use ProductQrBarcode\{AdminActions, AdminProductPanel, CodeGenerator, CodeLifecycle, CodeRepository, Plugin, ProductCodeService, QrRenderer, BarcodeRenderer, ScanUrl, Schema};

global $wpdb;

$vendor_classes = static fn( string $lib ) => array_values( preg_grep( '/^ProductQrBarcode\\\\Vendor\\\\' . preg_quote( $lib, '/' ) . '\\\\/', array_merge( get_declared_classes(), get_declared_interfaces(), get_declared_traits() ) ) );

$C           = Schema::codes_table();
$as_table    = $wpdb->prefix . 'actionscheduler_actions';
$option      = Plugin::SETTINGS_OPTION;
$raw_setting = static fn() => $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $option ), ARRAY_A );
$original    = $raw_setting();
$start_id    = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $C" );
$start_as    = (int) $wpdb->get_var( "SELECT COALESCE(MAX(action_id), 0) FROM $as_table" );
$start_post  = (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID), 0) FROM {$wpdb->posts}" );
$base_c      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" );
$base_prod   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')" );
$base_user   = (int) count_users()['total_users'];
$tmp         = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pqbg-p5-' . wp_generate_password( 8, false );
$user_ids    = array();
$pw          = array();
$home        = untrailingslashit( home_url() );
$timings     = array();

mkdir( $tmp, 0700, true );

// In-memory logger: WooCommerce re-reads this filter on every wc_get_logger() call.
$logger = new class() extends WC_Logger {
	/** @var array<int, array{0: string, 1: string}> */
	public array $entries = array();

	public function log( $level, $message, $context = array() ) {
		$this->entries[] = array( (string) $level, (string) $message );
	}
};
add_filter( 'woocommerce_logging_class', static fn() => $logger );

$set = static function ( array $values ) use ( $option ) {
	update_option( $option, array_merge( Plugin::default_settings(), $values ), false );
	wp_cache_delete( $option, 'options' );
};

/** Drops runtime caches after another process (an HTTP request) changed the database. */
$sync = static function () {
	wp_cache_flush();
};

$active = static fn( int $id ) => CodeRepository::find_active_for_product( $id );
$rows   = static fn( int $id ) => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $C WHERE product_id = %d ORDER BY id", $id ), ARRAY_A );
$count  = static fn() => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" );
$sum    = static fn() => (string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, code, status, COALESCE(active_product_id, 0), COALESCE(retired_by, -1), COALESCE(retired_at_gmt, '')))), 0)) FROM $C" );

// Decoder (same as Phase 4).
$decoder = getenv( 'PQBG_DECODER' );
$decoder = ( is_string( $decoder ) && '' !== $decoder ) ? $decoder : __DIR__ . '/decoder/decode.mjs';
$node_ok = '' !== trim( (string) shell_exec( 'node --version 2>&1' ) ) && str_starts_with( trim( (string) shell_exec( 'node --version 2>&1' ) ), 'v' );
$dec_ok  = $node_ok && is_file( $decoder ) && is_dir( dirname( $decoder ) . '/node_modules' );
$skip_why = ! $node_ok ? 'Node.js not on PATH' : 'decoder not installed (run `npm ci` in tests/decoder, or set PQBG_DECODER)';
$decode  = static function ( array $svgs ) use ( $decoder, $tmp ): ?array {
	$files = array();
	foreach ( $svgs as $key => $svg ) {
		$files[ $key ] = $tmp . DIRECTORY_SEPARATOR . 'svg-' . count( $files ) . '.svg';
		file_put_contents( $files[ $key ], $svg );
	}
	$out = shell_exec( 'node ' . escapeshellarg( $decoder ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $files ) ) . ' 2>&1' );
	foreach ( $files as $f ) {
		unlink( $f );
	}
	$json = json_decode( (string) $out, true );
	if ( ! is_array( $json ) ) {
		echo "   decoder output: $out\n";
		return null;
	}
	$by_file = array_column( $json, 'results', 'file' );
	$result  = array();
	foreach ( $files as $key => $f ) {
		$result[ $key ] = $by_file[ $f ] ?? array();
	}
	return $result;
};

// HTTP client: one cookie jar (curl handle) per user.
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
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_POST           => 'POST' === $method,
			CURLOPT_HTTPGET        => 'GET' === $method,
			CURLOPT_HTTPHEADER     => $headers,
		)
	);
	if ( 'POST' === $method ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, is_array( $post ) ? http_build_query( $post ) : (string) $post );
	}
	$raw  = (string) curl_exec( $ch );
	$size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	$head = substr( $raw, 0, $size );
	$hdrs = array();
	foreach ( preg_split( '/\r?\n/', $head ) as $line ) {
		if ( preg_match( '/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m ) ) {
			$hdrs[ strtolower( $m[1] ) ] = trim( $m[2] );
		}
	}
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
/** The part of $html starting at $marker (e.g. a form's opening tag). */
$from = static function ( string $html, string $marker ): string {
	$pos = strpos( $html, $marker );
	return false === $pos ? '' : substr( $html, $pos );
};
/** Every decoded href containing all $needles. */
$links = static function ( string $html, string ...$needles ): array {
	preg_match_all( '/href=["\']([^"\']+)["\']/i', $html, $m );
	$out = array();
	foreach ( $m[1] as $href ) {
		$href = html_entity_decode( $href, ENT_QUOTES );
		if ( ! array_filter( $needles, static fn( $n ) => ! str_contains( $href, $n ) ) ) {
			$out[] = str_starts_with( $href, 'http' ) ? $href : admin_url( ltrim( $href, '/' ) );
		}
	}
	return array_values( array_unique( $out ) );
};
/** A nonce from a localised script object: "key":"abc123". */
$js_nonce = static function ( string $html, string $key ): string {
	return preg_match( '/"' . preg_quote( $key, '/' ) . '":"([a-f0-9]+)"/', $html, $m ) ? $m[1] : '';
};
/** Rendered product-code SVGs in a page: QR codes and barcodes (barcodes carry a <text>). */
$svgs = static function ( string $html ): array {
	preg_match_all( '/<svg\b[^>]*aria-label="DC-[^"]*"[^>]*>.*?<\/svg>/s', $html, $m );
	return array(
		'qr'      => count( array_filter( $m[0], static fn( $s ) => ! str_contains( $s, '<text' ) ) ),
		'barcode' => count( array_filter( $m[0], static fn( $s ) => str_contains( $s, '<text' ) ) ),
	);
};

$edit_url  = static fn( int $id ) => admin_url( "post.php?post={$id}&action=edit" );
$edit_page = static fn( string $who, int $id ) => $http( $who, 'GET', $edit_url( $id ) );

/** Saves the product through the classic edit screen, like the browser does. */
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
			'post_title'             => 'PQBG P5 classic ' . $id,
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
$status_args = array(
	'draft'   => array( 'save' => 'Save Draft', 'post_status' => 'draft', 'publish' => null ),
	'pending' => array( 'save' => 'Save as Pending', 'post_status' => 'pending', 'publish' => null ),
	'private' => array( 'publish' => 'Publish', 'visibility' => 'private' ),
	'publish' => array( 'publish' => 'Publish', 'visibility' => 'public' ),
);

/** GET post-new.php: returns the auto-draft's ID and the page. */
$post_new = static function ( string $who ) use ( $http, $field ): array {
	$r = $http( $who, 'GET', admin_url( 'post-new.php?post_type=product' ) );
	return array( (int) $field( $r['body'], 'post_ID' ), $r );
};

$make_simple = static function ( int $as, string $status = 'publish', string $type = 'simple' ): int {
	wp_set_current_user( $as );
	$class = WC_Product_Factory::get_product_classname( 0, $type );
	$p     = new $class();
	$p->set_name( 'PQBG P5 ' . $type . ' ' . wp_generate_password( 6, false ) );
	$p->set_status( $status );
	if ( 'external' !== $type && 'grouped' !== $type ) {
		$p->set_regular_price( '10' );
	}
	$id = $p->save();
	wp_set_current_user( 0 );
	return (int) $id;
};
$make_variable = static function ( int $n, int $as, string $status = 'publish' ): array {
	wp_set_current_user( $as );
	$opts = array_map( static fn( $i ) => 'S' . $i, range( 1, max( 1, $n ) ) );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( $opts );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$p = new WC_Product_Variable();
	$p->set_name( 'PQBG P5 variable ' . wp_generate_password( 6, false ) );
	$p->set_status( $status );
	$p->set_attributes( array( $attr ) );
	$pid  = (int) $p->save();
	$vids = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $pid );
		$v->set_attributes( array( 'size' => $opts[ $i ] ) );
		$v->set_regular_price( '10' );
		$v->set_sku( 'PQBG-P5-' . $pid . '-' . $opts[ $i ] );
		$vids[] = (int) $v->save();
	}
	wp_set_current_user( 0 );
	return array( $pid, $vids );
};
$children = static fn( int $pid ) => array_map( 'intval', get_posts( array( 'post_parent' => $pid, 'post_type' => 'product_variation', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) ) );

/** Renders the meta box in-process as a user. */
$render_box = static function ( int $as, int $id ): string {
	wp_set_current_user( $as );
	ob_start();
	AdminProductPanel::render_meta_box( get_post( $id ) );
	$html = (string) ob_get_clean();
	wp_set_current_user( 0 );
	return $html;
};

try {
	pqbg_section( 'setup' );
	foreach ( array( 'admin' => 'administrator', 'sm' => 'shop_manager', 'nocap' => 'shop_manager', 'seller' => 'pqbg_seller', 'gone' => 'administrator' ) as $who => $role ) {
		$pw[ $who ]       = wp_generate_password( 24, false );
		$user_ids[ $who ] = wp_insert_user( array( 'user_login' => "pqbg_p5_{$who}", 'user_pass' => $pw[ $who ], 'user_email' => "pqbg-p5-{$who}@example.invalid", 'role' => $role, 'display_name' => "P5 {$who}" ) );
	}
	pqbg_t( 'temporary users created', 5 === count( array_filter( $user_ids, 'is_int' ) ) );
	( new WP_User( $user_ids['nocap'] ) )->add_cap( 'pqbg_manage_codes', false );
	$A  = $user_ids['admin'];
	$SM = $user_ids['sm'];
	pqbg_t( 'nocap: a shop manager with pqbg_manage_codes denied at user level', user_can( $user_ids['nocap'], 'edit_products' ) && ! user_can( $user_ids['nocap'], 'pqbg_manage_codes' ) && user_can( $SM, 'pqbg_manage_codes' ) && ! user_can( $user_ids['seller'], 'pqbg_manage_codes' ) );
	$site_up = 200 === $http( 'anon', 'GET', wp_login_url() )['code'];
	pqbg_t( 'site reachable over HTTP', $site_up, wp_login_url() );
	pqbg_t( 'logins succeed (admin, shop manager, nocap, seller)', $login( 'admin', 'pqbg_p5_admin', $pw['admin'] ) && $login( 'sm', 'pqbg_p5_sm', $pw['sm'] ) && $login( 'nocap', 'pqbg_p5_nocap', $pw['nocap'] ) && $login( 'seller', 'pqbg_p5_seller', $pw['seller'] ) );
	pqbg_t( 'block product editor is disabled (classic screen only)', ! Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'product_block_editor' ) );
	pqbg_t( 'lifecycle hooks registered on every request (CLI included)', 20 === has_action( 'woocommerce_new_product', array( CodeLifecycle::class, 'on_product_saved' ) ) && 20 === has_action( 'woocommerce_update_product_variation', array( CodeLifecycle::class, 'on_variation_saved' ) ) && 10 === has_action( 'deleted_post', array( CodeLifecycle::class, 'on_deleted_post' ) ) );
	pqbg_t( 'admin UI and handlers are not registered outside wp-admin', false === has_action( 'add_meta_boxes_product', array( AdminProductPanel::class, 'add_meta_box' ) ) && false === has_action( 'admin_post_' . AdminActions::IMAGE, array( AdminActions::class, 'handle_image' ) ) );
	pqbg_t( 'barcodes disabled at start (default)', false === ProductQrBarcode\Settings::is_barcode_enabled() );

	pqbg_section( 'barcodes disabled: no library on any Phase 5 path (in-process)' );
	$bd_simple  = $make_simple( $A );
	$bd_none    = $make_simple( 0 );
	$bd_var     = $make_variable( 2, $A );
	$bd_grouped = $make_simple( $A, 'publish', 'grouped' );
	$html       = $render_box( $A, $bd_simple ) . $render_box( $A, $bd_none ) . $render_box( $A, $bd_var[0] ) . $render_box( $A, $bd_grouped );
	wp_set_current_user( $A );
	ob_start();
	AdminProductPanel::render_variation_code( 0, array(), get_post( $bd_var[1][0] ) );
	AdminProductPanel::render_column( 'pqbg_code', $bd_simple );
	$html .= (string) ob_get_clean();
	( new ProductCodeService() )->regenerate( $bd_simple, $A );
	wp_set_current_user( 0 );
	pqbg_t( 'simple panel rendered one QR and no barcode', 1 === $svgs( $render_box( $A, $bd_simple ) )['qr'] && 0 === $svgs( $html )['barcode'] && ! str_contains( $html, 'pqbg-download-barcode' ) );
	pqbg_t( 'no Picqer class loaded after every panel, the variation text, the column, save hooks and regeneration', array() === $vendor_classes( 'Picqer' ), implode( ',', $vendor_classes( 'Picqer' ) ) );
	pqbg_t( 'QR library was loaded (the check is live)', array() !== $vendor_classes( 'BaconQrCode' ) );

	pqbg_section( 'auto-assignment: classic edit screen (HTTP)' );
	[ $ad, $r ] = $post_new( 'admin' );
	$sync();
	pqbg_t( 'post-new.php creates an auto-draft with no code', $ad > 0 && 'auto-draft' === get_post_status( $ad ) && array() === $rows( $ad ) );
	pqbg_t( 'the new-product screen says to save first, no buttons', str_contains( $r['body'], 'Save the product to assign its product code.' ) && ! str_contains( $r['body'], 'pqbg-generate' ) );
	pqbg_t( 'manual generation refuses an auto-draft', 'pqbg_ineligible_status' === ( new ProductCodeService() )->get_or_create( $ad, $A )->get_error_code() && array() === $rows( $ad ) );
	$by_status = array();
	foreach ( array( 'draft', 'pending', 'private', 'publish' ) as $st ) {
		[ $id ] = $post_new( 'admin' );
		$r      = $classic_save( 'admin', $id, $status_args[ $st ] );
		$sync();
		$row              = $active( $id );
		$by_status[ $st ] = $id;
		pqbg_t( "first real save as {$st}: saved and one code, created_by = the saving user", 302 === $r['code'] && $st === get_post_status( $id ) && null !== $row && (int) $row['created_by'] === $A && 1 === count( $rows( $id ) ), "status " . get_post_status( $id ) . ", HTTP {$r['code']}" );
	}
	$first = $active( $by_status['draft'] );
	foreach ( array( 'draft', 'publish', 'publish' ) as $st ) {
		$classic_save( 'admin', $by_status['draft'], $status_args[ $st ] );
	}
	$sync();
	pqbg_t( 'repeated saves (draft, publish, publish) are idempotent: same code, one row', $first['code'] === $active( $by_status['draft'] )['code'] && 1 === count( $rows( $by_status['draft'] ) ) );
	wp_set_current_user( $A );
	for ( $i = 0; $i < 5; $i++ ) {
		wc_get_product( $by_status['draft'] )->save();
	}
	wp_set_current_user( 0 );
	pqbg_t( '5 more CRUD saves still one row', 1 === count( $rows( $by_status['draft'] ) ) );

	pqbg_section( 'auto-assignment: AJAX variations (HTTP)' );
	[ $vp ] = $make_variable( 0, $A );
	$page   = $edit_page( 'admin', $vp );
	$nonce  = $js_nonce( $page['body'], 'add_variation_nonce' );
	$r      = $http( 'admin', 'POST', admin_url( 'admin-ajax.php' ), array( 'action' => 'woocommerce_add_variation', 'post_id' => $vp, 'loop' => 0, 'security' => $nonce ) );
	$sync();
	$new_v = $children( $vp );
	$vrow  = $new_v ? $active( $new_v[0] ) : null;
	pqbg_t( '"Add variation" on a saved variable product: the variation gets its code immediately', '' !== $nonce && 200 === $r['code'] && 1 === count( $new_v ) && null !== $vrow && (int) $vrow['parent_id'] === $vp );
	pqbg_t( 'the returned variation panel already shows the code, without an image', null !== $vrow && str_contains( $r['body'], $vrow['code'] ) && str_contains( $r['body'], 'pqbg-view-qr' ) && 0 === $svgs( $r['body'] )['qr'] );

	[ $adv, $page ] = $post_new( 'admin' );
	$r              = $http( 'admin', 'POST', admin_url( 'admin-ajax.php' ), array( 'action' => 'woocommerce_add_variation', 'post_id' => $adv, 'loop' => 0, 'security' => $js_nonce( $page['body'], 'add_variation_nonce' ) ) );
	$sync();
	$adv_children = $children( $adv );
	pqbg_t( '"Add variation" on an unsaved (auto-draft) product: variation saved, no code yet', 200 === $r['code'] && 1 === count( $adv_children ) && null === $active( $adv_children[0] ) );
	wp_set_current_user( $A );
	$tmp_parent = new WC_Product_Variable( $adv );
	$tmp_parent->save(); // Like WooCommerce's save_attributes AJAX: variable, still an auto-draft.
	wp_set_current_user( 0 );
	$sync();
	pqbg_t( '...still none while the parent is a variable auto-draft', 'auto-draft' === get_post_status( $adv ) && null === $active( $adv_children[0] ) && 'pqbg_ineligible_status' === ProductCodeService::eligibility( $adv_children[0] )->get_error_code() );
	$r = $classic_save( 'admin', $adv, array( 'product-type' => 'variable' ) + $status_args['publish'] );
	$sync();
	pqbg_t( '...and gets it on the parent\'s first real save (parent sweep); the parent gets none', 302 === $r['code'] && null !== $active( $adv_children[0] ) && null === $active( $adv ) );

	[ $sv, $sv_v ] = $make_variable( 2, 0 );
	pqbg_t( 'variations created without an acting user have no code', null === $active( $sv_v[0] ) && null === $active( $sv_v[1] ) );
	$page          = $edit_page( 'admin', $sv );
	$r             = $http( 'admin', 'POST', admin_url( 'admin-ajax.php' ), array( 'action' => 'woocommerce_save_variations', 'security' => $js_nonce( $page['body'], 'save_variations_nonce' ), 'product_id' => $sv, 'product-type' => 'variable', 'variable_post_id' => array( $sv_v[0] ), 'variable_menu_order' => array( 0 ), 'variable_regular_price' => array( '11' ), 'variable_sku' => array( 'PQBG-P5-SV-' . $sv_v[0] ), 'attribute_size' => array( 'S1' ) ) );
	$sync();
	pqbg_t( '"Save changes" in the variations panel assigns the saved variation its code', 200 === $r['code'] && null !== $active( $sv_v[0] ) && '11' === wc_get_product( $sv_v[0] )->get_regular_price( 'edit' ) );

	pqbg_section( 'auto-assignment: Quick Edit, Bulk Edit (HTTP)' );
	$qe   = $make_simple( 0 );
	$qe2  = $make_simple( 0 );
	$qe3  = $make_simple( 0 );
	pqbg_t( 'products created with no acting user have no code', null === $active( $qe ) && null === $active( $qe2 ) && null === $active( $qe3 ) );
	$list = $http( 'admin', 'GET', admin_url( 'edit.php?post_type=product' ) );
	$now  = current_time( 'timestamp' );
	$r    = $http(
		'admin',
		'POST',
		admin_url( 'admin-ajax.php' ),
		array(
			'action'                       => 'inline-save',
			'_inline_edit'                 => $field( $list['body'], '_inline_edit' ),
			'post_ID'                      => $qe,
			'post_type'                    => 'product',
			'post_title'                   => 'PQBG P5 quick edited',
			'post_name'                    => 'pqbg-p5-quick-' . $qe,
			'_status'                      => 'publish',
			'mm'                           => gmdate( 'm', $now ),
			'jj'                           => gmdate( 'd', $now ),
			'aa'                           => gmdate( 'Y', $now ),
			'hh'                           => gmdate( 'H', $now ),
			'mn'                           => gmdate( 'i', $now ),
			'ss'                           => gmdate( 's', $now ),
			'screen'                       => 'edit-product',
			'post_view'                    => 'list',
			'woocommerce_quick_edit'       => '1',
			'woocommerce_quick_edit_nonce' => $field( $list['body'], 'woocommerce_quick_edit_nonce' ),
			'_regular_price'               => '12',
		)
	);
	$sync();
	pqbg_t( 'Quick Edit assigns a code', 200 === $r['code'] && 'PQBG P5 quick edited' === get_the_title( $qe ) && null !== $active( $qe ) );
	$filter = $from( $list['body'], '<form id="posts-filter"' );
	$query  = http_build_query(
		array(
			'post_type'                    => 'product',
			'action'                       => 'edit',
			'bulk_edit'                    => 'Update',
			'post'                         => array( $qe2, $qe3 ),
			'_wpnonce'                     => $field( $filter, '_wpnonce' ),
			'_status'                      => '-1',
			'woocommerce_bulk_edit'        => '1',
			'woocommerce_quick_edit_nonce' => $field( $list['body'], 'woocommerce_quick_edit_nonce' ),
			'change_regular_price'         => '1',
			'change_stock'                 => '',
			'_regular_price'               => '13',
		)
	);
	$r      = $http( 'admin', 'GET', admin_url( 'edit.php?' . $query ) );
	$sync();
	pqbg_t( 'Bulk Edit assigns a code to every edited product', 302 === $r['code'] && null !== $active( $qe2 ) && null !== $active( $qe3 ) && '13' === wc_get_product( $qe3 )->get_regular_price( 'edit' ) );

	pqbg_section( 'auto-assignment: REST API (HTTP, cookie + wp_rest nonce)' );
	$rest_nonce = trim( $http( 'admin', 'GET', admin_url( 'admin-ajax.php?action=rest-nonce' ) )['body'] );
	$rest       = static fn( string $method, string $route, array $body = array() ) => $http( 'admin', 'POST', rest_url( 'wc/v3/' . $route ), $body, array( 'X-WP-Nonce: ' . $rest_nonce, 'X-HTTP-Method-Override: ' . $method ) );
	$r          = $rest( 'POST', 'products', array( 'name' => 'PQBG P5 REST simple', 'type' => 'simple', 'status' => 'pending', 'regular_price' => '5' ) );
	$rest_s     = (int) ( json_decode( $r['body'], true )['id'] ?? 0 );
	pqbg_t( 'REST: a new simple product (pending) gets a code', 201 === $r['code'] && $rest_s > 0 && null !== $active( $rest_s ) );
	$r      = $rest( 'POST', 'products', array( 'name' => 'PQBG P5 REST variable', 'type' => 'variable', 'status' => 'publish', 'attributes' => array( array( 'name' => 'Size', 'options' => array( 'A', 'B' ), 'variation' => true, 'visible' => true ) ) ) );
	$rest_v = (int) ( json_decode( $r['body'], true )['id'] ?? 0 );
	$r      = $rest( 'POST', "products/{$rest_v}/variations", array( 'regular_price' => '7', 'attributes' => array( array( 'name' => 'Size', 'option' => 'A' ) ) ) );
	$rest_x = (int) ( json_decode( $r['body'], true )['id'] ?? 0 );
	pqbg_t( 'REST: a variation gets a code; its variable parent none', 201 === $r['code'] && $rest_x > 0 && null !== $active( $rest_x ) && null === $active( $rest_v ) );
	$code_before = $active( $rest_s )['code'] ?? '';
	$r           = $rest( 'PUT', "products/{$rest_s}", array( 'regular_price' => '6' ) );
	pqbg_t( 'REST: an update keeps the same code (idempotent)', 200 === $r['code'] && $code_before === ( $active( $rest_s )['code'] ?? '' ) && 1 === count( $rows( $rest_s ) ) );
	$r = $rest( 'DELETE', "products/{$rest_s}?force=true" );
	$sync();
	$gone = $rows( $rest_s );
	pqbg_t( 'REST: force delete retires the code, retired_by = the API user', 200 === $r['code'] && ! get_post( $rest_s ) && 1 === count( $gone ) && 'retired' === $gone[0]['status'] && (int) $gone[0]['retired_by'] === $A );

	pqbg_section( 'auto-assignment: WooCommerce CSV import (in-process, as admin)' );
	require_once WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';
	$tag = strtoupper( wp_generate_password( 6, false ) );
	$csv = $tmp . DIRECTORY_SEPARATOR . 'import.csv';
	$fh  = fopen( $csv, 'w' );
	fputcsv( $fh, array( 'type', 'sku', 'name', 'published', 'regular_price', 'parent_id', 'attributes:name1', 'attributes:value1', 'attributes:visible1', 'attributes:taxonomy1' ), ',', '"', '' );
	fputcsv( $fh, array( 'simple', "P5CSV-S-$tag", 'PQBG P5 CSV simple', '1', '9', '', '', '', '', '' ), ',', '"', '' );
	fputcsv( $fh, array( 'variable', "P5CSV-V-$tag", 'PQBG P5 CSV variable', '1', '', '', 'Size', 'S, M', '1', '0' ), ',', '"', '' );
	fputcsv( $fh, array( 'variation', "P5CSV-V-$tag-S", '', '1', '9', "P5CSV-V-$tag", 'Size', 'S', '', '0' ), ',', '"', '' );
	fputcsv( $fh, array( 'variation', "P5CSV-V-$tag-M", '', '1', '9', "P5CSV-V-$tag", 'Size', 'M', '', '0' ), ',', '"', '' );
	fclose( $fh );
	$import = static function ( bool $update ) use ( $csv, $A ) {
		wp_set_current_user( $A );
		$result = ( new WC_Product_CSV_Importer( $csv, array( 'parse' => true, 'update_existing' => $update, 'prevent_timeouts' => false, 'lines' => -1 ) ) )->import();
		wp_set_current_user( 0 );
		return $result;
	};
	$res  = $import( false );
	$csv_s = wc_get_product_id_by_sku( "P5CSV-S-$tag" );
	$csv_p = wc_get_product_id_by_sku( "P5CSV-V-$tag" );
	$csv_v = array( wc_get_product_id_by_sku( "P5CSV-V-$tag-S" ), wc_get_product_id_by_sku( "P5CSV-V-$tag-M" ) );
	pqbg_t( 'CSV import: 4 rows imported, none failed', 2 === count( $res['imported'] ?? array() ) && 2 === count( $res['imported_variations'] ?? array() ) && array() === ( $res['failed'] ?? array( 'x' ) ), wp_json_encode( array_map( static fn( $x ) => is_array( $x ) ? array_map( static fn( $e ) => is_wp_error( $e ) ? $e->get_error_code() . ':' . $e->get_error_message() : $e, $x ) : $x, $res ) ) );
	pqbg_t( 'CSV import: simple and both variations have codes; the variable parent has none', null !== $active( $csv_s ) && null !== $active( $csv_v[0] ) && null !== $active( $csv_v[1] ) && null === $active( $csv_p ) );
	$before = $sum();
	$res    = $import( true );
	pqbg_t( 'CSV re-import (update existing): same codes, no new rows', 4 === count( $res['updated'] ?? array() ) + count( $res['updated_variations'] ?? array() ) && $before === $sum() );
	pqbg_t( 'CSV: a variation before its parent is rejected by WooCommerce 11.1.2 itself (placeholder parent), so it cannot get a code first', str_contains( (string) file_get_contents( WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php' ), 'woocommerce_product_importer_variation_parent_missing' ) );

	pqbg_section( 'auto-assignment: Duplicate (HTTP)' );
	$dup_src = $make_simple( $A );
	$page    = $edit_page( 'admin', $dup_src );
	$dl      = $links( $page['body'], 'action=duplicate_product', 'post=' . $dup_src );
	$r       = $dl ? $http( 'admin', 'GET', $dl[0] ) : array( 'code' => 0, 'location' => '' );
	parse_str( (string) wp_parse_url( $r['location'], PHP_URL_QUERY ), $q );
	$dup = (int) ( $q['post'] ?? 0 );
	$sync();
	pqbg_t( 'Duplicate (simple): the copy is a draft with its own, different code; the original keeps its code', 302 === $r['code'] && $dup > 0 && 'draft' === get_post_status( $dup ) && null !== $active( $dup ) && $active( $dup )['code'] !== $active( $dup_src )['code'] && 1 === count( $rows( $dup_src ) ) );
	[ $dvp, $dvv ] = $make_variable( 2, $A );
	$page          = $edit_page( 'admin', $dvp );
	$dl            = $links( $page['body'], 'action=duplicate_product', 'post=' . $dvp );
	$r             = $dl ? $http( 'admin', 'GET', $dl[0] ) : array( 'code' => 0, 'location' => '' );
	parse_str( (string) wp_parse_url( $r['location'], PHP_URL_QUERY ), $q );
	$dup_v = (int) ( $q['post'] ?? 0 );
	$sync();
	$dup_children = $children( $dup_v );
	$orig_codes   = array_column( array_map( $active, $dvv ), 'code' );
	$dup_codes    = array_column( array_filter( array_map( $active, $dup_children ) ), 'code' );
	pqbg_t( 'Duplicate (variable): each copied variation has its own new code, none shared', 2 === count( $dup_children ) && 2 === count( $dup_codes ) && array() === array_intersect( $orig_codes, $dup_codes ) && null === $active( $dup_v ) );
	pqbg_t( 'codes are not stored in meta, so nothing was copied', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id > %d AND ( meta_key LIKE %s OR meta_value LIKE %s )", $start_post, '%pqbg%', 'DC-____-____-____' ) ) );

	pqbg_section( 'revisions, cron and users without pqbg_manage_codes' );
	$rev_src = $make_simple( $A );
	$rev     = (int) _wp_put_post_revision( get_post( $rev_src ) );
	CodeLifecycle::on_product_saved( $rev );
	pqbg_t( 'a revision gets no code, even when the save hook is called with it', $rev > 0 && 'revision' === get_post_type( $rev ) && array() === $rows( $rev ) );
	$logger->entries = array();
	$cron            = $make_simple( 0 );
	pqbg_t( 'no user (cron/CLI): the save succeeds, no code, no error, no notice', $cron > 0 && 'publish' === get_post_status( $cron ) && null === $active( $cron ) && array() === $logger->entries && false === get_transient( CodeLifecycle::NOTICE_TRANSIENT . '0' ) );
	[ $nc ] = $post_new( 'nocap' );
	$r      = $classic_save( 'nocap', $nc, $status_args['publish'] );
	$sync();
	$page = $edit_page( 'nocap', $nc );
	pqbg_t( 'shop manager without pqbg_manage_codes: the save succeeds, no code, no error', 302 === $r['code'] && 'publish' === get_post_status( $nc ) && array() === $rows( $nc ) && false === get_transient( CodeLifecycle::NOTICE_TRANSIENT . $user_ids['nocap'] ) );
	pqbg_t( '...and they see no panel, no column and no failure notice', 200 === $page['code'] && ! str_contains( $page['body'], 'id="pqbg-codes"' ) && ! str_contains( $page['body'], 'pqbg-save-failure' ) && ! str_contains( $http( 'nocap', 'GET', admin_url( 'edit.php?post_type=product' ) )['body'], 'column-pqbg_code' ) );

	pqbg_section( 'generation failure during a save' );
	$logger->entries = array();
	CodeLifecycle::set_service( new ProductCodeService( new CodeGenerator( null, static fn() => true ) ) );
	$fail = $make_simple( $A );
	CodeLifecycle::set_service( null );
	pqbg_t( 'the save is not blocked: product saved and published, no code', $fail > 0 && 'publish' === get_post_status( $fail ) && null === $active( $fail ) );
	pqbg_t( 'logged by error code only', array( array( 'error', 'Product code generation failed: pqbg_code_generation_failed' ) ) === $logger->entries );
	wp_set_current_user( $A );
	ob_start();
	CodeLifecycle::failure_notice();
	$n1 = (string) ob_get_clean();
	ob_start();
	CodeLifecycle::failure_notice();
	$n2 = (string) ob_get_clean();
	wp_set_current_user( 0 );
	pqbg_t( 'the saving user gets one dismissible notice, shown once', str_contains( $n1, 'is-dismissible' ) && str_contains( $n1, 'pqbg_code_generation_failed' ) && ! preg_match( '/DC-[A-Z0-9]{4}/', $n1 ) && '' === $n2 );
	$r = ( new ProductCodeService() )->get_or_create( $fail, $A );
	pqbg_t( 'the item can be given a code later by someone permitted', is_array( $r ) && null !== $active( $fail ) );

	pqbg_section( 'lifecycle: trash, untrash, delete (HTTP)' );
	$lc   = $make_simple( $A );
	$lc0  = $active( $lc );
	$page = $edit_page( 'admin', $lc );
	$tl   = $links( $page['body'], 'action=trash', 'post=' . $lc );
	$r    = $tl ? $http( 'admin', 'GET', $tl[0] ) : array( 'code' => 0 );
	$sync();
	pqbg_t( 'trash: the code stays active, same row', 302 === $r['code'] && 'trash' === get_post_status( $lc ) && $lc0 === $active( $lc ) );
	$trash_list = $http( 'admin', 'GET', admin_url( 'edit.php?post_status=trash&post_type=product' ) );
	$ul         = $links( $trash_list['body'], 'action=untrash', 'post=' . $lc . '&' );
	$r          = $ul ? $http( 'admin', 'GET', $ul[0] ) : array( 'code' => 0 );
	$sync();
	pqbg_t( 'untrash: same code, unchanged', 302 === $r['code'] && 'trash' !== get_post_status( $lc ) && $lc0 === $active( $lc ) && 1 === count( $rows( $lc ) ) );
	$page = $edit_page( 'admin', $lc );
	$http( 'admin', 'GET', $links( $page['body'], 'action=trash', 'post=' . $lc )[0] ?? admin_url() );
	$trash_list = $http( 'admin', 'GET', admin_url( 'edit.php?post_status=trash&post_type=product' ) );
	$del        = $links( $trash_list['body'], 'action=delete', 'post=' . $lc . '&' );
	$r          = $del ? $http( 'admin', 'GET', $del[0] ) : array( 'code' => 0 );
	$sync();
	$lr = $rows( $lc );
	pqbg_t( 'permanent delete: the code is retired with retired_at and retired_by = the deleting user', 302 === $r['code'] && ! get_post( $lc ) && 1 === count( $lr ) && 'retired' === $lr[0]['status'] && null === $lr[0]['active_product_id'] && (int) $lr[0]['retired_by'] === $A && '' !== (string) $lr[0]['retired_at_gmt'] );

	[ $dp, $dpv ] = $make_variable( 3, $A );
	$dp_codes     = array_column( array_map( $active, $dpv ), 'code' );
	wp_set_current_user( $A );
	wp_trash_post( $dp );
	wp_set_current_user( 0 );
	pqbg_t( 'trashing a variable product (WooCommerce trashes its variations) keeps every variation code active', 3 === count( array_filter( array_map( $active, $dpv ) ) ) );
	wp_set_current_user( $SM );
	wp_delete_post( $dp, true );
	wp_set_current_user( 0 );
	$retired = array_merge( ...array_map( $rows, $dpv ) );
	pqbg_t( 'deleting the variable parent retires all 3 variation codes, retired_by = the user', 3 === count( $retired ) && 3 === count( array_filter( $retired, static fn( $x ) => 'retired' === $x['status'] && (int) $x['retired_by'] === $SM ) ) && ! array_filter( array_map( 'get_post', $dpv ) ) );

	$et1          = $make_simple( $A );
	$et2          = $make_simple( $A, 'draft' );
	[ $etp, $etv ] = $make_variable( 2, $A );
	wp_set_current_user( $A );
	foreach ( array( $et1, $et2, $etp ) as $x ) {
		wp_trash_post( $x );
	}
	wp_set_current_user( 0 );
	$trash_list = $http( 'admin', 'GET', admin_url( 'edit.php?post_status=trash&post_type=product' ) );
	$r          = $http( 'admin', 'GET', admin_url( 'edit.php?' . http_build_query( array( 'post_type' => 'product', 'post_status' => 'trash', '_wpnonce' => $field( $from( $trash_list['body'], '<form id="posts-filter"' ), '_wpnonce' ), 'delete_all' => 'Empty Trash' ) ) ) );
	$sync();
	$et_rows = array_merge( ...array_map( $rows, array( $et1, $et2, $etv[0], $etv[1] ) ) );
	pqbg_t( 'Empty Trash retires the codes of every deleted product and variation', 302 === $r['code'] && 4 === count( $et_rows ) && 4 === count( array_filter( $et_rows, static fn( $x ) => 'retired' === $x['status'] && (int) $x['retired_by'] === $A ) ) && ! get_post( $et1 ) && ! get_post( $etp ) );

	pqbg_section( 'lifecycle: type changes' );
	$t1  = $make_simple( $A );
	$t1c = $active( $t1 );
	wp_set_current_user( 0 );
	$orph = new WC_Product_Variation();
	$orph->set_parent_id( $t1 );
	$orph_id = (int) $orph->save(); // A variation added while the product is still simple in the database.
	$r       = $classic_save( 'admin', $t1, array( 'product-type' => 'variable' ) + $status_args['publish'] );
	$sync();
	$t1r = $rows( $t1 );
	pqbg_t( 'simple → variable (edit screen): the parent\'s code is retired, retired_by = the user', 302 === $r['code'] && wc_get_product( $t1 )->is_type( 'variable' ) && null === $active( $t1 ) && 1 === count( $t1r ) && 'retired' === $t1r[0]['status'] && $t1r[0]['code'] === $t1c['code'] && (int) $t1r[0]['retired_by'] === $A );
	pqbg_t( '...and its variation gets a code on that save', null !== $active( $orph_id ) && (int) $active( $orph_id )['parent_id'] === $t1 );
	$t2 = $make_simple( $A );
	$r  = $classic_save( 'admin', $t2, array( 'product-type' => 'grouped' ) + $status_args['publish'] );
	$sync();
	pqbg_t( 'simple → grouped (edit screen): code retired, none created', 302 === $r['code'] && wc_get_product( $t2 )->is_type( 'grouped' ) && null === $active( $t2 ) && 'retired' === ( $rows( $t2 )[0]['status'] ?? '' ) );
	$t3 = $make_simple( $A );
	wp_set_current_user( $SM );
	$ext = new WC_Product_External( $t3 );
	$ext->set_product_url( 'https://example.com/p' );
	$ext->save();
	wp_set_current_user( 0 );
	pqbg_t( 'simple → external (CRUD): code retired, retired_by = the user', null === $active( $t3 ) && (int) ( $rows( $t3 )[0]['retired_by'] ?? -1 ) === $SM );
	[ $t4, $t4v ] = $make_variable( 2, $A );
	$t4_old       = array_column( array_map( $active, $t4v ), 'code' );
	$r            = $classic_save( 'admin', $t4, array( 'product-type' => 'simple' ) + $status_args['publish'] );
	$sync();
	$t4_ret = array_merge( ...array_map( $rows, $t4v ) );
	pqbg_t( 'variable → simple: WooCommerce deletes the variations and their codes are retired', 302 === $r['code'] && ! array_filter( array_map( 'get_post', $t4v ) ) && 2 === count( array_filter( $t4_ret, static fn( $x ) => 'retired' === $x['status'] && (int) $x['retired_by'] === $A ) ) );
	pqbg_t( '...and the product gets a new code, never a reused one', null !== $active( $t4 ) && ! in_array( $active( $t4 )['code'], $t4_old, true ) && 0 === (int) $active( $t4 )['parent_id'] );
	[ $t5, $t5v ] = $make_variable( 1, $A );
	$r            = $classic_save( 'admin', $t5, array( 'product-type' => 'grouped' ) + $status_args['publish'] );
	$sync();
	pqbg_t( 'variable → grouped: variation code retired, the grouped product gets none', 302 === $r['code'] && 'retired' === ( $rows( $t5v[0] )[0]['status'] ?? '' ) && array() === $rows( $t5 ) );
	pqbg_t( 'no item anywhere has two active codes', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT product_id FROM $C WHERE status = 'active' GROUP BY product_id HAVING COUNT(*) > 1) x" ) );

	pqbg_section( 'regeneration (service and repository)' );
	$svc  = new ProductCodeService();
	$rg   = $make_simple( $A );
	$old  = $active( $rg );
	$new  = $svc->regenerate( $rg, $SM, (int) $old['id'] );
	$rgr  = $rows( $rg );
	$oldr = CodeRepository::find_by_code( $old['code'] );
	pqbg_t( 'regenerate: a new, different active code', is_array( $new ) && $new['code'] !== $old['code'] && CodeGenerator::is_valid_format( $new['code'] ) && $new === $active( $rg ) );
	pqbg_t( 'the old code is retired with retired_at and retired_by, kept in history', 'retired' === $oldr['status'] && null === $oldr['active_product_id'] && (int) $oldr['retired_by'] === $SM && '' !== (string) $oldr['retired_at_gmt'] && 2 === count( $rgr ) );
	pqbg_t( 'exactly one active code after regeneration', 1 === count( array_filter( $rgr, static fn( $x ) => 'active' === $x['status'] ) ) );
	pqbg_t( 'a stale expected id changes nothing (pqbg_code_changed)', ( function () use ( $svc, $rg, $old, $sum, $A ) {
		$s = $sum();
		$r = $svc->regenerate( $rg, $A, (int) $old['id'] );
		return is_wp_error( $r ) && 'pqbg_code_changed' === $r->get_error_code() && $s === $sum();
	} )() );
	pqbg_t( 'forbidden users (seller, nocap, 0) change nothing', ( function () use ( $svc, $rg, $sum, $user_ids ) {
		$s = $sum();
		foreach ( array( $user_ids['seller'], $user_ids['nocap'], 0 ) as $u ) {
			if ( 'pqbg_forbidden' !== $svc->regenerate( $rg, $u )->get_error_code() ) {
				return false;
			}
		}
		return $s === $sum();
	} )() );
	pqbg_t( 'no active code: pqbg_no_active_code; ineligible: refused', 'pqbg_no_active_code' === $svc->regenerate( $make_simple( 0 ), $A )->get_error_code() && 'pqbg_ineligible_product' === $svc->regenerate( $bd_var[0], $A )->get_error_code() );

	$alphabet = CodeGenerator::ALPHABET;
	$spell    = static function ( string $code ) use ( $alphabet ): callable {
		$queue = array_map( static fn( $ch ) => strpos( $alphabet, $ch ), str_split( str_replace( '-', '', substr( $code, 3 ) ) ) );
		$all   = $queue;
		return static function () use ( &$queue, $all ) {
			if ( array() === $queue ) {
				$queue = $all; // Repeat forever.
			}
			return array_shift( $queue );
		};
	};
	$current         = $active( $rg );
	$s               = $sum();
	$logger->entries = array();
	$taken           = $active( $bd_simple )['code']; // Another item's active code: the INSERT collides after the retire UPDATE.
	$r               = ( new ProductCodeService( new CodeGenerator( $spell( $taken ), static fn() => false ) ) )->regenerate( $rg, $A );
	pqbg_t( 'simulated failure mid-transaction (INSERT collides after the retire UPDATE): the original code stays active, nothing changed', is_wp_error( $r ) && 'pqbg_code_generation_failed' === $r->get_error_code() && $current === $active( $rg ) && $s === $sum(), is_wp_error( $r ) ? $r->get_error_code() : 'ok' );
	pqbg_t( '...logged by error code only', array( array( 'error', 'Product code generation failed: pqbg_code_generation_failed' ) ) === $logger->entries );
	$repo = CodeRepository::replace_active( $rg, 0, $taken, $A, (int) $current['id'] );
	pqbg_t( 'repository: replace_active rolls back on a duplicate code (pqbg_code_conflict)', is_wp_error( $repo ) && 'pqbg_code_conflict' === $repo->get_error_code() && $current === $active( $rg ) && $s === $sum() );
	$rng1 = $spell( $taken );
	$n    = 0;
	$r    = ( new ProductCodeService( new CodeGenerator( static function ( $min, $max ) use ( &$n, $rng1 ) { return $n++ < 12 ? $rng1() : random_int( $min, $max ); }, static fn() => false ) ) )->regenerate( $rg, $A );
	pqbg_t( 'one collision then success: retried with a new code, one active', is_array( $r ) && $r['code'] !== $taken && $r['code'] !== $current['code'] && $r === $active( $rg ) );

	pqbg_section( 'regeneration: real concurrency (4 PHP processes)' );
	$run_workers = static function ( int $item, int $user, int $expected ): array {
		$start = microtime( true ) + 2.5;
		$procs = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$procs[ $i ] = proc_open( array( PHP_BINARY, __FILE__, '--worker', (string) $item, (string) $user, (string) $expected, sprintf( '%.4F', $start ) ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes[ $i ] );
		}
		$out = array();
		foreach ( $procs as $i => $p ) {
			$text = stream_get_contents( $pipes[ $i ][1] ) . stream_get_contents( $pipes[ $i ][2] );
			proc_close( $p );
			$lines   = array_values( array_filter( array_map( 'trim', explode( "\n", $text ) ) ) );
			$out[ $i ] = json_decode( (string) end( $lines ), true ) ?? array( 'raw' => $text );
		}
		return $out;
	};
	$cc   = $make_simple( $A );
	$res  = $run_workers( $cc, $A, 0 );
	$ccr  = $rows( $cc );
	pqbg_t( '4 concurrent regenerations (no expected id): all succeed, serialised by the row lock', 4 === count( array_filter( $res, static fn( $x ) => isset( $x['code'] ) ) ), wp_json_encode( $res ) );
	pqbg_t( '...ending with exactly one active code and 4 retired ones (5 rows)', 5 === count( $ccr ) && 1 === count( array_filter( $ccr, static fn( $x ) => 'active' === $x['status'] ) ) && 4 === count( array_unique( array_column( $res, 'code' ) ) ) );
	$cc2  = $make_simple( $A );
	$res  = $run_workers( $cc2, $A, (int) $active( $cc2 )['id'] );
	$ccr  = $rows( $cc2 );
	$wins = array_filter( $res, static fn( $x ) => isset( $x['code'] ) );
	pqbg_t( '4 concurrent regenerations with the same expected id (double-click): exactly one wins, 3 get pqbg_code_changed', 1 === count( $wins ) && 3 === count( array_filter( $res, static fn( $x ) => 'pqbg_code_changed' === ( $x['error'] ?? '' ) ) ), wp_json_encode( $res ) );
	pqbg_t( '...ending with exactly one active code (2 rows)', 2 === count( $ccr ) && 1 === count( array_filter( $ccr, static fn( $x ) => 'active' === $x['status'] ) ) && reset( $wins )['code'] === $active( $cc2 )['code'] );

	pqbg_section( 'history: times in the site timezone, "retired by" labels' );
	echo '   site timezone: ' . wp_timezone_string() . "\n";
	$tz = static fn() => 'Asia/Kolkata';
	add_filter( 'pre_option_timezone_string', $tz );
	add_filter( 'pre_option_date_format', static fn() => 'Y-m-d' );
	add_filter( 'pre_option_time_format', static fn() => 'H:i' );
	pqbg_t( 'format_gmt shows stored GMT in the site timezone with wp_date() (UTC 00:00 → Asia/Kolkata 05:30)', '2026-01-01 05:30' === AdminProductPanel::format_gmt( '2026-01-01 00:00:00' ) );
	remove_filter( 'pre_option_timezone_string', $tz );
	pqbg_t( 'and in UTC when the site timezone is UTC', 'UTC' !== wp_timezone_string() || '2026-01-01 00:00' === AdminProductPanel::format_gmt( '2026-01-01 00:00:00' ) );
	pqbg_t( 'stored values stay GMT (retired_at within 10 minutes of UTC now)', abs( strtotime( $oldr['retired_at_gmt'] . ' UTC' ) - time() ) < 600 );
	pqbg_t( 'user_label: 0 → "System"', 'System' === AdminProductPanel::user_label( 0 ) );
	$gone_id = $user_ids['gone'];
	$hs      = $make_simple( $A );
	$svc->regenerate( $hs, $gone_id );
	wp_delete_user( $gone_id );
	unset( $user_ids['gone'] );
	pqbg_t( 'user_label: a deleted user → "User #ID (deleted)"', "User #{$gone_id} (deleted)" === AdminProductPanel::user_label( $gone_id ) && 'P5 admin' === AdminProductPanel::user_label( $A ) );
	$box = $render_box( $A, $hs );
	pqbg_t( 'the simple product history shows "User #ID (deleted)"', str_contains( $box, "User #{$gone_id} (deleted)" ) && 1 === substr_count( $box, 'pqbg-history-row' ) );
	[ $hv, $hvv ] = $make_variable( 2, $A );
	wp_delete_post( $hvv[1], true ); // No current user: retired_by 0.
	$box = $render_box( $A, $hv );
	pqbg_t( 'the variable product history shows "System" and "Variation #ID (deleted)"', str_contains( $box, '>System<' ) && str_contains( $box, "Variation #{$hvv[1]} (deleted)" ) );
	pqbg_t( 'history header names the timezone', str_contains( $box, 'Retired at (' . esc_html( wp_timezone_string() ) . ')' ) );

	pqbg_section( 'UI over HTTP: product edit screen' );
	$ui_s = $make_simple( $A );
	$uc   = $active( $ui_s )['code'];
	$page = $edit_page( 'admin', $ui_s );
	$sv   = $svgs( $page['body'] );
	pqbg_t( 'simple: panel, code, exactly ONE QR rendered, no barcode (disabled)', 200 === $page['code'] && str_contains( $page['body'], 'id="pqbg-codes"' ) && str_contains( $page['body'], $uc ) && 1 === $sv['qr'] && 0 === $sv['barcode'] );
	pqbg_t( 'simple: Download QR and Regenerate, no barcode download', 1 === count( $links( $page['body'], 'action=pqbg_code_image', 'item=' . $ui_s, 'type=qr', 'mode=download' ) ) && 1 === count( $links( $page['body'], 'page=pqbg-regenerate', 'item=' . $ui_s ) ) && ! str_contains( $page['body'], 'type=barcode' ) );
	$set( array( 'barcodes_enabled' => true ) );
	$page_b = $edit_page( 'admin', $ui_s );
	$sv     = $svgs( $page_b['body'] );
	pqbg_t( 'barcodes enabled: one QR + one barcode, and a Download barcode link', 1 === $sv['qr'] && 1 === $sv['barcode'] && 1 === count( $links( $page_b['body'], 'type=barcode', 'mode=download' ) ) );
	$bar_url = $links( $page_b['body'], 'type=barcode', 'mode=download' )[0] ?? '';
	$set( array() );
	$ui_none = $make_simple( 0 );
	$page    = $edit_page( 'sm', $ui_none );
	pqbg_t( 'no code yet: "No code yet." and a Generate button (shop manager)', str_contains( $page['body'], 'No code yet.' ) && str_contains( $page['body'], 'form="pqbg-f-generate-' . $ui_none . '"' ) && 0 === $svgs( $page['body'] )['qr'] );
	$footer = $from( $page['body'], '<form id="pqbg-f-generate-' . $ui_none . '"' );
	$post_close = strpos( $page['body'], '</form>', strpos( $page['body'], '<form name="post"' ) );
	pqbg_t( 'its POST form is printed outside the product form', '' !== $footer && strpos( $page['body'], '<form id="pqbg-f-generate-' ) > $post_close && str_contains( substr( $footer, 0, 600 ), 'value="pqbg_generate"' ) );
	$gen_post = array( 'action' => 'pqbg_generate', 'item' => $ui_none, '_pqbg_nonce' => $field( $footer, '_pqbg_nonce' ) );
	$s        = $sum();
	$r        = $http( 'sm', 'GET', admin_url( 'admin-post.php?' . http_build_query( $gen_post ) ) );
	pqbg_t( 'GET cannot generate: 405, nothing changed', 405 === $r['code'] && $s === $sum() );
	$r = $http( 'sm', 'POST', admin_url( 'admin-post.php' ), $gen_post );
	pqbg_t( 'Generate (POST): 303 back to the product with a success message; code created by the shop manager', 303 === $r['code'] && str_contains( $r['location'], 'pqbg_msg=generated' ) && str_contains( $r['location'], 'post=' . $ui_none ) && (int) ( $active( $ui_none )['created_by'] ?? 0 ) === $SM );
	pqbg_t( 'the message is shown after the redirect', str_contains( $http( 'sm', 'GET', $r['location'] )['body'], 'Product code generated.' ) );
	$http( 'sm', 'POST', admin_url( 'admin-post.php' ), $gen_post );
	pqbg_t( 'Generate again (replayed): still one code', 1 === count( $rows( $ui_none ) ) );

	[ $ui_v, $ui_vv ] = $make_variable( 3, $A );
	wp_delete_post( $ui_vv[2], true );
	$ui_nc = new WC_Product_Variation();
	$ui_nc->set_parent_id( $ui_v );
	$ui_nc->set_attributes( array( 'size' => 'S3' ) );
	$ui_nc_id = (int) $ui_nc->save(); // No user: no code.
	$page     = $edit_page( 'admin', $ui_v );
	pqbg_t( 'variable: a variations table (attributes, SKU, code, status), no QR rendered, no parent code', 3 === substr_count( $page['body'], 'data-pqbg-item=' ) && str_contains( $page['body'], 'PQBG-P5-' . $ui_v . '-S1' ) && str_contains( $page['body'], $active( $ui_vv[0] )['code'] ) && str_contains( $page['body'], 'Size: S1' ) && 0 === $svgs( $page['body'] )['qr'] && str_contains( $page['body'], 'has no code of its own' ) );
	pqbg_t( 'variable: per-variation View QR (click-to-load), Download QR, Regenerate; Generate for the one without a code', 2 === count( $links( $page['body'], 'mode=view' ) ) && 2 === count( $links( $page['body'], 'type=qr', 'mode=download' ) ) && 2 === count( $links( $page['body'], 'page=pqbg-regenerate' ) ) && str_contains( $page['body'], 'form="pqbg-f-generate-' . $ui_nc_id . '"' ) );
	pqbg_t( 'variable: history lists the deleted variation\'s code', str_contains( $page['body'], "Variation #{$ui_vv[2]} (deleted)" ) );
	$view = $http( 'admin', 'GET', $links( $page['body'], 'mode=view', 'item=' . $ui_vv[0] )[0] ?? admin_url() );
	pqbg_t( 'click-to-load: the view URL returns the SVG inline', 200 === $view['code'] && str_starts_with( $view['headers']['content-disposition'] ?? '', 'inline;' ) && str_starts_with( $view['body'], '<svg' ) );
	pqbg_t( 'the click-to-load script is enqueued on the edit screen', str_contains( $page['body'], 'assets/admin.js' ) );
	$lv = $http( 'admin', 'POST', admin_url( 'admin-ajax.php' ), array( 'action' => 'woocommerce_load_variations', 'security' => $js_nonce( $page['body'], 'load_variations_nonce' ), 'product_id' => $ui_v, 'attributes' => array(), 'page' => 1, 'per_page' => 15 ) );
	pqbg_t( 'variations panel (AJAX): code text and View/Download QR links, no image', 200 === $lv['code'] && str_contains( $lv['body'], $active( $ui_vv[0] )['code'] ) && str_contains( $lv['body'], 'pqbg-view-qr' ) && 0 === $svgs( $lv['body'] )['qr'] && str_contains( $lv['body'], 'No code yet' ) );
	foreach ( array( 'grouped', 'external' ) as $t ) {
		$gid  = $make_simple( $A, 'publish', $t );
		$page = $edit_page( 'admin', $gid );
		pqbg_t( "{$t}: a short explanation, no buttons, no image", str_contains( $page['body'], 'This product type has no product code' ) && ! str_contains( $page['body'], 'pqbg-generate' ) && ! str_contains( $page['body'], 'action=pqbg_code_image' ) && 0 === $svgs( $page['body'] )['qr'] );
	}
	$list = $http( 'admin', 'GET', admin_url( 'edit.php?post_type=product&posts_per_page=200' ) );
	pqbg_t( 'products list: "Code" column with code text, "—" otherwise, no images', str_contains( $list['body'], 'column-pqbg_code' ) && str_contains( $list['body'], $uc ) && str_contains( $list['body'], '<span aria-hidden="true">—</span>' ) && 0 === $svgs( $list['body'] )['qr'] && ! str_contains( $list['body'], 'action=pqbg_code_image' ) );
	pqbg_t( 'products list: column shown to a shop manager', str_contains( $http( 'sm', 'GET', admin_url( 'edit.php?post_type=product' ) )['body'], 'column-pqbg_code' ) );

	pqbg_section( 'UI over HTTP: regenerate confirmation and handler' );
	$page = $edit_page( 'sm', $ui_s );
	$cu   = $links( $page['body'], 'page=pqbg-regenerate', 'item=' . $ui_s )[0] ?? '';
	$s    = $sum();
	$conf = $http( 'sm', 'GET', $cu );
	pqbg_t( 'confirmation page (GET): 200, current code and the exact warning', 200 === $conf['code'] && str_contains( $conf['body'], 'Printed labels with the old code will stop working.' ) && str_contains( $conf['body'], $uc ) );
	pqbg_t( 'the confirmation page has no side effects', $s === $sum() );
	$menu = $from( $conf['body'], 'id="adminmenu"' );
	$menu = substr( $menu, 0, (int) strpos( $menu, 'id="wpbody"' ) );
	pqbg_t( 'the confirmation page is not in the admin menu', '' !== $menu && ! str_contains( $menu, 'pqbg-regenerate' ) );
	$cform  = $from( $conf['body'], 'value="pqbg_regenerate"' );
	pqbg_t( 'the confirmation form POSTs to admin-post.php with the expected code id', str_contains( $conf['body'], 'method="post"' ) && (int) $field( $conf['body'], 'expected' ) === (int) $active( $ui_s )['id'] );
	$regen  = array( 'action' => 'pqbg_regenerate', 'item' => $ui_s, 'expected' => $field( $conf['body'], 'expected' ), '_pqbg_nonce' => $field( $conf['body'], '_pqbg_nonce' ) );
	$r      = $http( 'sm', 'GET', admin_url( 'admin-post.php?' . http_build_query( $regen ) ) );
	pqbg_t( 'GET cannot regenerate: 405, nothing changed', 405 === $r['code'] && $s === $sum() && '' !== $cform );
	$r    = $http( 'sm', 'POST', admin_url( 'admin-post.php' ), $regen );
	$ui_r = CodeRepository::find_by_code( $uc );
	pqbg_t( 'Regenerate (POST): 303 with a success message; old code retired by the shop manager; a new active code', 303 === $r['code'] && str_contains( $r['location'], 'pqbg_msg=regenerated' ) && 'retired' === $ui_r['status'] && (int) $ui_r['retired_by'] === $SM && null !== $active( $ui_s ) && $active( $ui_s )['code'] !== $uc );
	$s = $sum();
	$r = $http( 'sm', 'POST', admin_url( 'admin-post.php' ), $regen );
	pqbg_t( 'replaying the same POST (double submit): pqbg_code_changed, nothing changed', 303 === $r['code'] && str_contains( $r['location'], 'pqbg_msg=pqbg_code_changed' ) && $s === $sum() );
	$page = $edit_page( 'admin', $ui_s );
	pqbg_t( 'the panel shows the new code and the retired one only as history text', str_contains( $page['body'], $active( $ui_s )['code'] ) && 1 === substr_count( $page['body'], $uc ) && str_contains( $from( $page['body'], 'pqbg-history' ), $uc ) && 1 === $svgs( $page['body'] )['qr'] );

	pqbg_section( 'downloads' );
	$code = $active( $ui_s )['code'];
	$page = $edit_page( 'admin', $ui_s );
	$qurl = $links( $page['body'], 'type=qr', 'mode=download', 'item=' . $ui_s )[0] ?? '';
	$d    = $http( 'admin', 'GET', $qurl );
	$h    = $d['headers'];
	pqbg_t( 'QR download: 200 with the exact headers', 200 === $d['code'] && 'image/svg+xml; charset=utf-8' === ( $h['content-type'] ?? '' ) && 'nosniff' === ( $h['x-content-type-options'] ?? '' ) && str_contains( $h['cache-control'] ?? '', 'no-store' ) && "attachment; filename=\"{$code}-qr.svg\"" === ( $h['content-disposition'] ?? '' ), wp_json_encode( array_intersect_key( $h, array_flip( array( 'content-type', 'x-content-type-options', 'cache-control', 'content-disposition' ) ) ) ) );
	pqbg_t( 'QR download: body is exactly the QrRenderer SVG of the ACTIVE code', ( new QrRenderer() )->render( $code ) === $d['body'] && ! str_contains( $d['body'], $uc ) );
	pqbg_t( 'the same URL never serves the retired code (it resolves the item, not a code)', ! str_contains( $d['body'] . wp_json_encode( $h ), $uc ) );
	$set( array( 'barcodes_enabled' => true ) );
	$page_b = $edit_page( 'admin', $ui_s );
	$burl   = $links( $page_b['body'], 'type=barcode', 'mode=download' )[0] ?? '';
	$db     = $http( 'admin', 'GET', $burl );
	pqbg_t( 'barcode download (enabled): headers and filename {CODE}-barcode.svg', 200 === $db['code'] && 'image/svg+xml; charset=utf-8' === ( $db['headers']['content-type'] ?? '' ) && "attachment; filename=\"{$code}-barcode.svg\"" === ( $db['headers']['content-disposition'] ?? '' ) && 'nosniff' === ( $db['headers']['x-content-type-options'] ?? '' ) );
	$set( array() );
	$dbx = $http( 'admin', 'GET', $burl );
	pqbg_t( 'barcodes disabled: the barcode handler refuses (404), no SVG', 404 === $dbx['code'] && ! str_contains( $dbx['body'], '<svg' ) );
	pqbg_t( 'an old barcode link from when barcodes were enabled is refused too', '' !== $bar_url && 404 === $http( 'admin', 'GET', $bar_url )['code'] );
	if ( $dec_ok ) {
		$decoded = $decode( array( 'qr' => $d['body'], 'bar' => $db['body'] ) );
		pqbg_t( 'round-trip: the downloaded QR decodes to exactly the scan URL', null !== $decoded && ScanUrl::for_code( $code ) === ( $decoded['qr'][0]['text'] ?? null ) && 'QRCode' === ( $decoded['qr'][0]['format'] ?? '' ), wp_json_encode( $decoded['qr'] ?? null ) );
		pqbg_t( 'round-trip: the downloaded barcode decodes to exactly the code', null !== $decoded && $code === ( $decoded['bar'][0]['text'] ?? null ), wp_json_encode( $decoded['bar'] ?? null ) );
	} else {
		pqbg_skip( 'round-trip: the downloaded QR decodes to exactly the scan URL', $skip_why );
		pqbg_skip( 'round-trip: the downloaded barcode decodes to exactly the code', $skip_why );
	}
	$page       = $edit_page( 'admin', $ui_v );
	$vdl        = $links( $page['body'], 'type=qr', 'mode=download', 'item=' . $ui_vv[0] )[0] ?? '';
	$vcode      = $active( $ui_vv[0] )['code'];
	$vd         = $http( 'admin', 'GET', $vdl );
	pqbg_t( 'variation download: the variation\'s own code and filename', 200 === $vd['code'] && "attachment; filename=\"{$vcode}-qr.svg\"" === ( $vd['headers']['content-disposition'] ?? '' ) );
	$svc->regenerate( $ui_vv[0], $A );
	$vd2 = $http( 'admin', 'GET', $vdl );
	pqbg_t( 'after regenerating the variation, the same link serves only the new code', 200 === $vd2['code'] && ! str_contains( $vd2['body'] . ( $vd2['headers']['content-disposition'] ?? '' ), $vcode ) && str_contains( $vd2['headers']['content-disposition'] ?? '', $active( $ui_vv[0] )['code'] ) );
	$del_link = $links( $page['body'], 'type=qr', 'mode=download', 'item=' . $ui_vv[1] )[0] ?? '';
	wp_set_current_user( $A );
	wp_delete_post( $ui_vv[1], true );
	wp_set_current_user( 0 );
	pqbg_t( 'a deleted variation\'s link: 404, its retired code is never served', 404 === $http( 'admin', 'GET', $del_link )['code'] );
	$nc_item = $make_simple( $A );
	$nc_link = $links( $edit_page( 'admin', $nc_item )['body'], 'type=qr', 'mode=download', 'item=' . $nc_item )[0] ?? '';
	CodeRepository::retire( (int) $active( $nc_item )['id'], $A ); // The item now has no active code.
	$s = $sum();
	pqbg_t( 'an item without an active code: 404, and the download never generates one', 404 === $http( 'admin', 'GET', $nc_link )['code'] && $s === $sum() && null === $active( $nc_item ) );

	pqbg_section( 'permissions and nonces on every handler' );
	$img_url  = $links( $edit_page( 'admin', $ui_s )['body'], 'type=qr', 'mode=download', 'item=' . $ui_s )[0] ?? '';
	$conf_url = $links( $edit_page( 'admin', $ui_s )['body'], 'page=pqbg-regenerate', 'item=' . $ui_s )[0] ?? '';
	$conf     = $http( 'admin', 'GET', $conf_url );
	$regen    = array( 'action' => 'pqbg_regenerate', 'item' => $ui_s, 'expected' => $field( $conf['body'], 'expected' ), '_pqbg_nonce' => $field( $conf['body'], '_pqbg_nonce' ) );
	$gen_item = $make_simple( 0 );
	$gp       = $edit_page( 'admin', $gen_item );
	$gen_post = array( 'action' => 'pqbg_generate', 'item' => $gen_item, '_pqbg_nonce' => $field( $from( $gp['body'], '<form id="pqbg-f-generate-' . $gen_item . '"' ), '_pqbg_nonce' ) );
	$s        = $sum();
	$refused  = static fn( array $r ) => ! in_array( $r['code'], array( 200, 303 ), true ) && ! str_starts_with( $r['body'], '<svg' );
	foreach ( array( 'seller', 'anon', 'nocap' ) as $who ) {
		$ok = $refused( $http( $who, 'GET', $img_url ) ) && $refused( $http( $who, 'POST', admin_url( 'admin-post.php' ), $gen_post ) ) && $refused( $http( $who, 'POST', admin_url( 'admin-post.php' ), $regen ) ) && $refused( $http( $who, 'GET', $conf_url ) );
		pqbg_t( "{$who}: refused on image, generate, regenerate and the confirmation page (admin's valid nonces), nothing changed", $ok && $s === $sum() );
	}
	pqbg_t( 'seller: admin-post answers 403 from the capability check (WooCommerce does not redirect admin-post.php)', 403 === $http( 'seller', 'GET', $img_url )['code'] );
	pqbg_t( 'logged out: admin-post answers 400 (no nopriv handler)', 400 === $http( 'anon', 'GET', $img_url )['code'] );
	$bad = static fn( string $url, string $nonce ) => preg_replace( '/_pqbg_nonce=[^&]+/', '_pqbg_nonce=' . $nonce, $url );
	pqbg_t( 'image: missing, invalid and other-item nonces → 403', 403 === $http( 'admin', 'GET', remove_query_arg( '_pqbg_nonce', $img_url ) )['code'] && 403 === $http( 'admin', 'GET', $bad( $img_url, 'abc123abc1' ) )['code'] && 403 === $http( 'admin', 'GET', add_query_arg( 'item', $ui_none, $img_url ) )['code'] );
	pqbg_t( 'generate: missing, invalid and other-item nonces → 403', 403 === $http( 'admin', 'POST', admin_url( 'admin-post.php' ), array_diff_key( $gen_post, array( '_pqbg_nonce' => 1 ) ) )['code'] && 403 === $http( 'admin', 'POST', admin_url( 'admin-post.php' ), array( '_pqbg_nonce' => 'abc123abc1' ) + $gen_post )['code'] && 403 === $http( 'admin', 'POST', admin_url( 'admin-post.php' ), array( 'item' => $ui_s ) + $gen_post )['code'] );
	pqbg_t( 'regenerate: missing, invalid and other-item nonces → 403', 403 === $http( 'admin', 'POST', admin_url( 'admin-post.php' ), array_diff_key( $regen, array( '_pqbg_nonce' => 1 ) ) )['code'] && 403 === $http( 'admin', 'POST', admin_url( 'admin-post.php' ), array( '_pqbg_nonce' => 'abc123abc1' ) + $regen )['code'] && 403 === $http( 'admin', 'POST', admin_url( 'admin-post.php' ), array( 'item' => $gen_item ) + $regen )['code'] );
	pqbg_t( 'confirmation page: missing, invalid and other-item nonces → 403', 403 === $http( 'admin', 'GET', remove_query_arg( '_pqbg_nonce', $conf_url ) )['code'] && 403 === $http( 'admin', 'GET', $bad( $conf_url, 'abc123abc1' ) )['code'] && 403 === $http( 'admin', 'GET', add_query_arg( 'item', $ui_none, $conf_url ) )['code'] );
	pqbg_t( 'nothing changed by any refused request', $s === $sum() && null === $active( $gen_item ) );
	pqbg_t( 'shop manager: image handler allowed with their own link', 200 === $http( 'sm', 'GET', $links( $edit_page( 'sm', $ui_s )['body'], 'mode=download', 'item=' . $ui_s )[0] ?? admin_url() )['code'] );
	pqbg_t( 'invalid type/mode → 400', 400 === $http( 'admin', 'GET', add_query_arg( 'type', 'png', $img_url ) )['code'] && 400 === $http( 'admin', 'GET', add_query_arg( 'mode', 'x', $img_url ) )['code'] );

	pqbg_section( 'performance: 40-variation product' );
	$t0 = microtime( true );
	$make_variable( 40, 0 );
	$timings['create product + 40 variations, no acting user = no codes (s)'] = round( microtime( true ) - $t0, 2 );
	$t0           = microtime( true );
	[ $pp, $ppv ] = $make_variable( 40, $A );
	$timings['create product + 40 variations as admin, with 40 codes (s)'] = round( microtime( true ) - $t0, 2 );
	pqbg_t( 'all 40 variations got codes on creation', 40 === count( CodeRepository::find_active_for_products( $ppv ) ) );
	wp_set_current_user( $A );
	$t0 = microtime( true );
	wc_get_product( $pp )->save();
	$timings['parent re-save with sweep (ms)'] = round( ( microtime( true ) - $t0 ) * 1000, 1 );
	wp_set_current_user( 0 );
	$box_ms = array();
	for ( $i = 0; $i < 5; $i++ ) {
		$t0       = microtime( true );
		$box      = $render_box( $A, $pp );
		$box_ms[] = ( microtime( true ) - $t0 ) * 1000;
	}
	sort( $box_ms );
	$timings['meta box render, median of 5 (ms)'] = round( $box_ms[2], 1 );
	pqbg_t( 'meta box for 40 variations: 40 rows, 0 QR rendered', 40 === substr_count( $box, 'data-pqbg-item=' ) && 0 === $svgs( $box )['qr'] );
	$page_t = array();
	$noc_t  = array();
	$sim_t  = array();
	$edit_page( 'sm', $pp );
	$http( 'nocap', 'GET', $edit_url( $pp ) ); // Warm-up.
	for ( $i = 0; $i < 5; $i++ ) {
		$pg       = $edit_page( 'sm', $pp );
		$page_t[] = $pg['time'];
		$noc_t[]  = $http( 'nocap', 'GET', $edit_url( $pp ) )['time'];
		$sim_t[]  = $edit_page( 'admin', $ui_s )['time'];
	}
	sort( $page_t );
	sort( $noc_t );
	sort( $sim_t );
	$timings['edit page, 40 variations, shop manager WITH panel, median (ms)']        = round( $page_t[2] * 1000 );
	$timings['edit page, 40 variations, shop manager WITHOUT pqbg cap, median (ms)'] = round( $noc_t[2] * 1000 );
	$timings['edit page, simple product with 1 QR (admin), median (ms)'] = round( $sim_t[2] * 1000 );
	pqbg_t( 'HTTP edit page (40 variations): 200, 0 QR images', 200 === $pg['code'] && 0 === $svgs( $pg['body'] )['qr'] && 40 === substr_count( $pg['body'], 'data-pqbg-item=' ) );
	pqbg_t( 'panel within budget (< 500 ms in-process)', $box_ms[2] < 500, $box_ms[2] . ' ms' );
	foreach ( $timings as $k => $v ) {
		echo "   {$k}: {$v}\n";
	}

	pqbg_section( 'scope' );
	do_action( 'rest_api_init' );
	$hit = static fn( $s ) => str_contains( $s, 'pqbg' ) || str_contains( $s, 'qrcode-barcode' ) || str_contains( $s, 'scan' );
	pqbg_t( 'no pqbg or /scan/ REST routes', ! array_filter( rest_get_server()->get_namespaces(), $hit ) && ! array_filter( array_keys( rest_get_server()->get_routes() ), static fn( $r ) => str_contains( $r, 'pqbg' ) || str_starts_with( $r, '/scan' ) ) );
	pqbg_t( 'no pqbg shortcodes', ! array_filter( array_keys( $GLOBALS['shortcode_tags'] ), $hit ) );
	// Phase 6 added the scan route: exactly its two rules, and no other pqbg or scan rule.
	$scan_rules = array_keys( ProductQrBarcode\ScanUrl::rewrite_rules( ProductQrBarcode\ScanRoute::ROUTE_VAR, ProductQrBarcode\ScanRoute::CODE_VAR ) );
	pqbg_t( 'no pqbg or scan rewrite rules other than the two Phase 6 scan rules', array() === array_diff( array_filter( array_keys( (array) get_option( 'rewrite_rules' ) ), static fn( $k ) => str_contains( $k, 'scan' ) || str_contains( $k, 'pqbg' ) ), $scan_rules ) );
	// Code tokens only: docblocks may name what is deliberately absent.
	$src = implode( "\n", array_map( static fn( $f ) => implode( '', array_map( static fn( $t ) => is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t, token_get_all( (string) file_get_contents( $f ) ) ) ), glob( PQBG_PLUGIN_DIR . 'includes/*.php' ) ) );
	pqbg_t( 'source: no nopriv handlers, AJAX actions, REST routes, shortcodes or rewrite endpoints', ! preg_match( '/admin_post_nopriv|wp_ajax_|register_rest_route|add_shortcode|add_rewrite_endpoint/', $src ) );
	pqbg_t( 'source: add_rewrite_rule appears only in ScanRoute (Phase 6)', array( 'ScanRoute.php' ) === array_values( array_map( 'basename', array_filter( glob( PQBG_PLUGIN_DIR . 'includes/*.php' ), static fn( $f ) => (bool) preg_match( '/add_rewrite_rule/', implode( '', array_map( static fn( $t ) => is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t, token_get_all( (string) file_get_contents( $f ) ) ) ) ) ) ) ) );
	pqbg_t( 'source: the barcode library is referenced only in BarcodeRenderer', 1 === count( array_filter( glob( PQBG_PLUGIN_DIR . 'includes/*.php' ), static fn( $f ) => str_contains( (string) file_get_contents( $f ), 'Picqer' ) ) ) );
	pqbg_t( 'source: no raw writes outside CodeRepository, SaleRepository (Phase 7), Install and Schema', ! preg_match( '/\$wpdb->(insert|update|query|delete)/', implode( "\n", array_map( 'file_get_contents', array_diff( glob( PQBG_PLUGIN_DIR . 'includes/*.php' ), array( PQBG_PLUGIN_DIR . 'includes/CodeRepository.php', PQBG_PLUGIN_DIR . 'includes/SaleRepository.php', PQBG_PLUGIN_DIR . 'includes/Install.php', PQBG_PLUGIN_DIR . 'includes/Schema.php' ) ) ) ) ) );
	pqbg_t( 'logged out: /scan/ and /scan/{CODE}/ redirect to the login page (Phase 6)', 302 === $http( 'anon', 'GET', $home . '/scan/' )['code'] && str_starts_with( $http( 'anon', 'GET', $home . '/scan/' . $code . '/' )['location'], wp_login_url() ) );
	pqbg_t( 'direct HTTP to the new files: empty output', array() === array_filter( array( 'includes/CodeLifecycle.php', 'includes/AdminActions.php', 'includes/AdminProductPanel.php', 'assets/index.php' ), static fn( $f ) => '' !== $http( 'anon', 'GET', PQBG_PLUGIN_URL . $f )['body'] ) );
} finally {
	pqbg_section( 'cleanup' );
	wp_set_current_user( 0 );
	CodeLifecycle::set_service( null );
	$handles = array();
	$ids     = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ('product_variation','product','revision') ORDER BY post_type = 'product', ID DESC", $start_post ) ) );
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $user_ids as $uid ) {
		if ( is_int( $uid ) ) {
			delete_transient( CodeLifecycle::NOTICE_TRANSIENT . $uid );
			wp_delete_user( $uid );
		}
	}
	delete_transient( CodeLifecycle::NOTICE_TRANSIENT . '0' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $C WHERE id > %d", $start_id ) );
	$wpdb->query( "ALTER TABLE $C AUTO_INCREMENT = 1" );
	$new_actions = $wpdb->get_results( $wpdb->prepare( "SELECT action_id, hook, args FROM $as_table WHERE action_id > %d", $start_as ), ARRAY_A );
	$ours_as     = array_filter(
		$new_actions,
		static function ( $a ) use ( $ids ) {
			foreach ( $ids as $id ) {
				if ( preg_match( '/(^|\D)' . $id . '(\D|$)/', (string) $a['args'] ) ) {
					return true;
				}
			}
			return false;
		}
	);
	foreach ( $ours_as as $a ) {
		ActionScheduler::store()->delete_action( (int) $a['action_id'] );
	}
	echo '   removed ' . count( $ids ) . ' post(s) and ' . count( $ours_as ) . ' Action Scheduler job(s): ' . implode( ', ', array_unique( array_column( $ours_as, 'hook' ) ) ) . "\n";
	$wpdb->update( $wpdb->options, $original, array( 'option_name' => $option ) );
	wp_cache_delete( $option, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	if ( is_dir( $tmp ) ) {
		array_map( 'unlink', glob( $tmp . '/*' ) );
		rmdir( $tmp );
	}
	$ids_in = implode( ',', array_merge( array( 0 ), $ids ) );
	pqbg_t( 'codes table back to its starting row count', $base_c === $count() );
	pqbg_t( 'products and variations back to the starting count', $base_prod === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')" ) );
	pqbg_t( 'no posts, meta or term relationships left for test IDs', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN ($ids_in)" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN ($ids_in)" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id IN ($ids_in)" ) );
	pqbg_t( 'settings restored to the exact stored value', $original === $raw_setting() );
	pqbg_t( 'temporary users removed', $base_user === (int) count_users()['total_users'] && ! get_user_by( 'login', 'pqbg_p5_admin' ) );
	pqbg_t( 'no failure-notice transients left', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( CodeLifecycle::NOTICE_TRANSIENT ) . '%' ) ) );
	pqbg_t( 'temporary directory removed', ! file_exists( $tmp ) );
}

pqbg_test_done();
