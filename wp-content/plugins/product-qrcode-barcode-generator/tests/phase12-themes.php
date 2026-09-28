<?php
/**
 * Phase 12 suite: theme compatibility (owner's decision B).
 *
 * Under each theme in THEMES (block: Twenty Twenty-Five, Twenty Twenty-Four; classic: Storefront,
 * Astra, Kadence, OceanWP), activated with switch_theme() as on a real site:
 *   - the scan pages (entry box, every product status, the sale page with Undo, My sales, 400, 403,
 *     the login page) over HTTP: status, security headers, only the plugin stylesheet, no script,
 *     nothing from the theme; and in headless Edge and Chrome: no console/page errors, failed
 *     requests or CSP violations, no horizontal scroll at 375/320/1280 px, touch targets of at
 *     least 44 px, the entry box focused and a scanner's "code + Enter" landing on the code page,
 *     computed styles and screenshots identical to Twenty Twenty-Five's (the theme cannot reach
 *     the page), the login round trip, and Back after Log out;
 *   - the theme's own store pages (home, shop, products, block and classic cart and checkout with
 *     an item, My Account, the Coming Soon page): 200, no plugin asset or markup, and no console
 *     error that is not also there with the plugin switched off for that request (Chrome);
 *   - the plugin's admin pages load and QR & Barcodes stays directly below Products;
 *   - no PHP notice from plugin code (the error-capture log, PQBG_ERROR_CAPTURE).
 * Then: permalinks (six structures set through the real Settings → Permalinks form; the notice,
 * Health check error and Dashboard warning for Plain and index.php), and a page cache (WP Fastest
 * Cache, under Twenty Twenty-Five and Storefront): a second user or a logged-out visitor never gets
 * the first user's page, a logged-out PUT does not poison /scan/, no cache file for any scan path.
 *
 * Screenshots (phone and desktop, Chrome) of every staff-facing screen per theme go to PQBG_SCREENS.
 *
 * Tools (all required; a missing one FAILS the tool check, nothing is skipped):
 *   PQBG_THEMECHECK    tests/theme-check/check.mjs installed with npm ci (default: this folder's copy)
 *   PQBG_THEME_PACKS   folder with storefront.4.6.2.zip, astra.4.14.0.zip, kadence.1.5.2.zip, oceanwp.4.2.6.zip
 *   PQBG_CACHE_PLUGIN  wp-fastest-cache.1.5.2.zip
 *   PQBG_ERROR_CAPTURE the temporary error logger's folder (as for run.php)
 *   PQBG_SCREENS       where screenshots go (default: the system temp folder)
 *
 * Restores the site exactly: the theme and every option (theme_mods, widgets, the placeholder
 * image's regenerated sizes, permalinks, rewrite rules), .htaccess byte for byte; removes the
 * theme and plugin folders it unzipped, its posts, users, codes, sales, sessions and a temporary
 * must-use file; a final guard compares everything with the state at the start (and with
 * C:\xampp\backups\sharayu\phase12-theme-snapshot-before.json when present).
 *
 *   php tests/phase12-themes.php
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/bootstrap.php';

pqbg_test_load_wp();
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/post.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';

use ProductQrBarcode\{AdminUrl, CodeRepository, HealthCheck, Permissions, ProductCodeService, SaleRepository, SaleRequest, SaleService, ScanRoute, ScanUrl, Schema};

global $wpdb, $wp_rewrite;

const PQBG12_THEMES = array(
	'twentytwentyfive' => '',
	'twentytwentyfour' => '',
	'storefront'       => 'storefront.4.6.2.zip',
	'astra'            => 'astra.4.14.0.zip',
	'kadence'          => 'kadence.1.5.2.zip',
	'oceanwp'          => 'oceanwp.4.2.6.zip',
);
const PQBG12_CACHE_SLUG = 'wp-fastest-cache/wpFastestCache.php';

$SUITE     = basename( __FILE__ );
$C         = Schema::codes_table();
$S         = Schema::sales_table();
$home      = untrailingslashit( home_url() );
$packs     = rtrim( str_replace( '\\', '/', (string) getenv( 'PQBG_THEME_PACKS' ) ), '/' );
$cache_zip = str_replace( '\\', '/', (string) getenv( 'PQBG_CACHE_PLUGIN' ) );
$capture   = rtrim( str_replace( '\\', '/', (string) getenv( 'PQBG_ERROR_CAPTURE' ) ), '/' );
$shots     = (string) getenv( 'PQBG_SCREENS' );
$shots     = '' !== $shots ? str_replace( '\\', '/', $shots ) : str_replace( '\\', '/', sys_get_temp_dir() ) . '/pqbg-phase12-screens';
$checker   = getenv( 'PQBG_THEMECHECK' );
$checker   = ( is_string( $checker ) && '' !== $checker ) ? $checker : __DIR__ . '/theme-check/check.mjs';
$browsers  = getenv( 'PQBG_BROWSERS' );
$browsers  = ( is_string( $browsers ) && '' !== $browsers ) ? explode( ';', $browsers ) : array( 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe', 'C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium' );
$browsers  = array_values( array_filter( $browsers, 'is_file' ) );
$by_name   = array();
foreach ( $browsers as $exe ) {
	$by_name[ str_contains( strtolower( $exe ), 'edge' ) ? 'Edge' : 'Chrome' ] ??= $exe;
}
$tmp = str_replace( '\\', '/', sys_get_temp_dir() ) . '/pqbg-p12-' . wp_generate_password( 8, false );
wp_mkdir_p( $tmp );

// ---------------------------------------------------------------- state at the start (the restore guard)
$RUNTIME = static function ( string $name ): bool {
	// Options the site itself rewrites while any suite runs (WP-Cron, Action Scheduler, caches).
	return in_array( $name, array( 'cron', 'action_scheduler_migration_status' ), true )
		|| str_starts_with( $name, '_transient_' ) || str_starts_with( $name, '_site_transient_' )
		|| str_starts_with( $name, 'action_scheduler_lock_' );
};
$options_now = static function () use ( $wpdb ): array {
	$out = array();
	foreach ( $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options}", ARRAY_A ) as $r ) {
		$out[ $r['option_name'] ] = array( $r['option_value'], $r['autoload'] );
	}
	return $out;
};
$dir_list = static function ( string $dir ): array {
	return is_dir( $dir ) ? array_values( array_diff( scandir( $dir ), array( '.', '..' ) ) ) : array();
};
$uploads     = wp_upload_dir()['basedir'];
$placeholder = (int) get_option( 'woocommerce_placeholder_image', 0 );
$ph_files    = static function () use ( $uploads ): array {
	$out = array();
	foreach ( glob( $uploads . '/woocommerce-placeholder*' ) ?: array() as $f ) {
		$out[ basename( $f ) ] = (string) file_get_contents( $f );
	}
	return $out;
};
$start = array(
	'options'     => $options_now(),
	'stylesheet'  => get_option( 'stylesheet' ),
	'posts'       => $wpdb->get_results( "SELECT ID, post_type, post_status, post_modified_gmt, SHA1(post_content) h FROM {$wpdb->posts} ORDER BY ID", OBJECT_K ),
	'post_max'    => (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID), 0) FROM {$wpdb->posts}" ),
	'term_max'    => (int) $wpdb->get_var( "SELECT COALESCE(MAX(term_id), 0) FROM {$wpdb->terms}" ),
	'ph_meta'     => $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", $placeholder ), ARRAY_A ),
	'ph_files'    => $ph_files(),
	'uploads'     => $dir_list( $uploads ),
	'wc_logs'     => $dir_list( $uploads . '/wc-logs' ),
	'themes'      => $dir_list( get_theme_root() ),
	'plugins'     => $dir_list( WP_PLUGIN_DIR ),
	'content'     => $dir_list( WP_CONTENT_DIR ),
	'mu'          => $dir_list( WPMU_PLUGIN_DIR ),
	'htaccess'    => (string) file_get_contents( ABSPATH . '.htaccess' ),
	'wpconfig'    => sha1_file( ABSPATH . 'wp-config.php' ),
	'users'       => (int) count_users()['total_users'],
	'codes'       => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $C" ),
	'sales'       => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $S" ),
	'sessions'    => (int) $wpdb->get_var( "SELECT COALESCE(MAX(session_id), 0) FROM {$wpdb->prefix}woocommerce_sessions" ),
	'structure'   => (string) get_option( 'permalink_structure' ),
);
$as_mark = pqbg_test_as_mark();

// Kept until the cleanup has run, so tests/phase12-repair.php can restore a run that was killed.
$state_file = str_replace( '\\', '/', sys_get_temp_dir() ) . '/pqbg-phase12-start.ser';
file_put_contents( $state_file, serialize( array_merge( $start, array( 'as_mark' => $as_mark ) ) ) );

// A temporary must-use file: a request carrying the header below runs without this plugin (the
// "plugin off" half of the console-error comparison). Nothing is written; the file is removed in cleanup.
$off_token = wp_generate_password( 32, false );
$mu_file   = WPMU_PLUGIN_DIR . '/pqbg-phase12-plugin-off.php';
$mu_made   = ! is_dir( WPMU_PLUGIN_DIR );

$user_ids = array();
$pw       = array();
$handles  = array();
$made     = array(
	'themes'  => array(),
	'plugin'  => false,
	'cache'   => false,
);

// ---------------------------------------------------------------- helpers
$http = static function ( string $who, string $method, string $url, array|string|null $post = null, array $headers = array() ) use ( &$handles ): array {
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
			CURLOPT_NOBODY         => 'HEAD' === $method,
			CURLOPT_CUSTOMREQUEST  => in_array( $method, array( 'GET', 'POST', 'HEAD' ), true ) ? null : $method,
			CURLOPT_POST           => 'POST' === $method,
			CURLOPT_HTTPGET        => 'GET' === $method,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_USERAGENT      => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Mobile Safari/537.36',
		)
	);
	if ( 'POST' === $method ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, is_array( $post ) ? http_build_query( $post ) : (string) $post );
	}
	$raw  = (string) curl_exec( $ch );
	$size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	$hdrs = array();
	foreach ( preg_split( '/\r?\n/', substr( $raw, 0, $size ) ) as $line ) {
		if ( preg_match( '/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m ) ) {
			$hdrs[ strtolower( $m[1] ) ] = trim( $m[2] );
		}
	}
	curl_setopt( $ch, CURLOPT_NOBODY, false );
	return array(
		'code'     => (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ),
		'location' => $hdrs['location'] ?? '',
		'headers'  => $hdrs,
		'body'     => substr( $raw, $size ),
	);
};
$login = static function ( string $who, string $user, string $pass, ?string $redirect_to = null ) use ( $http ): array {
	$http( $who, 'GET', wp_login_url() );
	$post = array( 'log' => $user, 'pwd' => $pass, 'wp-submit' => 'Log In', 'testcookie' => '1' );
	if ( null !== $redirect_to ) {
		$post['redirect_to'] = $redirect_to;
	}
	return $http( $who, 'POST', wp_login_url(), $post );
};
$cookies_of = static function ( string $who ) use ( &$handles ): array {
	$list = array();
	foreach ( (array) curl_getinfo( $handles[ $who ], CURLINFO_COOKIELIST ) as $line ) {
		$f = explode( "\t", $line );
		if ( 7 === count( $f ) ) {
			$list[] = array(
				'name'   => $f[5],
				'value'  => $f[6],
				'domain' => ltrim( str_replace( '#HttpOnly_', '', $f[0] ), '.' ),
				'path'   => $f[2],
			);
		}
	}
	return $list;
};
$field = static function ( string $html, string $name ): string {
	preg_match_all( '/<input\b[^>]*>/i', $html, $m );
	foreach ( $m[0] as $tag ) {
		if ( preg_match( '/\bname=["\']' . preg_quote( $name, '/' ) . '["\']/', $tag ) && preg_match( '/\bvalue=["\']([^"\']*)["\']/', $tag, $v ) ) {
			return html_entity_decode( $v[1], ENT_QUOTES );
		}
	}
	return '';
};
$top_ids = static function ( string $html ): array {
	preg_match_all( '/<li class="[^"]*\bmenu-top\b[^"]*" id="([^"]+)"/', $html, $m );
	return $m[1];
};
$rm_tree = static function ( string $dir ) use ( &$rm_tree ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
		$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
	rmdir( $dir );
};
/** Unzips a package into a folder with WordPress's own unzip_file() (PclZip when ZipArchive is missing). */
$unzip = static function ( string $zip, string $to ): bool {
	global $wp_filesystem;
	if ( ! $wp_filesystem ) {
		WP_Filesystem();
	}
	return true === unzip_file( $zip, $to );
};
/** Events the error logger recorded for this suite after a byte offset, split into plugin code and the rest. */
$capture_since = static function ( int $offset ) use ( $capture, $SUITE ): array {
	$plugin = str_replace( '\\', '/', dirname( __DIR__ ) ) . '/';
	$out    = array(
		'plugin' => array(),
		'other'  => array(),
	);
	$log    = $capture . '/capture.log';
	$h      = is_file( $log ) ? fopen( $log, 'rb' ) : false;
	if ( false === $h ) {
		return $out;
	}
	fseek( $h, $offset );
	while ( false !== ( $line = fgets( $h ) ) ) {
		$row = json_decode( $line, true );
		if ( ! is_array( $row ) || ( $row['suite'] ?? '' ) !== $SUITE ) {
			continue;
		}
		foreach ( (array) $row['events'] as $e ) {
			$file = (string) $e['file'];
			$text = "[{$e['level']}] " . substr( (string) $e['message'], 0, 160 ) . ' @ ' . preg_replace( '#^.*/wp-content/#', '', $file ) . ":{$e['line']}";
			if ( str_starts_with( $file, $plugin ) && ! str_starts_with( $file, $plugin . 'tests/' ) ) {
				$out['plugin'][] = $text;
			} else {
				$out['other'][ $text ] = true;
			}
		}
	}
	fclose( $h );
	$out['other'] = array_keys( $out['other'] );
	return $out;
};
$capture_size = static function () use ( $capture ): int {
	clearstatcache();
	return is_file( $capture . '/capture.log' ) ? (int) filesize( $capture . '/capture.log' ) : 0;
};
/** Runs the browser tool on a job; returns its decoded JSON or null. */
/** Screenshots: Chrome's go to PQBG_SCREENS for the owner; Edge's stay in the suite's temp folder (compared, then removed). */
$shot_root_of = static fn( string $bname ): string => 'Chrome' === $bname ? $shots : "$tmp/shots-" . strtolower( $bname );
/**
 * Pixels that differ between two PNG screenshots, or -1 when their sizes differ. Only used when the
 * SHA-256 hashes differ: Chromium's anti-aliasing of a border edge varies by a few dozen pixels between
 * renders of the same page, while anything a theme could change (a font, a colour, spacing) changes
 * thousands.
 */
$png_diff = static function ( string $a, string $b ): array {
	if ( ! is_file( $a ) || ! is_file( $b ) ) {
		return array( -1, 0 );
	}
	$ia = imagecreatefrompng( $a );
	$ib = imagecreatefrompng( $b );
	$w  = imagesx( $ia );
	$h  = imagesy( $ia );
	if ( imagesx( $ib ) !== $w || imagesy( $ib ) !== $h ) {
		return array( -1, $w * $h );
	}
	$n = 0;
	for ( $y = 0; $y < $h; $y++ ) {
		for ( $x = 0; $x < $w; $x++ ) {
			if ( imagecolorat( $ia, $x, $y ) !== imagecolorat( $ib, $x, $y ) ) {
				++$n;
			}
		}
	}
	return array( $n, $w * $h );
};
$run_browser  = static function ( string $exe, string $name, array $pages, string $shot_root ) use ( $checker, $tmp ): ?array {
	$dir  = "$tmp/$name";
	$spec = array(
		'browser' => $exe,
		'out'     => $dir,
		'shots'   => $shot_root,
		'pages'   => $pages,
	);
	wp_mkdir_p( $dir );
	file_put_contents( "$dir.json", wp_json_encode( $spec ) );
	$raw = (string) shell_exec( 'node ' . escapeshellarg( $checker ) . ' ' . escapeshellarg( "$dir.json" ) . ' 2>&1' );
	$res = json_decode( $raw, true );
	if ( ! is_array( $res ) ) {
		echo '   theme-check output: ' . substr( $raw, 0, 2000 ) . "\n";
		return null;
	}
	return $res;
};

$expected_headers = ScanRoute::security_headers();
$headers_ok       = static function ( array $r, string $nonce = '' ) use ( $expected_headers ): bool {
	foreach ( $expected_headers as $name => $value ) {
		if ( 'Content-Security-Policy' === $name ) {
			$value = ScanRoute::csp( $nonce );
		}
		if ( ( $r['headers'][ strtolower( $name ) ] ?? null ) !== $value ) {
			return false;
		}
	}
	return ! isset( $r['headers']['last-modified'] ) && ! isset( $r['headers']['etag'] );
};
$only_plugin_css = static function ( string $body ): bool {
	preg_match_all( '/<link\b[^>]*rel=["\']stylesheet["\'][^>]*>/i', $body, $m );
	return 1 === count( $m[0] ) && str_contains( $m[0][0], '/product-qrcode-barcode-generator/assets/pqbg-scan.css' )
		&& ! str_contains( $body, '<script' ) && ! str_contains( $body, '/wp-content/themes/' ) && ! str_contains( $body, 'id="wpadminbar"' );
};

$theme_results = array();
$hashes        = array();
$styles_seen   = array();

try {
	pqbg_section( 'tools' );
	$node_v = trim( (string) shell_exec( 'node --version 2>&1' ) );
	pqbg_t( 'Node.js and the theme-check tool are installed (PQBG_THEMECHECK)', str_starts_with( $node_v, 'v' ) && is_file( $checker ) && is_dir( dirname( $checker ) . '/node_modules/puppeteer-core' ), $node_v . ' ' . $checker );
	pqbg_t( 'Edge and Chrome are both available', isset( $by_name['Edge'], $by_name['Chrome'] ), implode( ', ', array_keys( $by_name ) ) );
	$missing_packs = array_filter( PQBG12_THEMES, static fn( $zip ) => '' !== $zip && ! is_file( "$packs/$zip" ) );
	pqbg_t( 'every theme package is present (PQBG_THEME_PACKS)', '' !== $packs && array() === $missing_packs, implode( ', ', $missing_packs ) );
	pqbg_t( 'the cache plugin package is present (PQBG_CACHE_PLUGIN)', is_file( $cache_zip ) && str_ends_with( $cache_zip, 'wp-fastest-cache.1.5.2.zip' ) );
	pqbg_t( 'the error logger is active for this suite (PQBG_ERROR_CAPTURE, as run.php sets it up)', '' !== $capture && is_file( WPMU_PLUGIN_DIR . '/pqbg-error-capture.php' ) && is_file( $capture . '/ACTIVE' ) && $SUITE === trim( (string) file_get_contents( $capture . '/ACTIVE' ) ) );
	pqbg_t( 'the site starts on its own theme with pretty permalinks and Coming Soon on', 'twentytwentyfive' === $start['stylesheet'] && '/%postname%/' === $start['structure'] && 'yes' === get_option( 'woocommerce_coming_soon' ) );
	pqbg_t( 'no test theme, cache plugin or cache folder is installed at the start', array() === array_intersect( array( 'storefront', 'astra', 'kadence', 'oceanwp' ), $start['themes'] ) && ! in_array( 'wp-fastest-cache', $start['plugins'], true ) && ! in_array( 'cache', $start['content'], true ) );
	$tools_ok = 0 === $GLOBALS['pqbg_test_fail'];
	if ( ! $tools_ok ) {
		throw new RuntimeException( 'A required tool is missing; see the failed checks above and tests/README.md.' );
	}

	pqbg_section( 'setup' );
	wp_mkdir_p( WPMU_PLUGIN_DIR );
	file_put_contents( $mu_file, "<?php\n// Phase 12 test file (temporary; removed by tests/phase12-themes.php). A request with the header below runs without the plugin.\nif ( isset( \$_SERVER['HTTP_X_PQBG_TEST_OFF'] ) && hash_equals( '{$off_token}', (string) \$_SERVER['HTTP_X_PQBG_TEST_OFF'] ) ) {\n\tadd_filter( 'option_active_plugins', static fn( \$p ) => array_values( array_diff( (array) \$p, array( 'product-qrcode-barcode-generator/product-qrcode-barcode-generator.php' ) ) ) );\n}\n" );
	foreach ( array( 'admin' => 'administrator', 'sm' => 'shop_manager', 'seller' => 'pqbg_seller', 'seller2' => 'pqbg_seller', 'customer' => 'customer' ) as $who => $role ) {
		$pw[ $who ]       = wp_generate_password( 24, false );
		$user_ids[ $who ] = wp_insert_user( array( 'user_login' => "p12t_{$who}", 'user_pass' => $pw[ $who ], 'user_email' => "p12t-{$who}@example.invalid", 'role' => $role, 'display_name' => 'P12 ' . $who ) );
	}
	pqbg_t( 'temporary users created', 5 === count( array_filter( $user_ids, 'is_int' ) ) );
	$A   = $user_ids['admin'];
	$pcs = new ProductCodeService();

	// A small real image (so every theme's woocommerce_thumbnail size applies).
	$img = imagecreatetruecolor( 600, 600 );
	imagefill( $img, 0, 0, imagecolorallocate( $img, 190, 40, 90 ) );
	imagefilledrectangle( $img, 150, 150, 450, 450, imagecolorallocate( $img, 250, 220, 120 ) );
	ob_start();
	imagepng( $img );
	$png = (string) ob_get_clean();
	$up  = wp_upload_bits( 'p12t-' . wp_generate_password( 6, false ) . '.png', null, $png );
	$att = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'P12T image', 'post_status' => 'inherit' ), $up['file'] );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $up['file'] ) );
	$cat = (int) wp_insert_term( 'P12T Sarees', 'product_cat' )['term_id'];

	$make = static function ( array $props, string $type = 'simple' ) use ( $A ): WC_Product {
		wp_set_current_user( $A );
		$p = 'variable' === $type ? new WC_Product_Variable() : new WC_Product_Simple();
		$p->set_props( array_merge( array( 'status' => 'publish', 'regular_price' => '1499' ), $props ) );
		$p->save();
		wp_set_current_user( 0 );
		return $p;
	};
	$P_in    = $make( array( 'name' => 'P12T Banarasi saree with a long name that must wrap on a phone', 'sku' => 'P12T-IN', 'sale_price' => '1199', 'manage_stock' => true, 'stock_quantity' => 7, 'image_id' => $att, 'category_ids' => array( $cat ) ) );
	$P_out   = $make( array( 'name' => 'P12T Cotton kurta', 'sku' => 'P12T-OUT', 'manage_stock' => true, 'stock_quantity' => 0, 'category_ids' => array( $cat ) ) );
	$P_sale  = $make( array( 'name' => 'P12T Dupatta', 'sku' => 'P12T-SALE', 'manage_stock' => true, 'stock_quantity' => 50 ) );
	$P_fixed = $make( array( 'name' => 'P12T Silk stole', 'sku' => 'P12T-FIXED', 'manage_stock' => true, 'stock_quantity' => 9 ) );
	$P_ret   = $make( array( 'name' => 'P12T Retired item', 'sku' => 'P12T-RET', 'manage_stock' => true, 'stock_quantity' => 3 ) );
	$attr    = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( array( 'S', 'M' ) );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$P_var = $make( array( 'name' => 'P12T Lehenga', 'attributes' => array( $attr ), 'category_ids' => array( $cat ), 'image_id' => $att ), 'variable' );
	$vids  = array();
	foreach ( array( 'S', 'M' ) as $size ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $P_var->get_id() );
		$v->set_attributes( array( 'size' => $size ) );
		$v->set_regular_price( '2999' );
		$v->set_manage_stock( true );
		$v->set_stock_quantity( 4 );
		$v->set_sku( 'P12T-VAR-' . $size );
		$vids[] = (int) $v->save();
	}
	$code_of = static function ( int $id ) use ( $pcs, $A ): string {
		$row = $pcs->get_or_create( $id, $A );
		return is_array( $row ) ? (string) $row['code'] : '';
	};
	$c_in    = $code_of( $P_in->get_id() );
	$c_out   = $code_of( $P_out->get_id() );
	$c_sale  = $code_of( $P_sale->get_id() );
	$c_fixed = $code_of( $P_fixed->get_id() );
	$c_var   = $code_of( $vids[0] );
	$c_old   = $code_of( $P_ret->get_id() );
	$pcs->regenerate( $P_ret->get_id(), $A );
	$c_new_r = $code_of( $P_ret->get_id() );
	do {
		$c_unknown = 'DC-' . implode( '-', array_map( static fn() => substr( str_shuffle( 'ABCDEFGHJKMNPQRSTUVWXYZ23456789' ), 0, 4 ), array( 1, 2, 3 ) ) );
	} while ( CodeRepository::code_exists( $c_unknown ) );
	pqbg_t( 'fixtures: products, a variation, codes (one retired), an image', '' !== $c_in && '' !== $c_out && '' !== $c_sale && '' !== $c_fixed && '' !== $c_var && '' !== $c_new_r && $c_old !== $c_new_r && $att > 0 );
	$fixed_sale = SaleService::sell( array( 'code' => $c_fixed, 'quantity' => 1, 'request_id' => wp_generate_uuid4(), 'seller_id' => $user_ids['seller'], 'payment_method' => 'cash' ) );
	$fixed_id   = is_wp_error( $fixed_sale ) ? 0 : (int) $fixed_sale['sale']['id'];
	pqbg_t( 'fixture sale for the seller\'s My sales', $fixed_id > 0 );

	// Classic (shortcode) cart and checkout next to the real block pages; the WooCommerce page options are not changed.
	$page_of = static fn( string $title, string $sc ) => (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => sanitize_title( $title ), 'post_content' => "<!-- wp:shortcode -->[{$sc}]<!-- /wp:shortcode -->" ) );
	$cart_classic     = $page_of( 'P12T classic cart', 'woocommerce_cart' );
	$checkout_classic = $page_of( 'P12T classic checkout', 'woocommerce_checkout' );
	pqbg_t( 'block cart/checkout pages are blocks; the classic test pages use shortcodes', has_block( 'woocommerce/cart', wc_get_page_id( 'cart' ) ) && has_block( 'woocommerce/checkout', wc_get_page_id( 'checkout' ) ) && $cart_classic > 0 && $checkout_classic > 0 );

	foreach ( array_keys( $user_ids ) as $who ) {
		$login( $who, "p12t_{$who}", $pw[ $who ] );
	}
	pqbg_t( 'every temporary user is logged in over HTTP', array() === array_filter( array_keys( $user_ids ), static fn( $w ) => ! str_contains( implode( ' ', array_column( $cookies_of( $w ), 'name' ) ), 'wordpress_logged_in' ) ) );

	// Expected statuses, decided in-process by the plugin as the relevant user (the HTTP answers must match).
	$expect = static function ( string $who, string $code ) use ( $user_ids ): int {
		wp_set_current_user( $user_ids[ $who ] );
		$path = (string) wp_parse_url( ScanUrl::site_url( $code ), PHP_URL_PATH );
		$r    = ScanRoute::decide( 'GET', $path, $code, null );
		wp_set_current_user( 0 );
		return (int) $r['status'];
	};
	$long_invalid = str_repeat( 'X', 100 );
	$screens      = array(
		// name => [ who, url, expected status, required selectors ]
		'entry'        => array( 'seller', ScanUrl::site_url(), 200, array( '#pqbg-code', '.pqbg-scan__button', '.pqbg-scan__nav', '.pqbg-scan__logout' ) ),
		'entry-bad'    => array( 'seller', add_query_arg( 'code', 'not-a-code', ScanUrl::site_url() ), 400, array( '#pqbg-code', '.pqbg-scan__notice' ) ),
		'in-stock'     => array( 'seller', ScanUrl::site_url( $c_in ), $expect( 'seller', $c_in ), array( '#pqbg-code', '.pqbg-scan__name', '.pqbg-scan__price', '.pqbg-scan__stock', '.pqbg-scan__sell', '.pqbg-scan__payment', '.pqbg-scan__image' ) ),
		'variation'    => array( 'seller', ScanUrl::site_url( $c_var ), $expect( 'seller', $c_var ), array( '.pqbg-scan__name', '.pqbg-scan__attributes', '.pqbg-scan__sell' ) ),
		'out-of-stock' => array( 'seller', ScanUrl::site_url( $c_out ), $expect( 'seller', $c_out ), array( '.pqbg-scan__name', '.pqbg-scan__stock' ) ),
		'retired'      => array( 'sm', ScanUrl::site_url( $c_old ), $expect( 'sm', $c_old ), array( '.pqbg-scan__notice' ) ),
		'not-found'    => array( 'seller', ScanUrl::site_url( $c_unknown ), $expect( 'seller', $c_unknown ), array( '.pqbg-scan__notice', '#pqbg-code' ) ),
		'invalid'      => array( 'seller', $home . '/scan/' . $long_invalid . '/', 400, array( '.pqbg-scan__notice' ) ),
		'my-sales'     => array( 'seller', ScanUrl::my_sales_url(), 200, array( '.pqbg-scan__tabs', '.pqbg-scan__table', '.pqbg-scan__list' ) ),
		'my-sales-7d'  => array( 'seller', ScanUrl::my_sales_url( '7d' ), 200, array( '.pqbg-scan__tabs', '.pqbg-scan__table' ) ),
		'forbidden'    => array( 'customer', ScanUrl::site_url( $c_in ), 403, array( '.pqbg-scan__notice' ) ),
	);
	pqbg_t( 'expected statuses decided in-process: in stock 200, not found 404', 200 === $screens['in-stock'][2] && 404 === $screens['not-found'][2], wp_json_encode( array_map( static fn( $s ) => $s[2], $screens ) ) );
	$style_selectors = array( 'body', '.pqbg-scan__bar', '.pqbg-scan__input', '.pqbg-scan__button', '.pqbg-scan__notice', '.pqbg-scan__name', '.pqbg-scan__table', '.pqbg-scan__logout' );
	$pixel_screens   = array_keys( $screens );

	$theme_pages = array(
		'home'              => array( 'admin', home_url( '/' ), array() ),
		'shop'              => array( 'admin', get_permalink( wc_get_page_id( 'shop' ) ), array() ),
		'product-simple'    => array( 'admin', get_permalink( $P_in->get_id() ), array() ),
		'product-variable'  => array( 'admin', get_permalink( $P_var->get_id() ), array() ),
		'cart-block'        => array( 'admin', get_permalink( wc_get_page_id( 'cart' ) ), array( home_url( '/?add-to-cart=' . $P_in->get_id() ) ) ),
		'checkout-block'    => array( 'admin', get_permalink( wc_get_page_id( 'checkout' ) ), array( home_url( '/?add-to-cart=' . $P_in->get_id() ) ) ),
		'cart-classic'      => array( 'admin', get_permalink( $cart_classic ), array( home_url( '/?add-to-cart=' . $P_in->get_id() ) ) ),
		'checkout-classic'  => array( 'admin', get_permalink( $checkout_classic ), array( home_url( '/?add-to-cart=' . $P_in->get_id() ) ) ),
		'my-account'        => array( 'admin', wc_get_page_permalink( 'myaccount' ), array() ),
		'my-account-orders' => array( 'admin', wc_get_account_endpoint_url( 'orders' ), array() ),
		'product-sm'        => array( 'sm', get_permalink( $P_in->get_id() ), array() ),
		'coming-soon'       => array( '', home_url( '/' ), array() ),
	);

	// ------------------------------------------------------------ the theme matrix
	foreach ( PQBG12_THEMES as $theme => $zip ) {
		pqbg_section( "theme: {$theme}" );
		$cap0 = $capture_size();
		if ( '' !== $zip ) {
			$ok_zip = $unzip( "$packs/$zip", get_theme_root() );
			if ( $ok_zip ) {
				$made['themes'][] = $theme;
			}
			pqbg_t( "{$theme}: unzipped from the wordpress.org package", $ok_zip && wp_get_theme( $theme )->exists() && '' === (string) wp_get_theme( $theme )->errors() );
		}
		switch_theme( $theme );
		wp_cache_flush();
		$is_block = wp_get_theme( $theme )->is_block_theme();
		pqbg_t( "{$theme}: active (" . ( $is_block ? 'block' : 'classic' ) . ' theme, version ' . wp_get_theme( $theme )->get( 'Version' ) . ')', $theme === get_option( 'stylesheet' ) );
		// The first requests after a switch run after_switch_theme (and WooCommerce's theme-switch work).
		$http( 'admin', 'GET', admin_url() );
		$http( 'admin', 'GET', home_url( '/' ) );

		// HTTP: the scan pages.
		$bad = array();
		foreach ( $screens as $name => $s ) {
			$r = $http( $s[0], 'GET', $s[1] );
			if ( $s[2] !== $r['code'] || ! $headers_ok( $r ) || ! $only_plugin_css( $r['body'] ) || ! str_contains( $r['body'], '<body class="pqbg-scan">' ) ) {
				$bad[] = "{$name}: {$r['code']}";
			}
		}
		pqbg_t( "{$theme}: every scan screen answers as decided, with the security headers, only the plugin stylesheet, no script, nothing from the theme", array() === $bad, implode( ', ', $bad ) );
		$sale = SaleService::sell( array( 'code' => $c_sale, 'quantity' => 1, 'request_id' => wp_generate_uuid4(), 'seller_id' => $user_ids['seller2'], 'payment_method' => 'upi' ) );
		$sid  = is_wp_error( $sale ) ? 0 : (int) $sale['sale']['id'];
		$sale_url = add_query_arg( SaleRequest::SALE_ARG, $sid, ScanUrl::site_url( $c_sale ) );
		$r        = $http( 'seller2', 'GET', $sale_url );
		preg_match( '/<style nonce="([A-Za-z0-9_-]+)">/', $r['body'], $nm );
		pqbg_t( "{$theme}: the sale page with Undo (its style nonce is the only extra in the CSP)", $sid > 0 && 200 === $r['code'] && str_contains( $r['body'], '<form class="pqbg-scan__undo"' ) && $headers_ok( $r, $nm[1] ?? '' ) );
		$r = $http( 'seller', 'PUT', ScanUrl::site_url( $c_in ) );
		pqbg_t( "{$theme}: logged in, PUT → 405 with the headers; logged out, any method → 302 to the login page", 405 === $r['code'] && $headers_ok( $r ) && 302 === $http( 'anon', 'PUT', ScanUrl::site_url( $c_in ) )['code'] && 302 === $http( 'anon', 'GET', ScanUrl::site_url( $c_in ) )['code'] );

		// HTTP: the login round trip through wp-login.php.
		$rt   = 'rt-' . $theme;
		$r1   = $http( $rt, 'GET', ScanUrl::site_url( $c_in ) );
		$lp   = $http( $rt, 'GET', $r1['location'] );
		$r2   = $http( $rt, 'POST', wp_login_url(), array( 'log' => 'p12t_seller', 'pwd' => $pw['seller'], 'wp-submit' => 'Log In', 'testcookie' => '1', 'redirect_to' => $field( $lp['body'], 'redirect_to' ) ) );
		$r3   = $http( $rt, 'GET', $r2['location'] );
		pqbg_t( "{$theme}: login round trip: code URL → wp-login.php → back to the same code page", 302 === $r1['code'] && str_contains( $r1['location'], 'wp-login.php' ) && 200 === $lp['code'] && 302 === $r2['code'] && ScanUrl::site_url( $c_in ) === $r2['location'] && 200 === $r3['code'] && str_contains( $r3['body'], '<body class="pqbg-scan">' ) );

		// HTTP: the plugin's admin pages and the menu position.
		$bad = array();
		foreach ( array( 'admin' => array( AdminUrl::dashboard(), AdminUrl::sales(), AdminUrl::reports(), AdminUrl::bulk_tools(), AdminUrl::settings(), AdminUrl::health(), AdminUrl::product_edit( $P_in->get_id() ) ), 'sm' => array( AdminUrl::dashboard(), AdminUrl::sales(), AdminUrl::bulk_tools() ) ) as $who => $urls ) {
			foreach ( $urls as $u ) {
				$r = $http( $who, 'GET', $u );
				if ( 200 !== $r['code'] || ( ! str_contains( $r['body'], 'pqbg-plugin-nav' ) && ! str_contains( $r['body'], 'pqbg-code' ) ) ) {
					$bad[] = "{$who} {$u}: {$r['code']}";
				}
			}
		}
		$dash = $http( 'admin', 'GET', AdminUrl::dashboard() );
		$ids  = $top_ids( $dash['body'] );
		$pos  = array_search( 'toplevel_page_' . AdminUrl::DASHBOARD, $ids, true );
		pqbg_t( "{$theme}: the plugin's admin pages load (admin, shop manager); QR & Barcodes directly below Products", array() === $bad && false !== $pos && 'menu-posts-product' === ( $ids[ $pos - 1 ] ?? '' ), implode( ', ', $bad ) . ' | ' . implode( ' ', array_slice( $ids, max( 0, (int) $pos - 2 ), 4 ) ) );

		// HTTP: the theme's store pages (an item in the cart, so checkout shows the form, not a redirect to the cart).
		$http( 'admin', 'GET', home_url( '/?add-to-cart=' . $P_in->get_id() ) );
		$bad = array();
		foreach ( $theme_pages as $name => $tp ) {
			$r = $http( '' === $tp[0] ? 'anon' : $tp[0], 'GET', $tp[1] );
			if ( 200 !== $r['code'] || str_contains( $r['body'], 'product-qrcode-barcode-generator/' ) || preg_match( '/class="[^"]*pqbg/', $r['body'] ) ) {
				$bad[] = "{$name}: {$r['code']}";
			}
		}
		pqbg_t( "{$theme}: the theme's store pages answer 200 with no plugin asset or markup (HTTP)", array() === $bad, implode( ', ', $bad ) );

		// Browsers.
		foreach ( $by_name as $bname => $exe ) {
			$login( "lb-{$theme}-{$bname}", 'p12t_seller', $pw['seller'] );
			$pages = array();
			foreach ( $screens as $name => $s ) {
				$pages[] = array(
					'name'     => $name,
					'url'      => $s[1],
					'kind'     => 'scan',
					'cookies'  => $cookies_of( $s[0] ),
					'required' => $s[3],
					'styles'   => $style_selectors,
					'scanner'  => 'entry' === $name ? $c_in : null,
					'shot'     => "{$theme}/{$name}",
				);
			}
			$pages[] = array( 'name' => 'sale-undo', 'url' => $sale_url, 'kind' => 'scan', 'cookies' => $cookies_of( 'seller2' ), 'required' => array( '.pqbg-scan__undo', '.pqbg-scan__name' ), 'styles' => $style_selectors, 'shot' => "{$theme}/sale-undo" );
			$pages[] = array( 'name' => 'login-page', 'url' => wp_login_url( ScanUrl::site_url( $c_in ) ), 'kind' => 'theme', 'shot' => "{$theme}/login-page" );
			$pages[] = array( 'name' => 'login', 'url' => ScanUrl::site_url( $c_in ), 'kind' => 'login', 'user' => 'p12t_seller2', 'pass' => $pw['seller2'] );
			$pages[] = array( 'name' => 'logout-back', 'url' => ScanUrl::site_url( $c_in ), 'kind' => 'logoutBack', 'cookies' => $cookies_of( "lb-{$theme}-{$bname}" ) );
			if ( 'Chrome' === $bname ) {
				foreach ( $theme_pages as $name => $tp ) {
					foreach ( array( 'on' => array(), 'off' => array( 'X-Pqbg-Test-Off' => $off_token ) ) as $mode => $hdr ) {
						$pages[] = array( 'name' => "store:{$name}:{$mode}", 'url' => $tp[1], 'kind' => 'theme', 'cookies' => '' === $tp[0] ? array() : $cookies_of( $tp[0] ), 'headers' => (object) $hdr, 'before' => $tp[2] );
					}
				}
			}
			$res = $run_browser( $exe, "{$theme}-{$bname}", $pages, $shot_root_of( $bname ) );
			pqbg_t( "{$theme}/{$bname}: browser checks ran", is_array( $res ) && count( $res['pages'] ) === count( $pages ) && array() === array_filter( $res['pages'], static fn( $p ) => isset( $p['error'] ) ), is_array( $res ) ? wp_json_encode( array_filter( array_map( static fn( $p ) => $p['error'] ?? null, $res['pages'] ) ) ) : 'no JSON' );
			if ( ! is_array( $res ) ) {
				continue;
			}
			$P = $res['pages'];
			$theme_results[ $theme ][ $bname ] = $P;

			$dirty = $wide = $small = $missing = $unfocused = $images = array();
			foreach ( array_merge( array_keys( $screens ), array( 'sale-undo' ) ) as $name ) {
				$p = $P[ $name ] ?? array();
				if ( array() !== ( $p['consoleErrors'] ?? array( 'x' ) ) || array() !== ( $p['pageErrors'] ?? array( 'x' ) ) || array() !== ( $p['failedRequests'] ?? array( 'x' ) ) || array() !== ( $p['csp'] ?? array( 'x' ) ) ) {
					$dirty[] = $name . ': ' . wp_json_encode( array( $p['consoleErrors'] ?? null, $p['pageErrors'] ?? null, $p['failedRequests'] ?? null, $p['csp'] ?? null ) );
				}
				foreach ( (array) ( $p['overflow'] ?? array() ) as $vp => $o ) {
					if ( $o['scrollWidth'] > $o['innerWidth'] ) {
						$wide[] = "{$name}@{$vp} {$o['scrollWidth']}>{$o['innerWidth']}";
					}
				}
				if ( array() !== ( $p['smallTargets'] ?? array() ) ) {
					$small[] = $name . ': ' . implode( '; ', $p['smallTargets'] );
				}
				if ( array() !== ( $p['missing'] ?? array( 'x' ) ) ) {
					$missing[] = $name . ': ' . implode( ' ', (array) ( $p['missing'] ?? array( 'no result' ) ) );
				}
				if ( in_array( $name, array( 'entry', 'entry-bad', 'in-stock', 'not-found' ), true ) && 'pqbg-code' !== ( $p['active'] ?? '' ) ) {
					$unfocused[] = $name . ': ' . ( $p['active'] ?? '?' );
				}
				foreach ( (array) ( $p['images'] ?? array() ) as $im ) {
					if ( ! $im['complete'] || $im['right'] > $im['innerWidth'] ) {
						$images[] = $name;
					}
				}
				foreach ( array( 'phone', 'desktop' ) as $vp ) {
					$hashes[ $bname ][ $name ][ $vp ][ $theme ] = $p['shots'][ $vp ] ?? '';
				}
				$styles_seen[ $bname ][ $name ][ $theme ] = wp_json_encode( $p['styles'] ?? null );
				if ( ! ( $p['isScan'] ?? false ) || array() !== ( $p['scripts'] ?? array( 'x' ) ) || 0 !== ( $p['inlineScripts'] ?? 1 ) || 1 !== count( $p['stylesheets'] ?? array() ) || ! str_contains( (string) ( $p['stylesheets'][0] ?? '' ), '/assets/pqbg-scan.css' ) ) {
					$missing[] = "{$name}: not an isolated scan page " . wp_json_encode( array( $p['stylesheets'] ?? null, $p['scripts'] ?? null ) );
				}
			}
			pqbg_t( "{$theme}/{$bname}: scan screens: no console or page errors, failed requests or CSP violations", array() === $dirty, implode( ' | ', $dirty ) );
			pqbg_t( "{$theme}/{$bname}: scan screens: no horizontal scroll at 375, 320 and 1280 px", array() === $wide, implode( ', ', $wide ) );
			pqbg_t( "{$theme}/{$bname}: scan screens: every touch target at least 44 x 44 px", array() === $small, implode( ' | ', $small ) );
			pqbg_t( "{$theme}/{$bname}: scan screens: required elements present; one stylesheet (the plugin's), no script", array() === $missing, implode( ' | ', $missing ) );
			pqbg_t( "{$theme}/{$bname}: the code box has focus on load (hardware scanners type into it)", array() === $unfocused, implode( ', ', $unfocused ) );
			pqbg_t( "{$theme}/{$bname}: product images load and stay inside the viewport", array() === $images, implode( ', ', $images ) );
			$sc = $P['entry']['scanner'] ?? array();
			pqbg_t( "{$theme}/{$bname}: a scanner's code + Enter lands on the code page, box focused again", 200 === ( $sc['status'] ?? 0 ) && ScanUrl::site_url( $c_in ) === ( $sc['url'] ?? '' ) && 'pqbg-code' === ( $sc['active'] ?? '' ) && ( $sc['isScan'] ?? false ), wp_json_encode( $sc ) );
			$lg = $P['login'] ?? array();
			pqbg_t( "{$theme}/{$bname}: login round trip in the browser lands on the code page", str_contains( (string) ( $lg['loginUrl'] ?? '' ), 'wp-login.php' ) && ScanUrl::site_url( $c_in ) === ( $lg['finalUrl'] ?? '' ) && ( $lg['isScan'] ?? false ) && array() === ( $lg['csp'] ?? array( 'x' ) ), wp_json_encode( $lg ) );
			$lb = $P['logout-back'] ?? array();
			pqbg_t( "{$theme}/{$bname}: after Log out, Back does not show the product page again (no-store)", ( $lb['before'] ?? false ) && ! ( $lb['backHasProduct'] ?? true ), wp_json_encode( $lb ) );
			$lp = $P['login-page'] ?? array();
			pqbg_t( "{$theme}/{$bname}: the login page loads without errors or horizontal scroll", 200 === ( $lp['status'] ?? 0 ) && array() === ( $lp['consoleErrors'] ?? array( 'x' ) ) && array() === ( $lp['pageErrors'] ?? array( 'x' ) ) && ( $lp['overflow']['phone']['scrollWidth'] ?? 9999 ) <= ( $lp['overflow']['phone']['innerWidth'] ?? 0 ), wp_json_encode( array( $lp['consoleErrors'] ?? null, $lp['overflow'] ?? null ) ) );

			if ( 'Chrome' === $bname ) {
				$leak = $new_err = $status = $own = array();
				foreach ( array_keys( $theme_pages ) as $name ) {
					$on  = $P[ "store:{$name}:on" ] ?? array();
					$off = $P[ "store:{$name}:off" ] ?? array();
					if ( 200 !== ( $on['status'] ?? 0 ) || 200 !== ( $off['status'] ?? 0 ) ) {
						$status[] = "{$name}: " . ( $on['status'] ?? '?' ) . '/' . ( $off['status'] ?? '?' );
					}
					if ( array() !== ( $on['plugin']['requests'] ?? array( 'x' ) ) || 0 !== ( $on['plugin']['classes'] ?? 1 ) || ( $on['plugin']['text'] ?? true ) ) {
						$leak[] = $name . ': ' . wp_json_encode( $on['plugin'] ?? null );
					}
					$norm  = static fn( array $l ) => array_values( array_unique( array_map( static fn( $e ) => preg_replace( '/[?&](ver|_wpnonce|nonce)=[^&\s]+/', '', (string) $e ), $l ) ) );
					$e_on  = $norm( array_merge( $on['consoleErrors'] ?? array(), $on['pageErrors'] ?? array(), $on['failedRequests'] ?? array() ) );
					$e_off = $norm( array_merge( $off['consoleErrors'] ?? array(), $off['pageErrors'] ?? array(), $off['failedRequests'] ?? array() ) );
					if ( array() !== array_diff( $e_on, $e_off ) ) {
						$new_err[] = $name . ': ' . implode( ' ; ', array_diff( $e_on, $e_off ) );
					}
					if ( array() !== $e_on ) {
						$own[] = $name . ': ' . implode( ' ; ', array_slice( $e_on, 0, 3 ) );
					}
				}
				pqbg_t( "{$theme}/Chrome: store pages 200 with the plugin on and off", array() === $status, implode( ', ', $status ) );
				pqbg_t( "{$theme}/Chrome: store pages load no plugin asset and carry no plugin markup", array() === $leak, implode( ' | ', $leak ) );
				pqbg_t( "{$theme}/Chrome: no console error, page error or failed request that the plugin adds (compared with the plugin off)", array() === $new_err, implode( ' | ', $new_err ) );
				if ( array() !== $own ) {
					echo "   INFO {$theme}: the theme's/WooCommerce's own browser errors (also there without the plugin): " . implode( ' | ', $own ) . "\n";
				}
			}
		}

		$cap = $capture_since( $cap0 );
		pqbg_t( "{$theme}: no PHP notice, warning or deprecation from plugin code (error capture)", array() === $cap['plugin'], implode( ' | ', $cap['plugin'] ) );
		if ( array() !== $cap['other'] ) {
			echo "   INFO {$theme}: PHP events from other code: " . implode( ' | ', array_slice( $cap['other'], 0, 8 ) ) . ( count( $cap['other'] ) > 8 ? ' …' : '' ) . "\n";
		}
	}

	pqbg_section( 'isolation across themes' );
	foreach ( $by_name as $bname => $exe ) {
		$diff = $sdiff = $near = array();
		$root = $shot_root_of( $bname );
		foreach ( $pixel_screens as $name ) {
			foreach ( array( 'phone', 'desktop' ) as $vp ) {
				$ref = $hashes[ $bname ][ $name ][ $vp ]['twentytwentyfive'] ?? '';
				foreach ( (array) ( $hashes[ $bname ][ $name ][ $vp ] ?? array() ) as $theme => $h ) {
					if ( '' !== $ref && $h === $ref ) {
						continue;
					}
					list( $n, $total ) = '' === $ref || '' === $h ? array( -1, 0 ) : $png_diff( "$root/twentytwentyfive/{$name}-{$vp}.png", "$root/{$theme}/{$name}-{$vp}.png" );
					if ( $n >= 0 && $n <= $total / 10000 ) {
						$near[] = "{$name}@{$vp} {$theme}: {$n} px";
					} else {
						$diff[] = "{$name}@{$vp}: {$theme}" . ( $n >= 0 ? " ({$n} of {$total} px)" : ' (size differs or missing)' );
					}
				}
			}
		}
		if ( array() !== $near ) {
			echo "   INFO {$bname}: same size, within 0.01% of pixels (anti-aliasing noise): " . implode( ', ', $near ) . "\n";
		}
		foreach ( array_merge( $pixel_screens, array( 'sale-undo' ) ) as $name ) {
			if ( 1 !== count( array_unique( (array) ( $styles_seen[ $bname ][ $name ] ?? array( 'a', 'b' ) ) ) ) || count( (array) ( $styles_seen[ $bname ][ $name ] ?? array() ) ) !== count( PQBG12_THEMES ) ) {
				$sdiff[] = $name;
			}
		}
		pqbg_t( "{$bname}: computed styles of the scan pages are identical under every theme", array() === $sdiff, implode( ', ', $sdiff ) );
		pqbg_t( "{$bname}: phone and desktop screenshots of every scan screen are identical to Twenty Twenty-Five's under every theme (same hash, or same size with at most 0.01% of pixels differing)", array() === $diff, implode( ', ', array_slice( $diff, 0, 20 ) ) );
	}
	$pngs = glob( $shots . '/*/*.png' ) ?: array();
	pqbg_t( 'screenshots saved for the owner (phone and desktop, every theme and staff-facing screen)', count( $pngs ) >= count( PQBG12_THEMES ) * ( count( $screens ) + 2 ) * 2, count( $pngs ) . " files in {$shots}" );

	// Back to the site's own theme for the rest.
	switch_theme( $start['stylesheet'] );
	wp_cache_flush();
	$http( 'admin', 'GET', admin_url() );

	// ------------------------------------------------------------ permalinks
	pqbg_section( 'permalinks: six structures through Settings → Permalinks' );
	$set_structure = static function ( string $structure ) use ( $http ): bool {
		$form = $http( 'admin', 'GET', admin_url( 'options-permalink.php' ) );
		preg_match( '/name="_wpnonce" value="([^"]+)"/', $form['body'], $n );
		$r = $http(
			'admin',
			'POST',
			admin_url( 'options-permalink.php' ),
			array(
				'_wpnonce'            => $n[1] ?? '',
				'_wp_http_referer'    => '/wp-admin/options-permalink.php',
				'selection'           => '' === $structure ? '' : 'custom',
				'permalink_structure' => $structure,
				'category_base'       => '',
				'tag_base'            => '',
				'submit'              => 'Save Changes',
			)
		);
		// Like a browser, open the page the form redirects to: that request's hard flush writes .htaccess
		// (the POST alone leaves the .htaccess that Plain wrote, without WordPress's rewrite block).
		$back = 302 === $r['code'] ? $http( 'admin', 'GET', $r['location'] ) : array( 'code' => 0 );
		wp_cache_flush();
		return 302 === $r['code'] && 200 === $back['code'] && $structure === (string) get_option( 'permalink_structure' );
	};
	$saved_rules = $wpdb->get_row( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = 'rewrite_rules'", ARRAY_A );
	foreach ( array( '', '/%year%/%monthnum%/%postname%/', '/archives/%post_id%', '/blog/%postname%/', '/index.php/%postname%/', '/%postname%/' ) as $structure ) {
		$label = '' === $structure ? 'Plain' : $structure;
		pqbg_t( "{$label}: saved through the real form (which flushes the rules)", $set_structure( $structure ) );
		// Only the structure, for the in-process checks (WP_Rewrite::init() would drop every registered endpoint).
		$wp_rewrite->permalink_structure = $structure;
		$pretty = '' !== $structure && ! str_contains( $structure, 'index.php' );
		$e      = $http( 'seller', 'GET', ScanUrl::site_url() );
		$cp     = $http( 'seller', 'GET', ScanUrl::site_url( $c_in ) );
		$ms     = $http( 'seller', 'GET', ScanUrl::my_sales_url() );
		$dash_a = $http( 'admin', 'GET', AdminUrl::dashboard() );
		$dash_s = $http( 'sm', 'GET', AdminUrl::dashboard() );
		$health = $http( 'admin', 'GET', AdminUrl::health() );
		$notice = static fn( array $r ) => str_contains( $r['body'], 'need pretty permalinks' );
		$warn   = static fn( array $r ) => str_contains( $r['body'], 'every printed label opens an error page' );
		wp_set_current_user( $A );
		$hc = HealthCheck::run( false );
		wp_set_current_user( 0 );
		if ( $pretty ) {
			pqbg_t( "{$label}: scan URLs unchanged and working (entry, code page, My sales)", $home . '/scan/' === ScanUrl::site_url() && 200 === $e['code'] && 200 === $cp['code'] && str_contains( $cp['body'], '<body class="pqbg-scan">' ) && 200 === $ms['code'] );
			pqbg_t( "{$label}: no permalink notice, Dashboard warning or Health check error", ! $notice( $dash_a ) && ! $notice( $dash_s ) && ! $warn( $dash_a ) && 0 === $hc['permalinks']['count'] && 200 === $health['code'] );
			if ( '/blog/%postname%/' === $structure ) {
				$b = $http( 'seller', 'GET', $home . '/blog/scan/' . $c_in . '/' );
				pqbg_t( "{$label}: the scan path stays at the site root (/blog/scan/… is not the scan page)", ! str_contains( $b['body'], '<body class="pqbg-scan">' ) );
			}
		} else {
			$reason = '' === $structure ? 'plain' : 'index_php';
			pqbg_t( "{$label}: scan URLs are not served (the theme's own answer), nothing breaks", ! str_contains( $cp['body'], '<body class="pqbg-scan">' ) && 'Product QR Code and Barcode Generator' !== ( $cp['headers']['x-redirect-by'] ?? '' ) && $cp['code'] < 500 && 200 === $dash_a['code'] );
			pqbg_t( "{$label}: the admin notice for administrators AND shop managers (they print labels)", $notice( $dash_a ) && $notice( $dash_s ) );
			pqbg_t( "{$label}: the Dashboard warning (administrator and shop manager; the Permalinks link for the administrator)", $warn( $dash_a ) && $warn( $dash_s ) && str_contains( $dash_a['body'], 'options-permalink.php' ) );
			pqbg_t( "{$label}: the Health check reports an error ({$reason}) and counts it on the Dashboard", 1 === $hc['permalinks']['count'] && HealthCheck::ERROR === $hc['permalinks']['severity'] && $reason === $hc['permalinks']['rows'][0]['reason'] && str_contains( $health['body'], 'Scan links (permalinks)' ) && str_contains( $dash_a['body'], 'The health check found' ) );
		}
	}
	pqbg_t( 'back on /%postname%/: the rewrite rules are the same as at the start', $saved_rules['option_value'] === $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'rewrite_rules'" ) );
	file_put_contents( ABSPATH . '.htaccess', $start['htaccess'] );

	// ------------------------------------------------------------ page cache
	foreach ( array( 'twentytwentyfive', 'storefront' ) as $theme ) {
		pqbg_section( "page cache (WP Fastest Cache) under {$theme}" );
		if ( ! wp_get_theme( $theme )->exists() ) {
			$unzip( $packs . '/' . PQBG12_THEMES[ $theme ], get_theme_root() );
			$made['themes'][] = $theme;
		}
		switch_theme( $theme );
		wp_cache_flush();
		$http( 'admin', 'GET', admin_url() );
		if ( ! $made['plugin'] ) {
			$made['plugin'] = $unzip( $cache_zip, WP_PLUGIN_DIR );
			$made['cache']  = true;
			$act            = activate_plugin( PQBG12_CACHE_SLUG );
			include_once WP_PLUGIN_DIR . '/wp-fastest-cache/inc/admin.php';
			$_SERVER['SERVER_SOFTWARE'] = 'Apache';
			wp_set_current_user( $A );
			$_POST = array(
				'wpFastestCachePage'   => 'options',
				'wpFastestCacheStatus' => 'on',
			);
			( new WpFastestCacheAdmin() )->saveOption();
			$_POST = array();
			wp_set_current_user( 0 );
			wp_cache_flush();
			pqbg_t( 'WP Fastest Cache active with page caching on; its .htaccess rules written; wp-config.php untouched', null === $act && str_contains( (string) get_option( 'WpFastestCache' ), '"wpFastestCacheStatus":"on"' ) && str_contains( (string) file_get_contents( ABSPATH . '.htaccess' ), 'BEGIN WpFastestCache' ) && $start['wpconfig'] === sha1_file( ABSPATH . 'wp-config.php' ) );
		}
		$cache_dir = WP_CONTENT_DIR . '/cache/all';
		$cached    = static function () use ( $cache_dir ): array {
			$out = array();
			if ( is_dir( $cache_dir ) ) {
				foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $cache_dir, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
					$out[] = str_replace( '\\', '/', substr( $f->getPathname(), strlen( $cache_dir ) ) );
				}
			}
			return $out;
		};
		$http( 'anon', 'GET', home_url( '/' ) );
		$ctl = $http( 'anon', 'GET', home_url( '/' ) );
		pqbg_t( "{$theme}: control: the cache stores and serves a logged-out page", str_contains( $ctl['body'], 'WP Fastest Cache file was created' ) && array() !== $cached() );

		// Logged out, any method → 302, and nothing for /scan/ ends up in the cache (the confirmed F1 case).
		foreach ( array( ScanUrl::site_url(), ScanUrl::site_url( $c_in ), ScanUrl::my_sales_url() ) as $u ) {
			$http( 'anon', 'PUT', $u );
			$http( 'anon', 'DELETE', $u );
			$http( 'anon', 'OPTIONS', $u );
		}
		$after = array();
		foreach ( array( ScanUrl::site_url(), ScanUrl::site_url( $c_in ), ScanUrl::my_sales_url() ) as $u ) {
			$r       = $http( 'anon', 'GET', $u );
			$after[] = $r['code'] . ' ' . ( str_contains( $r['location'], 'wp-login.php' ) ? 'login' : 'other' );
		}
		pqbg_t( "{$theme}: after logged-out PUT/DELETE/OPTIONS, a logged-out GET still gets the login redirect (not a stored page)", array( '302 login', '302 login', '302 login' ) === $after, implode( ', ', $after ) );

		// Two sellers and a logged-out visitor on the same URLs.
		$sa   = SaleService::sell( array( 'code' => $c_sale, 'quantity' => 1, 'request_id' => wp_generate_uuid4(), 'seller_id' => $user_ids['seller'], 'payment_method' => 'cash' ) );
		$said = is_wp_error( $sa ) ? 0 : (int) $sa['sale']['id'];
		$su   = add_query_arg( SaleRequest::SALE_ARG, $said, ScanUrl::site_url( $c_sale ) );
		$a1   = $http( 'seller', 'GET', $su );
		$a2   = $http( 'seller', 'GET', ScanUrl::my_sales_url() );
		$a3   = $http( 'seller', 'GET', ScanUrl::site_url( $c_in ) );
		$b1   = $http( 'seller2', 'GET', $su );
		$b2   = $http( 'seller2', 'GET', ScanUrl::my_sales_url() );
		$b3   = $http( 'seller2', 'GET', ScanUrl::site_url( $c_in ) );
		$n1   = $http( 'anon', 'GET', $su );
		$n2   = $http( 'anon', 'GET', ScanUrl::my_sales_url() );
		pqbg_t( "{$theme}: seller A sees their sale page, My sales (with the Silk stole) and the product", 200 === $a1['code'] && str_contains( $a1['body'], 'pqbg-scan__undo' ) && 200 === $a2['code'] && str_contains( $a2['body'], 'P12T Silk stole' ) && 200 === $a3['code'] );
		pqbg_t( "{$theme}: seller B never gets A's pages (A's sale page → 303; B's own My sales, without A's Silk stole)", 303 === $b1['code'] && 200 === $b2['code'] && ! str_contains( $b2['body'], 'P12T Silk stole' ) && str_contains( $b2['body'], 'P12T Dupatta' ) && 200 === $b3['code'] );
		pqbg_t( "{$theme}: logged out → the login redirect for the sale page and My sales", 302 === $n1['code'] && 302 === $n2['code'] && str_contains( $n2['location'], 'wp-login.php' ) );
		pqbg_t( "{$theme}: every scan response carries the no-store headers; none was altered by the cache plugin", $headers_ok( $a2 ) && $headers_ok( $b2 ) && $headers_ok( $a3 ) && $only_plugin_css( $a3['body'] ) && ! str_contains( $a3['body'], 'WP Fastest Cache' ) );
		$scan_files = array_filter( $cached(), static fn( $f ) => str_contains( $f, '/scan/' ) );
		pqbg_t( "{$theme}: no cache file for any /scan/ path", array() === $scan_files, implode( ', ', $scan_files ) );
	}

	// ------------------------------------------------------------ scope
	pqbg_section( 'scope' );
	$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/ScanRoute.php' );
	pqbg_t( 'the scan route sets DONOTCACHEPAGE, DONOTMINIFY and DONOTCDN (and only for its own responses)', array( 'DONOTCACHEPAGE', 'DONOTMINIFY', 'DONOTCDN' ) === ScanRoute::NO_CACHE_CONSTANTS && str_contains( $src, 'self::no_page_cache();' ) && ! defined( 'DONOTCACHEPAGE' ) );
	$theme_words = array();
	foreach ( glob( dirname( __DIR__ ) . '/{includes,templates,assets}/*', GLOB_BRACE ) ?: array() as $f ) {
		// Code only: comments may use words like "storefront" in their ordinary sense.
		$code = (string) file_get_contents( $f );
		$code = str_ends_with( $f, '.php' )
			? implode( '', array_map( static fn( $t ) => is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t, token_get_all( $code ) ) )
			: (string) preg_replace( '#/\*.*?\*/#s', '', $code );
		if ( preg_match( '/\b(storefront|astra|kadence|oceanwp|twentytwenty\w+|get_template|get_stylesheet|wp_is_block_theme|current_theme_supports)\b/i', $code, $m ) ) {
			$theme_words[] = basename( $f ) . ': ' . $m[1];
		}
	}
	pqbg_t( 'no theme-specific code in the plugin (no theme names, no theme checks)', array() === $theme_words, implode( ', ', $theme_words ) );
	global $wp_filter;
	$front = array();
	foreach ( array( 'wp_enqueue_scripts', 'wp_head', 'wp_footer', 'the_content', 'template_include', 'template_redirect', 'body_class', 'woocommerce_before_main_content', 'woocommerce_single_product_summary', 'woocommerce_before_cart', 'woocommerce_before_checkout_form', 'woocommerce_account_content', 'woocommerce_email_header', 'woocommerce_email_order_details', 'render_block' ) as $hook ) {
		foreach ( (array) ( $wp_filter[ $hook ]->callbacks ?? array() ) as $cbs ) {
			foreach ( $cbs as $cb ) {
				$fn = $cb['function'];
				if ( is_array( $fn ) && is_string( $fn[0] ) && str_starts_with( $fn[0], 'ProductQrBarcode\\' ) ) {
					$front[] = "$hook: {$fn[0]}::{$fn[1]}";
				}
			}
		}
	}
	pqbg_t( 'the plugin hooks nothing into theme, store-page or email output', array() === $front, implode( ', ', $front ) );
} catch ( PqbgTestStop $e ) {
	pqbg_t( 'suite completed (stopped on request)', false, $e->getMessage() );
} catch ( Throwable $e ) {
	pqbg_t( 'suite ran without an exception', false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
	pqbg_section( 'cleanup' );
	$_POST = array();
	// The cache plugin: deactivate (removes its .htaccess block), delete its folder, its cache and options.
	if ( is_plugin_active( PQBG12_CACHE_SLUG ) ) {
		deactivate_plugins( PQBG12_CACHE_SLUG );
	}
	wp_clear_scheduled_hook( 'wp_fastest_cache_Preload' );
	if ( $made['plugin'] || is_dir( WP_PLUGIN_DIR . '/wp-fastest-cache' ) && ! in_array( 'wp-fastest-cache', $start['plugins'], true ) ) {
		$rm_tree( WP_PLUGIN_DIR . '/wp-fastest-cache' );
	}
	if ( ! in_array( 'cache', $start['content'], true ) ) {
		$rm_tree( WP_CONTENT_DIR . '/cache' );
	}
	// The theme, then every option back to its value at the start.
	if ( get_option( 'stylesheet' ) !== $start['stylesheet'] ) {
		switch_theme( $start['stylesheet'] );
	}
	// A theme switch makes WooCommerce regenerate the placeholder's thumbnail sizes in a background
	// process (WC_Regenerate_Images_Request, queued in options); let it finish before restoring.
	$regen_deadline = microtime( true ) + 60;
	while ( microtime( true ) < $regen_deadline && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%wc\\_regenerate\\_images\\_batch\\_%' OR option_name LIKE '%wc\\_regenerate\\_images\\_process\\_lock%'" ) > 0 ) {
		usleep( 500000 );
	}
	foreach ( $options_now() as $name => $row ) {
		if ( ! isset( $start['options'][ $name ] ) ) {
			// New since the start, and caused by this suite only:
			// - theme_mods_*, current_theme, theme_switched: WordPress's switch_theme();
			// - woocommerce_catalog_rows/columns, woocommerce_maybe_regenerate_images_hash,
			//   wc_blocks_use_blockified_product_grid_block_as_template, wc_regenerate_images batches:
			//   WooCommerce's reaction to a theme switch (classic or block theme);
			// - storefront*, astra*, _astra*, bsf_* (Astra's bundled BSF analytics library), kadence*, ocean*:
			//   the test themes' own options; WpFastestCache*, wpfc*: the cache plugin's;
			// - pqbg_perf_samples: the plugin's Dashboard timing samples (Phase 11), written by this suite's
			//   Dashboard requests when the site had none;
			// - runtime caches (transients).
			if ( $RUNTIME( $name ) || str_starts_with( $name, 'theme_mods_' ) || in_array( $name, array( 'current_theme', 'theme_switched', 'woocommerce_catalog_rows', 'woocommerce_catalog_columns', 'woocommerce_maybe_regenerate_images_hash', 'wc_blocks_use_blockified_product_grid_block_as_template', 'pqbg_perf_samples' ), true ) || preg_match( '/^(storefront|astra|_astra|bsf_|kadence|ocean|wpfc|WpFc|WpFastestCache|theme_switched_via)/i', $name ) || str_contains( $name, 'wc_regenerate_images_batch_' ) ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
			}
		} elseif ( $row !== $start['options'][ $name ] && ! $RUNTIME( $name ) ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $start['options'][ $name ][0], 'autoload' => $start['options'][ $name ][1] ), array( 'option_name' => $name ) );
		}
	}
	foreach ( $start['options'] as $name => $row ) {
		if ( ! $RUNTIME( $name ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $name ) ) ) {
			$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $row[0], 'autoload' => $row[1] ) );
		}
	}
	wp_cache_flush();
	$wp_rewrite->permalink_structure = $start['structure'];
	// The placeholder image's sizes regenerated by the theme switches: files and metadata as they were.
	foreach ( glob( $uploads . '/woocommerce-placeholder*' ) ?: array() as $f ) {
		if ( ! isset( $start['ph_files'][ basename( $f ) ] ) ) {
			unlink( $f );
		}
	}
	foreach ( $start['ph_files'] as $name => $bytes ) {
		if ( ! is_file( "$uploads/$name" ) || file_get_contents( "$uploads/$name" ) !== $bytes ) {
			file_put_contents( "$uploads/$name", $bytes );
		}
	}
	foreach ( $start['ph_meta'] as $m ) {
		$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $m['meta_value'] ), array( 'meta_id' => $m['meta_id'] ) );
	}
	foreach ( $dir_list( $uploads . '/wc-logs' ) as $f ) {
		if ( ! in_array( $f, $start['wc_logs'], true ) && str_starts_with( $f, 'wc-image-regeneration-' ) ) {
			unlink( $uploads . '/wc-logs/' . $f );
		}
	}
	// Theme folders this suite unzipped.
	foreach ( array_unique( $made['themes'] ) as $t ) {
		if ( ! in_array( $t, $start['themes'], true ) ) {
			$rm_tree( get_theme_root() . '/' . $t );
		}
	}
	// Sales, codes, posts (products, variations, pages, the image and any auto-draft), terms, users, sessions.
	$wpdb->query( $wpdb->prepare( "DELETE FROM $S WHERE id > %d", $start['sales'] ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $C WHERE id > %d", $start['codes'] ) );
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d ORDER BY ID DESC", $start['post_max'] ) ) as $pid ) {
		'attachment' === get_post_type( (int) $pid ) ? wp_delete_attachment( (int) $pid, true ) : wp_delete_post( (int) $pid, true );
	}
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT term_id, taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id > %d", $start['term_max'] ) ) as $t ) {
		wp_delete_term( (int) $t->term_id, $t->taxonomy );
	}
	foreach ( $user_ids as $uid ) {
		if ( is_int( $uid ) ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d", $uid ) ) as $own ) {
				wp_delete_post( (int) $own, true );
			}
			wp_delete_user( $uid );
		}
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_sessions WHERE session_id > %d", $start['sessions'] ) );
	$removed = pqbg_test_as_cleanup( $as_mark );
	// Product lookup rows of deleted products.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id > %d", $start['post_max'] ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wc_product_attributes_lookup WHERE product_id > %d OR product_or_parent_id > %d", $start['post_max'], $start['post_max'] ) );
	// The temporary must-use file, .htaccess byte for byte.
	if ( is_file( $mu_file ) ) {
		unlink( $mu_file );
	}
	if ( $mu_made && is_dir( WPMU_PLUGIN_DIR ) && array() === $dir_list( WPMU_PLUGIN_DIR ) ) {
		rmdir( WPMU_PLUGIN_DIR );
	}
	if ( (string) file_get_contents( ABSPATH . '.htaccess' ) !== $start['htaccess'] ) {
		file_put_contents( ABSPATH . '.htaccess', $start['htaccess'] );
	}
	$handles = array();
	$rm_tree( $tmp );
	wp_cache_flush();

	// ------------------------------------------------------------ the restore guard
	$now      = $options_now();
	$changed  = array_keys( array_filter( $start['options'], static fn( $row, $name ) => ! $RUNTIME( $name ) && ( $now[ $name ] ?? null ) !== $row, ARRAY_FILTER_USE_BOTH ) );
	$new_opts = array_values( array_filter( array_keys( array_diff_key( $now, $start['options'] ) ), static fn( $n ) => ! $RUNTIME( $n ) ) );
	pqbg_t( 'guard: the original theme is active again', $start['stylesheet'] === get_option( 'stylesheet' ) && $start['stylesheet'] === get_option( 'template' ) );
	pqbg_t( 'guard: every option byte-identical to the start (theme mods, widgets, permalinks, rewrite rules, plugin settings); no option left behind', array() === $changed && array() === $new_opts, 'changed: ' . implode( ', ', $changed ) . ' | new: ' . implode( ', ', $new_opts ) );
	$snap_file = 'C:/xampp/backups/sharayu/phase12-theme-snapshot-before.json';
	if ( is_file( $snap_file ) ) {
		$snap = json_decode( (string) file_get_contents( $snap_file ), true );
		$off  = array();
		foreach ( (array) $snap['options'] as $name => $o ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
			if ( 'rewrite_rules' !== $name && ( ! $row || sha1( $row['option_value'] ) !== $o['sha1'] || $row['autoload'] !== $o['autoload'] ) ) {
				$off[] = $name;
			}
		}
		foreach ( (array) $snap['posts'] as $p ) {
			if ( sha1( (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $p['ID'] ) ) ) !== $p['sha1_content'] ) {
				$off[] = 'post #' . $p['ID'];
			}
		}
		pqbg_t( 'guard: the theme state recorded before Phase 12 (phase12-theme-snapshot-before.json) is intact', array() === $off, implode( ', ', $off ) );
	}
	$posts_now = $wpdb->get_results( "SELECT ID, post_type, post_status, post_modified_gmt, SHA1(post_content) h FROM {$wpdb->posts} ORDER BY ID", OBJECT_K );
	pqbg_t( 'guard: posts identical to the start (none added, removed or modified: global styles, navigation, pages)', $posts_now == $start['posts'], implode( ',', array_keys( array_diff_key( $posts_now, $start['posts'] ) ) ) );
	pqbg_t( 'guard: the placeholder image files and metadata as at the start', $ph_files() === $start['ph_files'] && $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", $placeholder ), ARRAY_A ) === $start['ph_meta'] );
	pqbg_t( 'guard: theme, plugin, wp-content and uploads folders as at the start; no cache folder', $dir_list( get_theme_root() ) === $start['themes'] && $dir_list( WP_PLUGIN_DIR ) === $start['plugins'] && $dir_list( WP_CONTENT_DIR ) === $start['content'] && $dir_list( $uploads ) === $start['uploads'], implode( ',', array_diff( $dir_list( get_theme_root() ), $start['themes'] ) ) . ' ' . implode( ',', array_diff( $dir_list( WP_PLUGIN_DIR ), $start['plugins'] ) ) . ' ' . implode( ',', array_diff( $dir_list( $uploads ), $start['uploads'] ) ) );
	pqbg_t( 'guard: must-use folder as at the start (the temporary file removed)', $dir_list( WPMU_PLUGIN_DIR ) === $start['mu'] );
	pqbg_t( 'guard: .htaccess byte-identical; wp-config.php never changed', (string) file_get_contents( ABSPATH . '.htaccess' ) === $start['htaccess'] && sha1_file( ABSPATH . 'wp-config.php' ) === $start['wpconfig'] );
	pqbg_t( 'guard: users, codes, sales, WooCommerce sessions back to the start', (int) count_users()['total_users'] === $start['users'] && (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $C" ) === $start['codes'] && (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $S" ) === $start['sales'] && (int) $wpdb->get_var( "SELECT COALESCE(MAX(session_id), 0) FROM {$wpdb->prefix}woocommerce_sessions" ) <= $start['sessions'] );
	pqbg_t( 'guard: the permalink structure and the scan rules are back; /scan/ answers', $start['structure'] === get_option( 'permalink_structure' ) && ScanRoute::is_available() && 302 === $http( 'anon-final', 'GET', ScanUrl::site_url() )['code'] );
	pqbg_test_as_check( $as_mark );
	echo "   (removed {$removed} Action Scheduler job(s) caused by the suite)\n";
	if ( is_file( $state_file ) ) {
		unlink( $state_file );
	}
}

pqbg_test_done();
