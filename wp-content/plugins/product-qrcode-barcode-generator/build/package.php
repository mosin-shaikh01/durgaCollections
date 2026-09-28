<?php
/**
 * Builds the release zip (Phase 13).
 *
 *   php -d extension=zip build/package.php --out=<folder> [--allow-dirty] [--dry-run]
 *
 * (XAMPP's php.ini does not load the zip extension; "-d extension=zip" loads it for this run only.)
 *
 * Writes <folder>/product-qrcode-barcode-generator-<version>.zip and a .sha256 file next to it, and
 * prints the file list, the count, the size and the SHA-256.
 *
 * - Only files git knows are packed: the files committed at HEAD, read from git itself (so the
 *   bytes are the committed ones, whatever the checkout's line endings). Untracked or ignored files
 *   (node_modules, build/vendor, build/tools, logs) can never slip in.
 * - Refuses to build when the plugin folder differs from HEAD, unless --allow-dirty is given: then
 *   the working tree is packed, tracked plus untracked-but-not-ignored files (a test build before a
 *   commit; the zip is marked as such in the .sha256 file).
 * - Leaves out the development files (EXCLUDE_DIRS, EXCLUDE_NAMES, EXCLUDE_EXTENSIONS) and refuses to
 *   build when a required file is missing, the version differs between the header, PQBG_VERSION,
 *   readme.txt and CHANGELOG.md, or a PHP file has no direct-access guard.
 * - Reproducible: sorted entries, a fixed timestamp and fixed Unix permissions, so the same files
 *   give a byte-identical zip.
 *
 * Build-time only; never loaded by the plugin, and not part of the zip.
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

const PQBG_PKG_SLUG = 'product-qrcode-barcode-generator';

/** Top-level folders that never ship. */
const PQBG_PKG_EXCLUDE_DIRS = array( 'tests', 'build', 'node_modules', '.git', '.github', '.vscode', '.idea' );

/** File names that never ship, wherever they are. */
const PQBG_PKG_EXCLUDE_NAMES = array( '.gitignore', '.gitattributes', '.editorconfig', '.distignore', '.DS_Store', 'Thumbs.db', 'desktop.ini', 'package.json', 'package-lock.json', 'composer.json', 'composer.lock', 'phpcs.xml', 'phpcs.xml.dist', '.phpcs.xml.dist', 'phpunit.xml', 'phpunit.xml.dist' );

/** Extensions that never ship. */
const PQBG_PKG_EXCLUDE_EXTENSIONS = array( 'patch', 'diff', 'log', 'sql', 'zip', 'bak', 'tmp', 'orig' );

/** Files the zip must contain. */
const PQBG_PKG_REQUIRED = array(
	'product-qrcode-barcode-generator.php',
	'uninstall.php',
	'index.php',
	'LICENSE',
	'readme.txt',
	'README.md',
	'CHANGELOG.md',
	'languages/product-qrcode-barcode-generator.pot',
	'docs/owner-guide.md',
	'docs/seller-guide.html',
	'docs/seller-guide.pdf',
	'vendor-prefixed/NOTICE.md',
	'vendor-prefixed/bacon/bacon-qr-code/LICENSE',
	'vendor-prefixed/dasprid/enum/LICENSE',
	'vendor-prefixed/picqer/php-barcode-generator/LICENSE.md',
	'vendor-prefixed/picqer/php-barcode-generator/GPL-3.0.txt',
);

/** Timestamp of every entry (reproducible builds). */
const PQBG_PKG_MTIME = 1790553600; // 2026-09-28 00:00:00 UTC, the 1.0.0 release date.

/**
 * Prints a message and stops.
 *
 * @param string $message Reason.
 */
function pqbg_pkg_fail( string $message ): void {
	fwrite( STDERR, "PACKAGE FAILED: {$message}\n" );
	exit( 1 );
}

/**
 * Runs git in the plugin folder and returns its output.
 *
 * @param string[] $args Arguments (not yet escaped).
 */
function pqbg_pkg_git( array $args ): string {
	$out  = array();
	$code = 0;
	exec( 'git -C ' . escapeshellarg( dirname( __DIR__ ) ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1', $out, $code );
	if ( 0 !== $code ) {
		pqbg_pkg_fail( 'git ' . implode( ' ', $args ) . ': ' . implode( "\n", $out ) );
	}
	return implode( "\n", $out );
}

/**
 * Whether a path (relative to the plugin folder, forward slashes) is left out of the zip.
 *
 * @param string $path Path.
 */
function pqbg_pkg_excluded( string $path ): bool {
	$parts = explode( '/', $path );
	if ( in_array( $parts[0], PQBG_PKG_EXCLUDE_DIRS, true ) || in_array( 'node_modules', $parts, true ) ) {
		return true;
	}
	$name = end( $parts );
	return in_array( $name, PQBG_PKG_EXCLUDE_NAMES, true ) || in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), PQBG_PKG_EXCLUDE_EXTENSIONS, true );
}

/**
 * Reads the committed contents of several files at HEAD with one `git cat-file --batch`.
 *
 * @param string   $prefix Plugin folder relative to the repository root, with a trailing slash.
 * @param string[] $paths  Paths relative to the plugin folder.
 * @return array<string, string> Path => bytes.
 */
function pqbg_pkg_committed( string $prefix, array $paths ): array {
	$proc = proc_open( array( 'git', '-C', dirname( __DIR__ ), 'cat-file', '--batch' ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
	if ( ! is_resource( $proc ) ) {
		pqbg_pkg_fail( 'cannot run git cat-file' );
	}
	// One request, then its answer: writing every request first deadlocks once git's output pipe
	// is full (git stops reading while nobody reads its output).
	$out = array();
	foreach ( $paths as $path ) {
		fwrite( $pipes[0], 'HEAD:' . $prefix . $path . "\n" );
		fflush( $pipes[0] );
		$header = (string) fgets( $pipes[1] );
		if ( ! preg_match( '/^[0-9a-f]{40} blob (\d+)$/', trim( $header ), $m ) ) {
			pqbg_pkg_fail( "not a committed file: {$path} ({$header})" );
		}
		$size = (int) $m[1];
		$data = '';
		while ( strlen( $data ) < $size && ! feof( $pipes[1] ) ) {
			$data .= (string) fread( $pipes[1], $size - strlen( $data ) );
		}
		fgets( $pipes[1] ); // The newline after the contents.
		$out[ $path ] = $data;
	}
	fclose( $pipes[0] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	proc_close( $proc );
	return $out;
}

// Options.
$opts  = getopt( '', array( 'out:', 'allow-dirty', 'dry-run' ) );
$out   = isset( $opts['out'] ) ? rtrim( (string) $opts['out'], '/\\' ) : '';
$dirty = isset( $opts['allow-dirty'] );
$dry   = isset( $opts['dry-run'] );

if ( '' === $out && ! $dry ) {
	pqbg_pkg_fail( 'give --out=<folder> (outside the repository), or --dry-run' );
}
if ( ! $dry && ! class_exists( 'ZipArchive' ) ) {
	pqbg_pkg_fail( 'the zip extension is not loaded; run with: php -d extension=zip build/package.php …' );
}

$plugin = dirname( __DIR__ );
$prefix = pqbg_pkg_git( array( 'rev-parse', '--show-prefix' ) ); // e.g. wp-content/plugins/<slug>/
$status = pqbg_pkg_git( array( 'status', '--porcelain', '--', '.' ) );
$head   = pqbg_pkg_git( array( 'rev-parse', '--short', 'HEAD' ) );

if ( '' !== trim( $status ) && ! $dirty ) {
	pqbg_pkg_fail( "the plugin folder differs from HEAD ({$head}); commit first, or use --allow-dirty for a test build:\n{$status}" );
}

// The file list.
$listed = $dirty
	? pqbg_pkg_git( array( 'ls-files', '--cached', '--others', '--exclude-standard', '--', '.' ) )
	: pqbg_pkg_git( array( 'ls-files', '--', '.' ) );
$files  = array_values( array_filter( array_unique( explode( "\n", str_replace( '\\', '/', $listed ) ) ), static fn( $p ) => '' !== $p && ! pqbg_pkg_excluded( $p ) && ( ! $GLOBALS['dirty'] || is_file( dirname( __DIR__ ) . '/' . $p ) ) ) );
sort( $files, SORT_STRING );

// The bytes of every file.
$bytes = $dirty ? array_combine( $files, array_map( static fn( $p ) => (string) file_get_contents( $plugin . '/' . $p ), $files ) ) : pqbg_pkg_committed( $prefix, $files );

// Checks.
foreach ( PQBG_PKG_REQUIRED as $required ) {
	if ( ! isset( $bytes[ $required ] ) ) {
		pqbg_pkg_fail( "required file missing: {$required}" );
	}
}

$versions = array(
	'header'       => preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $bytes[ PQBG_PKG_SLUG . '.php' ], $m ) ? $m[1] : '',
	'PQBG_VERSION' => preg_match( "/define\\(\\s*'PQBG_VERSION',\\s*'([^']+)'/", $bytes[ PQBG_PKG_SLUG . '.php' ], $m ) ? $m[1] : '',
	'readme.txt'   => preg_match( '/^Stable tag:\s*(\S+)/m', $bytes['readme.txt'], $m ) ? $m[1] : '',
	'CHANGELOG.md' => preg_match( '/^## (\d+\.\d+\.\d+)/m', $bytes['CHANGELOG.md'], $m ) ? $m[1] : '',
);
if ( 1 !== count( array_unique( $versions ) ) || '' === $versions['header'] ) {
	pqbg_pkg_fail( 'the version differs: ' . json_encode( $versions ) );
}
$version = $versions['header'];

foreach ( $bytes as $path => $data ) {
	if ( str_ends_with( $path, '.php' ) && ! str_contains( $data, "defined( 'ABSPATH' ) || exit" ) && ! str_contains( $data, "defined( 'WP_UNINSTALL_PLUGIN' ) || exit" ) && ! preg_match( '/^<\?php\s*\/\/ Silence is golden\.\s*$/', $data ) ) {
		pqbg_pkg_fail( "no direct-access guard: {$path}" );
	}
}

$folders = array( '' => true );
foreach ( $files as $path ) {
	for ( $dir = dirname( $path ); '.' !== $dir; $dir = dirname( $dir ) ) {
		$folders[ $dir ] = true;
	}
}
foreach ( array_keys( $folders ) as $dir ) {
	if ( ! isset( $bytes[ ( '' === $dir ? '' : $dir . '/' ) . 'index.php' ] ) ) {
		pqbg_pkg_fail( 'no silence index.php in ' . ( '' === $dir ? 'the plugin root' : $dir ) );
	}
}

// Report.
$total = array_sum( array_map( 'strlen', $bytes ) );
echo 'Source: ' . ( $dirty ? "the working tree (--allow-dirty; HEAD is {$head})" : "commit {$head}" ) . "\n";
echo "Version: {$version}\n";
echo 'Files: ' . count( $files ) . ', ' . number_format( $total ) . " bytes uncompressed\n";

if ( $dry ) {
	foreach ( $files as $path ) {
		printf( "%10s  %s/%s\n", number_format( strlen( $bytes[ $path ] ) ), PQBG_PKG_SLUG, $path );
	}
	echo "Dry run: no zip written.\n";
	exit( 0 );
}

// The zip.
if ( ! is_dir( $out ) && ! mkdir( $out, 0755, true ) ) {
	pqbg_pkg_fail( "cannot create {$out}" );
}
$real_out = realpath( $out );
$repo     = realpath( pqbg_pkg_git( array( 'rev-parse', '--show-toplevel' ) ) );
if ( false === $real_out || ( false !== $repo && str_starts_with( strtolower( $real_out . DIRECTORY_SEPARATOR ), strtolower( $repo . DIRECTORY_SEPARATOR ) ) ) ) {
	pqbg_pkg_fail( '--out must be outside the repository' );
}

$zip_path = $real_out . DIRECTORY_SEPARATOR . PQBG_PKG_SLUG . '-' . $version . '.zip';
if ( is_file( $zip_path ) && ! unlink( $zip_path ) ) {
	pqbg_pkg_fail( "cannot replace {$zip_path}" );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::EXCL ) ) {
	pqbg_pkg_fail( "cannot create {$zip_path}" );
}

$dirs = array_keys( $folders );
sort( $dirs, SORT_STRING );
foreach ( $dirs as $dir ) {
	$name = PQBG_PKG_SLUG . '/' . ( '' === $dir ? '' : $dir . '/' );
	$zip->addEmptyDir( rtrim( $name, '/' ) );
	$zip->setMtimeName( $name, PQBG_PKG_MTIME );
	$zip->setExternalAttributesName( $name, ZipArchive::OPSYS_UNIX, ( 040755 << 16 ) );
}
foreach ( $files as $path ) {
	$name = PQBG_PKG_SLUG . '/' . $path;
	$zip->addFromString( $name, $bytes[ $path ] );
	$zip->setCompressionName( $name, ZipArchive::CM_DEFLATE, 9 );
	$zip->setMtimeName( $name, PQBG_PKG_MTIME );
	$zip->setExternalAttributesName( $name, ZipArchive::OPSYS_UNIX, ( 0100644 << 16 ) );
}
if ( ! $zip->close() ) {
	pqbg_pkg_fail( 'cannot write the zip' );
}

// Verify what was written.
$check = new ZipArchive();
$check->open( $zip_path, ZipArchive::RDONLY );
$names = array();
for ( $i = 0; $i < $check->numFiles; $i++ ) {
	$names[] = (string) $check->getNameIndex( $i );
}
foreach ( $files as $path ) {
	if ( $check->getFromName( PQBG_PKG_SLUG . '/' . $path ) !== $bytes[ $path ] ) {
		pqbg_pkg_fail( "verification failed for {$path}" );
	}
}
$check->close();
foreach ( $names as $name ) {
	if ( ! str_starts_with( $name, PQBG_PKG_SLUG . '/' ) || str_contains( $name, '\\' ) || pqbg_pkg_excluded( substr( $name, strlen( PQBG_PKG_SLUG ) + 1 ) ) ) {
		pqbg_pkg_fail( "unexpected entry in the zip: {$name}" );
	}
}

$sha = hash_file( 'sha256', $zip_path );
file_put_contents( $zip_path . '.sha256', $sha . '  ' . basename( $zip_path ) . "\n" . ( $dirty ? "# test build from the working tree (HEAD {$head}); not a release\n" : "# built from commit {$head}\n" ) );

foreach ( $files as $path ) {
	printf( "%10s  %s/%s\n", number_format( strlen( $bytes[ $path ] ) ), PQBG_PKG_SLUG, $path );
}
echo "\nZip: {$zip_path}\n";
echo 'Entries: ' . count( $names ) . ' (' . count( $files ) . ' files, ' . count( $dirs ) . " folders)\n";
echo 'Size: ' . number_format( (int) filesize( $zip_path ) ) . " bytes\n";
echo "SHA-256: {$sha}\n";
