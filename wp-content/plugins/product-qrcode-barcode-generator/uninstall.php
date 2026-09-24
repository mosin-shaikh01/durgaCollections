<?php
/**
 * Uninstall handler.
 *
 * DEFAULT: PRESERVE ALL DATA. The pqbg_codes and pqbg_sales tables hold printed
 * product codes and the sales audit trail, so deleting the plugin from the
 * Plugins screen leaves tables, options, the Seller role and capabilities in
 * place. Reinstalling picks everything up again.
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

if ( ! defined( 'PQBG_UNINSTALL_DELETE_ALL_DATA' ) || true !== PQBG_UNINSTALL_DELETE_ALL_DATA ) {
	return;
}

require_once __DIR__ . '/includes/Schema.php';
require_once __DIR__ . '/includes/Permissions.php';

\ProductQrBarcode\Schema::drop_tables();

delete_option( 'pqbg_settings' );
delete_option( 'pqbg_db_version' );

// Users who had the Seller role keep their accounts; they simply lose the role.
\ProductQrBarcode\Permissions::remove_all();
