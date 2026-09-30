<?php
/**
 * Phase 17 suite: basket (several different items in one sale).
 *
 * Checks the schema (version 5, basket_id and its index, old rows untouched), the
 * database version gate (D16), the open basket in user meta (add, same item again,
 * the stock cap per stock holder, 30 lines, the stale-revision refusal, expiry, clear,
 * a new request ID after a change), confirm all or nothing (one sale, every line
 * completed in one statement, the stock of each holder lowered, two variations sharing
 * their parent's stock; a price change or too little stock refuses with the line named
 * and writes nothing; an online order during confirm rolls the whole basket back with
 * the failing line named; a repeated request returns the same sale), crash recovery of
 * held lines by the next single sale and by outcome(), undo of the whole basket (own,
 * 10 minutes, once), the manager's void (with and without restock), basket lines refused
 * by the single-sale undo and void, the receipt (one per basket, its number, every line,
 * a line's receipt address answering 303), report counts (sales = transactions, amounts
 * reconcile), the CSV's last column, the scan routes (/scan/basket/, the sold basket page,
 * "Add to basket" and the scan box), the new Health check items, and scope rules
 * (SaleRequest and StockLock unchanged since 1.0.1, no script, no new hooks).
 *
 * In-process only (no HTTP). Creates users, three products (one variable with two
 * variations on parent-level stock) with their codes, sale rows and basket user meta,
 * and removes them all. Temporarily changes pqbg_settings and restores the exact value.
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

use ProductQrBarcode\{BasketRequest, BasketService, BasketStore, CodeRepository, HealthCheck, Install, PaymentMethods, Permissions, Plugin, Receipt, ReportsQuery, SaleRepository, SaleService, SalesExport, SalesQuery, ScanRoute, ScanScreen, ScanUrl, Schema};

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
$base_meta   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s)", BasketStore::META, BasketStore::META_AT ) );
$old_rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $S WHERE id <= %d ORDER BY id", $start_sale ), ARRAY_A );
$as_mark     = pqbg_test_as_mark(); // Action Scheduler cleanup, see bootstrap.php.
$cl_mark     = pqbg_test_catlookup_mark(); // Category lookup rows, see bootstrap.php.
$users       = array();
$inr         = static fn() => 'INR';

$set  = static function ( array $values ) use ( $option ): void {
	update_option( $option, array_merge( Plugin::default_settings(), $values ), false );
	wp_cache_delete( $option, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
};
$user = static function ( string $login, string $role ) use ( &$users ): int {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $login . '@example.invalid', 'role' => $role, 'display_name' => 'Basket ' . $login ) );
	$users[] = is_int( $id ) ? $id : 0;
	return is_int( $id ) ? $id : 0;
};
$path_of  = static fn( string $url ) => (string) wp_parse_url( $url, PHP_URL_PATH );
$query_of = static fn( string $url ) => (string) wp_parse_url( $url, PHP_URL_QUERY );
$stock    = static function ( int $id ): int {
	return (int) SaleRepository::read_stock( $id );
};
$put_stock = static function ( int $id, int $qty ) use ( $wpdb ): void {
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = %d WHERE post_id = %d AND meta_key = '_stock'", $qty, $id ) );
	clean_post_cache( $id );
	wp_cache_delete( $id, 'post_meta' );
};
$rows_of  = static fn( int $basket ) => SaleRepository::basket_lines( $basket );
$count_s  = static fn() => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $S" );
$lines_of = static fn( array $spec ) => array_map( static fn( $l ) => array( 'code' => $l[0], 'quantity' => $l[1], 'price' => $l[2] ), $spec );
$confirm  = static fn( int $as, array $spec, string $method = 'cash', ?string $req = null ) => BasketService::confirm(
	array(
		'seller_id'      => $as,
		'request_id'     => $req ?? wp_generate_uuid4(),
		'payment_method' => $method,
		'lines'          => $lines_of( $spec ),
	)
);

try {
	add_filter( 'woocommerce_currency', $inr, 99 );
	$set( array() );

	pqbg_section( 'schema and database support' );
	$cols = $wpdb->get_col( "SHOW COLUMNS FROM $S" );
	$keys = array_column( (array) $wpdb->get_results( "SHOW INDEX FROM $S", ARRAY_A ), 'Key_name' );
	pqbg_t( 'DB_VERSION is 5 and the stored version matches', 5 === Install::DB_VERSION && 5 === Install::stored_version() );
	pqbg_t( 'pqbg_sales has basket_id and the basket_status index', in_array( 'basket_id', $cols, true ) && in_array( 'basket_status', $keys, true ) );
	pqbg_t( 'basket_id is NULL by default (a single sale; the migration wrote no row)', null === ( $wpdb->get_row( "SHOW COLUMNS FROM $S LIKE 'basket_id'", ARRAY_A )['Default'] ?? null ) && 'YES' === ( $wpdb->get_row( "SHOW COLUMNS FROM $S LIKE 'basket_id'", ARRAY_A )['Null'] ?? '' ) );
	pqbg_t( 'migrate_5 re-run changes nothing', true === Install::migrate_5() && $old_rows === $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $S WHERE id <= %d ORDER BY id", $start_sale ), ARRAY_A ) );
	foreach ( array( '10.4.32-MariaDB' => true, '5.5.5-10.4.32-MariaDB' => true, '10.0.1-MariaDB' => false, '10.0.2-MariaDB-log' => true, '8.0.36' => true, '5.7.5' => true, '5.7.4-log' => false, '5.6.51' => false, 'garbage' => false ) as $v => $want ) {
		pqbg_t( "version gate: {$v} → " . ( $want ? 'supported' : 'refused' ), $want === BasketService::version_supported( $v ) );
	}
	pqbg_t( 'this server supports baskets', BasketService::db_supported() );

	pqbg_section( 'fixtures' );
	$admin   = $user( 'pqbg_p17_admin', 'administrator' );
	$manager = $user( 'pqbg_p17_manager', 'shop_manager' );
	$seller  = $user( 'pqbg_p17_seller', Permissions::SELLER_ROLE );
	$other   = $user( 'pqbg_p17_other', Permissions::SELLER_ROLE );
	$buyer   = $user( 'pqbg_p17_customer', 'customer' );
	wp_set_current_user( $admin );
	$mk = static function ( string $name, string $price, int $qty ): int {
		$p = new WC_Product_Simple();
		$p->set_name( 'PQBG P17 ' . $name . ' ' . wp_generate_password( 5, false ) );
		$p->set_status( 'publish' );
		$p->set_regular_price( $price );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $qty );
		return (int) $p->save();
	};
	$pa   = $mk( 'saree', '1499', 10 );
	$pb   = $mk( 'dupatta', '350', 3 );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( array( 'S', 'M' ) );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$vp = new WC_Product_Variable();
	$vp->set_name( 'PQBG P17 kurti ' . wp_generate_password( 5, false ) );
	$vp->set_status( 'publish' );
	$vp->set_attributes( array( $attr ) );
	$vp->set_manage_stock( true );
	$vp->set_stock_quantity( 4 );
	$pv   = (int) $vp->save();
	$vars = array();
	foreach ( array( 'S', 'M' ) as $size ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $pv );
		$v->set_attributes( array( 'size' => $size ) );
		$v->set_regular_price( '500' );
		$v->set_status( 'publish' );
		$v->set_manage_stock( false );
		$vars[ $size ] = (int) $v->save();
	}
	wp_set_current_user( 0 );
	$code = static fn( int $id ) => (string) ( CodeRepository::find_active_for_product( $id )['code'] ?? '' );
	$ca   = $code( $pa );
	$cb   = $code( $pb );
	$cs   = $code( $vars['S'] );
	$cm   = $code( $vars['M'] );
	pqbg_t( 'fixtures: three items and two variations with codes', '' !== $ca && '' !== $cb && '' !== $cs && '' !== $cm );

	pqbg_section( 'open basket (BasketStore)' );
	$cap = static fn( int $max ) => static fn( array $lines ) => $max;
	$b0  = BasketStore::get( $seller );
	pqbg_t( 'a new seller has an empty basket', array() === $b0['lines'] && 0 === $b0['rev'] );
	$r1 = BasketStore::add( $seller, $ca, 1, '1499.00', $cap( 10 ) );
	$r2 = BasketStore::add( $seller, $ca, 2, '1499.00', $cap( 10 ) );
	$b1 = BasketStore::get( $seller );
	pqbg_t( 'the same item again raises its quantity (one line, 3)', ! is_wp_error( $r2 ) && 3 === $r2['quantity'] && 1 === count( $b1['lines'] ) && 3 === $b1['lines'][0]['quantity'] );
	pqbg_t( 'every change sets a new revision and request ID', $r1['rev'] + 1 === $r2['rev'] && $r1['request_id'] !== $r2['request_id'] && $b1['request_id'] === $r2['request_id'] && wp_is_uuid( $b1['request_id'], 4 ) );
	$r3 = BasketStore::add( $seller, $ca, 8, '1499.00', $cap( 10 ) );
	pqbg_t( 'above the stock cap: refused, "Only 10 in stock", nothing changed', is_wp_error( $r3 ) && str_contains( $r3->get_error_message(), '10' ) && 3 === BasketStore::get( $seller )['lines'][0]['quantity'] );
	$r4 = BasketStore::set_quantity( $seller, $b1['rev'] - 1, $ca, 2, $cap( 10 ) );
	pqbg_t( 'a stale revision is refused ("changed on another screen")', is_wp_error( $r4 ) && 'pqbg_basket_changed' === $r4->get_error_code() );
	$r5 = BasketStore::set_quantity( $seller, $b1['rev'], $ca, 2, $cap( 10 ) );
	pqbg_t( 'quantity changed with the current revision', ! is_wp_error( $r5 ) && 2 === BasketStore::get( $seller )['lines'][0]['quantity'] );
	pqbg_t( 'expired after 2 hours without a change (reads as empty, nothing written)', array() === BasketStore::get( $seller, time() + BasketStore::TTL + 1 )['lines'] && 1 === count( BasketStore::get( $seller )['lines'] ) );
	$many = array();
	for ( $i = 0; $i < BasketStore::MAX_LINES + 2; $i++ ) {
		$many[] = array( 'code' => 'DC-' . strtoupper( substr( str_replace( array( '0', '1', 'O', 'I', 'L', 'U' ), 'X', md5( (string) $i ) ), 0, 4 ) ) . '-AAAA-BBBB', 'quantity' => 1, 'price' => '1', 'added' => 1 );
	}
	update_user_meta( $other, BasketStore::META, array( 'rev' => 1, 'request_id' => wp_generate_uuid4(), 'updated' => time(), 'lines' => $many ) );
	pqbg_t( 'a stored basket is read with at most 30 lines', BasketStore::MAX_LINES === count( BasketStore::get( $other )['lines'] ) );
	$full = BasketStore::add( $other, $ca, 1, '1499.00', $cap( 10 ) );
	pqbg_t( 'the 31st different item is refused', is_wp_error( $full ) && 'pqbg_basket_full' === $full->get_error_code() );
	$cl = BasketStore::clear( $other, BasketStore::get( $other )['rev'] );
	pqbg_t( 'clear empties the basket (the revision survives)', ! is_wp_error( $cl ) && array() === BasketStore::get( $other )['lines'] && $cl['rev'] === BasketStore::get( $other )['rev'] );
	$re = BasketStore::renew( $seller );
	pqbg_t( 'renew: same lines, new request ID', ! is_wp_error( $re ) && $re['request_id'] !== $b1['request_id'] && 1 === count( BasketStore::get( $seller )['lines'] ) );
	BasketStore::clear( $seller, BasketStore::get( $seller )['rev'] );

	pqbg_section( 'confirm: all or nothing' );
	$sa0 = $stock( $pa );
	$sb0 = $stock( $pb );
	$sv0 = $stock( $pv );
	$n0  = $count_s();
	$bad = $confirm( $seller, array( array( $ca, 2, '1499' ), array( $cb, 1, '349' ) ) );
	pqbg_t( 'a price change on line 2: refused, line 2 named, nothing written', is_wp_error( $bad ) && 'pqbg_price_changed' === $bad->get_error_code() && 1 === $bad->get_error_data()['line'] && $n0 === $count_s() && $sa0 === $stock( $pa ) );
	$bad = $confirm( $seller, array( array( $ca, 1, '1499' ), array( $cs, 3, '500' ), array( $cm, 2, '500' ) ) );
	pqbg_t( 'two variations on one parent stock asking 5 of 4: refused (the first line of that stock, line 2, named), nothing written', is_wp_error( $bad ) && 'pqbg_insufficient_stock' === $bad->get_error_code() && 1 === $bad->get_error_data()['line'] && $n0 === $count_s() && $sv0 === $stock( $pv ) );
	$req = wp_generate_uuid4();
	$ok  = $confirm( $seller, array( array( $ca, 2, '1499' ), array( $cs, 1, '500' ), array( $cm, 2, '500' ) ), 'upi', $req );
	$K   = is_array( $ok ) ? (int) $ok['basket_id'] : 0;
	$L   = $rows_of( $K );
	pqbg_t( 'a basket of three lines sells as one sale', is_array( $ok ) && SaleRepository::STATUS_COMPLETED === $ok['status'] && 3 === count( $L ) && array( 'completed' ) === array_values( array_unique( array_column( $L, 'status' ) ) ) );
	pqbg_t( 'every line carries the basket number (the first line\'s ID), the method and the seller', $K === (int) $L[0]['id'] && array( (string) $K ) === array_values( array_unique( array_column( $L, 'basket_id' ) ) ) && array( 'upi' ) === array_values( array_unique( array_column( $L, 'payment_method' ) ) ) && $req === $L[0]['request_id'] );
	pqbg_t( 'stock lowered per holder (saree −2, the kurti parent −3)', $sa0 - 2 === $stock( $pa ) && $sv0 - 3 === $stock( $pv ) );
	pqbg_t( 'stock snapshots recorded on every line', ! array_filter( $L, static fn( $l ) => null === $l['stock_after'] ) );
	$again = $confirm( $seller, array( array( $ca, 2, '1499' ) ), 'cash', $req );
	pqbg_t( 'the same request again (double tap): the same sale, no new row', is_array( $again ) && $K === $again['basket_id'] && SaleRepository::STATUS_COMPLETED === $again['status'] && $n0 + 3 === $count_s() );
	$stolen = $confirm( $other, array( array( $ca, 1, '1499' ) ), 'cash', $req );
	pqbg_t( "another seller replaying the request ID: refused", is_wp_error( $stolen ) && 'pqbg_bad_request' === $stolen->get_error_code() );

	pqbg_section( 'confirm: an online order wins (D13)' );
	$put_stock( $pb, 3 );
	$sa1   = $stock( $pa );
	$fired = false;
	$race  = static function ( $product ) use ( &$fired, $pb, $wpdb ) {
		if ( ! $fired && $product instanceof WC_Product && $product->get_id() === $pb ) {
			$fired = true;
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value - 3 WHERE post_id = %d AND meta_key = '_stock'", $pb ) );
		}
	};
	add_action( 'woocommerce_product_set_stock', $race );
	$n1   = $count_s();
	$lost = $confirm( $seller, array( array( $ca, 1, '1499' ), array( $cb, 2, '350' ) ) );
	remove_action( 'woocommerce_product_set_stock', $race );
	$LL = is_array( $lost ) ? $rows_of( (int) $lost['basket_id'] ) : array();
	pqbg_t( 'the basket failed with line 2 named and "sold_online"', is_array( $lost ) && SaleRepository::STATUS_FAILED === $lost['status'] && 1 === $lost['line'] && SaleRepository::FAILURE_SOLD_ONLINE === $lost['failure'] );
	pqbg_t( 'no line is a sale: line 1 basket_rollback, line 2 sold_online (both kept in the journal)', 2 === count( $LL ) && array( 'failed' ) === array_values( array_unique( array_column( $LL, 'status' ) ) ) && SaleRepository::FAILURE_BASKET === $LL[0]['failure_code'] && SaleRepository::FAILURE_SOLD_ONLINE === $LL[1]['failure_code'] && $n1 + 2 === $count_s() );
	pqbg_t( 'stock: the saree is back; the dupatta shows only the online order', $sa1 === $stock( $pa ) && 0 === $stock( $pb ) );
	$put_stock( $pb, 3 );

	pqbg_section( 'crash recovery (held lines)' );
	$sa2  = $stock( $pa );
	$dead = SaleRepository::insert_pending( array( 'request_id' => wp_generate_uuid4(), 'code_id' => (int) CodeRepository::find_by_code( $ca )['id'], 'product_id' => $pa, 'seller_id' => $seller, 'quantity' => 2, 'unit_price' => '1499', 'line_total' => '2998', 'currency' => 'INR', 'product_name' => 'P17 dead basket', 'stock_holder_id' => $pa, 'payment_method' => 'cash' ) );
	SaleRepository::set_basket_id( (int) $dead );
	SaleService::change_stock( wc_get_product( $pa ), 2, 'decrease', (int) $dead, SaleRepository::STATUS_PENDING, array( 'status' => SaleRepository::STATUS_HELD ) );
	pqbg_t( 'simulated crash: a held line, stock lowered by 2, not a sale', SaleRepository::STATUS_HELD === SaleRepository::find( (int) $dead )['status'] && $sa2 - 2 === $stock( $pa ) );
	$health = HealthCheck::run( true, time() + HealthCheck::STALE_PENDING + 60 );
	pqbg_t( 'Health check (15 minutes later): the held line is listed', $health['stale_held']['count'] >= 1 && in_array( (int) $dead, array_column( $health['stale_held']['rows'], 'sale' ), true ) );
	pqbg_t( 'reports do not count a held line', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $S s WHERE s.id = %d AND s.status = 'completed'", (int) $dead ) ) );
	$single = SaleService::sell( array( 'code' => $ca, 'quantity' => 1, 'request_id' => wp_generate_uuid4(), 'seller_id' => $other, 'payment_method' => 'cash' ) );
	$dr     = SaleRepository::find( (int) $dead );
	pqbg_t( 'the next single sale of the item recovers it: failed "interrupted", stock put back', is_array( $single ) && SaleRepository::STATUS_COMPLETED === $single['status'] && SaleRepository::STATUS_FAILED === $dr['status'] && SaleRepository::FAILURE_INTERRUPTED === $dr['failure_code'] && $sa2 - 1 === $stock( $pa ) );
	$out = BasketService::outcome( $dr, $seller );
	pqbg_t( 'outcome() of the dead basket: failed', is_array( $out ) && SaleRepository::STATUS_FAILED === $out['status'] );

	pqbg_section( 'single-sale undo and void refuse basket lines' );
	$u = SaleService::undo( (int) $L[1]['id'], $seller );
	$v = SaleService::void_sale( (int) $L[1]['id'], $admin, 'P17', true );
	pqbg_t( 'SaleService::undo() refuses a basket line', is_wp_error( $u ) && 'pqbg_in_basket' === $u->get_error_code() );
	pqbg_t( 'SaleService::void_sale() refuses a basket line', is_wp_error( $v ) && 'pqbg_in_basket' === $v->get_error_code() );
	pqbg_t( 'the basket line has no single-sale Undo button', ! SaleService::can_undo( SaleRepository::find( (int) $L[1]['id'] ), $seller ) );

	pqbg_section( 'receipt' );
	$data = Receipt::data( SaleRepository::find( (int) $L[2]['id'] ) );
	pqbg_t( 'one receipt for the basket: its number, all three lines, the total (2 × 1,499 + 500 + 2 × 500)', (string) $K === $data['number'] && 3 === count( $data['lines'] ) && ( str_contains( $data['total'], '4,498' ) || str_contains( $data['total'], '4498' ) ) );
	pqbg_t( 'the UPI reference is the basket\'s', str_contains( wp_json_encode( $data ), \ProductQrBarcode\UpiPayment::reference( $req ) ) );
	pqbg_t( 'no cost on the receipt', ! array_key_exists( 'unit_cost', $data ) );
	wp_set_current_user( $seller );
	$r = ScanRoute::decide( 'GET', $path_of( ScanUrl::receipt_url( (int) $L[2]['id'] ) ), ScanUrl::RECEIPT . '/' . $L[2]['id'], null );
	pqbg_t( 'a line\'s receipt address answers 303 to the basket\'s receipt', 303 === $r['status'] && ScanUrl::receipt_url( $K ) === $r['location'] );
	$r = ScanRoute::decide( 'GET', $path_of( ScanUrl::receipt_url( $K ) ), ScanUrl::RECEIPT . '/' . $K, null );
	pqbg_t( 'the basket\'s receipt: 200', 200 === $r['status'] );
	wp_set_current_user( $other );
	$r = ScanRoute::decide( 'GET', $path_of( ScanUrl::receipt_url( (int) $L[2]['id'] ) ), ScanUrl::RECEIPT . '/' . $L[2]['id'], null );
	pqbg_t( "another seller: the same 404 (no redirect that would reveal the basket)", 404 === $r['status'] );
	wp_set_current_user( 0 );
	$long = array_merge( $data, array( 'lines' => array_fill( 0, 40, array( 'name' => str_repeat( 'Silk saree ', 5 ), 'attributes' => '', 'quantity' => '1', 'unit' => '₹1,499.00', 'total' => '₹1,499.00', 'void' => false ) ) ) );
	$text = Receipt::text( $long );
	pqbg_t( 'WhatsApp text of a long basket: within the limit, "…and N more items", the total kept', mb_strlen( $text ) <= Receipt::TEXT_MAX && str_contains( $text, 'more items' ) && str_contains( $text, 'Total:' ) );

	pqbg_section( 'reports: sales are transactions, amounts reconcile' );
	$f      = SalesQuery::filters( array( 'range' => 'today', 'seller' => (string) $seller ) );
	$totals = SalesQuery::totals( $f );
	$rev    = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(line_total) FROM $S WHERE seller_id = %d AND status = 'completed'", $seller ) );
	$items  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(quantity) FROM $S WHERE seller_id = %d AND status = 'completed'", $seller ) );
	pqbg_t( 'sales history totals: 1 sale, 3 lines, 5 items; revenue = sum of the lines', 1 === $totals['all']['count'] && 3 === $totals['all']['lines'] && 5 === $totals['all']['items'] && abs( (float) $totals['all']['revenue'] - $rev ) < 0.005 && 5 === $items );
	$sum = ReportsQuery::summary( $f, true );
	pqbg_t( 'reports summary: 1 sale, 5 items, the same revenue; average = revenue ÷ 1', 1 === $sum['all']['count'] && 5 === $sum['all']['items'] && abs( (float) $sum['all']['revenue'] - $rev ) < 0.005 && abs( (float) $sum['all']['average'] - $rev ) < 0.005 );
	$eod = ReportsQuery::end_of_day( $f );
	pqbg_t( 'end of day: 1 UPI sale for the seller, the net equals the revenue', 1 === $eod['methods']['upi']['sales'] && abs( (float) $eod['methods']['upi']['net'] - $rev ) < 0.005 );
	$prod = ReportsQuery::products( $f, false );
	pqbg_t( 'per item: the sale counts once for each item it contains', 3 === count( $prod ) && 3 === array_sum( array_column( $prod, 'count' ) ) );
	$cats = ReportsQuery::categories( $f, false );
	pqbg_t( 'categories total counts the basket once', 1 === $cats['total']['count'] );
	$cells = SalesExport::line( SalesQuery::find( (int) $L[1]['id'] ), false );
	pqbg_t( 'CSV: the receipt number is the last column, the other columns unchanged in place', (string) $K === end( $cells ) && count( SalesExport::header( false ) ) === count( $cells ) && (string) $L[1]['id'] === $cells[1] );
	$byno = SalesQuery::count( array_merge( SalesQuery::filters( array( 'range' => 'today' ) ), array( 'sale_no' => $K ) ) );
	pqbg_t( 'the sales history filter by receipt number shows the basket\'s lines', 3 === $byno );
	wp_set_current_user( $seller );
	$mine = ScanScreen::my_sales( $seller, 'today' );
	wp_set_current_user( 0 );
	pqbg_t( 'My sales: one entry for the basket, its three lines under it, one receipt link', 1 === count( $mine['mine']['lines'] ) && 3 === count( $mine['mine']['lines'][0]['parts'] ) && ScanUrl::receipt_url( $K ) === $mine['mine']['lines'][0]['receipt'] );

	pqbg_section( 'scan routes' );
	$basket_path = $path_of( ScanUrl::basket_url() );
	wp_set_current_user( 0 );
	$r = ScanRoute::decide( 'GET', $basket_path, ScanUrl::BASKET, null );
	pqbg_t( 'logged out: 302 to the login page, back to the basket', 302 === $r['status'] && str_contains( rawurldecode( $r['location'] ), '/scan/basket/' ) );
	wp_set_current_user( $buyer );
	pqbg_t( 'customer: 403', 403 === ScanRoute::decide( 'GET', $basket_path, ScanUrl::BASKET, null )['status'] );
	wp_set_current_user( $seller );
	$r = ScanRoute::decide( 'GET', $basket_path, ScanUrl::BASKET, null );
	pqbg_t( 'seller: the basket page, no code box but a "Scan to add" box', 200 === $r['status'] && is_array( $r['view']['basket'] ) && false === $r['view']['box'] && true === $r['view']['basket']['scan'] );
	pqbg_t( 'a query string other than added= or msg= → 301 to /scan/basket/', 301 === ScanRoute::decide( 'GET', $basket_path . '?x=1', ScanUrl::BASKET, null )['status'] );
	pqbg_t( 'PUT → 405, Allow GET, HEAD, POST', 'GET, HEAD, POST' === ( ScanRoute::decide( 'PUT', $basket_path, ScanUrl::BASKET, null )['headers']['Allow'] ?? '' ) );
	$scan = static fn( string $c ) => ScanRoute::decide( 'POST', $basket_path, ScanUrl::BASKET, null, array( 'pqbg_action' => 'basket_scan', '_pqbg_nonce' => wp_create_nonce( Permissions::nonce_action( 'basket_scan' ) ), 'code' => $c ) );
	$r1 = $scan( $cb );
	$r2 = $scan( ScanUrl::for_code( $cb ) );
	$bk = BasketStore::get( $seller );
	pqbg_t( 'scan box: two scans of one item (code, then a pasted scan URL) → 303 and quantity 2', 303 === $r1['status'] && 303 === $r2['status'] && 1 === count( $bk['lines'] ) && 2 === $bk['lines'][0]['quantity'] );
	pqbg_t( 'scan box: a bad nonce → 403, nothing added', 403 === ScanRoute::decide( 'POST', $basket_path, ScanUrl::BASKET, null, array( 'pqbg_action' => 'basket_scan', '_pqbg_nonce' => 'x', 'code' => $cb ) )['status'] && 2 === BasketStore::get( $seller )['lines'][0]['quantity'] );
	$view = ScanScreen::resolve( $ca );
	pqbg_t( 'product screen with a basket open: "Add to basket" only, with the basket bar', is_array( $view['sell'] ) && true === $view['sell']['basket']['open'] && str_contains( $view['sell']['basket']['text'], '2' ) );
	$html = ScanScreen::render( $view );
	pqbg_t( '…the page offers no "Confirm sale" and no payment choice', ! str_contains( $html, 'name="payment_method"' ) && str_contains( $html, 'value="basket_add"' ) );
	$add = ScanRoute::decide( 'POST', $path_of( ScanUrl::site_url( $ca ) ), $ca, null, array_merge( $view['sell']['fields'], array( 'pqbg_action' => 'basket_add', '_pqbg_nonce' => $view['sell']['nonce'], 'quantity' => '1' ) ) );
	pqbg_t( '"Add to basket" → 303 to /scan/basket/?added=…', 303 === $add['status'] && str_contains( $add['location'], 'added=' ) && 2 === count( BasketStore::get( $seller )['lines'] ) );
	$page = ScanScreen::basket( $seller );
	pqbg_t( 'the basket screen has a confirm form with a signed token for both lines', is_array( $page['basket']['confirm'] ) && str_contains( $page['basket']['confirm']['fields']['lines'], $ca ) && str_contains( $page['basket']['confirm']['fields']['lines'], $cb ) );
	$post = array_merge( $page['basket']['confirm']['fields'], array( 'pqbg_action' => 'basket_confirm', '_pqbg_nonce' => $page['basket']['confirm']['nonce'], 'payment_method' => 'cash' ) );
	$tamper = array_merge( $post, array( 'lines' => str_replace( ':2:', ':1:', $post['lines'] ) ) );
	pqbg_t( 'a tampered confirm token → 400, nothing sold', 400 === ScanRoute::decide( 'POST', $basket_path, ScanUrl::BASKET, null, $tamper )['status'] );
	$n2   = $count_s();
	$sold = ScanRoute::decide( 'POST', $basket_path, ScanUrl::BASKET, null, $post );
	$K2   = (int) basename( untrailingslashit( (string) ( $sold['location'] ?? '' ) ) );
	pqbg_t( 'confirm over the route: 303 to /scan/basket/{id}/, two lines written, the basket emptied', 303 === $sold['status'] && str_contains( $sold['location'], '/scan/basket/' ) && $n2 + 2 === $count_s() && array() === BasketStore::get( $seller )['lines'] );
	$twice = ScanRoute::decide( 'POST', $basket_path, ScanUrl::BASKET, null, $post );
	pqbg_t( 'the same confirm posted again: the same 303, no new row', 303 === $twice['status'] && $sold['location'] === $twice['location'] && $n2 + 2 === $count_s() );
	$page = ScanRoute::decide( 'GET', $path_of( ScanUrl::basket_sale_url( $K2 ) ), ScanUrl::BASKET . '/' . $K2, null );
	pqbg_t( 'the sold basket\'s page: 200, its lines, an Undo form', 200 === $page['status'] && 2 === count( $page['view']['basket_sale']['lines'] ) && is_array( $page['view']['undo'] ) );
	$line_page = ScanRoute::decide( 'GET', $path_of( ScanUrl::site_url( $cb ) ) . '?sale=' . $rows_of( $K2 )[1]['id'], $cb, null );
	pqbg_t( 'a basket line\'s sale page answers 303 to its basket\'s page', 303 === $line_page['status'] && ScanUrl::basket_sale_url( $K2 ) === $line_page['location'] );
	wp_set_current_user( $other );
	pqbg_t( "another seller: the sold basket's page is a 303 to the entry page", 303 === ScanRoute::decide( 'GET', $path_of( ScanUrl::basket_sale_url( $K2 ) ), ScanUrl::BASKET . '/' . $K2, null )['status'] );
	wp_set_current_user( 0 );

	pqbg_section( 'undo and void of a whole basket' );
	$sb1 = $stock( $pb );
	$u1  = BasketService::undo( $K2, $other );
	pqbg_t( "undo: another seller's basket refused", is_wp_error( $u1 ) && 'pqbg_not_own_sale' === $u1->get_error_code() );
	$u2 = BasketService::undo( $K2, $seller );
	pqbg_t( 'undo: every line voided ("undo"), every stock put back', is_array( $u2 ) && array( 'voided' ) === array_values( array_unique( array_column( $rows_of( $K2 ), 'status' ) ) ) && array( 'undo' ) === array_values( array_unique( array_column( $rows_of( $K2 ), 'void_reason' ) ) ) && $sb1 + 2 === $stock( $pb ) );
	$u3 = BasketService::undo( $K2, $seller );
	pqbg_t( 'undo again: "already undone"', is_wp_error( $u3 ) && 'pqbg_already_undone' === $u3->get_error_code() );
	$wpdb->query( $wpdb->prepare( "UPDATE $S SET created_at_gmt = %s WHERE basket_id = %d", gmdate( 'Y-m-d H:i:s', time() - SaleService::UNDO_WINDOW - 5 ), $K ) );
	$u4 = BasketService::undo( $K, $seller );
	pqbg_t( 'undo after 10 minutes: refused', is_wp_error( $u4 ) && 'pqbg_undo_expired' === $u4->get_error_code() );
	$sa3 = $stock( $pa );
	$v1  = BasketService::void( $K, $seller, 'P17', true );
	pqbg_t( 'void by a seller (no pqbg_void_sale): refused', is_wp_error( $v1 ) && 'pqbg_forbidden' === $v1->get_error_code() );
	$v2 = BasketService::void( $K, $manager, 'P17 no restock', false );
	pqbg_t( 'manager void without restock: every line voided in one statement, stock unchanged', is_array( $v2 ) && array( 'voided' ) === array_values( array_unique( array_column( $rows_of( $K ), 'status' ) ) ) && array( '0' ) === array_values( array_unique( array_column( $rows_of( $K ), 'void_restock' ) ) ) && $sa3 === $stock( $pa ) );
	$v3 = BasketService::void( $K, $manager, 'again', true );
	pqbg_t( 'void again: "only a completed sale can be voided"', is_wp_error( $v3 ) && 'pqbg_not_voidable' === $v3->get_error_code() );

	pqbg_section( 'Health check' );
	$health = HealthCheck::run( true );
	pqbg_t( 'basket_db: no error on this server', 0 === $health['basket_db']['count'] && HealthCheck::ERROR === $health['basket_db']['severity'] );
	pqbg_t( 'partly_voided: none (every basket voided as a whole)', 0 === $health['partly_voided']['count'] );
	BasketStore::add( $other, $ca, 1, '1499.00', $cap( 10 ) );
	$health = HealthCheck::run( true );
	pqbg_t( 'open_baskets: at least the one just opened (information)', $health['open_baskets']['count'] >= 1 && HealthCheck::INFO === $health['open_baskets']['severity'] );

	pqbg_section( 'scope' );
	$src_code = static fn( string $f ) => implode( '', array_map( static fn( $t ) => is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t, token_get_all( (string) file_get_contents( PQBG_PLUGIN_DIR . $f ) ) ) );
	$dir      = dirname( __DIR__ );
	foreach ( array( 'SaleRequest', 'StockLock' ) as $f ) {
		$tagged = (string) shell_exec( 'git -C ' . escapeshellarg( $dir ) . ' show pqbg-v1.0.1:./includes/' . $f . '.php 2>&1' );
		pqbg_t( "{$f} is byte-identical to 1.0.1", '' !== $tagged && str_replace( "\r\n", "\n", $tagged ) === str_replace( "\r\n", "\n", (string) file_get_contents( PQBG_PLUGIN_DIR . 'includes/' . $f . '.php' ) ) );
	}
	$new = $src_code( 'includes/BasketStore.php' ) . $src_code( 'includes/BasketService.php' ) . $src_code( 'includes/BasketRequest.php' );
	pqbg_t( 'the basket classes add no hook, handler, REST/AJAX route, shortcode or rewrite rule', ! preg_match( '/add_action\(|add_filter\(|admin_post_|wp_ajax_|register_rest_route|add_shortcode|add_rewrite/', $new ) );
	pqbg_t( 'the basket classes never start a transaction', ! preg_match( '/START TRANSACTION|COMMIT|ROLLBACK|BEGIN/', $new ) );
	pqbg_t( 'BasketService changes stock only through SaleService', ! preg_match( '/wc_update_product_stock|set_stock_quantity|stock_sql\(/', $src_code( 'includes/BasketService.php' ) ) );
	pqbg_t( 'BasketStore writes only its own two user meta keys', 1 === substr_count( $src_code( 'includes/BasketStore.php' ), 'update_user_meta( $user_id, self::META, ' ) && 1 === substr_count( $src_code( 'includes/BasketStore.php' ), 'update_user_meta( $user_id, self::META_AT, ' ) && ! preg_match( '/update_option|->insert\(|->update\(|->delete\(|\b(INSERT|UPDATE|DELETE)\b/', $src_code( 'includes/BasketStore.php' ) ) );
	pqbg_t( 'the scan template and the UPI partial have no script', ! str_contains( (string) file_get_contents( PQBG_PLUGIN_DIR . 'templates/pqbg-scan.php' ), '<script' ) && ! str_contains( (string) file_get_contents( PQBG_PLUGIN_DIR . 'templates/pqbg-scan-upi.php' ), '<script' ) );
	pqbg_t( 'the rewrite rules are unchanged (RULES_VERSION 1)', '1' === ScanRoute::RULES_VERSION );
	pqbg_t( 'uninstall.php removes the open baskets', str_contains( (string) file_get_contents( PQBG_PLUGIN_DIR . 'uninstall.php' ), "'pqbg_basket'" ) && str_contains( (string) file_get_contents( PQBG_PLUGIN_DIR . 'uninstall.php' ), "'pqbg_basket_at'" ) );
} catch ( Throwable $e ) {
	pqbg_t( 'no exception: ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine(), false );
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
	pqbg_t( 'sales and codes tables back to their starting row counts; earlier sales untouched', $base_s === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $S" ) && $base_c === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" ) && $old_rows === $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $S WHERE id <= %d ORDER BY id", $start_sale ), ARRAY_A ) );
	pqbg_t( 'no posts left above the starting post ID', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID > %d", $start_post ) ) );
	pqbg_t( 'temporary users (and their baskets) removed', $base_user === (int) count_users()['total_users'] && $base_meta === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s)", BasketStore::META, BasketStore::META_AT ) ) );
}

pqbg_test_done();
