<?php
/**
 * Phase 2 "WooCommerce missing" suite.
 *
 * Simulates WooCommerce being inactive FOR THIS PROCESS ONLY by filtering the
 * active_plugins option read before WordPress loads. Nothing is written.
 * The original Phase 2 script printed values; these are now assertions.
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/bootstrap.php';

$GLOBALS['wp_filter']['option_active_plugins'][10][] = array(
	'function'      => static fn( $p ) => array_values( array_diff( (array) $p, array( 'woocommerce/woocommerce.php' ) ) ),
	'accepted_args' => 1,
);

pqbg_test_load_wp();

use ProductQrBarcode\Requirements;

global $wpdb;

pqbg_t( 'positive control: the option filter is live', ! in_array( 'woocommerce/woocommerce.php', (array) get_option( 'active_plugins' ), true ) );
pqbg_t( 'WooCommerce not loaded', ! class_exists( 'WooCommerce', false ) );
pqbg_t( 'plugin main file loaded', defined( 'PQBG_VERSION' ) );
pqbg_t( 'requirements not met', ! Requirements::met() );
pqbg_t( 'error names WooCommerce', str_contains( implode( ' ', Requirements::errors() ), 'WooCommerce' ), json_encode( Requirements::errors() ) );
pqbg_t( 'requirements notice hooked', false !== has_action( 'admin_notices', array( Requirements::class, 'render_notice' ) ) );
pqbg_t( 'plugin did not boot (no textdomain hook)', false === has_action( 'init', array( 'ProductQrBarcode\\Plugin', 'load_textdomain' ) ) );
pqbg_t( 'settings page not registered', false === has_action( 'admin_menu', array( 'ProductQrBarcode\\SettingsPage', 'add_menu' ) ) && false === has_action( 'admin_notices', array( 'ProductQrBarcode\\SettingsPage', 'scan_url_notice' ) ) );

ob_start();
Requirements::render_notice();
pqbg_t( 'notice empty for logged-out user', '' === ob_get_clean() );

wp_set_current_user( 1 );
ob_start();
Requirements::render_notice();
$html = ob_get_clean();
pqbg_t( 'notice shown to a user who can manage plugins', str_contains( $html, 'notice-error' ) && str_contains( $html, 'requires WooCommerce' ) );
wp_set_current_user( 0 );

$stored = maybe_unserialize( $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'active_plugins'" ) );
pqbg_t( 'active_plugins in the database still contains WooCommerce', in_array( 'woocommerce/woocommerce.php', (array) $stored, true ) );

pqbg_test_done();
