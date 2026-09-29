<?php
/**
 * Builds the user manual (1.0.1): screenshots of a sample shop, then docs/user-manual.pdf from
 * docs/user-manual.md.
 *
 *   php build/manual/build-manual.php [--with-seller-guide]
 *
 * --with-seller-guide first rebuilds docs/seller-guide.html and .pdf (tests/guide-screenshots.php,
 * unchanged), so one command regenerates every document after a version or screen change.
 *
 * Needs (the same tools as the suites, all outside the site):
 *   PQBG_THEMECHECK    the installed theme-check tool (its node_modules provide puppeteer-core)
 *   PQBG_MANUAL_TOOLS  this folder's package.json installed with "npm ci --omit=optional" (marked,
 *                      pdfjs-dist), e.g. C:\xampp\tools\pqbg\manual
 *   PQBG_BROWSERS      optional; the first existing entry is used (default: Chrome, then Edge)
 *   PQBG_MANUAL_SHOTS  optional; also keep the compressed screenshots in this folder
 *   PQBG_TESTS_ALLOW_PRODUCTION=1 on a site without WP_ENVIRONMENT_TYPE=local (as the suites)
 *   PQBG_STOP_FILE     honoured at every section and in the long loops (the runner's watchdog)
 *
 * It follows the suites' rules (tests/README.md): it refuses a production site, creates only
 * sample data (users "Owner (sample)", "Ravi (sample)", "Asha", "Vikram"; products with SAMPLE-
 * SKUs in "(sample)" categories; their codes and sales), sells through SaleService::sell() and the
 * real scan page, and removes everything in `finally`: products, codes, sales, terms, users, the
 * Action Scheduler jobs of its product saves, and every plugin option it changed (restored byte
 * for byte; options it created are deleted). The site's own title is never changed: the browser
 * shows "Sample Shop" instead (shots.mjs), and a screenshot fails when the page shows the real site
 * title or a real administrator's login, name or e-mail.
 *
 * The screenshots are compressed to 8-bit palette PNGs (at most MAX_SHOT bytes each) and the PDF
 * must stay under MAX_PDF bytes. Build-time only; never loaded by the plugin, and not in the zip.
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$plugin_dir = dirname( __DIR__, 2 );
$with_guide = in_array( '--with-seller-guide', $argv, true );

require $plugin_dir . '/tests/bootstrap.php';
pqbg_test_load_wp();
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once __DIR__ . '/repair.php';

// Repair mode: after a build that was killed before its cleanup (see repair.php). Dry run unless --apply.
if ( in_array( '--repair', $argv, true ) ) {
	exit( pqbg_manual_repair( in_array( '--apply', $argv, true ) ) );
}

use ProductQrBarcode\{AdminUrl, BulkGenerator, CodeRepository, CostImport, CostPrice, Plugin, PrintJob, SaleService, Schema, ScanUrl};

const PQBG_MANUAL_MAX_SHOT = 200 * 1024;       // One screenshot after compression.
const PQBG_MANUAL_MAX_PDF  = 4 * 1024 * 1024;  // docs/user-manual.pdf.
const PQBG_MANUAL_SHOP     = 'Sample Shop';
const PQBG_MANUAL_BASE     = 'https://shop.example.com';

global $wpdb;

$tools      = rtrim( (string) getenv( 'PQBG_MANUAL_TOOLS' ), '/\\' );
$themecheck = (string) getenv( 'PQBG_THEMECHECK' );
$browsers   = array_values( array_filter( array_merge( explode( ';', (string) getenv( 'PQBG_BROWSERS' ) ), array( 'C:\Program Files\Google\Chrome\Application\chrome.exe', 'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe', 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe' ) ), 'is_file' ) );

if ( '' === $tools || ! is_file( $tools . '/node_modules/marked/package.json' ) || ! is_file( $tools . '/node_modules/pdfjs-dist/package.json' ) || '' === $themecheck || ! is_file( dirname( $themecheck ) . '/node_modules/puppeteer-core/package.json' ) || array() === $browsers ) {
	fwrite( STDERR, "Needs PQBG_MANUAL_TOOLS (build/manual installed with npm ci), PQBG_THEMECHECK (the theme-check tool with node_modules) and Chrome or Edge. See build/manual/README.md.\n" );
	exit( 2 );
}

// The examples use "today" and "yesterday" in the shop's timezone: never start close to midnight.
$to_midnight = ( new DateTimeImmutable( 'tomorrow', wp_timezone() ) )->getTimestamp() - time();
if ( $to_midnight < 20 * MINUTE_IN_SECONDS ) {
	fwrite( STDERR, 'Refusing to start: ' . round( $to_midnight / 60 ) . " minutes to midnight in the shop's timezone (the build takes about 10). Run it after midnight.\n" );
	exit( 2 );
}

// The cover's date is the release date of this version (CHANGELOG.md), not the day of the build.
$release_date = preg_match( '/^## ' . preg_quote( PQBG_VERSION, '/' ) . ' \((\d{4}-\d{2}-\d{2})\)/m', (string) file_get_contents( $plugin_dir . '/CHANGELOG.md' ), $rd ) ? $rd[1] : '';
if ( '' === $release_date ) {
	fwrite( STDERR, 'CHANGELOG.md has no dated entry for ' . PQBG_VERSION . ".\n" );
	exit( 2 );
}

// A snapshot left by an earlier build means that build was killed before its cleanup finished.
if ( is_file( pqbg_manual_snapshot_file() ) ) {
	fwrite( STDERR, 'Refusing to start: an earlier build left ' . pqbg_manual_snapshot_file() . " (it was killed before its cleanup). Run the repair first:\n  php build/manual/build-manual.php --repair            (dry run)\n  php build/manual/build-manual.php --repair --apply\n" );
	exit( 2 );
}
// The state before anything is created (also before the seller guide), for the repair mode.
file_put_contents( pqbg_manual_snapshot_file(), serialize( pqbg_manual_snapshot() ) );

// The seller guide first, in its own process (it has its own sample data and cleanup).
if ( $with_guide ) {
	echo "== seller guide (tests/guide-screenshots.php) ==\n";
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $plugin_dir . '/tests/guide-screenshots.php' ), $guide_code );
	if ( 0 !== $guide_code ) {
		fwrite( STDERR, "The seller guide build failed (exit {$guide_code}); the manual was not built. The snapshot is kept: run --repair (dry run first) to check that nothing was left.\n" );
		exit( 1 );
	}
}

$work  = get_temp_dir() . 'pqbg-manual-' . wp_generate_password( 8, false );
$shots = $work . '/shots';
$keep  = (string) getenv( 'PQBG_MANUAL_SHOTS' );
wp_mkdir_p( $shots );

// ---------------------------------------------------------------- the state to restore and check
$pqbg_options = static fn(): array => (array) $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE '%pqbg%' ORDER BY option_name", OBJECT_K );
$table_counts = static function () use ( $wpdb ): array {
	$out = array();
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ) as $table ) {
		$out[ $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from SHOW TABLES.
	}
	return $out;
};
$options0 = $pqbg_options();
$counts0  = $table_counts();
$mark     = pqbg_test_as_mark();
$cl_mark  = pqbg_test_catlookup_mark(); // WooCommerce category lookup rows of the sample categories (tests/bootstrap.php).

// What must never appear in a screenshot: the site title and every real administrator.
$site_title = trim( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
$forbidden  = array( $site_title, (string) get_option( 'admin_email' ) );
foreach ( get_users( array( 'role' => 'administrator' ) ) as $real ) {
	$forbidden[] = $real->user_login;
	$forbidden[] = $real->display_name;
	$forbidden[] = $real->user_email;
}
$forbidden = array_values( array_unique( array_filter( $forbidden, static fn( $s ) => strlen( (string) $s ) >= 4 ) ) );

$created = array(
	'products' => array(),
	'terms'    => array(),
	'users'    => array(),
);
$users   = array();
$report  = array(
	'shots'  => array(),
	'checks' => 0,
);

/**
 * Runs one node script with a job file (deleted afterwards: it may hold a sample password) and
 * returns the JSON of its last output line.
 */
$node = static function ( string $script, array $job ) use ( $work ): array {
	$file = $work . '/job-' . wp_generate_password( 6, false ) . '.json';
	file_put_contents( $file, wp_json_encode( $job ) );
	$out = (string) shell_exec( 'node ' . escapeshellarg( __DIR__ . '/' . $script ) . ' ' . escapeshellarg( $file ) . ' 2>&1' );
	unlink( $file );
	$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $out ) ) ) );
	$json  = json_decode( (string) end( $lines ), true );
	return is_array( $json ) ? $json : array( 'ok' => false, 'errors' => array( substr( $out, -2000 ) ) );
};

/**
 * Compresses a screenshot to an 8-bit palette PNG and returns its size.
 */
$compress = static function ( string $png ): int {
	$img = imagecreatefrompng( $png );
	if ( false === $img ) {
		return PHP_INT_MAX;
	}
	imagetruecolortopalette( $img, false, 256 );
	imagepng( $img, $png, 9 );
	clearstatcache( true, $png );
	return (int) filesize( $png );
};

/**
 * Takes the screenshots of one job and checks every file (taken, name check passed, compressed, size).
 */
$take = static function ( string $label, string $user, string $viewport, array $shot_list ) use ( $node, $compress, &$users, &$report, $shots, $work, $browsers, $themecheck, $site_title, $forbidden ): void {
	pqbg_test_stop_point();
	$r = $node(
		'shots.mjs',
		array(
			'browser'      => $browsers[0],
			'puppeteerDir' => dirname( $themecheck ),
			'profile'      => $work . '/profile-' . $user,
			'user'         => $users[ $user ]['login'],
			'pass'         => $users[ $user ]['pass'],
			'viewport'     => $viewport,
			'outDir'       => $shots,
			'siteTitle'    => $site_title,
			'shopName'     => PQBG_MANUAL_SHOP,
			'forbidden'    => $forbidden,
			// Other plugins' admin notices and WooCommerce's tour pop-ups are not part of this plugin's screens; the
			// fixed admin bar would cover the top of a clipped screenshot (the name check still reads the whole page).
			'css'          => '.notice:not([class*="pqbg"]):not(.inline),.woocommerce-layout__notice-list,.wp-pointer,.woocommerce-tour-kit{display:none !important}' . ( 'desktop' === $viewport ? '#wpadminbar{display:none !important}html.wp-toolbar{padding-top:0 !important}' : '' ),
			'shots'        => $shot_list,
		)
	);
	$names = array_values( array_filter( array_merge( array_column( $shot_list, 'name' ), array_filter( array_map( static fn( $s ) => $s['steps'][0]['loginShot'] ?? null, $shot_list ) ) ) ) );
	pqbg_t( "screenshots: {$label} (" . implode( ', ', $names ) . '): taken, no real name on any page', ! empty( $r['ok'] ) && count( $r['files'] ?? array() ) === count( $names ), implode( ' | ', $r['errors'] ?? array() ) );
	foreach ( array_unique( (array) ( $r['warnings'] ?? array() ) ) as $warning ) {
		echo "INFO  page script error (not the plugin's screens' content): {$warning}\n";
		$report['warnings'][] = $warning;
	}
	foreach ( $names as $name ) {
		$png  = $shots . '/' . $name . '.png';
		$size = is_file( $png ) ? $compress( $png ) : 0;
		$dims = is_file( $png ) ? getimagesize( $png ) : array( 0, 0 );
		$report['shots'][ $name ] = array( $size, $dims[0], $dims[1], $viewport );
		pqbg_t( "screenshot {$name}: compressed to at most " . size_format( PQBG_MANUAL_MAX_SHOT ), $size > 0 && $size <= PQBG_MANUAL_MAX_SHOT, size_format( $size, 1 ) . ", {$dims[0]} × {$dims[1]}" );
	}
};

// The Scan base URL for the screenshots (the stored settings are restored byte for byte in cleanup).
$settings_set = static function ( string $base ): void {
	$s                  = get_option( Plugin::SETTINGS_OPTION, array() );
	$s                  = is_array( $s ) ? $s : array();
	$s['scan_base_url'] = $base;
	update_option( Plugin::SETTINGS_OPTION, $s );
};

$sell = static function ( int $item, int $qty, int $seller, string $method, ?int $at = null ) use ( $wpdb ): int {
	$code = (string) ( CodeRepository::find_active_for_product( $item )['code'] ?? '' );
	$r    = SaleService::sell( array( 'code' => $code, 'quantity' => $qty, 'request_id' => wp_generate_uuid4(), 'seller_id' => $seller, 'payment_method' => $method ) );
	if ( is_wp_error( $r ) || 'completed' !== $r['status'] ) {
		throw new RuntimeException( 'sample sale failed: ' . ( is_wp_error( $r ) ? $r->get_error_message() : wp_json_encode( $r['sale'] ) ) );
	}
	$id = (int) $r['sale']['id'];
	if ( null !== $at ) {
		// Build tool only (as the Phase 11 suite): back-date the build's own sample row for the reports' history.
		$wpdb->update( Schema::sales_table(), array( 'created_at_gmt' => gmdate( 'Y-m-d H:i:s', $at ) ), array( 'id' => $id ) );
	}
	return $id;
};

$ok_pdf = false;

try {
	// ------------------------------------------------------------ sample data
	pqbg_section( 'sample data' );
	$real_admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ) )[0] ?? null;
	wp_set_current_user( $real_admin ? $real_admin->ID : 0 );

	foreach (
		array(
			'owner'  => array( 'Owner (sample)', 'administrator' ),
			'ravi'   => array( 'Ravi (sample)', 'shop_manager' ),
			'asha'   => array( 'Asha', 'pqbg_seller' ),
			'vikram' => array( 'Vikram', 'pqbg_seller' ),
		) as $key => [ $display, $role ]
	) {
		$pass = wp_generate_password( 24, true, false );
		$id   = wp_insert_user(
			array(
				'user_login'   => 'pqbg_manual_' . $key,
				'user_pass'    => $pass,
				'user_email'   => 'pqbg-manual-' . $key . '@example.com',
				'display_name' => $display,
				'first_name'   => strtok( $display, ' ' ),
				'role'         => $role,
			)
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( 'sample user: ' . $id->get_error_message() );
		}
		$created['users'][] = $id;
		$users[ $key ]      = array(
			'id'    => $id,
			'login' => 'pqbg_manual_' . $key,
			'pass'  => $pass,
		);
	}
	pqbg_t( 'sample users: Owner (administrator), Ravi (shop manager), Asha and Vikram (Store Sellers)', 4 === count( $created['users'] ) );

	// Products are saved by the sample owner, so they get their codes as in real use.
	wp_set_current_user( $users['owner']['id'] );
	$term = static function ( string $name ) use ( &$created ): int {
		$t                    = wp_insert_term( $name . ' (sample)', 'product_cat' );
		$created['terms'][] = is_wp_error( $t ) ? 0 : (int) $t['term_id'];
		return end( $created['terms'] );
	};
	$simple = static function ( string $name, string $sku, string $price, int $term_id ) use ( &$created ): int {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_sku( $sku );
		$p->set_regular_price( $price );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( 500 );
		$p->set_category_ids( array( $term_id ) );
		$p->set_status( 'publish' );
		$id                      = $p->save();
		$created['products'][] = $id;
		return $id;
	};
	$t_kurtas = $term( 'Kurtas' );
	$t_dup    = $term( 'Dupattas & scarves' );
	$t_sarees = $term( 'Sarees & shawls' );
	$t_shirts = $term( 'Shirts' );
	$t_foot   = $term( 'Footwear' );
	$t_tees   = $term( 'T-shirts' );

	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( array( 'S', 'M', 'L' ) );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$kp = new WC_Product_Variable();
	$kp->set_name( 'Cotton kurta – indigo' );
	$kp->set_sku( 'SAMPLE-KURTA' );
	$kp->set_attributes( array( $attr ) );
	$kp->set_category_ids( array( $t_kurtas ) );
	$kp->set_status( 'publish' );
	$kurta                   = $kp->save();
	$created['products'][] = $kurta;
	$size                    = array();
	foreach ( array( 'S', 'M', 'L' ) as $s ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $kurta );
		$v->set_attributes( array( 'size' => $s ) );
		$v->set_sku( 'SAMPLE-KURTA-' . $s );
		$v->set_regular_price( '1499' );
		$v->set_manage_stock( true );
		$v->set_stock_quantity( 500 );
		$v->set_status( 'publish' );
		$size[ $s ] = $v->save();
	}
	WC_Product_Variable::sync( $kurta );

	$dupatta = $simple( 'Silk dupatta – maroon', 'SAMPLE-DUPATTA', '899', $t_dup );
	$scarf   = $simple( 'Printed cotton scarf', 'SAMPLE-SCARF', '349', $t_dup );
	$saree   = $simple( 'Handloom saree – green', 'SAMPLE-SAREE', '3299', $t_sarees );
	$shirt   = $simple( 'Linen shirt – white', 'SAMPLE-SHIRT', '1299', $t_shirts );
	$shawl   = $simple( 'Wool shawl – grey', 'SAMPLE-SHAWL', '1899', $t_sarees );
	$jutti   = $simple( 'Embroidered jutti', 'SAMPLE-JUTTI', '1299', $t_foot );
	$items   = array( $size['S'], $size['M'], $size['L'], $dupatta, $scarf, $saree, $shirt, $shawl, $jutti );
	$coded   = array_filter( $items, static fn( $id ) => null !== CodeRepository::find_active_for_product( $id ) );
	pqbg_t( 'sample catalogue: a kurta in three sizes and six simple products, each with its code (saved by the sample owner)', count( $items ) === count( $coded ), count( $coded ) . ' of ' . count( $items ) );

	// Known costs (administrators only; the kurta's is the default for its sizes). The scarf, the shawl and the jutti have none.
	foreach ( array( $kurta => '900', $dupatta => '520', $saree => '2100', $shirt => '780' ) as $id => $cost ) {
		CostPrice::set( $id, (string) CostPrice::normalize( $cost ) );
	}

	// Four weeks of history, the same every build (a fixed seed; build tool only).
	pqbg_section( 'sample history (27 days back)' );
	mt_srand( 101 );
	$tz      = wp_timezone();
	$today   = new DateTimeImmutable( 'today', $tz );
	$pool    = array( $size['S'], $size['M'], $size['L'], $size['M'], $dupatta, $scarf, $scarf, $saree, $shirt, $shawl );
	$methods = array( 'cash', 'cash', 'cash', 'upi', 'upi', 'upi', 'card', 'card' );
	$history = 0;
	for ( $d = 27; $d >= 1; $d-- ) {
		pqbg_test_stop_point();
		$n = mt_rand( 2, 6 ) + ( in_array( (int) $today->modify( "-{$d} days" )->format( 'N' ), array( 6, 7 ), true ) ? 3 : 0 );
		for ( $i = 0; $i < $n; $i++ ) {
			$at = $today->modify( "-{$d} days" )->setTime( mt_rand( 10, 19 ), mt_rand( 0, 59 ) )->getTimestamp();
			$sell( $pool[ mt_rand( 0, count( $pool ) - 1 ) ], mt_rand( 1, 10 ) > 8 ? 2 : 1, mt_rand( 0, 1 ) ? $users['asha']['id'] : $users['vikram']['id'], $methods[ mt_rand( 0, count( $methods ) - 1 ) ], $at );
			++$history;
		}
	}
	// Yesterday's cash sale of a dupatta, returned today (chapter 8's example).
	$returned = $sell( $dupatta, 1, $users['asha']['id'], 'cash', $today->modify( '-1 day' )->setTime( 17, 40 )->getTimestamp() );
	pqbg_t( 'sample history: sales on each of the last 27 days (through SaleService::sell(), then back-dated)', $history > 27, $history . ' sales' );

	// The stock the manual's examples use (before today's sales).
	foreach ( array( $size['S'] => 12, $size['M'] => 8, $size['L'] => 5, $dupatta => 5, $scarf => 20, $saree => 3, $shirt => 6, $shawl => 4, $jutti => 0 ) as $id => $qty ) {
		wc_update_product_stock( wc_get_product( $id ), $qty, 'set' );
	}

	pqbg_section( 'sample sales today' );
	$today_ids = array(
		'kurta_m' => $sell( $size['M'], 1, $users['asha']['id'], 'cash' ),
		'kurta_l' => $sell( $size['L'], 1, $users['vikram']['id'], 'cash' ),
		'shirt'   => $sell( $shirt, 1, $users['asha']['id'], 'cash' ),
		'saree'   => $sell( $saree, 1, $users['vikram']['id'], 'upi' ),
		'scarf'   => $sell( $scarf, 2, $users['asha']['id'], 'card' ),
	);
	$void = SaleService::void_sale( $returned, $users['ravi']['id'], 'Customer returned it (unused).', true );
	pqbg_t( 'today: three cash sales (₹4,297), UPI and card sales, and yesterday\'s ₹899 cash sale voided by Ravi', ! is_wp_error( $void ) && 'voided' === $void['status'], is_wp_error( $void ) ? $void->get_error_message() : '' );

	// ------------------------------------------------------------ phone (the real scan page, this site's address)
	pqbg_section( 'screenshots: phone' );
	$settings_set( '' );
	$code_of = static fn( int $id ): string => (string) ( CodeRepository::find_active_for_product( $id )['code'] ?? '' );
	$take(
		'Asha on a phone',
		'asha',
		'phone',
		array(
			array(
				'name'  => 'phone-scan',
				'steps' => array( array( 'goto' => ScanUrl::for_code( $code_of( $size['S'] ) ), 'loginShot' => 'phone-login' ) ),
			),
			array(
				'name'  => 'phone-sell',
				'steps' => array( array( 'select' => array( '#pqbg-quantity', '2' ) ), array( 'click' => 'input[name="payment_method"][value="upi"]' ) ),
				'clip'  => array( 'selector' => array( '.pqbg-scan__price', '.pqbg-scan__sell' ), 'fullWidth' => true, 'pad' => 8 ),
			),
			array(
				'name'  => 'phone-undo',
				'steps' => array( array( 'click' => '.pqbg-scan__button--sell', 'nav' => true ) ),
			),
			array(
				'name'  => 'phone-mysales',
				'steps' => array( array( 'goto' => ScanUrl::my_sales_url() ) ),
				'full'  => true,
			),
			array(
				'name'  => 'phone-refused',
				'steps' => array( array( 'goto' => ScanUrl::for_code( $code_of( $jutti ) ) ) ),
			),
		)
	);
	wp_cache_flush(); // The sale ran in Apache.
	pqbg_t( 'the phone sale took 2 of size S (12 → 10)', 10 === wc_get_product( $size['S'] )->get_stock_quantity() );

	// ------------------------------------------------------------ wp-admin
	pqbg_section( 'screenshots: administrator (local address)' );
	$take( 'the owner, local address', 'owner', 'desktop', array( array( 'name' => 'dashboard-local-warning', 'steps' => array( array( 'goto' => AdminUrl::dashboard() ) ), 'clip' => array( 'selector' => '.pqbg-dashboard__attention' ) ) ) );

	pqbg_section( 'screenshots: administrator' );
	$settings_set( PQBG_MANUAL_BASE );
	PrintJob::save_prefs(
		$users['owner']['id'],
		array_merge(
			PrintJob::defaults(),
			array(
				'copies_mode' => 'stock',
				'fields'      => array( 'name', 'attributes', 'price', 'store' ),
			)
		)
	);
	$wrap = static fn( int $max = 900 ): array => array( 'selector' => '#wpbody-content .wrap', 'maxHeight' => $max, 'pad' => 4 );
	$take(
		'the owner',
		'owner',
		'desktop',
		array(
			array( 'name' => 'settings', 'steps' => array( array( 'goto' => AdminUrl::settings() ) ), 'clip' => $wrap( 820 ) ),
			array( 'name' => 'product-box', 'steps' => array( array( 'goto' => AdminUrl::product_edit( $kurta ) ) ), 'clip' => array( 'selector' => '#pqbg-codes' ) ),
			array( 'name' => 'print-setup', 'steps' => array( array( 'follow' => '#pqbg-codes a.pqbg-print-all' ) ), 'clip' => $wrap( 1150 ) ),
			array( 'name' => 'print-preview', 'steps' => array( array( 'click' => '#wpbody-content .wrap form p.submit button.button-primary', 'nav' => true ) ) ),
			array( 'name' => 'sales-list', 'steps' => array( array( 'goto' => AdminUrl::sales( array( 'range' => 'today' ) ) ) ), 'clip' => $wrap( 820 ) ),
			array( 'name' => 'sale-detail', 'steps' => array( array( 'goto' => AdminUrl::sale( $today_ids['shirt'] ) ) ), 'clip' => $wrap( 900 ) ),
			array( 'name' => 'void-form', 'steps' => array( array( 'goto' => AdminUrl::sale_void( $today_ids['scarf'] ) ) ), 'clip' => $wrap( 700 ) ),
			array( 'name' => 'end-of-day', 'steps' => array( array( 'goto' => AdminUrl::reports( array( 'tab' => 'eod', 'range' => 'today' ) ) ) ), 'clip' => $wrap( 1400 ) ),
			array( 'name' => 'reports-summary', 'steps' => array( array( 'goto' => AdminUrl::reports( array( 'tab' => 'summary', 'range' => 'last7' ) ) ) ), 'clip' => $wrap( 1200 ) ),
			array( 'name' => 'reports-profit', 'steps' => array( array( 'goto' => AdminUrl::reports( array( 'tab' => 'profit', 'range' => 'last7' ) ) ) ), 'clip' => $wrap( 1200 ) ),
			array( 'name' => 'health-check', 'steps' => array( array( 'goto' => AdminUrl::health() ) ), 'clip' => $wrap( 1000 ) ),
			array( 'name' => 'plugins-row', 'steps' => array( array( 'goto' => admin_url( 'plugins.php' ) ) ), 'clip' => array( 'selector' => 'tr[data-plugin="' . plugin_basename( PQBG_PLUGIN_FILE ) . '"]', 'pad' => 0 ) ),
		)
	);

	// ------------------------------------------------------------ Bulk tools: 200 T-shirts without codes
	pqbg_section( 'sample catalogue of 200 T-shirts (no codes)' );
	wp_set_current_user( 0 ); // Saved without a user, as an import would: no code on save.
	for ( $i = 1; $i <= 200; $i++ ) {
		pqbg_test_stop_point();
		$simple( sprintf( 'Cotton T-shirt – sample %03d', $i ), sprintf( 'SAMPLE-TEE-%03d', $i ), '499', $t_tees );
	}
	wp_set_current_user( $users['owner']['id'] );
	$tees = array_slice( $created['products'], -200 );
	pqbg_t( '200 sample T-shirts, none with a code', 200 === count( $tees ) && ! array_filter( $tees, static fn( $id ) => null !== CodeRepository::find_active_for_product( $id ) ) );

	$bulk = static fn( string $name, array $clip ): array => array( 'name' => $name, 'steps' => array( array( 'goto' => AdminUrl::bulk_tools( 'tools' ) ) ), 'clip' => $clip );
	$gen  = array( 'heading' => 'Generate missing codes', 'until' => 'Export codes (CSV)' );
	$take( 'Code tools before', 'owner', 'desktop', array( $bulk( 'codes-before', $gen ) ) );

	// The run itself goes through BulkGenerator, exactly as the page's batches do: one batch, Stop, then Continue.
	$state = BulkGenerator::start( array( 'publish' ), $users['owner']['id'] );
	if ( is_wp_error( $state ) ) {
		throw new RuntimeException( 'bulk start: ' . $state->get_error_message() );
	}
	$run = (string) $state['id'];
	BulkGenerator::run_batch( $run, $users['owner']['id'], BulkGenerator::TIME_BUDGET, BulkGenerator::BATCH );
	BulkGenerator::stop( $run );
	pqbg_t( 'code generation stopped after the first batch of 100', BulkGenerator::STOPPED === ( BulkGenerator::state()['status'] ?? '' ) && 100 === count( array_filter( $tees, static fn( $id ) => null !== CodeRepository::find_active_for_product( $id ) ) ) );
	$take( 'Code tools stopped', 'owner', 'desktop', array( $bulk( 'codes-stopped', $gen ) ) );

	BulkGenerator::resume( $run );
	for ( $guard = 0; $guard < 10 && BulkGenerator::DONE !== ( BulkGenerator::state()['status'] ?? '' ); $guard++ ) {
		BulkGenerator::run_batch( $run, $users['owner']['id'], BulkGenerator::TIME_BUDGET, BulkGenerator::BATCH );
	}
	pqbg_t( 'continued and finished: every T-shirt has a code', BulkGenerator::DONE === ( BulkGenerator::state()['status'] ?? '' ) && ! array_filter( $tees, static fn( $id ) => null === CodeRepository::find_active_for_product( $id ) ) );

	// ------------------------------------------------------------ cost import: the filled-in file
	pqbg_section( 'cost price file' );
	$mem = fopen( 'php://memory', 'w+' );
	CostImport::write_template( $mem );
	rewind( $mem );
	$csv = (string) preg_replace( '/^\xEF\xBB\xBF/', '', (string) stream_get_contents( $mem ) ); // The template's UTF-8 mark, before parsing (it would hide the first quote).
	fclose( $mem );
	$mem = fopen( 'php://memory', 'w+' );
	fwrite( $mem, $csv );
	rewind( $mem );
	$header = fgetcsv( $mem, 0, ',', '"', '' );
	$rows   = array();
	while ( false !== ( $row = fgetcsv( $mem, 0, ',', '"', '' ) ) ) {
		$rows[] = $row;
	}
	fclose( $mem );
	$col_sku   = array_search( 'SKU', $header, true );
	$col_cost  = count( $header ) - 1;
	$fill      = array(
		'SAMPLE-SCARF'   => '180',
		'SAMPLE-SHAWL'   => '1,200.50',
		'SAMPLE-DUPATTA' => 'clear',
		'SAMPLE-SHIRT'   => '78O',
		'SAMPLE-SAREE'   => '',
	);
	$chosen    = array();
	foreach ( $rows as $row ) {
		if ( false !== $col_sku && array_key_exists( (string) $row[ $col_sku ], $fill ) ) {
			$row[ $col_cost ] = $fill[ (string) $row[ $col_sku ] ];
			$chosen[]         = $row;
		}
	}
	usort( $chosen, static fn( $a, $b ) => array_search( $a[ $col_sku ], array_keys( $fill ), true ) <=> array_search( $b[ $col_sku ], array_keys( $fill ), true ) );
	$csv_file = $work . '/sample-costs.csv';
	$out      = fopen( $csv_file, 'w' );
	fwrite( $out, "\xEF\xBB\xBF" );
	fputcsv( $out, $header, ',', '"', '' );
	foreach ( $chosen as $row ) {
		fputcsv( $out, $row, ',', '"', '' );
	}
	fclose( $out );
	pqbg_t( 'the template (CostImport::write_template()) filled in: 5 rows (180, 1,200.50, clear, 78O by mistake, empty)', 5 === count( $chosen ) && false !== $col_sku, implode( ', ', $header ) );

	// The file as a spreadsheet shows it (a picture for the manual).
	$letters = range( 'A', 'Z' );
	$table   = '<tr><th></th>' . implode( '', array_map( static fn( $i ) => '<th>' . $letters[ $i ] . '</th>', array_keys( $header ) ) ) . '</tr>';
	foreach ( array_merge( array( $header ), $chosen ) as $n => $row ) {
		$table .= '<tr><th>' . ( $n + 1 ) . '</th>' . implode( '', array_map( static fn( $c, $i ) => '<td' . ( $i === $col_cost && $n > 0 ? ' class="cost"' : '' ) . ( 0 === $n ? ' class="head"' : '' ) . '>' . esc_html( (string) $c ) . '</td>', $row, array_keys( $row ) ) ) . '</tr>';
	}
	file_put_contents( $work . '/cost-file.html', '<!DOCTYPE html><html><head><meta charset="utf-8"><title>sample-costs.csv</title><style>body{margin:12px;font:13px Calibri,Segoe UI,Arial,sans-serif;background:#fff}p{margin:0 0 6px;color:#50575e}table{border-collapse:collapse}th,td{border:1px solid #d0d0d0;padding:3px 8px;white-space:nowrap}th{background:#f3f3f3;color:#555;font-weight:400;text-align:center}td.head{font-weight:700}td.cost{background:#fff8c5;font-weight:700}</style></head><body><p>sample-costs.csv</p><table id="sheet">' . $table . '</table></body></html>' );

	pqbg_section( 'screenshots: Bulk tools and the Dashboard' );
	$costs_url = AdminUrl::bulk_tools( 'costs' );
	$take(
		'the owner: Bulk tools',
		'owner',
		'desktop',
		array(
			$bulk( 'codes-finished', $gen ),
			$bulk( 'codes-export', array( 'heading' => 'Export codes (CSV)', 'until' => 'Recent bulk runs' ) ),
			array( 'name' => 'cost-file', 'steps' => array( array( 'goto' => 'file:///' . str_replace( '\\', '/', $work ) . '/cost-file.html' ) ), 'clip' => array( 'selector' => array( 'p', '#sheet' ), 'pad' => 6 ) ),
			array(
				'name'  => 'cost-preview',
				'steps' => array( array( 'goto' => $costs_url ), array( 'upload' => array( 'input[name="pqbg_file"]', $csv_file ) ), array( 'click' => 'form.pqbg-upload-form [type="submit"]', 'nav' => true ) ),
				'clip'  => $wrap( 1100 ),
			),
			array(
				'name'  => 'cost-report',
				'steps' => array( array( 'check' => 'input[name="ack"]' ), array( 'click' => 'form:has(input[name="ack"]) [type="submit"]', 'nav' => true ), array( 'waitText' => 'The report stays available for an hour' ) ),
				'clip'  => $wrap( 700 ),
			),
			$bulk( 'recent-runs', array( 'heading' => 'Recent bulk runs' ) ),
			array(
				'name'      => 'dashboard-admin',
				'steps'     => array( array( 'goto' => AdminUrl::dashboard() ), array( 'focus' => '.pqbg-help__link' ) ),
				'keepFocus' => true,
				'clip'      => $wrap( 1250 ),
			),
		)
	);
	wp_cache_flush();
	pqbg_t( 'the cost import applied 3 rows: scarf 180, shawl 1200.50, dupatta cleared; the shirt (error) and the saree (empty) unchanged', '180.00' === CostPrice::get( $scarf ) && '1200.50' === CostPrice::get( $shawl ) && '' === CostPrice::get( $dupatta ) && '780.00' === CostPrice::get( $shirt ) && '2100.00' === CostPrice::get( $saree ), wp_json_encode( array( CostPrice::get( $scarf ), CostPrice::get( $shawl ), CostPrice::get( $dupatta ), CostPrice::get( $shirt ), CostPrice::get( $saree ) ) ) );

	$take( 'Ravi (shop manager)', 'ravi', 'desktop', array( array( 'name' => 'dashboard-manager', 'steps' => array( array( 'goto' => AdminUrl::dashboard() ) ), 'clip' => $wrap( 1100 ) ) ) );

	// ------------------------------------------------------------ the PDF
	pqbg_section( 'PDF' );
	$pdf_tmp = $work . '/user-manual.pdf';
	$pdf     = $node(
		'render.mjs',
		array(
			'md'           => $plugin_dir . '/docs/user-manual.md',
			'shots'        => $shots,
			'template'     => __DIR__ . '/manual.template.html',
			'css'          => __DIR__ . '/manual.css',
			'out'          => $pdf_tmp,
			'work'         => $work,
			'version'      => PQBG_VERSION,
			'date'         => wp_date( 'j F Y', ( new DateTimeImmutable( $release_date . ' 12:00:00', wp_timezone() ) )->getTimestamp() ),
			'browser'      => $browsers[0],
			'puppeteerDir' => dirname( $themecheck ),
			'tools'        => $tools,
		)
	);
	$report['pdf'] = $pdf;
	pqbg_t( 'the PDF: rendered in two passes, every heading found in order, page numbers stable, no screenshot missing', ! empty( $pdf['ok'] ) && array() === ( $pdf['missing'] ?? array( 1 ) ), implode( ' | ', $pdf['errors'] ?? array() ) );
	pqbg_t( 'the PDF is at most ' . size_format( PQBG_MANUAL_MAX_PDF ), ( $pdf['bytes'] ?? PHP_INT_MAX ) <= PQBG_MANUAL_MAX_PDF, size_format( (int) ( $pdf['bytes'] ?? 0 ), 2 ) . ', ' . ( $pdf['pages'] ?? '?' ) . ' pages' );
	$text = $node( 'pdf-text.mjs', array( 'file' => $pdf_tmp, 'tools' => $tools ) );
	$all  = strtolower( implode( "\n", $text['text'] ?? array() ) );
	$hits = array_values( array_filter( $forbidden, static fn( $f ) => str_contains( $all, strtolower( $f ) ) ) );
	pqbg_t( 'the PDF\'s text names no real site, administrator or e-mail', ! empty( $text['ok'] ) && array() === $hits, implode( ', ', $hits ) );
	$ok_pdf = ! empty( $pdf['ok'] ) && ( $pdf['bytes'] ?? PHP_INT_MAX ) <= PQBG_MANUAL_MAX_PDF && array() === $hits && ! empty( $text['ok'] );
	if ( $ok_pdf ) {
		copy( $pdf_tmp, $plugin_dir . '/docs/user-manual.pdf' );
	}
	if ( '' !== $keep ) {
		wp_mkdir_p( $keep );
		foreach ( glob( $shots . '/*.png' ) ?: array() as $png ) {
			copy( $png, rtrim( $keep, '/\\' ) . '/' . basename( $png ) );
		}
	}
} catch ( PqbgTestStop $e ) {
	throw $e;
} catch ( Throwable $e ) {
	pqbg_t( 'the build ran without an error', false, $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
	pqbg_section( 'cleanup' );
	wp_set_current_user( 0 );
	$ids = array_values( array_filter( $created['products'] ) );
	if ( array() !== $ids ) {
		$children = array_map( 'intval', (array) $wpdb->get_col( 'SELECT ID FROM ' . $wpdb->posts . ' WHERE post_parent IN (' . implode( ',', array_map( 'intval', $ids ) ) . ") AND post_type = 'product_variation'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
		$all_ids  = implode( ',', array_map( 'intval', array_merge( $ids, $children ) ) );
		$wpdb->query( 'DELETE FROM ' . Schema::sales_table() . " WHERE product_id IN ($all_ids) OR variation_id IN ($all_ids)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
		$wpdb->query( 'DELETE FROM ' . Schema::codes_table() . " WHERE product_id IN ($all_ids)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( $p ) {
				$p->delete( true );
			}
		}
	}
	foreach ( array_filter( $created['terms'] ) as $t ) {
		wp_delete_term( (int) $t, 'product_cat' );
	}
	// WooCommerce keeps a deleted category's rows in its category lookup table; remove this build's own.
	pqbg_test_catlookup_cleanup( $cl_mark );
	foreach ( $created['users'] as $uid ) {
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d", $uid ) ) as $post_id ) {
			wp_delete_post( (int) $post_id, true ); // The Dashboard's Quick Draft auto-draft (see tests/README.md).
		}
		wp_delete_user( $uid );
	}
	// Plugin options: every one the build changed goes back byte for byte; every one it created goes.
	$now_opts = $pqbg_options();
	foreach ( $now_opts as $name => $row ) {
		if ( ! isset( $options0[ $name ] ) ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		} elseif ( $options0[ $name ]->option_value !== $row->option_value || $options0[ $name ]->autoload !== $row->autoload ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $options0[ $name ]->option_value, 'autoload' => $options0[ $name ]->autoload ), array( 'option_name' => $name ) );
		}
	}
	foreach ( $options0 as $name => $row ) {
		if ( ! isset( $now_opts[ $name ] ) ) {
			$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $row->option_value, 'autoload' => $row->autoload ) );
		}
	}
	foreach ( array( Schema::sales_table(), Schema::codes_table() ) as $table ) {
		if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier.
			$wpdb->query( "ALTER TABLE $table AUTO_INCREMENT = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier.
		}
	}
	pqbg_test_as_cleanup( $mark );
	wp_cache_flush();
	if ( is_dir( $work ) ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $work, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
		}
		@rmdir( $work );
	}

	// The leak guard.
	$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE 'SAMPLE-%'" )
		+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'pqbg\\_manual\\_%'" )
		+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE name LIKE '%(sample)'" );
	pqbg_t( 'cleanup: no sample product, variation, user or category left', 0 === $left, (string) $left );
	$opt_now  = $pqbg_options();
	$opt_same = array_keys( $opt_now ) === array_keys( $options0 ) && array() === array_filter( array_keys( $opt_now ), static fn( $n ) => $opt_now[ $n ]->option_value !== $options0[ $n ]->option_value || $opt_now[ $n ]->autoload !== $options0[ $n ]->autoload );
	pqbg_t( 'cleanup: every plugin option (settings, bulk log and run, timing samples, label cache) byte-identical to the start', $opt_same );
	$counts1 = $table_counts();
	$diff    = array();
	foreach ( $counts1 + $counts0 as $table => $unused ) {
		if ( ( $counts0[ $table ] ?? -1 ) !== ( $counts1[ $table ] ?? -1 ) ) {
			$diff[] = $table . ' ' . ( $counts0[ $table ] ?? 'none' ) . '→' . ( $counts1[ $table ] ?? 'none' );
		}
	}
	$non_option = array_filter( $diff, static fn( $d ) => ! str_starts_with( $d, $GLOBALS['wpdb']->options . ' ' ) );
	pqbg_t( 'cleanup: every table has the same number of rows as at the start (options: transients only, listed)', array() === $non_option, implode( ', ', $diff ) );
	if ( array() !== $diff ) {
		$new_opts = (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} ORDER BY option_id DESC LIMIT 40" );
		echo 'INFO  options table: ' . implode( ', ', $diff ) . '; newest options: ' . implode( ', ', array_slice( $new_opts, 0, 15 ) ) . "\n";
	}
	pqbg_test_as_check( $mark );
	pqbg_test_catlookup_check( $cl_mark );
	// The snapshot goes only when the cleanup is proven complete; otherwise --repair uses it.
	$as_left  = pqbg_test_as_leftovers( $mark );
	$clean_ok = 0 === $left && $opt_same && array() === $non_option && array() === $as_left['actions'] && 0 === $as_left['orphan_logs'] && pqbg_test_catlookup_orphans() <= $cl_mark['orphans'] && 0 === pqbg_test_prodlookup_orphans();
	if ( $clean_ok ) {
		@unlink( pqbg_manual_snapshot_file() );
	} else {
		echo 'Cleanup incomplete: the snapshot ' . pqbg_manual_snapshot_file() . " is kept. Run php build/manual/build-manual.php --repair (dry run first).\n";
	}
	$sizes = array_sum( array_column( $report['shots'], 0 ) );
	echo "\nSHOTS: " . count( $report['shots'] ) . ' screenshots, ' . size_format( $sizes, 1 ) . " after compression\n";
	foreach ( $report['shots'] as $name => [ $bytes, $w, $h, $vp ] ) {
		printf( "  %-26s %8s  %4d × %-4d %s\n", $name, size_format( $bytes, 1 ), $w, $h, $vp );
	}
	if ( isset( $report['pdf']['pages'] ) ) {
		echo 'PDF: ' . $report['pdf']['pages'] . ' pages, ' . size_format( (int) $report['pdf']['bytes'], 2 ) . ', title "' . ( $report['pdf']['title'] ?? '' ) . '", ' . ( $report['pdf']['outline'] ?? 0 ) . " bookmarks\n";
		foreach ( $report['pdf']['headings'] ?? array() as $h ) {
			printf( "  p.%-3d %s%s\n", $h['page'], 3 === $h['level'] ? '    ' : '', $h['text'] . ( $h['admin'] ? '  [Administrator only]' : '' ) );
		}
	}
	echo $ok_pdf ? "docs/user-manual.pdf written.\n" : "docs/user-manual.pdf NOT written.\n";
}

pqbg_test_done();
