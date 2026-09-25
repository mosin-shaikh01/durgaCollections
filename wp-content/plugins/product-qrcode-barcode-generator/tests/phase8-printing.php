<?php
/**
 * Phase 8 suite: label printing.
 *
 * Layout geometry for every preset and custom layouts (positions, start-at-N,
 * pages, invalid values); the QR minimum size (module counts for short and long
 * scan URLs, refusals, optional text dropped first); print-resolution round
 * trips (every label's QR and barcode rasterised at its printed size at 203 and
 * 300 dpi and decoded); the local-address TEST mark and confirmation; retired
 * codes never printed and items without codes skipped (never generated);
 * variable products, copies and the job limit; label fields and truncation;
 * barcodes on/off and library loading; the render cache (hit/miss,
 * invalidation, retired codes, bounded size, uninstall) and the 100/300-label
 * timings; permissions and nonces on every entry point over real HTTP; GET
 * never writes; escaping and headers; and, with the optional print-check
 * package, headless Chrome and Edge: CSP and console errors, the Print button,
 * print-media geometry, the rupee glyph, screenshots decoded at 203/300 dpi
 * and the PDF (page count, page size, text positions).
 *
 * Creates products, variations, codes and users, and removes them all.
 *
 *   php tests/phase8-printing.php
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

use ProductQrBarcode\{BarcodeRenderer, CodeRepository, Permissions, Plugin, PrintAdmin, PrintCache, PrintJob, PrintLayout, PrintPage, ProductCodeService, QrRenderer, ScanRoute, ScanUrl, Schema, Settings};

global $wpdb;

$C          = Schema::codes_table();
$as_mark    = pqbg_test_as_mark(); // Action Scheduler cleanup, see bootstrap.php.
$max        = static fn( string $table, string $col ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX($col), 0) FROM $table" );
$start_c    = $max( $C, 'id' );
$start_post = $max( $wpdb->posts, 'ID' );
$base_c     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" );
$base_prod  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')" );
$base_user  = (int) count_users()['total_users'];
$raw_option = static fn( string $name ) => $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
$saved      = array();
foreach ( array( Plugin::SETTINGS_OPTION, PrintCache::INDEX_OPTION, 'woocommerce_coming_soon', 'rewrite_rules', 'pqbg_rewrite_version', 'blogname' ) as $name ) {
	$saved[ $name ] = $raw_option( $name );
}
$user_ids = array();
$pw       = array();
$home     = untrailingslashit( home_url() );
$tmp      = sys_get_temp_dir() . '/pqbg-p8-' . wp_generate_password( 8, false );
wp_mkdir_p( $tmp );

add_filter( 'pre_wp_mail', '__return_false' ); // Local mail is not configured; a failing mail() takes about 2 s.

$decoder    = getenv( 'PQBG_DECODER' );
$decoder    = ( is_string( $decoder ) && '' !== $decoder ) ? $decoder : __DIR__ . '/decoder/decode.mjs';
$printcheck = getenv( 'PQBG_PRINTCHECK' );
$printcheck = ( is_string( $printcheck ) && '' !== $printcheck ) ? $printcheck : __DIR__ . '/print-check/check.mjs';
$browsers   = getenv( 'PQBG_BROWSERS' );
$browsers   = ( is_string( $browsers ) && '' !== $browsers ) ? explode( ';', $browsers ) : array( 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe', 'C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium' );
$browsers   = array_values( array_filter( $browsers, 'is_file' ) );
$node       = trim( (string) shell_exec( 'node --version 2>&1' ) );
$has_node   = (bool) preg_match( '/^v\d+/', $node );
$decoder_ok = $has_node && is_file( $decoder ) && is_dir( dirname( $decoder ) . '/node_modules' );
$browser_ok = $has_node && is_file( $printcheck ) && is_dir( dirname( $printcheck ) . '/node_modules' ) && array() !== $browsers;

/**
 * Runs the decoder on files; "--width=N" / "--zoom" arguments pass through. Null if it failed.
 */
$decode = static function ( array $args ) use ( $decoder ): ?array {
	$out  = shell_exec( 'node ' . escapeshellarg( $decoder ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1' );
	$json = json_decode( (string) $out, true );
	return is_array( $json ) ? $json : null;
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
	return $http( $who, 'POST', wp_login_url(), array( 'log' => $user, 'pwd' => $pass, 'wp-submit' => 'Log In', 'testcookie' => '1', 'redirect_to' => admin_url() ) );
};
/** A user's session cookies in the form puppeteer's page.setCookie() takes. */
$cookies_of = static function ( string $who ) use ( &$handles ): array {
	$list = array();
	foreach ( (array) curl_getinfo( $handles[ $who ], CURLINFO_COOKIELIST ) as $line ) {
		$f = explode( "\t", $line );
		if ( 7 === count( $f ) ) {
			$list[] = array(
				'name'   => $f[5],
				'value'  => $f[6],
				'domain' => ltrim( str_replace( '#HttpOnly_', '', $f[0] ), '.' ),
				'path'   => $f[2],
			);
		}
	}
	return $list;
};
$field = static function ( string $html, string $name ): string {
	preg_match_all( '/<input\b[^>]*>/i', $html, $m );
	foreach ( $m[0] as $tag ) {
		if ( preg_match( '/\bname=["\']' . preg_quote( $name, '/' ) . '["\']/', $tag ) && preg_match( '/\bvalue=["\']([^"\']*)["\']/', $tag, $v ) ) {
			return html_entity_decode( $v[1], ENT_QUOTES );
		}
	}
	return '';
};
$href_of = static function ( string $html, string $class ): string {
	return preg_match( '/<a class="[^"]*\b' . preg_quote( $class, '/' ) . '\b[^"]*" href="([^"]+)"/', $html, $m ) ? html_entity_decode( $m[1], ENT_QUOTES ) : '';
};

/** The setup screen URL for any selection, through the products list bulk action (as a real user would). */
$setup_url_for = static function ( string $who, array $ids ) use ( $http, $field ): string {
	$list = $http( $who, 'GET', admin_url( 'edit.php?post_type=product' ) );
	$args = array(
		'post_type' => 'product',
		'action'    => PrintAdmin::BULK_ACTION,
		'action2'   => '-1',
		'_wpnonce'  => $field( $list['body'], '_wpnonce' ),
		'post'      => $ids,
	);
	$r    = $http( $who, 'GET', admin_url( 'edit.php?' . preg_replace( '/%5B\d+%5D=/', '%5B%5D=', http_build_query( $args ) ) ) );
	return 302 === $r['code'] ? $r['location'] : '';
};
/** Opens the setup screen and submits its form with these options; returns the POST response (303 → print page). */
$prepare = static function ( string $who, string $setup_url, array $opt ) use ( $http, $field ): array {
	$page = $http( $who, 'GET', $setup_url );
	$post = array(
		'action'                 => PrintAdmin::PREPARE,
		'items'                  => $field( $page['body'], 'items' ),
		Permissions::NONCE_FIELD => $field( $page['body'], Permissions::NONCE_FIELD ),
		'opt'                    => $opt,
	);
	return $http( $who, 'POST', admin_url( 'admin-post.php' ), $post );
};
$print_url = static function ( string $who, array $ids, array $opt ) use ( $setup_url_for, $prepare ): string {
	$r = $prepare( $who, $setup_url_for( $who, $ids ), $opt );
	return 303 === $r['code'] && str_contains( $r['location'], 'action=' . PrintPage::ACTION ) ? $r['location'] : '';
};

/** Stores plugin settings exactly (no sanitize callback outside wp-admin). */
$set_settings = static function ( array $values ) use ( $wpdb ): void {
	update_option( Plugin::SETTINGS_OPTION, array_merge( array( 'settings_version' => 1 ), $values ) );
	wp_cache_delete( Plugin::SETTINGS_OPTION, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
};
$public_https = 'https://shop.example.com';
$public_http  = 'http://shop.example.com';

$code_of = static function ( int $id ): string {
	$row = CodeRepository::find_active_for_product( $id );
	return is_array( $row ) ? (string) $row['code'] : '';
};
$opts = static function ( array $raw ) {
	$o = PrintJob::options( $raw );
	if ( is_wp_error( $o ) ) {
		throw new RuntimeException( 'options: ' . $o->get_error_message() );
	}
	return $o;
};
$build = static function ( array $ids, array $raw, bool $confirm = true ) use ( $opts ) {
	return PrintPage::build( $ids, $opts( $raw ), $confirm );
};
/** Checksum of everything a GET must not change (render cache excluded; volatile core rows listed). */
$state = static function ( bool $skip_prefs = false ) use ( $wpdb, $C ): string {
	$S = Schema::sales_table();
	return md5(
		implode(
			'|',
			array(
				$wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, code, status, COALESCE(active_product_id, 0), COALESCE(retired_at_gmt, '')))), 0)) FROM $C" ),
				$wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', id, status))), 0)) FROM $S" ),
				$wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', ID, post_status, post_modified_gmt, post_title))), 0)) FROM {$wpdb->posts}" ),
				$wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', meta_id, meta_key, meta_value))), 0)) FROM {$wpdb->postmeta}" ),
				$wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', umeta_id, meta_key, meta_value))), 0)) FROM {$wpdb->usermeta} WHERE meta_key NOT IN ('wc_last_active', 'session_tokens'" . ( $skip_prefs ? ", '" . PrintJob::PREFS_META . "'" : '' ) . ')' ),
				$wpdb->get_var( "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', option_name, option_value))), 0)) FROM {$wpdb->options} WHERE option_name NOT LIKE '%transient%' AND option_name NOT IN ('cron', '" . PrintCache::INDEX_OPTION . "', '" . Plugin::SETTINGS_OPTION . "') AND option_name NOT LIKE 'action_scheduler%'" ),
			)
		)
	);
};
$headers_ok = static function ( array $r ): bool {
	foreach ( ScanRoute::security_headers() as $name => $value ) {
		if ( 'Content-Security-Policy' !== $name && ( $r['headers'][ strtolower( $name ) ] ?? null ) !== $value ) {
			return false;
		}
	}
	$csp = $r['headers']['content-security-policy'] ?? '';
	return (bool) preg_match( "/^default-src 'none'; style-src 'self' 'nonce-([A-Za-z0-9+\/=]{24})'; script-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'$/", $csp );
};
$csp_nonce = static fn( array $r ): string => preg_match( "/'nonce-([^']+)'/", $r['headers']['content-security-policy'] ?? '', $m ) ? $m[1] : '';
$labels_in = static fn( string $html ): int => preg_match_all( '/<div class="pqbg-label pqbg-s\d+"/', $html );
$median    = static function ( array $v ): float {
	sort( $v );
	return (float) $v[ intdiv( count( $v ), 2 ) ];
};

/** A simple product saved by $as (codes are assigned when $as manages codes; not for user 0). */
$make_simple = static function ( int $as, string $name, array $props = array() ): int {
	wp_set_current_user( $as );
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_status( $props['status'] ?? 'publish' );
	$p->set_regular_price( $props['price'] ?? '1499' );
	$p->set_sku( $props['sku'] ?? 'PQBG-P8-' . wp_generate_password( 8, false ) );
	if ( isset( $props['stock'] ) ) {
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $props['stock'] );
		$p->set_low_stock_amount( 0 );
	}
	$id = $p->save();
	wp_set_current_user( 0 );
	return $id;
};

try {
	pqbg_section( 'setup' );
	foreach ( array( 'admin' => 'administrator', 'sm' => 'shop_manager', 'seller' => 'pqbg_seller', 'customer' => 'customer', 'subscriber' => 'subscriber' ) as $who => $role ) {
		$pw[ $who ]       = wp_generate_password( 24, false );
		$user_ids[ $who ] = wp_insert_user( array( 'user_login' => "pqbg_p8_{$who}", 'user_pass' => $pw[ $who ], 'user_email' => "pqbg-p8-{$who}@example.invalid", 'role' => $role ) );
	}
	pqbg_t( 'temporary users created', 5 === count( array_filter( $user_ids, 'is_int' ) ) );
	$A  = $user_ids['admin'];
	$SM = $user_ids['sm'];
	pqbg_t( 'printing needs pqbg_manage_codes: admin and shop manager only', Permissions::can_manage_codes( $A ) && Permissions::can_manage_codes( $SM ) && ! Permissions::can_manage_codes( $user_ids['seller'] ) && ! Permissions::can_manage_codes( $user_ids['customer'] ) && ! Permissions::can_manage_codes( $user_ids['subscriber'] ) && ! Permissions::can_manage_codes( 0 ) );
	foreach ( array_keys( $user_ids ) as $who ) {
		$login( $who, "pqbg_p8_{$who}", $pw[ $who ] );
	}
	pqbg_t( 'admin logged in over HTTP (Dashboard 200)', 200 === $http( 'admin', 'GET', admin_url() )['code'] );
	PrintCache::clear_all();
	$set_settings( array() );

	// Fixtures. Codes are assigned on save by an admin (Phase 5); user 0 gets none.
	$simple  = $make_simple( $A, 'PQBG P8 Kurta', array( 'sku' => 'P8-KURTA', 'price' => '1499', 'stock' => 3 ) );
	$html    = $make_simple( $A, '<script>alert(1)</script> Saree "A&B" <3 end', array( 'sku' => '<img src=x onerror=alert(2)>', 'price' => '999.5' ) );
	$long    = $make_simple( $A, str_repeat( 'Very long product name ', 10 ) . 'END', array( 'sku' => 'P8-' . str_repeat( 'L', 40 ), 'price' => '123456.78' ) );
	$zero    = $make_simple( $A, 'PQBG P8 sold out', array( 'stock' => 0 ) );
	$nocode  = $make_simple( 0, 'PQBG P8 no code yet' );
	$draft   = $make_simple( $A, 'PQBG P8 draft', array( 'status' => 'draft' ) );
	$trashed = $make_simple( $A, 'PQBG P8 trashed' );
	$retire  = $make_simple( $A, 'PQBG P8 to be regenerated' );
	wp_trash_post( $trashed );
	wp_set_current_user( $A );
	$grouped = new WC_Product_Grouped();
	$grouped->set_name( 'PQBG P8 grouped' );
	$grouped->set_status( 'publish' );
	$grouped = $grouped->save();
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( array( 'S', 'M', 'L' ) );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$var1 = new WC_Product_Variable();
	$var1->set_name( 'PQBG P8 Lehenga' );
	$var1->set_status( 'publish' );
	$var1->set_attributes( array( $attr ) );
	$var1 = $var1->save();
	$vmake = static function ( int $parent, string $size, array $props, int $as ): int {
		wp_set_current_user( $as );
		$v = new WC_Product_Variation();
		$v->set_parent_id( $parent );
		$v->set_attributes( array( 'size' => $size ) );
		$v->set_status( 'publish' );
		$v->set_regular_price( $props['price'] ?? '1500' );
		$v->set_sku( 'P8-VAR-' . $size . '-' . $parent );
		if ( isset( $props['stock'] ) ) {
			$v->set_manage_stock( true );
			$v->set_stock_quantity( $props['stock'] );
			$v->set_low_stock_amount( 0 );
		}
		$id = $v->save();
		wp_set_current_user( 0 );
		return $id;
	};
	$v_own     = $vmake( $var1, 'S', array( 'stock' => 2 ), $A );
	$v_free    = $vmake( $var1, 'M', array( 'price' => '1600' ), $A );
	$v_nocode  = $vmake( $var1, 'L', array(), 0 );
	wp_set_current_user( $A );
	$var2 = new WC_Product_Variable();
	$var2->set_name( 'PQBG P8 Dupatta' );
	$var2->set_status( 'publish' );
	$var2->set_attributes( array( $attr ) );
	$var2->set_manage_stock( true );
	$var2->set_stock_quantity( 5 );
	$var2 = $var2->save();
	$v_parent = $vmake( $var2, 'S', array(), $A );
	wp_set_current_user( 0 );
	wc_delete_product_transients( $var1 );
	wc_delete_product_transients( $var2 );
	$with_code = array( $simple, $html, $long, $zero, $draft, $trashed, $retire, $v_own, $v_free, $v_parent );
	pqbg_t( 'fixtures: codes for the admin-saved items, none for the user-0 saves', array() === array_filter( $with_code, static fn( $id ) => '' === $code_of( $id ) ) && '' === $code_of( $nocode ) && '' === $code_of( $v_nocode ) );
	pqbg_t( 'fixtures: stock management as intended (own, none, parent)', true === wc_get_product( $v_own )->get_manage_stock() && false === wc_get_product( $v_free )->get_manage_stock() && 'parent' === wc_get_product( $v_parent )->get_manage_stock() );

	pqbg_section( 'Action Scheduler cleanup (the Phase 5 leak fixed in Phase 8)' );
	$gone = $make_simple( $A, 'PQBG P8 deleted mid-suite' );
	wc_get_product( $gone )->delete( true );
	$left = pqbg_test_as_leftovers( $as_mark );
	pqbg_t( 'jobs of a product deleted mid-suite are still found (matched on allocated IDs, not surviving posts)', (bool) array_filter( $left['actions'], static fn( $a ) => str_contains( (string) $a['args'], (string) $gone ) ) );

	pqbg_section( 'layout geometry' );
	$presets = PrintLayout::presets();
	pqbg_t( 'seven presets: four A4 sheets, three thermal', array( 'a4-3x7', 'a4-3x8', 'a4-4x10', 'a4-5x13', 'th-50x25', 'th-38x25', 'th-100x50' ) === array_keys( $presets ) && PrintLayout::DEFAULT_PRESET === 'a4-3x7' );
	$expected = array(
		'a4-3x7'  => array( 3, 7, 63.5, 38.1, 7.25, 15.15, 2.5, 0.0, 21 ),
		'a4-3x8'  => array( 3, 8, 70.0, 37.0, 0.0, 0.5, 0.0, 0.0, 24 ),
		'a4-4x10' => array( 4, 10, 48.5, 25.4, 8.0, 21.5, 0.0, 0.0, 40 ),
		'a4-5x13' => array( 5, 13, 38.1, 21.2, 4.75, 10.7, 2.5, 0.0, 65 ),
	);
	foreach ( $expected as $id => $e ) {
		$s   = $presets[ $id ];
		$ok  = array( $s['cols'], $s['rows'], $s['label_w'], $s['label_h'], $s['left'], $s['top'], $s['gap_x'], $s['gap_y'], PrintLayout::per_sheet( $s ) ) == $e && 210.0 === $s['page_w'] && 297.0 === $s['page_h'];
		$sum = array( $s['left'] * 2 + $s['cols'] * $s['label_w'] + ( $s['cols'] - 1 ) * $s['gap_x'], $s['top'] * 2 + $s['rows'] * $s['label_h'] + ( $s['rows'] - 1 ) * $s['gap_y'] );
		$pos = true;
		for ( $slot = 0; $slot < PrintLayout::per_sheet( $s ); $slot++ ) {
			[ $x, $y ] = PrintLayout::slot_position( $s, $slot );
			$pos       = $pos && abs( $x - ( $s['left'] + ( $slot % $s['cols'] ) * ( $s['label_w'] + $s['gap_x'] ) ) ) < 1e-6 && abs( $y - ( $s['top'] + intdiv( $slot, $s['cols'] ) * ( $s['label_h'] + $s['gap_y'] ) ) ) < 1e-6;
		}
		pqbg_t( "$id: exact spec, rows and columns sum to exactly 210 × 297 mm, every slot position", $ok && abs( $sum[0] - 210 ) < 1e-6 && abs( $sum[1] - 297 ) < 1e-6 && $pos, implode( ' × ', $sum ) );
	}
	pqbg_t( 'a4-3x7 last slot at (139.25, 243.75) mm; a4-5x13 last slot at (167.15, 265.1) mm', array( 139.25, 243.75 ) == PrintLayout::slot_position( $presets['a4-3x7'], 20 ) && array( 167.15, 265.1 ) == PrintLayout::slot_position( $presets['a4-5x13'], 64 ) );
	foreach ( array( 'th-50x25' => array( 50, 25, 1.5 ), 'th-38x25' => array( 38, 25, 1.5 ), 'th-100x50' => array( 100, 50, 2.0 ) ) as $id => $e ) {
		$s = $presets[ $id ];
		pqbg_t( "$id: page = label ({$e[0]} × {$e[1]} mm), one per page, 203 dpi, inset {$e[2]} mm", 'thermal' === $s['type'] && (float) $e[0] === $s['page_w'] && (float) $e[1] === $s['page_h'] && $s['page_w'] === $s['label_w'] && 1 === PrintLayout::per_sheet( $s ) && 203 === $s['dpi'] && (float) $e[2] === $s['inset_x'] && array( 0.0, 0.0 ) === PrintLayout::slot_position( $s, 0, 3.0, 3.0 ) );
	}
	$pg = PrintLayout::paginate( $presets['a4-3x7'], 30, 5 );
	pqbg_t( 'start at position 5, 30 labels on 3 × 7: sheet 1 slots 4–20, sheet 2 slots 0–12', 30 === count( $pg ) && array( 'sheet' => 0, 'slot' => 4 ) === $pg[0] && array( 'sheet' => 0, 'slot' => 20 ) === $pg[16] && array( 'sheet' => 1, 'slot' => 0 ) === $pg[17] && array( 'sheet' => 1, 'slot' => 12 ) === $pg[29] );
	$pg = PrintLayout::paginate( $presets['a4-3x7'], 21, 1 );
	pqbg_t( 'exactly one full sheet: 21 labels from position 1 → one page (no trailing blank page)', 0 === end( $pg )['sheet'] && 20 === end( $pg )['slot'] );
	$pg = PrintLayout::paginate( $presets['a4-3x7'], 1, 21 );
	pqbg_t( 'start at the last position (21) puts the label in slot 20', array( array( 'sheet' => 0, 'slot' => 20 ) ) === $pg );
	$pg = PrintLayout::paginate( $presets['th-50x25'], 3, 9 );
	pqbg_t( 'thermal: start position ignored, one label per page', array( array( 'sheet' => 0, 'slot' => 0 ), array( 'sheet' => 1, 'slot' => 0 ), array( 'sheet' => 2, 'slot' => 0 ) ) === $pg );
	pqbg_t( 'printer offset moves sheet labels only', array( 8.75, 14.15 ) == PrintLayout::slot_position( $presets['a4-3x7'], 0, 1.5, -1.0 ) );

	$custom_ok = array(
		'type'    => 'sheet',
		'page_w'  => '215.9',
		'page_h'  => '279.4',
		'label_w' => '66.68',
		'label_h' => '25.4',
		'cols'    => '3',
		'rows'    => '10',
		'left'    => '4.76',
		'top'     => '12.7',
		'gap_x'   => '3.18',
		'gap_y'   => '0',
	);
	$c = PrintLayout::custom( $custom_ok );
	pqbg_t( 'custom: a US Letter 3 × 10 sheet is accepted', is_array( $c ) && 'custom' === $c['id'] && 30 === PrintLayout::per_sheet( $c ) && 1.5 === $c['inset_x'] );
	$c = PrintLayout::custom( array( 'type' => 'thermal', 'dpi' => '300', 'label_w' => '60', 'label_h' => '40', 'cols' => 'ignored' ) );
	pqbg_t( 'custom thermal: page = label, 1 × 1, 300 dpi', is_array( $c ) && 60.0 === $c['page_w'] && 40.0 === $c['page_h'] && 1 === $c['cols'] && 300 === $c['dpi'] );
	$bad = array(
		'no type'              => array( 'type' => '' ),
		'unknown type'         => array( 'type' => 'roll' ),
		'thermal dpi 250'      => array( 'type' => 'thermal', 'dpi' => '250', 'label_w' => '50', 'label_h' => '25' ),
		'thermal dpi missing'  => array( 'type' => 'thermal', 'label_w' => '50', 'label_h' => '25' ),
		'label too narrow'     => array( 'label_w' => '9.99' ),
		'label too wide'       => array( 'label_w' => '300.01' ),
		'negative margin'      => array( 'left' => '-1' ),
		'three decimals'       => array( 'label_h' => '25.401' ),
		'comma decimal'        => array( 'label_h' => '25,4' ),
		'exponent'             => array( 'label_h' => '2e1' ),
		'text'                 => array( 'label_h' => 'abc' ),
		'empty'                => array( 'label_h' => '' ),
		'array value'          => array( 'label_h' => array( '25' ) ),
		'columns 0'            => array( 'cols' => '0' ),
		'columns 21'           => array( 'cols' => '21' ),
		'columns decimal'      => array( 'cols' => '2.5' ),
		'rows 51'              => array( 'rows' => '51' ),
		'more than 500'        => array( 'cols' => '20', 'rows' => '26', 'label_w' => '10', 'label_h' => '10', 'page_w' => '1000', 'page_h' => '1000', 'gap_x' => '0', 'left' => '0', 'top' => '0' ),
		'too wide for page'    => array( 'cols' => '4' ),
		'too high for page'    => array( 'rows' => '12' ),
		'page too small'       => array( 'page_w' => '19' ),
		'page too big'         => array( 'page_h' => '1000.5' ),
		'gap too big'          => array( 'gap_y' => '50.5' ),
		'margin too big'       => array( 'top' => '100.01' ),
		'HTML'                 => array( 'label_w' => '<b>60</b>' ),
		'huge number'          => array( 'label_w' => '99999' ),
	);
	$rejected = array();
	foreach ( $bad as $name => $change ) {
		$r = PrintLayout::custom( array_merge( $custom_ok, $change ) );
		if ( is_wp_error( $r ) && 'pqbg_invalid_layout' === $r->get_error_code() && isset( $r->get_error_data()['field'] ) ) {
			$rejected[] = $name;
		}
	}
	pqbg_t( 'custom: ' . count( $bad ) . ' invalid layouts rejected with a field-specific error', count( $bad ) === count( $rejected ), implode( ', ', array_diff( array_keys( $bad ), $rejected ) ) );
	pqbg_t( 'custom: not a list → rejected', is_wp_error( PrintLayout::custom( 'sheet' ) ) );

	pqbg_section( 'print options' );
	$o = PrintJob::options( array() );
	pqbg_t( 'defaults: A4 3 × 7, start 1, 1 copy, name + attributes + SKU + price (no store name), no offset', is_array( $o ) && 'a4-3x7' === $o['layout'] && 1 === $o['start'] && 'fixed' === $o['copies_mode'] && 1 === $o['copies'] && PrintJob::defaults()['fields'] === array( 'name', 'attributes', 'sku', 'price' ) && 0.0 === $o['dx'] );
	$bad_opts = array(
		'unknown layout'    => array( 'layout' => 'a4-9x9' ),
		'start 0'           => array( 'start' => '0' ),
		'start 22 on 3×7'   => array( 'start' => '22' ),
		'start text'        => array( 'start' => 'x' ),
		'copies 0'          => array( 'copies' => '0' ),
		'copies 101'        => array( 'copies' => '101' ),
		'copies decimal'    => array( 'copies' => '1.5' ),
		'copies mode'       => array( 'copies_mode' => 'all' ),
		'unknown field'     => array( 'fields' => array( 'name', 'cost' ) ),
		'fields not a list' => array( 'fields' => 'name' ),
		'offset 5.01'       => array( 'dx' => '5.01' ),
		'offset -6'         => array( 'dy' => '-6' ),
		'offset text'       => array( 'dx' => 'left' ),
		'bad custom'        => array( 'layout' => 'custom', 'custom' => array( 'type' => 'sheet' ) ),
	);
	$rejected = array_filter( $bad_opts, static fn( $in ) => is_wp_error( PrintJob::options( $in ) ) );
	pqbg_t( 'options: ' . count( $bad_opts ) . ' invalid inputs rejected', count( $bad_opts ) === count( $rejected ), implode( ', ', array_keys( array_diff_key( $bad_opts, $rejected ) ) ) );
	$o = PrintJob::options( array( 'layout' => 'th-50x25', 'start' => '9', 'dx' => '2', 'fields' => array() ) );
	pqbg_t( 'thermal: start and printer offset ignored; no optional fields allowed', is_array( $o ) && 1 === $o['start'] && 0.0 === $o['dx'] && array() === $o['fields'] );
	$o = PrintJob::options( array( 'dx' => '-5', 'dy' => '4.99', 'copies_mode' => 'stock', 'copies' => 'ignored' ) );
	pqbg_t( 'offsets ±5 mm accepted; copies ignored in stock mode', is_array( $o ) && -5.0 === $o['dx'] && 4.99 === $o['dy'] && 'stock' === $o['copies_mode'] );
	$q = PrintJob::query_args( $opts( array( 'layout' => 'custom', 'custom' => $custom_ok, 'start' => '3', 'fields' => array( 'sku' ) ) ) );
	pqbg_t( 'query args round-trip through options()', $opts( $q['opt'] )['spec'] == $opts( array( 'layout' => 'custom', 'custom' => $custom_ok ) )['spec'] && 3 === $opts( $q['opt'] )['start'] && array( 'sku' ) === $opts( $q['opt'] )['fields'] );
	$p = PrintJob::sanitize_prefs( array( 'layout' => '<b>a4</b>', 'custom' => array( 'label_w' => '"60"' ), 'fields' => array( 'name', 'evil' ), 'extra' => 'x' ) );
	pqbg_t( 'remembered options keep only known keys as short plain strings', 'ba4b' === $p['layout'] && '60' === $p['custom']['label_w'] && array( 'name' ) === $p['fields'] && ! isset( $p['extra'] ) );

	pqbg_section( 'QR minimum size' );
	pqbg_t( 'minimum module 0.40 mm, maximum 0.99 mm', 0.40 === PrintLayout::MIN_MODULE_MM && 0.99 === PrintLayout::MAX_MODULE_MM );
	$bases = array(
		24  => 'http://localhost/sharayu',
		38  => 'https://' . str_repeat( 'a', 25 ) . '.shop',
		39  => 'https://' . str_repeat( 'a', 26 ) . '.shop',
		60  => 'https://' . str_repeat( 'a', 47 ) . '.shop',
		61  => 'https://' . str_repeat( 'a', 48 ) . '.shop',
		82  => 'https://example.shop/' . str_repeat( 'b', 61 ),
		83  => 'https://example.shop/' . str_repeat( 'b', 62 ),
		98  => 'https://example.shop/' . str_repeat( 'b', 77 ),
		99  => 'https://example.shop/' . str_repeat( 'b', 78 ),
		100 => 'https://example.shop/' . str_repeat( 'b', 79 ),
	);
	$want    = array( 24 => 41, 38 => 41, 39 => 45, 60 => 45, 61 => 49, 82 => 49, 83 => 53, 98 => 53, 99 => 57, 100 => 57 );
	$got     = array();
	$sample  = $code_of( $simple );
	foreach ( $bases as $len => $base ) {
		$set_settings( array( 'scan_base_url' => 24 === $len ? '' : $base ) );
		$got[ $len ] = strlen( ScanUrl::base() ) === $len ? PrintLayout::qr_modules( ( new QrRenderer() )->render( $sample ) ) : -1;
	}
	$set_settings( array() );
	pqbg_t( 'module counts (quiet zone included) from the real encoding: V4 up to 38 characters of base URL, then V5, V6, V7, V8 at 99–100', $want === $got, wp_json_encode( $got ) );
	$fit_table = array(
		// preset => [ N => expected module (null = refused) ].
		'a4-3x7'    => array( 41 => 0.856, 57 => 0.615 ),
		'a4-3x8'    => array( 41 => 0.78, 57 => 0.561 ),
		'a4-4x10'   => array( 41 => 0.546, 53 => 0.422, 57 => null ),
		'a4-5x13'   => array( 41 => 0.468, 45 => 0.426, 49 => null, 57 => null ),
		'th-50x25'  => array( 41 => round( 4 * 25.4 / 203, 5 ), 45 => 0.488, 53 => 0.415, 57 => null ),
		'th-38x25'  => array( 41 => 0.475, 45 => 0.433, 49 => 0.448, 57 => null ),
		'th-100x50' => array( 41 => round( 7 * 25.4 / 203, 5 ), 53 => round( 6 * 25.4 / 203, 5 ) ),
	);
	$all    = PrintLayout::OPTIONAL_FIELDS;
	$wrong  = array();
	$sanity = true;
	foreach ( $fit_table as $id => $rows ) {
		foreach ( $rows as $n => $module ) {
			$f = PrintLayout::fit( $presets[ $id ], $n, $all, false, false );
			if ( null === $module ? ! is_wp_error( $f ) : ( is_wp_error( $f ) || abs( $f['module'] - $module ) > 1e-6 ) ) {
				$wrong[] = "$id/$n";
				continue;
			}
			if ( ! is_wp_error( $f ) ) {
				$s       = $presets[ $id ];
				$sanity  = $sanity && $f['module'] >= 0.40 && abs( $f['qr'] - $f['module'] * $n ) < 1e-3 && $f['qr_x'] >= $s['inset_x'] - 1e-6 && $f['qr_y'] >= $s['inset_y'] - 1e-6 && $f['qr_x'] + $f['qr'] <= $s['label_w'] - $s['inset_x'] + 1e-6 && $f['qr_y'] + $f['qr'] <= $s['label_h'] - $s['inset_y'] + 1e-6 && $f['text']['x'] >= $f['qr_x'] + $f['qr'] - 1e-6 && $f['text']['x'] + $f['text']['w'] <= $s['label_w'] - $s['inset_x'] + 1e-6;
			}
		}
	}
	pqbg_t( 'fit: module size (or refusal) exactly as planned for every preset and short/long scan URLs', array() === $wrong, implode( ', ', $wrong ) );
	pqbg_t( 'fit: QR ≥ 0.40 mm modules, quiet zone inside the safe inset, text column right of the QR', $sanity );
	$f = PrintLayout::fit( $presets['th-38x25'], 49, $all, false, false );
	pqbg_t( 'optional text is dropped before refusing (38 × 25 mm, V6): code only, all five fields dropped', is_array( $f ) && array() === $f['fields'] && $all === $f['dropped'] && 2 === $f['code_lines'] );
	$f = PrintLayout::fit( $presets['a4-5x13'], 41, $all, false, true );
	pqbg_t( 'lines run out: the TEST line and the code are kept, the lowest-priority field (store name) goes', is_array( $f ) && array( 'store' ) === $f['dropped'] && true === $f['test_mark'] );
	$f = PrintLayout::fit( $presets['a4-5x13'], 49, $all, false, false );
	pqbg_t( 'refusal explains the needed QR size and the room available', is_wp_error( $f ) && 'pqbg_layout_too_small' === $f->get_error_code() && str_contains( $f->get_error_message(), '19.6 mm' ) && str_contains( $f->get_error_message(), '49 × 49' ) );
	$exact = PrintLayout::custom( array_merge( $custom_ok, array( 'label_w' => '40', 'label_h' => '19.4', 'cols' => '1', 'rows' => '1' ) ) );
	$f     = PrintLayout::fit( $exact, 41, array(), false, false );
	pqbg_t( 'a label with room for exactly 0.40 mm modules is accepted (QR 16.4 mm)', is_array( $f ) && abs( $f['module'] - 0.40 ) < 1e-9 && abs( $f['qr'] - 16.4 ) < 1e-6 );
	$f = PrintLayout::fit( PrintLayout::custom( array_merge( $custom_ok, array( 'label_w' => '40', 'label_h' => '19.39', 'cols' => '1', 'rows' => '1' ) ) ), 41, array(), false, false );
	pqbg_t( '…and 0.01 mm less is refused', is_wp_error( $f ) );
	$f = PrintLayout::fit( $presets['th-50x25'], 41, $all, false, false );
	pqbg_t( 'thermal 203 dpi: modules snapped to whole dots (4 dots = 0.5005 mm) when that stays ≥ 0.40 mm', is_array( $f ) && abs( $f['module'] - 4 * 25.4 / 203 ) < 1e-5 );
	$f = PrintLayout::fit( $presets['th-38x25'], 41, $all, false, false );
	pqbg_t( '…and not snapped when 3 dots (0.375 mm) would be below the minimum', is_array( $f ) && abs( $f['module'] - 0.475 ) < 1e-6 );
	$f = PrintLayout::fit( $presets['a4-5x13'], 41, array(), false, false );
	pqbg_t( 'the code text wraps to two lines on narrow labels, and fits', is_array( $f ) && 2 === $f['code_lines'] && 9 * PrintLayout::MONO_EM * $f['font_pt'] * PrintLayout::PT_MM <= $f['text']['w'] );

	pqbg_section( 'job: items, skipped items, copies, limit' );
	wp_set_current_user( $A );
	$codes_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" );
	$r            = PrintJob::resolve( array( $var1 ) );
	pqbg_t( 'a variable product expands to its variations with codes; the one without is skipped (no code yet, with a link)', array( $v_own, $v_free ) === array_column( $r['items'], 'id' ) && 1 === count( $r['skipped'] ) && $v_nocode === $r['skipped'][0]['id'] && 'no_code' === $r['skipped'][0]['reason'] && str_contains( $r['skipped'][0]['edit_url'], 'post=' . $var1 ) );
	$page_id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'PQBG P8 page', 'post_status' => 'draft' ) );
	$r       = PrintJob::resolve( array( $var1, $v_own, $simple, $nocode, $trashed, $grouped, 999999999, $page_id, $draft, $html ) );
	$reasons = array_column( $r['skipped'], 'reason', 'id' );
	pqbg_t( 'mixed selection: items in order, a variation listed twice printed once', array( $v_own, $v_free, $simple, $draft, $html ) === array_column( $r['items'], 'id' ) );
	pqbg_t( 'skipped: no code yet (product and variation), in the trash, grouped (no code type), not found (missing ID, a page)', array( $v_nocode => 'no_code', $nocode => 'no_code', $trashed => 'trash', $grouped => 'type', 999999999 => 'not_found', $page_id => 'not_found' ) == $reasons );
	pqbg_t( 'printing never generates a code (codes table unchanged, the items still have none)', $codes_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" ) && '' === $code_of( $nocode ) && '' === $code_of( $v_nocode ) );
	pqbg_t( 'only ACTIVE codes: every printed code is the item\'s current active code', array() === array_filter( $r['items'], static fn( $i ) => $i['code'] !== $code_of( $i['id'] ) ) );
	$vi = $r['items'][0];
	pqbg_t( 'variation label data: parent name, "Size: S", SKU, price with ₹', 'PQBG P8 Lehenga' === $vi['name'] && 'Size: S' === $vi['attributes'] && 'P8-VAR-S-' . $var1 === $vi['sku'] && '₹1,500.00' === $vi['price'] );
	pqbg_t( 'a draft item is printed and flagged as not published', 'draft' === $r['items'][3]['status'] && 'publish' === $r['items'][2]['status'] );

	$items = PrintJob::resolve( array( $simple, $var1 ) )['items'];
	$l     = PrintJob::labels( $items, $opts( array( 'copies' => '3' ) ) );
	pqbg_t( 'copies = 3: three labels per item, in item order', is_array( $l ) && array( 0, 0, 0, 1, 1, 1, 2, 2, 2 ) === $l['labels'] && 9 === $l['total'] );
	$items = PrintJob::resolve( array( $simple, $v_own, $v_free, $v_parent, $zero ) )['items'];
	$l     = PrintJob::labels( $items, $opts( array( 'copies_mode' => 'stock' ) ) );
	$per   = is_array( $l ) ? array_count_values( array_map( static fn( $i ) => $items[ $i ]['id'], $l['labels'] ) ) : array();
	pqbg_t( 'copies = stock: own stock 3 and 2 → 3 and 2 labels; untracked → 1; parent-level → 1; stock 0 → skipped', array( $simple => 3, $v_own => 2, $v_free => 1, $v_parent => 1 ) == $per && isset( $l['notes'][ $v_free ], $l['notes'][ $v_parent ] ) && str_contains( $l['notes'][ $v_parent ], 'shared' ) && str_contains( $l['notes'][ $v_free ], 'not tracked' ) && array( $zero ) === array_column( $l['skipped'], 'id' ) && 'out_of_stock' === $l['skipped'][0]['reason'] );
	$items = PrintJob::resolve( array( $simple, $v_own, $v_free ) )['items'];
	$l300  = PrintJob::labels( $items, $opts( array( 'copies' => '100' ) ) );
	$items4 = PrintJob::resolve( array( $simple, $v_own, $v_free, $html ) )['items'];
	$l400  = PrintJob::labels( $items4, $opts( array( 'copies' => '100' ) ) );
	pqbg_t( 'job limit: 300 labels allowed, 400 refused with a clear message', is_array( $l300 ) && 300 === $l300['total'] && is_wp_error( $l400 ) && 'pqbg_print_too_many_labels' === $l400->get_error_code() && str_contains( $l400->get_error_message(), '400 labels; the maximum is 300' ) );
	$p = wc_get_product( $simple );
	$p->set_stock_quantity( 299 );
	$p->save();
	$l301 = PrintJob::labels( PrintJob::resolve( array( $simple, $v_own ) )['items'], $opts( array( 'copies_mode' => 'stock' ) ) );
	$p->set_stock_quantity( 3 );
	$p->save();
	pqbg_t( 'job limit also caps "one per unit in stock" (299 + 2 = 301 refused)', is_wp_error( $l301 ) && 301 === $l301->get_error_data()['total'] );
	pqbg_t( 'nothing to print → a clear error', is_wp_error( PrintJob::labels( PrintJob::resolve( array( $nocode ) )['items'], $opts( array() ) ) ) );
	pqbg_t( 'selection: duplicates removed, order kept; 300 IDs allowed, 301 refused; malformed refused', array( 5, 3 ) === PrintJob::parse_ids( '5,3,5' ) && 300 === count( PrintJob::parse_ids( range( 1, 300 ) ) ) && 'pqbg_print_too_many_items' === PrintJob::parse_ids( range( 1, 301 ) )->get_error_code() && is_wp_error( PrintJob::parse_ids( '1,a' ) ) && is_wp_error( PrintJob::parse_ids( '' ) ) && is_wp_error( PrintJob::parse_ids( '0' ) ) && is_wp_error( PrintJob::parse_ids( '-3' ) ) && is_wp_error( PrintJob::parse_ids( array( array( 1 ) ) ) ) );
	pqbg_t( 'nonce action bound to the selection: order and duplicates do not matter, contents do', PrintJob::nonce_action( 'print_view', array( 3, 5 ) ) === PrintJob::nonce_action( 'print_view', array( 5, 3, 3 ) ) && PrintJob::nonce_action( 'print_view', array( 3, 5 ) ) !== PrintJob::nonce_action( 'print_view', array( 3, 5, 6 ) ) && PrintJob::nonce_action( 'print_view', array( 3 ) ) !== PrintJob::nonce_action( 'print_prepare', array( 3 ) ) );

	pqbg_section( 'print page (in-process): TEST mark, warnings, fields, retired codes, escaping' );
	$set_settings( array() );
	$v = $build( array( $simple ), array(), false );
	pqbg_t( 'local scan URL, not confirmed: confirmation page, no labels rendered', is_array( $v ) && 'confirm' === $v['mode'] && ! isset( $v['labels'] ) && str_contains( $v['confirm_url'], PrintPage::CONFIRM_ARG . '=1' ) );
	$h = PrintPage::render( $v );
	pqbg_t( '…with the big warning and "Print TEST labels anyway"', 0 === $labels_in( $h ) && str_contains( $h, 'Print TEST labels anyway' ) && str_contains( $h, 'pqbg-warning--big' ) && str_contains( $h, 'http://localhost/sharayu' ) );
	$v = $build( array( $simple, $v_own ), array( 'copies' => '2' ), true );
	$h = PrintPage::render( $v );
	pqbg_t( 'local, confirmed: every label carries "TEST – NOT FOR USE"', 4 === $labels_in( $h ) && 4 === substr_count( $h, '<div class="pqbg-l pqbg-l-test">TEST – NOT FOR USE</div>' ) && str_contains( $h, 'pqbg-test-notice' ) );
	$set_settings( array( 'scan_base_url' => $public_https ) );
	$v = $build( array( $simple, $v_own ), array( 'copies' => '2' ), false );
	$h = PrintPage::render( $v );
	pqbg_t( 'public https:// scan URL: straight to the labels, no TEST mark, no warning', 'labels' === $v['mode'] && 4 === $labels_in( $h ) && ! str_contains( $h, 'pqbg-l-test' ) && ! str_contains( $h, 'pqbg-test-notice' ) && ! str_contains( $h, 'pqbg-http-notice' ) && ! str_contains( $h, 'pqbg-warning' ) );
	$set_settings( array( 'scan_base_url' => $public_http ) );
	$h = PrintPage::render( $build( array( $simple ), array(), false ) );
	pqbg_t( 'public http:// scan URL: the Phase 4 https warning on the page, no mark on the labels', 1 === $labels_in( $h ) && str_contains( $h, 'pqbg-http-notice' ) && str_contains( $h, 'Labels should use an https:// scan URL in production.' ) && ! str_contains( $h, 'pqbg-l-test' ) );
	$set_settings( array( 'scan_base_url' => $public_https ) );

	$fields_of = static function ( string $h ): array {
		preg_match_all( '/<div class="pqbg-l pqbg-l-([a-z]+)">/', $h, $m );
		return array_values( array_unique( $m[1] ) );
	};
	$h = PrintPage::render( $build( array( $v_own ), array( 'fields' => array( 'name', 'attributes', 'sku', 'price' ) ) ) );
	pqbg_t( 'fields (defaults): name, attributes, SKU, price and the code; no store name', array( 'name', 'attributes', 'sku', 'price', 'code' ) === $fields_of( $h ) && str_contains( $h, '<div class="pqbg-l pqbg-l-price">₹1,500.00</div>' ) );
	$h = PrintPage::render( $build( array( $v_own ), array( 'fields' => array( 'sku', 'store' ) ) ) );
	pqbg_t( 'fields: price and name off, store name on → SKU, store name and the code only', array( 'sku', 'store', 'code' ) === $fields_of( $h ) && str_contains( $h, 'pqbg-l-store">' . esc_html( wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) ) . '<' ) );
	$h = PrintPage::render( $build( array( $simple ), array( 'fields' => array() ) ) );
	pqbg_t( 'fields: all off → the code text only (always printed)', array( 'code' ) === $fields_of( $h ) && str_contains( $h, '<div class="pqbg-l pqbg-l-code">' . $code_of( $simple ) . '</div>' ) );
	$h = PrintPage::render( $build( array( $simple ), array( 'layout' => 'a4-5x13', 'fields' => PrintLayout::OPTIONAL_FIELDS ) ) );
	pqbg_t( 'narrow label: the code text wraps after its second hyphen', str_contains( $h, '<div class="pqbg-l pqbg-l-code">' . substr( $code_of( $simple ), 0, 8 ) . '<br>' . substr( $code_of( $simple ), 8 ) . '</div>' ) );
	$set_settings( array() );
	$h = PrintPage::render( $build( array( $simple ), array( 'layout' => 'a4-5x13', 'fields' => PrintLayout::OPTIONAL_FIELDS ) ) );
	pqbg_t( 'fields that do not fit are listed as not printed (5 × 13 with the TEST line: store name)', str_contains( $h, 'pqbg-dropped' ) && str_contains( $h, 'Not printed on this label size (not enough room): store name.' ) && ! str_contains( $h, 'pqbg-l-store' ) );
	$set_settings( array( 'scan_base_url' => $public_https ) );

	$old_code = $code_of( $retire );
	$h1       = PrintPage::render( $build( array( $retire ), array() ) );
	$new      = ( new ProductCodeService() )->regenerate( $retire, $A, (int) CodeRepository::find_active_for_product( $retire )['id'] );
	$new_code = $code_of( $retire );
	$h2       = PrintPage::render( $build( array( $retire ), array() ) );
	pqbg_t( 'after regeneration the label carries the new code; the retired code is never printed (its cached image exists but is never used)', ! is_wp_error( $new ) && $new_code !== $old_code && str_contains( $h1, $old_code ) && str_contains( $h2, $new_code ) && ! str_contains( $h2, $old_code ) && is_array( get_transient( PrintCache::key( implode( '|', array( 'qr', $old_code, ScanUrl::for_code( $old_code ), QrRenderer::QUIET_ZONE, PrintCache::VERSION, PQBG_VERSION ) ) ) ) ) );

	$v = $build( array( $html, $long ), array( 'fields' => PrintLayout::OPTIONAL_FIELDS ) );
	$h = PrintPage::render( $v );
	pqbg_t( 'escaping: a product name with markup is printed literally', str_contains( $h, '&lt;script&gt;alert(1)&lt;/script&gt; Saree &quot;A&amp;B&quot; &lt;3 end' ) && ! str_contains( $h, '<script>alert' ) );
	pqbg_t( 'escaping: an SKU with markup is printed literally', str_contains( $h, '&lt;img src=x onerror=alert(2)&gt;' ) && ! str_contains( $h, '<img' ) );
	pqbg_t( 'the page has one script (pqbg-print.js), one nonce\'d <style>, and no inline style attributes', 1 === substr_count( $h, '<script' ) && str_contains( $h, 'assets/pqbg-print.js' ) && 1 === substr_count( $h, '<style' ) && str_contains( $h, '<style nonce="' . $v['nonce'] . '">' ) && ! preg_match( '/\sstyle="/', $h ) && ! preg_match( '/<[^>]*\son[a-z]+=/i', $h ) );
	pqbg_t( 'standalone: no wp_head output, no admin bar, @page set to the exact size', ! str_contains( $h, 'wp-admin-bar' ) && ! str_contains( $h, 'wp-emoji' ) && str_contains( $h, '@page{size:210mm 297mm;margin:0}' ) && str_contains( $h, '<meta name="robots" content="noindex, nofollow">' ) );

	pqbg_section( 'barcodes' );
	$picqer = static fn() => array_values( array_filter( get_declared_classes(), static fn( $c ) => str_contains( $c, 'Picqer' ) ) );
	$h      = PrintPage::render( $build( array( $simple, $v_own ), array() ) );
	pqbg_t( 'barcodes disabled: no barcode on any label, no barcode library class loaded', ! str_contains( $h, 'pqbg-bc' ) && array() === $picqer() && 'pqbg_barcode_disabled' === PrintCache::barcode( $code_of( $simple ), PrintPage::barcode_args() )->get_error_code() && array() === $picqer() );
	$set_settings( array( 'scan_base_url' => $public_https, 'barcodes_enabled' => true ) );
	$v = $build( array( $simple, $v_own ), array() );
	$h = PrintPage::render( $v );
	pqbg_t( 'barcodes enabled, 3 × 7: a barcode strip on every label (bars only, 7 mm high at 0.25 mm per module)', 2 === substr_count( $h, '<div class="pqbg-bc">' ) && is_array( $v['fit']['barcode'] ) && 7.0 === $v['fit']['barcode']['h'] && preg_match( '/<div class="pqbg-bc"><svg [^>]*viewBox="0 0 \d+ 28"/', $h ) && ! preg_match( '/<div class="pqbg-bc">.*?<text/s', $h ) );
	$h = PrintPage::render( $build( array( $simple ), array( 'layout' => 'a4-4x10' ) ) );
	pqbg_t( 'barcodes enabled, 4 × 10 (too narrow): no barcode, with a notice', ! str_contains( $h, 'pqbg-bc' ) && str_contains( $h, 'pqbg-barcode-omitted' ) );
	pqbg_t( 'the default barcode output is unchanged (text, top margin, 50-module bars)', ( new BarcodeRenderer() )->render( $code_of( $simple ) ) === ( new BarcodeRenderer() )->render( $code_of( $simple ), array() ) && str_contains( ( new BarcodeRenderer() )->render( $code_of( $simple ) ), '<text' ) );
	$set_settings( array( 'scan_base_url' => $public_https ) );

	pqbg_section( 'render cache' );
	PrintCache::clear_all();
	PrintCache::reset_request();
	$three = array( $simple, $html, $long );
	$build( $three, array() );
	$cold = PrintCache::stats();
	PrintCache::reset_request();
	$v1 = $build( $three, array() );
	$warm = PrintCache::stats();
	pqbg_t( 'cold: 3 misses; warm (next request): 3 hits, 0 misses', array( 'hits' => 0, 'misses' => 3 ) === $cold && array( 'hits' => 3, 'misses' => 0 ) === $warm );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_' . PrintCache::PREFIX ) . '%' ), ARRAY_A );
	pqbg_t( 'stored as 3 transients that expire in 30 days and are not autoloaded', 3 === count( $rows ) && array() === array_filter( $rows, static fn( $r ) => abs( (int) $r['option_value'] - ( time() + 30 * DAY_IN_SECONDS ) ) > 120 || in_array( $r['autoload'], array( 'yes', 'on', 'auto-on' ), true ) ) );
	$index = $raw_option( PrintCache::INDEX_OPTION );
	pqbg_t( 'the index lists the 3 keys and is not autoloaded', is_array( $index ) && 3 === count( maybe_unserialize( $index['option_value'] ) ) && ! in_array( $index['autoload'], array( 'yes', 'on', 'auto-on' ), true ) );
	$set_settings( array( 'scan_base_url' => 'https://other.example.com' ) );
	PrintCache::reset_request();
	$v2 = $build( $three, array() );
	pqbg_t( 'changing the scan base URL: all misses, new images (never the old URL\'s image)', array( 'hits' => 0, 'misses' => 3 ) === PrintCache::stats() && $v1['labels'][0]['qr_svg'] !== $v2['labels'][0]['qr_svg'] );
	$set_settings( array( 'scan_base_url' => $public_https, 'barcodes_enabled' => true ) );
	PrintCache::reset_request();
	$b1 = PrintCache::barcode( $code_of( $simple ), array( 'bar_height' => 28, 'text' => false ) );
	$b2 = PrintCache::barcode( $code_of( $simple ), array( 'bar_height' => 20, 'text' => false ) );
	pqbg_t( 'barcode arguments are part of the key (different arguments → different image, 2 misses)', is_string( $b1 ) && is_string( $b2 ) && $b1 !== $b2 && 2 === PrintCache::stats()['misses'] );
	$set_settings( array( 'scan_base_url' => $public_https, 'barcodes_enabled' => false ) );
	PrintCache::reset_request();
	pqbg_t( 'barcodes disabled again: a cached barcode is not served', is_wp_error( PrintCache::barcode( $code_of( $simple ), array( 'bar_height' => 28, 'text' => false ) ) ) && 0 === PrintCache::stats()['hits'] );
	$key = PrintCache::key( implode( '|', array( 'qr', $code_of( $simple ), ScanUrl::for_code( $code_of( $simple ) ), QrRenderer::QUIET_ZONE, PrintCache::VERSION, PQBG_VERSION ) ) );
	$real = get_transient( $key );
	set_transient( $key, array_merge( (array) $real, array( 'c' => $code_of( $html ) ) ), HOUR_IN_SECONDS );
	PrintCache::reset_request();
	$svg = PrintCache::qr( $code_of( $simple ) );
	pqbg_t( 'an entry whose stored code does not match is never served (miss, re-rendered)', 1 === PrintCache::stats()['misses'] && $svg === ( new QrRenderer() )->render( $code_of( $simple ) ) );
	PrintCache::flush();
	// Bounded size: 2,000 older entries already indexed, then one more request.
	$fake = array();
	for ( $i = 0; $i < PrintCache::MAX_ENTRIES; $i++ ) {
		$fake[ PrintCache::PREFIX . 'fake' . $i ] = time() - DAY_IN_SECONDS - $i;
	}
	$fake[ PrintCache::PREFIX . 'expired' ] = time() - 31 * DAY_IN_SECONDS;
	PrintCache::clear_all();
	update_option( PrintCache::INDEX_OPTION, $fake, false );
	foreach ( array( 'fake1999', 'fake1998', 'fake0' ) as $k ) {
		set_transient( PrintCache::PREFIX . $k, array( 'c' => 'x' ), HOUR_IN_SECONDS );
	}
	PrintCache::reset_request();
	$build( $three, array() );
	$index = get_option( PrintCache::INDEX_OPTION );
	pqbg_t( 'bounded: the index keeps at most 2,000 entries; the least recently used are evicted with their transients; expired entries are dropped', PrintCache::MAX_ENTRIES === count( $index ) && ! isset( $index[ PrintCache::PREFIX . 'expired' ] ) && ! isset( $index[ PrintCache::PREFIX . 'fake1999' ] ) && false === get_transient( PrintCache::PREFIX . 'fake1999' ) && false === get_transient( PrintCache::PREFIX . 'fake1998' ) && isset( $index[ PrintCache::PREFIX . 'fake0' ] ) && false !== get_transient( PrintCache::PREFIX . 'fake0' ) && 3 === count( array_filter( array_keys( $index ), static fn( $k ) => ! str_contains( $k, 'fake' ) ) ) );
	PrintCache::clear_all();
	pqbg_t( 'clear_all() removes every cached image and the index', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name = %s", $wpdb->esc_like( '_transient_' . PrintCache::PREFIX ) . '%', $wpdb->esc_like( '_transient_timeout_' . PrintCache::PREFIX ) . '%', PrintCache::INDEX_OPTION ) ) );
	$build( $three, array() );
	$un = (string) file_get_contents( PQBG_PLUGIN_DIR . 'uninstall.php' );
	pqbg_t( 'uninstall.php clears the cache before the data-preservation check (the cache is not data)', false !== strpos( $un, 'PrintCache::clear_all();' ) && strpos( $un, 'PrintCache::clear_all();' ) < strpos( $un, "if ( ! defined( 'PQBG_UNINSTALL_DELETE_ALL_DATA' )" ) && strpos( $un, "delete_metadata( 'user', 0, 'pqbg_print_prefs'" ) > strpos( $un, "if ( ! defined( 'PQBG_UNINSTALL_DELETE_ALL_DATA' )" ) );
	$flag = $raw_option( 'pqbg_rewrite_version' );
	$codes_n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" );
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', pqbg_test_plugin_basename() );
	}
	include PQBG_PLUGIN_DIR . 'uninstall.php';
	if ( is_array( $flag ) ) {
		$wpdb->insert( $wpdb->options, array( 'option_name' => 'pqbg_rewrite_version', 'option_value' => $flag['option_value'], 'autoload' => $flag['autoload'] ) );
		wp_cache_delete( 'pqbg_rewrite_version', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
	pqbg_t( 'running the default uninstall path: cache gone; codes, settings and print preferences kept', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_' . PrintCache::PREFIX ) . '%' ) ) && false === get_option( PrintCache::INDEX_OPTION ) && $codes_n === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" ) && is_array( get_option( Plugin::SETTINGS_OPTION ) ) );

	pqbg_section( 'round trip at print resolution (QR and barcode rasterised at their printed size, decoded)' );
	$rt_names = array( 'decoder ran', 'every QR decodes to ScanUrl::for_code() at 300 dpi (A4 3 × 7, 5 × 13) and 203 dpi (thermal 50 × 25, 38 × 25)', 'every barcode decodes to its code at 300 dpi and 203 dpi (0.25 mm modules)', 'QR at exactly 0.40 mm modules decodes at 203 and 300 dpi (V4 and V8 scan URLs)' );
	if ( ! $decoder_ok ) {
		foreach ( $rt_names as $name ) {
			pqbg_skip( $name, 'the decoder is not installed (npm ci in tests/decoder, or PQBG_DECODER); see tests/README.md' );
		}
	} else {
		$set_settings( array( 'scan_base_url' => $public_https, 'barcodes_enabled' => true ) );
		$jobs    = array( array( 'a4-3x7', 300 ), array( 'a4-5x13', 300 ), array( 'th-50x25', 203 ), array( 'th-38x25', 203 ) );
		$args    = array();
		$expect  = array();
		$ids_rt  = array( $simple, $html, $long, $v_own, $v_free, $v_parent );
		foreach ( $jobs as [ $layout, $dpi ] ) {
			$v = $build( $ids_rt, array( 'layout' => $layout, 'fields' => PrintLayout::OPTIONAL_FIELDS ) );
			foreach ( $v['labels'] as $n => $label ) {
				$f      = "$tmp/rt-$layout-$n-qr.svg";
				file_put_contents( $f, $label['qr_svg'] );
				$args[] = '--width=' . (int) round( $v['fit']['qr'] / 25.4 * $dpi );
				$args[] = $f;
				$expect[ $f ] = array( 'QRCode', ScanUrl::for_code( $label['code'] ) );
				if ( '' !== $label['barcode_svg'] ) {
					preg_match( '/viewBox="0 0 (\d+) /', $label['barcode_svg'], $m );
					$f      = "$tmp/rt-$layout-$n-bc.svg";
					file_put_contents( $f, $label['barcode_svg'] );
					$args[] = '--width=' . (int) round( (int) $m[1] * PrintLayout::BARCODE_X_MM / 25.4 * $dpi );
					$args[] = $f;
					$expect[ $f ] = array( 'Code128', $label['code'] );
				}
			}
		}
		// Exactly 0.40 mm: 41 modules (V4) = 16.4 mm and 57 modules (V8, a 100-character base URL) = 22.8 mm.
		$minimum = array();
		foreach ( array( 'https://shop.example.com' => 16.4, $bases[100] => 22.8 ) as $base => $mm ) {
			$set_settings( array( 'scan_base_url' => $base ) );
			PrintCache::reset_request();
			$svg = PrintCache::qr( $code_of( $simple ) );
			foreach ( array( 203, 300 ) as $dpi ) {
				$f             = "$tmp/rt-min-" . strlen( $base ) . "-$dpi.svg";
				file_put_contents( $f, $svg );
				$args[]        = '--width=' . (int) round( $mm / 25.4 * $dpi );
				$args[]        = $f;
				$expect[ $f ]  = array( 'QRCode', ScanUrl::for_code( $code_of( $simple ) ) );
				$minimum[ $f ] = true;
			}
		}
		$decoded = $decode( $args );
		pqbg_t( $rt_names[0], is_array( $decoded ) && count( $decoded ) === count( $expect ) );
		$ok = array();
		foreach ( (array) $decoded as $d ) {
			[ $format, $text ] = $expect[ $d['file'] ];
			$ok[ $d['file'] ]  = (bool) array_filter( $d['results'], static fn( $r ) => $r['format'] === $format && $r['text'] === $text && ( 'QRCode' !== $format || 'M' === $r['ecLevel'] ) );
		}
		$qr_files = array_filter( array_keys( $expect ), static fn( $f ) => 'QRCode' === $expect[ $f ][0] && ! isset( $minimum[ $f ] ) );
		$bc_files = array_filter( array_keys( $expect ), static fn( $f ) => 'Code128' === $expect[ $f ][0] );
		pqbg_t( $rt_names[1], count( $qr_files ) > 20 && array() === array_filter( $qr_files, static fn( $f ) => empty( $ok[ $f ] ) ), count( $qr_files ) . ' QR codes' );
		pqbg_t( $rt_names[2], count( $bc_files ) > 5 && array() === array_filter( $bc_files, static fn( $f ) => empty( $ok[ $f ] ) ), count( $bc_files ) . ' barcodes' );
		pqbg_t( $rt_names[3], 4 === count( $minimum ) && array() === array_filter( array_keys( $minimum ), static fn( $f ) => empty( $ok[ $f ] ) ) );
		$set_settings( array( 'scan_base_url' => $public_https ) );
	}

	pqbg_section( 'HTTP: entry points, setup screen, print page' );
	wp_set_current_user( 0 );
	$set_settings( array( 'scan_base_url' => $public_https ) );
	$edit = $http( 'admin', 'GET', admin_url( 'post.php?post=' . $simple . '&action=edit' ) );
	$link = $href_of( $edit['body'], 'pqbg-print-label' );
	pqbg_t( 'simple product panel: "Print label" links to the setup screen for that product', 200 === $edit['code'] && str_contains( $link, 'page=' . PrintAdmin::SLUG ) && str_contains( $link, 'items=' . $simple . '&' ) );
	$setup = $http( 'admin', 'GET', $link );
	pqbg_t( 'setup screen: 200 with the summary, the layouts (default A4 3 × 7 checked), options and the price note', 200 === $setup['code'] && str_contains( $setup['body'], '1 item ready to print' ) && preg_match( '/value="a4-3x7" checked/', $setup['body'] ) && 8 === substr_count( $setup['body'], 'name="opt[layout]"' ) && str_contains( $setup['body'], 'Printed prices go out of date when you change them; the QR always shows the live price.' ) && str_contains( $setup['body'], 'QR code 35.1 mm' ) );
	$edit = $http( 'admin', 'GET', admin_url( 'post.php?post=' . $var1 . '&action=edit' ) );
	preg_match_all( '/<a class="button pqbg-print-label" href="([^"]+)"/', $edit['body'], $m );
	$var_links = array_map( static fn( $u ) => html_entity_decode( $u, ENT_QUOTES ), $m[1] );
	pqbg_t( 'variable product panel: "Print all variation labels (2)" and one "Print label" per variation with a code', str_contains( $edit['body'], 'Print all variation labels (2)' ) && str_contains( $href_of( $edit['body'], 'pqbg-print-all' ), 'items=' . $var1 . '&' ) && 2 === count( $var_links ) && str_contains( $var_links[0], 'items=' . $v_own . '&' ) && str_contains( $var_links[1], 'items=' . $v_free . '&' ) );
	pqbg_t( 'products list: the "Print QR labels" bulk action for admin and shop manager', str_contains( $http( 'admin', 'GET', admin_url( 'edit.php?post_type=product' ) )['body'], 'value="' . PrintAdmin::BULK_ACTION . '"' ) && str_contains( $http( 'sm', 'GET', admin_url( 'edit.php?post_type=product' ) )['body'], 'value="' . PrintAdmin::BULK_ACTION . '"' ) );
	$bulk = $setup_url_for( 'admin', array( $simple, $var1, $nocode ) );
	$page = $http( 'admin', 'GET', $bulk );
	pqbg_t( 'bulk action → setup screen with the selection; items without codes listed as skipped with a product link', str_contains( $bulk, 'page=' . PrintAdmin::SLUG ) && 200 === $page['code'] && str_contains( $page['body'], '3 items ready to print' ) && 2 === substr_count( $page['body'], 'pqbg-skip--no_code' ) && str_contains( $page['body'], 'Skipped – no code yet' ) && str_contains( $page['body'], 'post.php?post=' . $nocode . '&#038;action=edit' ) );
	$too_many = $http( 'admin', 'GET', $setup_url_for( 'admin', range( 900000001, 900000301 ) ) );
	pqbg_t( 'more than 300 products selected: the setup screen refuses with a clear message', 400 === $too_many['code'] && str_contains( $too_many['body'], '301 products are selected; the maximum is 300' ) );
	$before = $state();
	$r      = $prepare( 'admin', $bulk, array( 'layout' => 'a4-3x7', 'start' => '2', 'copies_mode' => 'fixed', 'copies' => '1', 'fields' => array( 'name', 'price' ), 'dx' => '0', 'dy' => '0' ) );
	$purl   = $r['location'];
	pqbg_t( 'prepare (POST) → 303 to the print page, the options in the URL', 303 === $r['code'] && str_contains( $purl, 'admin-post.php?action=' . PrintPage::ACTION ) && str_contains( $purl, 'opt%5Bstart%5D=2' ) );
	wp_cache_delete( $A, 'user_meta' );
	$prefs = get_user_meta( $A, PrintJob::PREFS_META, true );
	pqbg_t( 'prepare remembers the options in the user\'s own meta (not site-wide)', is_array( $prefs ) && '2' === $prefs['start'] && array( 'name', 'price' ) === $prefs['fields'] && '' === get_user_meta( $SM, PrintJob::PREFS_META, true ) && false === get_option( 'pqbg_print_prefs' ) );
	$pr = $http( 'admin', 'GET', $purl );
	pqbg_t( 'print page: 200, 3 labels (simple + 2 variations), starting at position 2', 200 === $pr['code'] && 3 === $labels_in( $pr['body'] ) && str_contains( $pr['body'], 'class="pqbg-label pqbg-s1"' ) && ! str_contains( $pr['body'], 'pqbg-s0"' ) && str_contains( $pr['body'], '3 labels · A4 · 3 × 7' ) );
	pqbg_t( 'print page: security headers and a CSP whose nonce matches the <style> element', $headers_ok( $pr ) && '' !== $csp_nonce( $pr ) && str_contains( $pr['body'], '<style nonce="' . $csp_nonce( $pr ) . '">' ) && str_starts_with( $pr['headers']['content-type'] ?? '', 'text/html' ) );
	pqbg_t( 'print page: the skipped item is listed with its reason', str_contains( $pr['body'], 'pqbg-skip--no_code' ) );
	$head = $http( 'admin', 'HEAD', $purl );
	pqbg_t( 'HEAD: 200, the same headers, no body', 200 === $head['code'] && '' === $head['body'] && $headers_ok( $head ) );
	$pr2 = $http( 'admin', 'GET', $purl );
	pqbg_t( 'reloading the print page gives the same labels', 3 === $labels_in( $pr2['body'] ) );
	$set_settings( array() );
	$pl = $http( 'admin', 'GET', $purl );
	$cl = preg_match( '/<a class="pqbg-button pqbg-button--primary pqbg-confirm" href="([^"]+)"/', $pl['body'], $m ) ? html_entity_decode( $m[1], ENT_QUOTES ) : '';
	$pt = '' !== $cl ? $http( 'admin', 'GET', $cl ) : array( 'code' => 0, 'body' => '' );
	pqbg_t( 'local scan URL over HTTP: warning page without labels, then "Print TEST labels anyway" → every label marked', 200 === $pl['code'] && 0 === $labels_in( $pl['body'] ) && $headers_ok( $pl ) && 200 === $pt['code'] && 3 === $labels_in( $pt['body'] ) && 3 === substr_count( $pt['body'], 'pqbg-l-test">TEST – NOT FOR USE<' ) );
	pqbg_t( 'the only change since before the first POST is the user\'s print preferences (GET, HEAD and the confirmation wrote nothing)', $before !== $state() && $before === $state( true ) );
	$set_settings( array( 'scan_base_url' => $public_https ) );
	$s_before = $state();
	foreach ( array( $link, $bulk ) as $u ) {
		$http( 'admin', 'GET', $u );
	}
	$http( 'admin', 'GET', $purl );
	$http( 'admin', 'HEAD', $purl );
	pqbg_t( 'GET never writes: state identical before and after setup screens, print page and HEAD', $s_before === $state() );
	$s_before = $state();
	$s_skip   = $state( true );
	$prepare( 'admin', $bulk, array( 'layout' => 'th-50x25', 'fields' => array( 'sku' ) ) );
	wp_cache_delete( $A, 'user_meta' );
	$prefs = get_user_meta( $A, PrintJob::PREFS_META, true );
	pqbg_t( 'prepare (POST) writes only the user\'s print preferences', $s_before !== $state() && $s_skip === $state( true ) && 'th-50x25' === $prefs['layout'] );
	$page = $http( 'admin', 'GET', $bulk );
	pqbg_t( 'the remembered options are pre-filled on the next setup screen', (bool) preg_match( '/value="th-50x25" checked/', $page['body'] ) );
	$r = $prepare( 'admin', $bulk, array( 'layout' => 'a4-3x7', 'start' => '99' ) );
	$e = $http( 'admin', 'GET', $r['location'] );
	pqbg_t( 'invalid options: back to the setup screen with the error, nothing printed', 303 === $r['code'] && str_contains( $r['location'], 'page=' . PrintAdmin::SLUG ) && str_contains( $e['body'], 'The start position must be a whole number from 1 to 21.' ) );
	$set_settings( array( 'scan_base_url' => $bases[61] ) );
	$r = $prepare( 'admin', $bulk, array( 'layout' => 'a4-5x13', 'fields' => array( 'name' ) ) );
	$e = $http( 'admin', 'GET', $r['location'] );
	pqbg_t( 'a layout too small for the scan URL is refused with the explanation', 303 === $r['code'] && str_contains( $r['location'], 'page=' . PrintAdmin::SLUG ) && str_contains( $e['body'], 'needs a QR code of at least 19.6 mm' ) );
	$set_settings( array( 'scan_base_url' => $public_https ) );
	$r = $prepare( 'admin', $bulk, array( 'layout' => 'a4-3x7', 'copies' => '100' ) );
	$e = $http( 'admin', 'GET', $r['location'] );
	pqbg_t( 'job limit: exactly 300 labels (3 items × 100 copies) go to the print page', 303 === $r['code'] && str_contains( $r['location'], 'action=' . PrintPage::ACTION ) );
	$r = $prepare( 'admin', $setup_url_for( 'admin', array( $simple, $var1, $html ) ), array( 'layout' => 'a4-3x7', 'copies' => '100' ) );
	$e = $http( 'admin', 'GET', $r['location'] );
	pqbg_t( '…and 400 labels are refused on the setup screen with the count', str_contains( $r['location'], 'page=' . PrintAdmin::SLUG ) && str_contains( $e['body'], 'This job has 400 labels; the maximum is 300.' ) );

	pqbg_section( 'HTTP: nonces, methods and permissions on every entry point' );
	$q = array();
	parse_str( (string) wp_parse_url( $purl, PHP_URL_QUERY ), $q );
	$tampered = add_query_arg( 'items', $q['items'] . ',' . $html, $purl );
	$t        = $http( 'admin', 'GET', $tampered );
	pqbg_t( 'print page: items changed after signing → 403 (nonce bound to the selection), headers kept', 403 === $t['code'] && 0 === $labels_in( $t['body'] ) && $headers_ok( $t ) );
	pqbg_t( 'print page: no nonce → 403; a nonce for another selection → 403', 403 === $http( 'admin', 'GET', remove_query_arg( Permissions::NONCE_FIELD, $purl ) )['code'] && 403 === $http( 'admin', 'GET', add_query_arg( Permissions::NONCE_FIELD, $field( $http( 'admin', 'GET', $link )['body'], Permissions::NONCE_FIELD ), $purl ) )['code'] );
	pqbg_t( 'setup screen: a wrong nonce → 403', 403 === $http( 'admin', 'GET', add_query_arg( Permissions::NONCE_FIELD, 'abc', $link ) )['code'] );
	$view_nonce = array();
	parse_str( (string) wp_parse_url( $link, PHP_URL_QUERY ), $view_nonce );
	$r = $http( 'admin', 'POST', admin_url( 'admin-post.php' ), array( 'action' => PrintAdmin::PREPARE, 'items' => (string) $simple, Permissions::NONCE_FIELD => $view_nonce[ Permissions::NONCE_FIELD ], 'opt' => array( 'layout' => 'a4-3x7' ) ) );
	pqbg_t( 'prepare: the view nonce is not accepted for the POST → 403', 403 === $r['code'] );
	$post_print = $http( 'admin', 'POST', $purl, array( 'x' => '1' ) );
	pqbg_t( 'print page: POST → 405 with Allow: GET, HEAD and the headers', 405 === $post_print['code'] && 'GET, HEAD' === ( $post_print['headers']['allow'] ?? '' ) && $headers_ok( $post_print ) );
	pqbg_t( 'prepare: GET → 405', 405 === $http( 'admin', 'GET', admin_url( 'admin-post.php?action=' . PrintAdmin::PREPARE ) )['code'] );
	pqbg_t( 'the shop manager cannot use the administrator\'s signed print link (nonces are per user) → 403', 403 === $http( 'sm', 'GET', $purl )['code'] );
	$sm_url = $print_url( 'sm', array( $simple ), array( 'layout' => 'a4-3x7', 'fields' => array( 'name' ) ) );
	$sm_pr  = '' !== $sm_url ? $http( 'sm', 'GET', $sm_url ) : array( 'code' => 0, 'body' => '' );
	pqbg_t( 'shop manager: bulk action → setup → prepare → print page 200 with the label', 200 === $sm_pr['code'] && 1 === $labels_in( $sm_pr['body'] ) );
	$admin_form = $http( 'admin', 'GET', $bulk );
	$post       = array(
		'action'                 => PrintAdmin::PREPARE,
		'items'                  => $field( $admin_form['body'], 'items' ),
		Permissions::NONCE_FIELD => $field( $admin_form['body'], Permissions::NONCE_FIELD ),
		'opt'                    => array( 'layout' => 'a4-3x7' ),
	);
	foreach ( array( 'seller', 'customer', 'subscriber' ) as $who ) {
		$s  = $http( $who, 'GET', $bulk );
		$p  = $http( $who, 'POST', admin_url( 'admin-post.php' ), $post );
		$g  = $http( $who, 'GET', $purl );
		$b  = $http( $who, 'GET', admin_url( 'edit.php?post_type=product&action=' . PrintAdmin::BULK_ACTION . '&post[]=' . $simple ) );
		$ok = 200 !== $s['code'] && ! str_contains( $s['body'], 'pqbg-print-form' )
			&& 403 === $p['code'] && '' === get_user_meta( $user_ids[ $who ], PrintJob::PREFS_META, true )
			&& 403 === $g['code'] && 0 === $labels_in( $g['body'] ) && $headers_ok( $g )
			&& ! str_contains( $b['location'], 'page=' . PrintAdmin::SLUG ) && ! str_contains( $b['body'], 'pqbg-print-form' );
		pqbg_t( "$who: refused on the setup screen, prepare (403), print page (403) and bulk action", $ok, "setup {$s['code']}, prepare {$p['code']}, print {$g['code']}, bulk {$b['code']}" );
	}
	$s = $http( 'anon', 'GET', $bulk );
	$p = $http( 'anon', 'POST', admin_url( 'admin-post.php' ), $post );
	$g = $http( 'anon', 'GET', $purl );
	pqbg_t( 'logged out: setup → login redirect; prepare and print page never answer with labels or a print link (no nopriv handlers)', 302 === $s['code'] && str_contains( $s['location'], 'wp-login.php' ) && ! str_contains( $p['location'], 'action=' . PrintPage::ACTION ) && 200 !== $p['code'] && 0 === $labels_in( $g['body'] ) && 200 !== $g['code'] );

	pqbg_section( 'HTTP: escaping' );
	$e_url = $print_url( 'admin', array( $html ), array( 'layout' => 'a4-3x7', 'fields' => PrintLayout::OPTIONAL_FIELDS ) );
	$e     = $http( 'admin', 'GET', $e_url );
	pqbg_t( 'a product name and SKU with markup are escaped on the print page (no script or img tag)', 200 === $e['code'] && str_contains( $e['body'], '&lt;script&gt;alert(1)&lt;/script&gt;' ) && str_contains( $e['body'], '&lt;img src=x onerror=alert(2)&gt;' ) && ! str_contains( $e['body'], '<script>alert' ) && ! str_contains( $e['body'], '<img' ) );
	$s = $http( 'admin', 'GET', $setup_url_for( 'admin', array( $html, $trashed ) ) );
	pqbg_t( '…and on the setup screen (skipped item names)', 200 === $s['code'] && str_contains( $s['body'], 'PQBG P8 trashed' ) && ! str_contains( $s['body'], '<script>alert' ) );

	pqbg_section( 'headless Chrome and Edge: CSP, console, Print button, geometry, rupee glyph, decoding at 203/300 dpi, PDF' );
	$b_names = array( 'browser checks ran' );
	if ( ! $browser_ok || ! $decoder_ok ) {
		$why = ! $has_node ? 'Node.js is not on PATH' : ( array() === $browsers ? 'no Chrome or Edge found (PQBG_BROWSERS)' : ( ! $decoder_ok ? 'the decoder is not installed' : 'the print-check package is not installed (npm ci in tests/print-check, or PQBG_PRINTCHECK)' ) );
		pqbg_skip( 'browser print checks (Chrome/Edge)', $why . '; see tests/README.md' );
	} else {
		$set_settings( array( 'scan_base_url' => $public_https, 'barcodes_enabled' => true ) );
		$all_f  = PrintLayout::OPTIONAL_FIELDS;
		$pages  = array(
			'setup'   => array( 'setup', $setup_url_for( 'admin', array( $simple, $var1, $html, $long, $draft, $nocode ) ), null, null ),
			'a4'      => array( 'print', $print_url( 'admin', array( $simple, $html, $long, $var1, $draft ), array( 'layout' => 'a4-3x7', 'start' => '5', 'copies' => '3', 'fields' => $all_f ) ), 300, array( 'layout' => 'a4-3x7', 'start' => '5', 'copies' => '3', 'fields' => $all_f ) ),
			'small'   => array( 'print', $print_url( 'admin', array( $long, $html, $simple, $var1 ), array( 'layout' => 'a4-5x13', 'fields' => $all_f ) ), 300, array( 'layout' => 'a4-5x13', 'fields' => $all_f ) ),
			'thermal' => array( 'print', $print_url( 'admin', array( $simple, $var1 ), array( 'layout' => 'th-50x25', 'fields' => $all_f ) ), 203, array( 'layout' => 'th-50x25', 'fields' => $all_f ) ),
			'minimum' => array( 'print', $print_url( 'admin', array( $simple ), array( 'layout' => 'custom', 'custom' => array( 'type' => 'thermal', 'dpi' => '203', 'label_w' => '40', 'label_h' => '19.4' ), 'fields' => array() ) ), 203, array( 'layout' => 'custom', 'custom' => array( 'type' => 'thermal', 'dpi' => '203', 'label_w' => '40', 'label_h' => '19.4' ), 'fields' => array() ) ),
		);
		$local_url = $print_url( 'admin', array( $simple, $v_own ), array( 'layout' => 'a4-3x7', 'fields' => $all_f ) );
		pqbg_t( 'print links for the browser jobs obtained through the real flow (bulk action → setup → POST)', array() === array_filter( $pages, static fn( $p ) => '' === $p[1] ) && '' !== $local_url );
		$ids_by_page = array(
			'a4'      => array( $simple, $html, $long, $var1, $draft ),
			'small'   => array( $long, $html, $simple, $var1 ),
			'thermal' => array( $simple, $var1 ),
			'minimum' => array( $simple ),
		);
		// Expected geometry, computed with the same classes the page uses (in-process, same settings).
		$expect_view = array();
		foreach ( $ids_by_page as $name => $ids ) {
			$expect_view[ $name ] = $build( $ids, $pages[ $name ][3] );
		}
		$jobs = array(
			'public' => array(
				'settings' => array( 'scan_base_url' => $public_https, 'barcodes_enabled' => true ),
				'pages'    => array(
					array( 'name' => 'setup', 'url' => $pages['setup'][1], 'kind' => 'setup' ),
					array( 'name' => 'a4', 'url' => $pages['a4'][1], 'kind' => 'print', 'dpi' => 300, 'clickPrint' => true, 'fonts' => true, 'screenshots' => true, 'pdf' => true ),
					array( 'name' => 'small', 'url' => $pages['small'][1], 'kind' => 'print', 'dpi' => 300, 'clickPrint' => true, 'fonts' => true, 'screenshots' => true, 'pdf' => true ),
					array( 'name' => 'thermal', 'url' => $pages['thermal'][1], 'kind' => 'print', 'dpi' => 203, 'clickPrint' => true, 'screenshots' => true, 'pdf' => true ),
					array( 'name' => 'minimum', 'url' => $pages['minimum'][1], 'kind' => 'print', 'dpi' => 203, 'screenshots' => true, 'pdf' => true ),
				),
			),
			'local'  => array(
				'settings' => array(),
				'pages'    => array(
					array( 'name' => 'confirm', 'url' => $local_url, 'kind' => 'setup' ),
					array( 'name' => 'testlabels', 'url' => add_query_arg( PrintPage::CONFIRM_ARG, '1', $local_url ), 'kind' => 'print', 'dpi' => 300, 'clickPrint' => true, 'screenshots' => true ),
				),
			),
		);
		$results = array();
		foreach ( $browsers as $exe ) {
			$bname = str_contains( strtolower( $exe ), 'edge' ) ? 'Edge' : 'Chrome';
			foreach ( $jobs as $jname => $job ) {
				$set_settings( $job['settings'] );
				$dir  = "$tmp/$bname-$jname";
				$spec = array(
					'browser' => $exe,
					'cookies' => $cookies_of( 'admin' ),
					'out'     => $dir,
					'pages'   => $job['pages'],
				);
				wp_mkdir_p( $dir );
				file_put_contents( "$dir.json", wp_json_encode( $spec ) );
				$raw                        = shell_exec( 'node ' . escapeshellarg( $printcheck ) . ' ' . escapeshellarg( "$dir.json" ) . ' 2>&1' );
				$results[ $bname ][ $jname ] = json_decode( (string) $raw, true );
				if ( ! is_array( $results[ $bname ][ $jname ] ) ) {
					echo '   print-check output: ' . substr( (string) $raw, 0, 2000 ) . "\n";
				}
			}
		}
		$set_settings( array( 'scan_base_url' => $public_https ) );

		foreach ( $results as $bname => $res ) {
			$ok_run = is_array( $res['public'] ) && is_array( $res['local'] );
			pqbg_t( "$bname: browser checks ran", $ok_run, $ok_run ? $res['public']['browser'] : 'no JSON' );
			if ( ! $ok_run ) {
				continue;
			}
			$all_pages = array_merge( $res['public']['pages'], $res['local']['pages'] );
			$dirty     = array();
			foreach ( $all_pages as $name => $p ) {
				if ( 200 !== $p['status'] || array() !== $p['csp'] || array() !== $p['consoleErrors'] || array() !== $p['pageErrors'] || array() !== $p['failedRequests'] ) {
					$dirty[] = $name . ': ' . wp_json_encode( array( $p['status'], $p['csp'], $p['consoleErrors'], $p['pageErrors'], $p['failedRequests'] ) );
				}
			}
			pqbg_t( "$bname: setup screen, confirmation and print pages load with zero CSP violations, console errors, page errors or failed requests", array() === $dirty, implode( ' | ', $dirty ) );
			$clicks = array_filter( $all_pages, static fn( $p ) => array_key_exists( 'printCalls', $p ) );
			pqbg_t( "$bname: clicking Print calls window.print() exactly once (on every print page)", 4 === count( $clicks ) && array() === array_filter( $clicks, static fn( $p ) => 1 !== $p['printCalls'] ) );

			// Geometry in print media, against PrintLayout's numbers.
			$geo_bad = array();
			foreach ( array( 'a4', 'small', 'thermal', 'minimum' ) as $name ) {
				$g    = $res['public']['pages'][ $name ]['geometry'];
				$ev   = $expect_view[ $name ];
				$spec = $ev['spec'];
				$fit  = $ev['fit'];
				$near = static fn( $a, $b, $tol = 0.06 ) => abs( (float) $a - (float) $b ) <= $tol;
				if ( $g['toolbarVisible'] || 0 !== $g['styleAttributes'] || count( $g['sheets'] ) !== $ev['sheets'] ) {
					$geo_bad[] = "$name: toolbar/style attributes/sheet count";
					continue;
				}
				$n = 0;
				foreach ( $g['sheets'] as $si => $sheet ) {
					if ( ! $near( $sheet['w'], $spec['page_w'] ) || ! $near( $sheet['h'], $spec['page_h'] ) ) {
						$geo_bad[] = "$name: sheet $si size";
					}
					foreach ( $sheet['labels'] as $label ) {
						$want = $ev['labels'][ $n++ ] ?? null;
						[ $x, $y ] = PrintLayout::slot_position( $spec, $label['slot'] );
						$inside    = static fn( $b, $o ) => null === $b || ( $b['x'] >= $o['x'] - 0.06 && $b['y'] >= $o['y'] - 0.06 && $b['x'] + $b['w'] <= $o['x'] + $o['w'] + 0.06 && $b['y'] + $b['h'] <= $o['y'] + $o['h'] + 0.06 );
						$apart     = static fn( $a, $b ) => null === $a || null === $b || $a['x'] + $a['w'] <= $b['x'] + 0.06 || $b['x'] + $b['w'] <= $a['x'] + 0.06 || $a['y'] + $a['h'] <= $b['y'] + 0.06 || $b['y'] + $b['h'] <= $a['y'] + 0.06;
						$ok        = null !== $want && $want['code'] === $label['code'] && $want['slot'] === $label['slot'] && $want['sheet'] === $si
							&& $near( $label['box']['x'], $x ) && $near( $label['box']['y'], $y ) && $near( $label['box']['w'], $spec['label_w'] ) && $near( $label['box']['h'], $spec['label_h'] )
							&& $near( $label['qr']['x'], $fit['qr_x'] ) && $near( $label['qr']['y'], $fit['qr_y'] ) && $near( $label['qr']['w'], $fit['qr'] ) && $near( $label['qr']['h'], $fit['qr'] )
							&& $near( $label['text']['x'], $fit['text']['x'] ) && $near( $label['text']['w'], $fit['text']['w'] )
							&& $apart( $label['qr'], $label['text'] ) && $apart( $label['bc'], $label['text'] ) && $apart( $label['bc'], $label['qr'] )
							&& ( null === $fit['barcode'] ) === ( null === $label['bc'] );
						foreach ( $label['lines'] as $line ) {
							$ok = $ok && $inside( $line['box'], $label['text'] );
							if ( 'pqbg-l-code' === $line['cls'] ) {
								$ok = $ok && $line['scrollW'] <= $line['clientW'] && $line['scrollH'] <= $line['clientH'] + 1 && str_replace( "\n", '', $line['text'] ) === $label['code'];
							}
						}
						if ( ! $ok ) {
							$geo_bad[] = "$name: label {$label['code']} slot {$label['slot']}";
						}
					}
				}
				if ( $n !== count( $ev['labels'] ) ) {
					$geo_bad[] = "$name: $n labels, expected " . count( $ev['labels'] );
				}
			}
			pqbg_t( "$bname: print-media geometry matches the layout to 0.06 mm (sheets, slots, QR, text, barcode); nothing overlaps; the code text is never cut", array() === $geo_bad, implode( ' | ', array_slice( $geo_bad, 0, 5 ) ) );
			$long_lines = array_merge( ...array_map( static fn( $s ) => array_merge( ...array_map( static fn( $l ) => $l['lines'], $s['labels'] ) ), $res['public']['pages']['small']['geometry']['sheets'] ) );
			$long_name  = array_filter( $long_lines, static fn( $l ) => 'pqbg-l-name' === $l['cls'] && str_contains( $l['text'], 'END' ) );
			$long_sku   = array_filter( $long_lines, static fn( $l ) => 'pqbg-l-sku' === $l['cls'] && str_contains( $l['text'], 'LLLL' ) );
			pqbg_t( "$bname: long name clamped to its two lines and long SKU ellipsised, both inside the text column", 1 === count( $long_name ) && reset( $long_name )['scrollH'] > reset( $long_name )['clientH'] && 1 === count( $long_sku ) && reset( $long_sku )['scrollW'] > reset( $long_sku )['clientW'] );
			$test_lines = array_merge( ...array_map( static fn( $s ) => array_merge( ...array_map( static fn( $l ) => $l['lines'], $s['labels'] ) ), $res['local']['pages']['testlabels']['geometry']['sheets'] ) );
			pqbg_t( "$bname: TEST labels: every label shows \"TEST – NOT FOR USE\" inside its text column", 2 === count( array_filter( $test_lines, static fn( $l ) => 'pqbg-l-test' === $l['cls'] && 'TEST – NOT FOR USE' === $l['text'] && $l['scrollW'] <= $l['clientW'] ) ) );

			// The rupee sign.
			$font_ok = array();
			foreach ( array( 'a4', 'small' ) as $name ) {
				$f         = $res['public']['pages'][ $name ]['fonts'];
				$families  = array_column( (array) ( $f['used'] ?? array() ), 'familyName' );
				$font_ok[] = ! empty( $f['found'] ) && str_contains( $f['glyph']['text'], '₹' ) && array() !== $families && array() === array_diff( $families, array( 'Segoe UI', 'Nirmala UI', 'Roboto', 'Noto Sans', 'Arial' ) ) && $f['glyph']['differsFromMissingGlyph'] && $f['glyph']['inked'];
			}
			$f = $res['public']['pages']['a4']['fonts'];
			pqbg_t( "$bname: ₹ renders from the label font stack (no fallback font, not a missing-glyph box)", array( true, true ) === $font_ok, wp_json_encode( array( 'fonts' => $f['used'] ?? null, 'widths' => array( $f['glyph']['rupeeWidth'] ?? null, $f['glyph']['missingWidth'] ?? null, $f['glyph']['knownWidth'] ?? null ) ) ) );

			// Print-resolution screenshots decoded.
			$files  = array();
			$expect = array();
			foreach ( array( 'a4', 'small', 'thermal', 'minimum', 'testlabels' ) as $name ) {
				$page = $res['public']['pages'][ $name ] ?? $res['local']['pages'][ $name ];
				foreach ( $page['screenshots']['files'] as $shot ) {
					$files[]                 = $shot['file'];
					$expect[ $shot['file'] ] = array( $name, $shot['code'] );
				}
			}
			$set_settings( array( 'scan_base_url' => $public_https ) );
			$decoded = $decode( array_merge( array( '--zoom' ), $files ) );
			$fail    = array();
			foreach ( (array) $decoded as $d ) {
				[ $name, $code ] = $expect[ $d['file'] ];
				$url             = 'testlabels' === $name ? home_url( '/scan/' . $code . '/' ) : ScanUrl::for_code( $code );
				$qr              = (bool) array_filter( $d['results'], static fn( $r ) => 'QRCode' === $r['format'] && $r['text'] === $url );
				$bc              = (bool) array_filter( $d['results'], static fn( $r ) => 'Code128' === $r['format'] && $r['text'] === $code );
				if ( ! $qr || ( 'a4' === $name && ! $bc ) ) {
					$fail[] = "$name $code";
				}
			}
			$counts = array_count_values( array_column( $expect, 0 ) );
			pqbg_t( "$bname: every printed label decoded from a screenshot at its printed size (QR → its scan URL; barcode → its code on A4 3 × 7): 300 dpi A4, 203 dpi thermal, 0.40 mm modules at 203 dpi", is_array( $decoded ) && count( $decoded ) === count( $files ) && array() === $fail && 18 === ( $counts['a4'] ?? 0 ) && 1 === ( $counts['minimum'] ?? 0 ), wp_json_encode( $counts ) . ' ' . implode( ', ', array_slice( $fail, 0, 5 ) ) );

			// PDF.
			$pdf_bad = array();
			foreach ( array( 'a4', 'small', 'thermal', 'minimum' ) as $name ) {
				$pdf  = $res['public']['pages'][ $name ]['pdf'];
				$ev   = $expect_view[ $name ];
				$spec = $ev['spec'];
				if ( count( $pdf['pages'] ) !== $ev['sheets'] ) {
					$pdf_bad[] = "$name: " . count( $pdf['pages'] ) . ' pages, expected ' . $ev['sheets'];
					continue;
				}
				foreach ( $pdf['pages'] as $pi => $pp ) {
					if ( abs( $pp['w'] - $spec['page_w'] ) > 0.5 || abs( $pp['h'] - $spec['page_h'] ) > 0.5 ) {
						$pdf_bad[] = "$name: page $pi is {$pp['w']} × {$pp['h']} mm";
					}
					$on_page = array_values( array_filter( $ev['labels'], static fn( $l ) => $l['sheet'] === $pi ) );
					if ( count( $pp['codes'] ) !== count( $on_page ) ) {
						$pdf_bad[] = "$name: page $pi has " . count( $pp['codes'] ) . ' code texts';
						continue;
					}
					foreach ( $on_page as $k => $l ) {
						[ $x, $y ] = PrintLayout::slot_position( $spec, $l['slot'] );
						$c         = $pp['codes'][ $k ];
						$top       = $y + $ev['fit']['text']['y'] + $ev['fit']['text']['h'] - $ev['fit']['code_lines'] * $ev['fit']['line'];
						if ( abs( $c['x'] - ( $x + $ev['fit']['text']['x'] ) ) > 0.3 || $c['baseline'] < $top || $c['baseline'] > $top + $ev['fit']['line'] + 0.3 ) {
							$pdf_bad[] = "$name: {$c['str']} at ({$c['x']}, {$c['baseline']})";
						}
					}
				}
			}
			$a4pdf = $res['public']['pages']['a4']['pdf']['pages'][0] ?? array( 'w' => 0, 'h' => 0 );
			$thpdf = $res['public']['pages']['thermal']['pdf']['pages'][0] ?? array( 'w' => 0, 'h' => 0 );
			pqbg_t( "$bname: PDF: one page per sheet or thermal label, page size = layout (±0.5 mm), every code text at its layout position (x ±0.3 mm, baseline on its line)", array() === $pdf_bad, "A4 page {$a4pdf['w']} × {$a4pdf['h']} mm, thermal page {$thpdf['w']} × {$thpdf['h']} mm; " . implode( ' | ', array_slice( $pdf_bad, 0, 5 ) ) );
			echo "   $bname PDF page sizes: A4 {$a4pdf['w']} × {$a4pdf['h']} mm, thermal 50 × 25 → {$thpdf['w']} × {$thpdf['h']} mm\n";
		}
		pqbg_t( 'both Chrome and Edge were checked', 2 === count( $results ), implode( ', ', array_keys( $results ) ) );
	}

	pqbg_section( 'timings: 100 and 300 labels, cold and warm cache' );
	$set_settings( array( 'scan_base_url' => $public_https ) );
	$bulk_ids = array();
	$t0       = microtime( true );
	for ( $i = 1; $i <= 300; $i++ ) {
		$bulk_ids[] = $make_simple( $A, sprintf( 'PQBG P8 timing %03d', $i ), array( 'price' => (string) ( 100 + $i ) ) );
	}
	echo '   created 300 products with codes in ' . round( microtime( true ) - $t0, 1 ) . " s\n";
	pqbg_t( '300 timing products, each with its own code', 300 === count( array_unique( array_filter( array_map( $code_of, $bulk_ids ) ) ) ) );
	$timing = array();
	foreach ( array( 100, 300 ) as $n ) {
		$ids = array_slice( $bulk_ids, 0, $n );
		foreach ( array( 'cold', 'warm' ) as $kind ) {
			if ( 'cold' === $kind ) {
				PrintCache::clear_all();
			}
			// Like a new request: nothing in memory (no persistent object cache here), so products and transients come from the database.
			wp_cache_flush();
			PrintCache::reset_request();
			$t    = microtime( true );
			$v    = $build( $ids, array( 'layout' => 'a4-3x7', 'fields' => PrintLayout::OPTIONAL_FIELDS ) );
			$html = PrintPage::render( $v );
			$timing[ "in-process $n $kind" ] = microtime( true ) - $t;
			$stats                           = PrintCache::stats();
			$timing[ "in-process $n $kind ok" ] = $n === $labels_in( $html ) && ( 'cold' === $kind ? $n === $stats['misses'] : ( $n === $stats['hits'] && 0 === $stats['misses'] ) );
			$timing[ "size $n" ] = strlen( $html );
		}
	}
	pqbg_t( 'in-process: 100 and 300 labels built; cold = all misses, warm = all hits', $timing['in-process 100 cold ok'] && $timing['in-process 100 warm ok'] && $timing['in-process 300 cold ok'] && $timing['in-process 300 warm ok'] );
	foreach ( array( 100, 300 ) as $n ) {
		$u = $print_url( 'admin', array_slice( $bulk_ids, 0, $n ), array( 'layout' => 'a4-3x7', 'fields' => PrintLayout::OPTIONAL_FIELDS ) );
		PrintCache::clear_all();
		$c = $http( 'admin', 'GET', $u );
		$w = $http( 'admin', 'GET', $u );
		$timing[ "HTTP $n cold" ]    = $c['time'];
		$timing[ "HTTP $n warm" ]    = $w['time'];
		$timing[ "HTTP $n ok" ]      = 200 === $c['code'] && 200 === $w['code'] && $n === $labels_in( $c['body'] ) && $c['body'] !== '' && $labels_in( $w['body'] ) === $n;
	}
	pqbg_t( 'over HTTP: 100 and 300 labels print (200, every label present), cold and warm', $timing['HTTP 100 ok'] && $timing['HTTP 300 ok'] );
	printf( "   %-12s %12s %12s %12s %12s   page size\n", 'labels', 'in-proc cold', 'in-proc warm', 'HTTP cold', 'HTTP warm' );
	foreach ( array( 100, 300 ) as $n ) {
		printf( "   %-12s %10.2f s %10.2f s %10.2f s %10.2f s   %.0f KB\n", $n, $timing[ "in-process $n cold" ], $timing[ "in-process $n warm" ], $timing[ "HTTP $n cold" ], $timing[ "HTTP $n warm" ], $timing[ "size $n" ] / 1024 );
	}
	pqbg_t( 'the warm cache is faster than cold (300 labels, in-process and over HTTP); 300 cold stays under 60 s', $timing['in-process 300 warm'] < $timing['in-process 300 cold'] && $timing['HTTP 300 warm'] < $timing['HTTP 300 cold'] && $timing['HTTP 300 cold'] < 60 );

	pqbg_section( 'scope' );
	$new_files = array( 'PrintLayout.php', 'PrintJob.php', 'PrintCache.php', 'PrintPage.php', 'PrintAdmin.php' );
	$code_only = static fn( string $f ) => implode( '', array_map( static fn( $t ) => is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t, token_get_all( (string) file_get_contents( $f ) ) ) );
	$src       = implode( "\n", array_map( static fn( $f ) => $code_only( PQBG_PLUGIN_DIR . 'includes/' . $f ), $new_files ) );
	pqbg_t( 'no REST routes, nopriv handlers, AJAX actions or shortcodes in the Phase 8 code', ! preg_match( '/register_rest_route|admin_post_nopriv|wp_ajax_|add_shortcode|add_rewrite/', $src ) );
	pqbg_t( 'print endpoints are admin-post handlers registered only for admin requests', (bool) preg_match( '/if \( is_admin\(\) \) \{.*PrintAdmin::register\(\);\s*PrintPage::register\(\);.*\}/s', $code_only( PQBG_PLUGIN_DIR . 'includes/Plugin.php' ) ) && str_contains( $src, "add_action( 'admin_post_' . self::ACTION" ) && str_contains( $src, "add_action( 'admin_post_' . self::PREPARE" ) );
	pqbg_t( 'scan URLs only from ScanUrl (no scan path literal in the Phase 8 code); images only from the renderers (no library namespaces)', ! preg_match( "#'/?scan/?'|/scan/#", $src ) && ! str_contains( $src, 'Vendor\\' ) && ! str_contains( $src, 'Encoder::' ) && ! str_contains( $src, 'Picqer' ) );
	pqbg_t( 'no PDF library anywhere in the plugin', array() === array_filter( array_merge( glob( PQBG_PLUGIN_DIR . 'includes/*.php' ), glob( PQBG_PLUGIN_DIR . 'templates/*.php' ) ), static fn( $f ) => (bool) preg_match( '/tcpdf|dompdf|fpdf|mpdf|wkhtmltopdf/i', (string) file_get_contents( $f ) ) ) && ! is_dir( PQBG_PLUGIN_DIR . 'vendor-prefixed/dompdf' ) );
	preg_match_all( '/\$wpdb->(query|insert|update|delete|replace)\((.*?)\);/s', $code_only( PQBG_PLUGIN_DIR . 'includes/PrintCache.php' ), $m );
	pqbg_t( 'PrintCache\'s only raw SQL is a DELETE of its own transient rows', 1 === count( $m[0] ) && str_contains( $m[2][0], 'DELETE FROM {$wpdb->options} WHERE option_name LIKE %s' ) && str_contains( $m[2][0], 'self::PREFIX' ) );
	pqbg_t( 'retired codes: the print code path reads codes only through CodeRepository::find_active_for_products()', str_contains( $src, 'CodeRepository::find_active_for_products(' ) && ! preg_match( '/find_by_code|find_retired|STATUS_RETIRED/', $src ) );
	pqbg_t( 'codes are never generated or written by printing', ! preg_match( '/get_or_create|create_active|replace_active|regenerate|CodeGenerator::generate|->retire\(/', $src ) );
	pqbg_t( 'direct HTTP to the new PHP files gives empty output', array() === array_filter( array( 'includes/PrintLayout.php', 'includes/PrintJob.php', 'includes/PrintCache.php', 'includes/PrintPage.php', 'includes/PrintAdmin.php', 'templates/pqbg-print.php' ), static fn( $f ) => '' !== $http( 'anon', 'GET', PQBG_PLUGIN_URL . $f )['body'] ) );
	pqbg_t( 'tests/print-check is denied over HTTP', 403 === $http( 'anon', 'GET', PQBG_PLUGIN_URL . 'tests/print-check/check.mjs' )['code'] );
} finally {
	pqbg_section( 'cleanup' );
	wp_set_current_user( 0 );
	$handles = array();
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d ORDER BY post_type = 'product', ID DESC", $start_post ) ) );
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id > %d", $start_post ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_relationships} WHERE object_id > %d", $start_post ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id > %d", $start_post ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wc_product_attributes_lookup WHERE product_id > %d OR product_or_parent_id > %d", $start_post, $start_post ) );
	foreach ( $user_ids as $uid ) {
		if ( is_int( $uid ) ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d", $uid ) ) as $pid ) {
				wp_delete_post( (int) $pid, true );
			}
			wp_delete_user( $uid );
		}
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM $C WHERE id > %d", $start_c ) );
	$wpdb->query( "ALTER TABLE $C AUTO_INCREMENT = 1" );
	PrintCache::clear_all();
	foreach ( $saved as $name => $row ) {
		if ( null === $row ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		} else {
			$wpdb->replace( $wpdb->options, array( 'option_name' => $name, 'option_value' => $row['option_value'], 'autoload' => $row['autoload'] ) );
		}
		wp_cache_delete( $name, 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_flush();
	$removed_as = pqbg_test_as_cleanup( $as_mark );
	echo '   removed ' . count( $ids ) . ' post(s) and ' . $removed_as . " Action Scheduler job(s)\n";
	$rm = static function ( string $dir ) use ( &$rm ): void {
		foreach ( (array) glob( $dir . '/{,.}[!.,!..]*', GLOB_BRACE ) as $f ) {
			is_dir( $f ) && ! is_link( $f ) ? $rm( $f ) : @unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- browser profile files may be locked briefly.
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
	};
	$rm( $tmp );
	pqbg_t( 'codes table back to its starting row count', $base_c === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" ) );
	pqbg_t( 'products and variations back to the starting count', $base_prod === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation')" ) );
	pqbg_t( 'no posts, meta or term relationships left above the starting IDs', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID > %d", $start_post ) ) + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id > %d", $start_post ) ) + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id > %d", $start_post ) ) );
	pqbg_t( 'temporary users (and their print preferences) removed', $base_user === (int) count_users()['total_users'] && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", PrintJob::PREFS_META ) ) );
	pqbg_t( 'render cache empty; options restored byte for byte (settings, cache index, Coming Soon, rewrite rules, rewrite flag, store name)', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( PrintCache::PREFIX ) . '%' ) ) && array() === array_filter( array_keys( $saved ), static fn( $n ) => $saved[ $n ] !== $raw_option( $n ) ) );
	pqbg_t( 'temporary directory removed', ! is_dir( $tmp ) );
	pqbg_test_as_check( $as_mark );
}

pqbg_test_done();
