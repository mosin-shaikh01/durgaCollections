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
 * Phase 11:
 *   - a suite stopped by the memory watchdog (PQBG_STOP_FILE) exits 3 and is reported as STOPPED;
 *   - PQBG_ERROR_CAPTURE=<folder> (the temporary must-use logger's folder, outside the web root):
 *     the runner names the running suite in <folder>/ACTIVE and afterwards counts the PHP notices,
 *     warnings, deprecations and fatal errors the logger recorded for it, from plugin code, the
 *     tests, WordPress, WooCommerce and anything else, and lists every event from plugin code.
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
	'phase9b-reports.php',
	'phase10-bulk.php',
	'phase10b-menu.php',
	'phase11-hardening.php',
	'phase12-themes.php',
	'phase13-release.php',
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
$capture = (string) getenv( 'PQBG_ERROR_CAPTURE' );
$capture = '' !== $capture && is_dir( $capture ) ? rtrim( str_replace( '\\', '/', $capture ), '/' ) : '';

/**
 * Counts the error-capture log's events for a suite since a byte offset: per source, and the
 * distinct events from plugin code.
 *
 * @param string $log    Log file.
 * @param int    $offset Byte offset before the suite ran.
 * @param string $suite  Suite file name.
 * @return array{counts: array<string, int>, plugin: string[]}
 */
function pqbg_run_capture( string $log, int $offset, string $suite ): array {
	$plugin = str_replace( '\\', '/', dirname( __DIR__ ) ) . '/';
	$counts = array_fill_keys( array( 'plugin', 'tests', 'WordPress', 'WooCommerce', 'other' ), 0 );
	$list   = array();
	$handle = is_file( $log ) ? fopen( $log, 'rb' ) : false;

	if ( false === $handle ) {
		return array(
			'counts' => $counts,
			'plugin' => $list,
		);
	}

	fseek( $handle, $offset );

	while ( false !== ( $line = fgets( $handle ) ) ) {
		$row = json_decode( $line, true );

		if ( ! is_array( $row ) || ( $row['suite'] ?? '' ) !== $suite ) {
			continue;
		}

		foreach ( (array) $row['events'] as $e ) {
			$file = (string) $e['file'];

			if ( str_starts_with( $file, $plugin . 'tests/' ) ) {
				$src = 'tests';
			} elseif ( str_starts_with( $file, $plugin ) ) {
				$src    = 'plugin';
				$list[] = "[{$e['level']}] {$e['message']} @ " . substr( $file, strlen( $plugin ) ) . ":{$e['line']} ({$row['sapi']} {$row['where']})";
			} elseif ( str_contains( $file, '/wp-content/plugins/woocommerce/' ) ) {
				$src = 'WooCommerce';
			} elseif ( str_contains( $file, '/wp-includes/' ) || str_contains( $file, '/wp-admin/' ) || 1 === preg_match( '#/wp-[a-z-]+\.php$#', $file ) ) {
				$src = 'WordPress';
			} else {
				$src = 'other';
			}

			$counts[ $src ] += (int) $e['count'];
		}
	}

	fclose( $handle );

	return array(
		'counts' => $counts,
		'plugin' => array_values( array_unique( $list ) ),
	);
}

foreach ( $suites as $suite ) {
	echo "\n########## {$suite}\n";

	$mark = trim( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/as-guard.php' ) . ' mark' ) );

	if ( '' !== $capture ) {
		clearstatcache();
		$cap_offset = is_file( $capture . '/capture.log' ) ? (int) filesize( $capture . '/capture.log' ) : 0;
		file_put_contents( $capture . '/ACTIVE', $suite );
	}

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

	if ( 3 === $code && ! str_contains( $line, 'STOPPED' ) ) {
		$line .= '; STOPPED';
	}

	if ( '' !== $capture ) {
		@unlink( $capture . '/ACTIVE' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may not exist.
		$cap    = pqbg_run_capture( $capture . '/capture.log', $cap_offset, $suite );
		$counts = array();
		foreach ( $cap['counts'] as $src => $n ) {
			$counts[] = "{$src} {$n}";
		}
		echo 'error capture: ' . implode( ', ', $counts ) . "\n";
		foreach ( $cap['plugin'] as $event ) {
			echo "  plugin: {$event}\n";
		}
		$line  .= '; plugin notices ' . $cap['counts']['plugin'];
		$failed = $failed || $cap['counts']['plugin'] > 0;
	}

	$summary[ $suite ] = $line;
	$failed            = $failed || 0 !== $code || 0 !== $guard_code;
}

echo "\n========== SUMMARY\n";

foreach ( $summary as $suite => $line ) {
	printf( "%-28s %s\n", $suite, $line );
}

echo $failed ? "\nFAILED\n" : "\nALL PASSED\n";
exit( $failed ? 1 : 0 );
