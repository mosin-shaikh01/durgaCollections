<?php
/**
 * Phase 13 suite: release checks (QA, documentation and packaging). Reads files only; creates no
 * data (the Action Scheduler guard still runs).
 *
 * - the version (header, PQBG_VERSION, readme.txt, CHANGELOG.md, the .pot) and the requirement
 *   fields (header, readme.txt, Requirements) agree; "Tested up to" matches this site's versions;
 * - licences: the header, LICENSE (GPL-2.0), every bundled library's licence file, the GPL-3.0 text
 *   next to the LGPL-3.0 library (the same bytes as build/licenses/, the SHA-256 pinned in
 *   build/build.php), NOTICE.md, and composer.lock holding exactly the bundled packages;
 * - code: a direct-access guard in every runtime PHP file, a silence index.php in every runtime
 *   folder, no debug calls, no TODO/FIXME, every translation call with the plugin's text domain;
 * - the .pot is up to date (regenerated with WP-CLI into a temporary file; the same strings);
 * - the guides: the user manual (1.0.1; replaces the owner guide), the built seller guide
 *   (self-contained, four screenshots, the current version) and its one-page PDF;
 * - the package: build/package.php --dry-run lists the runtime files only and passes its own checks;
 * - no shipped text file names the shop (this site's name).
 *
 * Needs PQBG_WPCLI (wp-cli.phar, kept outside the site) for the .pot check.
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/bootstrap.php';
pqbg_test_load_wp();
require_once ABSPATH . 'wp-admin/includes/plugin.php';

use ProductQrBarcode\Requirements;

$dir  = dirname( __DIR__ );
$read = static fn( string $rel ): string => is_file( $dir . '/' . $rel ) ? (string) file_get_contents( $dir . '/' . $rel ) : '';
$mark = pqbg_test_as_mark();

// Runtime files: what git knows (tracked and new, not ignored), outside tests/ and build/.
$git     = shell_exec( 'git -C ' . escapeshellarg( $dir ) . ' ls-files --cached --others --exclude-standard -- . 2>&1' );
$all     = array_values( array_filter( explode( "\n", str_replace( '\\', '/', (string) $git ) ), static fn( $p ) => '' !== $p && is_file( $dir . '/' . $p ) ) );
$runtime = array_values( array_filter( $all, static fn( $p ) => ! preg_match( '#^(tests|build)/#', $p ) ) );
$own     = array_values( array_filter( $runtime, static fn( $p ) => ! str_starts_with( $p, 'vendor-prefixed/' ) ) );

try {
	pqbg_section( 'version and requirements' );
	$main   = $read( 'product-qrcode-barcode-generator.php' );
	$header = get_plugin_data( PQBG_PLUGIN_FILE, false, false );
	$readme = $read( 'readme.txt' );
	$field  = static fn( string $text, string $name ): string => preg_match( '/^' . preg_quote( $name, '/' ) . ':\s*(.+?)\s*$/m', $text, $m ) ? $m[1] : '';
	$hfield = static fn( string $name ): string => preg_match( '/^\s*\*\s*' . preg_quote( $name, '/' ) . ':\s*(.+?)\s*$/m', $main, $m ) ? $m[1] : '';
	$change = preg_match( '/^## (\d+\.\d+\.\d+) \((\d{4}-\d{2}-\d{2})\)/m', $read( 'CHANGELOG.md' ), $cm ) ? $cm : array( '', '', '' );
	$pot    = $read( 'languages/product-qrcode-barcode-generator.pot' );
	// 1.0.1: one version everywhere, and it is the top CHANGELOG entry (no longer a fixed number).
	pqbg_t( 'the version (' . PQBG_VERSION . ') is the same in the header, PQBG_VERSION, readme.txt (Stable tag), CHANGELOG.md (top entry) and the .pot', preg_match( '/^\d+\.\d+\.\d+$/', PQBG_VERSION ) && PQBG_VERSION === $header['Version'] && PQBG_VERSION === $field( $readme, 'Stable tag' ) && PQBG_VERSION === $change[1] && str_contains( $pot, '"Project-Id-Version: Product QR Code and Barcode Generator ' . PQBG_VERSION . '\n"' ), wp_json_encode( array( $header['Version'], PQBG_VERSION, $field( $readme, 'Stable tag' ), $change[1] ) ) );
	pqbg_t( 'readme.txt has a changelog entry for this version', str_contains( $readme, '= ' . PQBG_VERSION . ' =' ) );
	pqbg_t( 'the CHANGELOG entry has a date', '' !== $change[2] );
	pqbg_t( 'the minimums agree: header, readme.txt and Requirements (WordPress 6.7, PHP 8.2, WooCommerce 9.0)', Requirements::MIN_WP === $header['RequiresWP'] && Requirements::MIN_PHP === $header['RequiresPHP'] && Requirements::MIN_WP === $field( $readme, 'Requires at least' ) && Requirements::MIN_PHP === $field( $readme, 'Requires PHP' ) && Requirements::MIN_WC === $hfield( 'WC requires at least' ) );
	$wp_mm = implode( '.', array_slice( explode( '.', get_bloginfo( 'version' ) ), 0, 2 ) );
	$wc_mm = implode( '.', array_slice( explode( '.', (string) WC()->version ), 0, 2 ) );
	pqbg_t( '"Tested up to" (readme.txt) and "WC tested up to" (header) are the versions this site runs', $wp_mm === $field( $readme, 'Tested up to' ) && $wc_mm === $hfield( 'WC tested up to' ), "WordPress {$wp_mm}, WooCommerce {$wc_mm}" );
	pqbg_t( 'the header keeps Requires Plugins: woocommerce and Update URI: false', 'woocommerce' === $hfield( 'Requires Plugins' ) && 'false' === $hfield( 'Update URI' ) );

	pqbg_section( 'licences' );
	$license = $read( 'LICENSE' );
	pqbg_t( 'header and readme.txt: GPL-2.0-or-later with the GPL-2.0 License URI', 'GPL-2.0-or-later' === $hfield( 'License' ) && 'https://www.gnu.org/licenses/gpl-2.0.html' === $hfield( 'License URI' ) && 'GPL-2.0-or-later' === $field( $readme, 'License' ) && 'https://www.gnu.org/licenses/gpl-2.0.html' === $field( $readme, 'License URI' ) );
	pqbg_t( 'LICENSE is the GNU GPL version 2 text', str_contains( $license, 'GNU GENERAL PUBLIC LICENSE' ) && str_contains( $license, 'Version 2, June 1991' ) && str_contains( $license, 'END OF TERMS AND CONDITIONS' ) );
	$build = $read( 'build/build.php' );
	$gpl3  = $read( 'vendor-prefixed/picqer/php-barcode-generator/GPL-3.0.txt' );
	$pin   = preg_match( "/'sha256' => '([0-9a-f]{64})'/", $build, $pm ) ? $pm[1] : '';
	pqbg_t( 'the GPL-3.0 text ships next to the LGPL-3.0 library: the same bytes as build/licenses/gpl-3.0.txt, the SHA-256 pinned in build/build.php', '' !== $gpl3 && $gpl3 === $read( 'build/licenses/gpl-3.0.txt' ) && hash( 'sha256', $gpl3 ) === $pin && str_contains( $gpl3, 'Version 3, 29 June 2007' ), $pin );
	$lock     = json_decode( $read( 'build/composer.lock' ), true );
	$packages = array_column( $lock['packages'] ?? array(), 'license', 'name' );
	ksort( $packages );
	pqbg_t( 'composer.lock bundles exactly bacon/bacon-qr-code, dasprid/enum (its only dependency) and picqer/php-barcode-generator, no dev packages', array( 'bacon/bacon-qr-code', 'dasprid/enum', 'picqer/php-barcode-generator' ) === array_keys( $packages ) && array() === ( $lock['packages-dev'] ?? array() ) );
	$files = array(
		'bacon/bacon-qr-code/LICENSE'                 => array( 'BSD-2-Clause', 'Redistribution and use in source and binary forms' ),
		'dasprid/enum/LICENSE'                        => array( 'BSD-2-Clause', 'Redistribution and use in source and binary forms' ),
		'picqer/php-barcode-generator/LICENSE.md'     => array( 'LGPL-3.0-or-later', 'GNU LESSER GENERAL PUBLIC LICENSE' ),
	);
	$bad = array();
	foreach ( $files as $file => [ $spdx, $text ] ) {
		$package = implode( '/', array_slice( explode( '/', $file ), 0, 2 ) );
		if ( ! str_contains( $read( 'vendor-prefixed/' . $file ), $text ) || array( $spdx ) !== ( $packages[ $package ] ?? null ) ) {
			$bad[] = $file;
		}
	}
	pqbg_t( 'every bundled library ships its own licence file, matching the licence composer.lock declares', array() === $bad, implode( ', ', $bad ) );
	$notice = $read( 'vendor-prefixed/NOTICE.md' );
	pqbg_t( 'NOTICE.md lists the three libraries, the modifications and every licence file', str_contains( $notice, '`bacon/bacon-qr-code` | v3.1.1' ) && str_contains( $notice, '`dasprid/enum` | 1.0.7' ) && str_contains( $notice, '`picqer/php-barcode-generator` | v3.3.0' ) && str_contains( $notice, '**Modifications.**' ) && str_contains( $notice, 'GPL-3.0.txt' ) && str_contains( $notice, 'LICENSE.md' ) );

	pqbg_section( 'code' );
	$php     = array_values( array_filter( $runtime, static fn( $p ) => str_ends_with( $p, '.php' ) ) );
	$unguard = array_values( array_filter( $php, static fn( $p ) => ! str_contains( $read( $p ), "defined( 'ABSPATH' ) || exit" ) && ! str_contains( $read( $p ), "defined( 'WP_UNINSTALL_PLUGIN' ) || exit" ) && ! preg_match( '/^<\?php\s*\/\/ Silence is golden\.\s*$/', $read( $p ) ) ) );
	pqbg_t( 'every runtime PHP file has a direct-access guard (or is a silence file)', array() === $unguard && count( $php ) > 200, count( $php ) . ' files ' . implode( ', ', $unguard ) );
	$folders = array( '.' => true );
	foreach ( $runtime as $p ) {
		for ( $d = dirname( $p ); '.' !== $d; $d = dirname( $d ) ) {
			$folders[ $d ] = true;
		}
	}
	$silent = array_keys( array_filter( $folders, static fn( $v, $d ) => ! is_file( $dir . '/' . ( '.' === $d ? '' : $d . '/' ) . 'index.php' ), ARRAY_FILTER_USE_BOTH ) );
	pqbg_t( 'every runtime folder has a silence index.php', array() === $silent, implode( ', ', $silent ) );
	$debug = array();
	$todo  = array();
	$i18n  = array();
	$funcs = array( '__', '_e', '_x', '_ex', '_n', '_nx', '_n_noop', '_nx_noop', 'esc_html__', 'esc_html_e', 'esc_html_x', 'esc_attr__', 'esc_attr_e', 'esc_attr_x' );
	foreach ( array_filter( $own, static fn( $p ) => str_ends_with( $p, '.php' ) ) as $p ) {
		$tokens = token_get_all( $read( $p ) );
		foreach ( $tokens as $k => $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			if ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) && preg_match( '/\b(TODO|FIXME|XXX(?!X)|HACK)\b/', str_replace( 'XXXX', '', $t[1] ) ) ) {
				$todo[] = "$p:{$t[2]}";
			}
			if ( T_STRING !== $t[0] ) {
				continue;
			}
			$next = $tokens[ $k + 1 ] ?? '';
			$prev = $tokens[ $k - 1 ] ?? '';
			$call = '(' === $next && ! ( is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR ), true ) );
			if ( $call && in_array( strtolower( $t[1] ), array( 'var_dump', 'print_r', 'var_export', 'error_log', 'debug_backtrace', 'debug_print_backtrace', 'debug_zval_dump' ), true ) ) {
				$debug[] = "$p:{$t[2]} {$t[1]}";
			}
			if ( $call && in_array( $t[1], $funcs, true ) ) {
				// The last string literal at depth 1 of the call is the text domain.
				$depth = 0;
				$last  = null;
				for ( $j = $k + 1, $n = count( $tokens ); $j < $n; $j++ ) {
					$u = $tokens[ $j ];
					if ( '(' === $u || '[' === $u ) {
						++$depth;
					} elseif ( ')' === $u || ']' === $u ) {
						if ( 0 === --$depth ) {
							break;
						}
					} elseif ( 1 === $depth && is_array( $u ) && T_CONSTANT_ENCAPSED_STRING === $u[0] ) {
						$last = $u[1];
					} elseif ( 1 === $depth && ',' === $u ) {
						$last = null;
					}
				}
				if ( "'product-qrcode-barcode-generator'" !== $last ) {
					$i18n[] = "$p:{$t[2]} {$t[1]}";
				}
			}
		}
	}
	foreach ( array_filter( $own, static fn( $p ) => str_ends_with( $p, '.js' ) ) as $p ) {
		if ( preg_match_all( '/\bconsole\.\w+\s*\(|\bdebugger\b|\balert\s*\(/', $read( $p ), $m ) ) {
			$debug[] = "$p: " . implode( ' ', $m[0] );
		}
	}
	pqbg_t( 'no debug calls in runtime code (var_dump, print_r, var_export, error_log, debug_backtrace; console.*, debugger, alert in JS)', array() === $debug, implode( ', ', $debug ) );
	pqbg_t( 'no TODO, FIXME, XXX or HACK comment in runtime code', array() === $todo, implode( ', ', $todo ) );
	pqbg_t( 'every translation call uses the text domain product-qrcode-barcode-generator (last argument, a literal)', array() === $i18n, implode( ', ', array_slice( $i18n, 0, 10 ) ) );

	pqbg_section( 'translation template' );
	$wpcli = (string) getenv( 'PQBG_WPCLI' );
	if ( '' === $wpcli || ! is_file( $wpcli ) ) {
		pqbg_skip( 'the .pot is up to date', 'set PQBG_WPCLI to wp-cli.phar (see tests/README.md)' );
	} else {
		$tmp = get_temp_dir() . 'pqbg-pot-' . wp_generate_password( 8, false ) . '.pot';
		$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $wpcli ) . ' i18n make-pot ' . escapeshellarg( $dir ) . ' ' . escapeshellarg( $tmp ) . ' --slug=product-qrcode-barcode-generator --domain=product-qrcode-barcode-generator --exclude=tests,build,vendor-prefixed,node_modules,docs --skip-audit 2>&1';
		$log = (string) shell_exec( $cmd );
		$ids = static function ( string $pot ): array {
			preg_match_all( '/^(?:msgctxt "((?:[^"\\\\]|\\\\.)*)"\n)?msgid "((?:[^"\\\\]|\\\\.)*)"(?:\n"(?:[^"\\\\]|\\\\.)*")*/m', $pot, $m, PREG_SET_ORDER );
			$out = array_map( static fn( $x ) => $x[0], $m );
			sort( $out );
			return $out;
		};
		$fresh = (string) file_get_contents( $tmp );
		unlink( $tmp );
		pqbg_t( 'the .pot is up to date: regenerating it gives the same strings, contexts and plurals', '' !== $fresh && $ids( $fresh ) === $ids( $pot ), count( $ids( $pot ) ) . ' entries; ' . trim( substr( $log, 0, 200 ) ) );
		pqbg_t( 'the .pot has no personal e-mail address (Report-Msgid-Bugs-To and Language-Team empty)', str_contains( $pot, '"Report-Msgid-Bugs-To: \n"' ) && str_contains( $pot, '"Language-Team: \n"' ) && ! preg_match( '/[A-Za-z0-9._%+-]+@(?!ADDRESS)[A-Za-z0-9.-]+\.[a-z]{2,}/', $pot ) );
	}

	pqbg_section( 'guides' );
	// 1.0.1: the user manual replaces the owner guide (its full checks are in phase14-manual).
	$manual = $read( 'docs/user-manual.md' );
	pqbg_t( 'docs/user-manual.md (14 numbered chapters) and docs/user-manual.pdf (a PDF of at most 4 MB) replace docs/owner-guide.md', 14 === preg_match_all( '/^## \d+\. /m', $manual ) && str_starts_with( $read( 'docs/user-manual.pdf' ), '%PDF-' ) && strlen( $read( 'docs/user-manual.pdf' ) ) <= 4 * 1024 * 1024 && ! file_exists( $dir . '/docs/owner-guide.md' ) );
	$guide = $read( 'docs/seller-guide.html' );
	pqbg_t( 'docs/seller-guide.html: built (no placeholder), the current version, four inlined PNG screenshots, self-contained (no external src or href)', '' !== $guide && ! str_contains( $guide, '{{' ) && str_contains( $guide, 'Product QR Code and Barcode Generator ' . PQBG_VERSION ) && 8 === substr_count( $guide, 'src="data:image/png;base64,' ) && ! preg_match( '/\b(src|href)="(?!data:|#)/', $guide ) && ! preg_match( '/@import|url\(\s*[\'"]?https?:/i', $guide ) );
	$tpl = $read( 'tests/guide/seller-guide.template.html' );
	pqbg_t( 'the built seller guide matches its template (tests/guide/)', preg_replace( '/data:image\/png;base64,[A-Za-z0-9+\/=]+/', '{{img}}', $guide ) === str_replace( array( '{{version}}', '{{scan}}', '{{sell}}', '{{undo}}', '{{mysales}}' ), array( PQBG_VERSION, '{{img}}', '{{img}}', '{{img}}', '{{img}}' ), $tpl ) );
	$pdf = $read( 'docs/seller-guide.pdf' );
	pqbg_t( 'docs/seller-guide.pdf: a PDF of one page', str_starts_with( $pdf, '%PDF-' ) && 1 === preg_match_all( '/\/Type\s*\/Page[^s]/', $pdf ) );

	pqbg_section( 'package' );
	$dry = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $dir . '/build/package.php' ) . ' --dry-run --allow-dirty 2>&1' );
	preg_match_all( '/^\s*[\d,]+\s+product-qrcode-barcode-generator\/(\S+)$/m', $dry, $pm );
	$listed = $pm[1];
	pqbg_t( 'build/package.php --dry-run passes its own checks (required files, versions, guards, silence files)', str_contains( $dry, 'Dry run: no zip written.' ) && str_contains( $dry, 'Version: ' . PQBG_VERSION ), trim( substr( $dry, 0, 300 ) ) );
	$dev = array_values( array_filter( $listed, static fn( $p ) => (bool) preg_match( '#(^|/)(tests|build|node_modules)/|(^|/)(package(-lock)?\.json|composer\.(json|lock)|\.git\w*|\.htaccess)$|\.(patch|log|sql|zip)$#', $p ) ) );
	pqbg_t( 'the package holds no development file (tests/, build/, node_modules, package or composer files, .git*, .htaccess, patches, logs, dumps)', array() === $dev, implode( ', ', $dev ) );
	sort( $listed );
	$expected = $runtime;
	sort( $expected );
	pqbg_t( 'the package holds exactly the runtime files (everything outside tests/ and build/)', $listed === $expected, count( $listed ) . ' files; missing: ' . implode( ', ', array_diff( $expected, $listed ) ) . '; extra: ' . implode( ', ', array_diff( $listed, $expected ) ) );
	pqbg_t( 'the package holds the user manual (PDF and source) and the seller guide, not the old owner guide', array() === array_diff( array( 'docs/user-manual.pdf', 'docs/user-manual.md', 'docs/seller-guide.pdf', 'docs/seller-guide.html' ), $listed ) && ! in_array( 'docs/owner-guide.md', $listed, true ) );
	// 1.0.1: a real test zip (from the working tree, into a temporary folder) stays within the 6 MB upload-friendly limit.
	$zip_dir = get_temp_dir() . 'pqbg-zip-' . wp_generate_password( 8, false );
	$zip_out = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d extension=zip ' . escapeshellarg( $dir . '/build/package.php' ) . ' --allow-dirty --out=' . escapeshellarg( $zip_dir ) . ' 2>&1' );
	$zip_sz  = preg_match( '/^Size: ([\d,]+) bytes$/m', $zip_out, $zm ) ? (int) str_replace( ',', '', $zm[1] ) : 0;
	// package.php re-reads every entry from the written zip and compares it byte for byte before listing it.
	$zip_has = (bool) preg_match( '/^\s*' . preg_quote( number_format( strlen( $read( 'docs/user-manual.pdf' ) ) ), '/' ) . '\s+product-qrcode-barcode-generator\/docs\/user-manual\.pdf$/m', $zip_out );
	array_map( 'unlink', glob( $zip_dir . '/*' ) ?: array() );
	@rmdir( $zip_dir );
	pqbg_t( 'a test zip is at most 6 MB and contains docs/user-manual.pdf', $zip_sz > 0 && $zip_sz <= 6 * 1024 * 1024 && $zip_has, number_format( $zip_sz ) . ' bytes' . ( $zip_sz ? '' : ': ' . trim( substr( $zip_out, -300 ) ) ) );

	pqbg_section( 'no shop name' );
	$shop   = trim( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	$naming = array();
	foreach ( $runtime as $p ) {
		if ( preg_match( '/\.(pdf|png|jpe?g|gif|webp|ico)$/i', $p ) ) {
			continue;
		}
		$text = preg_replace( '/data:image\/[a-z]+;base64,[A-Za-z0-9+\/=]+/', '', $read( $p ) );
		if ( '' !== $shop && false !== stripos( (string) $text, $shop ) ) {
			$naming[] = $p;
		}
	}
	// The screenshots are images (checked by eye; tests/guide/guide-shots.mjs shows "Your shop" in their header).
	pqbg_t( 'no shipped text file names this shop (the site title)', '' !== $shop && array() === $naming, implode( ', ', $naming ) );
} finally {
	pqbg_section( 'cleanup' );
	pqbg_test_as_check( $mark );
}

pqbg_test_done();
