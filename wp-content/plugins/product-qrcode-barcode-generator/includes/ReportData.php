<?php
/**
 * The tabular data of each in-store report (Phase 9B), shared by the screens
 * (ReportsAdmin), the CSV (ReportsExport) and the print page (ReportPrint), so
 * all three always show the same rows and columns.
 *
 * A dataset:
 *   columns       key => array{label, type, sortable, cost}
 *                 type: text | int | money | pct | datetime (UTC) | date (UTC, day only)
 *                 cost: the column exists only for pqbg_view_costs (never built otherwise)
 *   rows          list of key => raw value (null = unknown), plus optional '_link' (URL of the
 *                 first column) and '_order' (the natural order)
 *   total         a row of totals, or null
 *   notes         plain-text notes shown with the table
 *   default_sort, default_order
 *
 * Cost-only reports and columns are refused (WP_Error) or left out when $costs is
 * false; callers pass Permissions::can_view_costs().
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Report datasets.
 */
final class ReportData {

	/** Every report with a table (the Summary tab has none). */
	const REPORTS = array( 'sales', 'products', 'categories', 'sellers', 'peak', 'eod', 'profit', 'voids', 'stock', 'dead' );

	/** Reports that exist only for pqbg_view_costs. */
	const COST_REPORTS = array( 'profit' );

	/** Column keys that carry cost information (refused in orderby for other users). */
	const COST_KEYS = array( 'cost', 'profit', 'margin', 'known_revenue', 'cost_value', 'unit_cost' );

	/**
	 * Builds a report's dataset.
	 *
	 * @param string               $report One of REPORTS.
	 * @param array<string, mixed> $ctx    period (ReportPeriod::resolve()), costs (bool), and the view
	 *                                     options: group, view, mode, by, days, state, cat, metric.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function build( string $report, array $ctx ) {
		$costs = ! empty( $ctx['costs'] );

		if ( ! in_array( $report, self::REPORTS, true ) ) {
			return new WP_Error( 'pqbg_unknown_report', __( 'Unknown report.', 'product-qrcode-barcode-generator' ) );
		}

		if ( in_array( $report, self::COST_REPORTS, true ) && ! $costs ) {
			return new WP_Error( 'pqbg_forbidden', __( 'Sorry, you are not allowed to see costs and profit.', 'product-qrcode-barcode-generator' ) );
		}

		$f = in_array( $report, array( 'stock', 'dead' ), true ) ? array() : ReportPeriod::where_filters( $ctx['period'] );

		switch ( $report ) {
			case 'sales':
				return self::sales( $ctx, $f, $costs );
			case 'products':
				return 'slow' === ( $ctx['mode'] ?? '' ) ? self::slow( $f ) : self::products( $f, $costs, 'product' === ( $ctx['view'] ?? '' ) );
			case 'categories':
				return self::categories( $f, $costs );
			case 'sellers':
				return self::sellers( $f, $costs );
			case 'peak':
				return self::peak( $f );
			case 'eod':
				return self::eod( $f );
			case 'profit':
				return self::profit( $ctx, $f );
			case 'voids':
				return self::voids( $f );
			case 'stock':
				return self::stock( $costs, (string) ( $ctx['state'] ?? '' ), (int) ( $ctx['cat'] ?? 0 ) );
			default: // dead.
				return self::dead( $costs, (int) ( $ctx['days'] ?? 30 ) );
		}
	}

	/**
	 * Metric columns shared by the sales reports.
	 *
	 * @param bool $costs Include profit and margin.
	 * @param bool $share Include the share of revenue.
	 * @return array<string, array<string, mixed>>
	 */
	private static function metric_columns( bool $costs, bool $share = false ): array {
		$cols = array(
			'count'   => self::col( __( 'Sales', 'product-qrcode-barcode-generator' ), 'int' ),
			'items'   => self::col( __( 'Items', 'product-qrcode-barcode-generator' ), 'int' ),
			'revenue' => self::col( __( 'Revenue', 'product-qrcode-barcode-generator' ), 'money' ),
		);

		if ( $share ) {
			$cols['share'] = self::col( __( 'Share of revenue', 'product-qrcode-barcode-generator' ), 'pct' );
		}

		$cols['average'] = self::col( __( 'Average sale', 'product-qrcode-barcode-generator' ), 'money' );

		if ( $costs ) {
			$cols['profit']          = self::col( __( 'Gross profit', 'product-qrcode-barcode-generator' ), 'money', true, true );
			$cols['margin']          = self::col( __( 'Margin', 'product-qrcode-barcode-generator' ), 'pct', true, true );
			$cols['unknown']         = self::col( __( 'Lines with unknown cost', 'product-qrcode-barcode-generator' ), 'int', true, true );
			$cols['unknown_revenue'] = self::col( __( 'Revenue with unknown cost', 'product-qrcode-barcode-generator' ), 'money', true, true );
		}

		return $cols;
	}

	/**
	 * A metrics array as row values (every metric; the columns decide what is shown),
	 * with the share of revenue when a total is given. Cost keys exist only when the
	 * metrics were built with costs.
	 *
	 * @param array<string, mixed> $m     From ReportsQuery::metrics().
	 * @param array<string, mixed> $cols  Columns.
	 * @param string|null          $total Revenue total for the share.
	 * @return array<string, mixed>
	 */
	private static function metric_row( array $m, array $cols, ?string $total = null ): array {
		if ( isset( $cols['share'] ) ) {
			$m['share'] = null !== $total && (float) $total > 0 ? round( (float) $m['revenue'] / (float) $total * 100, 1 ) : null;
		}

		return $m;
	}

	/**
	 * Sales over time.
	 *
	 * @param array<string, mixed> $ctx   Context.
	 * @param array<string, mixed> $f     Filters.
	 * @param bool                 $costs Costs.
	 * @return array<string, mixed>
	 */
	private static function sales( array $ctx, array $f, bool $costs ): array {
		$group   = ReportPeriod::grouping( $ctx['period'], (string) ( $ctx['group'] ?? '' ) );
		$buckets = ReportPeriod::buckets( $ctx['period'], $group );
		$sum     = ReportsQuery::summary( $f, $costs, $buckets );
		$cols    = array_merge( array( 'period' => self::col( __( 'Period', 'product-qrcode-barcode-generator' ), 'text' ) ), self::metric_columns( $costs ) );
		$cols   += array(
			'voided' => self::col( __( 'Voided', 'product-qrcode-barcode-generator' ), 'int' ),
			'failed' => self::col( __( 'Failed', 'product-qrcode-barcode-generator' ), 'int' ),
		);
		$rows    = array();

		foreach ( $buckets as $i => $b ) {
			$m      = $sum['series'][ $i ];
			$rows[] = array_merge(
				array(
					'period' => self::bucket_label( $b, $group ),
					'_order' => $i,
				),
				self::metric_row( $m, $cols ),
				array(
					'voided' => $m['voided'],
					'failed' => $m['failed'],
				)
			);
		}

		return array(
			'columns'       => $cols,
			'rows'          => $rows,
			'total'         => array_merge( array( 'period' => __( 'Total (completed)', 'product-qrcode-barcode-generator' ) ), self::metric_row( $sum['all'], $cols ), array( 'voided' => $sum['voided'], 'failed' => $sum['failed'] ) ),
			'notes'         => array(),
			'default_sort'  => '_order',
			'default_order' => 'asc',
			'group'         => $group,
			'buckets'       => $buckets,
			'summary'       => $sum,
		);
	}

	/**
	 * Best sellers: per item or per product.
	 *
	 * @param array<string, mixed> $f          Filters.
	 * @param bool                 $costs      Costs.
	 * @param bool                 $by_product Group variations.
	 * @return array<string, mixed>
	 */
	private static function products( array $f, bool $costs, bool $by_product ): array {
		$list  = ReportsQuery::products( $f, $costs, $by_product );
		$total = ReportsQuery::money( (string) array_sum( array_map( static fn( $r ) => (float) $r['revenue'], $list ) ) );
		$cols  = array( 'name' => self::col( $by_product ? __( 'Product', 'product-qrcode-barcode-generator' ) : __( 'Item', 'product-qrcode-barcode-generator' ), 'text' ) );

		if ( ! $by_product ) {
			$cols['sku'] = self::col( __( 'SKU', 'product-qrcode-barcode-generator' ), 'text' );
		}

		$cols += self::metric_columns( $costs, true );
		$rows  = array();

		foreach ( $list as $r ) {
			$rows[] = array_merge(
				self::metric_row( $r, $cols, $total ),
				array(
					'name'  => $r['deleted'] ? sprintf( /* translators: %s: product name. */ __( '%s (deleted)', 'product-qrcode-barcode-generator' ), $r['name'] ) : $r['name'],
					'sku'   => $r['sku'],
					'_link' => $r['deleted'] ? '' : self::edit_link( $r['product_id'] ),
				)
			);
		}

		$all = ReportsQuery::metrics( ReportsQuery::add( $list ), $costs ); // The rows are every completed sale, once.

		return array(
			'columns'       => $cols,
			'rows'          => $rows,
			'total'         => array_merge( array( 'name' => __( 'Total (completed)', 'product-qrcode-barcode-generator' ) ), self::metric_row( $all, $cols, $total ) ),
			'notes'         => array( __( 'Names are the snapshot taken at the latest sale. "(deleted)": the product no longer exists.', 'product-qrcode-barcode-generator' ), self::basket_count_rule() ),
			'default_sort'  => 'revenue',
			'default_order' => 'desc',
		);
	}

	/**
	 * Slow sellers: items in stock now, fewest sold in the period first (zero included).
	 *
	 * @param array<string, mixed> $f Filters.
	 * @return array<string, mixed>
	 */
	private static function slow( array $f ): array {
		$sold = array();

		foreach ( ReportsQuery::grouped( $f, array( 'product', 'variation' ), array(), true ) as $r ) { // Sums only; no names needed.
			foreach ( array( $r['product'] . ':' . $r['variation'], $r['product'] . ':*' ) as $key ) {
				$sold[ $key ]['count']   = ( $sold[ $key ]['count'] ?? 0 ) + (int) $r['count'];
				$sold[ $key ]['items']   = ( $sold[ $key ]['items'] ?? 0 ) + (int) $r['items'];
				$sold[ $key ]['revenue'] = ( $sold[ $key ]['revenue'] ?? 0.0 ) + (float) $r['revenue'];
			}
		}

		$cols = array(
			'name'    => self::col( __( 'Item', 'product-qrcode-barcode-generator' ), 'text' ),
			'sku'     => self::col( __( 'SKU', 'product-qrcode-barcode-generator' ), 'text' ),
			'stock'   => self::col( __( 'In stock', 'product-qrcode-barcode-generator' ), 'int' ),
			'items'   => self::col( __( 'Items sold', 'product-qrcode-barcode-generator' ), 'int' ),
			'count'   => self::col( __( 'Sales', 'product-qrcode-barcode-generator' ), 'int' ),
			'revenue' => self::col( __( 'Revenue', 'product-qrcode-barcode-generator' ), 'money' ),
		);
		$rows = array();

		foreach ( StockQuery::holders( false ) as $h ) {
			if ( $h['stock'] <= 0 ) {
				continue;
			}

			$m      = $sold[ 'shared' === $h['kind'] ? $h['product_id'] . ':*' : $h['product_id'] . ':' . $h['variation_id'] ] ?? array();
			$rows[] = array(
				'name'    => $h['name'],
				'sku'     => $h['sku'],
				'stock'   => $h['stock'],
				'items'   => (int) ( $m['items'] ?? 0 ),
				'count'   => (int) ( $m['count'] ?? 0 ),
				'revenue' => ReportsQuery::money( (string) ( $m['revenue'] ?? '0' ) ),
				'_link'   => self::edit_link( $h['product_id'] ),
			);
		}

		return array(
			'columns'       => $cols,
			'rows'          => $rows,
			'total'         => null,
			'notes'         => array( __( 'Slow sellers: every item in stock now (stock above 0), with the fewest items sold in this period first. Items that share their product\'s stock are listed once, as the product.', 'product-qrcode-barcode-generator' ) ),
			'default_sort'  => 'items',
			'default_order' => 'asc',
		);
	}

	/**
	 * Categories.
	 *
	 * @param array<string, mixed> $f     Filters.
	 * @param bool                 $costs Costs.
	 * @return array<string, mixed>
	 */
	private static function categories( array $f, bool $costs ): array {
		$data  = ReportsQuery::categories( $f, $costs );
		$cols  = array(
			'name'     => self::col( __( 'Category', 'product-qrcode-barcode-generator' ), 'text' ),
			'products' => self::col( __( 'Products sold', 'product-qrcode-barcode-generator' ), 'int' ),
		) + self::metric_columns( $costs, true );
		$rows  = array();

		foreach ( $data['rows'] as $i => $r ) {
			$rows[] = array_merge(
				self::metric_row( $r, $cols, $data['total']['revenue'] ),
				array(
					'name'     => str_repeat( '— ', (int) $r['depth'] ) . $r['name'],
					'products' => $r['products'],
					'_order'   => $i,
					'_link'    => $r['term_id'] > 0 ? AdminUrl::products( array( 'product_cat' => (string) get_term_field( 'slug', $r['term_id'], 'product_cat' ) ) ) : '',
				)
			);
		}

		$notes = array( __( 'Current categories of each product (a variation counts under its product). A category includes its sub-categories, each product counted once there. A product in several categories counts in each, so category totals can add up to more than the total.', 'product-qrcode-barcode-generator' ), self::basket_count_rule() );

		if ( $data['multi'] > 0 ) {
			/* translators: %s: number of products. */
			$notes[] = sprintf( _n( '%s product sold in this period is in more than one category.', '%s products sold in this period are in more than one category.', $data['multi'], 'product-qrcode-barcode-generator' ), number_format_i18n( $data['multi'] ) );
		}

		return array(
			'columns'       => $cols,
			'rows'          => $rows,
			'total'         => array_merge( array( 'name' => __( 'Total (each sale once)', 'product-qrcode-barcode-generator' ), 'products' => null ), self::metric_row( $data['total'], $cols, $data['total']['revenue'] ) ),
			'notes'         => $notes,
			'default_sort'  => '_order',
			'default_order' => 'asc',
		);
	}

	/**
	 * Sellers.
	 *
	 * @param array<string, mixed> $f     Filters.
	 * @param bool                 $costs Costs.
	 * @return array<string, mixed>
	 */
	private static function sellers( array $f, bool $costs ): array {
		$cols  = array( 'name' => self::col( __( 'Seller', 'product-qrcode-barcode-generator' ), 'text' ) ) + self::metric_columns( $costs );
		$cols += array(
			'voided'    => self::col( __( 'Voided', 'product-qrcode-barcode-generator' ), 'int' ),
			'undone'    => self::col( __( 'of which undone by the seller', 'product-qrcode-barcode-generator' ), 'int' ),
			'void_rate' => self::col( __( 'Void rate', 'product-qrcode-barcode-generator' ), 'pct' ),
			'failed'    => self::col( __( 'Failed', 'product-qrcode-barcode-generator' ), 'int' ),
		);
		$rows  = array();

		foreach ( ReportsQuery::sellers( $f, $costs ) as $r ) {
			$rows[] = array_merge( array( 'name' => $r['name'] ), self::metric_row( $r, $cols ), array_intersect_key( $r, array_flip( array( 'voided', 'undone', 'void_rate', 'failed' ) ) ) );
		}

		$sum = ReportsQuery::summary( $f, $costs );

		return array(
			'columns'       => $cols,
			'rows'          => $rows,
			'total'         => array_merge(
				array( 'name' => __( 'Total', 'product-qrcode-barcode-generator' ) ),
				self::metric_row( $sum['all'], $cols ),
				array(
					'voided'    => $sum['voided'],
					'undone'    => $sum['undone'],
					'void_rate' => $sum['all']['count'] + $sum['voided'] > 0 ? round( $sum['voided'] / ( $sum['all']['count'] + $sum['voided'] ) * 100, 1 ) : null,
					'failed'    => $sum['failed'],
				)
			),
			'notes'         => array( __( 'Void rate = voided ÷ (completed + voided) of the sales each seller made in this period. Failed attempts (e.g. the item sold online at the same moment) changed no stock and are not the seller\'s voids.', 'product-qrcode-barcode-generator' ) ),
			'default_sort'  => 'revenue',
			'default_order' => 'desc',
		);
	}

	/**
	 * Peak times as a flat table (weekday × hour), for the CSV and the data table.
	 *
	 * @param array<string, mixed> $f Filters.
	 * @return array<string, mixed>
	 */
	private static function peak( array $f ): array {
		$data = ReportsQuery::peak( $f );
		$rows = array();

		foreach ( self::weekdays() as $day => $label ) {
			for ( $hour = 0; $hour < 24; $hour++ ) {
				$rows[] = array(
					'day'     => $label,
					'hour'    => sprintf( '%02d:00–%02d:00', $hour, ( $hour + 1 ) % 24 ),
					'count'   => $data['grid'][ $day ][ $hour ]['count'],
					'revenue' => $data['grid'][ $day ][ $hour ]['revenue'],
					'_order'  => count( $rows ),
				);
			}
		}

		return array(
			'columns'       => array(
				'day'     => self::col( __( 'Day', 'product-qrcode-barcode-generator' ), 'text', false ),
				'hour'    => self::col( __( 'Hour', 'product-qrcode-barcode-generator' ), 'text', false ),
				'count'   => self::col( __( 'Sales', 'product-qrcode-barcode-generator' ), 'int' ),
				'revenue' => self::col( __( 'Revenue', 'product-qrcode-barcode-generator' ), 'money' ),
			),
			'rows'          => $rows,
			'total'         => null,
			'notes'         => array(),
			'default_sort'  => '_order',
			'default_order' => 'asc',
			'peak'          => $data,
		);
	}

	/**
	 * End of day as a flat table (for the CSV): one row per payment method, per
	 * seller and method, per voiding user and method, and per voided sale.
	 *
	 * @param array<string, mixed> $f Filters.
	 * @return array<string, mixed>
	 */
	private static function eod( array $f ): array {
		$d     = ReportsQuery::end_of_day( $f );
		$rows  = array();
		$label = static fn( string $m ) => PaymentMethods::label( '' === $m ? null : $m );

		foreach ( $d['methods'] as $m => $x ) {
			$rows[] = array(
				'section' => __( 'Payment method', 'product-qrcode-barcode-generator' ),
				'name'    => '',
				'method'  => $label( (string) $m ),
				'count'   => $x['sales'],
				'items'   => $x['items'],
				'revenue' => $x['revenue'],
				'refunds' => $x['refunds'],
				'net'     => $x['net'],
			);
		}

		foreach ( $d['sellers'] as $s ) {
			foreach ( array_keys( $d['methods'] ) as $m ) {
				if ( 0.0 === (float) $s['revenue'][ $m ] && 0.0 === (float) $s['refunds'][ $m ] ) {
					continue;
				}

				$rows[] = array(
					'section' => __( 'Seller (who sold)', 'product-qrcode-barcode-generator' ),
					'name'    => $s['name'],
					'method'  => $label( (string) $m ),
					'revenue' => $s['revenue'][ $m ],
					'refunds' => $s['refunds'][ $m ],
					'net'     => $s['net'][ $m ],
				);
			}
		}

		foreach ( $d['refunds_by'] as $r ) {
			foreach ( $r['methods'] as $m => $amount ) {
				if ( 0.0 !== (float) $amount ) {
					$rows[] = array(
						'section' => __( 'Voided in this period by', 'product-qrcode-barcode-generator' ),
						'name'    => $r['name'],
						'method'  => $label( (string) $m ),
						'refunds' => $amount,
					);
				}
			}
		}

		foreach ( array( 'voided_own' => __( 'Voided sale (sold in this period)', 'product-qrcode-barcode-generator' ), 'voided_earlier' => __( 'Voided in this period, sold earlier', 'product-qrcode-barcode-generator' ) ) as $key => $section ) {
			foreach ( $d[ $key ] as $sale ) {
				$rows[] = array(
					'section'   => $section,
					'name'      => SalePresenter::seller( $sale ),
					'method'    => PaymentMethods::label( $sale['payment_method'] ),
					'count'     => 1,
					'items'     => (int) $sale['quantity'],
					'revenue'   => ReportsQuery::money( (string) $sale['line_total'] ),
					'sale'      => (int) $sale['id'],
					'item'      => SalePresenter::item( $sale ),
					'sold_at'   => $sale['created_at_gmt'],
					'voided_at' => $sale['voided_at_gmt'],
					'voided_by' => SalePresenter::user_label( (int) $sale['voided_by'] ),
					'reason'    => self::reason( $sale ),
					'restocked' => self::restocked( $sale ),
				);
			}
		}

		return array(
			'columns'       => array(
				'section'   => self::col( __( 'Section', 'product-qrcode-barcode-generator' ), 'text', false ),
				'name'      => self::col( __( 'Seller / user', 'product-qrcode-barcode-generator' ), 'text', false ),
				'method'    => self::col( __( 'Paid by', 'product-qrcode-barcode-generator' ), 'text', false ),
				'count'     => self::col( __( 'Sales', 'product-qrcode-barcode-generator' ), 'int', false ),
				'items'     => self::col( __( 'Items', 'product-qrcode-barcode-generator' ), 'int', false ),
				'revenue'   => self::col( __( 'Revenue', 'product-qrcode-barcode-generator' ), 'money', false ),
				'refunds'   => self::col( __( 'Refunds of earlier sales', 'product-qrcode-barcode-generator' ), 'money', false ),
				'net'       => self::col( __( 'Net collected', 'product-qrcode-barcode-generator' ), 'money', false ),
				'sale'      => self::col( __( 'Sale #', 'product-qrcode-barcode-generator' ), 'int', false ),
				'item'      => self::col( __( 'Item', 'product-qrcode-barcode-generator' ), 'text', false ),
				'sold_at'   => self::col( __( 'Sold at', 'product-qrcode-barcode-generator' ), 'datetime', false ),
				'voided_at' => self::col( __( 'Voided at', 'product-qrcode-barcode-generator' ), 'datetime', false ),
				'voided_by' => self::col( __( 'Voided by', 'product-qrcode-barcode-generator' ), 'text', false ),
				'reason'    => self::col( __( 'Reason', 'product-qrcode-barcode-generator' ), 'text', false ),
				'restocked' => self::col( __( 'Returned to stock', 'product-qrcode-barcode-generator' ), 'text', false ),
			),
			'rows'          => $rows,
			'total'         => array(
				'section' => __( 'Total', 'product-qrcode-barcode-generator' ),
				'count'   => $d['total']['sales'],
				'items'   => $d['total']['items'],
				'revenue' => $d['total']['revenue'],
				'refunds' => $d['total']['refunds'],
				'net'     => $d['total']['net'],
			),
			'notes'         => array( self::refund_assumption() ),
			'default_sort'  => '',
			'default_order' => 'asc',
			'eod'           => $d,
		);
	}

	/**
	 * Profit & margin (pqbg_view_costs only): by period, product or category.
	 *
	 * @param array<string, mixed> $ctx Context.
	 * @param array<string, mixed> $f   Filters.
	 * @return array<string, mixed>
	 */
	private static function profit( array $ctx, array $f ): array {
		$by   = in_array( $ctx['by'] ?? '', array( 'product', 'category' ), true ) ? $ctx['by'] : 'period';
		$base = 'product' === $by ? self::products( $f, true, false ) : ( 'category' === $by ? self::categories( $f, true ) : self::sales( $ctx, $f, true ) );
		$keep = array( 'period', 'name', 'sku', 'revenue', 'known_revenue', 'cost', 'profit', 'margin', 'unknown', 'unknown_revenue' );
		$cols = array_intersect_key( $base['columns'], array_flip( $keep ) );
		$ins  = array(
			'known_revenue' => self::col( __( 'Revenue with known cost', 'product-qrcode-barcode-generator' ), 'money', true, true ),
			'cost'          => self::col( __( 'Cost', 'product-qrcode-barcode-generator' ), 'money', true, true ),
		);
		$out  = array();

		foreach ( $cols as $key => $c ) { // Revenue, then known revenue and cost, then profit…
			$out[ $key ] = $c;
			if ( 'revenue' === $key ) {
				$out += $ins;
			}
		}

		$base['columns']      = $out;
		$base['default_sort'] = 'period' === $by || 'category' === $by ? '_order' : 'profit';
		$base['notes'][]      = self::unknown_cost_rule();

		return $base;
	}

	/**
	 * Voids & failed.
	 *
	 * @param array<string, mixed> $f Filters.
	 * @return array<string, mixed>
	 */
	private static function voids( array $f ): array {
		$data = ReportsQuery::voids( $f );
		$rows = array();

		foreach ( $data['rows'] as $sale ) {
			$voided = SaleRepository::STATUS_VOIDED === $sale['status'];
			$rows[] = array(
				'sale'      => (int) $sale['id'],
				'sold_at'   => $sale['created_at_gmt'],
				'item'      => SalePresenter::item( $sale ),
				'quantity'  => (int) $sale['quantity'],
				'total'     => ReportsQuery::money( (string) $sale['line_total'] ),
				'method'    => PaymentMethods::label( $sale['payment_method'] ),
				'seller'    => SalePresenter::seller( $sale ),
				'status'    => SalePresenter::status( (string) $sale['status'] ),
				'voided_at' => $voided ? $sale['voided_at_gmt'] : null,
				'voided_by' => $voided ? SalePresenter::user_label( (int) $sale['voided_by'] ) : '',
				'reason'    => $voided ? self::reason( $sale ) : '',
				'restocked' => $voided ? self::restocked( $sale ) : '',
				'failure'   => (string) $sale['failure_code'],
				'_link'     => AdminUrl::sale( (int) $sale['id'] ),
			);
		}

		return array(
			'columns'       => array(
				'sale'      => self::col( __( 'Sale #', 'product-qrcode-barcode-generator' ), 'int' ),
				'sold_at'   => self::col( __( 'Sold at', 'product-qrcode-barcode-generator' ), 'datetime' ),
				'item'      => self::col( __( 'Item', 'product-qrcode-barcode-generator' ), 'text' ),
				'quantity'  => self::col( __( 'Qty', 'product-qrcode-barcode-generator' ), 'int' ),
				'total'     => self::col( __( 'Total', 'product-qrcode-barcode-generator' ), 'money' ),
				'method'    => self::col( __( 'Paid by', 'product-qrcode-barcode-generator' ), 'text' ),
				'seller'    => self::col( __( 'Sold by', 'product-qrcode-barcode-generator' ), 'text' ),
				'status'    => self::col( __( 'Status', 'product-qrcode-barcode-generator' ), 'text' ),
				'voided_at' => self::col( __( 'Voided at', 'product-qrcode-barcode-generator' ), 'datetime' ),
				'voided_by' => self::col( __( 'Voided by', 'product-qrcode-barcode-generator' ), 'text' ),
				'reason'    => self::col( __( 'Reason', 'product-qrcode-barcode-generator' ), 'text', false ),
				'restocked' => self::col( __( 'Returned to stock', 'product-qrcode-barcode-generator' ), 'text' ),
				'failure'   => self::col( __( 'Failure', 'product-qrcode-barcode-generator' ), 'text' ),
			),
			'rows'          => $rows,
			'total'         => null,
			'notes'         => array( __( 'Voided and failed sales made in this period (by sale date, like the sales history). Voided sales are not in any revenue.', 'product-qrcode-barcode-generator' ) ),
			'default_sort'  => 'sold_at',
			'default_order' => 'desc',
			'voids'         => $data,
		);
	}

	/**
	 * Stock.
	 *
	 * @param bool   $costs Costs.
	 * @param string $state '' | low | out | instock | negative | nocode.
	 * @param int    $cat   product_cat term ID (with its sub-categories), 0 = all.
	 * @return array<string, mixed>
	 */
	private static function stock( bool $costs, string $state, int $cat ): array {
		if ( 'nocode' === $state ) {
			return self::missing_codes();
		}

		$holders = self::in_category( StockQuery::holders( $costs ), $cat );

		if ( in_array( $state, array( 'low', 'out', 'instock' ), true ) ) {
			$holders = array_values( array_filter( $holders, static fn( $h ) => $state === $h['state'] ) );
		} elseif ( 'negative' === $state ) {
			$holders = array_values( array_filter( $holders, static fn( $h ) => $h['negative'] ) );
		}

		$cols = array(
			'name'   => self::col( __( 'Item', 'product-qrcode-barcode-generator' ), 'text' ),
			'sku'    => self::col( __( 'SKU', 'product-qrcode-barcode-generator' ), 'text' ),
			'status' => self::col( __( 'Status', 'product-qrcode-barcode-generator' ), 'text' ),
			'stock'  => self::col( __( 'Stock', 'product-qrcode-barcode-generator' ), 'int' ),
			'state'  => self::col( __( 'State', 'product-qrcode-barcode-generator' ), 'text' ),
			'low'    => self::col( __( 'Low at', 'product-qrcode-barcode-generator' ), 'int' ),
			'price'  => self::col( __( 'Price', 'product-qrcode-barcode-generator' ), 'money' ),
			'value'  => self::col( __( 'Value at price', 'product-qrcode-barcode-generator' ), 'money' ),
		);

		if ( $costs ) {
			$cols['cost']       = self::col( __( 'Cost', 'product-qrcode-barcode-generator' ), 'money', true, true );
			$cols['cost_value'] = self::col( __( 'Value at cost', 'product-qrcode-barcode-generator' ), 'money', true, true );
		}

		$rows = array();

		foreach ( $holders as $h ) {
			$row = array(
				'name'   => $h['name'] . ( 'shared' === $h['kind'] ? ' ' . __( '(stock shared by its variations)', 'product-qrcode-barcode-generator' ) : '' ),
				'sku'    => $h['sku'],
				'status' => self::post_status( $h['status'] ),
				'stock'  => $h['stock'],
				'state'  => self::state_label( $h['state'] ) . ( $h['negative'] ? ' ' . __( '(below zero)', 'product-qrcode-barcode-generator' ) : '' ),
				'low'    => $h['low'],
				'price'  => $h['price'],
				'value'  => $h['value'],
				'_link'  => self::edit_link( $h['product_id'] ),
				'_notes' => array( 'price' => $h['price_note'] ),
			);

			if ( $costs ) {
				$row['cost']            = $h['cost'];
				$row['cost_value']      = $h['cost_value'];
				$row['_notes']['cost']  = $h['cost_note'];
			}

			$rows[] = $row;
		}

		$t     = StockQuery::totals( $holders, $costs );
		$notes = array(
			__( 'Items that manage their own stock (published, private, draft or pending). A variable product with product-level stock is one line, shared by its variations. Low and out of stock use the WooCommerce thresholds. Stock below zero (backorders) is valued at 0.', 'product-qrcode-barcode-generator' ),
		);

		if ( $t['unpriced'] > 0 ) {
			/* translators: 1: number of items, 2: units. */
			$notes[] = sprintf( __( '%1$s items (%2$s units) are not in the value at price: no price, or variations sharing the stock have different prices.', 'product-qrcode-barcode-generator' ), number_format_i18n( $t['unpriced'] ), number_format_i18n( $t['unpriced_units'] ) );
		}

		if ( $costs && $t['uncosted'] > 0 ) {
			/* translators: 1: number of items, 2: units, 3: their value at price. */
			$notes[] = sprintf( __( 'Value at cost leaves out %1$s items without a known cost (%2$s units, %3$s at price).', 'product-qrcode-barcode-generator' ), number_format_i18n( $t['uncosted'] ), number_format_i18n( $t['uncosted_units'] ), SalePresenter::money( $t['uncosted_value'] ) );
		}

		$total = array(
			'name'  => __( 'Total', 'product-qrcode-barcode-generator' ),
			'stock' => $t['units'],
			'value' => $t['value'],
		);

		if ( $costs ) {
			$total['cost_value'] = $t['cost_value'];
		}

		return array(
			'columns'       => $cols,
			'rows'          => $rows,
			'total'         => $total,
			'notes'         => $notes,
			'default_sort'  => 'name',
			'default_order' => 'asc',
			'totals'        => $t,
		);
	}

	/**
	 * Active items without a code (the Stock report's "Without a code" view).
	 *
	 * @return array<string, mixed>
	 */
	private static function missing_codes(): array {
		$rows = array_map(
			static fn( $r ) => array(
				'name'  => $r['name'],
				'sku'   => $r['sku'],
				'_link' => self::edit_link( $r['product_id'] ),
			),
			StockQuery::missing_codes()
		);

		return array(
			'columns'       => array(
				'name' => self::col( __( 'Item', 'product-qrcode-barcode-generator' ), 'text' ),
				'sku'  => self::col( __( 'SKU', 'product-qrcode-barcode-generator' ), 'text' ),
			),
			'rows'          => $rows,
			'total'         => null,
			'notes'         => array( __( 'Published simple products and published variations of published variable products that have no active QR code. Open the product and save it, or use "Generate code" in its QR & Barcode panel.', 'product-qrcode-barcode-generator' ) ),
			'default_sort'  => 'name',
			'default_order' => 'asc',
		);
	}

	/**
	 * Dead stock.
	 *
	 * @param bool $costs Costs.
	 * @param int  $days  30, 60, 90 or 0 (never sold).
	 * @return array<string, mixed>
	 */
	private static function dead( bool $costs, int $days ): array {
		$days = in_array( $days, array_merge( StockQuery::DEAD_WINDOWS, array( 0 ) ), true ) ? $days : 30;
		$data = StockQuery::dead( StockQuery::holders( $costs ), $days );
		$cols = array(
			'name'         => self::col( __( 'Item', 'product-qrcode-barcode-generator' ), 'text' ),
			'sku'          => self::col( __( 'SKU', 'product-qrcode-barcode-generator' ), 'text' ),
			'stock'        => self::col( __( 'Stock', 'product-qrcode-barcode-generator' ), 'int' ),
			'age_days'     => self::col( __( 'Age (days)', 'product-qrcode-barcode-generator' ), 'int' ),
			'last_instore' => self::col( __( 'Last in-store sale', 'product-qrcode-barcode-generator' ), 'date' ),
			'last_online'  => self::col( __( 'Last online order', 'product-qrcode-barcode-generator' ), 'date' ),
			'value'        => self::col( __( 'Value at price', 'product-qrcode-barcode-generator' ), 'money' ),
		);

		if ( $costs ) {
			$cols['cost_value'] = self::col( __( 'Value at cost', 'product-qrcode-barcode-generator' ), 'money', true, true );
		}

		$rows = array();

		foreach ( $data['rows'] as $h ) {
			$row = array(
				'name'         => $h['name'],
				'sku'          => $h['sku'],
				'stock'        => $h['stock'],
				'age_days'     => $h['age_days'],
				'last_instore' => '' === $h['last_instore'] ? null : $h['last_instore'],
				'last_online'  => '' === $h['last_online'] ? null : $h['last_online'],
				'value'        => $h['value'],
				'_link'        => self::edit_link( $h['product_id'] ),
			);

			if ( $costs ) {
				$row['cost_value'] = $h['cost_value'];
			}

			$rows[] = $row;
		}

		$notes = array( self::online_rule() );

		if ( $days > 0 ) {
			/* translators: %d: days. */
			$notes[] = sprintf( __( 'Dead stock: in stock now and not sold in the last %d days. Items added less than that long ago are not counted as dead yet.', 'product-qrcode-barcode-generator' ), $days );
		}

		if ( $data['new'] > 0 ) {
			/* translators: 1: number of items, 2: days. */
			$notes[] = sprintf( __( '%1$s unsold items were added in the last %2$d days and are not listed.', 'product-qrcode-barcode-generator' ), number_format_i18n( $data['new'] ), $days );
		}

		return array(
			'columns'       => $cols,
			'rows'          => $rows,
			'total'         => array(
				'name'  => __( 'Total', 'product-qrcode-barcode-generator' ),
				'stock' => array_sum( array_column( $rows, 'stock' ) ),
				'value' => ReportsQuery::money( (string) array_sum( array_map( 'floatval', array_filter( array_column( $rows, 'value' ), static fn( $v ) => null !== $v ) ) ) ),
			) + ( $costs ? array( 'cost_value' => ReportsQuery::money( (string) array_sum( array_map( 'floatval', array_filter( array_column( $rows, 'cost_value' ), static fn( $v ) => null !== $v ) ) ) ) ) : array() ),
			'notes'         => $notes,
			'default_sort'  => 0 === $days ? 'age_days' : 'value',
			'default_order' => 'desc',
			'days'          => $days,
		);
	}

	/**
	 * The void reason as shown ("Undone by the seller" for an undo).
	 *
	 * @param array<string, mixed> $sale Row.
	 */
	public static function reason( array $sale ): string {
		return SaleService::VOID_REASON_UNDO === $sale['void_reason'] ? __( 'Undone by the seller', 'product-qrcode-barcode-generator' ) : (string) $sale['void_reason'];
	}

	/**
	 * "Yes", "No", "Yes (undo)" or "Not recorded" (voids before schema v4) for a voided sale.
	 *
	 * @param array<string, mixed> $sale Row.
	 */
	public static function restocked( array $sale ): string {
		if ( isset( $sale['void_restock'] ) && null !== $sale['void_restock'] && '' !== $sale['void_restock'] ) {
			return (int) $sale['void_restock'] ? __( 'Yes', 'product-qrcode-barcode-generator' ) : __( 'No', 'product-qrcode-barcode-generator' );
		}

		return SaleService::VOID_REASON_UNDO === $sale['void_reason'] ? __( 'Yes (undo)', 'product-qrcode-barcode-generator' ) : __( 'Not recorded', 'product-qrcode-barcode-generator' );
	}

	/**
	 * The on-screen statement of what "Sales" counts per item or category (Phase 17).
	 */
	public static function basket_count_rule(): string {
		return __( 'Sales = the number of sales that include the item. A sale of several items (a basket) counts once in the totals but once for each item here, so these rows can add up to more than the total. Items and amounts always add up.', 'product-qrcode-barcode-generator' );
	}

	/**
	 * The on-screen statement of the unknown-cost rule.
	 */
	public static function unknown_cost_rule(): string {
		return __( 'Profit = total − quantity × unit cost, for sales whose cost was known at the moment of sale. Sales with an unknown cost are left out of cost, profit and margin (never counted as zero) and are shown separately. Margin = profit ÷ revenue of the sales with a known cost.', 'product-qrcode-barcode-generator' );
	}

	/**
	 * The on-screen statement of the refund assumption (end of day).
	 */
	public static function refund_assumption(): string {
		return __( 'Net collected = revenue of this period\'s completed sales − the amounts of EARLIER sales voided in this period, per payment method. Assumption: refunds are paid back in the original payment method. A sale made and voided in the same period is already not in revenue, so it is not subtracted again.', 'product-qrcode-barcode-generator' );
	}

	/**
	 * The on-screen statement of the online-orders rule (dead stock, decision D4).
	 */
	public static function online_rule(): string {
		return __( 'Counts in-store sales and online orders (processing, completed, on hold): an item sold online is not dead stock.', 'product-qrcode-barcode-generator' );
	}

	/**
	 * Weekday labels in the site's week order: ISO weekday (1 = Monday) => short name.
	 *
	 * @return array<int, string>
	 */
	public static function weekdays(): array {
		global $wp_locale;

		$first = (int) get_option( 'start_of_week', 1 );
		$out   = array();

		for ( $i = 0; $i < 7; $i++ ) {
			$w                       = ( $first + $i ) % 7; // 0 = Sunday.
			$out[ 0 === $w ? 7 : $w ] = $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $w ) ) : gmdate( 'D', strtotime( 'Sunday +' . $w . ' days' ) );
		}

		return $out;
	}

	/**
	 * A bucket's label for tables: the day, "1–7 Sep 2026" for a week, "Sep 2026" for a month, "14:00" for an hour.
	 *
	 * @param array<string, mixed> $b     Bucket.
	 * @param string               $group Grouping.
	 */
	public static function bucket_label( array $b, string $group ): string {
		if ( 'hour' === $group ) {
			return wp_date( 'H:i', $b['start'] ) . '–' . wp_date( 'H:i', $b['end'] );
		}

		if ( 'month' === $group ) {
			return wp_date( 'F Y', $b['start'] ) . ( wp_date( 'j', $b['start'] ) !== '1' || $b['to'] !== wp_date( 'Y-m-t', $b['start'] ) ? ' (' . ReportPeriod::span_label( $b['from'], $b['to'] ) . ')' : '' );
		}

		return ReportPeriod::span_label( $b['from'], $b['to'] );
	}

	/**
	 * Products in a category or its sub-categories (0 = all).
	 *
	 * @param array<int, array<string, mixed>> $holders Holders.
	 * @param int                              $cat     Term ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function in_category( array $holders, int $cat ): array {
		if ( $cat <= 0 ) {
			return $holders;
		}

		$terms = array_merge( array( $cat ), array_map( 'intval', (array) get_term_children( $cat, 'product_cat' ) ) );
		$map   = ReportsQuery::product_categories( array_map( static fn( $h ) => (int) $h['product_id'], $holders ) );

		return array_values( array_filter( $holders, static fn( $h ) => (bool) array_intersect( $map[ (int) $h['product_id'] ] ?? array(), $terms ) ) );
	}

	/**
	 * A column spec.
	 *
	 * @param string $label    Label.
	 * @param string $type     Type.
	 * @param bool   $sortable Sortable.
	 * @param bool   $cost     Cost column.
	 * @return array<string, mixed>
	 */
	private static function col( string $label, string $type, bool $sortable = true, bool $cost = false ): array {
		return array(
			'label'    => $label,
			'type'     => $type,
			'sortable' => $sortable,
			'cost'     => $cost,
		);
	}

	/**
	 * The product's edit link when the user may edit it, else ''.
	 *
	 * @param int $product_id Product (never a variation).
	 */
	private static function edit_link( int $product_id ): string {
		if ( $product_id <= 0 || ! current_user_can( 'edit_post', $product_id ) ) {
			return '';
		}

		return (string) get_edit_post_link( $product_id, 'raw' );
	}

	/**
	 * Post status label.
	 *
	 * @param string $status Status.
	 */
	private static function post_status( string $status ): string {
		$object = get_post_status_object( $status );

		return $object ? (string) $object->label : $status;
	}

	/**
	 * Stock state label.
	 *
	 * @param string $state out|low|instock.
	 */
	public static function state_label( string $state ): string {
		$labels = array(
			'out'     => __( 'Out of stock', 'product-qrcode-barcode-generator' ),
			'low'     => __( 'Low stock', 'product-qrcode-barcode-generator' ),
			'instock' => __( 'In stock', 'product-qrcode-barcode-generator' ),
		);

		return $labels[ $state ] ?? $state;
	}
}
