<?php
/**
 * Runs every suite, each in its own PHP process, and prints a summary.
 *
 *   php tests/run.php                 all suites
 *   php tests/run.php phase3 phase4   only suites whose file name contains one of the arguments
 *
 * Each suite is followed by the Action Scheduler leak guard (as-guard.php), which runs
 * after the suite's process has exited; a leak fails that suite.
 *
 * Exit code 0 only if every selected suite passed. See tests/README.md.
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

// Order matters only for readability; every suite cleans up after itself.
$suites = array(
	'phase2-main.php',
	'phase2-lifecycle.php',
	'phase2-no-woocommerce.php',
	'phase3-codes.php',
	'phase4-rendering.php',
	'phase5-admin.php',
	'phase6-scan.php',
	'phase7-sales.php',
	'phase8-printing.php',
	'phase9a-sales-history.php',
);

$filters = array_slice( $argv, 1 );

if ( array() !== $filters ) {
	$suites = array_values( array_filter( $suites, static fn( $s ) => (bool) array_filter( $filters, static fn( $f ) => str_contains( $s, $f ) ) ) );
}

if ( array() === $suites ) {
	fwrite( STDERR, "No suite matches.\n" );
	exit( 2 );
}

$summary = array();
$failed  = false;

foreach ( $suites as $suite ) {
	echo "\n########## {$suite}\n";

	$mark = trim( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/as-guard.php' ) . ' mark' ) );

	$output = array();
	$code   = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/' . $suite ) . ' 2>&1', $output, $code );
	echo implode( "\n", $output ), "\n";

	// After the suite's process (and its PHP shutdown) has ended.
	$guard      = array();
	$guard_code = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/as-guard.php' ) . ' check ' . escapeshellarg( $mark ) . ' 2>&1', $guard, $guard_code );
	echo implode( "\n", $guard ), "\n";

	$result = preg_grep( '/^RESULT: /', $output );
	$line   = array() !== $result ? substr( (string) end( $result ), 8 ) : 'no result line (exit ' . $code . ')';
	$line  .= 0 === $guard_code ? '; AS guard PASS' : '; AS guard FAIL';

	$summary[ $suite ] = $line;
	$failed            = $failed || 0 !== $code || 0 !== $guard_code;
}

echo "\n========== SUMMARY\n";

foreach ( $summary as $suite => $line ) {
	printf( "%-28s %s\n", $suite, $line );
}

echo $failed ? "\nFAILED\n" : "\nALL PASSED\n";
exit( $failed ? 1 : 0 );
