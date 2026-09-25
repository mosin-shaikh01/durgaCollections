<?php
/**
 * Action Scheduler leak guard, run by run.php around every suite process.
 *
 *   php tests/as-guard.php mark                          prints "action,log,post,order" (the highest IDs)
 *   php tests/as-guard.php check <action,log,post,order>  after the suite's process has exited
 *
 * The mark is plain digits and commas because escapeshellarg() on Windows
 * replaces double quotes, which would break JSON.
 *
 * The check runs after the suite's PHP shutdown and waits (up to 20 s) for any
 * Apache queue runner still working on new jobs, so it also catches jobs
 * scheduled after a suite's own cleanup check. It fails on:
 *   - any new job that references an ID allocated during the suite (posts, orders)
 *   - any new job whose hook had no job at all before the suite
 *   - any new log row whose action no longer exists
 *
 * New jobs of hooks that already existed before the suite and reference no test ID
 * are the site's own WP-Cron work (fetch_patterns, the Action Scheduler migration
 * hook, WooCommerce cleanups…), triggered by the suites' HTTP requests. They are
 * reported, not failed (Phase 8 decision D9).
 *
 * Prints one line "AS-GUARD: PASS|FAIL …". Exit code 0 on PASS.
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/bootstrap.php';
pqbg_test_load_wp();

global $wpdb;

$mode = $argv[1] ?? '';

if ( 'mark' === $mode ) {
	echo implode( ',', pqbg_test_as_mark() ), "\n";
	exit( 0 );
}

$parts = explode( ',', (string) ( $argv[2] ?? '' ) );

if ( 'check' !== $mode || 4 !== count( $parts ) || array() !== array_filter( $parts, static fn( $p ) => ! ctype_digit( $p ) ) ) {
	fwrite( STDERR, "usage: as-guard.php mark | check <action,log,post,order>\n" );
	exit( 2 );
}

$mark = array_combine( array( 'action', 'log', 'post', 'order' ), array_map( 'intval', $parts ) );
$A    = $wpdb->prefix . 'actionscheduler_actions';
$L    = $wpdb->prefix . 'actionscheduler_logs';

// Let any queue runner that is still busy with new jobs finish first.
$deadline = microtime( true ) + 20;
while ( microtime( true ) < $deadline && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $A WHERE action_id > %d AND ( status = 'in-progress' OR ( status = 'pending' AND claim_id <> 0 ) )", $mark['action'] ) ) > 0 ) {
	usleep( 250000 );
}

[ $post_end, $order_end ] = pqbg_test_id_ends();
$old_hooks = array_flip( $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT hook FROM $A WHERE action_id <= %d", $mark['action'] ) ) );
$new       = $wpdb->get_results( $wpdb->prepare( "SELECT action_id, hook, status, args FROM $A WHERE action_id > %d ORDER BY action_id", $mark['action'] ), ARRAY_A );
$bad       = array();
$site      = array();

foreach ( $new as $a ) {
	if ( pqbg_test_as_references( (string) $a['args'], $mark, $post_end, $order_end ) || ! isset( $old_hooks[ $a['hook'] ] ) ) {
		$bad[] = '#' . $a['action_id'] . ' ' . $a['hook'] . ' ' . $a['args'] . ' (' . $a['status'] . ')';
	} else {
		$site[ $a['hook'] ] = ( $site[ $a['hook'] ] ?? 0 ) + 1;
	}
}

$orphans = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $L l LEFT JOIN $A a ON a.action_id = l.action_id WHERE l.log_id > %d AND a.action_id IS NULL", $mark['log'] ) );
$ok      = array() === $bad && 0 === $orphans;
$site_s  = array() === $site ? 'none' : implode( ', ', array_map( static fn( $h, $n ) => "$h x$n", array_keys( $site ), $site ) );

echo 'AS-GUARD: ' . ( $ok ? 'PASS' : 'FAIL' ) . ' - ' . count( $bad ) . ' leaked job(s), ' . $orphans . ' orphan log(s); site cron (not test data): ' . $site_s . "\n";

foreach ( $bad as $line ) {
	echo "   leaked: $line\n";
}

exit( $ok ? 0 : 1 );
