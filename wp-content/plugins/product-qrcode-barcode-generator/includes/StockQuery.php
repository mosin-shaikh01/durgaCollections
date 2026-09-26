<?php
/**
 * Read-only stock, dead-stock and missing-code queries for the in-store reports
 * (Phase 9B). Current state, not snapshots.
 *
 * Stock holders: every product or variation that manages its own stock
 * (_manage_stock = yes), in the statuses publish, private, draft and pending:
 *   - a simple product;
 *   - a variation with its own stock;
 *   - a variable product with product-level stock, shared by its variations that do
 *     not manage their own (decision D12: valued at their price only when all of
 *     them have the same price, and at cost only when all have the same known
 *     effective cost; otherwise left out of the value totals and disclosed).
 * These are the same "stock holders" that sales change (SaleService).
 *
 * Thresholds are WooCommerce's: out of stock at or below "Out of stock threshold"
 * (woocommerce_notify_no_stock_amount); low at or below the item's low stock amount
 * (its own, else its parent's, else "Low stock threshold"), as wc_get_low_stock_amount().
 * Negative stock (backorders) is valued at 0 and flagged.
 *
 * Cost prices are read only through CostPrice::get_many(), and only when asked for
 * ($costs = true, i.e. for pqbg_view_costs).
 *
 * Online orders (decision D4, dead stock only): the latest date of a WooCommerce
 * order line for each item, from the order line items joined to the orders table
 * that OrderUtil names (HPOS or posts), for orders that are processing, completed
 * or on hold. Read-only; the plugin never writes orders.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Stock and dead-stock data.
 */
final class StockQuery {

	const STATUSES = array( 'publish', 'private', 'draft', 'pending' );

	/** Order statuses whose lines count as an online sale for dead stock (without the wc- prefix). */
	const ONLINE_STATUSES = array( 'processing', 'completed', 'on-hold' );

	const DEAD_WINDOWS = array( 30, 60, 90 );

	/**
	 * Every stock holder with its stock state and values.
	 *
	 * @param bool $costs Include cost values.
	 * @return array<int, array<string, mixed>> id, product_id (the product, for categories), variation_id, kind (simple|variation|shared),
	 *         name, sku, status, created (UTC), stock, low, state (out|low|instock), price (null = unknown or mixed),
	 *         price_note, value (null when not valued), [cost, cost_note, cost_value]
	 */
	public static function holders( bool $costs ): array {
		global $wpdb;

		$statuses = "'" . implode( "','", self::STATUSES ) . "'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core tables; fixed literals.
		$rows = (array) $wpdb->get_results( "SELECT p.ID AS id, p.post_parent AS parent, p.post_type AS type, p.post_status AS status, p.post_title AS title, p.post_date_gmt AS created FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_manage_stock' AND ms.meta_value = 'yes' WHERE p.post_type IN ('product', 'product_variation') AND p.post_status IN ({$statuses}) ORDER BY p.post_title, p.ID", ARRAY_A );

		if ( array() === $rows ) {
			return array();
		}

		$ids     = array_map( static fn( $r ) => (int) $r['id'], $rows );
		$parents = array_values( array_unique( array_filter( array_map( static fn( $r ) => 'product_variation' === $r['type'] ? (int) $r['parent'] : 0, $rows ) ) ) );
		$types   = self::product_types( array_merge( array_filter( array_map( static fn( $r ) => 'product' === $r['type'] ? (int) $r['id'] : 0, $rows ) ), $parents ) );
		$shared  = array_keys( array_filter( $types, static fn( $t ) => 'variable' === $t ) );
		$sharing = self::sharing_variations( array_values( array_intersect( $shared, $ids ) ) );
		$alive_p = self::parent_statuses( $parents );

		$sharers = array_merge( array(), ...array_values( $sharing ) );
		$meta    = self::meta( array_merge( $ids, $parents, $sharers ), array( '_stock', '_price', '_sku', '_low_stock_amount' ) );
		$cost    = $costs ? CostPrice::get_many( array_merge( $ids, $parents, $sharers ) ) : array();
		$value   = static fn( int $id, string $key ): string => (string) ( $meta[ $id ][ $key ] ?? '' );
		$cost_of = static fn( int $id ): string => $cost[ $id ] ?? '';

		$global_low = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
		$out_at     = (int) get_option( 'woocommerce_notify_no_stock_amount', 0 );
		$out        = array();

		foreach ( $rows as $row ) {
			$id   = (int) $row['id'];
			$is_v = 'product_variation' === $row['type'];

			if ( $is_v && ( ! isset( $alive_p[ (int) $row['parent'] ] ) || 'variable' !== ( $types[ (int) $row['parent'] ] ?? '' ) ) ) {
				continue; // Parent trashed/missing or no longer variable.
			}

			if ( ! $is_v && ! in_array( $types[ $id ] ?? '', array( 'simple', 'variable' ), true ) ) {
				continue; // Grouped, external or another type.
			}

			$kind  = $is_v ? 'variation' : ( 'variable' === $types[ $id ] ? 'shared' : 'simple' );
			$stock = (int) wc_stock_amount( $value( $id, '_stock' ) );
			$low   = self::low_amount( $value( $id, '_low_stock_amount' ), $is_v ? $value( (int) $row['parent'], '_low_stock_amount' ) : '', $global_low );
			$item  = array(
				'id'           => $id,
				'product_id'   => $is_v ? (int) $row['parent'] : $id,
				'variation_id' => $is_v ? $id : 0,
				'kind'         => $kind,
				'name'         => html_entity_decode( (string) $row['title'], ENT_QUOTES, 'UTF-8' ),
				'sku'          => $value( $id, '_sku' ),
				'status'       => (string) $row['status'],
				'created'      => (string) $row['created'],
				'stock'        => $stock,
				'low'          => $low,
				'state'        => $stock <= $out_at ? 'out' : ( $stock <= $low ? 'low' : 'instock' ),
				'negative'     => $stock < 0,
			);

			if ( 'shared' === $kind ) {
				list( $price, $note ) = self::common( array_map( static fn( $v ) => self::price( $value( $v, '_price' ) ), $sharing[ $id ] ?? array() ) );
			} else {
				$price = self::price( $value( $id, '_price' ) );
				$note  = null === $price ? 'unknown' : '';
			}

			$item['price']      = $price;
			$item['price_note'] = $note;
			$item['value']      = null === $price ? null : ReportsQuery::money( (string) ( max( 0, $stock ) * (float) $price ) );

			if ( $costs ) {
				if ( 'shared' === $kind ) {
					$default = $cost_of( $id );
					$list    = array_map(
						static function ( int $v ) use ( $default, $cost_of ): ?string {
							$own = $cost_of( $v );
							$use = '' !== $own ? $own : $default;
							return '' === $use ? null : $use;
						},
						$sharing[ $id ] ?? array()
					);

					list( $cost, $c_note ) = array() === $list ? array( '' === $default ? null : $default, '' === $default ? 'unknown' : '' ) : self::common( $list );
				} else {
					$own    = $cost_of( $id );
					$parent = $is_v ? $cost_of( (int) $row['parent'] ) : '';
					$cost   = '' !== $own ? $own : ( '' !== $parent ? $parent : null );
					$c_note = null === $cost ? 'unknown' : '';
				}

				$item['cost']       = $cost;
				$item['cost_note']  = $c_note;
				$item['cost_value'] = null === $cost ? null : ReportsQuery::money( (string) ( max( 0, $stock ) * (float) $cost ) );
			}

			$out[] = $item;
		}

		return $out;
	}

	/**
	 * Totals of a list of holders: units, value at price, (value at cost), and what was left out.
	 *
	 * @param array<int, array<string, mixed>> $holders From holders().
	 * @param bool                             $costs   Include cost totals.
	 * @return array<string, mixed>
	 */
	public static function totals( array $holders, bool $costs ): array {
		$out = array(
			'items'            => count( $holders ),
			'units'            => 0,
			'value'            => '0',
			'unpriced'         => 0,
			'unpriced_units'   => 0,
			'negative'         => 0,
			'low'              => 0,
			'out'              => 0,
		);

		if ( $costs ) {
			$out += array(
				'cost_value'      => '0',
				'uncosted'        => 0,
				'uncosted_units'  => 0,
				'uncosted_value'  => '0',
			);
		}

		foreach ( $holders as $h ) {
			$units         = max( 0, (int) $h['stock'] );
			$out['units'] += $units;
			$out['negative'] += $h['negative'] ? 1 : 0;
			$out['low']      += 'low' === $h['state'] ? 1 : 0;
			$out['out']      += 'out' === $h['state'] ? 1 : 0;

			if ( null === $h['value'] ) {
				$out['unpriced']++;
				$out['unpriced_units'] += $units;
			} else {
				$out['value'] = ReportsQuery::money( (string) ( (float) $out['value'] + (float) $h['value'] ) );
			}

			if ( $costs ) {
				if ( null === $h['cost_value'] ) {
					$out['uncosted']++;
					$out['uncosted_units'] += $units;
					$out['uncosted_value']  = ReportsQuery::money( (string) ( (float) $out['uncosted_value'] + (float) ( $h['value'] ?? 0 ) ) );
				} else {
					$out['cost_value'] = ReportsQuery::money( (string) ( (float) $out['cost_value'] + (float) $h['cost_value'] ) );
				}
			}
		}

		return $out;
	}

	/**
	 * Dead stock: holders with stock > 0 and no completed in-store sale (and, per D4,
	 * no online order line) in the last $days days, or never ($days = 0).
	 * Decision D11: an item created less than $days days ago is not dead yet; it is
	 * counted in "new". For "never", every never-sold item is listed with its age.
	 *
	 * @param array<int, array<string, mixed>> $holders From holders().
	 * @param int                              $days    30, 60, 90 or 0 (never sold).
	 * @param int|null                         $now     Unix time "now" (tests).
	 * @return array{rows: array<int, array<string, mixed>>, new: int}
	 *         rows add last_instore, last_online (UTC or ''), age_days.
	 */
	public static function dead( array $holders, int $days, ?int $now = null ): array {
		$now      = $now ?? time();
		$instore  = ReportsQuery::last_sales();
		$online   = self::online_last_sales();
		$cutoff   = gmdate( 'Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS );
		$rows     = array();
		$new      = 0;
		$by_product = static function ( array $map ): array { // product => latest date of any of its items.
			$out = array();
			foreach ( $map as $key => $date ) {
				$product         = (int) strtok( $key, ':' );
				$out[ $product ] = max( $out[ $product ] ?? '', $date );
			}
			return $out;
		};
		$product_in = $by_product( $instore );
		$product_on = $by_product( $online );
		$latest     = static function ( array $map, array $products, array $h ): string {
			return 'shared' === $h['kind'] ? ( $products[ $h['product_id'] ] ?? '' ) : ( $map[ $h['product_id'] . ':' . $h['variation_id'] ] ?? '' );
		};

		foreach ( $holders as $h ) {
			if ( (int) $h['stock'] <= 0 ) {
				continue;
			}

			$last_in  = $latest( $instore, $product_in, $h );
			$last_on  = $latest( $online, $product_on, $h );
			$last     = max( $last_in, $last_on );
			$age      = '' === $h['created'] || '0000-00-00 00:00:00' === $h['created'] ? null : (int) floor( ( $now - strtotime( $h['created'] . ' UTC' ) ) / DAY_IN_SECONDS );
			$is_dead  = 0 === $days ? '' === $last : ( '' === $last || $last < $cutoff );

			if ( ! $is_dead ) {
				continue;
			}

			if ( $days > 0 && null !== $age && $age < $days ) {
				$new++;
				continue;
			}

			$rows[] = $h + array(
				'last_instore' => $last_in,
				'last_online'  => $last_on,
				'age_days'     => $age,
			);
		}

		return array(
			'rows' => $rows,
			'new'  => $new,
		);
	}

	/**
	 * Latest online order line of each item: "product:variation" => UTC datetime.
	 * Order lines of orders in ONLINE_STATUSES, from the table WooCommerce stores
	 * orders in (HPOS wc_orders or posts). Read-only.
	 *
	 * @return array<string, string>
	 */
	public static function online_last_sales(): array {
		global $wpdb;

		$items    = $wpdb->prefix . 'woocommerce_order_items';
		$meta     = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$statuses = "'wc-" . implode( "','wc-", self::ONLINE_STATUSES ) . "'";

		if ( class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$orders = OrderUtil::get_table_for_orders();
			$join   = "JOIN {$orders} o ON o.id = oi.order_id AND o.type = 'shop_order' AND o.status IN ({$statuses})";
			$date   = 'o.date_created_gmt';
		} else {
			$join = "JOIN {$wpdb->posts} o ON o.ID = oi.order_id AND o.post_type = 'shop_order' AND o.post_status IN ({$statuses})";
			$date = 'o.post_date_gmt';
		}

		$out = array();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifiers and literals.
		foreach ( (array) $wpdb->get_results( "SELECT CAST(pm.meta_value AS UNSIGNED) AS product_id, CAST(COALESCE(vm.meta_value, 0) AS UNSIGNED) AS variation_id, MAX({$date}) AS last FROM {$items} oi {$join} JOIN {$meta} pm ON pm.order_item_id = oi.order_item_id AND pm.meta_key = '_product_id' LEFT JOIN {$meta} vm ON vm.order_item_id = oi.order_item_id AND vm.meta_key = '_variation_id' WHERE oi.order_item_type = 'line_item' GROUP BY product_id, variation_id", ARRAY_A ) as $row ) {
			$key         = (int) $row['product_id'] . ':' . (int) $row['variation_id'];
			$out[ $key ] = max( $out[ $key ] ?? '', (string) $row['last'] );
		}

		return $out;
	}

	/**
	 * Active sellable items without an active code: published simple products and
	 * published variations of published variable products (decision D10).
	 *
	 * @return array<int, array{id: int, product_id: int, name: string, sku: string}>
	 */
	public static function missing_codes(): array {
		global $wpdb;

		$codes = Schema::codes_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core and plugin tables; fixed literals.
		$type_join = static fn( string $alias, string $type ) => "JOIN {$wpdb->term_relationships} tr_{$alias} ON tr_{$alias}.object_id = {$alias}.ID JOIN {$wpdb->term_taxonomy} tt_{$alias} ON tt_{$alias}.term_taxonomy_id = tr_{$alias}.term_taxonomy_id AND tt_{$alias}.taxonomy = 'product_type' JOIN {$wpdb->terms} t_{$alias} ON t_{$alias}.term_id = tt_{$alias}.term_id AND t_{$alias}.slug = '{$type}'";
		$simple    = (array) $wpdb->get_results( "SELECT p.ID AS id, p.ID AS product_id, p.post_title AS title FROM {$wpdb->posts} p " . $type_join( 'p', 'simple' ) . " LEFT JOIN {$codes} c ON c.active_product_id = p.ID WHERE p.post_type = 'product' AND p.post_status = 'publish' AND c.id IS NULL", ARRAY_A );
		$variation = (array) $wpdb->get_results( "SELECT v.ID AS id, pp.ID AS product_id, v.post_title AS title FROM {$wpdb->posts} v JOIN {$wpdb->posts} pp ON pp.ID = v.post_parent AND pp.post_type = 'product' AND pp.post_status = 'publish' " . $type_join( 'pp', 'variable' ) . " LEFT JOIN {$codes} c ON c.active_product_id = v.ID WHERE v.post_type = 'product_variation' AND v.post_status = 'publish' AND c.id IS NULL", ARRAY_A );
		// phpcs:enable

		$rows = array_merge( $simple, $variation );

		$meta = self::meta( array_map( static fn( $r ) => (int) $r['id'], $rows ), array( '_sku' ) );
		usort( $rows, static fn( $a, $b ) => strcasecmp( $a['title'], $b['title'] ) ?: (int) $a['id'] <=> (int) $b['id'] );

		return array_map(
			static fn( $r ) => array(
				'id'         => (int) $r['id'],
				'product_id' => (int) $r['product_id'],
				'name'       => html_entity_decode( (string) $r['title'], ENT_QUOTES, 'UTF-8' ),
				'sku'        => (string) ( $meta[ (int) $r['id'] ]['_sku'] ?? '' ),
			),
			$rows
		);
	}

	/**
	 * Product type slugs of products: ID => simple|variable|grouped|external|….
	 *
	 * @param int[] $ids Products.
	 * @return array<int, string>
	 */
	private static function product_types( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$out = array();

		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core tables; integer IDs.
			foreach ( (array) $wpdb->get_results( "SELECT tr.object_id AS id, t.slug AS type FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type' JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id IN (" . implode( ',', $chunk ) . ')', ARRAY_A ) as $row ) {
				$out[ (int) $row['id'] ] = (string) $row['type'];
			}
		}

		return $out;
	}

	/**
	 * Enabled variations that use their parent's stock: parent ID => variation IDs.
	 *
	 * @param int[] $parents Variable products with product-level stock.
	 * @return array<int, int[]>
	 */
	private static function sharing_variations( array $parents ): array {
		global $wpdb;

		$out = array();

		foreach ( array_chunk( array_map( 'intval', $parents ), 1000 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core tables; integer IDs.
			foreach ( (array) $wpdb->get_results( "SELECT v.ID AS id, v.post_parent AS parent FROM {$wpdb->posts} v LEFT JOIN {$wpdb->postmeta} ms ON ms.post_id = v.ID AND ms.meta_key = '_manage_stock' WHERE v.post_type = 'product_variation' AND v.post_status = 'publish' AND v.post_parent IN (" . implode( ',', $chunk ) . ") AND ( ms.meta_value IS NULL OR ms.meta_value <> 'yes' )", ARRAY_A ) as $row ) {
				$out[ (int) $row['parent'] ][] = (int) $row['id'];
			}
		}

		return $out;
	}

	/**
	 * Parents that exist and are not trashed: ID => status.
	 *
	 * @param int[] $ids Parent IDs.
	 * @return array<int, string>
	 */
	private static function parent_statuses( array $ids ): array {
		global $wpdb;

		$out      = array();
		$statuses = "'" . implode( "','", self::STATUSES ) . "'";

		foreach ( array_chunk( array_map( 'intval', $ids ), 1000 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table; integer IDs; fixed literals.
			foreach ( (array) $wpdb->get_results( "SELECT ID, post_status FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ({$statuses}) AND ID IN (" . implode( ',', $chunk ) . ')', ARRAY_A ) as $row ) {
				$out[ (int) $row['ID'] ] = (string) $row['post_status'];
			}
		}

		return $out;
	}

	/**
	 * The one value shared by a list (decision D12): [value, ''] when every entry is the
	 * same known value; [null, 'unknown'] when any is unknown; [null, 'mixed'] when they
	 * differ; [null, 'none'] for an empty list.
	 *
	 * @param array<int, string|null> $values Values (null = unknown).
	 * @return array{0: string|null, 1: string}
	 */
	private static function common( array $values ): array {
		if ( array() === $values ) {
			return array( null, 'none' );
		}

		if ( in_array( null, $values, true ) ) {
			return array( null, 'unknown' );
		}

		$unique = array_values( array_unique( $values ) );

		return 1 === count( $unique ) ? array( $unique[0], '' ) : array( null, 'mixed' );
	}

	/**
	 * An active price (the _price meta) as a decimal string, or null when there is none.
	 *
	 * @param string $price Stored value.
	 */
	private static function price( string $price ): ?string {
		return is_numeric( $price ) ? ReportsQuery::money( $price ) : null;
	}

	/**
	 * The low-stock amount of an item: its own, else its parent's, else the store's.
	 *
	 * @param string $own        The item's _low_stock_amount.
	 * @param string $parent     The parent's (or '').
	 * @param int    $global_low Store threshold.
	 */
	private static function low_amount( string $own, string $parent, int $global_low ): int {
		foreach ( array( $own, $parent ) as $value ) {
			if ( '' !== $value && is_numeric( $value ) ) {
				return (int) $value;
			}
		}

		return $global_low;
	}

	/**
	 * Some WooCommerce meta values of many posts in one query per 1,000 posts:
	 * post ID => meta key => value (the first value of each key). Only the named keys
	 * are read, instead of priming every meta row of every post.
	 *
	 * @param int[]    $ids  Posts.
	 * @param string[] $keys Meta keys (fixed literals).
	 * @return array<int, array<string, string>>
	 */
	private static function meta( array $ids, array $keys ): array {
		global $wpdb;

		$ids  = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$in   = "'" . implode( "','", array_map( 'esc_sql', $keys ) ) . "'";
		$out  = array();

		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table; integer IDs; escaped fixed keys.
			foreach ( (array) $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', $chunk ) . ") AND meta_key IN ({$in}) ORDER BY meta_id", ARRAY_A ) as $row ) {
				$out[ (int) $row['post_id'] ][ $row['meta_key'] ] ??= (string) $row['meta_value'];
			}
		}

		return $out;
	}
}
