<?php
/**
 * Read-only aggregates over pqbg_sales for the in-store reports and the owner
 * dashboard (Phase 9B).
 *
 * Counting rules (the same as SalesQuery::totals(), shown on every report):
 *   - a sale is one transaction (Phase 17, D23): a basket of several lines counts once
 *     (COALESCE(basket_id, id)), a single sale is its own transaction; revenue, items and
 *     sales count status = completed only; voided and failed sales are only counted;
 *   - items, amounts, cost and "lines" are sums over the lines (rows), so every amount
 *     reconciles whatever the count means; "unknown" counts LINES with an unknown cost;
 *   - per product and per category, "sales" counts the sales that contain the product,
 *     so those rows do not add up to the number of sales (a basket counts in each);
 *   - amounts are the sale's snapshots (line_total, quantity, unit_cost);
 *   - profit = line_total − quantity × unit_cost where unit_cost is known; rows
 *     with an unknown cost are left out of cost, profit and margin and reported
 *     separately; margin = profit ÷ revenue of the rows with a known cost;
 *   - every filter is on the sale date (created_at_gmt), in site-timezone days.
 *
 * Cost fields (cost, profit, margin, known_revenue) are returned only when the
 * caller asks for them with $costs = true, which callers do only for users with
 * pqbg_view_costs.
 *
 * Time buckets: INTERVAL() over the sale's Unix time with the buckets' local
 * boundaries computed in PHP (ReportPeriod::buckets()), so days, weeks and months
 * follow the site timezone exactly, daylight saving included. The peak-times grid
 * groups by 15-minute UTC slots, mapped to local weekday and hour in PHP (exact
 * for every real UTC offset, e.g. +05:30).
 *
 * Never writes: only $wpdb->get_* calls.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * In-store report queries.
 */
final class ReportsQuery {

	/** Unix time of a sale (created_at_gmt is UTC), independent of the connection's time zone. */
	const EPOCH = "TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', s.created_at_gmt)";

	/** Aggregates of a group of rows. */
	const AGG = 'COUNT(DISTINCT COALESCE(s.basket_id, s.id)) AS count, COUNT(*) AS lines, SUM(s.quantity) AS items, SUM(s.line_total) AS revenue,
		SUM(CASE WHEN s.unit_cost IS NOT NULL THEN s.quantity * s.unit_cost END) AS cost,
		SUM(CASE WHEN s.unit_cost IS NOT NULL THEN s.line_total END) AS known_revenue,
		SUM(s.unit_cost IS NOT NULL) AS known,
		SUM(CASE WHEN s.unit_cost IS NULL THEN s.line_total END) AS unknown_revenue,
		COUNT(DISTINCT CASE WHEN s.void_reason = \'undo\' THEN COALESCE(s.basket_id, s.id) END) AS undone';

	/** Dimensions a grouped query may use: name => SQL expression. */
	const DIMS = array(
		'status'    => 's.status',
		'method'    => 's.payment_method',
		'seller'    => 's.seller_id',
		'product'   => 's.product_id',
		'variation' => 's.variation_id',
	);

	/**
	 * Aggregated rows of the sales in a filter set, grouped by some dimensions.
	 *
	 * @param array<string, mixed> $f          Filters for SalesQuery::where() (start, end, seller, method, status…).
	 * @param string[]             $dims       Keys of DIMS, and/or 'bucket' (needs $buckets) or 'quarter' (15-minute UTC slot).
	 * @param array<int, array>    $buckets    ReportPeriod::buckets() when grouping by bucket.
	 * @param bool                 $completed  Only completed rows.
	 * @param string               $extra_sql  Extra aggregate columns (fixed SQL only).
	 * @return array<int, array<string, mixed>> Raw rows: the dimensions (bucket as 0-based index) and the AGG columns.
	 */
	public static function grouped( array $f, array $dims, array $buckets = array(), bool $completed = false, string $extra_sql = '' ): array {
		global $wpdb;

		$select = array();

		foreach ( $dims as $dim ) {
			if ( 'bucket' === $dim ) {
				$bounds   = array_map( static fn( $b ) => (int) $b['start'], $buckets );
				$select[] = 'INTERVAL(' . self::EPOCH . ', ' . implode( ',', array() === $bounds ? array( 0 ) : $bounds ) . ') - 1 AS bucket';
			} elseif ( 'quarter' === $dim ) {
				$select[] = 'FLOOR(' . self::EPOCH . ' / 900) AS quarter';
			} elseif ( isset( self::DIMS[ $dim ] ) ) {
				$select[] = self::DIMS[ $dim ] . ' AS ' . $dim;
			}
		}

		$where = SalesQuery::where( $f ) . ( $completed ? $wpdb->prepare( ' AND s.status = %s', SaleRepository::STATUS_COMPLETED ) : '' );
		$group = array() === $dims ? '' : ' GROUP BY ' . implode( ', ', $dims );
		$sql   = 'SELECT ' . implode( ', ', array_merge( $select, array( self::AGG ) ) ) . ( '' === $extra_sql ? '' : ', ' . $extra_sql ) . ' FROM ' . Schema::sales_table() . " s WHERE {$where}{$group}";

		return (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed identifiers and expressions; where() is prepared; bucket bounds are integers.
	}

	/**
	 * Metrics of one aggregated row (or of a sum of rows, see add()).
	 *
	 * @param array<string, mixed>|null $row   Row with the AGG columns, or null for zero.
	 * @param bool                      $costs Include cost, profit, margin and known revenue.
	 * @return array<string, mixed> count, items, revenue, average, unknown, unknown_revenue, undone
	 *                              (+ cost, profit, margin, known, known_revenue).
	 */
	public static function metrics( ?array $row, bool $costs ): array {
		$count   = (int) ( $row['count'] ?? 0 );
		$lines   = (int) ( $row['lines'] ?? $count );
		$revenue = self::money( (string) ( $row['revenue'] ?? '0' ) );
		$known   = (int) ( $row['known'] ?? 0 );
		$out     = array(
			'count'           => $count,
			'items'           => (int) ( $row['items'] ?? 0 ),
			'revenue'         => $revenue,
			'average'         => $count > 0 ? self::money( (string) ( (float) $revenue / $count ) ) : null,
			'unknown'         => $lines - $known,
			'unknown_revenue' => self::money( (string) ( $row['unknown_revenue'] ?? '0' ) ),
			'undone'          => (int) ( $row['undone'] ?? 0 ),
		);

		if ( $costs ) {
			$cost          = self::money( (string) ( $row['cost'] ?? '0' ) );
			$known_revenue = self::money( (string) ( $row['known_revenue'] ?? '0' ) );
			$profit        = self::money( (string) ( (float) $known_revenue - (float) $cost ) );

			$out['known']         = $known;
			$out['known_revenue'] = $known_revenue;
			$out['cost']          = $cost;
			$out['profit']        = $profit;
			$out['margin']        = (float) $known_revenue > 0 ? round( (float) $profit / (float) $known_revenue * 100, 1 ) : null;
		}

		return $out;
	}

	/**
	 * Sum of raw AGG rows (exact decimal strings are added as amounts rounded to the store's decimals).
	 *
	 * @param array<int, array<string, mixed>> $rows Raw rows.
	 * @return array<string, mixed> A raw row.
	 */
	public static function add( array $rows ): array {
		$sum = array(
			'count'           => 0,
			'lines'           => 0,
			'items'           => 0,
			'known'           => 0,
			'undone'          => 0,
			'revenue'         => '0',
			'cost'            => '0',
			'known_revenue'   => '0',
			'unknown_revenue' => '0',
		);

		$amounts = array(
			'revenue'         => 0.0,
			'cost'            => 0.0,
			'known_revenue'   => 0.0,
			'unknown_revenue' => 0.0,
		);

		foreach ( $rows as $row ) {
			foreach ( array( 'count', 'lines', 'items', 'known', 'undone' ) as $key ) {
				$sum[ $key ] += (int) ( $row[ $key ] ?? ( 'lines' === $key ? ( $row['count'] ?? 0 ) : 0 ) );
			}

			foreach ( $amounts as $key => $value ) {
				$amounts[ $key ] = $value + (float) ( $row[ $key ] ?? 0 );
			}
		}

		// Added as floats and rounded once: amounts have at most 8 decimals, so the error of
		// even 50,000 additions stays far below the store's 2 decimals.
		foreach ( $amounts as $key => $value ) {
			$sum[ $key ] = self::money( (string) $value );
		}

		return $sum;
	}

	/**
	 * Completed totals of a period, per payment method, and the voided/failed counts.
	 * One pass grouped by status and method (plus the time bucket when $buckets is given).
	 *
	 * @param array<string, mixed> $f       Filters.
	 * @param bool                 $costs   Include cost fields.
	 * @param array<int, array>    $buckets Optional buckets for a series.
	 * @param bool                 $sellers Also group by seller in the same scan (the dashboard's top sellers).
	 * @return array{all: array<string, mixed>, methods: array<string, array<string, mixed>>, voided: int, failed: int, undone: int, sellers: array<int, array<string, mixed>>, series: array<int, array<string, mixed>>}
	 *         series: one metrics set per bucket, plus its voided and failed counts;
	 *         sellers: completed metrics per seller ID (empty unless $sellers).
	 */
	public static function summary( array $f, bool $costs, array $buckets = array(), bool $sellers = false ): array {
		$dims    = array_merge( array() === $buckets ? array() : array( 'bucket' ), array( 'status', 'method' ), $sellers ? array( 'seller' ) : array() );
		$rows    = self::grouped( $f, $dims, $buckets );
		$by_sell = array();
		$done    = array();
		$methods = array();
		$series  = array_fill( 0, count( $buckets ), array() );
		$others  = array_fill( 0, count( $buckets ), array( SaleRepository::STATUS_VOIDED => 0, SaleRepository::STATUS_FAILED => 0 ) );
		$counts  = array();
		$undone  = 0;

		foreach ( $rows as $row ) {
			$status            = (string) $row['status'];
			$counts[ $status ] = ( $counts[ $status ] ?? 0 ) + (int) $row['count'];

			if ( SaleRepository::STATUS_VOIDED === $status ) {
				$undone += (int) $row['undone'];
			}

			if ( SaleRepository::STATUS_COMPLETED !== $status ) {
				if ( isset( $row['bucket'], $others[ (int) $row['bucket'] ][ $status ] ) ) {
					$others[ (int) $row['bucket'] ][ $status ] += (int) $row['count'];
				}
				continue;
			}

			if ( $sellers ) {
				$by_sell[ (int) $row['seller'] ][] = $row;
			}

			$done[]                               = $row;
			$methods[ (string) $row['method'] ][] = $row;

			if ( isset( $row['bucket'] ) && isset( $series[ (int) $row['bucket'] ] ) ) {
				$series[ (int) $row['bucket'] ][] = $row;
			}
		}

		$by_method = array();

		foreach ( array_merge( array_keys( PaymentMethods::all() ), array( '' ) ) as $key ) {
			$by_method[ $key ] = self::metrics( self::add( $methods[ $key ] ?? array() ), $costs );
		}

		foreach ( $methods as $key => $list ) { // A stored key no longer offered (never expected).
			if ( ! isset( $by_method[ $key ] ) ) {
				$by_method[ $key ] = self::metrics( self::add( $list ), $costs );
			}
		}

		return array(
			'all'     => self::metrics( self::add( $done ), $costs ),
			'methods' => $by_method,
			'voided'  => $counts[ SaleRepository::STATUS_VOIDED ] ?? 0,
			'failed'  => $counts[ SaleRepository::STATUS_FAILED ] ?? 0,
			'undone'  => $undone,
			'sellers' => array_map( static fn( array $list ) => self::metrics( self::add( $list ), $costs ), $by_sell ),
			'series'  => array_map( static fn( array $list, array $other ) => self::metrics( self::add( $list ), $costs ) + array( 'voided' => $other[ SaleRepository::STATUS_VOIDED ], 'failed' => $other[ SaleRepository::STATUS_FAILED ] ), $series, $others ),
		);
	}

	/**
	 * Per item (variation, or simple product) or per product (a variable product's
	 * variations together): completed sales only, with the latest name snapshot.
	 *
	 * @param array<string, mixed> $f     Filters.
	 * @param bool                 $costs Include cost fields.
	 * @param bool                 $by_product Group variations under their product.
	 * @param int                  $top        Only the top N by revenue (and every row tied with the Nth), named; 0 = all.
	 * @return array<int, array<string, mixed>> product_id, variation_id (0 by product), name, sku, deleted, metrics…
	 */
	public static function products( array $f, bool $costs, bool $by_product = false, int $top = 0 ): array {
		global $wpdb;

		$rows = self::grouped( $f, $by_product ? array( 'product' ) : array( 'product', 'variation' ), array(), true, 'MAX(s.id) AS last_id' );

		if ( array() === $rows ) {
			return array();
		}

		if ( $top > 0 && count( $rows ) > $top ) { // Name only what is shown (the dashboard's top 5), ties at the cut kept.
			usort( $rows, static fn( $a, $b ) => (float) $b['revenue'] <=> (float) $a['revenue'] );
			$cut  = (float) $rows[ $top - 1 ]['revenue'];
			$rows = array_values( array_filter( $rows, static fn( $r ) => (float) $r['revenue'] >= $cut ) );
		}

		$sales = Schema::sales_table();
		$ids   = implode( ',', array_map( static fn( $r ) => (int) $r['last_id'], $rows ) );
		$snaps = array_column( (array) $wpdb->get_results( "SELECT id, product_id, variation_id, product_name, sku, attributes_json FROM {$sales} WHERE id IN ({$ids})", ARRAY_A ), null, 'id' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier; integer IDs.
		$items = array();

		foreach ( $rows as $row ) {
			$items[] = (int) $row['product'];
			if ( ! $by_product && (int) $row['variation'] > 0 ) {
				$items[] = (int) $row['variation'];
			}
		}

		$alive = self::existing_items( $items );
		$out   = array();

		foreach ( $rows as $row ) {
			$snap      = $snaps[ (int) $row['last_id'] ] ?? array( 'product_name' => '', 'sku' => null, 'attributes_json' => null );
			$variation = $by_product ? 0 : (int) $row['variation'];
			$item      = $variation > 0 ? $variation : (int) $row['product'];
			$name      = $by_product ? (string) $snap['product_name'] : SalePresenter::item( $snap );

			$out[] = array_merge(
				array(
					'product_id'   => (int) $row['product'],
					'variation_id' => $variation,
					'name'         => $name,
					'sku'          => $by_product ? '' : (string) $snap['sku'],
					'deleted'      => ! isset( $alive[ $item ] ),
				),
				self::metrics( $row, $costs )
			);
		}

		return $out;
	}

	/**
	 * Per seller: completed metrics, voided (and undone) and failed counts, void rate.
	 * Void rate = voided ÷ (completed + voided) of the sales made in the period.
	 *
	 * @param array<string, mixed> $f     Filters.
	 * @param bool                 $costs Include cost fields.
	 * @return array<int, array<string, mixed>> seller_id, name, metrics…, voided, failed, void_rate
	 */
	public static function sellers( array $f, bool $costs ): array {
		$by    = array();
		$names = SalesQuery::sellers();

		foreach ( self::grouped( $f, array( 'seller', 'status' ) ) as $row ) {
			$by[ (int) $row['seller'] ][ (string) $row['status'] ] = $row;
		}

		$out = array();

		foreach ( $by as $seller => $statuses ) {
			$voided = (int) ( $statuses[ SaleRepository::STATUS_VOIDED ]['count'] ?? 0 );
			$done   = (int) ( $statuses[ SaleRepository::STATUS_COMPLETED ]['count'] ?? 0 );
			$name   = $names[ $seller ] ?? '';

			$out[] = array_merge(
				array(
					'seller_id' => $seller,
					'name'      => SalePresenter::seller( array( 'seller_id' => $seller, 'seller_name' => '' === $name ? null : $name ) ),
				),
				self::metrics( $statuses[ SaleRepository::STATUS_COMPLETED ] ?? null, $costs ),
				array(
					'voided'    => $voided,
					'undone'    => (int) ( $statuses[ SaleRepository::STATUS_VOIDED ]['undone'] ?? 0 ),
					'failed'    => (int) ( $statuses[ SaleRepository::STATUS_FAILED ]['count'] ?? 0 ),
					'void_rate' => $done + $voided > 0 ? round( $voided / ( $done + $voided ) * 100, 1 ) : null,
				)
			);
		}

		return $out;
	}

	/**
	 * Per category of the product (current categories; a variation counts under its
	 * product's). Decision D3: a product in several categories counts fully in each;
	 * a parent category includes its sub-categories with each product counted once
	 * at that level; deleted products (no categories left) form their own row.
	 *
	 * @param array<string, mixed> $f     Filters.
	 * @param bool                 $costs Include cost fields.
	 * @return array{rows: array<int, array<string, mixed>>, total: array<string, mixed>, multi: int}
	 *         rows in tree order: term_id (0 = deleted products), name, depth, parent, products, metrics…
	 */
	public static function categories( array $f, bool $costs ): array {
		$raw      = self::grouped( $f, array( 'product' ), array(), true );
		$products = array_map( static fn( $r ) => (int) $r['product'], $raw );
		$terms    = self::product_categories( $products );
		$alive    = self::existing_items( $products );
		$groups   = array();
		$multi    = 0;

		foreach ( $raw as $row ) {
			$product = (int) $row['product'];
			$direct  = $terms[ $product ] ?? array();

			if ( ! isset( $alive[ $product ] ) || array() === $direct ) {
				$groups[0][ $product ] = $row;
				continue;
			}

			$multi += count( $direct ) > 1 ? 1 : 0;
			$set    = array();

			foreach ( $direct as $term_id ) {
				$set[ $term_id ] = true;

				foreach ( get_ancestors( $term_id, 'product_cat', 'taxonomy' ) as $ancestor ) {
					$set[ (int) $ancestor ] = true;
				}
			}

			foreach ( array_keys( $set ) as $term_id ) {
				$groups[ $term_id ][ $product ] = $row; // Keyed by product: counted once per category.
			}
		}

		$rows = array();

		foreach ( self::category_tree( array_keys( $groups ) ) as $node ) {
			$rows[] = array_merge( $node, array( 'products' => count( $groups[ $node['term_id'] ] ) ), self::metrics( self::add( array_values( $groups[ $node['term_id'] ] ) ), $costs ) );
		}

		if ( isset( $groups[0] ) ) {
			$rows[] = array_merge(
				array(
					'term_id'  => 0,
					'name'     => __( 'Deleted products (category unknown)', 'product-qrcode-barcode-generator' ),
					'depth'    => 0,
					'parent'   => 0,
					'products' => count( $groups[0] ),
				),
				self::metrics( self::add( array_values( $groups[0] ) ), $costs )
			);
		}

		// Phase 17: the total counts each sale once, even a basket of products in several categories.
		$all = self::grouped( $f, array(), array(), true );

		return array(
			'rows'  => $rows,
			'total' => self::metrics( $all[0] ?? null, $costs ),
			'multi' => $multi,
		);
	}

	/**
	 * Hour-of-day × day-of-week grid of completed sales in the site timezone.
	 *
	 * @param array<string, mixed> $f Filters.
	 * @return array{grid: array<int, array<int, array{count: int, revenue: string}>>, days: array<int, array{count: int, revenue: string}>, hours: array<int, array{count: int, revenue: string}>}
	 *         grid[weekday 1 (Mon)..7 (Sun)][hour 0..23].
	 */
	public static function peak( array $f ): array {
		$tz    = wp_timezone();
		$cell  = array(
			'count'   => 0,
			'revenue' => 0.0,
		);
		$grid  = array_fill( 1, 7, array_fill( 0, 24, $cell ) );
		$days  = array_fill( 1, 7, $cell );
		$hours = array_fill( 0, 24, $cell );
		$utc   = new DateTimeImmutable( '@0' );

		foreach ( self::grouped( $f, array( 'quarter' ), array(), true ) as $row ) {
			$time  = (int) $row['quarter'] * 900;
			$local = $utc->setTimestamp( $time )->setTimezone( $tz );
			$day   = (int) $local->format( 'N' );
			$hour  = (int) $local->format( 'G' );

			foreach ( array( &$grid[ $day ][ $hour ], &$days[ $day ], &$hours[ $hour ] ) as &$target ) {
				$target['count']   += (int) $row['count'];
				$target['revenue'] += (float) $row['revenue'];
			}
			unset( $target );
		}

		$round = static fn( array $c ) => array(
			'count'   => $c['count'],
			'revenue' => self::money( (string) $c['revenue'] ),
		);

		return array(
			'grid'  => array_map( static fn( array $row ) => array_map( $round, $row ), $grid ),
			'days'  => array_map( $round, $days ),
			'hours' => array_map( $round, $hours ),
		);
	}

	/**
	 * End of day / payments for a period (normally one day). Decision "net collected"
	 * (the user's addition): refunds are assumed to be paid back in the original
	 * payment method, so for each method
	 *   net collected = revenue of the period's completed sales in that method
	 *                   − amounts of EARLIER sales in that method voided during the period.
	 * A sale made AND voided in the period is already not in revenue, so it is never
	 * subtracted twice. For cash the net is "Cash expected in drawer".
	 *
	 * @param array<string, mixed> $f Filters (the period).
	 * @return array<string, mixed> methods (method => sales, items, revenue, refunds, net),
	 *         sellers (seller => name, methods (method => revenue, refunds, net), total_net),
	 *         refunds_by (voiding user => name, methods (method => count, amount)),
	 *         voided_own (rows), voided_earlier (rows), total.
	 */
	public static function end_of_day( array $f ): array {
		global $wpdb;

		$sales   = Schema::sales_table();
		$methods = array_merge( array_keys( PaymentMethods::all() ), array( '' ) );
		$zero    = static fn() => array_fill_keys( $methods, '0' );
		$names   = SalesQuery::sellers();
		$out     = array(
			'methods'    => array(),
			'sellers'    => array(),
			'refunds_by' => array(),
		);

		foreach ( $methods as $m ) {
			$out['methods'][ $m ] = array(
				'sales'   => 0,
				'items'   => 0,
				'revenue' => '0',
				'refunds' => '0',
				'refund_count' => 0,
				'net'     => '0',
			);
		}

		$seller = static function ( int $id ) use ( &$out, $names, $zero ) {
			if ( ! isset( $out['sellers'][ $id ] ) ) {
				$name                  = $names[ $id ] ?? '';
				$out['sellers'][ $id ] = array(
					'name'    => SalePresenter::seller( array( 'seller_id' => $id, 'seller_name' => '' === $name ? null : $name ) ),
					'sales'   => 0,
					'revenue' => $zero(),
					'refunds' => $zero(),
					'net'     => $zero(),
				);
			}
		};

		foreach ( self::grouped( $f, array( 'seller', 'method' ), array(), true ) as $row ) {
			$m  = (string) $row['method'];
			$id = (int) $row['seller'];
			$seller( $id );
			$out['methods'][ $m ]['sales']              += (int) $row['count'];
			$out['methods'][ $m ]['items']              += (int) $row['items'];
			$out['methods'][ $m ]['revenue']             = self::money( (string) ( (float) $out['methods'][ $m ]['revenue'] + (float) $row['revenue'] ) );
			$out['sellers'][ $id ]['sales']             += (int) $row['count'];
			$out['sellers'][ $id ]['revenue'][ $m ]      = self::money( (string) ( (float) $out['sellers'][ $id ]['revenue'][ $m ] + (float) $row['revenue'] ) );
		}

		// Earlier sales voided during the period (the refunds). Phase 17: amounts per line, counts per sale.
		$counted = array();
		$earlier = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$sales} s WHERE s.status = %s AND s.voided_at_gmt >= %s AND s.voided_at_gmt < %s AND s.created_at_gmt < %s ORDER BY s.voided_at_gmt, s.id", SaleRepository::STATUS_VOIDED, $f['start'], $f['end'], $f['start'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier.

		foreach ( $earlier as $row ) {
			$m     = (string) $row['payment_method'];
			$id    = (int) $row['seller_id'];
			$by    = (int) $row['voided_by'];
			$total = (string) $row['line_total'];
			$seller( $id );
			$number                                = empty( $row['basket_id'] ) ? (int) $row['id'] : (int) $row['basket_id'];
			$first                                 = ! isset( $counted[ $number ] );
			$counted[ $number ]                    = true;
			$out['methods'][ $m ]['refunds']       = self::money( (string) ( (float) $out['methods'][ $m ]['refunds'] + (float) $total ) );
			$out['methods'][ $m ]['refund_count'] += $first ? 1 : 0;
			$out['sellers'][ $id ]['refunds'][ $m ] = self::money( (string) ( (float) $out['sellers'][ $id ]['refunds'][ $m ] + (float) $total ) );

			if ( ! isset( $out['refunds_by'][ $by ] ) ) {
				$out['refunds_by'][ $by ] = array(
					'name'    => SalePresenter::user_label( $by ),
					'count'   => 0,
					'methods' => $zero(),
				);
			}

			$out['refunds_by'][ $by ]['count'] += $first ? 1 : 0;
			$out['refunds_by'][ $by ]['methods'][ $m ] = self::money( (string) ( (float) $out['refunds_by'][ $by ]['methods'][ $m ] + (float) $total ) );
		}

		$net_total = 0.0;

		foreach ( $methods as $m ) {
			$out['methods'][ $m ]['net'] = self::money( (string) ( (float) $out['methods'][ $m ]['revenue'] - (float) $out['methods'][ $m ]['refunds'] ) );
			$net_total                  += (float) $out['methods'][ $m ]['net'];
		}

		foreach ( $out['sellers'] as $id => $row ) {
			foreach ( $methods as $m ) {
				$out['sellers'][ $id ]['net'][ $m ] = self::money( (string) ( (float) $row['revenue'][ $m ] - (float) $row['refunds'][ $m ] ) );
			}
		}

		uasort( $out['sellers'], static fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );

		$out['voided_own']     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$sales} s WHERE s.status = %s AND s.created_at_gmt >= %s AND s.created_at_gmt < %s ORDER BY s.created_at_gmt, s.id", SaleRepository::STATUS_VOIDED, $f['start'], $f['end'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier.
		$out['voided_earlier'] = $earlier;
		$out['total']          = array(
			'sales'   => array_sum( array_column( $out['methods'], 'sales' ) ),
			'items'   => array_sum( array_column( $out['methods'], 'items' ) ),
			'revenue' => self::money( (string) array_sum( array_map( 'floatval', array_column( $out['methods'], 'revenue' ) ) ) ),
			'refunds' => self::money( (string) array_sum( array_map( 'floatval', array_column( $out['methods'], 'refunds' ) ) ) ),
			'net'     => self::money( (string) $net_total ),
		);

		return $out;
	}

	/**
	 * Voided and failed sales made in the period (newest first), with per-seller and
	 * per-failure counts.
	 *
	 * @param array<string, mixed> $f Filters.
	 * @return array{rows: array<int, array<string, mixed>>, sellers: array<int, array<string, mixed>>, failures: array<string, int>}
	 */
	public static function voids( array $f ): array {
		global $wpdb;

		$sales = Schema::sales_table();
		$rows  = (array) $wpdb->get_results( 'SELECT s.* FROM ' . $sales . ' s WHERE ' . SalesQuery::where( $f ) . $wpdb->prepare( ' AND s.status IN (%s, %s)', SaleRepository::STATUS_VOIDED, SaleRepository::STATUS_FAILED ) . ' ORDER BY s.created_at_gmt DESC, s.id DESC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed identifier; where() is prepared.
		$per   = array();
		$fails = array();
		$seen  = array(); // Phase 17: a basket counts once per seller and status.

		foreach ( $rows as $row ) {
			$id     = (int) $row['seller_id'];
			$number = empty( $row['basket_id'] ) ? (int) $row['id'] : (int) $row['basket_id'];

			if ( ! isset( $per[ $id ] ) ) {
				$per[ $id ] = array(
					'seller_id' => $id,
					'name'      => SalePresenter::seller( $row ),
					'voided'    => 0,
					'undone'    => 0,
					'failed'    => 0,
				);
			}

			$key = $row['status'] . ':' . $number;

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;

			if ( SaleRepository::STATUS_VOIDED === $row['status'] ) {
				$per[ $id ]['voided']++;
				$per[ $id ]['undone'] += SaleService::VOID_REASON_UNDO === $row['void_reason'] ? 1 : 0;
			} else {
				$per[ $id ]['failed']++;
				$code           = self::failure_of( $rows, $row, $number );
				$fails[ $code ] = ( $fails[ $code ] ?? 0 ) + 1;
			}
		}

		return array(
			'rows'     => $rows,
			'sellers'  => array_values( $per ),
			'failures' => $fails,
		);
	}

	/**
	 * A failed sale's failure code: for a basket, the code of the line that failed (the
	 * other lines say basket_rollback).
	 *
	 * @param array<int, array<string, mixed>> $rows   The listed rows.
	 * @param array<string, mixed>             $row    One of them.
	 * @param int                              $number Its sale number.
	 */
	private static function failure_of( array $rows, array $row, int $number ): string {
		if ( empty( $row['basket_id'] ) ) {
			return (string) $row['failure_code'];
		}

		foreach ( $rows as $other ) {
			if ( (int) $other['basket_id'] === $number && SaleRepository::STATUS_FAILED === $other['status'] && SaleRepository::FAILURE_BASKET !== $other['failure_code'] ) {
				return (string) $other['failure_code'];
			}
		}

		return (string) $row['failure_code'];
	}

	/**
	 * The last completed in-store sale of every item, all time:
	 * "product:variation" => UTC datetime (variation 0 for simple products).
	 *
	 * @return array<string, string>
	 */
	public static function last_sales(): array {
		global $wpdb;

		$sales = Schema::sales_table();
		$out   = array();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT product_id, variation_id, MAX(created_at_gmt) AS last FROM {$sales} WHERE status = %s GROUP BY product_id, variation_id", SaleRepository::STATUS_COMPLETED ), ARRAY_A ) as $row ) {
			$out[ (int) $row['product_id'] . ':' . (int) $row['variation_id'] ] = (string) $row['last'];
		}

		return $out;
	}

	/**
	 * Top N of a list by a key, descending (ties by name).
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @param string                           $key  Numeric key.
	 * @param int                              $n    How many.
	 * @return array<int, array<string, mixed>>
	 */
	public static function top( array $rows, string $key, int $n ): array {
		usort( $rows, static fn( $a, $b ) => ( (float) $b[ $key ] <=> (float) $a[ $key ] ) ?: strcasecmp( (string) $a['name'], (string) $b['name'] ) );

		return array_slice( $rows, 0, $n );
	}

	/**
	 * Change from a previous value in percent (null when the previous value is 0).
	 *
	 * @param float|int|string|null $now  Current value.
	 * @param float|int|string|null $then Previous value.
	 */
	public static function change( $now, $then ): ?float {
		if ( null === $now || null === $then || 0.0 === (float) $then ) {
			return null;
		}

		return round( ( (float) $now - (float) $then ) / abs( (float) $then ) * 100, 1 );
	}

	/**
	 * Products and variations that still exist (not deleted): ID => post type.
	 *
	 * @param int[] $ids IDs.
	 * @return array<int, string>
	 */
	public static function existing_items( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$out = array();

		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table; integer IDs.
			foreach ( (array) $wpdb->get_results( "SELECT ID, post_type FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', $chunk ) . ") AND post_type IN ('product', 'product_variation')", ARRAY_A ) as $row ) {
				$out[ (int) $row['ID'] ] = (string) $row['post_type'];
			}
		}

		return $out;
	}

	/**
	 * Current product_cat terms of products: product ID => term IDs.
	 *
	 * @param int[] $product_ids Products.
	 * @return array<int, int[]>
	 */
	public static function product_categories( array $product_ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ) ) ) );
		$out = array();

		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core tables; integer IDs.
			foreach ( (array) $wpdb->get_results( "SELECT tr.object_id AS id, tt.term_id AS term FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat' WHERE tr.object_id IN (" . implode( ',', $chunk ) . ')', ARRAY_A ) as $row ) {
				$out[ (int) $row['id'] ][] = (int) $row['term'];
			}
		}

		return $out;
	}

	/**
	 * The given categories in tree order (parents first, siblings by name), with depth.
	 *
	 * @param int[] $term_ids Categories (0 is ignored).
	 * @return array<int, array{term_id: int, name: string, depth: int, parent: int}>
	 */
	private static function category_tree( array $term_ids ): array {
		$nodes = array();

		foreach ( $term_ids as $id ) {
			$term = $id > 0 ? get_term( (int) $id, 'product_cat' ) : null;

			if ( $term instanceof \WP_Term ) {
				$nodes[ (int) $id ] = array(
					'term_id' => (int) $id,
					'name'    => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
					'parent'  => (int) $term->parent,
				);
			}
		}

		$children = array();

		foreach ( $nodes as $id => $node ) {
			$children[ isset( $nodes[ $node['parent'] ] ) ? $node['parent'] : 0 ][] = $id;
		}

		$out  = array();
		$walk = static function ( int $parent, int $depth ) use ( &$walk, &$out, $children, $nodes ): void {
			$list = $children[ $parent ] ?? array();
			usort( $list, static fn( $a, $b ) => strcasecmp( $nodes[ $a ]['name'], $nodes[ $b ]['name'] ) );

			foreach ( $list as $id ) {
				$out[] = array_merge( $nodes[ $id ], array( 'depth' => $depth ) );
				$walk( $id, $depth + 1 );
			}
		};
		$walk( 0, 0 );

		return $out;
	}

	/**
	 * An amount rounded to the store's price decimals.
	 *
	 * @param string $amount Amount.
	 */
	public static function money( string $amount ): string {
		static $decimals = null;

		$decimals = $decimals ?? wc_get_price_decimals(); // Read once per request (called for every aggregated row).
		$number   = round( (float) $amount, $decimals );

		return number_format( 0.0 === $number ? 0.0 : $number, $decimals, '.', '' );
	}
}
