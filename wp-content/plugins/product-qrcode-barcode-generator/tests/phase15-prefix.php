<?php
/**
 * Phase 15 suite: the configurable code prefix.
 *
 * Checks the Settings rule (2-4 characters, A-Z and 0-9, a letter first, uppercased),
 * that the field is for administrators only, that new codes use the prefix, that the
 * format check accepts every 2-6 character prefix so codes issued under an earlier
 * prefix keep scanning, the scan route with mixed prefixes (including the logged-out
 * redirect, which never checks existence), and the label layout for longer codes
 * (code text wrap, barcode width, the barcode warning).
 *
 * In-process only (no HTTP). Temporarily changes pqbg_settings and restores the exact
 * stored value at the end. Creates one administrator, two products and their code
 * rows, and removes them all.
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

use ProductQrBarcode\{CodeGenerator, CodeRepository, Permissions, Plugin, PrintLayout, ScanRoute, ScanScreen, ScanUrl, Schema, Settings, SettingsPage};

global $wpdb;

$C           = Schema::codes_table();
$option      = Plugin::SETTINGS_OPTION;
$raw_setting = static fn() => $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $option ), ARRAY_A );
$original    = $raw_setting();
$start_id    = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $C" );
$start_post  = (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID), 0) FROM {$wpdb->posts}" );
$base_c      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" );
$base_user   = (int) count_users()['total_users'];
$as_mark     = pqbg_test_as_mark(); // Action Scheduler cleanup, see bootstrap.php.
$cl_mark     = pqbg_test_catlookup_mark(); // Category lookup rows, see bootstrap.php.
$admin_id    = 0;

/** Stores plugin settings exactly (no sanitize callback outside wp-admin). */
$set = static function ( array $values ) use ( $option ): void {
	update_option( $option, array_merge( Plugin::default_settings(), $values ), false );
	wp_cache_delete( $option, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
};
$make_simple = static function ( int $as ): int {
	wp_set_current_user( $as );
	$p = new WC_Product_Simple();
	$p->set_name( 'PQBG P15 simple ' . wp_generate_password( 6, false ) );
	$p->set_status( 'publish' );
	$p->set_regular_price( '10' );
	$id = (int) $p->save();
	wp_set_current_user( 0 );
	return $id;
};
$code_of = static fn( int $id ) => (string) ( CodeRepository::find_active_for_product( $id )['code'] ?? '' );
$path_of = static fn( string $code ) => (string) wp_parse_url( ScanUrl::site_url( $code ), PHP_URL_PATH );
$unknown = static fn( string $prefix ) => $prefix . '-' . implode( '-', str_split( substr( str_shuffle( str_repeat( CodeGenerator::ALPHABET, 3 ) ), 0, 12 ), 4 ) );

try {
	pqbg_section( 'prefix rule (Settings)' );
	pqbg_t( 'default prefix is DC, in the default settings too', 'DC' === CodeGenerator::DEFAULT_PREFIX && 'DC' === Plugin::default_settings()['code_prefix'] );
	pqbg_t( 'rule constants: 2 to 4 characters', 2 === CodeGenerator::PREFIX_MIN && 4 === CodeGenerator::PREFIX_MAX && '/^[A-Z][A-Z0-9]{1,3}$/D' === CodeGenerator::PREFIX_PATTERN );
	foreach ( array( 'DC', 'AB', 'DUR', 'DGC1', 'A2', 'Z999' ) as $ok ) {
		pqbg_t( "accepted: {$ok}", $ok === Settings::validate_code_prefix( $ok ) );
	}
	pqbg_t( 'lowercase converted to uppercase, spaces around removed', 'DUR' === Settings::validate_code_prefix( ' dur ' ) && 'AB12' === Settings::validate_code_prefix( 'ab12' ) );
	$bad = array(
		'empty'          => '',
		'one character'  => 'D',
		'five'           => 'DURGA',
		'hyphen'         => 'D-C',
		'underscore'     => 'D_C',
		'inner space'    => 'D C',
		'digit first'    => '2DC',
		'accented'       => 'DÉ',
		'Greek letter'   => "D\u{03A4}",
		'fullwidth'      => "D\u{FF23}",
		'trailing NL'    => "DC\n",
		'not a string'   => array( 'DC' ),
		'markup'         => '<b>',
	);
	foreach ( $bad as $label => $value ) {
		$r = Settings::validate_code_prefix( $value );
		pqbg_t( "refused: {$label}", is_wp_error( $r ) && 'pqbg_invalid_code_prefix' === $r->get_error_code() );
	}

	pqbg_section( 'sanitize and stored value' );
	$set( array( 'code_prefix' => 'DUR' ) );
	$out = Settings::sanitize( array( 'code_prefix' => 'ab' ) );
	pqbg_t( 'a valid prefix is saved uppercased', 'AB' === $out['code_prefix'] );
	$out = Settings::sanitize( array( 'code_prefix' => 'D-C' ) );
	pqbg_t( 'an invalid prefix keeps the previous one and reports an error', 'DUR' === $out['code_prefix'] && in_array( 'pqbg_invalid_code_prefix', array_column( get_settings_errors( $option ), 'code' ), true ) );
	$out = Settings::sanitize( array( 'barcodes_enabled' => '1' ) );
	pqbg_t( 'saving other fields keeps the prefix', 'DUR' === $out['code_prefix'] );
	$set( array( 'code_prefix' => 'x-y' ) );
	pqbg_t( 'a stored value that breaks the rule falls back to DC', 'DC' === Settings::get_code_prefix() );
	update_option( $option, array( 'settings_version' => 1 ), false );
	wp_cache_delete( $option, 'options' );
	pqbg_t( 'settings saved before Phase 15 (no key) give DC', 'DC' === Settings::get_code_prefix() );

	pqbg_section( 'administrators only' );
	pqbg_t( 'the Settings page and its option need pqbg_manage_settings', Permissions::MANAGE_SETTINGS === SettingsPage::capability() );
	pqbg_t( 'administrators have it; shop managers and sellers do not', get_role( 'administrator' )->has_cap( Permissions::MANAGE_SETTINGS ) && ! get_role( 'shop_manager' )->has_cap( Permissions::MANAGE_SETTINGS ) && ! get_role( Permissions::SELLER_ROLE )->has_cap( Permissions::MANAGE_SETTINGS ) );
	$set( array( 'code_prefix' => 'DUR' ) );
	ob_start();
	SettingsPage::render_code_prefix_field();
	$field = (string) ob_get_clean();
	pqbg_t( 'field: current value, maxlength 4, help text about new codes only', str_contains( $field, 'name="pqbg_settings[code_prefix]"' ) && str_contains( $field, 'value="DUR"' ) && str_contains( $field, 'maxlength="4"' ) && str_contains( $field, 'Used for new codes only' ) && str_contains( $field, 'DUR-XXXX-XXXX-XXXX' ) );
	pqbg_t( 'field: no barcode warning while barcodes are off', ! str_contains( $field, 'pqbg-prefix-warning' ) );

	pqbg_section( 'barcode warning' );
	$set( array( 'code_prefix' => 'DUR', 'barcodes_enabled' => true ) );
	ob_start();
	SettingsPage::render_code_prefix_field();
	$field = (string) ob_get_clean();
	pqbg_t( 'barcodes on and a 3-character prefix: warning on Settings', '' !== Settings::prefix_barcode_warning() && str_contains( $field, 'pqbg-prefix-warning' ) );
	$set( array( 'code_prefix' => 'AB', 'barcodes_enabled' => true ) );
	pqbg_t( 'barcodes on and a 2-character prefix: no warning', '' === Settings::prefix_barcode_warning() );
	$set( array( 'code_prefix' => 'DGC1', 'barcodes_enabled' => false ) );
	pqbg_t( 'barcodes off: no warning', '' === Settings::prefix_barcode_warning() );
	$src = (string) file_get_contents( PQBG_PLUGIN_DIR . 'includes/PrintAdmin.php' );
	pqbg_t( 'the print setup screen shows the same warning', str_contains( $src, 'Settings::prefix_barcode_warning()' ) );

	pqbg_section( 'generation' );
	foreach ( array( 'DC', 'AB', 'DUR', 'DGC1' ) as $prefix ) {
		$set( array( 'code_prefix' => $prefix ) );
		$code = ( new CodeGenerator( null, static fn() => false ) )->generate();
		pqbg_t( "new codes use the prefix {$prefix}", str_starts_with( $code, $prefix . '-' ) && strlen( $code ) === strlen( $prefix ) + 15 && CodeGenerator::is_valid_format( $code ) );
	}
	pqbg_t( 'example code follows the prefix', 'DGC1-XXXX-XXXX-XXXX' === CodeGenerator::example_code( 'DGC1' ) );

	pqbg_section( 'format check: every 2-6 character prefix, independent of Settings' );
	$set( array( 'code_prefix' => 'DC' ) );
	foreach ( array( 'DC', 'AB', 'DUR', 'DGC1', 'DURGA', 'DURGA6', 'Z0', 'OIL1' ) as $prefix ) {
		pqbg_t( "accepted while the prefix is DC: {$prefix}-", CodeGenerator::is_valid_format( $prefix . '-7K4M-9P2X-Q8RT' ) );
	}
	foreach ( array( 'D', 'DURGAC7', '2C', 'dc', 'D_C', 'D-C' ) as $prefix ) {
		pqbg_t( "refused: {$prefix}-", ! CodeGenerator::is_valid_format( $prefix . '-7K4M-9P2X-Q8RT' ) );
	}
	pqbg_t( 'the random part still refuses 0, 1, I, L and O', ! CodeGenerator::is_valid_format( 'DUR-7K4M-9P2X-Q8R0' ) && ! CodeGenerator::is_valid_format( 'DUR-7K4M-9P2X-Q8RO' ) );
	pqbg_t( 'the longest code fits the code column (varchar 32) and the storage check', 21 === strlen( 'DURGA6-7K4M-9P2X-Q8RT' ) && 'DURGA6-7K4M-9P2X-Q8RT' === CodeRepository::normalize_code( 'durga6-7k4m-9p2x-q8rt' ) );
	pqbg_t( 'typed input with another prefix is normalised and accepted', 'DGC1-7K4M-9P2X-Q8RT' === ScanUrl::extract_code( " dgc1-7k4m-9p2x-q8rt\t" ) && CodeGenerator::is_valid_format( ScanUrl::extract_code( home_url( '/scan/dgc1-7k4m-9p2x-q8rt/' ) ) ) );
	pqbg_t( 'my-sales can never be a code, whatever the prefix', ! CodeGenerator::is_valid_format( ScanUrl::extract_code( ScanUrl::MY_SALES ) ) );
	pqbg_t( 'scan URL for an old-prefix code (labels, codes CSV)', ScanUrl::base() . '/scan/DURGA6-7K4M-9P2X-Q8RT/' === ScanUrl::for_code( 'DURGA6-7K4M-9P2X-Q8RT' ) );

	pqbg_section( 'scan with mixed prefixes' );
	$admin_id = wp_insert_user( array( 'user_login' => 'pqbg_p15_admin', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'pqbg-p15-admin@example.invalid', 'role' => 'administrator' ) );
	pqbg_t( 'temporary administrator created', is_int( $admin_id ) );
	$set( array( 'code_prefix' => 'DUR' ) );
	$old_item = $make_simple( $admin_id );
	$old_code = $code_of( $old_item );
	$set( array( 'code_prefix' => 'DC' ) );
	$new_item = $make_simple( $admin_id );
	$new_code = $code_of( $new_item );
	pqbg_t( 'automatic codes: DUR- before the change, DC- after it', str_starts_with( $old_code, 'DUR-' ) && str_starts_with( $new_code, 'DC-' ) );
	pqbg_t( 'the earlier code is unchanged after the prefix change', $old_code === $code_of( $old_item ) );
	wp_set_current_user( $admin_id );
	foreach ( array( 'old prefix' => $old_code, 'current prefix' => $new_code ) as $label => $code ) {
		$r = ScanRoute::decide( 'GET', $path_of( $code ), $code, null );
		pqbg_t( "logged in: the {$label} code opens its product", 200 === $r['status'] && $code === ( $r['view']['code'] ?? '' ) && null !== ( $r['view']['product'] ?? null ) );
	}
	$ghost = $unknown( 'ZZ9' );
	$r     = ScanRoute::decide( 'GET', $path_of( $ghost ), $ghost, null );
	pqbg_t( 'logged in: an unknown code with another prefix is "Code not found" (404)', 404 === $r['status'] );
	$r = ScanRoute::decide( 'GET', ScanUrl::site_path(), null, strtolower( $old_code ) );
	pqbg_t( 'entry box: the old-prefix code typed in lowercase goes to its page', 302 === $r['status'] && ScanUrl::site_url( $old_code ) === $r['location'] );
	pqbg_t( 'entry box placeholder uses the current prefix', 'DC-XXXX-XXXX-XXXX' === ScanScreen::entry()['placeholder'] );
	wp_set_current_user( 0 );
	$known = ScanRoute::decide( 'GET', $path_of( $old_code ), $old_code, null );
	$ghost = ScanRoute::decide( 'GET', $path_of( $ghost ), $ghost, null );
	pqbg_t( 'logged out: known and unknown codes both go to the login page, existence never checked', 302 === $known['status'] && 302 === $ghost['status'] && wp_login_url( ScanUrl::site_url( $old_code ) ) === $known['location'] && ! isset( $known['view'] ) && ! isset( $ghost['view'] ) );

	pqbg_section( 'label layout' );
	pqbg_t( 'barcode width: 242 modules (60.5 mm) for 17 characters, 11 more per character', 242 === PrintLayout::barcode_modules( 17 ) && 60.5 === PrintLayout::barcode_min_mm( 17 ) && 63.25 === PrintLayout::barcode_min_mm( 18 ) && 66.0 === PrintLayout::barcode_min_mm( 19 ) );
	$presets = PrintLayout::presets();
	$f17     = PrintLayout::fit( $presets['a4-3x7'], 33, array(), true, false );
	$f18     = PrintLayout::fit( $presets['a4-3x7'], 33, array(), true, false, 18 );
	$f19     = PrintLayout::fit( $presets['th-100x50'], 33, array(), true, false, 19 );
	pqbg_t( 'A4 3 × 7: barcode printed for a DC code, omitted (width) for a 3-character prefix', is_array( $f17 ) && null !== $f17['barcode'] && is_array( $f18 ) && null === $f18['barcode'] && 'width' === $f18['barcode_omitted'] && 63.25 === $f18['barcode_min'] );
	pqbg_t( '100 × 50 thermal: barcode printed for a 4-character prefix', is_array( $f19 ) && null !== $f19['barcode'] );
	pqbg_t( 'wrap after the second hyphen, whatever the prefix', array( 'DC-7K4M-', '9P2X-Q8RT' ) === PrintLayout::split_code( 'DC-7K4M-9P2X-Q8RT' ) && array( 'DGC1-7K4M-', '9P2X-Q8RT' ) === PrintLayout::split_code( 'DGC1-7K4M-9P2X-Q8RT' ) );
	pqbg_t( 'longest code of a mixed job', 'DGC1-7K4M-9P2X-Q8RT' === PrintLayout::longest_code( array( 'DC-7K4M-9P2X-Q8RT', 'DGC1-7K4M-9P2X-Q8RT', 'DUR-7K4M-9P2X-Q8RT' ) ) );
	$tpl = (string) file_get_contents( PQBG_PLUGIN_DIR . 'templates/pqbg-print.php' );
	pqbg_t( 'the print template splits with split_code(), not a fixed position', str_contains( $tpl, 'PrintLayout::split_code( $pqbg_code )' ) && ! str_contains( $tpl, 'substr( $pqbg_code, 0, 8 )' ) );

	pqbg_section( 'no fixed DC- left in plugin code' );
	$hits = array();
	foreach ( array_merge( glob( PQBG_PLUGIN_DIR . 'includes/*.php' ), glob( PQBG_PLUGIN_DIR . 'templates/*.php' ), glob( PQBG_PLUGIN_DIR . 'assets/*.js' ) ) as $file ) {
		foreach ( file( $file ) as $n => $line ) {
			// Comments may give DC-… as an example of the default prefix.
			if ( str_contains( $line, 'DC-' ) && ! preg_match( '#^\s*(\*|//|/\*\*)#', $line ) ) {
				$hits[] = basename( $file ) . ':' . ( $n + 1 );
			}
		}
	}
	pqbg_t( 'no "DC-" outside comments in includes, templates and assets', array() === $hits, implode( ', ', $hits ) );
} finally {
	pqbg_section( 'cleanup' );
	wp_set_current_user( 0 );
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ('product_variation','product','revision') ORDER BY post_type = 'product', ID DESC", $start_post ) ) );
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	if ( is_int( $admin_id ) && $admin_id > 0 ) {
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d", $admin_id ) ) as $pid ) {
			wp_delete_post( (int) $pid, true );
		}
		wp_delete_user( $admin_id );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM $C WHERE id > %d", $start_id ) );
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
	pqbg_t( 'codes table back to its starting row count', $base_c === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" ) );
	pqbg_t( 'no posts left above the starting post ID', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID > %d", $start_post ) ) );
	pqbg_t( 'temporary administrator removed', $base_user === (int) count_users()['total_users'] && ! get_user_by( 'login', 'pqbg_p15_admin' ) );
}

pqbg_test_done();
