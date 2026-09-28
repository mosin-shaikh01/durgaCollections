<?php
/**
 * Repairs the site after a Phase 12 suite run that was killed before its cleanup (Claude Code or
 * the machine stopped the process). Not a suite; run.php never runs it.
 *
 *   php tests/phase12-repair.php           dry run: lists what differs from the suite's start state
 *   php tests/phase12-repair.php --apply   restores it (then deletes the saved start state)
 *
 * Reads the start state that tests/phase12-themes.php saved in the system temp folder
 * (pqbg-phase12-start.ser; deleted by the suite's own cleanup) and puts back what the suite
 * changes: the active theme and every non-runtime option, the placeholder image files and
 * metadata, .htaccess; removes the test theme folders, the cache plugin and its cache folder,
 * the temporary must-use file, posts/terms/users/codes/sales/sessions created after the start,
 * and the Action Scheduler jobs that reference them.
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
require_once ABSPATH . 'wp-admin/includes/theme.php';

use ProductQrBarcode\Schema;

global $wpdb;

$apply = in_array( '--apply', $argv, true );
$file  = str_replace( '\\', '/', sys_get_temp_dir() ) . '/pqbg-phase12-start.ser';

if ( ! is_file( $file ) ) {
	echo "No saved start state ({$file}): the last Phase 12 run cleaned up after itself. Nothing to do.\n";
	exit( 0 );
}

$start   = unserialize( (string) file_get_contents( $file ), array( 'allowed_classes' => array( 'stdClass' ) ) );
$C       = Schema::codes_table();
$S       = Schema::sales_table();
$uploads = wp_upload_dir()['basedir'];
$runtime = static fn( string $n ): bool => in_array( $n, array( 'cron', 'action_scheduler_migration_status' ), true ) || str_starts_with( $n, '_transient_' ) || str_starts_with( $n, '_site_transient_' ) || str_starts_with( $n, 'action_scheduler_lock_' );
$list    = static fn( string $dir ): array => is_dir( $dir ) ? array_values( array_diff( scandir( $dir ), array( '.', '..' ) ) ) : array();
$rm_tree = static function ( string $dir ) use ( &$rm_tree ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
		$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
	rmdir( $dir );
};
$say = static function ( string $what ) use ( $apply ): void {
	echo ( $apply ? 'REPAIR  ' : 'WOULD   ' ) . $what . "\n";
};

$now = array();
foreach ( $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options}", ARRAY_A ) as $r ) {
	$now[ $r['option_name'] ] = array( $r['option_value'], $r['autoload'] );
}

if ( is_plugin_active( 'wp-fastest-cache/wpFastestCache.php' ) ) {
	$say( 'deactivate WP Fastest Cache' );
	$apply && deactivate_plugins( 'wp-fastest-cache/wpFastestCache.php' );
}
foreach ( array( WP_PLUGIN_DIR . '/wp-fastest-cache' => $start['plugins'], WP_CONTENT_DIR . '/cache' => $start['content'] ) as $dir => $before ) {
	if ( is_dir( $dir ) && ! in_array( basename( $dir ), $before, true ) ) {
		$say( "remove folder {$dir}" );
		$apply && $rm_tree( $dir );
	}
}
if ( get_option( 'stylesheet' ) !== $start['stylesheet'] ) {
	$say( 'switch the theme back to ' . $start['stylesheet'] . ' (now ' . get_option( 'stylesheet' ) . ')' );
	$apply && switch_theme( $start['stylesheet'] );
}
foreach ( $now as $name => $row ) {
	if ( $runtime( $name ) ) {
		continue;
	}
	if ( ! isset( $start['options'][ $name ] ) ) {
		$say( "delete option {$name} (new since the start)" );
		$apply && $wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
	} elseif ( $row !== $start['options'][ $name ] ) {
		$say( "restore option {$name}" );
		$apply && $wpdb->update( $wpdb->options, array( 'option_value' => $start['options'][ $name ][0], 'autoload' => $start['options'][ $name ][1] ), array( 'option_name' => $name ) );
	}
}
foreach ( $start['options'] as $name => $row ) {
	if ( ! $runtime( $name ) && ! isset( $now[ $name ] ) ) {
		$say( "re-create option {$name}" );
		$apply && $wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $row[0], 'autoload' => $row[1] ) );
	}
}
foreach ( glob( $uploads . '/woocommerce-placeholder*' ) ?: array() as $f ) {
	if ( ! isset( $start['ph_files'][ basename( $f ) ] ) ) {
		$say( 'remove ' . basename( $f ) );
		$apply && unlink( $f );
	}
}
foreach ( $start['ph_files'] as $name => $bytes ) {
	if ( ! is_file( "$uploads/$name" ) || file_get_contents( "$uploads/$name" ) !== $bytes ) {
		$say( "restore {$name}" );
		$apply && file_put_contents( "$uploads/$name", $bytes );
	}
}
foreach ( $start['ph_meta'] as $m ) {
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", $m['meta_id'] ) ) !== $m['meta_value'] ) {
		$say( "restore placeholder meta {$m['meta_key']}" );
		$apply && $wpdb->update( $wpdb->postmeta, array( 'meta_value' => $m['meta_value'] ), array( 'meta_id' => $m['meta_id'] ) );
	}
}
foreach ( array_diff( $list( get_theme_root() ), $start['themes'] ) as $t ) {
	$say( "remove theme folder {$t}" );
	$apply && $rm_tree( get_theme_root() . '/' . $t );
}
$mu = WPMU_PLUGIN_DIR . '/pqbg-phase12-plugin-off.php';
if ( is_file( $mu ) ) {
	$say( 'remove the temporary must-use file' );
	$apply && unlink( $mu );
}
if ( is_dir( WPMU_PLUGIN_DIR ) && ! in_array( 'mu-plugins', $start['content'], true ) && array() === array_diff( $list( WPMU_PLUGIN_DIR ), array( 'pqbg-phase12-plugin-off.php' ) ) ) {
	$say( 'remove the empty mu-plugins folder' );
	$apply && rmdir( WPMU_PLUGIN_DIR );
}
if ( (string) file_get_contents( ABSPATH . '.htaccess' ) !== $start['htaccess'] ) {
	$say( 'restore .htaccess' );
	$apply && file_put_contents( ABSPATH . '.htaccess', $start['htaccess'] );
}
$n_s = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $S WHERE id > %d", $start['sales'] ) );
$n_c = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $C WHERE id > %d", $start['codes'] ) );
$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d ORDER BY ID DESC", $start['post_max'] ) ) );
$tms = $wpdb->get_results( $wpdb->prepare( "SELECT term_id, taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id > %d", $start['term_max'] ) );
$usr = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE 'p12t\\_%'" ) );
if ( $n_s + $n_c + count( $ids ) + count( $tms ) + count( $usr ) > 0 ) {
	$say( "delete {$n_s} sale row(s), {$n_c} code row(s), " . count( $ids ) . ' post(s) (' . implode( ',', array_slice( $ids, 0, 20 ) ) . '), ' . count( $tms ) . ' term(s), ' . count( $usr ) . ' user(s)' );
	if ( $apply ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM $S WHERE id > %d", $start['sales'] ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $C WHERE id > %d", $start['codes'] ) );
		foreach ( $ids as $pid ) {
			'attachment' === get_post_type( $pid ) ? wp_delete_attachment( $pid, true ) : wp_delete_post( $pid, true );
		}
		foreach ( $tms as $t ) {
			wp_delete_term( (int) $t->term_id, $t->taxonomy );
		}
		foreach ( $usr as $uid ) {
			wp_delete_user( $uid );
		}
	}
}
$apply && $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_sessions WHERE session_id > %d", $start['sessions'] ) );

if ( $apply ) {
	echo 'REPAIR  removed ' . pqbg_test_as_cleanup( $start['as_mark'] ) . " Action Scheduler job(s) the run caused\n";
	wp_cache_flush();
	unlink( $file );
	echo "Done. Run it again (dry run) to confirm nothing is left.\n";
} else {
	echo "Dry run only. Re-run with --apply to repair.\n";
}
