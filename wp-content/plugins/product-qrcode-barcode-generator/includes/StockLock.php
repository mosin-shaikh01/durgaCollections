<?php
/**
 * Per-stock-holder lock for sales, undo and void (MariaDB/MySQL GET_LOCK).
 *
 * The lock belongs to the database connection, not to a transaction: taking
 * or releasing it never commits anything, and the server releases it when the
 * connection drops (a crashed PHP process cannot leave it held).
 *
 * Lock names are server-wide, so they carry a hash of the database name and
 * table prefix: two sites on one server never share a lock.
 *
 * Only this plugin takes the lock. WooCommerce checkout does not, which is the
 * documented online-checkout boundary (see SaleService).
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * GET_LOCK wrapper.
 */
final class StockLock {

	/** Seconds to wait for another sale of the same stock holder before giving up ("busy"). */
	const TIMEOUT = 5;

	/**
	 * Lock name for a stock holder (at most 64 characters).
	 *
	 * @param int $holder_id Stock holder ID.
	 */
	public static function name( int $holder_id ): string {
		global $wpdb;

		return 'pqbg:' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 12 ) . ':stock:' . $holder_id;
	}

	/**
	 * Takes the lock, waiting up to $timeout seconds.
	 *
	 * @param int $holder_id Stock holder ID.
	 * @param int $timeout   Seconds.
	 */
	public static function acquire( int $holder_id, int $timeout = self::TIMEOUT ): bool {
		global $wpdb;

		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::name( $holder_id ), max( 0, $timeout ) ) );
	}

	/**
	 * Releases the lock if this connection holds it.
	 *
	 * @param int $holder_id Stock holder ID.
	 */
	public static function release( int $holder_id ): void {
		global $wpdb;

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::name( $holder_id ) ) );
	}

	/**
	 * Whether no connection holds the lock (tests and diagnostics).
	 *
	 * @param int $holder_id Stock holder ID.
	 */
	public static function is_free( int $holder_id ): bool {
		global $wpdb;

		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_FREE_LOCK(%s)', self::name( $holder_id ) ) );
	}
}
