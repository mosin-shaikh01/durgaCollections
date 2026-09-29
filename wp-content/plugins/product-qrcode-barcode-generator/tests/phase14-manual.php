<?php
/**
 * 1.0.1 suite: the user manual (PDF), the "Plugin guide" button, the Plugins screen link and the
 * seller guide links.
 *
 * - the documents: docs/user-manual.pdf (a PDF within the size limit, the current version in its
 *   title, every chapter in the bookmarks and the text, the table of contents' page numbers equal
 *   to the real pages, "Administrator only" where the source says so, one image per screenshot,
 *   no real site or administrator name), docs/user-manual.md, the owner guide gone, the build
 *   files in build/manual/ with exact package pins;
 * - the source's screen names (bold text) are the plugin's own strings (the .pot) or known
 *   WordPress/WooCommerce labels;
 * - the URL helper: AdminUrl::user_manual() / seller_guide(), and no docs/ address written anywhere else;
 * - over HTTP: both PDFs 200 with application/pdf (logged out and logged in); the button, its
 *   tooltip and the seller guide link on the Dashboard for an administrator and a shop manager,
 *   never for a Store Seller or a visitor; the script only on the Dashboard; the "User manual" link
 *   in this plugin's row only; the "How to sell (guide)" link on My sales only; the scan pages'
 *   security headers byte-identical to 1.0.0 (the tagged ScanRoute) and sent unchanged;
 * - in a real browser: the button reached with Tab, a visible focus outline, the tooltip on focus
 *   and hover (also while the pointer is on the tooltip), hidden with Escape, Enter opens the
 *   manual in a new tab, no console error;
 * - the .pot has the new strings.
 *
 * Needs PQBG_MANUAL_TOOLS (build/manual installed, for pdfjs-dist) and PQBG_THEMECHECK (puppeteer-core).
 * Creates three users and a sample product (for My sales and a scan page); removes them at the end.
 *
 *   php tests/phase14-manual.php
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

use ProductQrBarcode\{AdminUrl, CodeRepository, PluginLinks, ScanRoute, ScanUrl, Schema};

global $wpdb;

$dir        = dirname( __DIR__ );
$read       = static fn( string $rel ): string => is_file( $dir . '/' . $rel ) ? (string) file_get_contents( $dir . '/' . $rel ) : '';
$tools      = rtrim( (string) getenv( 'PQBG_MANUAL_TOOLS' ), '/\\' );
$themecheck = (string) getenv( 'PQBG_THEMECHECK' );
$browsers   = array_values( array_filter( array_merge( explode( ';', (string) getenv( 'PQBG_BROWSERS' ) ), array( 'C:\Program Files\Google\Chrome\Application\chrome.exe', 'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe', 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe' ) ), 'is_file' ) );
$mark       = pqbg_test_as_mark();
// Every Dashboard render records a timing sample (PerfSignal): the plugin's options are restored byte for byte.
$pqbg_opts  = static fn(): array => (array) $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE '%pqbg%' ORDER BY option_name", OBJECT_K );
$opts0      = $pqbg_opts();
$users      = array();
$product    = 0;
$norm       = static fn( string $s ): string => strtolower( (string) preg_replace( '/\s+/u', '', $s ) );

add_filter( 'pre_wp_mail', '__return_false' );

/** Runs a node script with a job file (deleted afterwards) and returns the JSON of its last line. */
$node = static function ( string $script, array $job ): array {
	$file = get_temp_dir() . 'pqbg-p14-' . wp_generate_password( 8, false ) . '.json';
	file_put_contents( $file, wp_json_encode( $job ) );
	$out = (string) shell_exec( 'node ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $file ) . ' 2>&1' );
	unlink( $file );
	$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $out ) ) ) );
	$json  = json_decode( (string) end( $lines ), true );
	return is_array( $json ) ? $json : array( 'ok' => false, 'errors' => array( substr( $out, -1500 ) ) );
};

// HTTP client: a fresh connection per request (see the Phase 6 notes about this XAMPP's php8ts.dll).
$handles = array();
$http    = static function ( string $who, string $method, string $url, ?array $post = null ) use ( &$handles ): array {
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
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_FORBID_REUSE   => true,
			CURLOPT_POST           => 'POST' === $method,
			CURLOPT_HTTPGET        => 'GET' === $method,
		)
	);
	if ( 'POST' === $method ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( (array) $post ) );
	}
	$raw  = (string) curl_exec( $ch );
	$size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	$hdrs = array();
	foreach ( preg_split( '/\r?\n/', substr( $raw, 0, $size ) ) as $line ) {
		if ( preg_match( '/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m ) ) {
			$hdrs[ strtolower( $m[1] ) ] = trim( $m[2] );
		}
	}
	return array(
		'code'    => (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ),
		'headers' => $hdrs,
		'body'    => substr( $raw, $size ),
	);
};
$login = static function ( string $who, string $user, string $pass ) use ( $http ): bool {
	$http( $who, 'GET', wp_login_url() );
	$r = $http( $who, 'POST', wp_login_url(), array( 'log' => $user, 'pwd' => $pass, 'wp-submit' => 'Log In', 'testcookie' => '1', 'redirect_to' => admin_url() ) );
	return 302 === $r['code'];
};

try {
	// ------------------------------------------------------------------ documents
	pqbg_section( 'documents' );
	$pdf_file = $dir . '/docs/user-manual.pdf';
	$pdf      = $read( 'docs/user-manual.pdf' );
	$md       = $read( 'docs/user-manual.md' );
	pqbg_t( 'docs/user-manual.pdf is a PDF of at most 4 MB', str_starts_with( $pdf, '%PDF-' ) && strlen( $pdf ) <= 4 * 1024 * 1024, size_format( strlen( $pdf ), 2 ) );
	pqbg_t( 'docs/user-manual.md is the source; docs/owner-guide.md is gone (the manual replaces it)', '' !== $md && ! file_exists( $dir . '/docs/owner-guide.md' ) );
	$build_files = array( 'build-manual.php', 'repair.php', 'shots.mjs', 'render.mjs', 'pdf-text.mjs', 'manual.css', 'manual.template.html', 'package.json', 'package-lock.json', 'README.md', 'index.php' );
	$missing     = array_values( array_filter( $build_files, static fn( $f ) => ! is_file( $dir . '/build/manual/' . $f ) ) );
	pqbg_t( 'the build is in build/manual/ (orchestrator, screenshots, renderer, PDF reader, styles, template, package pins, README)', array() === $missing, implode( ', ', $missing ) );
	$pkg  = json_decode( $read( 'build/manual/package.json' ), true );
	$lock = json_decode( $read( 'build/manual/package-lock.json' ), true );
	$deps = (array) ( $pkg['dependencies'] ?? array() );
	pqbg_t( 'the build\'s packages are pinned to exact versions, and the lock file has them with integrity hashes', array( 'marked', 'pdfjs-dist' ) === array_keys( $deps ) && array() === array_filter( $deps, static fn( $v ) => ! preg_match( '/^\d+\.\d+\.\d+$/', (string) $v ) ) && ( $lock['packages']['node_modules/marked']['version'] ?? '' ) === $deps['marked'] && ( $lock['packages']['node_modules/pdfjs-dist']['version'] ?? '' ) === $deps['pdfjs-dist'] && '' !== ( $lock['packages']['node_modules/pdfjs-dist']['integrity'] ?? '' ), wp_json_encode( $deps ) );

	// The chapters and sections the source declares.
	preg_match_all( '/^(##|###) (.+?)\s*$/m', $md, $hm, PREG_SET_ORDER );
	$heads = array();
	foreach ( $hm as $h ) {
		$admin   = (bool) preg_match( '/\{admin\}$/', $h[2] );
		$heads[] = array(
			'level' => strlen( $h[1] ),
			'text'  => trim( (string) preg_replace( array( '/\s*\{admin\}$/', '/\*\*|`/' ), '', $h[2] ) ),
			'admin' => $admin,
		);
	}
	preg_match_all( '/!\[[^\]]*\]\(shot:([a-z0-9-]+)\)/', $md, $sm );
	$shot_names = $sm[1];
	pqbg_t( 'the source: 15 chapters (How to use + 1–14), sections, four marked "Administrator only", every screenshot used once', 15 === count( array_filter( $heads, static fn( $h ) => 2 === $h['level'] ) ) && 4 === count( array_filter( $heads, static fn( $h ) => $h['admin'] ) ) && count( $shot_names ) === count( array_unique( $shot_names ) ) && count( $shot_names ) >= 25, count( $heads ) . ' headings, ' . count( $shot_names ) . ' screenshots' );

	pqbg_section( 'PDF content' );
	if ( '' === $tools || ! is_file( $tools . '/node_modules/pdfjs-dist/package.json' ) ) {
		pqbg_skip( 'the PDF\'s text, bookmarks and table of contents', 'set PQBG_MANUAL_TOOLS to the installed build/manual tools (see build/manual/README.md)' );
	} else {
		$info  = $node( $dir . '/build/manual/pdf-text.mjs', array( 'file' => $pdf_file, 'tools' => $tools ) );
		$texts = array_map( $norm, (array) ( $info['text'] ?? array() ) );
		pqbg_t( 'the PDF reads, with 20 to 80 pages', ! empty( $info['ok'] ) && $info['pages'] >= 20 && $info['pages'] <= 80, ( $info['pages'] ?? '?' ) . ' pages ' . implode( ' ', (array) ( $info['error'] ?? '' ) ) );
		pqbg_t( 'its title is "Product QR Code and Barcode Generator ' . PQBG_VERSION . ' – User manual"', 'Product QR Code and Barcode Generator ' . PQBG_VERSION . ' – User manual' === ( $info['title'] ?? '' ), (string) ( $info['title'] ?? '' ) );
		pqbg_t( 'the cover and every page\'s footer carry the version', isset( $texts[0] ) && str_contains( $texts[0], $norm( 'Version' ) . $norm( PQBG_VERSION ) ) && array() === array_filter( $texts, static fn( $t ) => ! str_contains( $t, $norm( 'Generator ' . PQBG_VERSION . ' – User manual' ) ) ) );
		$outline = array_map( $norm, (array) ( $info['outline'] ?? array() ) );
		$no_mark = array_values( array_filter( $heads, static fn( $h ) => ! in_array( $norm( $h['text'] . ( $h['admin'] ? 'Administrator only' : '' ) ), $outline, true ) && ! in_array( $norm( $h['text'] ), $outline, true ) ) );
		pqbg_t( 'every chapter and section is a bookmark', array() === $no_mark, implode( ', ', array_column( $no_mark, 'text' ) ) );

		// The table of contents: its pages end with the fixed sentence; every entry's number is the page where the heading really is.
		$toc_end = -1;
		foreach ( $texts as $i => $t ) {
			if ( str_contains( $t, $norm( 'Page numbers are printed at the bottom of every page.' ) ) ) {
				$toc_end = $i;
				break;
			}
		}
		// One entry per line (whitespace removed within each line only): joined, "…manual 3" and "1. What…" would
		// run together as "manual31.what…" and no page number could be told from the next entry's number.
		$toc   = implode( "\n", array_map( $norm, explode( "\n", implode( "\n", array_slice( (array) ( $info['text'] ?? array() ), 0, $toc_end + 1 ) ) ) ) );
		$wrong = array();
		$from  = $toc_end + 1;
		foreach ( $heads as $h ) {
			$page = -1;
			for ( $p = $from; $p < count( $texts ); $p++ ) {
				if ( str_contains( $texts[ $p ], $norm( $h['text'] ) ) ) {
					$page = $p + 1;
					break;
				}
			}
			$from  = max( $from, $page - 1 );
			$entry = preg_quote( $norm( $h['text'] ), '/' ) . '(?:administratoronly)?' . $page;
			if ( $page < 1 || ! preg_match( '/^' . $entry . '$/m', $toc ) ) {
				$wrong[] = $h['text'] . ' (p. ' . $page . ')';
			}
		}
		pqbg_t( 'the table of contents lists every chapter and section with the page it really starts on', $toc_end > 0 && array() === $wrong, implode( '; ', $wrong ) );
		$admin_heads = array_filter( $heads, static fn( $h ) => $h['admin'] );
		$badge_miss  = array_filter( $admin_heads, static fn( $h ) => ! str_contains( implode( '', $texts ), $norm( $h['text'] ) . 'administratoronly' ) );
		pqbg_t( 'the "Administrator only" sections carry the badge (in the contents and on the heading)', array() === $badge_miss && substr_count( implode( '', $texts ), 'administratoronly' ) >= 2 * count( $admin_heads ), implode( ', ', array_column( $badge_miss, 'text' ) ) );

		$images = preg_match_all( '/\/Subtype\s*\/Image/', $pdf );
		pqbg_t( 'one image in the PDF per screenshot in the source', count( $shot_names ) === $images, $images . ' images, ' . count( $shot_names ) . ' screenshots' );
		$real = array_filter( array_merge( array( trim( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), (string) get_option( 'admin_email' ) ), array_merge( ...array_map( static fn( $u ) => array( $u->user_login, $u->display_name, $u->user_email ), get_users( array( 'role' => 'administrator' ) ) ) ) ), static fn( $s ) => strlen( $s ) >= 4 );
		$all  = strtolower( implode( "\n", (array) ( $info['text'] ?? array() ) ) . $md );
		$hits = array_values( array_filter( array_unique( $real ), static fn( $s ) => str_contains( $all, strtolower( $s ) ) ) );
		pqbg_t( 'neither the PDF\'s text nor its source names this site or a real administrator', array() === $hits, implode( ', ', $hits ) );
	}

	pqbg_section( 'source: screen names' );
	// Every bold phrase that names something on a screen is one of the plugin's strings, or a WordPress/WooCommerce label.
	$pot     = $read( 'languages/product-qrcode-barcode-generator.pot' );
	preg_match_all( '/^msgid "(.+)"$/m', $pot, $pm );
	$msgids  = array_map( 'stripcslashes', $pm[1] );
	$outside = array( 'Users → Add New', 'Store Seller', 'Shop manager', 'Settings → Permalinks', 'Track stock quantity for this product', 'Plugins', 'Plugins → Add New → Upload Plugin', 'Data → From Text/CSV', 'Paid by: Cash', 'In-store reports → Summary → Today', 'QR & Barcodes → Settings → Health check', 'QR & Barcodes → Settings', 'QR & Barcodes → In-store sales', 'QR & Barcodes → In-store reports', 'QR & Barcodes → In-store reports → End of day', 'QR & Barcodes → Bulk tools', 'QR & Barcodes → Dashboard', 'QR & Barcodes', 'Bulk tools → Code tools', 'CSV UTF-8 (Comma delimited)', 'Cost price (₹)', 'Published', 'Finished', 'Stopped', 'Interrupted', 'Continue in Bulk tools' );
	$prose   = array( 'The shop owner (administrator):', 'Shop managers:', 'Sellers:', 'Item:', 'Code:', 'Label:', 'Scan page:', 'Example.', 'Important: print real labels only after the shop is on its final https address.', 'Give every person their own login. Never share an account.', 'No', 'not', 'does not', 'Administrator only', 'your own', 'Check that it is the item in your hand.', '10 minutes', 'Cash expected in drawer = ₹4,297 − ₹899 = ₹3,398.', 'Worked example: a catalogue of 200 T-shirts.', 'Stop and continue.', 'Worked example.', 'Step 1 – the template.', 'Step 2 – fill in the costs.', 'Step 3 – upload and preview.', 'Step 4 – apply.', 'Step 5 – the report.', '180', '1,200.50', 'clear', '78O', 'empty', 'published', 'Voiding a sale.', 'Replacing a code', 'One product:', 'Several products:', 'Everything that just got a code:', 'Layout.', 'Start at position.', 'Copies.', 'Show on the label:', 'Printer offset.', 'Before your first real labels:', 'Scan base URL.', 'Enable barcodes (for hardware scanners).', 'Payment methods offered.', 'Summary:', 'Sales over time', 'Products', 'Categories', 'Sellers', 'Peak times', 'Stock', 'Dead stock', 'Profit & margin', 'Needs attention', 'Today in the shop:', 'Products and codes', 'Recent bulk runs', 'Setup', 'Quick links', 'Plugin guide.', 'Backups.', 'Updates.', 'Deactivating', 'Deleting', 'To remove everything permanently', 'Codes created: 200', '21 labels', 'Print labels: items 1–200', '100 of about 200 items', 'Applied', 'Error', 'Update', 'Clear (becomes unknown)', 'No change', 'Seller guide (1 page, for staff)', 'User manual', 'Plugin guide', 'How to sell (guide)', 'shop manager', 'seller guide', 'Example: one label per unit in stock.', '1 per item' ); // "1 per item": the Copies box's default 1 before the string "per item".
	preg_match_all( '/\*\*(.+?)\*\*/u', $md, $bm );
	$unknown = array();
	foreach ( array_unique( $bm[1] ) as $bold ) {
		$plain = trim( (string) preg_replace( '/`/', '', $bold ) );
		if ( in_array( $plain, $outside, true ) || in_array( $plain, $prose, true ) ) {
			continue;
		}
		$found = array_filter( $msgids, static fn( $id ) => str_contains( $id, $plain ) || str_contains( $id, str_replace( array( 'Apply the 3 valid changes and skip the 1 rows with errors', 'Apply 3 changes', 'I understand that up to 200 new codes' ), array( 'Apply the %1$s valid changes and skip the %2$s rows with errors', 'Apply %s changes', 'I understand that up to %s new codes' ), $plain ) ) );
		if ( array() === $found ) {
			$unknown[] = $plain;
		}
	}
	pqbg_t( 'every screen name in the manual\'s source (bold) is a string of the plugin (.pot) or a known WordPress/WooCommerce label', array() === $unknown, implode( ' | ', $unknown ) );

	// ------------------------------------------------------------------ URLs
	pqbg_section( 'URL helper' );
	pqbg_t( 'AdminUrl::user_manual() and seller_guide(): the docs/ PDFs with ?ver=' . PQBG_VERSION, PQBG_PLUGIN_URL . 'docs/user-manual.pdf?ver=' . PQBG_VERSION === AdminUrl::user_manual() && PQBG_PLUGIN_URL . 'docs/seller-guide.pdf?ver=' . PQBG_VERSION === AdminUrl::seller_guide() );
	$code_only = static function ( string $file ): string {
		$code = '';
		foreach ( token_get_all( (string) file_get_contents( $file ) ) as $t ) {
			$code .= is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t;
		}
		return $code;
	};
	$docs_hits = array();
	foreach ( array_merge( glob( PQBG_PLUGIN_DIR . 'includes/*.php' ), glob( PQBG_PLUGIN_DIR . 'templates/*.php' ), glob( PQBG_PLUGIN_DIR . 'assets/*.js' ) ) as $file ) {
		if ( 'AdminUrl.php' !== basename( $file ) && preg_match( '/docs\/|user-manual|seller-guide\.pdf/', $code_only( $file ), $m ) ) {
			$docs_hits[] = basename( $file ) . ": {$m[0]}";
		}
	}
	pqbg_t( 'no document address written outside AdminUrl', array() === $docs_hits, implode( ', ', $docs_hits ) );
	$meta = PluginLinks::row_meta( array( 'a' ), 'akismet/akismet.php' );
	pqbg_t( 'PluginLinks::row_meta() leaves every other plugin\'s row unchanged', array( 'a' ) === $meta );

	// ------------------------------------------------------------------ fixtures
	pqbg_section( 'fixtures' );
	foreach ( array( 'admin' => 'administrator', 'manager' => 'shop_manager', 'seller' => 'pqbg_seller' ) as $key => $role ) {
		$pass          = wp_generate_password( 24, true, false );
		$id            = wp_insert_user( array( 'user_login' => 'pqbg_p14_' . $key, 'user_pass' => $pass, 'user_email' => 'pqbg-p14-' . $key . '@example.com', 'display_name' => 'P14 ' . $key, 'role' => $role ) );
		$users[ $key ] = array( 'id' => is_wp_error( $id ) ? 0 : $id, 'login' => 'pqbg_p14_' . $key, 'pass' => $pass );
	}
	wp_set_current_user( $users['admin']['id'] );
	$p = new WC_Product_Simple();
	$p->set_name( 'PQBG P14 sample' );
	$p->set_sku( 'PQBG-P14-SAMPLE' );
	$p->set_regular_price( '100' );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( 5 );
	$p->set_status( 'publish' );
	$product = $p->save();
	$code    = (string) ( CodeRepository::find_active_for_product( $product )['code'] ?? '' );
	pqbg_t( 'fixtures: an administrator, a shop manager, a Store Seller and a product with a code', $users['admin']['id'] && $users['manager']['id'] && $users['seller']['id'] && '' !== $code );
	$logged = array();
	foreach ( $users as $key => $u ) {
		$logged[ $key ] = $login( $key, $u['login'], $u['pass'] );
	}
	pqbg_t( 'each fixture user logs in over HTTP', ! in_array( false, $logged, true ) );

	// ------------------------------------------------------------------ the PDFs over HTTP
	pqbg_section( 'PDFs over HTTP' );
	foreach ( array( 'user manual' => array( AdminUrl::user_manual(), $pdf_file ), 'seller guide' => array( AdminUrl::seller_guide(), $dir . '/docs/seller-guide.pdf' ) ) as $label => [ $url, $file ] ) {
		foreach ( array( 'visitor', 'admin' ) as $who ) {
			$r = $http( $who, 'GET', $url );
			pqbg_t( "the {$label} for a " . ( 'visitor' === $who ? 'logged-out visitor' : 'logged-in administrator' ) . ': 200, application/pdf, the file\'s bytes', 200 === $r['code'] && str_starts_with( strtolower( $r['headers']['content-type'] ?? '' ), 'application/pdf' ) && $r['body'] === (string) file_get_contents( $file ), $r['code'] . ' ' . ( $r['headers']['content-type'] ?? '' ) . ' ' . strlen( $r['body'] ) . ' bytes' );
		}
	}

	// ------------------------------------------------------------------ Dashboard
	pqbg_section( 'Dashboard: the Plugin guide button per role' );
	$button = static function ( string $html ): array {
		$ok = preg_match( '/<a class="page-title-action pqbg-help__link" href="([^"]+)" target="_blank" rel="noopener" aria-describedby="pqbg-help-tip"><span class="dashicons dashicons-editor-help" aria-hidden="true"><\/span>Plugin guide<span class="screen-reader-text"> \(opens in a new tab\)<\/span><\/a>/', $html, $m );
		return array( (bool) $ok, $ok ? html_entity_decode( $m[1] ) : '' );
	};
	foreach ( array( 'admin' => 'administrator', 'manager' => 'shop manager' ) as $who => $label ) {
		$r            = $http( $who, 'GET', AdminUrl::dashboard() );
		[ $ok, $href ] = $button( $r['body'] );
		pqbg_t( "{$label}: the Dashboard shows \"Plugin guide\" (visible label, icon hidden from screen readers, new tab with rel=noopener, screen-reader note) to the manual", 200 === $r['code'] && $ok && AdminUrl::user_manual() === $href, $r['code'] . ' ' . $href );
		pqbg_t( "{$label}: the tooltip is a real element the button points to, with the exact text", str_contains( $r['body'], '<span class="pqbg-help__tip" id="pqbg-help-tip" role="tooltip">How to use this plugin: step-by-step guide (PDF)</span>' ) && 1 === substr_count( $r['body'], 'id="pqbg-help-tip"' ) );
		pqbg_t( "{$label}: no title attribute on the button (the tooltip is not title-only)", (bool) preg_match( '/<a class="page-title-action pqbg-help__link"(?![^>]*\btitle=)[^>]*>/', $r['body'] ) );
		pqbg_t( "{$label}: the seller guide link next to it", str_contains( $r['body'], '<a class="pqbg-help__secondary" href="' . esc_url( AdminUrl::seller_guide() ) . '" target="_blank" rel="noopener">Seller guide (1 page, for staff)<span class="screen-reader-text"> (opens in a new tab)</span></a>' ) );
		pqbg_t( "{$label}: pqbg-help.js is loaded on the Dashboard", str_contains( $r['body'], 'assets/pqbg-help.js?ver=' . PQBG_VERSION ) );
	}
	$sales = $http( 'admin', 'GET', AdminUrl::sales() );
	pqbg_t( 'the help script and button are on the Dashboard only (not on In-store sales)', 200 === $sales['code'] && ! str_contains( $sales['body'], 'pqbg-help' ) );
	$seller = $http( 'seller', 'GET', AdminUrl::dashboard() );
	pqbg_t( 'a Store Seller cannot open the Dashboard (no button)', 200 !== $seller['code'] && ! str_contains( $seller['body'], 'pqbg-help' ), (string) $seller['code'] );
	$visitor = $http( 'visitor', 'GET', AdminUrl::dashboard() );
	pqbg_t( 'a logged-out visitor is sent to the login page (no button)', 302 === $visitor['code'] && ! str_contains( $visitor['body'], 'pqbg-help' ), (string) $visitor['code'] );

	pqbg_section( 'Plugins screen' );
	$plugins = $http( 'admin', 'GET', admin_url( 'plugins.php' ) );
	$row     = preg_match( '/<tr [^>]*data-plugin="' . preg_quote( plugin_basename( PQBG_PLUGIN_FILE ), '/' ) . '".*?<\/tr>/s', $plugins['body'], $rm ) ? $rm[0] : '';
	pqbg_t( 'administrator: this plugin\'s row has "User manual" (new tab, rel=noopener, screen-reader note) to the manual', 200 === $plugins['code'] && str_contains( $row, '<a class="pqbg-manual-link" href="' . esc_url( AdminUrl::user_manual() ) . '" target="_blank" rel="noopener">User manual<span class="screen-reader-text"> (opens in a new tab)</span></a>' ) && str_contains( $row, 'Version ' . PQBG_VERSION ) );
	pqbg_t( 'the link is in this plugin\'s row only', 1 === substr_count( $plugins['body'], 'pqbg-manual-link' ) );
	$pm_plugins = $http( 'manager', 'GET', admin_url( 'plugins.php' ) );
	pqbg_t( 'a shop manager still cannot open the Plugins screen (unchanged)', 200 !== $pm_plugins['code'], (string) $pm_plugins['code'] );

	// ------------------------------------------------------------------ scan pages
	pqbg_section( 'scan pages: the seller guide link and the security headers' );
	$mine = $http( 'seller', 'GET', ScanUrl::my_sales_url() );
	pqbg_t( 'My sales: "How to sell (guide)" opens the seller guide PDF in a new tab (rel=noopener, screen-reader note)', 200 === $mine['code'] && str_contains( $mine['body'], '<a href="' . esc_url( AdminUrl::seller_guide() ) . '" target="_blank" rel="noopener">How to sell (guide)<span class="screen-reader-text"> (opens in a new tab)</span></a>' ), (string) $mine['code'] );
	$scan = $http( 'seller', 'GET', ScanUrl::for_code( $code ) );
	pqbg_t( 'the product (sell) screen has no guide link', 200 === $scan['code'] && ! str_contains( $scan['body'], 'seller-guide' ), (string) $scan['code'] );
	$expected = ScanRoute::security_headers();
	$sent     = array();
	foreach ( array( $mine, $scan ) as $r ) {
		foreach ( $expected as $name => $value ) {
			if ( ( $r['headers'][ strtolower( $name ) ] ?? null ) !== $value ) {
				$sent[] = $name . ': ' . ( $r['headers'][ strtolower( $name ) ] ?? '(missing)' );
			}
		}
	}
	pqbg_t( 'My sales and the product screen send every security header exactly as ScanRoute::security_headers() (CSP unchanged, no script allowed)', array() === $sent && str_contains( $expected['Content-Security-Policy'], "default-src 'none'" ) && ! str_contains( $expected['Content-Security-Policy'], 'script-src' ), implode( ' | ', $sent ) );
	$tagged = (string) shell_exec( 'git -C ' . escapeshellarg( $dir ) . ' show pqbg-v1.0.0:./includes/ScanRoute.php 2>&1' );
	$fn     = static function ( string $src, string $name ): string {
		return preg_match( '/\tpublic static function ' . preg_quote( $name, '/' ) . '\(.*?\n\t}\n/s', str_replace( "\r\n", "\n", $src ), $m ) ? $m[0] : '';
	};
	$now    = (string) file_get_contents( PQBG_PLUGIN_DIR . 'includes/ScanRoute.php' );
	pqbg_t( 'ScanRoute::security_headers() and csp() are byte-identical to 1.0.0 (the tagged file)', '' !== $fn( $tagged, 'security_headers' ) && $fn( $tagged, 'security_headers' ) === $fn( $now, 'security_headers' ) && '' !== $fn( $tagged, 'csp' ) && $fn( $tagged, 'csp' ) === $fn( $now, 'csp' ) );
	pqbg_t( 'the scan page is still script-free (no <script> on My sales or the product screen)', ! str_contains( $mine['body'], '<script' ) && ! str_contains( $scan['body'], '<script' ) );

	// ------------------------------------------------------------------ browser
	pqbg_section( 'browser: keyboard, focus and tooltip' );
	if ( '' === $themecheck || ! is_file( dirname( $themecheck ) . '/node_modules/puppeteer-core/package.json' ) || array() === $browsers ) {
		pqbg_skip( 'the button in a real browser', 'set PQBG_THEMECHECK to the installed theme-check tool (see tests/README.md)' );
	} else {
		$b = $node(
			__DIR__ . '/manual/help-check.mjs',
			array(
				'browser'      => $browsers[0],
				'puppeteerDir' => dirname( $themecheck ),
				'loginUrl'     => wp_login_url(),
				'user'         => $users['admin']['login'],
				'pass'         => $users['admin']['pass'],
				'dashboardUrl' => AdminUrl::dashboard(),
			)
		);
		$shown  = static fn( $t ) => is_array( $t ) && 'visible' === $t['visibility'] && '1' === (string) $t['opacity'];
		$hidden = static fn( $t ) => is_array( $t ) && 'hidden' === $t['visibility'];
		pqbg_t( 'the check ran (logged in, Dashboard opened)', ! empty( $b['ok'] ), implode( ' | ', $b['errors'] ?? array() ) );
		pqbg_t( 'the tooltip is hidden until the button is focused or hovered', $hidden( $b['hiddenAtStart'] ?? null ) );
		pqbg_t( 'Tab reaches the button; the focus is visible (an outline)', ! empty( $b['reachedByTab'] ) && ! empty( $b['focusVisible'] ) && ! str_starts_with( (string) ( $b['outline'] ?? 'none' ), 'none' ), ( $b['tabs'] ?? '?' ) . ' Tab presses, outline ' . ( $b['outline'] ?? '' ) );
		pqbg_t( 'on keyboard focus the tooltip shows; the accessible name is the visible label and the description is the tooltip', $shown( $b['onFocus'] ?? null ) && 'Plugin guide (opens in a new tab)' === ( $b['accessibleName'] ?? '' ) && 'How to use this plugin: step-by-step guide (PDF)' === ( $b['describedBy'] ?? '' ), ( $b['accessibleName'] ?? '' ) . ' / ' . ( $b['describedBy'] ?? '' ) );
		pqbg_t( 'Escape hides the tooltip; after the focus leaves and comes back it shows again', $hidden( $b['afterEscape'] ?? null ) && $shown( $b['backOnFocus'] ?? null ) );
		pqbg_t( 'on hover the tooltip shows, and stays while the pointer is on the tooltip', $hidden( $b['blurred'] ?? null ) && $shown( $b['onHover'] ?? null ) && $shown( $b['onTooltipHover'] ?? null ) );
		pqbg_t( 'Enter opens the manual in a new tab', AdminUrl::user_manual() === ( $b['newTabUrl'] ?? '' ), (string) ( $b['newTabUrl'] ?? '' ) );
		pqbg_t( 'no JavaScript error or console error on the Dashboard', array() === ( $b['console'] ?? array( 1 ) ) && empty( $b['errors'] ), implode( ' | ', $b['console'] ?? array() ) );
	}

	pqbg_section( 'translation template' );
	$new = array( 'Plugin guide', 'How to use this plugin: step-by-step guide (PDF)', '(opens in a new tab)', 'Seller guide (1 page, for staff)', 'User manual', 'How to sell (guide)' );
	pqbg_t( 'the .pot has the new strings', array() === array_diff( $new, $msgids ), implode( ', ', array_diff( $new, $msgids ) ) );
} finally {
	pqbg_section( 'cleanup' );
	if ( $product ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::codes_table() . ' WHERE product_id = %d', $product ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier.
		wp_delete_post( $product, true );
	}
	foreach ( $users as $u ) {
		if ( $u['id'] ) {
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d", $u['id'] ) ) as $post_id ) {
				wp_delete_post( (int) $post_id, true );
			}
			wp_delete_user( $u['id'] );
		}
	}
	if ( 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::codes_table() ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier.
		$wpdb->query( 'ALTER TABLE ' . Schema::codes_table() . ' AUTO_INCREMENT = 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier.
	}
	$opts1 = $pqbg_opts();
	foreach ( $opts1 as $name => $row ) {
		if ( ! isset( $opts0[ $name ] ) ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		} elseif ( $opts0[ $name ]->option_value !== $row->option_value || $opts0[ $name ]->autoload !== $row->autoload ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $opts0[ $name ]->option_value, 'autoload' => $opts0[ $name ]->autoload ), array( 'option_name' => $name ) );
		}
	}
	foreach ( array_diff_key( $opts0, $opts1 ) as $name => $row ) {
		$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $row->option_value, 'autoload' => $row->autoload ) );
	}
	pqbg_test_as_cleanup( $mark );
	wp_cache_flush();
	$opts2 = $pqbg_opts();
	pqbg_t( 'cleanup: the plugin\'s options (incl. the Dashboard timing samples) byte-identical to the start', array_keys( $opts2 ) === array_keys( $opts0 ) && array() === array_filter( array_keys( $opts2 ), static fn( $n ) => $opts2[ $n ]->option_value !== $opts0[ $n ]->option_value ) );
	pqbg_t( 'cleanup: no fixture user or product left', ! get_user_by( 'login', 'pqbg_p14_admin' ) && ! get_user_by( 'login', 'pqbg_p14_seller' ) && ! get_user_by( 'login', 'pqbg_p14_manager' ) && ! wc_get_product_id_by_sku( 'PQBG-P14-SAMPLE' ) );
	pqbg_test_as_check( $mark );
}

pqbg_test_done();
