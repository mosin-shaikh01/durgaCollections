<?php
/**
 * Data access for pqbg_sales, plus the two SQL statements that change a
 * WooCommerce stock level together with a sale row.
 *
 * Row statuses:
 *   pending    journal entry written before the stock changes; the stock has NOT changed
 *   completed  the stock was decremented and the sale stands
 *   voided     a completed sale that was undone or voided (stock restored unless voided without restock)
 *   failed     no sale: never decremented, or decremented and compensated (failure_code says why)
 *
 * pending → completed and completed → failed/voided happen in the SAME statement
 * that changes the stock (a multi-table UPDATE of postmeta and this table, see
 * stock_sql()), so a crash can never leave a stock change without its record or
 * a record without its stock change. A pending row found while its stock holder
 * is locked belongs to a process that died before its stock statement ran, so it
 * is safe to mark it failed ("interrupted"); see recover_stale().
 *
 * Rows are never deleted. No capability checks here; SaleService checks them.
 * No transactions: every write is one autocommitted statement.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes sale rows.
 */
final class SaleRepository {

	const STATUS_PENDING   = 'pending';
	const STATUS_COMPLETED = 'completed';
	const STATUS_VOIDED    = 'voided';
	const STATUS_FAILED    = 'failed';

	/** An online order took the stock between our read and our decrement; compensated. */
	const FAILURE_SOLD_ONLINE = 'sold_online';

	/** An error or exception after the journal entry; compensated if the stock had changed. */
	const FAILURE_ERROR = 'error';

	/** The process died before its stock statement ran; the stock never changed. */
	const FAILURE_INTERRUPTED = 'interrupted';

	/**
	 * Inserts the journal row for a sale that is about to change the stock.
	 *
	 * @param array<string, mixed> $data Column values (snapshots); status and created_at_gmt are set here.
	 * @return int|WP_Error Row ID, or pqbg_duplicate_request when the request ID was already used.
	 */
	public static function insert_pending( array $data ) {
		global $wpdb;

		$data['status']         = self::STATUS_PENDING;
		$data['created_at_gmt'] = current_time( 'mysql', true );

		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert( Schema::sales_table(), $data, self::formats( $data ) );
		$wpdb->suppress_errors( $suppress );

		if ( false === $inserted ) {
			return null !== self::find_by_request_id( (string) $data['request_id'] )
				? new WP_Error( 'pqbg_duplicate_request', __( 'This sale was already submitted.', 'product-qrcode-barcode-generator' ) )
				: new WP_Error( 'pqbg_sale_write_failed', __( 'The sale could not be recorded.', 'product-qrcode-barcode-generator' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * A sale row by ID, read from the database (never cached).
	 *
	 * @param int $id Row ID.
	 * @return array<string, string>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$table = Schema::sales_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier.

		return is_array( $row ) ? $row : null;
	}

	/**
	 * A sale row by its request ID.
	 *
	 * @param string $request_id Request ID.
	 * @return array<string, string>|null
	 */
	public static function find_by_request_id( string $request_id ): ?array {
		global $wpdb;

		if ( '' === $request_id ) {
			return null;
		}

		$table = Schema::sales_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE request_id = %s", $request_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier.

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Marks pending rows of a stock holder as failed ("interrupted").
	 *
	 * Only call this while holding the holder's StockLock: every sale of that
	 * holder runs under the same lock, so a pending row seen then belongs to a
	 * process that died before its stock statement ran (MariaDB releases the
	 * lock of a dropped connection). Its stock never changed.
	 *
	 * @param int $holder_id Stock holder ID.
	 * @return int Rows recovered.
	 */
	public static function recover_stale( int $holder_id ): int {
		global $wpdb;

		$table = Schema::sales_table();

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, failure_code = %s WHERE stock_holder_id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier.
				self::STATUS_FAILED,
				self::FAILURE_INTERRUPTED,
				$holder_id,
				self::STATUS_PENDING
			)
		);
	}

	/**
	 * Moves a row from one status to another only if it still has the expected status.
	 *
	 * @param int                  $id    Row ID.
	 * @param string               $from  Expected current status.
	 * @param array<string, mixed> $set   Columns to set (including status).
	 * @return bool Whether the row changed.
	 */
	public static function transition( int $id, string $from, array $set ): bool {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		$updated  = $wpdb->update( Schema::sales_table(), $set, array( 'id' => $id, 'status' => $from ), self::formats( $set ), array( '%d', '%s' ) );
		$wpdb->suppress_errors( $suppress );

		return 1 === $updated;
	}

	/**
	 * Records the stock levels around a completed sale (informational snapshots).
	 *
	 * @param int       $id     Row ID.
	 * @param int|float $before Stock read under the lock before the decrement.
	 * @param int|float $after  Stock read after the decrement.
	 */
	public static function set_stock_after( int $id, $before, $after ): bool {
		global $wpdb;

		$table = Schema::sales_table();

		return false !== $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET stock_before = %d, stock_after = %d WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier.
				(int) $before,
				(int) $after,
				$id
			)
		);
	}

	/**
	 * The stock quantity WooCommerce's atomic stock UPDATE works on (postmeta
	 * _stock of the holder), read straight from the database, bypassing every cache.
	 *
	 * @param int $holder_id Stock holder ID.
	 * @return int|float|null Null when the holder has no _stock row.
	 */
	public static function read_stock( int $holder_id ) {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock' ORDER BY meta_id ASC LIMIT 1", $holder_id ) );

		return null === $value ? null : wc_stock_amount( $value );
	}

	/**
	 * One statement that changes the holder's stock by $delta AND moves the sale
	 * row from $from to the values in $set. If the row no longer has status $from,
	 * the join matches nothing and neither table changes.
	 *
	 * Used as the replacement for WooCommerce's own stock UPDATE (see
	 * SaleService::change_stock()), so WooCommerce still does everything else:
	 * lookup table, product save, stock status, caches and hooks.
	 *
	 * @param int                  $holder_id Stock holder ID.
	 * @param int|float            $delta     Signed change (negative to decrement).
	 * @param int                  $sale_id   Sale row ID.
	 * @param string               $from      Required current status.
	 * @param array<string, mixed> $set       Sale columns to set (status and friends).
	 */
	public static function stock_sql( int $holder_id, $delta, int $sale_id, string $from, array $set ): string {
		global $wpdb;

		$table   = Schema::sales_table();
		$assign  = array( $wpdb->prepare( 'pm.meta_value = pm.meta_value %+f', $delta ) );
		$allowed = array( 'status', 'failure_code', 'voided_by', 'voided_at_gmt', 'void_reason' );

		foreach ( $set as $column => $value ) {
			if ( ! in_array( $column, $allowed, true ) ) {
				continue;
			}

			$assign[] = null === $value ? "s.{$column} = NULL" : $wpdb->prepare( "s.{$column} = " . ( is_int( $value ) ? '%d' : '%s' ), $value );
		}

		return $wpdb->prepare(
			"UPDATE {$wpdb->postmeta} pm, {$table} s SET " . implode( ', ', $assign ) . " WHERE pm.post_id = %d AND pm.meta_key = '_stock' AND s.id = %d AND s.status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- fixed identifiers; values prepared above.
			$holder_id,
			$sale_id,
			$from
		);
	}

	/**
	 * $wpdb formats for a set of sale columns.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return string[]
	 */
	private static function formats( array $data ): array {
		$ints = array( 'code_id', 'unit_id', 'product_id', 'variation_id', 'order_id', 'seller_id', 'quantity', 'stock_before', 'stock_after', 'voided_by', 'stock_holder_id' );

		return array_map( static fn( $column ) => in_array( $column, $ints, true ) ? '%d' : '%s', array_keys( $data ) );
	}
}
