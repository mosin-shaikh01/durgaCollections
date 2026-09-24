<?php
/**
 * Phase 6 suite: the scan / product screen.
 *
 * Routing (rules, subdirectory, canonical 301s, flush once, deactivation,
 * plain and index.php permalinks, slug conflicts), every row of the status →
 * screen matrix over real HTTP, logged-out redirects and login round trips
 * through wp-login.php and the WooCommerce My Account form for admin, shop
 * manager and seller, the customer 403 (identical for every code), entry box
 * normalisation, WooCommerce Coming Soon, price/stock/category/image
 * rendering, HTML escaping, security headers, scope, a QR round trip
 * (QrRenderer → decoder → request) and scan timing.
 *
 * Creates products, variations, a page, terms, an attachment, code rows and
 * users, and removes them all. pqbg_settings, the Coming Soon option,
 * permalink options, rewrite rules and the rules flag are restored exactly.
 *
 *   php tests/phase6-scan.php
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
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

use ProductQrBarcode\{CodeGenerator, CodeRepository, Permissions, Plugin, ProductCodeService, QrRenderer, ScanRoute, ScanScreen, ScanUrl, Schema};

global $wpdb, $wp_rewrite;

$C          = Schema::codes_table();
$as_table   = $wpdb->prefix . 'actionscheduler_actions';
$raw_option = static fn( string $name ) => $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
$put_option = static function ( string $name, ?array $row ) use ( $wpdb ) {
	if ( null === $row ) {
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
	} elseif ( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $name ) ) ) {
		$wpdb->insert( $wpdb->options, array_merge( array( 'option_name' => $name ), $row ) );
	} else {
		$wpdb->update( $wpdb->options, $row, array( 'option_name' => $name ) );
	}
	wp_cache_delete( $name, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
};
$saved = array();
foreach ( array( Plugin::SETTINGS_OPTION, 'woocommerce_coming_soon', 'permalink_structure', 'rewrite_rules', ScanRoute::FLAG_OPTION, 'active_plugins' ) as $name ) {
	$saved[ $name ] = $raw_option( $name );
}
$start_id    = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $C" );
$start_as    = (int) $wpdb->get_var( "SELECT COALESCE(MAX(action_id), 0) FROM $as_table" );
$start_post  = (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID), 0) FROM {$wpdb->posts}" );
$start_term  = (int) $wpdb->get_var( "SELECT COALESCE(MAX(term_id), 0) FROM {$wpdb->terms}" );
$base_c      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" );
$base_prod   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')" );
$base_user   = (int) count_users()['total_users'];
$tmp         = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pqbg-p6-' . wp_generate_password( 8, false );
$user_ids    = array();
$pw          = array();
$home        = untrailingslashit( home_url() );
$files       = array();

mkdir( $tmp, 0700, true );

$sync   = static fn() => wp_cache_flush();
$count  = static fn() => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" );
$sum    = static fn() => (string) $wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, code, status, COALESCE(active_product_id, 0), COALESCE(retired_by, -1), COALESCE(retired_at_gmt, '')))), 0)) FROM $C" );
$code_of = static function ( int $id ): string {
	$row = CodeRepository::find_active_for_product( $id );
	return is_array( $row ) ? (string) $row['code'] : '';
};
$url = static fn( string $code = '' ) => ScanUrl::site_url( $code );

// Decoder (as in Phases 4 and 5).
$decoder  = getenv( 'PQBG_DECODER' );
$decoder  = ( is_string( $decoder ) && '' !== $decoder ) ? $decoder : __DIR__ . '/decoder/decode.mjs';
$node_v   = trim( (string) shell_exec( 'node --version 2>&1' ) );
$dec_ok   = str_starts_with( $node_v, 'v' ) && is_file( $decoder ) && is_dir( dirname( $decoder ) . '/node_modules' );
$skip_why = ! str_starts_with( $node_v, 'v' ) ? 'Node.js not on PATH' : 'decoder not installed (run `npm ci` in tests/decoder, or set PQBG_DECODER)';

// HTTP client: one cookie jar (curl handle) per name. Each request uses a fresh connection:
// with ~20 jars open, reusing idle keep-alive connections that Apache (KeepAliveTimeout 5)
// closes on its side intermittently produced empty responses (status 0) during development.
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
			CURLOPT_FORBID_REUSE   => true,
			CURLOPT_NOBODY         => 'HEAD' === $method,
			CURLOPT_CUSTOMREQUEST  => in_array( $method, array( 'GET', 'POST', 'HEAD' ), true ) ? null : $method,
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
/** Logs in through wp-login.php. $redirect_to null leaves the field out, as when the login page is opened directly. */
$login = static function ( string $who, string $user, string $pass, ?string $redirect_to = null ) use ( $http ): array {
	$http( $who, 'GET', wp_login_url() );
	$post = array( 'log' => $user, 'pwd' => $pass, 'wp-submit' => 'Log In', 'testcookie' => '1' );
	if ( null !== $redirect_to ) {
		$post['redirect_to'] = $redirect_to;
	}
	return $http( $who, 'POST', wp_login_url(), $post );
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
/** The redirect_to argument of a login URL. */
$redirect_to_of = static function ( string $location ): string {
	$args = array();
	parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $args );
	return (string) ( $args['redirect_to'] ?? '' );
};
$notices_in = static function ( string $body ): array {
	preg_match_all( '/<p class="pqbg-scan__notice[^"]*"[^>]*>(.*?)<\/p>/s', $body, $m );
	return array_map( static fn( $s ) => html_entity_decode( $s, ENT_QUOTES ), $m[1] );
};
$is_scan_page = static fn( array $r ) => str_contains( $r['body'], '<body class="pqbg-scan">' );

$make_simple = static function ( int $as, array $props = array() ): int {
	wp_set_current_user( $as );
	$p = new WC_Product_Simple();
	$p->set_name( 'PQBG P6 simple ' . wp_generate_password( 6, false ) );
	$p->set_status( 'publish' );
	$p->set_regular_price( '10' );
	$p->set_props( $props );
	$id = (int) $p->save();
	wp_set_current_user( 0 );
	return $id;
};
$make_variable = static function ( int $n, int $as, string $status = 'publish', array $parent_props = array() ): array {
	wp_set_current_user( $as );
	$opts = array_map( static fn( $i ) => 'S' . $i, range( 1, max( 1, $n ) ) );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( $opts );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$p = new WC_Product_Variable();
	$p->set_name( 'PQBG P6 variable ' . wp_generate_password( 6, false ) );
	$p->set_status( $status );
	$p->set_attributes( array( $attr ) );
	$p->set_props( $parent_props );
	$pid  = (int) $p->save();
	$vids = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $pid );
		$v->set_attributes( array( 'size' => $opts[ $i ] ) );
		$v->set_regular_price( '20' );
		$v->set_sku( 'PQBG-P6-' . $pid . '-' . $opts[ $i ] );
		$vids[] = (int) $v->save();
	}
	wp_set_current_user( 0 );
	return array( $pid, $vids );
};
/** Sets a post status directly (no hooks), like a status the admin UI produced. */
$set_status = static function ( int $id, string $status ) use ( $wpdb ) {
	$wpdb->update( $wpdb->posts, array( 'post_status' => $status ), array( 'ID' => $id ) );
	clean_post_cache( $id );
};

$rules_keys = array_keys( ScanUrl::rewrite_rules( ScanRoute::ROUTE_VAR, ScanRoute::CODE_VAR ) );
$has_rules  = static function () use ( $rules_keys ): bool {
	$rules = (array) get_option( 'rewrite_rules' );
	return array() === array_diff( $rules_keys, array_keys( $rules ) );
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

try {
	pqbg_section( 'setup' );
	foreach ( array( 'admin' => 'administrator', 'sm' => 'shop_manager', 'seller' => 'pqbg_seller', 'customer' => 'customer', 'subscriber' => 'subscriber' ) as $who => $role ) {
		$pw[ $who ]       = wp_generate_password( 24, false );
		$user_ids[ $who ] = wp_insert_user( array( 'user_login' => "pqbg_p6_{$who}", 'user_pass' => $pw[ $who ], 'user_email' => "pqbg-p6-{$who}@example.invalid", 'role' => $role ) );
	}
	pqbg_t( 'temporary users created', 5 === count( array_filter( $user_ids, 'is_int' ) ) );
	$A = $user_ids['admin'];
	pqbg_t( 'capabilities as expected (seller/sm/admin view, customer/subscriber do not)', Permissions::can_view_products( $user_ids['seller'] ) && Permissions::can_view_products( $user_ids['sm'] ) && Permissions::can_view_products( $A ) && ! Permissions::can_view_products( $user_ids['customer'] ) && ! Permissions::can_view_products( $user_ids['subscriber'] ) );
	pqbg_t( 'seller cannot edit products or use wp-admin; shop manager can edit products', ! user_can( $user_ids['seller'], 'edit_posts' ) && ! user_can( $user_ids['seller'], 'edit_products' ) && user_can( $user_ids['sm'], 'edit_products' ) );
	pqbg_t( 'site reachable over HTTP', 200 === $http( 'anon', 'GET', wp_login_url() )['code'] );
	pqbg_t( 'Coming Soon is on for the whole site (as in the live environment)', 'yes' === get_option( 'woocommerce_coming_soon' ) && 'yes' !== get_option( 'woocommerce_store_pages_only' ) );
	foreach ( array( 'admin', 'sm', 'seller', 'customer', 'subscriber' ) as $who ) {
		$login( $who, "pqbg_p6_{$who}", $pw[ $who ] );
	}

	pqbg_section( 'routing: rules and flag' );
	$rules = (array) get_option( 'rewrite_rules' );
	$keys  = array_keys( $rules );
	pqbg_t( 'both scan rules present with the expected queries', $has_rules() && 'index.php?pqbg_scan=1' === $rules[ $rules_keys[0] ] && 'index.php?pqbg_scan=1&pqbg_code=$matches[1]' === $rules[ $rules_keys[1] ] );
	pqbg_t( 'scan rules come before every page/post catch-all rule', array_search( $rules_keys[1], $keys, true ) < array_search( '(.?.+?)(?:/([0-9]+))?/?$', $keys, true ) && array_search( $rules_keys[1], $keys, true ) < array_search( '([^/]+)(?:/([0-9]+))?/?$', $keys, true ) );
	pqbg_t( 'rules are exactly ^scan/?$ and ^scan/(.+?)/?$', array( '^scan/?$', '^scan/(.+?)/?$' ) === $rules_keys );
	pqbg_t( 'no other pqbg or scan rewrite rules', 2 === count( array_filter( $keys, static fn( $k ) => str_contains( $k, 'scan' ) || str_contains( $k, 'pqbg' ) ) ) );
	pqbg_t( 'flag option holds version:rules-version, autoloaded', PQBG_VERSION . ':' . ScanRoute::RULES_VERSION === get_option( ScanRoute::FLAG_OPTION ) && in_array( $raw_option( ScanRoute::FLAG_OPTION )['autoload'], array( 'yes', 'on', 'auto-on' ), true ) );
	pqbg_t( 'query vars registered through the query_vars filter', array() === array_diff( array( 'pqbg_scan', 'pqbg_code' ), apply_filters( 'query_vars', array() ) ) );
	pqbg_t( 'route is available with /%postname%/', ScanRoute::is_available() );
	pqbg_t( 'site URL helpers use the subdirectory', $home . '/scan/' === $url() && $home . '/scan/DC-AAAA-BBBB-CCCC/' === $url( 'DC-AAAA-BBBB-CCCC' ) && '/sharayu/scan/' === ScanUrl::site_path() );
	pqbg_t( 'site_url() refuses a malformed code (falls back to the entry page)', $url() === $url( 'dc-aaaa-bbbb-cccc' ) && $url() === $url( '../x' ) );

	pqbg_section( 'routing: flush once' );
	$flushes = 0;
	$counter = static function () use ( &$flushes ) {
		++$flushes;
	};
	add_action( 'generate_rewrite_rules', $counter );
	update_option( ScanRoute::FLAG_OPTION, 'stale', true );
	ScanRoute::maybe_flush();
	$first = $flushes;
	ScanRoute::maybe_flush();
	ScanRoute::maybe_flush();
	remove_action( 'generate_rewrite_rules', $counter );
	pqbg_t( 'a stale flag flushes exactly once', 1 === $first && PQBG_VERSION . ':' . ScanRoute::RULES_VERSION === get_option( ScanRoute::FLAG_OPTION ) );
	pqbg_t( 'later calls do not flush', 1 === $flushes );
	pqbg_t( 'rules still present after the flush', $has_rules() );
	$cli_rules = array_keys( (array) get_option( 'rewrite_rules' ) );
	$before_rules = $raw_option( 'rewrite_rules' );
	$before_flag  = $raw_option( ScanRoute::FLAG_OPTION );
	for ( $i = 0; $i < 3; $i++ ) {
		$http( 'seller', 'GET', $url() );
		$http( 'anon', 'GET', $home . '/' );
	}
	$sync();
	pqbg_t( 'HTTP requests do not flush (rewrite_rules and flag unchanged)', $before_rules === $raw_option( 'rewrite_rules' ) && $before_flag === $raw_option( ScanRoute::FLAG_OPTION ) );

	pqbg_section( 'products for the matrix' );
	$cat_id = (int) wp_insert_term( 'PQBG P6 Sarees ' . wp_generate_password( 4, false ), 'product_cat' )['term_id'];
	// A 1x1 PNG attachment for the image check.
	$upload = wp_upload_bits( 'pqbg-p6-' . wp_generate_password( 6, false ) . '.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );
	$files[] = $upload['file'];
	$att     = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'PQBG P6 image', 'post_status' => 'inherit' ), $upload['file'] );
	pqbg_t( 'test attachment created', $att > 0 );

	$simple = $make_simple(
		$A,
		array(
			'regular_price'  => '2499',
			'sale_price'     => '1999',
			'sku'            => 'PQBG-P6-SALE',
			'manage_stock'   => true,
			'stock_quantity' => 7,
			'category_ids'   => array( $cat_id ),
			'image_id'       => $att,
		)
	);
	$plain      = $make_simple( $A, array( 'regular_price' => '1499.50', 'sku' => 'PQBG-P6-PLAIN', 'manage_stock' => false, 'stock_status' => 'instock' ) );
	$noprice    = $make_simple( $A, array( 'regular_price' => '' ) );
	$outstock   = $make_simple( $A, array( 'manage_stock' => false, 'stock_status' => 'outofstock' ) );
	$backorder  = $make_simple( $A, array( 'manage_stock' => true, 'stock_quantity' => 0, 'backorders' => 'yes' ) );
	$private    = $make_simple( $A );
	$draft      = $make_simple( $A );
	$pending    = $make_simple( $A );
	$future     = $make_simple( $A );
	$trashed    = $make_simple( $A, array( 'sku' => 'PQBG-P6-TRASH' ) );
	$regen      = $make_simple( $A );
	$deleted    = $make_simple( $A );
	$vanished   = $make_simple( $A );
	$xss        = $make_simple( $A, array( 'sku' => 'PQBG-P6-XSS' ) );
	[ $vp, $vv ]             = $make_variable( 2, $A, 'publish', array( 'manage_stock' => true, 'stock_quantity' => 5, 'category_ids' => array( $cat_id ) ) );
	[ $vdraft, $vdraftv ]    = $make_variable( 2, $A );
	[ $vpriv, $vprivv ]      = $make_variable( 1, $A );
	[ $vtrash, $vtrashv ]    = $make_variable( 1, $A );
	[ $vponly, $vponlyv ]    = $make_variable( 1, $A );
	$set_status( $private, 'private' );
	$set_status( $draft, 'draft' );
	$set_status( $pending, 'pending' );
	$set_status( $future, 'future' );
	$set_status( $vv[1], 'private' );           // Disabled variation under a published parent.
	$set_status( $vdraft, 'draft' );            // Parent draft; variation 0 enabled, variation 1 disabled.
	$set_status( $vdraftv[1], 'private' );
	$set_status( $vpriv, 'private' );           // Private parent, enabled variation.
	wp_trash_post( $trashed );
	wp_trash_post( $vtrash );                   // WooCommerce trashes the variations too.
	$set_status( $vponly, 'trash' );            // Parent only in trash; the variation stays published.
	$sync();
	pqbg_t( 'WooCommerce trashed the variation with its parent', 'trash' === get_post_status( $vtrashv[0] ) && 'publish' === get_post_status( $vponlyv[0] ) );

	$codes = array();
	foreach ( compact( 'simple', 'plain', 'noprice', 'outstock', 'backorder', 'private', 'draft', 'pending', 'future', 'trashed', 'regen', 'deleted', 'vanished', 'xss' ) as $k => $id ) {
		$codes[ $k ] = $code_of( $id );
	}
	$codes['var0']      = $code_of( $vv[0] );
	$codes['var1']      = $code_of( $vv[1] );
	$codes['vdraft0']   = $code_of( $vdraftv[0] );
	$codes['vdraft1']   = $code_of( $vdraftv[1] );
	$codes['vpriv']     = $code_of( $vprivv[0] );
	$codes['vtrash']    = $code_of( $vtrashv[0] );
	$codes['vponly']    = $code_of( $vponlyv[0] );
	pqbg_t( 'every test item has an active code', 21 === count( array_filter( $codes, array( CodeGenerator::class, 'is_valid_format' ) ) ), implode( ',', array_keys( array_filter( $codes, static fn( $c ) => '' === $c ) ) ) );

	// Retired by regeneration; retired by deletion; active with the product removed behind WordPress's back.
	$codes['regen_old'] = $codes['regen'];
	$regen_result       = ( new ProductCodeService() )->regenerate( $regen, $A );
	$codes['regen']     = $code_of( $regen );
	wp_delete_post( $deleted, true );
	$wpdb->delete( $wpdb->posts, array( 'ID' => $vanished ) );
	clean_post_cache( $vanished );
	// Values that must be escaped: stored directly so no input filter cleans them first.
	$evil = 'PQBG <script>alert(1)</script> "q" \'a\' & <img src=x onerror=alert(2)>';
	$wpdb->update( $wpdb->posts, array( 'post_title' => $evil ), array( 'ID' => $xss ) );
	$wpdb->update( $wpdb->terms, array( 'name' => 'Cat <script>alert(3)</script>' ), array( 'term_id' => $cat_id ) );
	clean_post_cache( $xss );
	clean_term_cache( $cat_id, 'product_cat' );
	$sync();
	pqbg_t( 'regenerated: old code retired, new code active', ! is_wp_error( $regen_result ) && 'retired' === CodeRepository::find_by_code( $codes['regen_old'] )['status'] && $codes['regen'] !== $codes['regen_old'] );
	pqbg_t( 'deleted: code retired by the lifecycle hook', 'retired' === CodeRepository::find_by_code( $codes['deleted'] )['status'] );
	pqbg_t( 'vanished: code still active, product gone', 'active' === CodeRepository::find_by_code( $codes['vanished'] )['status'] && ! wc_get_product( $vanished ) );
	do {
		$unknown = ( new CodeGenerator() )->generate();
	} while ( CodeRepository::code_exists( $unknown ) );

	$checksum = $sum();

	pqbg_section( 'status → screen matrix (HTTP, seller)' );
	$get = static fn( string $key, string $who = 'seller' ) => $http( $who, 'GET', $url( $codes[ $key ] ) );
	$np  = 'Not published – cannot be sold yet.';
	$dis = 'This variation is disabled – cannot be sold.';
	$full = static fn( array $r ) => str_contains( $r['body'], 'class="pqbg-scan__price"' ) && str_contains( $r['body'], 'class="pqbg-scan__stock"' );

	$r = $get( 'simple' );
	pqbg_t( 'active simple, published: 200 full screen, no banner', 200 === $r['code'] && $full( $r ) && array() === $notices_in( $r['body'] ) && str_contains( $r['body'], esc_html( get_the_title( $simple ) ) ), (string) $r['code'] );
	$r = $get( 'private' );
	pqbg_t( 'active simple, private: 200 full screen, NO banner (a private simple product is normal)', 200 === $r['code'] && $full( $r ) && array() === $notices_in( $r['body'] ) );
	foreach ( array( 'draft', 'pending', 'future' ) as $k ) {
		$r = $get( $k );
		pqbg_t( "active simple, {$k}: full screen + \"Not published\" banner", 200 === $r['code'] && $full( $r ) && array( $np ) === $notices_in( $r['body'] ) );
	}
	$r = $get( 'trashed' );
	pqbg_t( 'active simple, in trash: "in the trash", name and SKU only', 200 === $r['code'] && array( 'This product is in the trash.' ) === $notices_in( $r['body'] ) && str_contains( $r['body'], 'PQBG-P6-TRASH' ) && str_contains( $r['body'], 'PQBG P6 simple' ) && ! $full( $r ) && ! str_contains( $r['body'], 'woocommerce-Price-amount' ) );
	$r = $get( 'var0' );
	pqbg_t( 'active variation, published: full screen with parent name and attributes, no banner', 200 === $r['code'] && $full( $r ) && array() === $notices_in( $r['body'] ) && str_contains( $r['body'], esc_html( get_the_title( $vp ) ) ) && str_contains( $r['body'], 'Size: S1' ) );
	$r = $get( 'var1' );
	pqbg_t( 'active variation, private (disabled): full screen + "variation is disabled" banner', 200 === $r['code'] && $full( $r ) && array( $dis ) === $notices_in( $r['body'] ) );
	$r = $get( 'vdraft0' );
	pqbg_t( 'variation under a draft parent: "Not published" banner', 200 === $r['code'] && $full( $r ) && array( $np ) === $notices_in( $r['body'] ) );
	$r = $get( 'vdraft1' );
	pqbg_t( 'disabled variation under a draft parent: both banners', array( $np, $dis ) === $notices_in( $r['body'] ) );
	$r = $get( 'vpriv' );
	pqbg_t( 'enabled variation under a private parent: full screen, no banner', 200 === $r['code'] && $full( $r ) && array() === $notices_in( $r['body'] ) );
	$r = $get( 'vtrash' );
	pqbg_t( 'variation trashed with its parent: "in the trash"', array( 'This product is in the trash.' ) === $notices_in( $r['body'] ) && ! $full( $r ) && str_contains( $r['body'], 'PQBG-P6-' . $vtrash ) );
	$r = $get( 'vponly' );
	pqbg_t( 'published variation whose parent is in trash: "in the trash"', array( 'This product is in the trash.' ) === $notices_in( $r['body'] ) && ! $full( $r ) );
	$r_old_seller = $get( 'regen_old' );
	$r_old_sm     = $get( 'regen_old', 'sm' );
	pqbg_t( 'retired code, product exists: "out of date" + name', 200 === $r_old_seller['code'] && array( 'This label is out of date.' ) === $notices_in( $r_old_seller['body'] ) && str_contains( $r_old_seller['body'], esc_html( get_the_title( $regen ) ) ) && ! $full( $r_old_seller ) );
	pqbg_t( 'retired: code managers get the reprint link to the edit screen, sellers do not', str_contains( $r_old_sm['body'], 'Open the product to reprint its label' ) && str_contains( $r_old_sm['body'], 'post.php?post=' . $regen . '&#038;action=edit' ) && ! str_contains( $r_old_seller['body'], 'reprint' ) && ! str_contains( $r_old_seller['body'], 'post.php' ) );
	$r = $get( 'regen' );
	pqbg_t( 'the replacement code shows the full screen', 200 === $r['code'] && $full( $r ) );
	$r = $get( 'deleted' );
	pqbg_t( 'retired code, product deleted: "out of date" + "no longer valid", no name', 200 === $r['code'] && array( 'This label is out of date.', 'This label is no longer valid.' ) === $notices_in( $r['body'] ) && ! str_contains( $r['body'], 'PQBG P6 simple' ) );
	$r = $get( 'vanished' );
	pqbg_t( 'active code, product missing: "no longer valid" (not "out of date")', 200 === $r['code'] && array( 'This label is no longer valid.' ) === $notices_in( $r['body'] ) );
	$r = $http( 'seller', 'GET', $url( $unknown ) );
	pqbg_t( 'unknown well-formed code: 404 "Code not found."', 404 === $r['code'] && array( 'Code not found.' ) === $notices_in( $r['body'] ) && str_contains( $r['body'], $unknown ) );
	$r = $http( 'seller', 'GET', $home . '/scan/DC-0000-0000-0000/' );
	pqbg_t( 'invalid format: 400 "Not a valid product code." with the entry box', 400 === $r['code'] && array( 'Not a valid product code.' ) === $notices_in( $r['body'] ) && str_contains( $r['body'], 'name="code"' ) );
	$r = $http( 'seller', 'GET', $home . '/scan/garbage/more/path/' );
	pqbg_t( 'anything below /scan/ is handled by the plugin (400, not a theme 404)', 400 === $r['code'] && $is_scan_page( $r ) );
	pqbg_t( 'edit link: shop manager sees "Edit product" (parent for a variation), seller does not', str_contains( $get( 'var0', 'sm' )['body'], 'post.php?post=' . $vp . '&#038;action=edit' ) && ! str_contains( $get( 'var0' )['body'], 'post.php' ) );
	$all_screens = array();
	foreach ( array_keys( $codes ) as $k ) {
		$all_screens[ $k ] = $get( $k );
	}
	pqbg_t( 'every screen has the box (autofocus, name=code, GET to /scan/) and a Log out link', array() === array_filter( $all_screens, static fn( $r ) => ! ( str_contains( $r['body'], 'name="code"' ) && str_contains( $r['body'], ' autofocus' ) && str_contains( $r['body'], 'method="get" action="' . esc_url( $url() ) . '"' ) && str_contains( $r['body'], 'wp-login.php?action=logout' ) ) ) );
	pqbg_t( 'no screen offers selling or stock changes', array() === array_filter( $all_screens, static fn( $r ) => (bool) preg_match( '/mark\s+(as\s+)?sold|<form[^>]*method="post"/i', $r['body'] ) ) );
	pqbg_t( 'scans wrote nothing to pqbg_codes (checksum unchanged)', $checksum === $sum() );

	pqbg_section( 'rendering: price, stock, categories, image, code (HTTP, seller)' );
	$r = $get( 'simple' );
	pqbg_t( 'on sale: regular price struck through, sale price shown, INR', (bool) preg_match( '/<del[^>]*>.*2,499\.00.*<\/del>.*<ins[^>]*>.*1,999\.00.*<\/ins>/s', $r['body'] ) && str_contains( $r['body'], '&#8377;' ) );
	pqbg_t( 'managed stock shows quantity: "In stock (7)"', str_contains( $r['body'], 'In stock (7)' ) );
	pqbg_t( 'category name and SKU shown', str_contains( $r['body'], 'Cat &lt;script&gt;' ) && str_contains( $r['body'], 'PQBG-P6-SALE' ) );
	pqbg_t( 'image: one lazy <img> from the attachment', 1 === preg_match_all( '/<img\b[^>]*loading="lazy"[^>]*>/', $r['body'] ) && str_contains( $r['body'], wp_basename( $upload['file'] ) ) );
	pqbg_t( 'the code itself is shown', str_contains( $r['body'], '<dd class="pqbg-scan__code">' . $codes['simple'] . '</dd>' ) );
	$r = $get( 'plain' );
	pqbg_t( 'not on sale: one price, nothing struck through', str_contains( $r['body'], '1,499.50' ) && ! str_contains( $r['body'], '<del' ) );
	pqbg_t( 'unmanaged stock: "In stock" without a quantity', (bool) preg_match( '/<p class="pqbg-scan__stock">\s*In stock\s*<\/p>/', $r['body'] ) );
	pqbg_t( 'no price: "Price not set"', str_contains( $get( 'noprice' )['body'], 'Price not set' ) );
	pqbg_t( 'out of stock', (bool) preg_match( '/<p class="pqbg-scan__stock">\s*Out of stock\s*<\/p>/', $get( 'outstock' )['body'] ) );
	pqbg_t( 'on backorder with managed stock: "On backorder (0)"', str_contains( $get( 'backorder' )['body'], 'On backorder (0)' ) );
	$r = $get( 'var0' );
	pqbg_t( 'variation: stock managed at the parent level shows the parent quantity "In stock (5)"', str_contains( $r['body'], 'In stock (5)' ) );
	pqbg_t( 'variation: price, SKU and the parent\'s categories', str_contains( $r['body'], '20.00' ) && str_contains( $r['body'], 'PQBG-P6-' . $vp . '-S1' ) && str_contains( $r['body'], 'Cat &lt;script&gt;' ) );
	pqbg_t( 'no cost, supplier, notes or customer fields on the screen', ! preg_match( '/cost|supplier|purchase note|customer/i', wp_strip_all_tags( $get( 'simple' )['body'] ) ) );
	$live = wc_get_product( $plain );
	$live->set_regular_price( '1777' );
	wp_set_current_user( $A );
	$live->save();
	wp_set_current_user( 0 );
	pqbg_t( 'live data: a price change shows on the next scan', str_contains( $get( 'plain' )['body'], '1,777.00' ) );

	pqbg_section( 'HTML escaping' );
	$r = $get( 'xss' );
	pqbg_t( 'product name with HTML/script is escaped', 200 === $r['code'] && str_contains( $r['body'], 'PQBG &lt;script&gt;alert(1)&lt;/script&gt;' ) && ! str_contains( $r['body'], '<script' ) && ! str_contains( $r['body'], '<img src=x' ) );
	pqbg_t( 'category with script is escaped', ! str_contains( $get( 'simple' )['body'], '<script' ) );
	$r = $http( 'seller', 'GET', $url() . '?code=' . rawurlencode( '"><script>alert(4)</script>' ) );
	pqbg_t( 'entry box: rejected input is prefilled escaped', 400 === $r['code'] && ! str_contains( $r['body'], '<script' ) && str_contains( $r['body'], 'value="&quot;&gt;&lt;script&gt;alert(4)&lt;/script&gt;"' ) );
	// No %2F: Apache rejects encoded slashes in paths itself (AllowEncodedSlashes Off).
	$r = $http( 'seller', 'GET', $home . '/scan/%22%3E%3Cimg%20src=x%20onerror=alert(5)%3E/' );
	pqbg_t( 'invalid path segment with HTML is escaped', 400 === $r['code'] && ! str_contains( $r['body'], '<img src=x' ) && str_contains( $r['body'], '&quot;&gt;&lt;img src=x onerror=alert(5)&gt;' ) );
	$r = $http( 'seller', 'GET', $home . '/scan/%3Cscript%3Ealert(6)%3C/script%3E/' );
	pqbg_t( 'invalid multi-segment path with script is escaped', 400 === $r['code'] && ! str_contains( $r['body'], '<script' ) );
	pqbg_t( 'no <script> on any scan screen (the page uses no JavaScript)', array() === array_filter( $all_screens, static fn( $r ) => str_contains( $r['body'], '<script' ) ) );

	pqbg_section( 'canonical URLs (HTTP, seller)' );
	$c   = $codes['simple'];
	$cu  = $url( $c );
	$cases = array(
		'lowercase'                => $home . '/scan/' . strtolower( $c ) . '/',
		'no trailing slash'        => $home . '/scan/' . $c,
		'lowercase, no slash'      => $home . '/scan/' . strtolower( $c ),
		'surrounding spaces'       => $home . '/scan/%20' . $c . '%20/',
		'query string'             => $cu . '?utm=1',
		'raw query vars'           => $home . '/?pqbg_scan=1&pqbg_code=' . $c,
	);
	foreach ( $cases as $name => $u ) {
		$r = $http( 'seller', 'GET', $u );
		pqbg_t( "301 to the canonical URL: {$name}", 301 === $r['code'] && $cu === $r['location'], $r['code'] . ' ' . $r['location'] );
	}
	$r = $http( 'seller', 'GET', $home . '/scan' );
	pqbg_t( '/scan (no slash) → 301 /scan/', 301 === $r['code'] && $url() === $r['location'] );
	$r = $http( 'seller', 'GET', $home . '/?pqbg_scan=1' );
	pqbg_t( 'raw entry query var → 301 /scan/', 301 === $r['code'] && $url() === $r['location'] );
	$r = $http( 'seller', 'GET', $cu );
	pqbg_t( 'canonical URL itself: 200, no redirect', 200 === $r['code'] && '' === $r['location'] );
	pqbg_t( 'every redirect stays in the subdirectory', str_starts_with( $http( 'seller', 'GET', $cases['lowercase'] )['location'], 'http://localhost/sharayu/scan/' ) );
	pqbg_t( 'WordPress canonical redirects do not interfere (X-Redirect-By is the plugin)', 'Product QR Code and Barcode Generator' === ( $http( 'seller', 'GET', $cases['no trailing slash'] )['headers']['x-redirect-by'] ?? '' ) );

	pqbg_section( 'entry box normalisation (HTTP, seller)' );
	$entry = static fn( string $v ) => $http( 'seller', 'GET', $url() . '?code=' . rawurlencode( $v ) );
	$inputs = array(
		'spaces around'            => '  ' . $c . '  ',
		'lowercase'                => strtolower( $c ),
		'tab and newline'          => "\t" . $c . "\n",
		'spaces inside'            => str_replace( '-', ' - ', $c ),
		'pasted site scan URL'     => $cu,
		'pasted lowercase URL'     => strtolower( $cu ),
		'pasted label URL (other base)' => 'https://shop.example.com/store/scan/' . $c . '/',
		'pasted URL with query'    => $cu . '?x=1#top',
	);
	foreach ( $inputs as $name => $v ) {
		$r = $entry( $v );
		pqbg_t( "entry: {$name} → 302 to the canonical URL", 302 === $r['code'] && $cu === $r['location'], $r['code'] . ' ' . $r['location'] );
	}
	foreach ( array( 'garbage' => 'hello', 'wrong alphabet' => 'DC-0000-1111-OOOO', 'URL without a code' => $home . '/shop/', 'too short' => 'DC-AAAA' ) as $name => $v ) {
		$r = $entry( $v );
		pqbg_t( "entry: {$name} → 400 \"Not a valid product code.\"", 400 === $r['code'] && array( 'Not a valid product code.' ) === $notices_in( $r['body'] ) );
	}
	$r = $entry( '' );
	pqbg_t( 'entry: empty → 200 entry page, no message', 200 === $r['code'] && array() === $notices_in( $r['body'] ) );
	$r = $http( 'seller', 'GET', $url() );
	pqbg_t( 'entry page: 200 with the autofocus box and no product', 200 === $r['code'] && str_contains( $r['body'], ' autofocus' ) && ! str_contains( $r['body'], 'pqbg-scan__product' ) );
	pqbg_t( 'extract_code(): unit cases', 'DC-AAAA-BBBB-CCCC' === ScanUrl::extract_code( " dc-aaaa-bbbb-cccc\t" ) && 'DC-AAAA-BBBB-CCCC' === ScanUrl::extract_code( 'http://x.test/a/SCAN/dc-aaaa-bbbb-cccc/' ) && '' === ScanUrl::extract_code( 'http://x.test/shop/' ) && '' === ScanUrl::extract_code( '' ) );

	pqbg_section( 'access: logged out' );
	$anon_cases = array(
		'active'  => array( $cu, $cu ),
		'retired' => array( $url( $codes['regen_old'] ), $url( $codes['regen_old'] ) ),
		'unknown' => array( $url( $unknown ), $url( $unknown ) ),
		'lowercase' => array( $cases['lowercase'], $cu ),
		'invalid' => array( $home . '/scan/DC-0000-0000-0000/', $url() ),
		'entry'   => array( $url(), $url() ),
		'entry with ?code=' => array( $url() . '?code=' . $c, $url() ),
	);
	foreach ( $anon_cases as $name => [ $u, $target ] ) {
		$r = $http( 'anon', 'GET', $u );
		pqbg_t( "logged out, {$name}: 302 to wp-login.php with redirect_to = {$target}", 302 === $r['code'] && str_starts_with( $r['location'], wp_login_url() ) && $target === $redirect_to_of( $r['location'] ) && '' === trim( $r['body'] ), $r['code'] . ' ' . $r['location'] );
	}
	$r1 = $http( 'anon', 'GET', $cu );
	$r2 = $http( 'anon', 'GET', $url( $unknown ) );
	pqbg_t( 'logged out: an existing and an unknown code get the same kind of response (only the code in redirect_to differs)', $r1['code'] === $r2['code'] && str_replace( $c, 'X', $r1['location'] ) === str_replace( $unknown, 'X', $r2['location'] ) );
	pqbg_t( 'logged out: no product information anywhere in the response', ! str_contains( $r1['body'] . implode( '', $r1['headers'] ), 'PQBG P6' ) );

	pqbg_section( 'access: customer and subscriber get an identical 403' );
	$forbidden_urls = array( $cu, $url( $codes['regen_old'] ), $url( $unknown ), $home . '/scan/DC-0000-0000-0000/', $cases['lowercase'], $url(), $url() . '?code=' . $c, $url( $codes['trashed'] ) );
	foreach ( array( 'customer', 'subscriber' ) as $who ) {
		$bodies  = array();
		$headers = array();
		$codes_s = array();
		foreach ( $forbidden_urls as $u ) {
			$r         = $http( $who, 'GET', $u );
			$bodies[]  = $r['body'];
			$codes_s[] = $r['code'];
			unset( $r['headers']['date'] );
			$headers[] = $r['headers'];
		}
		pqbg_t( "{$who}: 403 for every code type and the entry page", array( 403 ) === array_values( array_unique( $codes_s ) ) );
		pqbg_t( "{$who}: byte-identical bodies", 1 === count( array_unique( $bodies ) ) );
		pqbg_t( "{$who}: identical headers (except Date)", 1 === count( array_unique( array_map( 'serialize', $headers ) ) ) );
		pqbg_t( "{$who}: no product information, no box, a Log out link", ! str_contains( $bodies[0], 'PQBG' ) && ! str_contains( $bodies[0], 'DC-' ) && ! str_contains( $bodies[0], 'name="code"' ) && str_contains( $bodies[0], 'action=logout' ) && str_contains( $bodies[0], 'You do not have permission to view products.' ) );
	}

	pqbg_section( 'access: methods' );
	$r = $http( 'seller', 'POST', $cu, array( 'x' => '1' ) );
	pqbg_t( 'POST as seller: 405 with Allow: GET, HEAD', 405 === $r['code'] && 'GET, HEAD' === ( $r['headers']['allow'] ?? '' ) && ! str_contains( $r['body'], 'PQBG P6' ) );
	pqbg_t( 'POST logged out: 405', 405 === $http( 'anon', 'POST', $cu, array( 'x' => '1' ) )['code'] );
	$r = $http( 'seller', 'HEAD', $cu );
	pqbg_t( 'HEAD as seller: 200, empty body', 200 === $r['code'] && '' === $r['body'] );

	pqbg_section( 'security headers on every response type' );
	$typed = array(
		'302 logged out'   => $http( 'anon', 'GET', $cu ),
		'301 canonical'    => $http( 'seller', 'GET', $cases['lowercase'] ),
		'302 entry'        => $entry( $c ),
		'200 product'      => $http( 'seller', 'GET', $cu ),
		'200 entry'        => $http( 'seller', 'GET', $url() ),
		'200 retired'      => $http( 'seller', 'GET', $url( $codes['regen_old'] ) ),
		'400 invalid'      => $entry( 'nope' ),
		'404 unknown'      => $http( 'seller', 'GET', $url( $unknown ) ),
		'403 customer'     => $http( 'customer', 'GET', $cu ),
		'405 method'       => $http( 'seller', 'POST', $cu, 'x=1' ),
		'200 HEAD'         => $http( 'seller', 'HEAD', $cu ),
	);
	foreach ( $typed as $name => $r ) {
		pqbg_t( "{$name}: all security headers exact", $headers_ok( $r ), $r['code'] . ' ' . wp_json_encode( array_intersect_key( $r['headers'], array_change_key_case( $expected_headers ) ) ) );
	}
	foreach ( array( '200 product', '200 entry', '400 invalid', '404 unknown', '403 customer' ) as $name ) {
		pqbg_t( "{$name}: meta robots noindex, nofollow and text/html", str_contains( $typed[ $name ]['body'], '<meta name="robots" content="noindex, nofollow">' ) && str_starts_with( $typed[ $name ]['headers']['content-type'] ?? '', 'text/html' ) );
	}
	pqbg_t( 'no Last-Modified or X-Pingback on scan responses', ! isset( $typed['200 product']['headers']['last-modified'] ) && ! isset( $typed['200 product']['headers']['x-pingback'] ) );

	pqbg_section( 'template and assets' );
	$page = $typed['200 product']['body'];
	pqbg_t( 'standalone document: no theme or wp_head output', str_starts_with( $page, '<!DOCTYPE html>' ) && ! str_contains( $page, 'twentytwentyfive' ) && ! str_contains( $page, 'wp-block' ) && ! str_contains( $page, 'wp-emoji' ) && ! str_contains( $page, 'admin-bar' ) && ! str_contains( $page, 'wp-json' ) );
	pqbg_t( 'the plugin stylesheet is linked on scan pages', 1 === substr_count( $page, 'assets/pqbg-scan.css' ) && 1 === substr_count( $page, '<link ' ) );
	pqbg_t( 'the plugin stylesheet is not loaded elsewhere (home page as admin)', ! str_contains( $http( 'admin', 'GET', $home . '/' )['body'], 'pqbg-scan.css' ) );
	pqbg_t( 'mobile viewport meta present', str_contains( $page, 'name="viewport" content="width=device-width, initial-scale=1"' ) );
	$css = (string) file_get_contents( PQBG_PLUGIN_DIR . 'assets/pqbg-scan.css' );
	pqbg_t( 'CSS: tap targets at least 48px, base font 18px', str_contains( $css, 'min-height: 52px' ) && str_contains( $css, 'min-height: 48px' ) && str_contains( $css, 'font: 18px' ) );
	pqbg_t( 'stylesheet served with 200', 200 === $http( 'anon', 'GET', PQBG_PLUGIN_URL . 'assets/pqbg-scan.css' )['code'] );

	pqbg_section( 'WooCommerce Coming Soon' );
	$r = $http( 'anon', 'GET', $cu );
	pqbg_t( 'logged out: login redirect, not the Coming Soon page', 302 === $r['code'] && ! str_contains( $r['body'], 'woo-coming-soon-page' ) );
	$home_seller = $http( 'seller', 'GET', $home . '/' );
	pqbg_t( 'control: Coming Soon is live for the seller on the home page', str_contains( $home_seller['body'], 'woo-coming-soon-page' ) );
	foreach ( array( 'seller', 'sm', 'admin' ) as $who ) {
		$r = $http( $who, 'GET', $cu );
		pqbg_t( "{$who}: scan screen, not Coming Soon (no marker, no max-age=60)", 200 === $r['code'] && $is_scan_page( $r ) && ! str_contains( $r['body'], 'woo-coming-soon-page' ) && ! str_contains( $r['headers']['cache-control'] ?? '', 'max-age=60' ) );
	}
	pqbg_t( 'control: logged-out /my-account/ shows Coming Soon, not the login form', str_contains( $http( 'anon', 'GET', wc_get_page_permalink( 'myaccount' ) )['body'], 'woo-coming-soon-page' ) );

	pqbg_section( 'login round trips: wp-login.php' );
	foreach ( array( 'admin', 'sm', 'seller' ) as $who ) {
		$jar = "rt_wp_{$who}";
		$r   = $http( $jar, 'GET', $cu );
		$to  = $redirect_to_of( $r['location'] );
		$http( $jar, 'GET', $r['location'] );
		$r2 = $http( $jar, 'POST', wp_login_url(), array( 'log' => "pqbg_p6_{$who}", 'pwd' => $pw[ $who ], 'wp-submit' => 'Log In', 'testcookie' => '1', 'redirect_to' => $to ) );
		$r3 = $http( $jar, 'GET', $r2['location'] );
		pqbg_t( "{$who}: scan → wp-login.php → back to the same scan URL → product screen", $cu === $to && 302 === $r2['code'] && $cu === $r2['location'] && 200 === $r3['code'] && $full( $r3 ), $r2['code'] . ' ' . $r2['location'] );
	}
	$r = $login( 'rt_wp_seller_default', 'pqbg_p6_seller', $pw['seller'] );
	pqbg_t( 'seller with no redirect_to lands on /scan/ (not profile.php → My Account)', 302 === $r['code'] && $url() === $r['location'], $r['location'] );
	$r = $login( 'rt_wp_seller_admin', 'pqbg_p6_seller', $pw['seller'], admin_url() );
	pqbg_t( 'seller with redirect_to=wp-admin lands on /scan/', $url() === $r['location'], $r['location'] );
	$r = $login( 'rt_wp_sm_default', 'pqbg_p6_sm', $pw['sm'] );
	pqbg_t( 'shop manager with no redirect_to still goes to wp-admin (unchanged)', admin_url() === $r['location'], $r['location'] );
	$r = $login( 'rt_wp_customer_default', 'pqbg_p6_customer', $pw['customer'] );
	pqbg_t( 'customer with no redirect_to is unchanged (profile.php)', admin_url( 'profile.php' ) === $r['location'], $r['location'] );
	$r = $login( 'rt_wp_customer_scan', 'pqbg_p6_customer', $pw['customer'], $cu );
	pqbg_t( 'customer with a scan redirect_to lands on the 403 page', $cu === $r['location'] && 403 === $http( 'rt_wp_customer_scan', 'GET', $cu )['code'] );

	pqbg_section( 'login round trips: My Account form' );
	$account = wc_get_page_permalink( 'myaccount' );
	$acc_to  = add_query_arg( 'redirect_to', rawurlencode( $cu ), $account );
	$nonces  = array();
	$put_option( 'woocommerce_coming_soon', array( 'option_value' => 'no', 'autoload' => $saved['woocommerce_coming_soon']['autoload'] ) );
	foreach ( array( 'admin', 'sm', 'seller', 'customer' ) as $who ) {
		$jar  = "rt_acc_{$who}";
		$form = $http( $jar, 'GET', $acc_to );
		$nonces[ $who ] = array( $field( $form['body'], 'woocommerce-login-nonce' ), $field( $form['body'], '_wp_http_referer' ) );
		if ( 'customer' === $who ) {
			continue;
		}
		$r  = $http( $jar, 'POST', $acc_to, array( 'username' => "pqbg_p6_{$who}", 'password' => $pw[ $who ], 'woocommerce-login-nonce' => $nonces[ $who ][0], '_wp_http_referer' => $nonces[ $who ][1], 'login' => 'Log in' ) );
		$r3 = $http( $jar, 'GET', $r['location'] );
		pqbg_t( "{$who} (Coming Soon off): My Account form → back to the scan URL → product screen", '' !== $nonces[ $who ][0] && 302 === $r['code'] && $cu === $r['location'] && 200 === $r3['code'] && $full( $r3 ), $r['code'] . ' ' . $r['location'] );
	}
	$form = $http( 'rt_acc_seller_plain', 'GET', $account );
	$r    = $http( 'rt_acc_seller_plain', 'POST', $account, array( 'username' => 'pqbg_p6_seller', 'password' => $pw['seller'], 'woocommerce-login-nonce' => $field( $form['body'], 'woocommerce-login-nonce' ), '_wp_http_referer' => $field( $form['body'], '_wp_http_referer' ), 'login' => 'Log in' ) );
	pqbg_t( 'seller via plain My Account (no redirect_to) lands on /scan/', 302 === $r['code'] && $url() === $r['location'], $r['location'] );
	$form2 = $http( 'rt_acc_seller_cs', 'GET', $acc_to );
	$cs_nonce = array( $field( $form2['body'], 'woocommerce-login-nonce' ), $field( $form2['body'], '_wp_http_referer' ) );
	$put_option( 'woocommerce_coming_soon', $saved['woocommerce_coming_soon'] );
	$r = $http( 'rt_acc_customer', 'POST', $acc_to, array( 'username' => 'pqbg_p6_customer', 'password' => $pw['customer'], 'woocommerce-login-nonce' => $nonces['customer'][0], '_wp_http_referer' => $nonces['customer'][1], 'login' => 'Log in' ) );
	pqbg_t( 'customer via My Account with a scan redirect_to: WooCommerce default kept (not sent to the scan page)', 302 === $r['code'] && ! ScanUrl::is_site_scan_url( $r['location'] ), $r['location'] );
	$r  = $http( 'rt_acc_seller_cs', 'POST', $acc_to, array( 'username' => 'pqbg_p6_seller', 'password' => $pw['seller'], 'woocommerce-login-nonce' => $cs_nonce[0], '_wp_http_referer' => $cs_nonce[1], 'login' => 'Log in' ) );
	$r3 = $http( 'rt_acc_seller_cs', 'GET', $r['location'] );
	pqbg_t( 'seller, Coming Soon ON: My Account login POST still returns to the scan URL → product screen', 'yes' === get_option( 'woocommerce_coming_soon' ) && 302 === $r['code'] && $cu === $r['location'] && 200 === $r3['code'] && $full( $r3 ), $r['location'] );
	pqbg_t( 'Coming Soon option restored exactly', $saved['woocommerce_coming_soon'] === $raw_option( 'woocommerce_coming_soon' ) );
	pqbg_t( 'login redirect filters ignore foreign hosts', 'https://evil.example/scan/X/' !== ScanRoute::woocommerce_login_redirect( add_query_arg( 'redirect_to', rawurlencode( 'https://evil.example/scan/' . $c . '/' ), $account ), get_user_by( 'id', $user_ids['seller'] ) ) && ! ScanUrl::is_site_scan_url( 'https://evil.example/sharayu/scan/' ) && ! ScanUrl::is_site_scan_url( 'http://localhost:8080/sharayu/scan/' ) && ! ScanUrl::is_site_scan_url( 'http://localhost/sharayu/scanner/' ) );

	pqbg_section( 'logout' );
	$r = $http( 'seller', 'GET', $cu );
	preg_match( '/href="([^"]*action=logout[^"]*)"/', $r['body'], $m );
	$out = $http( 'seller', 'GET', html_entity_decode( $m[1] ?? '' ) );
	$after = $http( 'seller', 'GET', $cu );
	pqbg_t( 'Log out link logs out and returns to /scan/, which then asks for login', 302 === $out['code'] && str_starts_with( $out['location'], $url() ) && 302 === $after['code'] && str_starts_with( $after['location'], wp_login_url() ) );
	$login( 'seller', 'pqbg_p6_seller', $pw['seller'] );

	pqbg_section( 'round trip: QrRenderer → decoder → request' );
	if ( ! $dec_ok ) {
		pqbg_skip( 'round-trip: the QR decodes to the scan URL and that URL opens the product screen', $skip_why );
	} else {
		$svg  = ( new QrRenderer() )->render( $codes['var0'] );
		$file = $tmp . DIRECTORY_SEPARATOR . 'qr.svg';
		file_put_contents( $file, $svg );
		$json = json_decode( (string) shell_exec( 'node ' . escapeshellarg( $decoder ) . ' ' . escapeshellarg( $file ) . ' 2>&1' ), true );
		unlink( $file );
		$text = (string) ( $json[0]['results'][0]['text'] ?? '' );
		$r    = '' !== $text ? $http( 'seller', 'GET', $text ) : array( 'code' => 0, 'body' => '' );
		pqbg_t( 'round-trip: the QR decodes to the scan URL and that URL opens the product screen', ScanUrl::for_code( $codes['var0'] ) === $text && $url( $codes['var0'] ) === $text && 200 === $r['code'] && str_contains( $r['body'], $codes['var0'] ) && str_contains( $r['body'], 'Size: S1' ), $text );
	}

	pqbg_section( 'timing' );
	$times = array();
	for ( $i = 0; $i < 10; $i++ ) {
		$times[] = $http( 'seller', 'GET', $url( $codes['var0'] ) )['time'];
	}
	sort( $times );
	$median = ( $times[4] + $times[5] ) / 2;
	echo '   product scan over HTTP (seller, variation): median ' . round( $median * 1000 ) . ' ms, min ' . round( $times[0] * 1000 ) . ' ms, max ' . round( end( $times ) * 1000 ) . " ms (10 requests)\n";
	wp_set_current_user( $user_ids['seller'] );
	$t0 = microtime( true );
	$q0 = $wpdb->num_queries;
	ScanScreen::render( ScanScreen::resolve( $codes['var0'] ) );
	$in_ms = ( microtime( true ) - $t0 ) * 1000;
	$in_q  = $wpdb->num_queries - $q0;
	wp_set_current_user( 0 );
	echo '   in-process resolve + render: ' . round( $in_ms, 1 ) . " ms, {$in_q} queries\n";
	pqbg_t( 'scan timing within a sanity bound (median < 3 s)', $median < 3.0, round( $median * 1000 ) . ' ms' );

	pqbg_section( 'sitemaps' );
	add_filter( 'wp_sitemaps_enabled', '__return_true' );
	$server = new WP_Sitemaps();
	$server->register_sitemaps();
	$locs = array();
	foreach ( $server->registry->get_providers() as $provider ) {
		foreach ( array_keys( (array) $provider->get_object_subtypes() ) ?: array( '' ) as $subtype ) {
			foreach ( (array) $provider->get_url_list( 1, (string) $subtype ) as $entry ) {
				$locs[] = $entry['loc'];
			}
		}
	}
	remove_filter( 'wp_sitemaps_enabled', '__return_true' );
	pqbg_t( 'no sitemap entry points into /scan/ (sitemaps forced on in-process)', array() !== $locs && array() === array_filter( $locs, static fn( $l ) => str_contains( $l, '/scan/' ) ), count( $locs ) . ' entries' );

	pqbg_section( 'slug conflicts' );
	pqbg_t( 'no conflicts at start', array() === ScanRoute::conflicts() );
	$notice = static function ( int $as ): string {
		wp_set_current_user( $as );
		ob_start();
		ScanRoute::admin_notices();
		wp_set_current_user( 0 );
		return (string) ob_get_clean();
	};
	pqbg_t( 'no notice without conflicts or permalink problems', '' === $notice( $A ) );
	$scan_term = wp_insert_term( 'Scan', 'product_cat', array( 'slug' => 'scan' ) );
	pqbg_t( 'a product category "scan" (/product-category/scan/) is not a conflict', ! is_wp_error( $scan_term ) && array() === ScanRoute::conflicts() );
	$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Scan <b>page</b>', 'post_name' => 'scan' ) );
	$kid  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Child', 'post_name' => 'child', 'post_parent' => $page ) );
	pqbg_t( 'test page really lives at /scan/', $url() === get_permalink( $page ) );
	$conf = ScanRoute::conflicts();
	pqbg_t( 'a page with the slug "scan" is detected', 1 === count( $conf ) && str_contains( $conf[0], '#' . $page ) );
	$n = $notice( $A );
	pqbg_t( 'admin sees the conflict notice (escaped)', str_contains( $n, 'notice-warning' ) && str_contains( $n, 'The scan page takes precedence' ) && ! str_contains( $n, '<b>page' ) );
	pqbg_t( 'shop manager and seller do not see it (pqbg_manage_settings only)', '' === $notice( $user_ids['sm'] ) && '' === $notice( $user_ids['seller'] ) );
	pqbg_t( 'the notice is wired on real admin screens (HTTP, admin)', str_contains( $http( 'admin', 'GET', admin_url() )['body'], 'The scan page takes precedence' ) );
	$r = $http( 'seller', 'GET', $url() );
	pqbg_t( 'the plugin route still wins over the page at /scan/', 200 === $r['code'] && $is_scan_page( $r ) && ! str_contains( $r['body'], 'Scan <b>page' ) );
	$r = $http( 'seller', 'GET', get_permalink( $kid ) );
	pqbg_t( 'and over its child page (/scan/child/ is an invalid code)', 400 === $r['code'] && $is_scan_page( $r ) );
	wp_trash_post( $page );
	pqbg_t( 'a trashed page is no longer a conflict', array() === array_filter( ScanRoute::conflicts(), static fn( $l ) => str_contains( $l, '#' . $page ) ) );
	wp_delete_post( $kid, true );
	wp_delete_post( $page, true );
	wp_delete_term( $scan_term['term_id'], 'product_cat' );

	pqbg_section( 'permalinks: plain and index.php' );
	pqbg_t( 'no permalink notice with /%postname%/', ! str_contains( $notice( $A ), 'need pretty permalinks' ) );
	foreach ( array( 'plain' => '', 'index.php' => '/index.php/%postname%/' ) as $name => $structure ) {
		// Only the structure changes: WP_Rewrite::init() would also drop every registered endpoint and extra rule.
		$put_option( 'permalink_structure', array( 'option_value' => $structure, 'autoload' => $saved['permalink_structure']['autoload'] ) );
		$wp_rewrite->permalink_structure = $structure;
		pqbg_t( "{$name}: route reported unavailable", ! ScanRoute::is_available() );
		$n = $notice( $A );
		pqbg_t( "{$name}: admin notice for pqbg_manage_settings only", str_contains( $n, 'need pretty permalinks' ) && str_contains( $n, esc_html( $url() ) ) && '' === $notice( $user_ids['sm'] ) );
		$fake             = new WP();
		$fake->query_vars = array( ScanRoute::ROUTE_VAR => '1', ScanRoute::CODE_VAR => $c );
		ScanRoute::handle( $fake ); // Would exit the suite if it handled the request.
		pqbg_t( "{$name}: handle() leaves the request to WordPress", true );
		$r = $http( 'seller', 'GET', $home . '/?pqbg_scan=1&pqbg_code=' . $c );
		pqbg_t( "{$name}: query-var access is not served either (HTTP)", ! $is_scan_page( $r ) && 'Product QR Code and Barcode Generator' !== ( $r['headers']['x-redirect-by'] ?? '' ), (string) $r['code'] );
	}
	$put_option( 'permalink_structure', $saved['permalink_structure'] );
	$put_option( 'rewrite_rules', $saved['rewrite_rules'] );
	$wp_rewrite->permalink_structure = $saved['permalink_structure']['option_value'];
	pqbg_t( 'permalinks and rules restored; route available again', ScanRoute::is_available() && $saved['permalink_structure'] === $raw_option( 'permalink_structure' ) && 200 === $http( 'seller', 'GET', $cu )['code'] );
	pqbg_t( 'login redirect filters do nothing while the route is unavailable (checked in-process)', ( static function () use ( $put_option, $saved, $wp_rewrite, $user_ids, $url ) {
		$put_option( 'permalink_structure', array( 'option_value' => '', 'autoload' => $saved['permalink_structure']['autoload'] ) );
		$wp_rewrite->permalink_structure = '';
		$out = ScanRoute::login_redirect( admin_url(), '', get_user_by( 'id', $user_ids['seller'] ) );
		$put_option( 'permalink_structure', $saved['permalink_structure'] );
		$wp_rewrite->permalink_structure = $saved['permalink_structure']['option_value'];
		return admin_url() === $out && $url() === ScanRoute::login_redirect( admin_url(), '', get_user_by( 'id', $user_ids['seller'] ) );
	} )() );

	pqbg_section( 'deactivation and reactivation' );
	$pf = pqbg_test_plugin_basename();
	deactivate_plugins( $pf );
	$sync();
	pqbg_t( 'deactivation removes the scan rules and the flag', ! array_filter( array_keys( (array) get_option( 'rewrite_rules' ) ), static fn( $k ) => str_contains( $k, 'scan' ) ) && false === get_option( ScanRoute::FLAG_OPTION ) && ! is_plugin_active( $pf ) );
	$after_rules = array_keys( (array) get_option( 'rewrite_rules' ) );
	pqbg_t( 'every other rewrite rule survives deactivation', array_values( array_diff( $cli_rules, $rules_keys ) ) === $after_rules, 'missing: ' . implode( ' ', array_diff( $cli_rules, $rules_keys, $after_rules ) ) . ' | extra: ' . implode( ' ', array_diff( $after_rules, $cli_rules ) ) );
	$r = $http( 'anon', 'GET', $cu );
	pqbg_t( 'while deactivated: /scan/{CODE}/ is not served by the plugin', 'Product QR Code and Barcode Generator' !== ( $r['headers']['x-redirect-by'] ?? '' ) && ! $is_scan_page( $r ), (string) $r['code'] );
	pqbg_t( 'deactivation kept codes (checksum unchanged)', $checksum === $sum() );
	$act = activate_plugin( $pf );
	$sync();
	pqbg_t( 'reactivation adds the rules and the flag back', null === $act && is_plugin_active( $pf ) && $has_rules() && PQBG_VERSION . ':' . ScanRoute::RULES_VERSION === get_option( ScanRoute::FLAG_OPTION ) );
	pqbg_t( 'after reactivation: the route works again', 200 === $http( 'seller', 'GET', $cu )['code'] );

	pqbg_section( 'scope' );
	do_action( 'rest_api_init' );
	$hit = static fn( $s ) => str_contains( $s, 'pqbg' ) || str_contains( $s, 'qrcode-barcode' ) || str_contains( $s, 'scan' );
	pqbg_t( 'no pqbg or /scan REST routes', ! array_filter( rest_get_server()->get_namespaces(), $hit ) && ! array_filter( array_keys( rest_get_server()->get_routes() ), static fn( $r ) => str_contains( $r, 'pqbg' ) || str_starts_with( $r, '/scan' ) ) );
	pqbg_t( 'no pqbg shortcodes', ! array_filter( array_keys( $GLOBALS['shortcode_tags'] ), $hit ) );
	$src_of = static fn( array $files ) => implode( "\n", array_map( static fn( $f ) => implode( '', array_map( static fn( $t ) => is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t, token_get_all( (string) file_get_contents( $f ) ) ) ), $files ) );
	$all_src = $src_of( array_merge( glob( PQBG_PLUGIN_DIR . 'includes/*.php' ), glob( PQBG_PLUGIN_DIR . 'templates/*.php' ) ) );
	pqbg_t( 'source: no nopriv handlers, AJAX actions, REST routes, shortcodes or rewrite endpoints', ! preg_match( '/admin_post_nopriv|wp_ajax_|register_rest_route|add_shortcode|add_rewrite_endpoint/', $all_src ) );
	pqbg_t( 'source: add_rewrite_rule only in ScanRoute', 1 === count( array_filter( glob( PQBG_PLUGIN_DIR . 'includes/*.php' ), static fn( $f ) => str_contains( $src_of( array( $f ) ), 'add_rewrite_rule' ) ) ) && str_contains( $src_of( array( PQBG_PLUGIN_DIR . 'includes/ScanRoute.php' ) ), 'add_rewrite_rule' ) );
	pqbg_t( 'source: scan classes and template never write to the database', ! preg_match( '/\$wpdb|update_post_meta|wp_update_post|->save\(|set_stock|wc_update_product_stock|CodeRepository::(create|replace|retire)/', $src_of( array( PQBG_PLUGIN_DIR . 'includes/ScanScreen.php', PQBG_PLUGIN_DIR . 'templates/pqbg-scan.php' ) ) ) && ! preg_match( '/\$wpdb|->save\(|set_stock|CodeRepository::/', $src_of( array( PQBG_PLUGIN_DIR . 'includes/ScanRoute.php' ) ) ) );
	pqbg_t( 'source: the template does not use the theme (no get_header/get_footer/wp_head/wp_footer)', ! preg_match( '/get_header|get_footer|wp_head\(|wp_footer\(|get_template_part/', $src_of( array( PQBG_PLUGIN_DIR . 'templates/pqbg-scan.php' ) ) ) );
	pqbg_t( 'source: no JavaScript in the scan page or assets', ! str_contains( (string) file_get_contents( PQBG_PLUGIN_DIR . 'templates/pqbg-scan.php' ), '<script' ) && ! str_contains( $src_of( array( PQBG_PLUGIN_DIR . 'includes/ScanScreen.php', PQBG_PLUGIN_DIR . 'includes/ScanRoute.php' ) ), 'wp_enqueue_script' ) );
	pqbg_t( 'no nopriv or pqbg AJAX hooks registered', ! array_filter( array_keys( $GLOBALS['wp_filter'] ), static fn( $h ) => str_starts_with( $h, 'admin_post_nopriv_pqbg' ) || str_starts_with( $h, 'wp_ajax_nopriv_pqbg' ) || str_starts_with( $h, 'wp_ajax_pqbg' ) ) );
	pqbg_t( 'direct HTTP to the new PHP files: empty output', array() === array_filter( array( 'includes/ScanRoute.php', 'includes/ScanScreen.php', 'templates/pqbg-scan.php', 'templates/index.php' ), static fn( $f ) => '' !== $http( 'anon', 'GET', PQBG_PLUGIN_URL . $f )['body'] ) );
	pqbg_t( 'the label URL format is unchanged ({base}/scan/{CODE}/)', $home . '/scan/' . $c . '/' === ScanUrl::for_code( $c ) );
	pqbg_t( 'codes untouched by the whole suite since setup (checksum)', $checksum === $sum() );
} finally {
	pqbg_section( 'cleanup' );
	wp_set_current_user( 0 );
	$handles = array();
	foreach ( array( 'woocommerce_coming_soon', 'permalink_structure', 'rewrite_rules', ScanRoute::FLAG_OPTION, 'active_plugins', Plugin::SETTINGS_OPTION ) as $name ) {
		$put_option( $name, $saved[ $name ] );
	}
	$wp_rewrite->permalink_structure = $saved['permalink_structure']['option_value'];
	$end_post = (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID), 0) FROM {$wpdb->posts}" );
	$ids      = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ('product_variation','product','revision','page','attachment') ORDER BY post_type = 'product', ID DESC", $start_post ) ) );
	foreach ( $ids as $id ) {
		'attachment' === get_post_type( $id ) ? wp_delete_attachment( $id, true ) : wp_delete_post( $id, true );
	}
	foreach ( $files as $f ) {
		if ( is_file( $f ) ) {
			unlink( $f );
		}
	}
	// Rows deleted directly (the "vanished" product) leave meta and relationships behind.
	$all_new = array_map( 'intval', range( $start_post + 1, max( $start_post + 1, $end_post, (int) $wpdb->get_var( "SELECT COALESCE(MAX(post_id), 0) FROM {$wpdb->postmeta}" ) ) ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id > %d", $start_post ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_relationships} WHERE object_id > %d", $start_post ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id > %d", $start_post ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wc_product_attributes_lookup WHERE product_id > %d OR product_or_parent_id > %d", $start_post, $start_post ) );
	foreach ( get_terms( array( 'taxonomy' => array( 'product_cat', 'product_tag' ), 'hide_empty' => false ) ) as $term ) {
		if ( $term->term_id > $start_term ) {
			wp_delete_term( $term->term_id, $term->taxonomy );
		}
	}
	// Opening the Dashboard creates a Quick Draft auto-draft owned by the user; wp_delete_user() would trash it.
	foreach ( $user_ids as $uid ) {
		if ( is_int( $uid ) ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d", $uid ) ) as $pid ) {
				wp_delete_post( (int) $pid, true );
			}
			wp_delete_user( $uid );
		}
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM $C WHERE id > %d", $start_id ) );
	$wpdb->query( "ALTER TABLE $C AUTO_INCREMENT = 1" );
	$new_actions = $wpdb->get_results( $wpdb->prepare( "SELECT action_id, hook, args FROM $as_table WHERE action_id > %d", $start_as ), ARRAY_A );
	$ours_as     = array_filter(
		$new_actions,
		static function ( $a ) use ( $all_new ) {
			foreach ( $all_new as $id ) {
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
	$sync();
	if ( is_dir( $tmp ) ) {
		array_map( 'unlink', glob( $tmp . '/*' ) );
		rmdir( $tmp );
	}
	pqbg_t( 'codes table back to its starting row count', $base_c === $count() );
	pqbg_t( 'products and variations back to the starting count', $base_prod === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')" ) );
	pqbg_t( 'no posts, meta or term relationships left above the starting post ID', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID > %d", $start_post ) ) + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id > %d", $start_post ) ) + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id > %d", $start_post ) ) );
	pqbg_t( 'no terms left above the starting term ID', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id > %d", $start_term ) ) );
	pqbg_t( 'uploaded test file removed', array() === array_filter( $files, 'file_exists' ) );
	pqbg_t( 'options restored exactly (settings, Coming Soon, permalinks, rules, flag, active plugins)', array() === array_filter( array_keys( $saved ), static fn( $n ) => $saved[ $n ] !== $raw_option( $n ) ) );
	pqbg_t( 'temporary users removed', $base_user === (int) count_users()['total_users'] && ! get_user_by( 'login', 'pqbg_p6_admin' ) );
	pqbg_t( 'temporary directory removed', ! file_exists( $tmp ) );
}

pqbg_test_done();
