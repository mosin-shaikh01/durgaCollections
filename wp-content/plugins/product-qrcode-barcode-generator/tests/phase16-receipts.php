<?php
/**
 * Phase 16 suite: receipts and the UPI payment QR.
 *
 * Checks the new settings (receipt texts, phone, GSTIN with its check character, UPI ID
 * and payee name; invalid input keeps the previous value; other keys kept), when the
 * UPI QR is active (INR only, UPI enabled), the upi:// payload, the receipt route
 * (who sees which receipt; a missing, failed or other seller's sale gets the same 404;
 * canonical URLs and ?paper=; logged out; GET/HEAD only), the receipt content (no cost
 * or profit, VOID marking, the seller's first name setting, the WhatsApp text and link),
 * the receipt page's CSP (one nonce'd script, only there), the two-step UPI sale
 * (no sale at the QR step, a tampered quantity refused, idempotent confirm, the
 * "may already have paid" note after a price change), and scope rules.
 *
 * In-process only (no HTTP). Temporarily changes pqbg_settings and restores the exact
 * stored value at the end. Creates users, one product with its code, and sale rows,
 * and removes them all.
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/bootstrap.php';
pqbg_test_load_wp();
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use ProductQrBarcode\{CodeRepository, CostPrice, PaymentMethods, Permissions, Plugin, QrRenderer, Receipt, SaleRepository, SaleRequest, SaleService, ScanRoute, ScanScreen, ScanUrl, Schema, Settings, SettingsPage, UpiPayment, UpiSale};

global $wpdb;

$C           = Schema::codes_table();
$S           = Schema::sales_table();
$option      = Plugin::SETTINGS_OPTION;
$raw_setting = static fn() => $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $option ), ARRAY_A );
$original    = $raw_setting();
$start_code  = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $C" );
$start_sale  = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $S" );
$start_post  = (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID), 0) FROM {$wpdb->posts}" );
$base_c      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" );
$base_s      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $S" );
$base_user   = (int) count_users()['total_users'];
$as_mark     = pqbg_test_as_mark(); // Action Scheduler cleanup, see bootstrap.php.
$cl_mark     = pqbg_test_catlookup_mark(); // Category lookup rows, see bootstrap.php.
$users       = array();
$inr         = static fn() => 'INR';

/** Stores plugin settings exactly (no sanitize callback outside wp-admin). */
$set = static function ( array $values ) use ( $option ): void {
	update_option( $option, array_merge( Plugin::default_settings(), $values ), false );
	wp_cache_delete( $option, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
};
$user = static function ( string $login, string $role ) use ( &$users ): int {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $login . '@example.invalid', 'role' => $role, 'first_name' => 'Asha', 'display_name' => 'Asha Patil' ) );
	$users[] = is_int( $id ) ? $id : 0;
	return is_int( $id ) ? $id : 0;
};
$path_of = static fn( string $url ) => (string) wp_parse_url( $url, PHP_URL_PATH );
$query_of = static fn( string $url ) => (string) wp_parse_url( $url, PHP_URL_QUERY );
$open    = static function ( int $as, int $sale_id, string $paper = '' ) use ( $path_of, $query_of ): array {
	wp_set_current_user( $as );
	$url = ScanUrl::receipt_url( $sale_id, $paper );
	$r   = ScanRoute::decide( 'GET', $path_of( $url ) . ( '' === $query_of( $url ) ? '' : '?' . $query_of( $url ) ), ScanUrl::RECEIPT . '/' . $sale_id, null );
	wp_set_current_user( 0 );
	return $r;
};
$html_of = static fn( array $r ) => isset( $r['view'] ) ? ScanScreen::render( $r['view'] ) : '';

try {
	add_filter( 'woocommerce_currency', $inr, 99 );

	pqbg_section( 'settings: receipts' );
	$defaults = Plugin::default_settings();
	pqbg_t( 'defaults: empty texts, seller shown, A4, UPI off', '' === $defaults['receipt_shop_name'] && true === $defaults['receipt_show_seller'] && 'a4' === $defaults['receipt_paper'] && '' === $defaults['upi_id'] && '' === $defaults['upi_payee_name'] && 1 === $defaults['settings_version'] );
	pqbg_t( 'shop name: one line, trimmed', 'Durga Collections' === Settings::validate_receipt_text( 'receipt_shop_name', '  Durga Collections ' ) );
	pqbg_t( 'shop name: two lines refused', is_wp_error( Settings::validate_receipt_text( 'receipt_shop_name', "A\nB" ) ) );
	pqbg_t( 'shop name: 81 characters refused', is_wp_error( Settings::validate_receipt_text( 'receipt_shop_name', str_repeat( 'x', 81 ) ) ) );
	pqbg_t( 'address: empty lines dropped, 4 lines accepted', "1 Main Rd\nPune\n411001\nIndia" === Settings::validate_receipt_text( 'receipt_address', "1 Main Rd\r\n\r\nPune\n 411001 \nIndia" ) );
	pqbg_t( 'address: 5 lines refused', is_wp_error( Settings::validate_receipt_text( 'receipt_address', "a\nb\nc\nd\ne" ) ) );
	pqbg_t( 'footer: 4 lines refused, tags removed', is_wp_error( Settings::validate_receipt_text( 'receipt_footer', "a\nb\nc\nd" ) ) && 'Thanks' === Settings::validate_receipt_text( 'receipt_footer', '<b>Thanks</b>' ) );
	pqbg_t( 'phone: digits, spaces, + - ( ) accepted', '+91 (20) 1234-5678' === Settings::validate_phone( ' +91 (20) 1234-5678 ' ) && '' === Settings::validate_phone( '' ) );
	pqbg_t( 'phone: letters, no digit or 31 characters refused', is_wp_error( Settings::validate_phone( 'call me' ) ) && is_wp_error( Settings::validate_phone( '+-()' ) ) && is_wp_error( Settings::validate_phone( str_repeat( '1', 31 ) ) ) );
	pqbg_t( 'GSTIN: a valid one accepted, spaces removed, uppercased', '27AAPFU0939F1ZV' === Settings::validate_gstin( ' 27aapfu0939f1zv ' ) && '' === Settings::validate_gstin( '' ) );
	pqbg_t( 'GSTIN: wrong check character refused', is_wp_error( Settings::validate_gstin( '27AAPFU0939F1ZW' ) ) );
	pqbg_t( 'GSTIN: wrong format refused', is_wp_error( Settings::validate_gstin( '27AAPFU0939F1Y' ) ) && is_wp_error( Settings::validate_gstin( 'AAAAAAAAAAAAAAA' ) ) );

	pqbg_section( 'settings: UPI' );
	pqbg_t( 'UPI ID: accepted and lowercased', 'durga.shop@okaxis' === UpiPayment::validate_id( ' Durga.Shop@OKAXIS ' ) && '' === UpiPayment::validate_id( '' ) );
	foreach ( array( 'no @' => 'durgashop', 'two @' => 'a@b@c', 'space' => 'durga shop@upi', 'one character' => 'd@upi', 'digit after @' => 'shop@1bank', 'markup' => '<b>@upi', 'not a string' => array( 'x@upi' ) ) as $label => $bad ) {
		pqbg_t( "UPI ID refused: {$label}", is_wp_error( UpiPayment::validate_id( $bad ) ) );
	}
	pqbg_t( 'payee: accepted, spaces collapsed', 'Durga Collections & Co.' === UpiPayment::validate_payee( '  Durga  Collections & Co. ' ) );
	pqbg_t( 'payee: non-ASCII, one character or 51 characters refused', is_wp_error( UpiPayment::validate_payee( 'दुर्गा' ) ) && is_wp_error( UpiPayment::validate_payee( 'D' ) ) && is_wp_error( UpiPayment::validate_payee( str_repeat( 'a', 51 ) ) ) );
	$set( array( 'upi_id' => 'old@upi', 'receipt_gstin' => '27AAPFU0939F1ZV' ) );
	$out = Settings::sanitize( array( 'upi_id' => 'broken', 'receipt_gstin' => 'bad' ) );
	$errors = array_column( get_settings_errors( $option ), 'code' );
	pqbg_t( 'sanitize: invalid values keep the previous ones and report errors', 'old@upi' === $out['upi_id'] && '27AAPFU0939F1ZV' === $out['receipt_gstin'] && in_array( 'pqbg_invalid_upi_id', $errors, true ) && in_array( 'pqbg_invalid_receipt_gstin', $errors, true ) );
	$out = Settings::sanitize( array( 'receipt_show_seller' => '0', 'receipt_paper' => '58' ) );
	pqbg_t( 'sanitize: seller checkbox and paper saved; other keys kept', false === $out['receipt_show_seller'] && '58' === $out['receipt_paper'] && 'old@upi' === $out['upi_id'] && 'DC' === $out['code_prefix'] );
	$out = Settings::sanitize( array( 'receipt_paper' => 'letter' ) );
	pqbg_t( 'sanitize: an unknown paper is ignored', 'a4' === $out['receipt_paper'] );
	pqbg_t( 'the Settings page (and so these fields) is for administrators only', Permissions::MANAGE_SETTINGS === SettingsPage::capability() && ! get_role( 'shop_manager' )->has_cap( Permissions::MANAGE_SETTINGS ) );

	pqbg_section( 'UPI activation and payload' );
	$set( array() );
	pqbg_t( 'off until both the UPI ID and the payee name are set', ! UpiPayment::is_configured() && ! UpiPayment::is_active() );
	$set( array( 'upi_id' => 'durga@okaxis' ) );
	pqbg_t( 'the UPI ID alone is not enough', ! UpiPayment::is_active() );
	$set( array( 'upi_id' => 'durga@okaxis', 'upi_payee_name' => 'Durga Collections', 'receipt_shop_name' => 'Durga Collections' ) );
	pqbg_t( 'on with both, INR and UPI enabled', UpiPayment::is_active() && '' === UpiPayment::inactive_reason() );
	$usd = static fn() => 'USD';
	add_filter( 'woocommerce_currency', $usd, 100 );
	pqbg_t( 'off when the store currency is not INR, with the reason', ! UpiPayment::is_active() && str_contains( UpiPayment::inactive_reason(), 'USD' ) );
	remove_filter( 'woocommerce_currency', $usd, 100 );
	$set( array( 'upi_id' => 'durga@okaxis', 'upi_payee_name' => 'Durga Collections', 'payment_methods' => array( PaymentMethods::CASH ) ) );
	pqbg_t( 'off when UPI is not an enabled payment method', ! UpiPayment::is_active() && '' !== UpiPayment::inactive_reason() );
	$set( array( 'upi_id' => 'durga@okaxis', 'upi_payee_name' => 'Durga Collections', 'receipt_shop_name' => 'Durga Collections' ) );
	$uuid = '3f9a0c1b-1234-4abc-8def-0123456789ab';
	pqbg_t( 'reference: first 8 characters of the request ID, uppercased', '3F9A0C1B' === UpiPayment::reference( $uuid ) && '' === UpiPayment::reference( 'nope' ) );
	pqbg_t( 'amount: two decimals, "." separator, no thousands separator', '1499.00' === UpiPayment::amount( '1499' ) && '12345.50' === UpiPayment::amount( '12345.5' ) );
	pqbg_t( 'payload: pa literal, pn/tn encoded, am, cu=INR, no tr/mc', 'upi://pay?pa=durga@okaxis&pn=Durga%20Collections&am=1499.00&cu=INR&tn=Durga%20Collections%203F9A0C1B' === UpiPayment::uri( '1499.00', '3F9A0C1B' ) );
	pqbg_t( 'payload: refused for a malformed amount or reference', '' === UpiPayment::uri( '1,499.00', '3F9A0C1B' ) && '' === UpiPayment::uri( '0.00', '3F9A0C1B' ) && '' === UpiPayment::uri( '10.00', 'xyz' ) );
	$svg = ( new QrRenderer() )->render_upi( '1499.00', '3F9A0C1B' );
	pqbg_t( 'QR: an SVG labelled "UPI payment QR"; a bad amount is an error', is_string( $svg ) && str_starts_with( $svg, '<svg' ) && str_contains( $svg, 'aria-label="UPI payment QR"' ) && is_wp_error( ( new QrRenderer() )->render_upi( 'x', '3F9A0C1B' ) ) );

	pqbg_section( 'receipt URLs' );
	pqbg_t( 'receipt URL: /scan/receipt/{id}/; ?paper= only when not the default', home_url( '/scan/receipt/42/' ) === ScanUrl::receipt_url( 42 ) && home_url( '/scan/receipt/42/' ) === ScanUrl::receipt_url( 42, 'a4' ) && home_url( '/scan/receipt/42/?paper=80' ) === ScanUrl::receipt_url( 42, '80' ) && home_url( '/scan/receipt/42/' ) === ScanUrl::receipt_url( 42, 'letter' ) );
	pqbg_t( 'segment parsing', 42 === ScanUrl::receipt_id( 'receipt/42' ) && 0 === ScanUrl::receipt_id( 'receipt/0' ) && 0 === ScanUrl::receipt_id( 'receipt/x' ) && 0 === ScanUrl::receipt_id( 'receipt/42/9' ) && ScanUrl::is_receipt_segment( 'receipt' ) && ! ScanUrl::is_receipt_segment( 'receipts' ) && ! ScanUrl::is_receipt_segment( null ) );

	pqbg_section( 'sales for the receipt checks' );
	$admin   = $user( 'pqbg_p16_admin', 'administrator' );
	$manager = $user( 'pqbg_p16_manager', 'shop_manager' );
	$seller  = $user( 'pqbg_p16_seller', Permissions::SELLER_ROLE );
	$other   = $user( 'pqbg_p16_other', Permissions::SELLER_ROLE );
	$buyer   = $user( 'pqbg_p16_customer', 'customer' );
	pqbg_t( 'temporary users created', 0 < min( $admin, $manager, $seller, $other, $buyer ) );
	wp_set_current_user( $admin );
	$p = new WC_Product_Simple();
	$p->set_name( 'PQBG P16 kurta ' . wp_generate_password( 6, false ) );
	$p->set_status( 'publish' );
	$p->set_regular_price( '1499' );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 20 );
	$pid = (int) $p->save();
	CostPrice::set( $pid, '777.77' );
	wp_set_current_user( 0 );
	$code = (string) ( CodeRepository::find_active_for_product( $pid )['code'] ?? '' );
	pqbg_t( 'product and code created', $pid > 0 && '' !== $code );
	$sell = static fn( int $as, string $method = 'cash', int $qty = 1 ) => SaleService::sell( array( 'code' => $code, 'quantity' => $qty, 'request_id' => wp_generate_uuid4(), 'seller_id' => $as, 'payment_method' => $method ) );
	$a = $sell( $seller );
	$b = $sell( $seller, 'upi', 2 );
	$c = $sell( $other );
	pqbg_t( 'three sales completed', ! is_wp_error( $a ) && ! is_wp_error( $b ) && ! is_wp_error( $c ) && SaleRepository::STATUS_COMPLETED === $a['status'] && SaleRepository::STATUS_COMPLETED === $c['status'] );
	$A = (int) $a['sale']['id'];
	$B = (int) $b['sale']['id'];
	$O = (int) $c['sale']['id'];
	$v = SaleService::void_sale( $O, $admin, 'P16 test void', true );
	pqbg_t( "the other seller's sale voided", ! is_wp_error( $v ) );
	$wpdb->insert( $S, array( 'request_id' => wp_generate_uuid4(), 'product_id' => $pid, 'seller_id' => $seller, 'unit_price' => '1499', 'line_total' => '1499', 'currency' => 'INR', 'product_name' => 'P16 failed', 'created_at_gmt' => gmdate( 'Y-m-d H:i:s' ), 'status' => SaleRepository::STATUS_FAILED, 'failure_code' => 'test' ) );
	$F = (int) $wpdb->insert_id;

	pqbg_section( 'receipt access' );
	$r = $open( $seller, $A );
	pqbg_t( 'seller: own completed sale -> 200 receipt', 200 === $r['status'] && is_array( $r['view']['receipt'] ?? null ) );
	$not_found = $html_of( $open( $seller, $O ) );
	pqbg_t( "seller: another seller's sale -> 404", 404 === $open( $seller, $O )['status'] );
	pqbg_t( 'seller: own failed sale and a missing sale -> the same 404 page', 404 === $open( $seller, $F )['status'] && 404 === $open( $seller, 999999999 )['status'] && $html_of( $open( $seller, $F ) ) === $not_found && $html_of( $open( $seller, 999999999 ) ) === $not_found );
	pqbg_t( "shop manager and administrator: any seller's receipt", 200 === $open( $manager, $A )['status'] && 200 === $open( $manager, $O )['status'] && 200 === $open( $admin, $O )['status'] );
	pqbg_t( 'customer: the fixed 403, before any lookup', 403 === $open( $buyer, $A )['status'] && 403 === $open( $buyer, 999999999 )['status'] );
	$r = ScanRoute::decide( 'GET', $path_of( ScanUrl::receipt_url( $A ) ), ScanUrl::RECEIPT . '/' . $A, null );
	pqbg_t( 'logged out: 302 to the login page, back to the receipt', 302 === $r['status'] && wp_login_url( ScanUrl::receipt_url( $A ) ) === $r['location'] && ! isset( $r['view'] ) );
	wp_set_current_user( $seller );
	$r = ScanRoute::decide( 'POST', $path_of( ScanUrl::receipt_url( $A ) ), ScanUrl::RECEIPT . '/' . $A, null, array( 'x' => '1' ) );
	pqbg_t( 'POST: 405, Allow: GET, HEAD', 405 === $r['status'] && 'GET, HEAD' === $r['headers']['Allow'] );
	$r = ScanRoute::decide( 'GET', $path_of( ScanUrl::receipt_url( $A ) ) . '?paper=a4', ScanUrl::RECEIPT . '/' . $A, null );
	pqbg_t( 'the default paper in the query: 301 to the address without it', 301 === $r['status'] && ScanUrl::receipt_url( $A ) === $r['location'] );
	$r = ScanRoute::decide( 'GET', $path_of( ScanUrl::receipt_url( $A ) ) . '?paper=letter', ScanUrl::RECEIPT . '/' . $A, null );
	pqbg_t( 'an unknown paper: 301 to the default', 301 === $r['status'] && ScanUrl::receipt_url( $A ) === $r['location'] );
	$r = ScanRoute::decide( 'GET', $path_of( home_url( '/scan/receipt/abc/' ) ), 'receipt/abc', null );
	pqbg_t( 'a malformed receipt address: 404', 404 === $r['status'] );
	wp_set_current_user( 0 );
	$r = $open( $seller, $A, '58' );
	pqbg_t( '?paper=58: the 58 mm layout', 200 === $r['status'] && '58' === $r['view']['receipt']['paper'] );

	pqbg_section( 'receipt content' );
	$set( array( 'upi_id' => 'durga@okaxis', 'upi_payee_name' => 'Durga Collections', 'receipt_shop_name' => 'Durga Collections', 'receipt_address' => "1 Main Rd\nPune", 'receipt_phone' => '+91 20 1234 5678', 'receipt_gstin' => '27AAPFU0939F1ZV', 'receipt_footer' => 'Thank you!' ) );
	$html = $html_of( $open( $seller, $B ) );
	$sale = SaleRepository::find( $B );
	pqbg_t( 'shop name, address, phone, GSTIN, footer', str_contains( $html, 'Durga Collections' ) && str_contains( $html, '1 Main Rd' ) && str_contains( $html, '+91 20 1234 5678' ) && str_contains( $html, '27AAPFU0939F1ZV' ) && str_contains( $html, 'Thank you!' ) );
	pqbg_t( 'labelled "Receipt", never "invoice"', str_contains( $html, '>Receipt<' ) && ! preg_match( '/invoice/i', $html ) );
	pqbg_t( 'receipt number = sale ID; item; 2 × price = total; paid by UPI with the reference', str_contains( $html, '>' . $B . '<' ) && str_contains( $html, esc_html( $sale['product_name'] ) ) && str_contains( $html, 'UPI' ) && str_contains( $html, UpiPayment::reference( (string) $sale['request_id'] ) ) );
	pqbg_t( 'no cost or profit anywhere (777.77 is the cost price)', ! str_contains( $html, '777' ) && ! preg_match( '/\bcost\b|profit/i', $html ) );
	pqbg_t( "the seller's first name (from the snapshot) shown by default", str_contains( $html, 'Asha' ) && ! str_contains( $html, 'Patil' ) );
	$set( array( 'receipt_show_seller' => false ) );
	pqbg_t( 'seller hidden when the setting is off', ! str_contains( $html_of( $open( $seller, $B ) ), 'Asha' ) );
	$html = $html_of( $open( $admin, $O ) );
	pqbg_t( 'voided sale: VOID banner and note, no WhatsApp button', str_contains( $html, 'pqbg-receipt--void' ) && str_contains( $html, '>VOID<' ) && str_contains( $html, 'This sale was voided on' ) && ! str_contains( $html, 'wa.me' ) );
	$data = Receipt::data( SaleRepository::find( $A ) );
	$text = Receipt::text( $data );
	pqbg_t( 'WhatsApp text: receipt number, total, no link to the receipt, no cost', str_contains( $text, 'Receipt no. ' . $A ) && str_contains( $text, 'Total:' ) && ! str_contains( $text, '/scan/' ) && ! str_contains( $text, 'http' ) && ! str_contains( $text, '777' ) && mb_strlen( $text ) <= Receipt::TEXT_MAX );
	pqbg_t( 'WhatsApp link: wa.me with the text only, no phone number', str_starts_with( Receipt::whatsapp_url( $text ), 'https://wa.me/?text=' ) && rawurldecode( substr( Receipt::whatsapp_url( $text ), strlen( 'https://wa.me/?text=' ) ) ) === $text );
	$html = $html_of( $open( $seller, $A ) );
	pqbg_t( 'completed sale: the WhatsApp link opens in a new tab without a referrer', str_contains( $html, 'https://wa.me/?text=' ) && str_contains( $html, 'rel="noopener noreferrer"' ) );

	pqbg_section( 'receipt page: standalone, CSP' );
	$r = $open( $seller, $A );
	pqbg_t( 'nonces for one style and one script element', '' !== $r['view']['style_nonce'] && '' !== $r['view']['script_nonce'] && $r['view']['style_nonce'] !== $r['view']['script_nonce'] );
	$html = $html_of( $r );
	pqbg_t( 'exactly one script element, pqbg-receipt.js with the nonce; no inline script; no theme', 1 === substr_count( $html, '<script' ) && str_contains( $html, 'assets/pqbg-receipt.js' ) && str_contains( $html, 'nonce="' . $r['view']['script_nonce'] . '"' ) && ! preg_match( '#<script[^>]*>[^<]+</script>#', $html ) && ! str_contains( $html, 'wp-block-library' ) );
	$csp = ScanRoute::receipt_csp( 'S1', 'J1' );
	pqbg_t( "receipt CSP: csp() plus script-src 'nonce-…' only", ScanRoute::csp( 'S1' ) . "; script-src 'nonce-J1'" === $csp && ! str_contains( $csp, "script-src 'self'" ) && ! str_contains( $csp, 'unsafe' ) );
	pqbg_t( 'every other scan page keeps a CSP without script-src', ! str_contains( ScanRoute::security_headers()['Content-Security-Policy'], 'script-src' ) && ! str_contains( ScanRoute::csp( 'X' ), 'script-src' ) );

	pqbg_section( 'links to receipts' );
	wp_set_current_user( $seller );
	$view = ScanScreen::sale( SaleRepository::find( $A ), $code );
	pqbg_t( 'sale result page: Receipt link', ScanUrl::receipt_url( $A ) === $view['sale']['receipt'] && str_contains( ScanScreen::render( $view ), ScanUrl::receipt_url( $A ) ) );
	$mine = ScanScreen::my_sales( $seller, 'today' );
	pqbg_t( 'My sales: a Receipt link on each line', array() !== $mine['mine']['lines'] && ! array_filter( $mine['mine']['lines'], static fn( $l ) => '' === $l['receipt'] ) );
	wp_set_current_user( 0 );
	pqbg_t( 'admin detail and list: Receipt links through ScanUrl', str_contains( (string) file_get_contents( PQBG_PLUGIN_DIR . 'includes/SalesAdmin.php' ), 'ScanUrl::receipt_url( $id )' ) && str_contains( (string) file_get_contents( PQBG_PLUGIN_DIR . 'includes/SalesListTable.php' ), "ScanUrl::receipt_url( (int) \$item['id'] )" ) );

	pqbg_section( 'two-step UPI sale' );
	$set( array( 'upi_id' => 'durga@okaxis', 'upi_payee_name' => 'Durga Collections', 'receipt_shop_name' => 'Durga Collections' ) );
	$form = static function () use ( $seller, $code ): array {
		wp_set_current_user( $seller );
		$sell = ScanScreen::resolve( $code )['sell'];
		return array_merge( $sell['fields'], array( 'pqbg_action' => 'sell', '_pqbg_nonce' => $sell['nonce'], 'quantity' => '2', 'payment_method' => 'upi' ) );
	};
	$count  = static fn( string $req ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $S WHERE request_id = %s", $req ) );
	$stock  = static fn() => (int) SaleRepository::read_stock( $pid );
	$post   = $form();
	$before = $stock();
	$r      = UpiSale::handle( $code, $post );
	pqbg_t( 'step 1: 200 with the UPI panel, the QR and the note; no sale, stock unchanged', 200 === $r['status'] && is_array( $r['view']['upi'] ) && str_contains( $r['view']['upi']['qr'], '<svg' ) && null === $r['view']['sell'] && 0 === $count( $post['request_id'] ) && $before === $stock() && str_contains( ScanScreen::render( $r['view'] ), 'Check the customer' ) );
	pqbg_t( 'step 1: amount = price × quantity; reference from the request ID', \ProductQrBarcode\SalePresenter::money( '2998' ) === $r['view']['upi']['amount'] && UpiPayment::reference( $post['request_id'] ) === $r['view']['upi']['reference'] );
	$confirm = $r['view']['upi']['fields'];
	$confirm = array_merge( $confirm, array( 'pqbg_action' => 'sell', '_pqbg_nonce' => $r['view']['upi']['nonce'] ) );
	$r = UpiSale::handle( $code, array_merge( $confirm, array( 'quantity' => '1' ) ) );
	pqbg_t( 'step 2 with a changed quantity: refused (400), no sale', 400 === $r['status'] && 0 === $count( $post['request_id'] ) );
	$r = UpiSale::handle( $code, $confirm );
	$made = SaleRepository::find_by_request_id( $post['request_id'] );
	pqbg_t( 'step 2: 303 to the sale; recorded as UPI, quantity 2, stock down by 2', 303 === $r['status'] && null !== $made && 'upi' === $made['payment_method'] && 2 === (int) $made['quantity'] && $before - 2 === $stock() );
	$r = UpiSale::handle( $code, $confirm );
	pqbg_t( 'step 2 again (double tap): the same sale, never a second one', 303 === $r['status'] && 1 === $count( $post['request_id'] ) && $before - 2 === $stock() );
	$post = $form();
	$r    = UpiSale::handle( $code, $post );
	wp_set_current_user( $admin );
	$pp = wc_get_product( $pid );
	$pp->set_regular_price( '1599' );
	$pp->save();
	wp_set_current_user( $seller );
	$confirm = array_merge( $r['view']['upi']['fields'], array( 'pqbg_action' => 'sell', '_pqbg_nonce' => $r['view']['upi']['nonce'] ) );
	$r       = UpiSale::handle( $code, $confirm );
	$notes   = array_column( $r['view']['notices'] ?? array(), 1 );
	pqbg_t( 'price changed after the QR: refused, no sale, with the "may already have paid" note', $r['status'] >= 400 && null === SaleRepository::find_by_request_id( $post['request_id'] ) && (bool) array_filter( $notes, static fn( $n ) => str_contains( $n, 'may already have paid' ) ) );
	$post = $form();
	$r    = UpiSale::handle( $code, array_merge( $post, array( 'payment_method' => 'cash' ) ) );
	pqbg_t( 'cash: one step as before (sold at once)', 303 === $r['status'] && null !== SaleRepository::find_by_request_id( $post['request_id'] ) );
	$set( array() );
	$post = $form();
	$r    = UpiSale::handle( $code, $post );
	pqbg_t( 'UPI QR off: UPI is one step as before', 303 === $r['status'] && 'upi' === ( SaleRepository::find_by_request_id( $post['request_id'] )['payment_method'] ?? '' ) );
	wp_set_current_user( 0 );

	pqbg_section( 'scope' );
	$src_code = static fn( string $f ) => implode( '', array_map( static fn( $t ) => is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t, token_get_all( (string) file_get_contents( PQBG_PLUGIN_DIR . $f ) ) ) );
	pqbg_t( 'Receipt reads no cost: unit_cost only in the line that removes it; no CostPrice', 1 === substr_count( $src_code( 'includes/Receipt.php' ), 'unit_cost' ) && str_contains( $src_code( 'includes/Receipt.php' ), "unset( \$sale['unit_cost'] )" ) && ! preg_match( '/CostPrice|can_view_costs|profit/i', $src_code( 'includes/Receipt.php' ) . $src_code( 'templates/pqbg-receipt.php' ) . $src_code( 'includes/UpiSale.php' ) . $src_code( 'includes/UpiPayment.php' ) ) );
	pqbg_t( 'the scan template still has no script', ! str_contains( (string) file_get_contents( PQBG_PLUGIN_DIR . 'templates/pqbg-scan.php' ), '<script' ) );
	pqbg_t( 'the new classes add no hook, handler, REST/AJAX route, shortcode or rewrite rule', ! preg_match( '/add_action\(|add_filter\(|admin_post_|wp_ajax_|register_rest_route|add_shortcode|add_rewrite/', $src_code( 'includes/Receipt.php' ) . $src_code( 'includes/UpiPayment.php' ) . $src_code( 'includes/UpiSale.php' ) ) );
	pqbg_t( 'the new classes never write to the database', ! preg_match( '/\$wpdb|update_option|add_option|update_post_meta|->save\(|wc_update_product_stock/', $src_code( 'includes/Receipt.php' ) . $src_code( 'includes/UpiPayment.php' ) . $src_code( 'includes/UpiSale.php' ) ) );
	pqbg_t( 'UpiSale sells only through SaleRequest::handle()', ! str_contains( $src_code( 'includes/UpiSale.php' ), 'SaleService::sell' ) && str_contains( $src_code( 'includes/UpiSale.php' ), 'SaleRequest::handle( $code, $post )' ) );
	pqbg_t( 'the receipt path is built only by ScanUrl', ! preg_match( "#'/scan/receipt|'receipt/#", $src_code( 'includes/ScanRoute.php' ) . $src_code( 'includes/ScanScreen.php' ) . $src_code( 'includes/SalesAdmin.php' ) . $src_code( 'includes/SalesListTable.php' ) ) );
} catch ( Throwable $e ) {
	pqbg_t( 'suite ran without an exception', false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
	pqbg_section( 'cleanup' );
	wp_set_current_user( 0 );
	remove_filter( 'woocommerce_currency', $inr, 99 );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $S WHERE id > %d", $start_sale ) );
	$wpdb->query( "ALTER TABLE $S AUTO_INCREMENT = 1" );
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ('product_variation','product','revision') ORDER BY post_type = 'product', ID DESC", $start_post ) ) );
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( array_filter( $users ) as $uid ) {
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d", $uid ) ) as $pid_left ) {
			wp_delete_post( (int) $pid_left, true );
		}
		wp_delete_user( $uid );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM $C WHERE id > %d", $start_code ) );
	$wpdb->query( "ALTER TABLE $C AUTO_INCREMENT = 1" );
	$wpdb->update( $wpdb->options, $original, array( 'option_name' => $option ) );
	wp_cache_delete( $option, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	$removed_as = pqbg_test_as_cleanup( $as_mark );
	echo '   removed ' . count( $ids ) . ' post(s) and ' . $removed_as . " Action Scheduler job(s)\n";
	pqbg_test_as_check( $as_mark );
	pqbg_test_catlookup_cleanup( $cl_mark );
	pqbg_test_catlookup_check( $cl_mark );
	pqbg_t( 'settings restored to the exact stored value', $original === $raw_setting() );
	pqbg_t( 'sales and codes tables back to their starting row counts', $base_s === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $S" ) && $base_c === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" ) );
	pqbg_t( 'no posts left above the starting post ID', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID > %d", $start_post ) ) );
	pqbg_t( 'temporary users removed', $base_user === (int) count_users()['total_users'] );
}

pqbg_test_done();
