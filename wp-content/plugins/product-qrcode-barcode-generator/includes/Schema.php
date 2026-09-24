<?php
/**
 * Database table definitions.
 *
 * The CREATE statements always describe the CURRENT schema and are applied
 * with dbDelta(), which only adds/changes columns and indexes and never drops
 * anything. dbDelta formatting rules apply: one column per line, two spaces
 * after PRIMARY KEY, KEY instead of INDEX, column types spelled the way
 * MariaDB/MySQL report them (e.g. bigint(20) unsigned) so re-runs are no-ops.
 *
 * Table names come only from $wpdb->prefix plus fixed suffixes; nothing
 * user-controlled ever reaches an identifier.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the pqbg_codes and pqbg_sales table definitions.
 */
final class Schema {

	/**
	 * Product codes. One row per code ever issued; rows are retired, never deleted.
	 *
	 * Invariant (enforced by CodeRepository, the UNIQUE index and, where the
	 * server supports it, a CHECK constraint):
	 *   status = 'active' AND kind = 'product'  =>  active_product_id = product_id
	 *   otherwise                               =>  active_product_id IS NULL
	 * InnoDB UNIQUE indexes allow many NULLs, so UNIQUE(active_product_id)
	 * allows at most one active product code per purchasable item.
	 */
	public static function codes_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pqbg_codes';
	}

	/**
	 * Sales ledger (future Mark-as-Sold). product_id/variation_id follow the
	 * WooCommerce order-item convention: product_id is the simple product or the
	 * variation's parent, variation_id is the variation (0 for simple products).
	 */
	public static function sales_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pqbg_sales';
	}

	/**
	 * Name of the optional CHECK constraint on pqbg_codes (constraint names are schema-wide).
	 */
	public static function active_check_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'pqbg_codes_active_chk';
	}

	/**
	 * CREATE TABLE statements for dbDelta().
	 *
	 * @return string[]
	 */
	public static function statements(): array {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();
		$codes   = self::codes_table();
		$sales   = self::sales_table();

		return array(
			"CREATE TABLE {$codes} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
code varchar(32) NOT NULL,
kind varchar(20) NOT NULL DEFAULT 'product',
product_id bigint(20) unsigned NOT NULL,
parent_id bigint(20) unsigned NOT NULL DEFAULT '0',
active_product_id bigint(20) unsigned NULL DEFAULT NULL,
status varchar(20) NOT NULL DEFAULT 'active',
created_at_gmt datetime NOT NULL,
created_by bigint(20) unsigned NOT NULL DEFAULT '0',
retired_at_gmt datetime NULL DEFAULT NULL,
retired_by bigint(20) unsigned NULL DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY code (code),
UNIQUE KEY active_product_id (active_product_id),
KEY product_status (product_id,status),
KEY parent_id (parent_id),
KEY status (status)
) {$collate};",
			"CREATE TABLE {$sales} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
request_id char(36) NOT NULL,
code_id bigint(20) unsigned NULL DEFAULT NULL,
unit_id bigint(20) unsigned NULL DEFAULT NULL,
product_id bigint(20) unsigned NOT NULL,
variation_id bigint(20) unsigned NOT NULL DEFAULT '0',
order_id bigint(20) unsigned NULL DEFAULT NULL,
seller_id bigint(20) unsigned NOT NULL,
quantity int(10) unsigned NOT NULL DEFAULT '1',
unit_price decimal(26,8) NOT NULL,
regular_price decimal(26,8) NULL DEFAULT NULL,
line_total decimal(26,8) NOT NULL,
currency char(3) NOT NULL,
product_name text NOT NULL,
sku varchar(100) NULL DEFAULT NULL,
attributes_json longtext NULL,
stock_before int(11) NULL DEFAULT NULL,
stock_after int(11) NULL DEFAULT NULL,
source varchar(20) NOT NULL DEFAULT 'scan',
status varchar(20) NOT NULL DEFAULT 'completed',
void_reason text NULL,
voided_by bigint(20) unsigned NULL DEFAULT NULL,
voided_at_gmt datetime NULL DEFAULT NULL,
note text NULL,
created_at_gmt datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY request_id (request_id),
KEY code_id (code_id),
KEY product_variation (product_id,variation_id),
KEY seller_created (seller_id,created_at_gmt),
KEY status_created (status,created_at_gmt),
KEY created_at_gmt (created_at_gmt),
KEY order_id (order_id)
) {$collate};",
		);
	}

	/**
	 * Creates missing tables or adds missing columns/indexes. Never drops anything.
	 *
	 * @return string[] dbDelta() change log (empty when the schema was already current).
	 */
	public static function create_or_update(): array {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		return dbDelta( self::statements() );
	}

	/**
	 * Whether both plugin tables exist.
	 */
	public static function tables_exist(): bool {
		global $wpdb;

		foreach ( array( self::codes_table(), self::sales_table() ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the active-code CHECK constraint is present on pqbg_codes.
	 */
	public static function has_active_check(): bool {
		global $wpdb;

		// SHOW CREATE TABLE is portable across MariaDB/MySQL, unlike information_schema.CHECK_CONSTRAINTS.
		$row = $wpdb->get_row( 'SHOW CREATE TABLE ' . self::codes_table(), ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed identifier.

		return is_array( $row ) && isset( $row[1] ) && false !== strpos( $row[1], 'CONSTRAINT `' . self::active_check_name() . '` CHECK' );
	}

	/**
	 * Adds the active-code CHECK constraint when missing (defence in depth only).
	 *
	 * Servers that reject CHECK fail softly; servers that parse but ignore it
	 * (MySQL < 8.0.16) will not report it back, so this returns false there.
	 * CodeRepository enforces the same invariant regardless.
	 *
	 * @return bool Whether the constraint is present afterwards.
	 */
	public static function ensure_active_check(): bool {
		global $wpdb;

		if ( self::has_active_check() ) {
			return true;
		}

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed identifiers and literals only.
		$wpdb->query(
			'ALTER TABLE ' . self::codes_table() . ' ADD CONSTRAINT ' . self::active_check_name() . " CHECK (
				(status = 'active' AND kind = 'product' AND active_product_id IS NOT NULL AND active_product_id = product_id)
				OR (active_product_id IS NULL AND NOT (status = 'active' AND kind = 'product'))
			)"
		);
		$wpdb->suppress_errors( $suppress );

		return self::has_active_check();
	}

	/**
	 * Drops both plugin tables. Only called from uninstall.php when explicitly opted in.
	 */
	public static function drop_tables(): void {
		global $wpdb;

		foreach ( array( self::codes_table(), self::sales_table() ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier.
		}
	}
}
