<?php
/**
 * Phase 4 suite: QR code and optional barcode rendering, scan URLs, settings.
 *
 * Round-trip checks rasterise the SVGs and decode them with a real decoder
 * (tests/decoder: resvg + ZXing-C++), so Node.js and `npm ci` in
 * tests/decoder are required; see tests/README.md. PQBG_DECODER may point to
 * another copy of decode.mjs (with its node_modules next to it).
 *
 * HTTP checks log in as temporary users against home_url(), so the site must
 * be reachable from this machine.
 *
 * Temporarily changes pqbg_settings and restores the exact stored value at
 * the end. Creates code rows, three users and a temporary directory outside
 * the web root, and removes them all.
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/bootstrap.php';
pqbg_test_load_wp();
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use ProductQrBarcode\{BarcodeRenderer, CodeGenerator, CodeRepository, Plugin, QrRenderer, Requirements, ScanUrl, Schema, Settings, SettingsPage, Svg};

global $wpdb;

// Must run before anything renders: proves the libraries are loaded lazily.
$vendor_classes = static fn( string $lib ) => array_values( preg_grep( '/^ProductQrBarcode\\\\Vendor\\\\' . preg_quote( $lib, '/' ) . '\\\\/', array_merge( get_declared_classes(), get_declared_interfaces(), get_declared_traits() ) ) );
$bacon_at_start  = count( $vendor_classes( 'BaconQrCode' ) );
$picqer_at_start = count( $vendor_classes( 'Picqer' ) );

$C           = Schema::codes_table();
$option      = Plugin::SETTINGS_OPTION;
$raw_setting = static fn() => $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $option ), ARRAY_A );
$original    = $raw_setting();
$start_id    = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $C" );
$base_c      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" );
$base_user   = (int) count_users()['total_users'];
$tmp         = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pqbg-p4-' . wp_generate_password( 8, false );
$user_ids    = array();
$home        = untrailingslashit( home_url() );
$site_is_up  = null;

/** Replaces the stored settings and clears the object cache so every read sees them. */
$set = static function ( array $values ) use ( $option ) {
	update_option( $option, array_merge( Plugin::default_settings(), $values ), false );
	wp_cache_delete( $option, 'options' );
};
/** Reads the stored option straight from the database (other processes may have written it). */
$stored = static function () use ( $wpdb, $option ) {
	wp_cache_delete( $option, 'options' );
	return maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) ) );
};

$codes = array();
$gen   = new CodeGenerator();
for ( $i = 0; $i < 8; $i++ ) {
	$codes[] = $gen->generate();
}

$qr = new QrRenderer();
$bc = new BarcodeRenderer();

$err = static fn( $r ) => is_wp_error( $r ) ? $r->get_error_code() : 'svg';

// Decoder.
$decoder = getenv( 'PQBG_DECODER' );
$decoder = ( is_string( $decoder ) && '' !== $decoder ) ? $decoder : __DIR__ . '/decoder/decode.mjs';
$decode  = static function ( array $svgs ) use ( $decoder, $tmp ): ?array {
	if ( ! is_dir( $tmp ) ) {
		mkdir( $tmp, 0700, true );
	}
	$files = array();
	foreach ( $svgs as $key => $svg ) {
		$files[ $key ] = $tmp . DIRECTORY_SEPARATOR . 'svg-' . count( $files ) . '.svg';
		file_put_contents( $files[ $key ], $svg );
	}
	$cmd = 'node ' . escapeshellarg( $decoder ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $files ) ) . ' 2>&1';
	$out = shell_exec( $cmd );
	foreach ( $files as $f ) {
		unlink( $f );
	}
	$json = json_decode( (string) $out, true );
	if ( ! is_array( $json ) ) {
		echo "   decoder output: $out\n";
		return null;
	}
	$by_file = array_column( $json, 'results', 'file' );
	$result  = array();
	foreach ( $files as $key => $f ) {
		$result[ $key ] = $by_file[ $f ] ?? array();
	}
	return $result;
};

// HTTP client: one cookie jar (curl handle) per user.
$handles = array();
$http    = static function ( string $who, string $method, string $url, array|string|null $post = null ) use ( &$handles ): array {
	if ( ! isset( $handles[ $who ] ) ) {
		$handles[ $who ] = curl_init();
		curl_setopt( $handles[ $who ], CURLOPT_COOKIEFILE, '' );
	}
	$ch = $handles[ $who ];
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_POST           => 'POST' === $method,
			CURLOPT_HTTPGET        => 'GET' === $method,
		)
	);
	if ( 'POST' === $method ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, is_array( $post ) ? http_build_query( $post ) : (string) $post );
	}
	$raw  = (string) curl_exec( $ch );
	$size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	$head = substr( $raw, 0, $size );
	preg_match( '/^Location:\s*(\S+)/mi', $head, $m );
	return array(
		'code'     => (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ),
		'location' => $m[1] ?? '',
		'body'     => substr( $raw, $size ),
	);
};
$login = static function ( string $who, string $user, string $pass ) use ( $http ): bool {
	$http( $who, 'GET', wp_login_url() );
	$r = $http( $who, 'POST', wp_login_url(), array( 'log' => $user, 'pwd' => $pass, 'wp-submit' => 'Log In', 'testcookie' => '1', 'redirect_to' => admin_url() ) );
	return 302 === $r['code'];
};
$logged_in_cookie = static function ( string $who ) use ( &$handles ): string {
	foreach ( (array) curl_getinfo( $handles[ $who ], CURLINFO_COOKIELIST ) as $line ) {
		$parts = explode( "\t", $line );
		if ( isset( $parts[5] ) && LOGGED_IN_COOKIE === $parts[5] ) {
			return urldecode( $parts[6] );
		}
	}
	return '';
};
$nonce_for = static function ( int $user_id, string $cookie ): string {
	$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
	wp_set_current_user( $user_id );
	$nonce = wp_create_nonce( 'pqbg_settings-options' );
	$valid = 1 === wp_verify_nonce( $nonce, 'pqbg_settings-options' );
	wp_set_current_user( 0 );
	unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
	return $valid ? $nonce : '';
};
$page_url = admin_url( 'admin.php?page=' . SettingsPage::SLUG );
$message  = 'QR codes currently point to a local address. Do not print labels until the production URL is set.';
$https_msg = 'Labels should use an https:// scan URL in production.';

try {
	$set( array() );

	pqbg_section( 'defaults and requirements' );
	pqbg_t( 'libraries not loaded at start (lazy loading)', 0 === $bacon_at_start && 0 === $picqer_at_start );
	pqbg_t( 'default settings (payment_methods since Phase 9A)', array( 'settings_version' => 1, 'barcodes_enabled' => false, 'scan_base_url' => '', 'payment_methods' => array( 'cash', 'upi', 'card' ) ) === Plugin::default_settings() );
	pqbg_t( 'barcodes off by default', false === Settings::is_barcode_enabled() );
	pqbg_t( 'default scan base URL is the site URL', $home === Settings::get_scan_base_url() && ! Settings::has_scan_base_url_override(), Settings::get_scan_base_url() );
	pqbg_t( 'PHP minimum is 8.2 (Requirements and plugin header)', '8.2' === Requirements::MIN_PHP && '8.2' === get_plugin_data( PQBG_PLUGIN_FILE, false, false )['RequiresPHP'] );
	$off = true;
	foreach ( array( '1', 1, 'yes', 'true', array( true ) ) as $not_true ) {
		$set( array( 'barcodes_enabled' => $not_true ) );
		$off = $off && ! Settings::is_barcode_enabled();
	}
	$set( array() );
	pqbg_t( 'only a stored boolean true enables barcodes', $off );

	pqbg_section( 'barcode disabled (default)' );
	$r = $bc->render( $codes[0] );
	pqbg_t( 'disabled: valid code refused with pqbg_barcode_disabled', 'pqbg_barcode_disabled' === $err( $r ) );
	pqbg_t( 'disabled: the setting is checked before the code', 'pqbg_barcode_disabled' === $err( $bc->render( 'not a code' ) ) );
	$q = $qr->render( $codes[0] );
	pqbg_t( 'QR still renders while barcodes are disabled', is_string( $q ) );
	pqbg_t( 'QR library loaded only on use', count( $vendor_classes( 'BaconQrCode' ) ) > 0 );
	pqbg_t( 'no barcode library class loaded while disabled', array() === $vendor_classes( 'Picqer' ) && array() === preg_grep( '/^Picqer\\\\/', get_declared_classes() ) );
	pqbg_t( 'no barcode library file included while disabled', array() === preg_grep( '#[\\\\/]picqer[\\\\/]#i', get_included_files() ) );

	pqbg_section( 'scan URL payload' );
	pqbg_t( 'default: {site}/scan/{CODE}/', ScanUrl::for_code( $codes[0] ) === $home . '/scan/' . $codes[0] . '/', (string) ScanUrl::for_code( $codes[0] ) );
	$bases = array(
		'https://shop.example.com'           => 'https://shop.example.com',
		'https://example.com/store/sub/'     => 'https://example.com/store/sub',
		'http://example.com:8080/'           => 'http://example.com:8080',
		'HTTPS://Example.COM/Path/?q=1#frag' => 'https://example.com/Path',
	);
	foreach ( $bases as $input => $normal ) {
		$set( array( 'scan_base_url' => Settings::validate_base_url( $input ) ) );
		$all = true;
		foreach ( $codes as $code ) {
			$all = $all && ScanUrl::for_code( $code ) === $normal . '/scan/' . $code . '/';
		}
		pqbg_t( "override {$input}: exactly {$normal}/scan/{CODE}/ for 8 codes", $all );
	}
	$set( array() );
	pqbg_t( 'ScanUrl rejects an invalid code', 'pqbg_invalid_code' === $err( ScanUrl::for_code( 'dc-7k4m-9p2x-q8rt' ) ) );
	$builders = array();
	foreach ( glob( PQBG_PLUGIN_DIR . 'includes/*.php' ) as $file ) {
		if ( 'ScanUrl.php' === basename( $file ) ) {
			continue;
		}
		foreach ( token_get_all( (string) file_get_contents( $file ) ) as $tok ) {
			if ( is_array( $tok ) && in_array( $tok[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) && ( preg_match( '#/scan\b#', $tok[1] ) || in_array( $tok[1], array( "'scan'", '"scan"' ), true ) ) ) {
				$builders[] = basename( $file ) . ':' . $tok[2];
			}
		}
	}
	pqbg_t( 'no class other than ScanUrl contains a scan path literal', array() === $builders, implode( ',', $builders ) );

	pqbg_section( 'round-trip decode' );
	// Optional tooling: without Node.js or the decoder install, the round-trip checks are SKIPPED
	// (reported, not failed) and the rest of the suite still runs.
	$node_version = trim( (string) shell_exec( 'node --version 2>&1' ) );
	$missing      = array();
	if ( ! preg_match( '/^v\d+\./', $node_version ) ) {
		$missing[] = 'Node.js not found on PATH';
	}
	if ( ! is_file( $decoder ) || ! is_dir( dirname( $decoder ) . '/node_modules' ) ) {
		$missing[] = 'decoder not installed (run `npm ci` in tests/decoder, or set PQBG_DECODER)';
	}
	$decoder_ready = array() === $missing;
	if ( ! $decoder_ready ) {
		foreach ( array( 'decoder ran', 'QR decodes to exactly the scan URL with EC level M', 'barcode (enabled) decodes to exactly the code', 'negative control: a damaged QR does not decode to the URL', 'negative control: a damaged barcode does not decode to the code' ) as $name ) {
			pqbg_skip( $name, implode( '; ', $missing ) );
		}
	} else {
		echo "   decoder: {$decoder} (node {$node_version})\n";
		$jobs = array();
		foreach ( array( '', 'https://shop.example.com', 'https://durgacollections.example/store' ) as $base ) {
			$set( array( 'scan_base_url' => $base ) );
			foreach ( array_slice( $codes, 0, 4 ) as $code ) {
				$jobs[] = array( 'qr', ScanUrl::for_code( $code ), $qr->render( $code ) );
			}
		}
		$set( array( 'barcodes_enabled' => true ) );
		foreach ( $codes as $code ) {
			$jobs[] = array( 'bc', $code, $bc->render( $code ) );
		}
		// Negative controls: half of each symbol removed.
		$broken_qr = preg_replace_callback( '/ d="([^"]+)"/', fn( $m ) => ' d="' . implode( 'z', array_slice( explode( 'z', $m[1] ), 0, (int) ( substr_count( $m[1], 'z' ) / 2 ) ) ) . 'z"', $jobs[0][2] );
		$broken_bc = preg_replace_callback( '/ d="([^"]+)"/', fn( $m ) => ' d="' . implode( 'z', array_slice( explode( 'z', $m[1] ), 0, (int) ( substr_count( $m[1], 'z' ) / 2 ) ) ) . 'z"', end( $jobs )[2] );
		$svgs      = array_column( $jobs, 2 );
		$svgs[]    = $broken_qr;
		$svgs[]    = $broken_bc;
		$decoded   = $decode( $svgs );
		pqbg_t( 'decoder ran', is_array( $decoded ) );
		$decoded = (array) $decoded;
		$qr_ok   = 0;
		$bc_ok   = 0;
		$qr_n    = 0;
		$bc_n    = 0;
		foreach ( $jobs as $i => $job ) {
			$res = $decoded[ $i ] ?? array();
			if ( 'qr' === $job[0] ) {
				++$qr_n;
				$qr_ok += ( 1 === count( $res ) && 'QRCode' === $res[0]['format'] && $job[1] === $res[0]['text'] && 'M' === $res[0]['ecLevel'] ) ? 1 : 0;
			} else {
				++$bc_n;
				$bc_ok += ( 1 === count( $res ) && 'Code128' === $res[0]['format'] && $job[1] === $res[0]['text'] ) ? 1 : 0;
			}
		}
		pqbg_t( "QR decodes to exactly the scan URL with EC level M ($qr_ok/$qr_n, 3 base URLs)", 12 === $qr_n && $qr_n === $qr_ok );
		pqbg_t( "barcode (enabled) decodes to exactly the code ($bc_ok/$bc_n)", 8 === $bc_n && $bc_n === $bc_ok );
		pqbg_t( 'negative control: a damaged QR does not decode to the URL', ! in_array( $jobs[0][1], array_column( $decoded[ count( $jobs ) ] ?? array(), 'text' ), true ) );
		pqbg_t( 'negative control: a damaged barcode does not decode to the code', ! in_array( end( $jobs )[1], array_column( $decoded[ count( $jobs ) + 1 ] ?? array(), 'text' ), true ) );
	}
	$set( array( 'barcodes_enabled' => true ) );

	pqbg_section( 'geometry' );
	$runs = static function ( string $svg ): array {
		preg_match( '/ d="([^"]+)"/', $svg, $m );
		preg_match_all( '/M(\d+) (\d+)h(\d+)v(\d+)/', $m[1] ?? '', $all, PREG_SET_ORDER );
		return $all;
	};
	$box  = static function ( string $svg ): array {
		preg_match( '/viewBox="0 0 (\d+) (\d+)"/', $svg, $m );
		return array( (int) ( $m[1] ?? 0 ), (int) ( $m[2] ?? 0 ) );
	};
	$q        = $qr->render( $codes[1] );
	$r        = $runs( $q );
	list( $w ) = $box( $q );
	pqbg_t( 'QR quiet zone is exactly 4 modules on every side', 4 === min( array_map( fn( $x ) => (int) $x[1], $r ) ) && 4 === min( array_map( fn( $x ) => (int) $x[2], $r ) ) && $w - 4 === max( array_map( fn( $x ) => $x[1] + $x[3], $r ) ) && $w - 4 === max( array_map( fn( $x ) => $x[2] + 1, $r ) ), "viewBox $w" );
	$b              = $bc->render( $codes[1] );
	$r              = $runs( $b );
	list( $bw, $bh ) = $box( $b );
	pqbg_t( 'barcode quiet zone is exactly 10 modules left and right', 10 === min( array_map( fn( $x ) => (int) $x[1], $r ) ) && $bw - 10 === max( array_map( fn( $x ) => $x[1] + $x[3], $r ) ), "viewBox {$bw}x{$bh}" );
	pqbg_t( 'barcode prints the code beneath the bars', 1 === substr_count( $b, '<text ' ) && str_contains( $b, '>' . $codes[1] . '</text>' ) && preg_match( '/<text x="\d+" y="(\d+)"/', $b, $ty ) && (int) $ty[1] > BarcodeRenderer::TOP_MARGIN + BarcodeRenderer::BAR_HEIGHT );
	pqbg_t( 'QR payload is ASCII scan URL only (no product data)', 1 === preg_match( '#^https?://[\x21-\x7E]+/scan/DC(-[A-HJKMNP-Z2-9]{4}){3}/$#D', (string) ScanUrl::for_code( $codes[1] ) ) );

	pqbg_section( 'invalid codes (barcodes enabled so both renderers reach validation)' );
	$invalid = array(
		'lowercase'          => 'dc-7k4m-9p2x-q8rt',
		'too short'          => 'DC-7K4M-9P2X-Q8R',
		'too long'           => 'DC-7K4M-9P2X-Q8RTT',
		'ambiguous 0'        => 'DC-7K4M-9P2X-Q8R0',
		'ambiguous 1'        => 'DC-7K4M-9P2X-Q8R1',
		'ambiguous I'        => 'DC-7K4M-9P2X-Q8RI',
		'ambiguous L'        => 'DC-7K4M-9P2X-Q8RL',
		'ambiguous O'        => 'DC-7K4M-9P2X-Q8RO',
		'trailing newline'   => "DC-7K4M-9P2X-Q8RT\n",
		'empty'              => '',
		'NUL byte'           => "DC-7K4M-9P2X-Q8RT\0",
		'surrounding spaces' => ' DC-7K4M-9P2X-Q8RT ',
		'Greek Tau'          => "DC-7K4M-9P2X-Q8R\u{03A4}",
		'fullwidth'          => "DC-7K4M-9P2X-Q8R\u{FF34}",
		'wrong prefix'       => 'XC-7K4M-9P2X-Q8RT',
		'markup'             => '<script>alert(1)</script>',
		'path traversal'     => 'DC-7K4M-9P2X-Q8RT/../x',
		'URL'                => 'https://example.com/scan/DC-7K4M-9P2X-Q8RT/',
	);
	foreach ( $invalid as $label => $bad ) {
		pqbg_t( "both renderers reject: {$label}", 'pqbg_invalid_code' === $err( $qr->render( $bad ) ) && 'pqbg_invalid_code' === $err( $bc->render( $bad ) ) );
	}

	pqbg_section( 'SVG safety' );
	$allowed = array(
		'svg'  => array( 'viewBox', 'width', 'height', 'role', 'aria-label', 'shape-rendering' ),
		'rect' => array( 'width', 'height', 'fill' ),
		'path' => array( 'fill', 'd' ),
		'text' => array( 'x', 'y', 'font-family', 'font-size', 'text-anchor', 'fill' ),
	);
	foreach ( array( 'QR' => $qr->render( $codes[2] ), 'barcode' => $bc->render( $codes[2] ) ) as $label => $svg ) {
		$doc    = new DOMDocument();
		$parsed = $doc->loadXML( $svg, LIBXML_NONET );
		$bad    = array();
		foreach ( ( $parsed ? $doc->getElementsByTagName( '*' ) : array() ) as $el ) {
			if ( ! isset( $allowed[ $el->localName ] ) || 'http://www.w3.org/2000/svg' !== $el->namespaceURI ) {
				$bad[] = $el->localName;
				continue;
			}
			foreach ( $el->attributes as $attr ) {
				if ( ! in_array( $attr->name, $allowed[ $el->localName ], true ) ) {
					$bad[] = $el->localName . '@' . $attr->name;
				}
			}
		}
		pqbg_t( "{$label}: well-formed SVG with only allowed elements and attributes", $parsed && 'svg' === $doc->documentElement->localName && array() === $bad, implode( ',', $bad ) );
		pqbg_t( "{$label}: no script, event handlers, styles, links, url() or DOCTYPE", ! preg_match( '/<script|\son[a-z]+=|href|url\(|<style|<!|<\?|javascript:|<foreignObject|<use/i', $svg ) );
	}
	pqbg_t( 'Svg escapes text and attribute values', str_contains( Svg::text( 1, 1, 1, '<a href="x">&\'' ), '&lt;a href=&quot;x&quot;&gt;&amp;&apos;' ) && ! str_contains( Svg::document( 1, 1, 1, '"><script>', '' ), '"><script>' ) );

	pqbg_section( 'base URL validation' );
	$valid = array(
		'https://shop.example.com'           => 'https://shop.example.com',
		'https://shop.example.com/'          => 'https://shop.example.com',
		'https://shop.example.com///'        => 'https://shop.example.com',
		'HTTPS://Shop.Example.COM/Store/'    => 'https://shop.example.com/Store',
		'https://example.com/store?utm=1#x'  => 'https://example.com/store',
		'https://example.com/#only-fragment' => 'https://example.com',
		'http://example.com:8080/a/b/'       => 'http://example.com:8080/a/b',
		'https://[2001:db8::1]/x'            => 'https://[2001:db8::1]/x',
		'http://192.168.1.10/shop'           => 'http://192.168.1.10/shop',
		'https://example.com/%7Euser'        => 'https://example.com/%7Euser',
		'http://localhost/sharayu'           => 'http://localhost/sharayu',
	);
	foreach ( $valid as $input => $expected ) {
		$got = Settings::validate_base_url( $input );
		pqbg_t( "valid: {$input} -> {$expected}", $expected === $got, is_wp_error( $got ) ? $got->get_error_code() : (string) $got );
	}
	$rejected = array( '', 'example.com', '//example.com', 'ftp://example.com', 'javascript:alert(1)', 'data:text/html,x', 'https://', 'https:///path', 'https://user:pass@example.com', 'https://user@example.com', 'https://exa mple.com', ' https://example.com', "https://example.com\n", 'https://example.com/a b', 'https://example.com/../x', 'https://example.com/./x', 'https://example.com//x', "https://ex\u{00E4}mple.com", "https://example.com/\u{00FC}", 'https://-example.com', 'https://example..com', 'https://example.com:0', 'https://example.com:99999', 'https://example.com:abc', 'https://example.com/%zz', 'https://example.com/<script>', 'https://example.com/"onload=x', 'https://example.com/a;b', 'http://[::1', 'https://999.1.1.1', 'https://' . str_repeat( 'a', 90 ) . '.example.com' );
	foreach ( $rejected as $input ) {
		pqbg_t( 'rejected: ' . json_encode( $input ), 'pqbg_invalid_scan_base_url' === $err( Settings::validate_base_url( $input ) ) );
	}

	pqbg_section( 'sanitize (Settings API callback)' );
	update_option( $option, array_merge( Plugin::default_settings(), array( 'zz_unrelated' => 'keep', 'scan_base_url' => 'https://old.example.com' ) ), false );
	$out = Settings::sanitize( array( 'scan_base_url' => '  https://Shop.Example.com/store/ ' ) );
	pqbg_t( 'trims, validates and normalises the URL', 'https://shop.example.com/store' === $out['scan_base_url'] );
	pqbg_t( 'unrelated stored keys are kept', 'keep' === ( $out['zz_unrelated'] ?? null ) && 1 === $out['settings_version'] );
	pqbg_t( 'keys absent from the input are unchanged', false === $out['barcodes_enabled'] );
	pqbg_t( 'checkbox values', true === Settings::sanitize( array( 'barcodes_enabled' => '1' ) )['barcodes_enabled'] && false === Settings::sanitize( array( 'barcodes_enabled' => '0' ) )['barcodes_enabled'] && false === Settings::sanitize( array( 'barcodes_enabled' => 'yes' ) )['barcodes_enabled'] );
	pqbg_t( 'empty URL means "use the site URL"', '' === Settings::sanitize( array( 'scan_base_url' => '' ) )['scan_base_url'] );
	pqbg_t( 'unknown input keys are not added', ! array_key_exists( 'evil', Settings::sanitize( array( 'evil' => 'x' ) ) ) );
	pqbg_t( 'non-array input leaves the settings unchanged', 'https://old.example.com' === Settings::sanitize( 'garbage' )['scan_base_url'] );
	$GLOBALS['wp_settings_errors'] = array();
	$out                           = Settings::sanitize( array( 'scan_base_url' => 'javascript:alert(1)' ) );
	$errors                        = get_settings_errors( $option );
	pqbg_t( 'invalid URL keeps the previous value and reports a settings error', 'https://old.example.com' === $out['scan_base_url'] && 1 === count( $errors ) && 'pqbg_invalid_scan_base_url' === $errors[0]['code'] );
	pqbg_t( 'non-string URL rejected', 'https://old.example.com' === Settings::sanitize( array( 'scan_base_url' => array( 'x' ) ) )['scan_base_url'] );
	$set( array() );

	pqbg_section( 'codes are stored, URLs are not' );
	foreach ( array_slice( $codes, 0, 3 ) as $i => $code ) {
		CodeRepository::create_active( $code, 999999961 + $i, 0, 1 );
	}
	$checksum = static fn() => md5( (string) wp_json_encode( $wpdb->get_results( "SELECT * FROM $C ORDER BY id", ARRAY_A ) ) );
	$before   = $checksum();
	foreach ( array( 'https://a.example.com', 'http://localhost/x', '', 'https://b.example.com/shop' ) as $base ) {
		$set( array( 'scan_base_url' => $base, 'barcodes_enabled' => true ) );
		foreach ( array_slice( $codes, 0, 3 ) as $code ) {
			$qr->render( $code );
			$bc->render( $code );
		}
	}
	pqbg_t( 'changing the base URL and rendering never touches pqbg_codes', $before === $checksum() );
	$warm = $qr->render( $codes[0] ) . $bc->render( $codes[0] );
	$n    = $wpdb->num_queries;
	for ( $i = 0; $i < 10; $i++ ) {
		$qr->render( $codes[ $i % 8 ] );
		$bc->render( $codes[ $i % 8 ] );
	}
	pqbg_t( 'renderers run no database queries', $n === $wpdb->num_queries, ( $wpdb->num_queries - $n ) . ' queries' );
	$set( array() );

	pqbg_section( 'local-address warning' );
	$local  = array( 'http://localhost', 'http://localhost:8080/x', 'http://LOCALHOST/', 'http://shop.localhost', 'http://127.0.0.1', 'http://127.9.8.7', 'http://[::1]', 'http://10.0.0.5', 'http://172.16.4.2', 'http://172.31.255.255', 'http://192.168.1.10', 'http://169.254.1.1', 'http://[fd00::1]', 'http://[fe80::1]', 'http://0.0.0.0', 'http://mystore.local', 'http://mystore.test', 'http://intranet' );
	$public = array( 'https://example.com', 'https://shop.example.co.in', 'http://8.8.8.8', 'http://172.32.0.1', 'https://[2606:4700::1111]', 'https://localhost.example.com', 'https://example.test.com', 'https://mylocal.com' );
	$wrong  = array_merge( array_filter( $local, fn( $u ) => ! Settings::is_local_url( $u ) ), array_filter( $public, fn( $u ) => Settings::is_local_url( $u ) ) );
	pqbg_t( 'is_local_url: ' . count( $local ) . ' local and ' . count( $public ) . ' public hosts classified correctly', array() === $wrong, implode( ',', $wrong ) );
	$notice = static function ( int $user_id ): string {
		wp_set_current_user( $user_id );
		ob_start();
		SettingsPage::scan_url_notice();
		wp_set_current_user( 0 );
		return (string) ob_get_clean();
	};

	pqbg_section( 'users and access' );
	$pw = array();
	foreach ( array( 'admin' => 'administrator', 'sm' => 'shop_manager', 'seller' => 'pqbg_seller' ) as $who => $role ) {
		$pw[ $who ]       = wp_generate_password( 24, false );
		$user_ids[ $who ] = wp_insert_user( array( 'user_login' => "pqbg_p4_{$who}", 'user_pass' => $pw[ $who ], 'user_email' => "pqbg-p4-{$who}@example.invalid", 'role' => $role ) );
	}
	pqbg_t( 'temporary users created', 3 === count( array_filter( $user_ids, 'is_int' ) ) );
	$set( array( 'scan_base_url' => 'http://192.168.1.10/shop' ) );
	pqbg_t( 'warning shown to an administrator for a local base URL, with the exact text', str_contains( $notice( $user_ids['admin'] ), esc_html( $message ) ) );
	pqbg_t( 'warning not shown to a shop manager, seller or logged-out user', '' === $notice( $user_ids['sm'] ) . $notice( $user_ids['seller'] ) . $notice( 0 ) );
	$set( array( 'scan_base_url' => 'https://shop.example.com' ) );
	pqbg_t( 'no warning at all for a public https:// base URL', '' === $notice( $user_ids['admin'] ) );

	pqbg_section( 'http:// warning' );
	pqbg_t( 'is_http_url', Settings::is_http_url( 'http://shop.example.com' ) && Settings::is_http_url( 'HTTP://shop.example.com' ) && ! Settings::is_http_url( 'https://shop.example.com' ) );
	$set( array( 'scan_base_url' => 'http://shop.example.com' ) );
	$html = $notice( $user_ids['admin'] );
	pqbg_t( 'shown for a public http:// host, with the exact text', str_contains( $html, esc_html( $https_msg ) ) && ! str_contains( $html, esc_html( $message ) ) );
	pqbg_t( 'http:// is a warning only: the URL is valid and saved', 'http://shop.example.com' === Settings::validate_base_url( 'http://shop.example.com' ) && 'http://shop.example.com' === Settings::sanitize( array( 'scan_base_url' => 'http://shop.example.com' ) )['scan_base_url'] );
	pqbg_t( 'http:// warning not shown to a shop manager, seller or logged-out user', '' === $notice( $user_ids['sm'] ) . $notice( $user_ids['seller'] ) . $notice( 0 ) );
	$set( array( 'scan_base_url' => 'https://shop.example.com' ) );
	pqbg_t( 'hidden for a public https:// host', ! str_contains( $notice( $user_ids['admin'] ), esc_html( $https_msg ) ) );
	$priority = true;
	foreach ( array( 'http://localhost/sharayu', 'http://192.168.1.10/shop', 'http://mystore.test' ) as $local_http ) {
		$set( array( 'scan_base_url' => $local_http ) );
		$html     = $notice( $user_ids['admin'] );
		$priority = $priority && str_contains( $html, esc_html( $message ) ) && ! str_contains( $html, esc_html( $https_msg ) ) && 1 === substr_count( $html, 'notice-warning' );
	}
	pqbg_t( 'local http:// hosts get only the local-address warning (it takes priority)', $priority );
	$set( array() );
	pqbg_t( 'warning back for the default (local) site URL', str_contains( $notice( $user_ids['admin'] ), esc_html( $message ) ) === Settings::is_local_url( $home ) );
	pqbg_t( 'options.php capability for this group is pqbg_manage_settings (enforcement tested over HTTP below)', 'pqbg_manage_settings' === SettingsPage::capability() );
	pqbg_t( 'capability: admin yes; shop manager, seller, logged-out no', user_can( $user_ids['admin'], 'pqbg_manage_settings' ) && ! user_can( $user_ids['sm'], 'pqbg_manage_settings' ) && ! user_can( $user_ids['seller'], 'pqbg_manage_settings' ) && ! user_can( 0, 'pqbg_manage_settings' ) );
	pqbg_t( 'admin hooks are not registered outside wp-admin', false === has_action( 'admin_menu', array( SettingsPage::class, 'add_menu' ) ) && false === has_action( 'admin_notices', array( SettingsPage::class, 'scan_url_notice' ) ) );

	pqbg_section( 'HTTP: settings page access' );
	$site_is_up = 200 === $http( 'anon', 'GET', wp_login_url() )['code'];
	pqbg_t( 'site reachable over HTTP', $site_is_up, wp_login_url() );
	$r = $http( 'anon', 'GET', $page_url );
	pqbg_t( 'logged out: direct URL redirects to login', 302 === $r['code'] && str_contains( $r['location'], 'wp-login.php' ) );
	pqbg_t( 'logins succeed', $login( 'admin', 'pqbg_p4_admin', $pw['admin'] ) && $login( 'sm', 'pqbg_p4_sm', $pw['sm'] ) && $login( 'seller', 'pqbg_p4_seller', $pw['seller'] ) );
	$r = $http( 'admin', 'GET', $page_url );
	pqbg_t( 'admin: page loads (200) with both fields', 200 === $r['code'] && str_contains( $r['body'], 'Enable barcodes (for hardware scanners)' ) && str_contains( $r['body'], 'Scan base URL' ) && str_contains( $r['body'], "name='option_page' value='pqbg_settings'" ), $r['code'] . ' ' . $r['location'] );
	pqbg_t( 'admin: effective URL and example payload shown', str_contains( $r['body'], '<code>' . esc_html( $home ) . '</code>' ) && str_contains( $r['body'], esc_html( $home . '/scan/DC-XXXX-XXXX-XXXX/' ) ) );
	pqbg_t( 'admin: local-address warning shown', str_contains( $r['body'], esc_html( $message ) ) === Settings::is_local_url( $home ) );
	pqbg_t( 'admin: menu item under WooCommerce', 1 === preg_match( '#href=[\'"]admin\.php\?page=pqbg-settings[\'"]#', $r['body'] ) );
	preg_match( '/name="_wpnonce" value="([a-f0-9]+)"/', $r['body'], $m );
	$admin_nonce = $m[1] ?? '';
	pqbg_t( 'admin: form carries a nonce', '' !== $admin_nonce );
	$r = $http( 'sm', 'GET', $page_url );
	pqbg_t( 'shop manager: direct URL refused (403), no form', 403 === $r['code'] && ! str_contains( $r['body'], 'pqbg_settings[' ) );
	// The Dashboard renders the full admin menu, including WooCommerce's (the Products screen of an empty store redirects to onboarding).
	$r = $http( 'sm', 'GET', admin_url( 'index.php' ) );
	pqbg_t( 'shop manager: WooCommerce menu visible but no settings item and no warning', str_contains( $r['body'], 'admin.php?page=wc-settings' ) && 200 === $r['code'] && ! str_contains( $r['body'], 'page=pqbg-settings' ) && ! str_contains( $r['body'], esc_html( $message ) ), $r['code'] . ' ' . $r['location'] );
	$r = $http( 'seller', 'GET', $page_url );
	pqbg_t( 'seller: direct URL does not show the page', 200 !== $r['code'] && ! str_contains( $r['body'], 'pqbg_settings[' ), $r['code'] . ' ' . $r['location'] );

	pqbg_section( 'HTTP: saving settings' );
	update_option( $option, array_merge( Plugin::default_settings(), array( 'zz_unrelated' => 'keep' ) ), false );
	$form = static fn( string $nonce, bool $barcodes, string $url ) => 'option_page=pqbg_settings&action=update&_wpnonce=' . rawurlencode( $nonce )
		. '&_wp_http_referer=' . rawurlencode( wp_parse_url( $page_url, PHP_URL_PATH ) . '?page=' . SettingsPage::SLUG )
		. '&' . rawurlencode( 'pqbg_settings[barcodes_enabled]' ) . '=0' . ( $barcodes ? '&' . rawurlencode( 'pqbg_settings[barcodes_enabled]' ) . '=1' : '' )
		. '&' . rawurlencode( 'pqbg_settings[scan_base_url]' ) . '=' . rawurlencode( $url );
	$post = static fn( string $who, string $body ) => $http( $who, 'POST', admin_url( 'options.php' ), $body );
	$r    = $post( 'admin', $form( $admin_nonce, true, 'https://Shop.Example.com/store/?utm=1' ) );
	$s = $stored();
	pqbg_t( 'admin: valid save redirects back with settings-updated', 302 === $r['code'] && str_contains( $r['location'], 'page=pqbg-settings' ) && str_contains( $r['location'], 'settings-updated=true' ), $r['code'] . ' ' . $r['location'] );
	pqbg_t( 'admin: values saved and normalised', true === $s['barcodes_enabled'] && 'https://shop.example.com/store' === $s['scan_base_url'], wp_json_encode( $s ) );
	pqbg_t( 'admin: unrelated keys kept by the form save', 'keep' === ( $s['zz_unrelated'] ?? null ) && 1 === $s['settings_version'] );
	$r = $http( 'admin', 'GET', $page_url . '&settings-updated=true' );
	pqbg_t( 'admin: saved page shows "Settings saved." and no local or http:// warning', str_contains( $r['body'], 'Settings saved.' ) && ! str_contains( $r['body'], esc_html( $message ) ) && ! str_contains( $r['body'], esc_html( $https_msg ) ) && str_contains( $r['body'], 'checked=\'checked\'' ), wp_json_encode( array( str_contains( $r['body'], 'Settings saved.' ), ! str_contains( $r['body'], esc_html( $message ) ), str_contains( $r['body'], 'checked=\'checked\'' ) ) ) );
	$r = $post( 'admin', $form( $admin_nonce, false, 'javascript:alert(1)' ) );
	$s = $stored();
	pqbg_t( 'admin: invalid URL rejected, previous URL kept, checkbox off saved', 'https://shop.example.com/store' === $s['scan_base_url'] && false === $s['barcodes_enabled'] );
	$r = $http( 'admin', 'GET', $page_url . '&settings-updated=true' );
	pqbg_t( 'admin: the error is shown on the page', str_contains( $r['body'], 'The scan base URL must be an absolute' ) );
	$r = $post( 'admin', $form( $admin_nonce, false, 'http://shop.example.com' ) );
	$s = $stored();
	$r = $http( 'admin', 'GET', $page_url . '&settings-updated=true' );
	pqbg_t( 'admin: an http:// public URL saves (warning only) and the https notice is shown', 'http://shop.example.com' === $s['scan_base_url'] && str_contains( $r['body'], esc_html( $https_msg ) ) && ! str_contains( $r['body'], esc_html( $message ) ) );
	$before = $stored();
	$r      = $post( 'admin', $form( 'badnonce00', true, 'https://evil.example.com' ) );
	pqbg_t( 'admin: bad nonce rejected (403), nothing saved', 403 === $r['code'] && $before == $stored() );
	$sm_nonce = $nonce_for( $user_ids['sm'], $logged_in_cookie( 'sm' ) );
	pqbg_t( 'positive control: a valid nonce was minted for the shop manager session', '' !== $sm_nonce );
	$r = $post( 'sm', $form( $sm_nonce, true, 'https://evil.example.com' ) );
	pqbg_t( 'shop manager: valid nonce but no capability, refused (403), nothing saved', 403 === $r['code'] && $before == $stored(), (string) $r['code'] );
	$seller_nonce = $nonce_for( $user_ids['seller'], $logged_in_cookie( 'seller' ) );
	$r            = $post( 'seller', $form( $seller_nonce, true, 'https://evil.example.com' ) );
	pqbg_t( 'seller: refused, nothing saved', ! str_contains( $r['location'], 'settings-updated' ) && $before == $stored(), $r['code'] . ' ' . $r['location'] );
	$r = $post( 'anon', $form( 'x', true, 'https://evil.example.com' ) );
	pqbg_t( 'logged out: refused, nothing saved', ! str_contains( $r['location'], 'settings-updated' ) && $before == $stored() );

	pqbg_section( 'vendor isolation' );
	$files      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( PQBG_PLUGIN_DIR . 'vendor-prefixed', FilesystemIterator::SKIP_DOTS ) );
	$unprefixed = array();
	$unguarded  = array();
	$count      = 0;
	foreach ( $files as $file ) {
		if ( 'php' !== $file->getExtension() || 'index.php' === $file->getFilename() ) {
			continue;
		}
		++$count;
		$src = (string) file_get_contents( $file->getPathname() );
		if ( ! preg_match( '/^namespace ProductQrBarcode\\\\Vendor\\\\(BaconQrCode|DASPRiD\\\\Enum|Picqer\\\\Barcode)(\\\\[A-Za-z\\\\]+)?;$/m', $src ) || preg_match( '/^namespace (BaconQrCode|DASPRiD|Picqer)/m', $src ) ) {
			$unprefixed[] = $file->getFilename();
		}
		if ( 1 !== substr_count( $src, "defined( 'ABSPATH' ) || exit;" ) ) {
			$unguarded[] = $file->getFilename();
		}
	}
	pqbg_t( "all {$count} vendor files are namespaced under ProductQrBarcode\\Vendor\\", $count > 100 && array() === $unprefixed, implode( ',', $unprefixed ) );
	pqbg_t( 'every vendor file has exactly one ABSPATH guard', array() === $unguarded, implode( ',', $unguarded ) );
	$declared = array_merge( get_declared_classes(), get_declared_interfaces(), get_declared_traits() );
	pqbg_t( 'no unprefixed library classes declared', array() === preg_grep( '/^(BaconQrCode|DASPRiD|Picqer)\\\\/', $declared ) );
	pqbg_t( 'both libraries were loaded (checks above are live)', count( $vendor_classes( 'BaconQrCode' ) ) > 0 && count( $vendor_classes( 'Picqer' ) ) > 0 && count( $vendor_classes( 'DASPRiD' ) ) > 0 );
	$vendor_functions = array_filter( get_defined_functions()['user'], fn( $f ) => str_contains( str_replace( '\\', '/', (string) ( new ReflectionFunction( $f ) )->getFileName() ), 'vendor-prefixed' ) );
	pqbg_t( 'no global functions declared by vendor code', array() === $vendor_functions, implode( ',', $vendor_functions ) );
	pqbg_t( 'licenses and NOTICE shipped', is_file( PQBG_PLUGIN_DIR . 'vendor-prefixed/bacon/bacon-qr-code/LICENSE' ) && is_file( PQBG_PLUGIN_DIR . 'vendor-prefixed/dasprid/enum/LICENSE' ) && str_contains( (string) file_get_contents( PQBG_PLUGIN_DIR . 'vendor-prefixed/picqer/php-barcode-generator/LICENSE.md' ), 'GNU LESSER GENERAL PUBLIC LICENSE' ) && is_file( PQBG_PLUGIN_DIR . 'vendor-prefixed/NOTICE.md' ) );

	pqbg_section( 'HTTP: direct file access' );
	$plugin_url = untrailingslashit( PQBG_PLUGIN_URL );
	$empty      = array( '/vendor-prefixed/bacon/bacon-qr-code/src/Encoder/Encoder.php', '/vendor-prefixed/picqer/php-barcode-generator/src/Types/TypeCode128.php', '/vendor-prefixed/dasprid/enum/src/AbstractEnum.php', '/vendor-prefixed/bacon/bacon-qr-code/src/', '/includes/QrRenderer.php', '/includes/BarcodeRenderer.php', '/includes/Settings.php', '/includes/SettingsPage.php', '/includes/ScanUrl.php', '/includes/Svg.php' );
	$leaky      = array();
	foreach ( $empty as $path ) {
		$r = $http( 'anon', 'GET', $plugin_url . $path );
		if ( 200 !== $r['code'] || '' !== trim( $r['body'] ) ) {
			$leaky[] = "$path ({$r['code']})";
		}
	}
	pqbg_t( 'vendor and new plugin files return empty output', array() === $leaky, implode( ', ', $leaky ) );
	$open = array();
	foreach ( array( '/tests/phase4-rendering.php', '/tests/run.php', '/tests/README.md', '/tests/decoder/package.json', '/build/composer.json', '/build/build.php', '/build/composer.lock' ) as $path ) {
		$r = $http( 'anon', 'GET', $plugin_url . $path );
		if ( 403 !== $r['code'] ) {
			$open[] = "$path ({$r['code']})";
		}
	}
	pqbg_t( 'tests/ and build/ are denied over HTTP (403)', array() === $open, implode( ', ', $open ) );

	pqbg_section( 'scope' );
	do_action( 'rest_api_init' );
	$hit = fn( $s ) => str_contains( $s, 'pqbg' ) || str_contains( $s, 'qrcode-barcode' );
	pqbg_t( 'no pqbg or /scan REST routes', ! array_filter( rest_get_server()->get_namespaces(), $hit ) && ! array_filter( array_keys( rest_get_server()->get_routes() ), fn( $r ) => $hit( $r ) || str_starts_with( $r, '/scan' ) ) );
	pqbg_t( 'no pqbg AJAX or admin-post actions', ! array_filter( array_keys( $GLOBALS['wp_filter'] ), fn( $h ) => ( str_starts_with( $h, 'wp_ajax' ) || str_starts_with( $h, 'admin_post' ) ) && $hit( $h ) ) );
	pqbg_t( 'no pqbg shortcodes', ! array_filter( array_keys( $GLOBALS['shortcode_tags'] ), $hit ) );
	// Phase 6 added the scan route (tests/phase6-scan.php): exactly its two rules, and logged-out requests go to the login page.
	$scan_rules = array_keys( ScanUrl::rewrite_rules( ProductQrBarcode\ScanRoute::ROUTE_VAR, ProductQrBarcode\ScanRoute::CODE_VAR ) );
	pqbg_t( 'no scan rewrite rules other than the two Phase 6 scan rules', array() === array_diff( array_filter( array_keys( (array) get_option( 'rewrite_rules' ) ), fn( $k ) => str_contains( $k, 'scan' ) || $hit( $k ) ), $scan_rules ) );
	$r1 = $http( 'anon', 'GET', $home . '/scan/' );
	$r2 = $http( 'anon', 'GET', $home . '/scan/' . $codes[0] . '/' );
	pqbg_t( 'logged out: /scan/ and /scan/{CODE}/ redirect to the login page (Phase 6)', 302 === $r1['code'] && 302 === $r2['code'] && str_starts_with( $r2['location'], wp_login_url() ) && '' === trim( $r2['body'] ), $r1['code'] . ' ' . $r2['code'] );
	$writes = array();
	foreach ( array( 'QrRenderer', 'BarcodeRenderer', 'ScanUrl', 'Svg', 'Settings' ) as $class ) {
		foreach ( token_get_all( (string) file_get_contents( PQBG_PLUGIN_DIR . "includes/{$class}.php" ) ) as $tok ) {
			if ( is_array( $tok ) && in_array( strtolower( ltrim( $tok[1], '$' ) ), array( 'wpdb', 'file_put_contents', 'fopen', 'fwrite', 'mkdir', 'wp_upload_dir', 'update_option', 'add_option', 'delete_option', 'set_transient', 'wp_cache_set' ), true ) ) {
				$writes[] = "$class:{$tok[1]}";
			}
		}
	}
	pqbg_t( 'renderers, ScanUrl, Svg and Settings contain no DB/file writes', array() === $writes, implode( ',', $writes ) );
	$uploads = wp_upload_dir()['basedir'];
	$listing = static fn() => is_dir( $uploads ) ? iterator_count( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $uploads, FilesystemIterator::SKIP_DOTS ) ) ) : 0;
	$n       = $listing();
	$set( array( 'barcodes_enabled' => true ) );
	foreach ( $codes as $code ) {
		$qr->render( $code );
		$bc->render( $code );
	}
	pqbg_t( 'rendering writes nothing to uploads', $n === $listing() );

	pqbg_section( 'performance' );
	$t = microtime( true );
	for ( $i = 0; $i < 50; $i++ ) {
		$qr->render( $codes[ $i % 8 ] );
	}
	$qr_ms = ( microtime( true ) - $t ) * 20;
	$t     = microtime( true );
	for ( $i = 0; $i < 50; $i++ ) {
		$bc->render( $codes[ $i % 8 ] );
	}
	$bc_ms = ( microtime( true ) - $t ) * 20;
	// Budget for rendering one code on demand. bacon's pure-PHP encoder takes ~45 ms per QR on the dev
	// machine (it scores all 8 mask patterns); bulk label printing should revisit caching.
	pqbg_t( 'on-demand render within budget (QR < 200 ms, barcode < 20 ms average)', $qr_ms < 200 && $bc_ms < 20, sprintf( 'QR %.1f ms, barcode %.1f ms', $qr_ms, $bc_ms ) );
} finally {
	pqbg_section( 'cleanup' );
	wp_set_current_user( 0 );
	$handles = array(); // curl handles are freed when unset (curl_close() is a deprecated no-op since PHP 8.5).
	// Opening the Dashboard as a user who can edit posts creates a Quick Draft auto-draft owned by that
	// user; wp_delete_user() would move it to the trash (with a revision) instead of deleting it.
	$authored = array();
	foreach ( $user_ids as $uid ) {
		if ( is_int( $uid ) ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d", $uid ) ) as $pid ) {
				$authored[] = (int) $pid;
				wp_delete_post( (int) $pid, true );
			}
			wp_delete_user( $uid );
		}
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM $C WHERE id > %d", $start_id ) );
	$wpdb->query( "ALTER TABLE $C AUTO_INCREMENT = 1" );
	$wpdb->update( $wpdb->options, $original, array( 'option_name' => $option ) );
	wp_cache_delete( $option, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	if ( is_dir( $tmp ) ) {
		array_map( 'unlink', glob( $tmp . '/*' ) );
		rmdir( $tmp );
	}
	pqbg_t( 'settings restored to the exact stored value', $original === $raw_setting() );
	pqbg_t( 'codes table back to its starting row count', $base_c === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $C" ) );
	pqbg_t( 'temporary users removed', $base_user === (int) count_users()['total_users'] && ! get_user_by( 'login', 'pqbg_p4_admin' ) );
	pqbg_t( 'no posts left by the temporary users (Dashboard auto-drafts, their revisions)', array() === $authored || 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->posts . ' WHERE ID IN (' . implode( ',', $authored ) . ') OR post_parent IN (' . implode( ',', $authored ) . ')' ) );
	pqbg_t( 'temporary directory removed', ! file_exists( $tmp ) );
	$stray = array_filter( iterator_to_array( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ), FilesystemIterator::SKIP_DOTS ) ) ), fn( $f ) => in_array( strtolower( $f->getExtension() ), array( 'svg', 'png' ), true ) && ! str_contains( str_replace( '\\', '/', $f->getPathname() ), '/node_modules/' ) );
	pqbg_t( 'no SVG/PNG files left in the plugin directory', array() === $stray );
}

pqbg_test_done();
