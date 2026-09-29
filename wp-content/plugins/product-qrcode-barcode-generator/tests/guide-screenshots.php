<?php
/**
 * Builds the seller guide (Phase 13): phone screenshots from sample data, then docs/seller-guide.html
 * (self-contained, the screenshots inlined) and docs/seller-guide.pdf (A4).
 *
 *   php tests/guide-screenshots.php
 *
 * Not a regression suite (not in run.php), but it follows the suites' rules: it refuses a production
 * site without PQBG_TESTS_ALLOW_PRODUCTION=1, creates only clearly fictional sample data (the seller
 * "Asha", an indigo cotton kurta and a silk dupatta in two sample categories), sells through the real
 * scan page over HTTP (and one earlier sale through SaleService::sell(), for My sales), and removes
 * every row it created in `finally`, including the Action Scheduler jobs of its product saves.
 *
 * Needs:
 *   PQBG_THEMECHECK  the installed theme-check tool (its node_modules provide puppeteer-core)
 *   PQBG_BROWSERS    optional; the first entry is used (default: the installed Chrome, then Edge)
 *   PQBG_GUIDE_SHOTS optional; also keep the PNGs in this folder
 *
 * Rebuild the guide whenever the scan screens or the template (tests/guide/) change.
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/bootstrap.php';
pqbg_test_load_wp();

use ProductQrBarcode\{CodeRepository, SaleService, Schema, ScanUrl};

global $wpdb;

$themecheck = (string) getenv( 'PQBG_THEMECHECK' );
$browsers   = array_values( array_filter( array_merge( explode( ';', (string) getenv( 'PQBG_BROWSERS' ) ), array( 'C:\Program Files\Google\Chrome\Application\chrome.exe', 'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe', 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe' ) ), 'is_file' ) );

if ( '' === $themecheck || ! is_file( dirname( $themecheck ) . '/node_modules/puppeteer-core/package.json' ) || array() === $browsers ) {
	fwrite( STDERR, "Needs PQBG_THEMECHECK (the installed theme-check tool with node_modules) and Chrome or Edge.\n" );
	exit( 2 );
}

$plugin_dir = dirname( __DIR__ );
$work       = get_temp_dir() . 'pqbg-guide-' . wp_generate_password( 8, false );
$keep       = (string) getenv( 'PQBG_GUIDE_SHOTS' );
wp_mkdir_p( $work );

$mark     = pqbg_test_as_mark();
$cl_mark  = pqbg_test_catlookup_mark(); // 1.0.1: category lookup rows of the sample categories, see bootstrap.php.
$created  = array( 'products' => array(), 'terms' => array(), 'user' => 0 );
$pqbg_opt = static fn(): string => (string) $wpdb->get_var( "SELECT GROUP_CONCAT(CONCAT(option_name, '=', MD5(option_value)) ORDER BY option_name) FROM {$wpdb->options} WHERE option_name LIKE 'pqbg%'" );
$opts0    = $pqbg_opt();
$node     = static function ( array $job ) use ( $work ): array {
	$file = $work . '/job-' . $job['mode'] . '.json';
	file_put_contents( $file, wp_json_encode( $job ) );
	$out = shell_exec( 'node ' . escapeshellarg( __DIR__ . '/guide/guide-shots.mjs' ) . ' ' . escapeshellarg( $file ) . ' 2>&1' );
	unlink( $file ); // The job holds the sample seller's password.
	$json = json_decode( trim( (string) substr( (string) $out, (int) strrpos( trim( (string) $out ), "\n" ) ) ), true );
	return is_array( $json ) ? $json : array( 'ok' => false, 'errors' => array( (string) $out ) );
};

try {
	pqbg_section( 'sample data' );
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ) )[0] ?? null;
	wp_set_current_user( $admin ? $admin->ID : 0 );

	foreach ( array( 'Kurtas', 'Dupattas' ) as $name ) {
		$term                = wp_insert_term( $name . ' (sample)', 'product_cat' );
		$created['terms'][]  = is_wp_error( $term ) ? 0 : (int) $term['term_id'];
	}

	$make = static function ( string $name, string $sku, string $price, int $stock, int $term ) use ( &$created ): int {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_sku( $sku );
		$p->set_regular_price( $price );
		$p->set_manage_stock( true );
		$p->set_stock_quantity( $stock );
		$p->set_category_ids( array( $term ) );
		$p->set_status( 'publish' );
		$id                      = $p->save();
		$created['products'][] = $id;
		return $id;
	};
	$kurta   = $make( 'Cotton kurta – indigo, M', 'SAMPLE-KURTA-IND-M', '1499', 12, $created['terms'][0] );
	$dupatta = $make( 'Silk dupatta – maroon', 'SAMPLE-DUPATTA-MRN', '899', 5, $created['terms'][1] );
	$code    = static fn( int $id ): string => (string) ( CodeRepository::find_active_for_product( $id )['code'] ?? '' );
	pqbg_t( 'sample products saved by an administrator got their codes', '' !== $code( $kurta ) && '' !== $code( $dupatta ), $code( $kurta ) );

	$password        = wp_generate_password( 24, true, false );
	$created['user'] = wp_insert_user(
		array(
			'user_login'   => 'pqbg_guide_asha',
			'user_pass'    => $password,
			'user_email'   => 'asha.sample@example.com',
			'display_name' => 'Asha',
			'first_name'   => 'Asha',
			'role'         => 'pqbg_seller',
		)
	);
	pqbg_t( 'sample Store Seller "Asha" created', is_int( $created['user'] ) );

	// An earlier sale, so My sales shows more than one line and two payment methods.
	$earlier = SaleService::sell( array( 'code' => $code( $dupatta ), 'quantity' => 1, 'request_id' => wp_generate_uuid4(), 'seller_id' => $created['user'], 'payment_method' => 'cash' ) );
	pqbg_t( 'an earlier sample sale through SaleService::sell() (cash)', ! is_wp_error( $earlier ) && 'completed' === $earlier['status'] );

	pqbg_section( 'screenshots (phone, 375 × 667 at 2×)' );
	$scan_url = ScanUrl::for_code( $code( $kurta ) );
	$shots    = $node(
		array(
			'mode'         => 'shots',
			'browser'      => $browsers[0],
			'puppeteerDir' => dirname( $themecheck ),
			'loginUrl'     => add_query_arg( 'redirect_to', rawurlencode( $scan_url ), wp_login_url() ),
			'user'         => 'pqbg_guide_asha',
			'pass'         => $password,
			'scanUrl'      => $scan_url,
			'mySalesUrl'   => ScanUrl::my_sales_url(),
			'shopName'     => 'Your shop',
			'outDir'       => $work,
		)
	);
	pqbg_t( 'four screenshots taken, no page error', ! empty( $shots['ok'] ) && 4 === count( $shots['files'] ?? array() ), implode( ' | ', $shots['errors'] ?? array() ) );
	wp_cache_flush(); // The sale ran in Apache; drop this process's cached product.
	pqbg_t( 'the sale over HTTP took 2 of 12 in stock', 10 === wc_get_product( $kurta )->get_stock_quantity() );

	pqbg_section( 'guide' );
	$template = (string) file_get_contents( __DIR__ . '/guide/seller-guide.template.html' );
	$html     = str_replace( '{{version}}', PQBG_VERSION, $template );
	foreach ( array( 'scan', 'sell', 'undo', 'mysales' ) as $name ) {
		$png  = $work . '/' . $name . '.png';
		$html = str_replace( '{{' . $name . '}}', is_file( $png ) ? 'data:image/png;base64,' . base64_encode( (string) file_get_contents( $png ) ) : '', $html );
		if ( '' !== $keep && is_file( $png ) ) {
			wp_mkdir_p( $keep );
			copy( $png, rtrim( $keep, '/\\' ) . '/' . $name . '.png' );
		}
	}
	$ok_html = ! str_contains( $html, '{{' ) && 4 === count( $shots['files'] ?? array() );
	pqbg_t( 'every placeholder filled', $ok_html );
	if ( $ok_html ) {
		file_put_contents( $plugin_dir . '/docs/seller-guide.html', $html );
		$pdf = $node(
			array(
				'mode'         => 'pdf',
				'browser'      => $browsers[0],
				'puppeteerDir' => dirname( $themecheck ),
				'html'         => $plugin_dir . '/docs/seller-guide.html',
				'pdf'          => $plugin_dir . '/docs/seller-guide.pdf',
			)
		);
		pqbg_t( 'docs/seller-guide.html written, self-contained (no external URL in src or href)', ! preg_match( '/\b(src|href)="(?!data:|#)/', $html ), size_format( strlen( $html ), 1 ) );
		pqbg_t( 'docs/seller-guide.pdf written: one A4 page', ! empty( $pdf['ok'] ) && 1 === ( $pdf['pages'] ?? 0 ), ( $pdf['pages'] ?? '?' ) . ' page(s), ' . size_format( (int) @filesize( $plugin_dir . '/docs/seller-guide.pdf' ), 1 ) . ' ' . implode( ' | ', $pdf['errors'] ?? array() ) );
	}
} finally {
	pqbg_section( 'cleanup' );
	$ids = array_filter( $created['products'] );
	if ( array() !== $ids ) {
		$in = implode( ',', array_map( 'intval', $ids ) );
		$wpdb->query( 'DELETE FROM ' . Schema::sales_table() . " WHERE product_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
		$wpdb->query( 'DELETE FROM ' . Schema::codes_table() . " WHERE product_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}
	foreach ( array_filter( $created['terms'] ) as $term ) {
		wp_delete_term( $term, 'product_cat' );
	}
	if ( $created['user'] && ! is_wp_error( $created['user'] ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $created['user'] );
	}
	foreach ( array( Schema::sales_table(), Schema::codes_table() ) as $table ) {
		if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier.
			$wpdb->query( "ALTER TABLE $table AUTO_INCREMENT = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier.
		}
	}
	pqbg_test_as_cleanup( $mark );
	array_map( 'unlink', glob( $work . '/*' ) ?: array() );
	@rmdir( $work );
	wp_cache_flush();

	pqbg_t( 'cleanup: no sample product, code, sale, term or user left', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'Cotton kurta – indigo%' OR post_title LIKE 'Silk dupatta – maroon%'" ) && ! get_user_by( 'login', 'pqbg_guide_asha' ) && ! term_exists( 'Kurtas (sample)', 'product_cat' ) && ( array() === $ids || 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::sales_table() . ' WHERE product_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	pqbg_t( 'cleanup: the plugin options are unchanged', $opts0 === $pqbg_opt() );
	pqbg_test_as_check( $mark );
	pqbg_test_catlookup_cleanup( $cl_mark );
	pqbg_test_catlookup_check( $cl_mark );
}

pqbg_test_done();
