<?php
/**
 * Phase 2 main suite (foundation and data layer), ported to the current names.
 *
 * Changes from the original Phase 2 script, all intentional:
 *   - identifiers renamed (pqbg_, PQBG_, ProductQrBarcode, "Store Seller")
 *   - "settings merge drops unknown keys" compares with Plugin::default_settings(),
 *     because Phase 4 added keys; the stored option is restored exactly afterwards
 *   - "unrelated role capabilities untouched" used a pre-activation snapshot file;
 *     it now snapshots the roles, runs install()/sync_roles() with a canary
 *     capability on each role, and compares everything except pqbg_* capabilities
 *   - row checks count only this suite's TEST-PQBG-* rows, so existing codes do not break it
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/bootstrap.php';
pqbg_test_load_wp();

use ProductQrBarcode\{Install, Schema, Permissions, CodeRepository, Plugin, Requirements};

global $wpdb;

$C = Schema::codes_table();
$S = Schema::sales_table();

$test_rows = static fn() => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C WHERE code LIKE 'TEST-PQBG-%'" );
$base_c    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" );
$base_s    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $S" );

pqbg_section( 'bootstrap' );
pqbg_t( 'constants defined', defined( 'PQBG_VERSION' ) && defined( 'PQBG_PLUGIN_FILE' ) && defined( 'PQBG_PLUGIN_DIR' ) && defined( 'PQBG_PLUGIN_URL' ), PQBG_VERSION );
pqbg_t( 'autoload classes', class_exists( Install::class ) && class_exists( Schema::class ) && class_exists( Permissions::class ) && class_exists( CodeRepository::class ) );
pqbg_t( 'autoloader rejects traversal', ! class_exists( 'ProductQrBarcode\\..\\..\\wp-config' ) );
pqbg_t( 'requirements met', Requirements::met(), json_encode( Requirements::errors() ) );
pqbg_t( 'DB_VERSION == max migration key', Install::DB_VERSION === max( array_keys( Install::migrations() ) ) );

pqbg_section( 'schema' );
pqbg_t( 'tables use prefix', $C === $wpdb->prefix . 'pqbg_codes' && $S === $wpdb->prefix . 'pqbg_sales', "$C, $S" );
pqbg_t( 'tables exist', Schema::tables_exist() );
pqbg_t( 'no leftover test rows at start', 0 === $test_rows() && 0 == $wpdb->get_var( "SELECT COUNT(*) FROM $S WHERE note = 'PQBG_PHASE2_TEST'" ) );
$cols = fn( $t ) => array_column( $wpdb->get_results( "SHOW FULL COLUMNS FROM $t", ARRAY_A ), null, 'Field' );
$cc   = $cols( $C );
$sc   = $cols( $S );
pqbg_t( 'pqbg_codes columns', array_keys( $cc ) === array( 'id', 'code', 'kind', 'product_id', 'parent_id', 'active_product_id', 'status', 'created_at_gmt', 'created_by', 'retired_at_gmt', 'retired_by' ), implode( ',', array_keys( $cc ) ) );
pqbg_t( 'pqbg_sales columns', array_keys( $sc ) === array( 'id', 'request_id', 'code_id', 'unit_id', 'product_id', 'variation_id', 'order_id', 'seller_id', 'quantity', 'unit_price', 'regular_price', 'line_total', 'currency', 'product_name', 'sku', 'attributes_json', 'stock_before', 'stock_after', 'source', 'status', 'void_reason', 'voided_by', 'voided_at_gmt', 'note', 'created_at_gmt', 'stock_holder_id', 'failure_code', 'payment_method', 'unit_cost', 'seller_name' ), implode( ',', array_keys( $sc ) ) ); // Schema v2 (Phase 7) added stock_holder_id and failure_code; v3 (Phase 9A) the last three.
pqbg_t( 'codes types/defaults', 'bigint(20) unsigned' === $cc['id']['Type'] && 'varchar(32)' === $cc['code']['Type'] && 'YES' === $cc['active_product_id']['Null'] && 'active' === $cc['status']['Default'] && 'product' === $cc['kind']['Default'] && '0' === $cc['parent_id']['Default'] && 'YES' === $cc['retired_at_gmt']['Null'] && 'datetime' === $cc['created_at_gmt']['Type'] );
pqbg_t( 'sales types/defaults', 'char(36)' === $sc['request_id']['Type'] && 'decimal(26,8)' === $sc['unit_price']['Type'] && 'decimal(26,8)' === $sc['line_total']['Type'] && 'YES' === $sc['regular_price']['Null'] && 'char(3)' === $sc['currency']['Type'] && 'varchar(100)' === $sc['sku']['Type'] && 'longtext' === $sc['attributes_json']['Type'] && 'text' === $sc['product_name']['Type'] && 'scan' === $sc['source']['Default'] && 'completed' === $sc['status']['Default'] && '0' === $sc['variation_id']['Default'] && 'YES' === $sc['code_id']['Null'] && 'YES' === $sc['order_id']['Null'] && 'YES' === $sc['unit_id']['Null'] && 'NO' === $sc['seller_id']['Null'] );
pqbg_t( 'collation matches WP', $cc['code']['Collation'] === $wpdb->collate && $sc['product_name']['Collation'] === $wpdb->collate, $cc['code']['Collation'] );
$idx = function ( $t ) use ( $wpdb ) {
	$o = array();
	foreach ( $wpdb->get_results( "SHOW INDEX FROM $t", ARRAY_A ) as $r ) {
		$o[ $r['Key_name'] ]['unique'] = ! $r['Non_unique'];
		$o[ $r['Key_name'] ]['cols'][] = $r['Column_name'];
	}
	return $o;
};
$ci  = $idx( $C );
$si  = $idx( $S );
pqbg_t( 'codes indexes', $ci['code']['unique'] && $ci['active_product_id']['unique'] && array( 'product_id', 'status' ) === $ci['product_status']['cols'] && isset( $ci['parent_id'], $ci['status'] ) && 6 === count( $ci ) );
pqbg_t( 'sales indexes', $si['request_id']['unique'] && array( 'product_id', 'variation_id' ) === $si['product_variation']['cols'] && array( 'seller_id', 'created_at_gmt' ) === $si['seller_created']['cols'] && array( 'status', 'created_at_gmt' ) === $si['status_created']['cols'] && isset( $si['code_id'], $si['created_at_gmt'], $si['order_id'] ) && array( 'stock_holder_id', 'status' ) === $si['holder_status']['cols'] && array( 'payment_method', 'created_at_gmt' ) === $si['method_created']['cols'] && 10 === count( $si ) ); // holder_status: schema v2 (Phase 7); method_created: v3 (Phase 9A).
$eng = fn( $t ) => $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', DB_NAME, $t ) );
pqbg_t( 'engine InnoDB', 'InnoDB' === $eng( $C ) && 'InnoDB' === $eng( $S ) );
pqbg_t( 'CHECK constraint present', Schema::has_active_check(), Schema::active_check_name() );
$dd = Schema::create_or_update();
pqbg_t( 'dbDelta re-run is a no-op', array() === $dd, json_encode( $dd ) );

pqbg_section( 'options' );
pqbg_t( 'pqbg_db_version = Install::DB_VERSION (2 since Phase 7)', Install::DB_VERSION === Install::stored_version() );
$ao = fn( $n ) => $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name=%s", $n ) );
pqbg_t( 'pqbg_settings exists, not autoloaded', is_array( get_option( 'pqbg_settings' ) ) && in_array( $ao( 'pqbg_settings' ), array( 'off', 'no' ), true ), $ao( 'pqbg_settings' ) );
$settings_before = get_option( 'pqbg_settings' );
pqbg_t(
	'settings merge drops unknown keys',
	( function () use ( $settings_before ) {
		update_option( 'pqbg_settings', array( 'bogus' => 'x' ), false );
		$s = Plugin::settings();
		update_option( 'pqbg_settings', $settings_before, false );
		return Plugin::default_settings() === $s;
	} )()
);
pqbg_t( 'settings restored exactly', get_option( 'pqbg_settings' ) === $settings_before );
pqbg_t( 'no lock left behind', null === $ao( 'pqbg_install_lock' ) );

pqbg_section( 'repository / active-code invariant' );
$uid = 1;
$P   = 999999901;
$P2  = 999999902;
$now = time();
$id1 = CodeRepository::create_active( 'test-pqbg-0001', $P, 0, $uid );
pqbg_t( 'create active (input normalised to uppercase)', is_int( $id1 ) && 'TEST-PQBG-0001' === CodeRepository::find_by_code( 'TEST-PQBG-0001' )['code'] );
$row = CodeRepository::find_active_for_product( $P );
pqbg_t( 'active row invariant', $row && (int) $row['active_product_id'] === $P && 'active' === $row['status'] && 'product' === $row['kind'] && 0 === (int) $row['parent_id'] );
pqbg_t( 'created_at_gmt is UTC now', abs( strtotime( $row['created_at_gmt'] . ' UTC' ) - $now ) <= 5, $row['created_at_gmt'] . ' vs ' . gmdate( 'Y-m-d H:i:s', $now ) );
$dup = CodeRepository::create_active( 'TEST-PQBG-0002', $P, 0, $uid );
pqbg_t( 'app rejects 2nd active code for item', is_wp_error( $dup ) && 'pqbg_active_code_exists' === $dup->get_error_code() );
$dupc = CodeRepository::create_active( 'TEST-PQBG-0001', $P2, 0, $uid );
pqbg_t( 'duplicate code string rejected safely', is_wp_error( $dupc ) && 'pqbg_code_conflict' === $dupc->get_error_code() );
pqbg_t( 'invalid code formats rejected', is_wp_error( CodeRepository::create_active( "X'; DROP TABLE", $P2, 0, $uid ) ) && is_wp_error( CodeRepository::create_active( 'AB', $P2, 0, $uid ) ) && is_wp_error( CodeRepository::create_active( '-ABCD', $P2, 0, $uid ) ) && is_wp_error( CodeRepository::create_active( str_repeat( 'A', 33 ), $P2, 0, $uid ) ) );
pqbg_t( 'invalid product refs rejected', is_wp_error( CodeRepository::create_active( 'TEST-PQBG-0009', 0, 0, $uid ) ) && is_wp_error( CodeRepository::create_active( 'TEST-PQBG-0009', $P2, $P2, $uid ) ) );
pqbg_t( 'no stray rows from rejected creates', 1 === $test_rows() && $base_c + 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" ) );
$wpdb->suppress_errors( true );
pqbg_t( 'DB UNIQUE blocks raw duplicate active', false === $wpdb->insert( $C, array( 'code' => 'TEST-PQBG-0003', 'product_id' => $P, 'active_product_id' => $P, 'status' => 'active', 'created_at_gmt' => gmdate( 'Y-m-d H:i:s' ) ) ), $wpdb->last_error );
pqbg_t( 'DB CHECK blocks active + NULL', false === $wpdb->insert( $C, array( 'code' => 'TEST-PQBG-0004', 'product_id' => $P2, 'status' => 'active', 'created_at_gmt' => gmdate( 'Y-m-d H:i:s' ) ) ), $wpdb->last_error );
pqbg_t( 'DB CHECK blocks retired with active id', false === $wpdb->insert( $C, array( 'code' => 'TEST-PQBG-0005', 'product_id' => $P2, 'active_product_id' => $P2, 'status' => 'retired', 'created_at_gmt' => gmdate( 'Y-m-d H:i:s' ) ) ), $wpdb->last_error );
$wpdb->suppress_errors( false );
pqbg_t( 'retire', true === CodeRepository::retire( $id1, $uid ) );
$r1 = CodeRepository::find_by_code( 'TEST-PQBG-0001' );
pqbg_t( 'retired row: NULL active id + stamps', 'retired' === $r1['status'] && null === $r1['active_product_id'] && (int) $r1['retired_by'] === $uid && null !== $r1['retired_at_gmt'] );
pqbg_t( 'retire twice rejected', is_wp_error( CodeRepository::retire( $id1, $uid ) ) );
pqbg_t( 'no active code after retire', null === CodeRepository::find_active_for_product( $P ) );
$id2 = CodeRepository::create_active( 'TEST-PQBG-0006', $P, 0, $uid );
pqbg_t( 'new active code after retire', is_int( $id2 ) );
pqbg_t( 'retired code stays retired', 'retired' === CodeRepository::find_by_code( 'TEST-PQBG-0001' )['status'] && (int) CodeRepository::find_active_for_product( $P )['id'] === $id2 );
pqbg_t( 'retired code string cannot be reissued', is_wp_error( CodeRepository::create_active( 'TEST-PQBG-0001', $P2, 0, $uid ) ) );
$id3 = CodeRepository::create_active( 'TEST-PQBG-0007', $P2, $P, $uid );
pqbg_t( 'variation-style code keeps parent_id', is_int( $id3 ) && (int) CodeRepository::find_by_code( 'TEST-PQBG-0007' )['parent_id'] === $P );

pqbg_section( 'sales table' );
$req  = wp_generate_uuid4();
$sale = array( 'request_id' => $req, 'code_id' => $id2, 'product_id' => $P, 'variation_id' => 0, 'seller_id' => $uid, 'quantity' => 1, 'unit_price' => '1499.50', 'regular_price' => '1999.00', 'line_total' => '1499.50', 'currency' => 'INR', 'product_name' => 'PQBG PHASE2 TEST', 'sku' => 'TEST-SKU', 'attributes_json' => '{}', 'stock_before' => 3, 'stock_after' => 2, 'note' => 'PQBG_PHASE2_TEST', 'created_at_gmt' => gmdate( 'Y-m-d H:i:s' ) );
pqbg_t( 'sale row insert', false !== $wpdb->insert( $S, $sale ) );
$sr = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $S WHERE request_id=%s", $req ), ARRAY_A );
pqbg_t( 'sale defaults + decimal precision', 'scan' === $sr['source'] && 'completed' === $sr['status'] && '1499.50000000' === $sr['unit_price'] && null === $sr['order_id'] && null === $sr['unit_id'] );
$wpdb->suppress_errors( true );
pqbg_t( 'duplicate request_id rejected', false === $wpdb->insert( $S, $sale ) );
$wpdb->suppress_errors( false );

pqbg_section( 'idempotency / migrations' );
$snapshot = fn() => array( $wpdb->get_results( "SELECT * FROM $C ORDER BY id", ARRAY_A ), $wpdb->get_results( "SELECT * FROM $S ORDER BY id", ARRAY_A ), $wpdb->get_row( "SHOW CREATE TABLE $C", ARRAY_N )[1], $wpdb->get_row( "SHOW CREATE TABLE $S", ARRAY_N )[1] );
$before   = $snapshot();
pqbg_t( 'install() #1', true === Install::install() );
pqbg_t( 'install() #2', true === Install::install() );
Install::activate( false );
pqbg_t( 'activation hook re-run completes', true );
pqbg_t( 'data + schema unchanged after reruns', $before == $snapshot() );
update_option( 'pqbg_db_version', 0, true );
Install::maybe_upgrade();
pqbg_t( 'migrate v0 -> current via maybe_upgrade', Install::DB_VERSION === Install::stored_version() );
pqbg_t( 'data preserved through migration re-run', $before == $snapshot() );
update_option( 'pqbg_db_version', 5, true );
Install::maybe_upgrade();
pqbg_t( 'newer stored version left alone (no downgrade)', 5 === Install::stored_version() && $before == $snapshot() );
update_option( 'pqbg_db_version', 1, true );

pqbg_section( 'lock' );
$tok = Install::acquire_lock();
pqbg_t( 'acquire lock', is_string( $tok ) );
pqbg_t( 'second acquire blocked', false === Install::acquire_lock() );
$r = Install::install();
pqbg_t( 'install refuses while locked', is_wp_error( $r ) && 'pqbg_install_locked' === $r->get_error_code() );
update_option( 'pqbg_db_version', 0, true );
Install::maybe_upgrade();
pqbg_t( 'maybe_upgrade skips while locked (no migration)', 0 === Install::stored_version() );
update_option( 'pqbg_db_version', 1, true );
Install::release_lock( 'bogus-token' );
pqbg_t( 'foreign token cannot release', false === Install::acquire_lock() );
Install::release_lock( $tok );
pqbg_t( 'release frees lock', null === $ao( 'pqbg_install_lock' ) );
$stale = ( time() - 10 ) . ':stale';
$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('pqbg_install_lock', %s, 'off')", $stale ) );
$tok2 = Install::acquire_lock();
pqbg_t( 'expired lock is recoverable', is_string( $tok2 ) && $tok2 !== $stale );
Install::release_lock( $tok2 );
pqbg_t( 'lock clean', null === $ao( 'pqbg_install_lock' ) );

pqbg_section( 'roles' );
$sel  = get_role( 'pqbg_seller' );
$sm   = get_role( 'shop_manager' );
$ad   = get_role( 'administrator' );
$pqbg = fn( $r ) => array_values( array_filter( array_keys( array_filter( $r->capabilities ) ), fn( $c ) => str_starts_with( $c, 'pqbg_' ) ) );
pqbg_t( 'seller role exists as "Store Seller"', (bool) $sel && 'Store Seller' === wp_roles()->role_names['pqbg_seller'] );
pqbg_t( 'seller caps exact', array( 'read', 'pqbg_view_products', 'pqbg_sell', 'pqbg_view_own_sales' ) === array_keys( array_filter( $sel->capabilities ) ), implode( ',', array_keys( $sel->capabilities ) ) );
pqbg_t( 'seller lacks edit_products/manage_woocommerce/edit_posts/manage_options', ! $sel->has_cap( 'edit_products' ) && ! $sel->has_cap( 'manage_woocommerce' ) && ! $sel->has_cap( 'edit_posts' ) && ! $sel->has_cap( 'manage_options' ) );
pqbg_t( 'shop_manager pqbg caps', array( 'pqbg_view_products', 'pqbg_sell', 'pqbg_view_own_sales', 'pqbg_view_all_sales', 'pqbg_void_sale', 'pqbg_manage_codes' ) === $pqbg( $sm ), implode( ',', $pqbg( $sm ) ) );
pqbg_t( 'shop_manager has NO pqbg_manage_settings (and, since Phase 9A, no pqbg_view_costs)', ! $sm->has_cap( 'pqbg_manage_settings' ) && ! $sm->has_cap( 'pqbg_view_costs' ) );
pqbg_t( 'administrator has all 8 pqbg caps (pqbg_view_costs since Phase 9A)', 8 === count( $pqbg( $ad ) ) && $ad->has_cap( 'pqbg_manage_settings' ) && $ad->has_cap( 'pqbg_view_costs' ), implode( ',', $pqbg( $ad ) ) );
$others = true;
foreach ( array( 'editor', 'author', 'contributor', 'subscriber', 'customer' ) as $rn ) {
	$others = $others && ! count( $pqbg( get_role( $rn ) ) );
}
pqbg_t( 'other roles have no pqbg caps', $others );
$strip  = function ( $roles ) {
	unset( $roles['pqbg_seller'] );
	foreach ( $roles as &$r ) {
		$r['capabilities'] = array_filter( $r['capabilities'], fn( $k ) => ! str_starts_with( $k, 'pqbg_' ), ARRAY_FILTER_USE_KEY );
	}
	return $roles;
};
$canary = 'zz_pqbg_test_canary';
foreach ( array_keys( wp_roles()->roles ) as $rn ) {
	get_role( $rn )->add_cap( $canary );
}
$roles_before = get_option( wp_roles()->role_key );
Install::install();
Permissions::sync_roles();
$roles_after = get_option( wp_roles()->role_key );
$canary_kept = true;
foreach ( array_keys( wp_roles()->roles ) as $rn ) {
	$canary_kept = $canary_kept && ! empty( $roles_after[ $rn ]['capabilities'][ $canary ] );
	get_role( $rn )->remove_cap( $canary );
}
pqbg_t( 'unrelated role capabilities untouched by install/sync (snapshot + canary)', $canary_kept && $strip( $roles_before ) == $strip( $roles_after ) );
$all_before = get_option( wp_roles()->role_key );
Permissions::sync_roles();
Permissions::sync_roles();
pqbg_t( 'sync_roles idempotent', get_option( wp_roles()->role_key ) === $all_before );
$sid = wp_insert_user( array( 'user_login' => 'pqbg_phase2_test_seller', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'pqbg-phase2-test@example.invalid', 'role' => 'pqbg_seller' ) );
pqbg_t( 'helpers: seller', Permissions::can_sell( $sid ) && Permissions::can_view_products( $sid ) && Permissions::can_view_sale( $sid, $sid ) && ! Permissions::can_view_sale( 1, $sid ) && ! Permissions::can_void_sale( $sid ) && ! Permissions::can_manage_codes( $sid ) && ! Permissions::can_manage_settings( $sid ) );
pqbg_t( 'helpers: admin', Permissions::can_view_sale( $sid, 1 ) && Permissions::can_manage_settings( 1 ) );
pqbg_t( 'helpers: logged-out denied', ! Permissions::can_view_products() && ! Permissions::can_sell( 0 ) && ! Permissions::can_view_sale( 0, 0 ) );
pqbg_t( 'nonce action convention', 'pqbg_void_sale' === Permissions::nonce_action( 'void_sale' ) );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $sid );

pqbg_section( 'woocommerce / security' );
$fc     = wc_get_container()->get( \Automattic\WooCommerce\Internal\Features\FeaturesController::class );
$compat = $fc->get_compatible_plugins_for_feature( 'custom_order_tables', true );
$pf     = pqbg_test_plugin_basename();
pqbg_t( 'HPOS: compatible with custom_order_tables', in_array( $pf, $compat['compatible'], true ) && ! in_array( $pf, $compat['incompatible'], true ) );
pqbg_t( 'HPOS still enabled', \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
do_action( 'rest_api_init' );
$hit = fn( $s ) => str_contains( $s, 'pqbg' ) || str_contains( $s, 'qrcode-barcode' );
pqbg_t( 'no pqbg REST namespace/routes', ! array_filter( rest_get_server()->get_namespaces(), $hit ) && ! array_filter( array_keys( rest_get_server()->get_routes() ), $hit ) );
pqbg_t( 'no pqbg shortcodes', ! array_filter( array_keys( $GLOBALS['shortcode_tags'] ), $hit ) );
pqbg_t( 'no pqbg ajax actions', ! array_filter( array_keys( $GLOBALS['wp_filter'] ), fn( $h ) => str_starts_with( $h, 'wp_ajax' ) && $hit( $h ) ) );

pqbg_section( 'cleanup' );
$wpdb->query( "DELETE FROM $S WHERE note = 'PQBG_PHASE2_TEST'" );
$wpdb->query( "DELETE FROM $C WHERE code LIKE 'TEST-PQBG-%'" );
$wpdb->query( "ALTER TABLE $C AUTO_INCREMENT = 1" );
$wpdb->query( "ALTER TABLE $S AUTO_INCREMENT = 1" );
pqbg_t( 'tables back to their starting row counts', $base_c === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" ) && $base_s === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $S" ) );
pqbg_t( 'test user removed', ! get_user_by( 'login', 'pqbg_phase2_test_seller' ) );
pqbg_t( 'pqbg_db_version = Install::DB_VERSION, no lock', Install::DB_VERSION === Install::stored_version() && null === $ao( 'pqbg_install_lock' ) );
pqbg_t( 'no canary capability left on any role', ! str_contains( (string) wp_json_encode( get_option( wp_roles()->role_key ) ), $canary ) );

pqbg_test_done();
