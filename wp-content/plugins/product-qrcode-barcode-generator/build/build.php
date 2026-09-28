<?php
/**
 * Rebuilds ../vendor-prefixed/ from the pinned libraries in composer.lock.
 *
 *   php build/build.php
 *
 * Needs, in build/tools/ (not committed, see build/README.md):
 *   composer.phar     Composer 2.10.3
 *   php-scoper.phar   PHP-Scoper 0.18.19
 * Both are checked against the SHA-256 values below before use.
 *
 * Steps:
 *   1. composer install from composer.lock into build/vendor/ (no dev packages, no plugins, no scripts)
 *   2. php-scoper add-prefix into build/scoped/ (namespace prefix and ABSPATH guard, see scoper.inc.php)
 *   3. replace ../vendor-prefixed/ with the scoped src/ trees plus each package's LICENSE
 *      (and the GPL-3.0 text next to the LGPL-3.0 library, from build/licenses/)
 *   4. write NOTICE.md and an index.php stub into every directory
 *   5. verify namespaces and guards
 *
 * Build-time only; never loaded by the plugin.
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

const PQBG_BUILD_TOOLS = array(
	'composer.phar'   => '7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6',
	'php-scoper.phar' => '170fb84bd3390defb30f99f7dc39c9a89d10c29973accc26f31c00abc5b25933',
);

/** Package directory => license file name, relative to build/vendor/. */
const PQBG_BUILD_PACKAGES = array(
	'bacon/bacon-qr-code'          => 'LICENSE',
	'dasprid/enum'                 => 'LICENSE',
	'picqer/php-barcode-generator' => 'LICENSE.md',
);

/*
 * Phase 13: the LGPL-3.0 is a set of additional permissions on top of the GNU GPL v3, and asks
 * that a copy of the GPL v3 accompany the library (LGPL-3.0 section 4(b)). picqer ships only the
 * LGPL text, so the official GPL-3.0 text (https://www.gnu.org/licenses/gpl-3.0.txt, verbatim,
 * kept in build/licenses/) is copied next to it.
 */
const PQBG_BUILD_GPL3 = array(
	'source' => 'licenses/gpl-3.0.txt',
	'sha256' => '3972dc9744f6499f0f9b2dbf76696f2ae7ad8af9b23dde66d6af86c9dfb36986',
	'target' => 'picqer/php-barcode-generator/GPL-3.0.txt',
);

$build_dir  = __DIR__;
$plugin_dir = dirname( __DIR__ );
$target     = $plugin_dir . DIRECTORY_SEPARATOR . 'vendor-prefixed';

/**
 * Prints a message and stops.
 *
 * @param string $message Reason.
 */
function pqbg_build_fail( string $message ): void {
	fwrite( STDERR, "BUILD FAILED: {$message}\n" );
	exit( 1 );
}

/**
 * Runs a command in the build directory and stops on a non-zero exit code.
 *
 * @param string[] $args Command and arguments (not yet escaped).
 */
function pqbg_build_run( array $args ): void {
	$command = implode( ' ', array_map( 'escapeshellarg', $args ) );
	echo "> {$command}\n";
	passthru( $command, $code );

	if ( 0 !== $code ) {
		pqbg_build_fail( "command exited with {$code}" );
	}
}

/**
 * Deletes a directory tree. Only accepts paths inside the plugin directory.
 *
 * @param string $dir        Directory to delete.
 * @param string $plugin_dir Plugin root the directory must be inside.
 */
function pqbg_build_rmdir( string $dir, string $plugin_dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$real = realpath( $dir );
	$root = realpath( $plugin_dir );

	if ( false === $real || false === $root || ! str_starts_with( $real, $root . DIRECTORY_SEPARATOR ) ) {
		pqbg_build_fail( "refusing to delete {$dir}" );
	}

	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );

	foreach ( $items as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}

	rmdir( $real );
}

/**
 * Copies a directory tree.
 *
 * @param string $from Source directory.
 * @param string $to   Destination directory (created).
 */
function pqbg_build_copy( string $from, string $to ): void {
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );

	if ( ! is_dir( $to ) && ! mkdir( $to, 0755, true ) ) {
		pqbg_build_fail( "cannot create {$to}" );
	}

	foreach ( $items as $item ) {
		$dest = $to . DIRECTORY_SEPARATOR . $items->getSubPathname();

		if ( $item->isDir() ) {
			if ( ! is_dir( $dest ) && ! mkdir( $dest, 0755, true ) ) {
				pqbg_build_fail( "cannot create {$dest}" );
			}
		} elseif ( ! copy( $item->getPathname(), $dest ) ) {
			pqbg_build_fail( "cannot copy to {$dest}" );
		}
	}
}

// 1. Tools.
foreach ( PQBG_BUILD_TOOLS as $tool => $sha256 ) {
	$path = $build_dir . '/tools/' . $tool;

	if ( ! is_file( $path ) ) {
		pqbg_build_fail( "missing build/tools/{$tool}; see build/README.md" );
	}

	if ( hash_file( 'sha256', $path ) !== $sha256 ) {
		pqbg_build_fail( "build/tools/{$tool} does not match the pinned SHA-256" );
	}
}

chdir( $build_dir );

// 2. Install exactly what composer.lock pins.
pqbg_build_run( array( PHP_BINARY, 'tools/composer.phar', 'install', '--no-dev', '--no-interaction', '--no-plugins', '--no-scripts', '--no-progress', '--prefer-dist' ) );

// 3. Scope.
pqbg_build_rmdir( $build_dir . '/scoped', $plugin_dir );
pqbg_build_run( array( PHP_BINARY, 'tools/php-scoper.phar', 'add-prefix', '--config=scoper.inc.php', '--output-dir=scoped', '--force', '--no-interaction' ) );

// 4. Replace vendor-prefixed/.
pqbg_build_rmdir( $target, $plugin_dir );

$lock     = json_decode( (string) file_get_contents( $build_dir . '/composer.lock' ), true );
$versions = array();

foreach ( $lock['packages'] ?? array() as $package ) {
	$versions[ $package['name'] ] = array( $package['version'], implode( ' OR ', $package['license'] ?? array() ) );
}

foreach ( PQBG_BUILD_PACKAGES as $package => $license_file ) {
	$scoped_src = $build_dir . '/scoped/' . $package . '/src';

	if ( ! is_dir( $scoped_src ) || ! isset( $versions[ $package ] ) ) {
		pqbg_build_fail( "scoped output or lock entry missing for {$package}" );
	}

	pqbg_build_copy( $scoped_src, $target . '/' . $package . '/src' );

	if ( ! copy( $build_dir . '/vendor/' . $package . '/' . $license_file, $target . '/' . $package . '/' . $license_file ) ) {
		pqbg_build_fail( "cannot copy the license for {$package}" );
	}
}

if ( hash_file( 'sha256', $build_dir . '/' . PQBG_BUILD_GPL3['source'] ) !== PQBG_BUILD_GPL3['sha256'] ) {
	pqbg_build_fail( 'build/' . PQBG_BUILD_GPL3['source'] . ' does not match the pinned SHA-256' );
}

if ( ! copy( $build_dir . '/' . PQBG_BUILD_GPL3['source'], $target . '/' . PQBG_BUILD_GPL3['target'] ) ) {
	pqbg_build_fail( 'cannot copy the GPL-3.0 text' );
}

$notice  = "# Bundled third-party libraries\n\n";
$notice .= "Generated by `build/build.php`. Do not edit by hand; change `build/` and rebuild.\n\n";
$notice .= "| Package | Version | License |\n|---|---|---|\n";

foreach ( array_keys( PQBG_BUILD_PACKAGES ) as $package ) {
	$notice .= "| `{$package}` | {$versions[ $package ][0]} | {$versions[ $package ][1]} |\n";
}

$notice .= "\n**Modifications.** Each package's `src/` was processed with PHP-Scoper 0.18.19: every namespace was moved under ";
$notice .= "`ProductQrBarcode\\Vendor\\`, and the line `defined( 'ABSPATH' ) || exit;` was inserted after each namespace declaration. ";
$notice .= "Nothing else was changed. The original license of each package is kept next to its `src/` directory; ";
$notice .= "`picqer/php-barcode-generator` remains under the GNU LGPL-3.0-or-later.\n";
$notice .= "\n**License files.** `bacon/bacon-qr-code/LICENSE` and `dasprid/enum/LICENSE` (BSD-2-Clause; `dasprid/enum` is the only ";
$notice .= "dependency of `bacon/bacon-qr-code`); `picqer/php-barcode-generator/LICENSE.md` (GNU LGPL-3.0) and, because the LGPL-3.0 ";
$notice .= "adds permissions to the GNU GPL v3 and asks for a copy of it, `picqer/php-barcode-generator/GPL-3.0.txt` (the official text ";
$notice .= "from gnu.org, unchanged). Parts of `picqer/php-barcode-generator` derive from the TCPDF barcode classes by Nicola Asuni, ";
$notice .= "also LGPL-3.0, as stated in those files.\n";

file_put_contents( $target . '/NOTICE.md', $notice );

// 5. Silence stubs everywhere.
$dirs = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $target, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
$stub = "<?php\n// Silence is golden.\n";

file_put_contents( $target . '/index.php', $stub );

foreach ( $dirs as $dir ) {
	if ( $dir->isDir() ) {
		file_put_contents( $dir->getPathname() . '/index.php', $stub );
	}
}

// 6. Verify.
$files = 0;

foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $target, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	if ( 'php' !== $file->getExtension() || 'index.php' === $file->getFilename() ) {
		continue;
	}

	$code = (string) file_get_contents( $file->getPathname() );

	if ( ! preg_match( '/^namespace ProductQrBarcode\\\\Vendor\\\\(BaconQrCode|DASPRiD\\\\Enum|Picqer\\\\Barcode)(\\\\|;)/m', $code ) ) {
		pqbg_build_fail( 'unprefixed namespace in ' . $file->getPathname() );
	}

	if ( 1 !== substr_count( $code, "defined( 'ABSPATH' ) || exit;" ) ) {
		pqbg_build_fail( 'missing ABSPATH guard in ' . $file->getPathname() );
	}

	++$files;
}

pqbg_build_rmdir( $build_dir . '/scoped', $plugin_dir );

echo "OK: {$files} scoped library files written to vendor-prefixed/.\n";
