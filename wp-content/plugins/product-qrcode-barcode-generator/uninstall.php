<?php
/**
 * Uninstall handler.
 *
 * DEFAULT: PRESERVE ALL DATA. The pqbg_codes and pqbg_sales tables hold printed
 * product codes and the sales audit trail, so deleting the plugin from the
 * Plugins screen leaves tables, options, the Seller role and capabilities in
 * place. Reinstalling picks everything up again. Only runtime state is always
 * removed: the install lock, the rewrite flag, the render cache of QR and
 * barcode images (PrintCache), the cost-import previews and the code-generation
 * run state (Phase 10), none of which is data.
 *
 * To permanently delete all plugin data, the site owner must add
 *     define( 'PQBG_UNINSTALL_DELETE_ALL_DATA', true );
 * to wp-config.php BEFORE deleting the plugin. This cannot be undone.
 *
 * @package ProductQrBarcode
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Runtime-only lock row; never contains data.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'pqbg_install_lock' ) );

// Runtime-only flag for the scan route's rewrite rules (normally already removed on deactivation).
delete_option( 'pqbg_rewrite_version' );

// The render cache of QR/barcode images is not data: always removed.
if ( ! class_exists( '\ProductQrBarcode\PrintCache', false ) ) {
	require_once __DIR__ . '/includes/PrintCache.php';
}
\ProductQrBarcode\PrintCache::clear_all();

// Phase 10 runtime state, never data: every user's cost-import preview (it holds costs and
// is temporary anyway) and the code-generation run state (codes already created stay).
delete_metadata( 'user', 0, 'pqbg_cost_import', '', true );
delete_option( 'pqbg_bulk_run' );

// Phase 11 runtime state, never data: the Dashboard timing samples (PerfSignal) and every
// user's one-time "code could not be saved" notice (CodeLifecycle; transients that expire
// anyway; with a persistent object cache they are not in the options table and expire there).
delete_option( 'pqbg_perf_samples' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_pqbg_save_failure_' ) . '%', $wpdb->esc_like( '_transient_timeout_pqbg_save_failure_' ) . '%' ) );

if ( ! defined( 'PQBG_UNINSTALL_DELETE_ALL_DATA' ) || true !== PQBG_UNINSTALL_DELETE_ALL_DATA ) {
	return;
}

require_once __DIR__ . '/includes/Schema.php';
require_once __DIR__ . '/includes/Permissions.php';

\ProductQrBarcode\Schema::drop_tables();

delete_option( 'pqbg_settings' );
delete_option( 'pqbg_db_version' );

// The bulk tools' audit trail (Phase 10).
delete_option( 'pqbg_bulk_log' );

// Cost prices of products and variations (Phase 9A). Bulk removal: never blocked by CostPrice's guard.
delete_metadata( 'post', 0, '_pqbg_cost_price', '', true );

// Each user's remembered label-printing options.
delete_metadata( 'user', 0, 'pqbg_print_prefs', '', true );

// Users who had the Seller role keep their accounts; they simply lose the role.
\ProductQrBarcode\Permissions::remove_all();
