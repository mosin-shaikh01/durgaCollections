<?php
/**
 * Read-only queries over pqbg_sales for the sales history, its totals, the CSV
 * export and the seller's "My sales" page (Phase 9A).
 *
 * Filters (a normalised array; see filters()):
 *   start, end  UTC "Y-m-d H:i:s", half-open [start, end), from a site-timezone date range
 *   seller      user ID, 0 = everyone
 *   method      PaymentMethods key, 'none' = not recorded, '' = any
 *   status      completed | voided | failed, '' = any
 *   statuses    optional list of statuses (My sales: completed and voided only)
 *   search      product name or SKU (substring), or an exact product code
 *
 * Date ranges are whole days in the SITE timezone (wp_timezone()), converted to
 * UTC for created_at_gmt:
 *   today, yesterday, last7 (today and the 6 days before), this_month, last_month,
 *   custom (from/to as Y-m-d, both inclusive)
 *
 * Totals count COMPLETED rows only; voided and failed rows are only counted.
 * Cost and profit use the unit_cost snapshot; rows with an unknown cost (NULL)
 * are left out of cost and profit and reported separately, never as zero.
 *
 * Indexes used (see Schema): created_at_gmt, seller_created, status_created and
 * method_created (schema v3, chosen on EXPLAIN evidence for the payment filter).
 * Never writes: only $wpdb->get_* calls.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Sales history queries.
 */
final class SalesQuery {

	const PRESETS = array( 'today', 'yesterday', 'last7', 'this_month', 'last_month', 'custom' );

	const STATUSES = array( SaleRepository::STATUS_COMPLETED, SaleRepository::STATUS_VOIDED, SaleRepository::STATUS_FAILED );

	/** Payment filter value for rows without a method (sales before schema v3). */
	const METHOD_NONE = 'none';

	/** Sortable columns: request value => SQL column. */
	const SORTS = array(
		'date'    => 's.created_at_gmt',
		'id'      => 's.id',
		'total'   => 's.line_total',
		'qty'     => 's.quantity',
		'product' => 's.product_name',
	);

	/** Rows per CSV query. */
	const CHUNK = 1000;

	/** IDs per keyset page of the CSV export (see each_chunk()). */
	const ID_PAGE = 5000;

	/**
	 * A site-timezone date range as UTC bounds.
	 *
	 * @param string   $preset One of PRESETS; anything else means 'today'.
	 * @param string   $from   Custom start, Y-m-d.
	 * @param string   $to     Custom end, Y-m-d (inclusive).
	 * @param int|null $now    Unix time to use as "now" (tests).
	 * @return array{preset: string, start: string, end: string, from: string, to: string, swapped: bool}
	 *         start/end in UTC; from/to the local days shown (both inclusive).
	 */
	public static function range( string $preset, string $from = '', string $to = '', ?int $now = null ): array {
		$tz      = wp_timezone();
		$today   = ( new DateTimeImmutable( '@' . ( $now ?? time() ) ) )->setTimezone( $tz )->setTime( 0, 0 );
		$swapped = false;
		$preset  = in_array( $preset, self::PRESETS, true ) ? $preset : 'today';

		switch ( $preset ) {
			case 'yesterday':
				$start = $today->modify( '-1 day' );
				$end   = $today;
				break;
			case 'last7':
				$start = $today->modify( '-6 days' );
				$end   = $today->modify( '+1 day' );
				break;
			case 'this_month':
				$start = $today->modify( 'first day of this month' );
				$end   = $today->modify( 'first day of next month' );
				break;
			case 'last_month':
				$start = $today->modify( 'first day of last month' );
				$end   = $today->modify( 'first day of this month' );
				break;
			case 'custom':
				$a = self::parse_day( $from, $tz );
				$b = self::parse_day( $to, $tz );

				if ( null === $a && null === $b ) {
					$preset = 'today';
					$start  = $today;
					$end    = $today->modify( '+1 day' );
					break;
				}

				$a = $a ?? $b;
				$b = $b ?? $a;

				if ( $a > $b ) {
					list( $a, $b ) = array( $b, $a );
					$swapped       = true;
				}

				$start = $a;
				$end   = $b->modify( '+1 day' );
				break;
			default:
				$start = $today;
				$end   = $today->modify( '+1 day' );
		}

		$utc = new DateTimeZone( 'UTC' );

		return array(
			'preset'  => $preset,
			'start'   => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'end'     => $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'from'    => $start->format( 'Y-m-d' ),
			'to'      => $end->modify( '-1 day' )->format( 'Y-m-d' ),
			'swapped' => $swapped,
		);
	}

	/**
	 * Normalised filters and view options from request arguments (the history URL).
	 * Unknown or malformed values fall back to "any".
	 *
	 * @param array<string, mixed> $args     Unslashed query arguments.
	 * @param int|null             $now      Unix time "now" (tests).
	 * @return array<string, mixed>
	 */
	public static function filters( array $args, ?int $now = null ): array {
		$get    = static fn( string $key ): string => isset( $args[ $key ] ) && is_string( $args[ $key ] ) ? trim( $args[ $key ] ) : '';
		$range  = self::range( $get( 'range' ), $get( 'from' ), $get( 'to' ), $now );
		$method = $get( 'method' );
		$status = $get( 'status' );
		$seller = $get( 'seller' );
		$sort   = $get( 'orderby' );
		$paged  = $get( 'paged' );

		return array(
			'range'   => $range,
			'start'   => $range['start'],
			'end'     => $range['end'],
			'seller'  => ctype_digit( $seller ) && strlen( $seller ) < 20 ? (int) $seller : 0,
			'method'  => self::METHOD_NONE === $method || array_key_exists( $method, PaymentMethods::all() ) ? $method : '',
			'status'  => in_array( $status, self::STATUSES, true ) ? $status : '',
			'search'  => mb_substr( $get( 's' ), 0, 100 ),
			'orderby' => array_key_exists( $sort, self::SORTS ) ? $sort : 'date',
			'order'   => 'asc' === strtolower( $get( 'order' ) ) ? 'asc' : 'desc',
			'paged'   => ctype_digit( $paged ) && (int) $paged > 0 && strlen( $paged ) < 7 ? (int) $paged : 1,
		);
	}

	/**
	 * Query arguments that reproduce a filter set (for links, pagination and the CSV URL).
	 *
	 * @param array<string, mixed> $f Filters from filters().
	 * @return array<string, string>
	 */
	public static function args( array $f ): array {
		$args = array( 'range' => $f['range']['preset'] );

		if ( 'custom' === $f['range']['preset'] ) {
			$args['from'] = $f['range']['from'];
			$args['to']   = $f['range']['to'];
		}

		foreach ( array( 'seller', 'method', 'status', 'search' ) as $key ) {
			if ( ! empty( $f[ $key ] ) ) {
				$args[ 'search' === $key ? 's' : $key ] = (string) $f[ $key ];
			}
		}

		if ( 'date' !== $f['orderby'] || 'desc' !== $f['order'] ) {
			$args['orderby'] = $f['orderby'];
			$args['order']   = $f['order'];
		}

		return $args;
	}

	/**
	 * One page of rows, each with the product code (or null) as "code".
	 *
	 * @param array<string, mixed> $f      Filters.
	 * @param int                  $limit  Rows.
	 * @param int                  $offset Offset.
	 * @return array<int, array<string, string|null>>
	 */
	public static function rows( array $f, int $limit, int $offset = 0 ): array {
		global $wpdb;

		$sales = Schema::sales_table();
		$codes = Schema::codes_table();
		$dir   = 'asc' === ( $f['order'] ?? 'desc' ) ? 'ASC' : 'DESC';
		$sort  = self::SORTS[ $f['orderby'] ?? 'date' ] ?? self::SORTS['date'];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifiers; the sort column and direction are whitelisted; where() is prepared.
		$sql = "SELECT s.*, c.code AS code FROM {$sales} s LEFT JOIN {$codes} c ON c.id = s.code_id WHERE " . self::where( $f ) . " ORDER BY {$sort} {$dir}, s.id {$dir}" . $wpdb->prepare( ' LIMIT %d OFFSET %d', max( 1, $limit ), max( 0, $offset ) );

		return (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built above.
	}

	/**
	 * One sale with its product code (or null) as "code".
	 *
	 * @param int $id Sale ID.
	 * @return array<string, string|null>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$sales = Schema::sales_table();
		$codes = Schema::codes_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT s.*, c.code AS code FROM {$sales} s LEFT JOIN {$codes} c ON c.id = s.code_id WHERE s.id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifiers.

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Number of rows matching the filters.
	 *
	 * @param array<string, mixed> $f Filters.
	 */
	public static function count( array $f ): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::sales_table() . ' s WHERE ' . self::where( $f ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed identifier; where() is prepared.
	}

	/**
	 * Totals of the filtered set: completed rows only, overall and per payment method,
	 * plus how many rows of each other status the set contains.
	 *
	 * @param array<string, mixed> $f Filters.
	 * @return array{methods: array<string, array<string, mixed>>, all: array<string, mixed>, voided: int, failed: int}
	 *         Each total: count, items, revenue, cost, profit, known (rows with a cost), unknown (rows without), unknown_revenue.
	 *         methods is keyed by the stored value ('' for not recorded), in display order.
	 */
	public static function totals( array $f ): array {
		global $wpdb;

		$table   = Schema::sales_table();
		$methods = array();
		$counts  = array();
		$all     = self::zero();

		// One pass over the filtered set, grouped by status and method: the completed groups give the
		// totals, the others only their counts (measured faster than two queries on 50,000 rows).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- fixed identifier; where() is prepared.
		$rows = (array) $wpdb->get_results(
			"SELECT s.status AS status, s.payment_method AS method, COUNT(*) AS count, SUM(s.quantity) AS items, SUM(s.line_total) AS revenue,
				SUM(CASE WHEN s.unit_cost IS NOT NULL THEN s.quantity * s.unit_cost END) AS cost,
				SUM(CASE WHEN s.unit_cost IS NOT NULL THEN s.line_total END) AS known_revenue,
				SUM(s.unit_cost IS NOT NULL) AS known,
				SUM(CASE WHEN s.unit_cost IS NULL THEN s.line_total END) AS unknown_revenue
			FROM {$table} s WHERE " . self::where( $f ) . ' GROUP BY s.status, s.payment_method',
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$status            = (string) $row['status'];
			$counts[ $status ] = ( $counts[ $status ] ?? 0 ) + (int) $row['count'];

			if ( SaleRepository::STATUS_COMPLETED !== $status ) {
				continue;
			}

			$total                              = self::total( $row );
			$methods[ (string) $row['method'] ] = $total;

			foreach ( array( 'count', 'items', 'known', 'unknown' ) as $key ) {
				$all[ $key ] += $total[ $key ];
			}

			foreach ( array( 'revenue', 'cost', 'profit', 'unknown_revenue' ) as $key ) {
				$all[ $key ] = self::add( $all[ $key ], $total[ $key ] );
			}
		}

		$order   = array_merge( array_keys( PaymentMethods::all() ), array( '' ) );
		$methods = array_merge( array_intersect_key( array_fill_keys( $order, null ), $methods ), $methods );

		return array(
			'methods' => $methods,
			'all'     => $all,
			'voided'  => $counts[ SaleRepository::STATUS_VOIDED ] ?? 0,
			'failed'  => $counts[ SaleRepository::STATUS_FAILED ] ?? 0,
		);
	}

	/**
	 * Calls $callback with successive chunks of rows (the view's order), so a
	 * large export never holds more than CHUNK rows in memory.
	 *
	 * Keyset paging: the ordered IDs are read ID_PAGE at a time, each page
	 * continuing after the last (sort value, id) of the previous one, and the rows
	 * are then fetched CHUNK at a time by primary key. OFFSET paging re-reads
	 * every earlier row on each chunk (measured 23.6 s for 50,000 rows); reading
	 * every ID at once held all of them in $wpdb (+25 MB). A sale recorded during
	 * the export never shifts a page, so no row is exported twice or skipped.
	 *
	 * @param array<string, mixed> $f        Filters.
	 * @param callable             $callback Receives a list of rows.
	 * @return int Rows delivered.
	 */
	public static function each_chunk( array $f, callable $callback ): int {
		global $wpdb;

		$sales = Schema::sales_table();
		$codes = Schema::codes_table();
		$dir   = 'asc' === ( $f['order'] ?? 'desc' ) ? 'ASC' : 'DESC';
		$cmp   = 'ASC' === $dir ? '>' : '<';
		$sort  = self::SORTS[ $f['orderby'] ?? 'date' ] ?? self::SORTS['date'];
		$where = self::where( $f );
		$last  = null;
		$sent  = 0;

		do {
			$after = null === $last ? '' : $wpdb->prepare( " AND ({$sort} {$cmp} %s OR ({$sort} = %s AND s.id {$cmp} %d))", $last['k'], $last['k'], $last['id'] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- whitelisted column and operator.
			$page  = (array) $wpdb->get_results( "SELECT s.id AS id, {$sort} AS k FROM {$sales} s WHERE {$where}{$after} ORDER BY {$sort} {$dir}, s.id {$dir} LIMIT " . (int) self::ID_PAGE, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- fixed identifiers; whitelisted sort; prepared conditions.
			$wpdb->flush();

			if ( array() === $page ) {
				break;
			}

			$last = end( $page );

			foreach ( array_chunk( array_map( 'intval', array_column( $page, 'id' ) ), self::CHUNK ) as $chunk ) {
				$rows = (array) $wpdb->get_results( "SELECT s.*, c.code AS code FROM {$sales} s LEFT JOIN {$codes} c ON c.id = s.code_id WHERE s.id IN (" . implode( ',', $chunk ) . ')', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- fixed identifiers; integer IDs.
				$wpdb->flush();
				$byid = array_column( $rows, null, 'id' );
				$list = array();

				foreach ( $chunk as $id ) {
					if ( isset( $byid[ $id ] ) ) { // Rows are never deleted, but never trust that.
						$list[] = $byid[ $id ];
					}
				}

				unset( $rows, $byid );

				if ( array() !== $list ) {
					$callback( $list );
					$sent += count( $list );
				}
			}
		} while ( count( $page ) === self::ID_PAGE );

		return $sent;
	}

	/**
	 * Everyone who has recorded a sale: user ID => latest name snapshot ('' when none).
	 *
	 * @return array<int, string>
	 */
	public static function sellers(): array {
		global $wpdb;

		$table = Schema::sales_table();
		$out   = array();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier.
		foreach ( (array) $wpdb->get_results( "SELECT s.seller_id AS id, (SELECT x.seller_name FROM {$table} x WHERE x.seller_id = s.seller_id AND x.seller_name IS NOT NULL ORDER BY x.id DESC LIMIT 1) AS name FROM (SELECT DISTINCT seller_id FROM {$table}) s", ARRAY_A ) as $row ) {
			$out[ (int) $row['id'] ] = (string) $row['name'];
		}

		return $out;
	}

	/**
	 * WHERE clause (prepared) for a filter set. Table alias "s".
	 *
	 * @param array<string, mixed> $f Filters.
	 */
	public static function where( array $f ): string {
		global $wpdb;

		$parts = array( $wpdb->prepare( 's.created_at_gmt >= %s AND s.created_at_gmt < %s', $f['start'], $f['end'] ) );

		if ( ! empty( $f['seller'] ) ) {
			$parts[] = $wpdb->prepare( 's.seller_id = %d', $f['seller'] );
		}

		if ( self::METHOD_NONE === ( $f['method'] ?? '' ) ) {
			$parts[] = 's.payment_method IS NULL';
		} elseif ( ! empty( $f['method'] ) ) {
			$parts[] = $wpdb->prepare( 's.payment_method = %s', $f['method'] );
		}

		if ( ! empty( $f['status'] ) ) {
			$parts[] = $wpdb->prepare( 's.status = %s', $f['status'] );
		}

		if ( ! empty( $f['statuses'] ) ) {
			$parts[] = 's.status IN (' . implode( ',', array_map( static fn( $s ) => $wpdb->prepare( '%s', $s ), (array) $f['statuses'] ) ) . ')';
		}

		$search = trim( (string) ( $f['search'] ?? '' ) );

		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$match = array( $wpdb->prepare( 's.product_name LIKE %s', $like ), $wpdb->prepare( 's.sku LIKE %s', $like ) );
			$code  = ScanUrl::extract_code( $search );
			$row   = CodeGenerator::is_valid_format( $code ) ? CodeRepository::find_by_code( $code ) : null;

			if ( null !== $row ) {
				$match[] = $wpdb->prepare( 's.code_id = %d', (int) $row['id'] );
			}

			$parts[] = '(' . implode( ' OR ', $match ) . ')';
		}

		return implode( ' AND ', $parts );
	}

	/**
	 * A totals row from the GROUP BY query.
	 *
	 * @param array<string, string|null> $row Row.
	 * @return array<string, mixed>
	 */
	private static function total( array $row ): array {
		$cost          = null === $row['cost'] ? '0' : self::money( $row['cost'] );
		$known_revenue = null === $row['known_revenue'] ? '0' : self::money( $row['known_revenue'] );

		return array(
			'count'           => (int) $row['count'],
			'items'           => (int) $row['items'],
			'revenue'         => self::money( (string) $row['revenue'] ),
			'cost'            => $cost,
			'profit'          => self::money( (string) ( (float) $known_revenue - (float) $cost ) ),
			'known'           => (int) $row['known'],
			'unknown'         => (int) $row['count'] - (int) $row['known'],
			'unknown_revenue' => null === $row['unknown_revenue'] ? '0' : self::money( $row['unknown_revenue'] ),
		);
	}

	/**
	 * An empty total.
	 *
	 * @return array<string, mixed>
	 */
	private static function zero(): array {
		return array(
			'count'           => 0,
			'items'           => 0,
			'revenue'         => '0',
			'cost'            => '0',
			'profit'          => '0',
			'known'           => 0,
			'unknown'         => 0,
			'unknown_revenue' => '0',
		);
	}

	/**
	 * An amount rounded to the store's price decimals.
	 *
	 * @param string $amount Amount.
	 */
	private static function money( string $amount ): string {
		return (string) wc_format_decimal( round( (float) $amount, wc_get_price_decimals() ), wc_get_price_decimals() );
	}

	/**
	 * Sum of two amounts.
	 *
	 * @param string $a Amount.
	 * @param string $b Amount.
	 */
	private static function add( string $a, string $b ): string {
		return self::money( (string) ( (float) $a + (float) $b ) );
	}

	/**
	 * A Y-m-d day at local midnight, or null when malformed.
	 *
	 * @param string       $day Y-m-d.
	 * @param DateTimeZone $tz  Site timezone.
	 */
	private static function parse_day( string $day, DateTimeZone $tz ): ?DateTimeImmutable {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $day ) ) {
			return null;
		}

		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $day, $tz );

		return $date && $date->format( 'Y-m-d' ) === $day ? $date : null;
	}
}
