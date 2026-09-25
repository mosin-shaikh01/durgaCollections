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
 * High-water marks for Action Scheduler cleanup: the highest action, log, post and
 * order IDs when a suite starts. See pqbg_test_as_cleanup().
 *
 * @return array{action: int, log: int, post: int, order: int}
 */
function pqbg_test_as_mark(): array {
	global $wpdb;

	$max = static fn( string $table, string $col ): int => (int) $wpdb->get_var( "SELECT COALESCE(MAX($col), 0) FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifiers.

	return array(
		'action' => $max( $wpdb->prefix . 'actionscheduler_actions', 'action_id' ),
		'log'    => $max( $wpdb->prefix . 'actionscheduler_logs', 'log_id' ),
		'post'   => $max( $wpdb->posts, 'ID' ),
		'order'  => $max( $wpdb->prefix . 'wc_orders', 'id' ),
	);
}

/**
 * Whether an action's JSON args reference an ID allocated after the mark.
 *
 * Every post (and HPOS order) ID handed out since the mark counts, INCLUDING IDs of
 * posts the suite has already deleted: matching only posts that still exist missed
 * the jobs of products deleted mid-suite (the Phase 5 leak found in Phase 8).
 *
 * @param string $args JSON args of an action.
 * @param array  $mark From pqbg_test_as_mark().
 * @param int    $post_end Highest post ID allocated so far.
 * @param int    $order_end Highest order ID allocated so far.
 */
function pqbg_test_as_references( string $args, array $mark, int $post_end, int $order_end ): bool {
	preg_match_all( '/\d+/', $args, $m );

	foreach ( $m[0] as $n ) {
		$n = (int) $n;
		if ( ( $n > $mark['post'] && $n <= $post_end ) || ( $n > $mark['order'] && $n <= $order_end ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Highest post and order IDs ever allocated: AUTO_INCREMENT - 1, so deleted rows count too.
 *
 * @return array{0: int, 1: int}
 */
function pqbg_test_id_ends(): array {
	global $wpdb;

	$auto = static fn( string $table ): int => max( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) ) - 1 );

	return array( $auto( $wpdb->posts ), $auto( $wpdb->prefix . 'wc_orders' ) );
}

/**
 * Action Scheduler rows left behind since the mark: new actions that reference test IDs,
 * and new log rows whose action no longer exists.
 *
 * @param array $mark From pqbg_test_as_mark().
 * @return array{actions: array<int, array<string, string>>, orphan_logs: int}
 */
function pqbg_test_as_leftovers( array $mark ): array {
	global $wpdb;

	[ $post_end, $order_end ] = pqbg_test_id_ends();
	$actions = $wpdb->get_results( $wpdb->prepare( "SELECT action_id, hook, status, args FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id > %d", $mark['action'] ), ARRAY_A );
	$orphans = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_logs l LEFT JOIN {$wpdb->prefix}actionscheduler_actions a ON a.action_id = l.action_id WHERE l.log_id > %d AND a.action_id IS NULL", $mark['log'] ) );

	return array(
		'actions'     => array_values( array_filter( $actions, static fn( $a ) => pqbg_test_as_references( (string) $a['args'], $mark, $post_end, $order_end ) ) ),
		'orphan_logs' => $orphans,
	);
}

/**
 * Removes every Action Scheduler job the suite caused, and their logs.
 *
 * WooCommerce schedules product-lookup jobs on every product save and delete, and an
 * Apache queue runner (started by the suites' own HTTP requests) may be running them
 * concurrently. So: wait (up to 15 s) until no job for a test ID is claimed or in
 * progress, delete them all (Action Scheduler deletes their logs with them), then
 * delete any new log rows whose action is gone.
 *
 * @param array $mark From pqbg_test_as_mark().
 * @return int Number of jobs removed.
 */
function pqbg_test_as_cleanup( array $mark ): int {
	global $wpdb;

	$deadline = microtime( true ) + 15;

	do {
		[ $post_end, $order_end ] = pqbg_test_id_ends();
		$busy = array_filter(
			$wpdb->get_results( $wpdb->prepare( "SELECT args FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id > %d AND ( status = 'in-progress' OR ( status = 'pending' AND claim_id <> 0 ) )", $mark['action'] ), ARRAY_A ),
			static fn( $a ) => pqbg_test_as_references( (string) $a['args'], $mark, $post_end, $order_end )
		);
		if ( array() === $busy ) {
			break;
		}
		usleep( 250000 );
	} while ( microtime( true ) < $deadline );

	$removed = 0;

	foreach ( pqbg_test_as_leftovers( $mark )['actions'] as $a ) {
		try {
			ActionScheduler::store()->delete_action( (int) $a['action_id'] );
			++$removed;
		} catch ( InvalidArgumentException $e ) {
			// Already deleted by another process.
		}
	}

	$wpdb->query( $wpdb->prepare( "DELETE l FROM {$wpdb->prefix}actionscheduler_logs l LEFT JOIN {$wpdb->prefix}actionscheduler_actions a ON a.action_id = l.action_id WHERE l.log_id > %d AND a.action_id IS NULL", $mark['log'] ) );

	return $removed;
}

/**
 * The standard zero-leak check, run at the end of a suite's cleanup.
 *
 * @param array $mark From pqbg_test_as_mark().
 */
function pqbg_test_as_check( array $mark ): void {
	$left = pqbg_test_as_leftovers( $mark );

	pqbg_t( 'zero Action Scheduler jobs or logs left for test data', array() === $left['actions'] && 0 === $left['orphan_logs'], count( $left['actions'] ) . ' job(s), ' . $left['orphan_logs'] . ' orphan log(s)' );
}

/**
 * Plugin basename as WordPress stores it in active_plugins.
 */
function pqbg_test_plugin_basename(): string {
	return 'product-qrcode-barcode-generator/product-qrcode-barcode-generator.php';
}
