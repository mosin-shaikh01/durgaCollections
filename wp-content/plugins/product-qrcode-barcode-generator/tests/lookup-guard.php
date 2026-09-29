<?php
/**
 * WooCommerce lookup-table leak guard (1.0.1), run by run.php around every suite process, next to
 * the Action Scheduler guard.
 *
 *   php tests/lookup-guard.php mark                    prints "category,product" (orphaned row counts)
 *   php tests/lookup-guard.php check <category,product> after the suite's process has exited
 *
 * Orphaned rows:
 *   - wp_wc_category_lookup: category_id or category_tree_id no longer in wp_term_taxonomy (WooCommerce
 *     keeps them when a category is deleted);
 *   - wp_wc_product_meta_lookup: product_id with no matching post.
 * The check fails when either count is higher than before the suite. The mark is plain digits and a
 * comma (see as-guard.php about escapeshellarg() on Windows). Prints one line
 * "LOOKUP-GUARD: PASS|FAIL …". Exit code 0 on PASS.
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require __DIR__ . '/bootstrap.php';
pqbg_test_load_wp();

$mode = $argv[1] ?? '';

if ( 'mark' === $mode ) {
	echo pqbg_test_catlookup_orphans(), ',', pqbg_test_prodlookup_orphans(), "\n";
	exit( 0 );
}

$parts = explode( ',', (string) ( $argv[2] ?? '' ) );

if ( 'check' !== $mode || 2 !== count( $parts ) || array() !== array_filter( $parts, static fn( $p ) => ! ctype_digit( $p ) ) ) {
	fwrite( STDERR, "usage: lookup-guard.php mark | check <category,product>\n" );
	exit( 2 );
}

[ $cat_before, $prod_before ] = array_map( 'intval', $parts );
$cat_now                      = pqbg_test_catlookup_orphans();
$prod_now                     = pqbg_test_prodlookup_orphans();
$ok                           = $cat_now <= $cat_before && $prod_now <= $prod_before;

echo 'LOOKUP-GUARD: ' . ( $ok ? 'PASS' : 'FAIL' ) . " - orphaned category lookup rows {$cat_before} before, {$cat_now} after; orphaned product lookup rows {$prod_before} before, {$prod_now} after\n";

exit( $ok ? 0 : 1 );
