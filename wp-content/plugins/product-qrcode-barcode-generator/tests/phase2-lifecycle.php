<?php
/**
 * Phase 2 lifecycle suite: deactivate, default uninstall path, reactivate.
 *
 * Ported to the current names. The plugin is always reactivated at the end,
 * even if a check throws. The destructive uninstall branch
 * (PQBG_UNINSTALL_DELETE_ALL_DATA) is never exercised.
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/bootstrap.php';
pqbg_test_load_wp();
require_once ABSPATH . 'wp-admin/includes/plugin.php';

use ProductQrBarcode\{Schema, CodeRepository, Install};

global $wpdb;

$pf = pqbg_test_plugin_basename();

try {
	// Marker row so persistence across deactivate/uninstall is observable.
	$id = CodeRepository::create_active( 'TEST-PQBG-LIFE', 999999903, 0, 1 );
	pqbg_t( 'marker row created', is_int( $id ) );

	deactivate_plugins( $pf );
	pqbg_t( 'deactivated', ! is_plugin_active( $pf ) );
	pqbg_t( 'tables persist after deactivation', Schema::tables_exist() );
	pqbg_t( 'marker row persists', null !== CodeRepository::find_by_code( 'TEST-PQBG-LIFE' ) );
	pqbg_t( 'options persist', Install::DB_VERSION === (int) get_option( 'pqbg_db_version' ) && is_array( get_option( 'pqbg_settings' ) ) );
	pqbg_t( 'seller role + caps persist', get_role( 'pqbg_seller' ) && get_role( 'administrator' )->has_cap( 'pqbg_manage_settings' ) );

	// Default uninstall path (constant NOT defined): must preserve everything. Plugin files are not touched.
	pqbg_t( 'PQBG_UNINSTALL_DELETE_ALL_DATA not defined', ! defined( 'PQBG_UNINSTALL_DELETE_ALL_DATA' ) );
	define( 'WP_UNINSTALL_PLUGIN', $pf );
	include WP_PLUGIN_DIR . '/product-qrcode-barcode-generator/uninstall.php';
	pqbg_t( 'default uninstall: tables kept', Schema::tables_exist() );
	pqbg_t( 'default uninstall: marker row kept', null !== CodeRepository::find_by_code( 'TEST-PQBG-LIFE' ) );
	wp_cache_flush();
	pqbg_t( 'default uninstall: options kept', Install::DB_VERSION === (int) get_option( 'pqbg_db_version' ) && is_array( get_option( 'pqbg_settings' ) ) );
	pqbg_t( 'default uninstall: role/caps kept', get_role( 'pqbg_seller' ) && get_role( 'shop_manager' )->has_cap( 'pqbg_manage_codes' ) );
} finally {
	if ( ! is_plugin_active( $pf ) ) {
		$r = activate_plugin( $pf );
	}
}

pqbg_t( 'reactivated', ( ! isset( $r ) || ! is_wp_error( $r ) ) && is_plugin_active( $pf ) );
pqbg_t( 'marker row survives reactivation', null !== CodeRepository::find_by_code( 'TEST-PQBG-LIFE' ) );
pqbg_t( 'db version still Install::DB_VERSION', Install::DB_VERSION === Install::stored_version() );

$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::codes_table() . ' WHERE code = %s', 'TEST-PQBG-LIFE' ) );
$wpdb->query( 'ALTER TABLE ' . Schema::codes_table() . ' AUTO_INCREMENT = 1' );
pqbg_t( 'cleanup: marker row removed', null === CodeRepository::find_by_code( 'TEST-PQBG-LIFE' ) );
pqbg_t( 'cleanup: no test sales rows', 0 == $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::sales_table() . " WHERE note LIKE 'PQBG_%TEST%'" ) );

pqbg_test_done();
