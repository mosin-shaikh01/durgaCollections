<?php
/**
 * Shared helpers for the CLI test suites. See tests/README.md.
 *
 * The suites write and then remove test data on the target site (and the
 * lifecycle suite briefly deactivates the plugin), so they refuse to run on a
 * site whose environment type is "production". Preferred: set
 * define( 'WP_ENVIRONMENT_TYPE', 'local' ) in the development site's
 * wp-config.php. Fallback: PQBG_TESTS_ALLOW_PRODUCTION=1.
 *
 * Never loaded by the plugin.
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

$GLOBALS['pqbg_test_pass']   = 0;
$GLOBALS['pqbg_test_fail']   = 0;
$GLOBALS['pqbg_test_skip']   = 0;
$GLOBALS['pqbg_test_errors'] = array();

/**
 * Path to wp-load.php: PQBG_WP_LOAD, or four levels above this directory.
 */
function pqbg_test_wp_load_path(): string {
	$env = getenv( 'PQBG_WP_LOAD' );

	return ( is_string( $env ) && '' !== $env ) ? $env : dirname( __DIR__, 4 ) . '/wp-load.php';
}

/**
 * Loads WordPress for a suite and applies the production guard.
 */
function pqbg_test_load_wp(): void {
	$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';

	if ( ! defined( 'WP_USE_THEMES' ) ) {
		define( 'WP_USE_THEMES', false );
	}

	// Records PHP notices, warnings and deprecations raised by plugin code.
	set_error_handler(
		static function ( $level, $message, $file, $line ) {
			$plugin = str_replace( '\\', '/', dirname( __DIR__ ) );

			if ( str_starts_with( str_replace( '\\', '/', (string) $file ), $plugin ) ) {
				$GLOBALS['pqbg_test_errors'][] = "[$level] $message @ " . basename( (string) $file ) . ":$line";
			}

			return false;
		}
	);

	require_once pqbg_test_wp_load_path();

	if ( 'production' === wp_get_environment_type() && '1' !== getenv( 'PQBG_TESTS_ALLOW_PRODUCTION' ) ) {
		fwrite( STDERR, "Refusing to run: this site reports environment type 'production'.\nThe suites create and delete test data. On a development copy, set define( 'WP_ENVIRONMENT_TYPE', 'local' ) in wp-config.php (preferred) or PQBG_TESTS_ALLOW_PRODUCTION=1.\n" );
		exit( 2 );
	}
}

/**
 * Records one check.
 *
 * @param string $name  Check name.
 * @param bool   $ok    Result.
 * @param string $extra Detail printed next to the result.
 */
function pqbg_t( string $name, bool $ok, string $extra = '' ): void {
	$ok ? $GLOBALS['pqbg_test_pass']++ : $GLOBALS['pqbg_test_fail']++;
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$name}" . ( '' !== $extra ? "  [{$extra}]" : '' ) . "\n";
}

/**
 * Records a check that could not run (a missing optional tool). Neither passes nor fails.
 *
 * @param string $name   Check name.
 * @param string $reason Why it was skipped and how to enable it.
 */
function pqbg_skip( string $name, string $reason ): void {
	$GLOBALS['pqbg_test_skip']++;
	echo "SKIP  {$name}  [{$reason}]
";
}

/**
 * Prints a section heading.
 *
 * @param string $title Section name.
 */
function pqbg_section( string $title ): void {
	echo "== {$title} ==\n";
}

/**
 * Adds the "no PHP notices from plugin code" check, prints the result line and exits.
 */
function pqbg_test_done(): void {
	pqbg_t( 'no PHP notices, warnings or deprecations from plugin code', array() === $GLOBALS['pqbg_test_errors'], implode( ' | ', $GLOBALS['pqbg_test_errors'] ) );

	$skipped = $GLOBALS['pqbg_test_skip'] > 0 ? ", {$GLOBALS['pqbg_test_skip']} skipped" : '';

	echo "\nRESULT: {$GLOBALS['pqbg_test_pass']} passed, {$GLOBALS['pqbg_test_fail']} failed{$skipped}\n";
	exit( 0 === $GLOBALS['pqbg_test_fail'] ? 0 : 1 );
}

/**
 * Plugin basename as WordPress stores it in active_plugins.
 */
function pqbg_test_plugin_basename(): string {
	return 'product-qrcode-barcode-generator/product-qrcode-barcode-generator.php';
}
