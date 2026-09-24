<?php
/**
 * Uninstall handler.
 *
 * DEFAULT: PRESERVE ALL DATA. The dpc_codes and dpc_sales tables hold printed
 * product codes and the sales audit trail, so deleting the plugin from the
 * Plugins screen leaves tables, options, the Seller role and capabilities in
 * place. Reinstalling picks everything up again.
 *
 * To permanently delete all plugin data, the site owner must add
 *     define( 'DPC_UNINSTALL_DELETE_ALL_DATA', true );
 * to wp-config.php BEFORE deleting the plugin. This cannot be undone.
 *
 * @package Durga\ProductCodes
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Runtime-only lock row; never contains data.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'dpc_install_lock' ) );

if ( ! defined( 'DPC_UNINSTALL_DELETE_ALL_DATA' ) || true !== DPC_UNINSTALL_DELETE_ALL_DATA ) {
	return;
}

require_once __DIR__ . '/includes/Schema.php';
require_once __DIR__ . '/includes/Permissions.php';

\Durga\ProductCodes\Schema::drop_tables();

delete_option( 'dpc_settings' );
delete_option( 'dpc_db_version' );

// Users who had the Seller role keep their accounts; they simply lose the role.
\Durga\ProductCodes\Permissions::remove_all();
