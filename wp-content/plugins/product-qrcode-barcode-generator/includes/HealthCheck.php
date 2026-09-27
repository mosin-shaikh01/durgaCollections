<?php
/**
 * Data integrity health check (Phase 11, decisions D1–D5). Read-only: every query
 * is a SELECT, nothing is repaired or cached. Shown on QR & Barcodes → Settings →
 * Health check (HealthCheckAdmin) and summarised on the Dashboard for
 * administrators.
 *
 * Checks (severity):
 *   schema            error    stored schema version differs from the code, or a table is missing
 *   negative_stock    error    negative stock on an item with an active code, or on its stock holder
 *   stock_after_null  warning  completed sales without the stock_after snapshot (a Phase 7 crash window)
 *   stale_pending     warning  pending sales older than STALE_PENDING seconds (a process that died;
 *                              the next sale of the same stock holder marks them failed, see
 *                              SaleRepository::recover_stale())
 *   code_items        error    active codes on items that are missing, not products, or ineligible
 *   active_codes      error    an item with more than one active code, or a code row that breaks the
 *                              active_product_id invariant (should be impossible)
 *   cost_meta         warning  cost meta that CostPrice::set() would never write (CostPrice::invalid_values())
 *   code_items_trash  info     active codes on trashed items (kept active by the Phase 5 rule)
 *   sales_no_item     info     sales whose product or variation no longer exists (allowed: sales keep snapshots)
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only data checks.
 */
final class HealthCheck {

	const ERROR   = 'error';
	const WARNING = 'warning';
	const INFO    = 'info';

	/** A pending sale older than this cannot be running: the lock wait is 5 s, a request at most minutes. */
	const STALE_PENDING = 900;

	/** Rows listed per check (the count is always complete). */
	const LIST_LIMIT = 50;

	/** Rows listed for the information checks. */
	const INFO_LIMIT = 10;

	/** Whether the plugin tables exist, checked once per run(). */
	private static bool $tables = false;

	/**
	 * Runs the checks.
	 *
	 * @param bool     $with_info Also run the information checks (the Dashboard leaves them out).
	 * @param int|null $now       Unix time "now" (tests).
	 * @return array<string, array{severity: string, count: int, rows: array<int, array<string, mixed>>}>
	 */
	public static function run( bool $with_info = true, ?int $now = null ): array {
		$now          = null === $now ? time() : $now;
		self::$tables = self::tables_present();
		$items        = self::code_items();

		$out = array(
			'schema'           => self::schema(),
			'negative_stock'   => self::negative_stock(),
			'stock_after_null' => self::stock_after_null(),
			'stale_pending'    => self::stale_pending( $now ),
			'code_items'       => $items['problems'],
			'active_codes'     => self::active_codes(),
			'cost_meta'        => self::cost_meta(),
		);

		if ( $with_info ) {
			$out['code_items_trash'] = $items['trash'];
			$out['sales_no_item']    = self::sales_no_item();
		}

		return $out;
	}

	/**
	 * Whether both plugin tables can be read: two SELECTs that read no row, errors suppressed
	 * (cheaper than Schema::tables_exist()'s SHOW TABLES, which took about 10 ms here).
	 */
	private static function tables_present(): bool {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		$ok       = true;

		foreach ( array( Schema::codes_table(), Schema::sales_table() ) as $table ) {
			$wpdb->get_var( 'SELECT 1 FROM ' . $table . ' LIMIT 0' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed identifier.
			$ok = $ok && '' === $wpdb->last_error;
		}

		$wpdb->suppress_errors( $suppress );

		return $ok;
	}

	/**
	 * Number of findings that need attention (errors and warnings).
	 *
	 * @param array<string, array{severity: string, count: int}> $results From run().
	 */
	public static function problems( array $results ): int {
		$n = 0;

		foreach ( $results as $check ) {
			if ( self::INFO !== $check['severity'] ) {
				$n += (int) $check['count'];
			}
		}

		return $n;
	}

	/**
	 * A result.
	 *
	 * @param string                           $severity Severity.
	 * @param int                              $count    Complete count.
	 * @param array<int, array<string, mixed>> $rows     Listed rows.
	 * @return array{severity: string, count: int, rows: array<int, array<string, mixed>>}
	 */
	private static function result( string $severity, int $count, array $rows ): array {
		return array(
			'severity' => $severity,
			'count'    => $count,
			'rows'     => array_values( $rows ),
		);
	}

	/**
	 * Schema version and tables.
	 */
	private static function schema(): array {
		$rows = array();

		if ( ! self::$tables ) {
			$rows[] = array( 'reason' => 'tables_missing' );
		}

		if ( Install::stored_version() !== Install::DB_VERSION ) {
			$rows[] = array(
				'reason' => 'version',
				'stored' => Install::stored_version(),
				'code'   => Install::DB_VERSION,
			);
		}

		return self::result( self::ERROR, count( $rows ), $rows );
	}

	/**
	 * Negative stock on items with an active code or on their parent (parent-level stock).
	 */
	private static function negative_stock(): array {
		global $wpdb;

		if ( ! self::$tables ) {
			return self::result( self::ERROR, 0, array() );
		}

		$codes = Schema::codes_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifiers; no input.
		$rows = (array) $wpdb->get_results( "SELECT pm.post_id AS id, pm.meta_value AS stock FROM {$wpdb->postmeta} pm JOIN {$wpdb->postmeta} ms ON ms.post_id = pm.post_id AND ms.meta_key = '_manage_stock' AND ms.meta_value = 'yes' WHERE pm.meta_key = '_stock' AND pm.meta_value <> '' AND CAST(pm.meta_value AS DECIMAL(20,6)) < 0 AND pm.post_id IN ( SELECT active_product_id FROM {$codes} WHERE active_product_id IS NOT NULL UNION SELECT parent_id FROM {$codes} WHERE active_product_id IS NOT NULL AND parent_id > 0 ) ORDER BY pm.post_id", ARRAY_A );

		return self::result(
			self::ERROR,
			count( $rows ),
			array_map(
				static fn( $r ) => array(
					'item'  => (int) $r['id'],
					'stock' => (string) $r['stock'],
				),
				array_slice( $rows, 0, self::LIST_LIMIT )
			)
		);
	}

	/**
	 * Completed sales without stock_after.
	 */
	private static function stock_after_null(): array {
		// Without the index: status_created matches nearly every row here (completed), and a row lookup
		// per match took 200–600 ms at 50,000 sales; one sequential scan took about 50 ms.
		return self::sales_where( self::WARNING, "status = 'completed' AND stock_after IS NULL", array(), ' IGNORE INDEX (status_created)' );
	}

	/**
	 * Pending sales older than STALE_PENDING.
	 *
	 * @param int $now Unix time.
	 */
	private static function stale_pending( int $now ): array {
		return self::sales_where( self::WARNING, "status = 'pending' AND created_at_gmt < %s", array( gmdate( 'Y-m-d H:i:s', $now - self::STALE_PENDING ) ) );
	}

	/**
	 * Sales rows matching a condition: the count and the newest LIST_LIMIT.
	 *
	 * @param string   $severity Severity.
	 * @param string   $where    Fixed condition with placeholders.
	 * @param string[] $args     Placeholder values.
	 * @param string   $hint     Fixed index hint after the table name, or ''.
	 */
	private static function sales_where( string $severity, string $where, array $args, string $hint = '' ): array {
		global $wpdb;

		if ( ! self::$tables ) {
			return self::result( $severity, 0, array() );
		}

		$sales = Schema::sales_table();
		$count = "SELECT COUNT(*) FROM {$sales}{$hint} WHERE {$where}";
		$list  = "SELECT id, created_at_gmt, product_id, variation_id, product_name, stock_holder_id FROM {$sales}{$hint} WHERE {$where} ORDER BY id DESC LIMIT " . (int) self::LIST_LIMIT;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- fixed identifiers and conditions; values prepared.
		$n    = (int) $wpdb->get_var( array() === $args ? $count : $wpdb->prepare( $count, $args ) );
		$rows = 0 === $n ? array() : (array) $wpdb->get_results( array() === $args ? $list : $wpdb->prepare( $list, $args ), ARRAY_A );
		// phpcs:enable

		return self::result(
			$severity,
			$n,
			array_map(
				static fn( $r ) => array(
					'sale'    => (int) $r['id'],
					'created' => (string) $r['created_at_gmt'],
					'item'    => (int) $r['variation_id'] > 0 ? (int) $r['variation_id'] : (int) $r['product_id'],
					'name'    => (string) $r['product_name'],
					'holder'  => (int) $r['stock_holder_id'],
				),
				$rows
			)
		);
	}

	/**
	 * Active codes whose item is missing, not a product, or ineligible (error), and
	 * those on trashed items (information). Same rules as ProductCodeService::eligibility(),
	 * in SQL: a simple product (no product_type term counts as simple, as in WooCommerce), or a
	 * variation whose parent is a variable product; never an auto-draft.
	 *
	 * @return array{problems: array, trash: array}
	 */
	private static function code_items(): array {
		global $wpdb;

		if ( ! self::$tables ) {
			return array(
				'problems' => self::result( self::ERROR, 0, array() ),
				'trash'    => self::result( self::INFO, 0, array() ),
			);
		}

		$codes = Schema::codes_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifiers; no input.
		$rows = (array) $wpdb->get_results( "SELECT c.id, c.code, c.product_id, p.ID AS pid, p.post_type, p.post_status, pp.ID AS ppid, pp.post_type AS parent_post_type, pp.post_status AS parent_status FROM {$codes} c LEFT JOIN {$wpdb->posts} p ON p.ID = c.product_id LEFT JOIN {$wpdb->posts} pp ON pp.ID = p.post_parent AND p.post_type = 'product_variation' WHERE c.active_product_id IS NOT NULL ORDER BY c.id", ARRAY_A );

		// The product types of those products and parents, one grouped query per 1,000 IDs
		// (a subquery per code row cost about 25 µs a row).
		$ids   = array_values( array_unique( array_filter( array_map( 'intval', array_merge( array_column( array_filter( $rows, static fn( $r ) => 'product' === $r['post_type'] ), 'pid' ), array_column( $rows, 'ppid' ) ) ) ) ) );
		$types = array();

		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- fixed identifiers; integer IDs.
			foreach ( (array) $wpdb->get_results( "SELECT tr.object_id AS id, MIN(t.slug) AS slug FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type' JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id IN (" . implode( ',', $chunk ) . ') GROUP BY tr.object_id', ARRAY_A ) as $t ) {
				$types[ (int) $t['id'] ] = (string) $t['slug'];
			}
		}

		foreach ( $rows as $i => $r ) {
			$rows[ $i ]['type']        = null === $r['pid'] ? null : ( $types[ (int) $r['pid'] ] ?? null );
			$rows[ $i ]['parent_type'] = null === $r['ppid'] ? null : ( $types[ (int) $r['ppid'] ] ?? null );
		}

		$problems = array();
		$trash    = array();

		foreach ( $rows as $r ) {
			$reason = '';

			if ( null === $r['pid'] ) {
				$reason = 'missing';
			} elseif ( 'product' === $r['post_type'] ) {
				$reason = 'simple' === ( $r['type'] ?? 'simple' ) ? '' : 'type';
			} elseif ( 'product_variation' === $r['post_type'] ) {
				$reason = null !== $r['ppid'] && 'product' === $r['parent_post_type'] && 'variable' === $r['parent_type'] ? '' : 'parent';
			} else {
				$reason = 'not_product';
			}

			if ( '' === $reason && ( 'auto-draft' === $r['post_status'] || 'auto-draft' === $r['parent_status'] ) ) {
				$reason = 'auto_draft';
			}

			$row = array(
				'code'   => (string) $r['code'],
				'item'   => (int) $r['product_id'],
				'reason' => $reason,
			);

			if ( '' !== $reason ) {
				$problems[] = $row;
			} elseif ( 'trash' === $r['post_status'] || 'trash' === $r['parent_status'] ) {
				$trash[] = $row;
			}
		}

		return array(
			'problems' => self::result( self::ERROR, count( $problems ), array_slice( $problems, 0, self::LIST_LIMIT ) ),
			'trash'    => self::result( self::INFO, count( $trash ), array_slice( $trash, 0, self::INFO_LIMIT ) ),
		);
	}

	/**
	 * Items with more than one active code, and code rows breaking the invariant.
	 */
	private static function active_codes(): array {
		global $wpdb;

		if ( ! self::$tables ) {
			return self::result( self::ERROR, 0, array() );
		}

		$codes = Schema::codes_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifiers; no input.
		$dupes = (array) $wpdb->get_results( "SELECT product_id, COUNT(*) AS n FROM {$codes} WHERE status = 'active' GROUP BY product_id HAVING COUNT(*) > 1 ORDER BY product_id", ARRAY_A );
		$bad   = (array) $wpdb->get_results( "SELECT id, code, product_id, status, active_product_id FROM {$codes} WHERE ( status = 'active' AND ( active_product_id IS NULL OR active_product_id <> product_id ) ) OR ( status <> 'active' AND active_product_id IS NOT NULL ) OR status NOT IN ( 'active', 'retired' ) ORDER BY id", ARRAY_A );
		// phpcs:enable

		$rows = array();

		foreach ( $dupes as $r ) {
			$rows[] = array(
				'item'   => (int) $r['product_id'],
				'reason' => 'duplicate',
				'n'      => (int) $r['n'],
			);
		}

		foreach ( $bad as $r ) {
			$rows[] = array(
				'item'   => (int) $r['product_id'],
				'reason' => 'invariant',
				'code'   => (string) $r['code'],
				'status' => (string) $r['status'],
			);
		}

		return self::result( self::ERROR, count( $rows ), array_slice( $rows, 0, self::LIST_LIMIT ) );
	}

	/**
	 * Cost meta CostPrice would never write.
	 */
	private static function cost_meta(): array {
		$rows = CostPrice::invalid_values();

		return self::result(
			self::WARNING,
			count( $rows ),
			array_map(
				static fn( $r ) => array(
					'item'   => $r['post_id'],
					'reason' => $r['reason'],
				),
				array_slice( $rows, 0, self::LIST_LIMIT )
			)
		);
	}

	/**
	 * Sales whose item no longer exists (information).
	 */
	private static function sales_no_item(): array {
		global $wpdb;

		if ( ! self::$tables ) {
			return self::result( self::INFO, 0, array() );
		}

		$sales = Schema::sales_table();
		$join  = "FROM {$sales} s LEFT JOIN {$wpdb->posts} p ON p.ID = IF( s.variation_id > 0, s.variation_id, s.product_id ) WHERE p.ID IS NULL";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- fixed identifiers; no input.
		$n    = (int) $wpdb->get_var( "SELECT COUNT(*) {$join}" );
		$rows = 0 === $n ? array() : (array) $wpdb->get_results( "SELECT s.id, s.created_at_gmt, s.product_id, s.variation_id, s.product_name, s.stock_holder_id {$join} ORDER BY s.id DESC LIMIT " . (int) self::INFO_LIMIT, ARRAY_A );
		// phpcs:enable

		return self::result(
			self::INFO,
			$n,
			array_map(
				static fn( $r ) => array(
					'sale'    => (int) $r['id'],
					'created' => (string) $r['created_at_gmt'],
					'item'    => (int) $r['variation_id'] > 0 ? (int) $r['variation_id'] : (int) $r['product_id'],
					'name'    => (string) $r['product_name'],
					'holder'  => (int) $r['stock_holder_id'],
				),
				$rows
			)
		);
	}
}
