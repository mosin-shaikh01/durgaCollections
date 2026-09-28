<?php
/**
 * Phase 2 lifecycle suite: deactivate, default uninstall path, reactivate.
 *
 * Ported to the current names. The plugin is always reactivated at the end,
 * even if a check throws. The destructive uninstall branch
 * (PQBG_UNINSTALL_DELETE_ALL_DATA) is never exercised here; since Phase 11 the
 * phase11-hardening suite runs it on a cloned temporary table prefix.
 *
 * Phase 11 (D16): while deactivated, the scan rewrite rules and their flag are gone
 * and /scan/ is no longer the plugin's (over HTTP); after reactivation they are back;
 * no cron events either way.
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

// Phase 11: the one safe point, before anything is created or deactivated.
pqbg_test_stop_point();

/** Whether the stored rewrite rules route anything to the scan page. */
$scan_rules = static function (): bool {
	return (bool) array_filter( (array) get_option( 'rewrite_rules', array() ), static fn( $q ) => is_string( $q ) && str_contains( $q, 'pqbg_scan=' ) );
};
/** Logged-out GET of the scan entry page over HTTP (a fresh connection): [status, Location]. */
$scan_http = static function (): array {
	$ch = curl_init( home_url( '/scan/' ) );
	curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_FORBID_REUSE => true, CURLOPT_FRESH_CONNECT => true, CURLOPT_TIMEOUT => 30 ) );
	$raw  = (string) curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$loc  = preg_match( '/^Location:\s*(\S+)/mi', $raw, $m ) ? $m[1] : '';
	return array( $code, $loc );
};
/** Cron events of this plugin. */
$pqbg_cron = static fn(): array => array_filter( array_keys( array_merge( ...array_values( array_map( static fn( $e ) => is_array( $e ) ? $e : array(), (array) _get_cron_array() ) ) ) ), static fn( $h ) => str_contains( (string) $h, 'pqbg' ) );

/*
 * Phase 13: the default uninstall below runs for real on this site, so it removes the runtime
 * state uninstall.php always removes. Keep what the site had and restore it byte for byte
 * afterwards: the Dashboard timing samples and the code-generation run state (options) and every
 * user's cost-import preview (user meta). The install lock and the rewrite flag come back with
 * reactivation; the render cache stays cleared (a cache, cleared as a real uninstall clears it).
 */
$RUNTIME_OPTIONS = array( 'pqbg_perf_samples', 'pqbg_bulk_run' );
$runtime_state   = static function () use ( $wpdb, $RUNTIME_OPTIONS ): array {
	$in = implode( ',', array_fill( 0, count( $RUNTIME_OPTIONS ), '%s' ) );
	return array(
		'options' => $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name IN ($in) ORDER BY option_name", ...$RUNTIME_OPTIONS ), ARRAY_A ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'meta'    => $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s ORDER BY user_id, umeta_id", 'pqbg_cost_import' ), ARRAY_A ),
	);
};
$saved_runtime = $runtime_state();

$before = $scan_http();
pqbg_t( 'active: rules and flag present, /scan/ sends logged-out visitors to the login page', $scan_rules() && false !== get_option( 'pqbg_rewrite_version' ) && 302 === $before[0] && str_contains( $before[1], 'wp-login.php' ), $before[0] . ' ' . $before[1] );

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
	wp_cache_flush();
	$during = $scan_http();
	pqbg_t( 'deactivated: the scan rules and their flag are gone; /scan/ is no longer the plugin\'s (no login redirect, no error)', ! $scan_rules() && false === get_option( 'pqbg_rewrite_version' ) && ! ( 302 === $during[0] && str_contains( $during[1], 'wp-login.php' ) ) && $during[0] > 0 && $during[0] < 500, $during[0] . ' ' . $during[1] );
	pqbg_t( 'deactivated: no cron events of the plugin', array() === $pqbg_cron() );

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
	// Phase 13: put back the runtime state the real uninstall removed (see $saved_runtime).
	foreach ( $saved_runtime['options'] as $row ) {
		$wpdb->delete( $wpdb->options, array( 'option_name' => $row['option_name'] ) );
		$wpdb->insert( $wpdb->options, $row );
	}
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'pqbg_cost_import' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	foreach ( $saved_runtime['meta'] as $row ) {
		$wpdb->insert( $wpdb->usermeta, $row );
	}
	wp_cache_flush();
}
pqbg_t( 'cleanup: the runtime state the uninstall removed (timing samples, run state, cost-import previews) is restored byte for byte', $saved_runtime === $runtime_state(), count( $saved_runtime['options'] ) . ' option(s), ' . count( $saved_runtime['meta'] ) . ' preview(s)' );

pqbg_t( 'reactivated', ( ! isset( $r ) || ! is_wp_error( $r ) ) && is_plugin_active( $pf ) );
pqbg_t( 'marker row survives reactivation', null !== CodeRepository::find_by_code( 'TEST-PQBG-LIFE' ) );
pqbg_t( 'db version still Install::DB_VERSION', Install::DB_VERSION === Install::stored_version() );
wp_cache_flush();
$after = $scan_http();
pqbg_t( 'reactivated: rules and flag back; /scan/ sends logged-out visitors to the login page again', $scan_rules() && false !== get_option( 'pqbg_rewrite_version' ) && 302 === $after[0] && str_contains( $after[1], 'wp-login.php' ), $after[0] . ' ' . $after[1] );
pqbg_t( 'reactivated: no cron events of the plugin', array() === $pqbg_cron() );

$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::codes_table() . ' WHERE code = %s', 'TEST-PQBG-LIFE' ) );
$wpdb->query( 'ALTER TABLE ' . Schema::codes_table() . ' AUTO_INCREMENT = 1' );
pqbg_t( 'cleanup: marker row removed', null === CodeRepository::find_by_code( 'TEST-PQBG-LIFE' ) );
pqbg_t( 'cleanup: no test sales rows', 0 == $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::sales_table() . " WHERE note LIKE 'PQBG_%TEST%'" ) );

pqbg_test_done();
