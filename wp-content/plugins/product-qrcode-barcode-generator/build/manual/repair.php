<?php
/**
 * The manual build's start snapshot and its repair mode (1.0.1), after a build that was killed before
 * its cleanup (Claude Code stopped it for low memory once, on 2026-09-28). Loaded by build-manual.php:
 *
 *   php build/manual/build-manual.php --repair           dry run: lists what the killed build left
 *   php build/manual/build-manual.php --repair --apply   removes exactly that, then deletes the snapshot
 *
 * The build saves a snapshot in the system temp folder (pqbg-manual-start.ser) before it creates
 * anything, and deletes it only when its own cleanup checks passed; while a snapshot exists, a new
 * build refuses to start. The repair identifies only what the builds create, each item by two things
 * (above the snapshot's highest ID, and a sample marker):
 *   - users above the highest user ID with a login pqbg_manual_* or pqbg_guide_* (the sample
 *     administrator first), with their meta and sessions;
 *   - products and variations above the highest post ID with a SAMPLE- SKU, or with no SKU at all
 *     (a save interrupted by the kill leaves a bare row), and the variations of those products;
 *     posts above the highest post ID written by a sample user (the Dashboard's auto-draft);
 *   - the plugin's code and sales rows of any post ID above the highest post ID;
 *   - product categories above the highest term ID named "… (sample)";
 *   - WooCommerce lookup rows (category and product meta) of the IDs above, and orphaned ones above the snapshot;
 *   - Action Scheduler jobs above the highest job ID whose arguments name a post ID above the
 *     highest post ID, any status, with their logs, and orphaned logs above the highest log ID;
 *   - every option whose name contains "pqbg" (settings, bulk run and log, timing samples, label
 *     cache index and its transients): restored byte for byte from the snapshot, or deleted when the
 *     snapshot had none;
 *   - the build's temporary folders (pqbg-manual-*, pqbg-guide-*) in the system temp folder.
 * Anything else is left alone. docs/ is never touched (the build writes the PDF only after its checks).
 *
 * @package ProductQrBarcode
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

/**
 * Where the snapshot lives.
 */
function pqbg_manual_snapshot_file(): string {
	return rtrim( str_replace( '\\', '/', get_temp_dir() ), '/' ) . '/pqbg-manual-start.ser';
}

/**
 * The state before a build: the plugin's options and the highest IDs.
 *
 * @return array<string, mixed>
 */
function pqbg_manual_snapshot(): array {
	global $wpdb;

	$max     = static fn( string $table, string $col ): int => (int) $wpdb->get_var( "SELECT COALESCE(MAX($col), 0) FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifiers.
	$options = array();
	foreach ( (array) $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE '%pqbg%'", ARRAY_A ) as $row ) {
		$options[ $row['option_name'] ] = array( $row['option_value'], $row['autoload'] );
	}

	return array(
		'time'    => time(),
		'post'    => $max( $wpdb->posts, 'ID' ),
		'user'    => $max( $wpdb->users, 'ID' ),
		'term'    => $max( $wpdb->terms, 'term_id' ),
		'action'  => $max( $wpdb->prefix . 'actionscheduler_actions', 'action_id' ),
		'log'     => $max( $wpdb->prefix . 'actionscheduler_logs', 'log_id' ),
		'options' => $options,
	);
}

/**
 * Lists (dry run) or removes what a killed build left.
 *
 * @param bool $apply Remove; otherwise only list.
 * @return int Exit code: 0 clean or repaired, 1 something could not be repaired, 3 leftovers listed (dry run).
 */
function pqbg_manual_repair( bool $apply ): int {
	global $wpdb;

	$file = pqbg_manual_snapshot_file();

	if ( ! is_file( $file ) ) {
		echo "No manual build snapshot ({$file}): the last build cleaned up after itself. Nothing to do.\n";
		return 0;
	}

	$s = unserialize( (string) file_get_contents( $file ), array( 'allowed_classes' => false ) );

	if ( ! is_array( $s ) || ! isset( $s['post'], $s['user'], $s['term'], $s['action'], $s['log'], $s['options'] ) ) {
		echo "The snapshot {$file} is unreadable; nothing was changed. Inspect the site by hand.\n";
		return 1;
	}

	$p     = $wpdb->prefix;
	$found = 0;
	$say   = static function ( string $what ) use ( $apply, &$found ): void {
		++$found;
		echo ( $apply ? 'REPAIR  ' : 'WOULD   ' ) . $what . "\n";
	};
	echo 'Snapshot of ' . gmdate( 'Y-m-d H:i', (int) $s['time'] ) . " UTC: highest post {$s['post']}, user {$s['user']}, term {$s['term']}, job {$s['action']}, log {$s['log']}; " . count( $s['options'] ) . " plugin options.\n";
	echo $apply ? "== APPLY ==\n" : "== DRY RUN (nothing changed) ==\n";

	// Users: the sample administrator first.
	$users = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, user_login FROM {$wpdb->users} WHERE ID > %d AND ( user_login LIKE %s OR user_login LIKE %s ) ORDER BY ( user_login = 'pqbg_manual_owner' ) DESC, ID", $s['user'], $wpdb->esc_like( 'pqbg_manual_' ) . '%', $wpdb->esc_like( 'pqbg_guide_' ) . '%' ), ARRAY_A );
	$uids  = array_map( 'intval', array_column( $users, 'ID' ) );

	// Posts: sample products (SAMPLE- SKU, or no SKU at all) and their variations; the sample users' posts.
	$products = array_map(
		'intval',
		(array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku' WHERE p.ID > %d AND p.post_type IN ('product', 'product_variation') AND ( m.meta_value LIKE %s OR m.meta_id IS NULL )",
				$s['post'],
				'SAMPLE-%'
			)
		)
	);
	if ( array() !== $products ) {
		$products = array_values( array_unique( array_merge( $products, array_map( 'intval', (array) $wpdb->get_col( 'SELECT ID FROM ' . $wpdb->posts . " WHERE post_type = 'product_variation' AND post_parent IN (" . implode( ',', $products ) . ')' ) ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
	}
	$authored = array() === $uids ? array() : array_map( 'intval', (array) $wpdb->get_col( 'SELECT ID FROM ' . $wpdb->posts . ' WHERE post_author IN (' . implode( ',', $uids ) . ')' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
	$authored = array_values( array_diff( $authored, $products ) );

	foreach ( $users as $u ) {
		$say( "user {$u['ID']} {$u['user_login']} (and its meta and sessions)" );
	}
	foreach ( $products as $id ) {
		$say( "product/variation {$id} \"" . get_the_title( $id ) . '" (' . ( get_post_meta( $id, '_sku', true ) ?: 'no SKU' ) . ')' );
	}
	foreach ( $authored as $id ) {
		$say( "post {$id} (" . get_post_type( $id ) . ') written by a sample user' );
	}

	$codes = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}pqbg_codes WHERE product_id > %d OR parent_id > %d", $s['post'], $s['post'] ) );
	$sales = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}pqbg_sales WHERE product_id > %d OR variation_id > %d", $s['post'], $s['post'] ) );
	if ( $codes + $sales > 0 ) {
		$say( "{$codes} code row(s) and {$sales} sales row(s) of posts above {$s['post']}" );
	}

	$terms = (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.term_id, t.name FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'product_cat' WHERE t.term_id > %d AND t.name LIKE %s", $s['term'], '%' . $wpdb->esc_like( '(sample)' ) ), ARRAY_A );
	foreach ( $terms as $t ) {
		$say( "category {$t['term_id']} \"" . html_entity_decode( $t['name'] ) . '"' );
	}

	$lk_cat  = $p . 'wc_category_lookup';
	$lk_prod = $p . 'wc_product_meta_lookup';
	$tt      = $wpdb->term_taxonomy;
	$cat_q   = $wpdb->prepare( "FROM $lk_cat l WHERE ( l.category_id > %d OR l.category_tree_id > %d ) AND ( NOT EXISTS (SELECT 1 FROM $tt x WHERE x.term_id = l.category_id) OR NOT EXISTS (SELECT 1 FROM $tt x WHERE x.term_id = l.category_tree_id) )", $s['term'], $s['term'] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifiers.
	$prod_q  = $wpdb->prepare( "FROM $lk_prod l WHERE l.product_id > %d AND NOT EXISTS (SELECT 1 FROM {$wpdb->posts} x WHERE x.ID = l.product_id)", $s['post'] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifiers.
	$n_cat   = (int) $wpdb->get_var( "SELECT COUNT(*) $cat_q" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
	$n_prod  = (int) $wpdb->get_var( "SELECT COUNT(*) $prod_q" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
	if ( $n_cat + $n_prod > 0 ) {
		$say( "{$n_cat} orphaned category lookup row(s) and {$n_prod} orphaned product lookup row(s) above the snapshot" );
	}

	// Action Scheduler: jobs that name a post ID above the snapshot, any status. Called again after the
	// deletes (WooCommerce queues and runs an attribute lookup job for every deleted product) and in VERIFY.
	$find_jobs = static function () use ( $wpdb, $p, $s ): array {
		$post_end = max( $s['post'], (int) $wpdb->get_var( $wpdb->prepare( 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->posts ) ) - 1 );
		$found    = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT action_id, args FROM {$p}actionscheduler_actions WHERE action_id > %d", $s['action'] ), ARRAY_A ) as $a ) {
			preg_match_all( '/\d+/', (string) $a['args'], $m );
			foreach ( $m[0] as $n ) {
				if ( (int) $n > $s['post'] && (int) $n <= $post_end ) {
					$found[] = (int) $a['action_id'];
					break;
				}
			}
		}
		return $found;
	};
	$delete_jobs = static function ( array $ids ) use ( $wpdb, $p, $s ): void {
		if ( array() !== $ids ) {
			$in = implode( ',', array_map( 'intval', $ids ) );
			$wpdb->query( "DELETE FROM {$p}actionscheduler_logs WHERE action_id IN ($in)" );    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
			$wpdb->query( "DELETE FROM {$p}actionscheduler_actions WHERE action_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
		}
		$wpdb->query( $wpdb->prepare( "DELETE l FROM {$p}actionscheduler_logs l LEFT JOIN {$p}actionscheduler_actions a ON a.action_id = l.action_id WHERE l.log_id > %d AND a.action_id IS NULL", $s['log'] ) );
	};
	$jobs = $find_jobs();
	$job_logs    = array() === $jobs ? 0 : (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}actionscheduler_logs WHERE action_id IN (" . implode( ',', $jobs ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
	$orphan_logs = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}actionscheduler_logs l LEFT JOIN {$p}actionscheduler_actions a ON a.action_id = l.action_id WHERE l.log_id > %d AND a.action_id IS NULL", $s['log'] ) );
	if ( array() !== $jobs || $orphan_logs > 0 ) {
		$say( count( $jobs ) . ' Action Scheduler job(s) naming a post above the snapshot' . ( $jobs ? ' (' . min( $jobs ) . '–' . max( $jobs ) . ')' : '' ) . ", {$job_logs} of their log rows, {$orphan_logs} orphaned log row(s)" );
	}

	// The plugin's options.
	$now = array();
	foreach ( (array) $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE '%pqbg%'", ARRAY_A ) as $row ) {
		$now[ $row['option_name'] ] = array( $row['option_value'], $row['autoload'] );
	}
	$opt_plan = array();
	foreach ( $now as $name => $v ) {
		if ( ! isset( $s['options'][ $name ] ) ) {
			$opt_plan[ $name ] = 'delete';
		} elseif ( $s['options'][ $name ] !== $v ) {
			$opt_plan[ $name ] = 'restore';
		}
	}
	foreach ( array_diff_key( $s['options'], $now ) as $name => $unused ) {
		$opt_plan[ $name ] = 'insert';
	}
	foreach ( $opt_plan as $name => $op ) {
		$say( "option {$name}: {$op}" . ( 'delete' === $op ? '' : ' (from the snapshot)' ) );
	}

	$tmp  = rtrim( str_replace( '\\', '/', get_temp_dir() ), '/' );
	$dirs = array_values( array_filter( array_merge( glob( $tmp . '/pqbg-manual-*' ) ?: array(), glob( $tmp . '/pqbg-guide-*' ) ?: array() ), 'is_dir' ) );
	foreach ( $dirs as $d ) {
		$say( "temporary folder {$d}" );
	}

	if ( ! $apply ) {
		echo 0 === $found ? "\nNothing left by the build. Run with --repair --apply to delete the snapshot.\n" : "\n{$found} item(s). Review them, then run: php build/manual/build-manual.php --repair --apply\n";
		return 0 === $found ? 0 : 3;
	}

	// Apply, in the order the build's own cleanup uses; users first (the sample administrator first).
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $uids as $uid ) {
		wp_delete_user( $uid );
	}
	foreach ( $authored as $id ) {
		wp_delete_post( $id, true );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}pqbg_sales WHERE product_id > %d OR variation_id > %d", $s['post'], $s['post'] ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}pqbg_codes WHERE product_id > %d OR parent_id > %d", $s['post'], $s['post'] ) );
	foreach ( $products as $id ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		if ( $product ) {
			$product->delete( true );
		} else {
			wp_delete_post( $id, true );
		}
	}
	foreach ( $terms as $t ) {
		wp_delete_term( (int) $t['term_id'], 'product_cat' );
	}
	$wpdb->query( "DELETE l $cat_q" );  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
	$wpdb->query( "DELETE l $prod_q" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
	$delete_jobs( $jobs );
	// The deletes above made WooCommerce queue (and usually run) new jobs naming the deleted posts.
	$late = $find_jobs();
	if ( array() !== $late ) {
		echo 'REPAIR  ' . count( $late ) . ' Action Scheduler job(s) queued by the deletes themselves (' . min( $late ) . '–' . max( $late ) . "), with their log rows\n";
		$delete_jobs( $late );
	}
	foreach ( $opt_plan as $name => $op ) {
		if ( 'delete' === $op ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		} elseif ( 'restore' === $op ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $s['options'][ $name ][0], 'autoload' => $s['options'][ $name ][1] ), array( 'option_name' => $name ) );
		} else {
			$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $s['options'][ $name ][0], 'autoload' => $s['options'][ $name ][1] ) );
		}
	}
	foreach ( $dirs as $d ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
			$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
		}
		@rmdir( $d );
	}
	foreach ( array( $p . 'pqbg_sales', $p . 'pqbg_codes' ) as $table ) {
		if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifiers.
			$wpdb->query( "ALTER TABLE $table AUTO_INCREMENT = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifiers.
		}
	}
	wp_cache_flush();

	// Verify with a fresh look: nothing identified may remain.
	echo "\nVERIFY\n";
	$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE ID > %d AND ( user_login LIKE %s OR user_login LIKE %s )", $s['user'], $wpdb->esc_like( 'pqbg_manual_' ) . '%', $wpdb->esc_like( 'pqbg_guide_' ) . '%' ) )
		+ ( array() === $products ? 0 : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->posts . ' WHERE ID IN (' . implode( ',', $products ) . ')' ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
		+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id > %d AND name LIKE %s", $s['term'], '%' . $wpdb->esc_like( '(sample)' ) ) )
		+ (int) $wpdb->get_var( "SELECT COUNT(*) $cat_q" ) + (int) $wpdb->get_var( "SELECT COUNT(*) $prod_q" ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}pqbg_sales WHERE product_id > %d OR variation_id > %d", $s['post'], $s['post'] ) )
		+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}pqbg_codes WHERE product_id > %d OR parent_id > %d", $s['post'], $s['post'] ) )
		+ count( $find_jobs() )
		+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}actionscheduler_logs l LEFT JOIN {$p}actionscheduler_actions a ON a.action_id = l.action_id WHERE l.log_id > %d AND a.action_id IS NULL", $s['log'] ) );
	$opts = array();
	foreach ( (array) $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE '%pqbg%'", ARRAY_A ) as $row ) {
		$opts[ $row['option_name'] ] = array( $row['option_value'], $row['autoload'] );
	}
	ksort( $opts );
	$want = $s['options'];
	ksort( $want );
	$ok = 0 === $left && $opts === $want;
	echo 'identified rows left: ' . $left . '; plugin options byte-identical to the snapshot: ' . ( $opts === $want ? 'yes' : 'NO' ) . "\n";
	if ( $ok ) {
		unlink( $file );
		echo "Repaired; the snapshot is deleted. The next build can start.\n";
		return 0;
	}
	echo "NOT fully repaired; the snapshot is kept. Run the dry run again and inspect.\n";
	return 1;
}
