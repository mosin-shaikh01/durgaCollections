<?php
/**
 * PHP-Scoper patcher: adds a direct-access guard to every scoped library file.
 *
 * The guard goes on the line after the namespace declaration (after
 * declare(strict_types=1) as well, which must stay the first statement), so a
 * direct HTTP request to a vendor file exits before anything runs. The build
 * fails if a file does not have exactly one namespace declaration.
 *
 * Build-time only; never loaded by the plugin.
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

return static function ( string $file_path, string $prefix, string $contents ): string {
	if ( ! str_ends_with( $file_path, '.php' ) ) {
		return $contents;
	}

	$guard = "defined( 'ABSPATH' ) || exit;";

	if ( str_contains( $contents, $guard ) ) {
		return $contents;
	}

	$count = preg_match_all( '/^namespace\s+[A-Za-z0-9_\\\\]+\s*;[ \t]*\R/m', $contents, $matches, PREG_OFFSET_CAPTURE );

	if ( 1 !== $count ) {
		throw new RuntimeException( "Expected exactly one namespace declaration in {$file_path}, found {$count}." );
	}

	$end = $matches[0][0][1] + strlen( $matches[0][0][0] );

	return substr( $contents, 0, $end ) . "\n" . $guard . "\n" . substr( $contents, $end );
};
