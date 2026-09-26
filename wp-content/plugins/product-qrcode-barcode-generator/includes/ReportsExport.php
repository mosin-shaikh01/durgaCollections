<?php
/**
 * CSV export of an in-store report (Phase 9B), with the Phase 9A export rules.
 *
 * GET admin-post.php?action=pqbg_report_csv&_wpnonce=…&tab={report}&{the report's options}
 *   - pqbg_view_all_sales and a nonce; read-only (no writes of any kind)
 *   - the profit report and cost columns only for pqbg_view_costs; asking for them
 *     without it (tab=profit, orderby a cost column) is refused with 403
 *   - UTF-8 with a byte order mark; dates in the site timezone ("Y-m-d H:i:s");
 *     amounts as plain decimals; percentages as plain numbers
 *   - formula injection neutralised (SalesExport::put())
 *   - streamed: written and flushed every 500 rows (reports are aggregates, so the
 *     rows themselves are small)
 *   - the same rows and columns as the screen (ReportData), not paged, and the
 *     totals row last
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * Report CSV.
 */
final class ReportsExport {

	const ACTION = 'pqbg_report_csv';

	/**
	 * The export URL of a report view.
	 *
	 * @param array<string, string> $args ReportsAdmin::args().
	 */
	public static function url( array $args ): string {
		return add_query_arg(
			array_map( 'rawurlencode', array_merge( array( 'action' => self::ACTION ), $args, array( '_wpnonce' => wp_create_nonce( self::ACTION ) ) ) ),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * admin_post_pqbg_report_csv.
	 */
	public static function handle(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			header( 'Allow: GET, HEAD' );
			wp_die( esc_html__( 'This export can only be downloaded.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 405 ) );
		}

		if ( ! Permissions::can_view_all_sales() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export reports.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_die( esc_html__( 'This export link has expired. Go back to In-store reports and export again.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 403 ) );
		}

		$costs = Permissions::can_view_costs();
		$ctx   = ReportsAdmin::context( wp_unslash( $_GET ), $costs ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is validated by context().

		if ( $ctx['cost_request'] && ! $costs ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to see costs and profit.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 403 ) );
		}

		if ( 'dashboard' === $ctx['tab'] ) {
			wp_die( esc_html__( 'The dashboard has no export. Open a report and export it.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 400 ) );
		}

		$data = ReportData::build( $ctx['tab'], $ctx );

		if ( is_wp_error( $data ) ) {
			wp_die( esc_html( $data->get_error_message() ), '', array( 'response' => 'pqbg_forbidden' === $data->get_error_code() ? 403 : 400 ) );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . self::filename( $ctx ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );

		if ( 'HEAD' !== $method ) {
			$out = fopen( 'php://output', 'w' );
			self::write( $out, $data, true );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output.
		}

		exit;
	}

	/**
	 * "in-store-report-products-2026-09-01-to-2026-09-26.csv" (stock reports: today's date).
	 *
	 * @param array<string, mixed> $ctx Context.
	 */
	public static function filename( array $ctx ): string {
		$p = $ctx['period'];

		if ( in_array( $ctx['tab'], array( 'stock', 'dead' ), true ) ) {
			$dates = wp_date( 'Y-m-d' );
		} else {
			$dates = $p['from'] . ( $p['from'] === $p['to'] ? '' : '-to-' . $p['to'] );
		}

		return 'in-store-report-' . $ctx['tab'] . '-' . $dates . '.csv';
	}

	/**
	 * Writes a dataset as CSV to a stream.
	 *
	 * @param resource             $out   Stream.
	 * @param array<string, mixed> $data  Dataset (ReportData::build()).
	 * @param bool                 $flush Flush every 500 rows (HTTP).
	 * @return int Rows written (without the header and totals).
	 */
	public static function write( $out, array $data, bool $flush = false ): int {
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- stream output.
		SalesExport::put( $out, self::header( $data['columns'] ) );

		$rows = self::sorted( $data );
		$n    = 0;

		foreach ( $rows as $row ) {
			SalesExport::put( $out, self::line( $data['columns'], $row ) );

			if ( $flush && 0 === ++$n % 500 ) {
				fflush( $out );
				flush();
			}
		}

		if ( null !== $data['total'] ) {
			SalesExport::put( $out, self::line( $data['columns'], $data['total'] ) );
		}

		return count( $rows );
	}

	/**
	 * The header row: labels, with the currency symbol on amount columns.
	 *
	 * @param array<string, array<string, mixed>> $columns Columns.
	 * @return string[]
	 */
	public static function header( array $columns ): array {
		$symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		$out    = array();

		foreach ( $columns as $c ) {
			$out[] = 'money' === $c['type'] ? sprintf( '%s (%s)', $c['label'], $symbol ) : ( 'pct' === $c['type'] ? sprintf( '%s (%%)', $c['label'] ) : ( in_array( $c['type'], array( 'datetime', 'date' ), true ) ? sprintf( '%s (%s)', $c['label'], wp_timezone_string() ) : $c['label'] ) );
		}

		return $out;
	}

	/**
	 * One row as CSV cells (unescaped; put() neutralises and quotes them).
	 *
	 * @param array<string, array<string, mixed>> $columns Columns.
	 * @param array<string, mixed>                $row     Row.
	 * @return string[]
	 */
	public static function line( array $columns, array $row ): array {
		$cells = array();

		foreach ( $columns as $key => $c ) {
			$v = $row[ $key ] ?? null;

			if ( null === $v ) {
				$cells[] = '';
				continue;
			}

			switch ( $c['type'] ) {
				case 'int':
					$cells[] = (string) (int) $v;
					break;
				case 'money':
					$cells[] = ReportsQuery::money( (string) $v );
					break;
				case 'pct':
					$cells[] = number_format( (float) $v, 1, '.', '' );
					break;
				case 'datetime':
					$cells[] = '' === $v ? '' : SalePresenter::datetime( (string) $v, 'Y-m-d H:i:s' );
					break;
				case 'date':
					$cells[] = '' === $v ? '' : SalePresenter::datetime( (string) $v, 'Y-m-d' );
					break;
				default:
					$cells[] = (string) $v;
			}
		}

		return $cells;
	}

	/**
	 * The rows in the screen's default order (or the one requested with orderby/order).
	 *
	 * @param array<string, mixed> $data Dataset.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sorted( array $data ): array {
		$rows = $data['rows'];

		if ( '' === $data['default_sort'] ) {
			return $rows;
		}

		$spec                = array_map( static fn( $c ) => array( $c['label'], $c['sortable'] ), $data['columns'] );
		list( $by, $order )  = ReportTable::requested_sort( $spec, (string) $data['default_sort'], (string) $data['default_order'] );

		usort(
			$rows,
			static function ( $a, $b ) use ( $by, $order ) {
				$x = $a[ $by ] ?? null;
				$y = $b[ $by ] ?? null;

				if ( null === $x || null === $y ) {
					return ( null === $x ) <=> ( null === $y );
				}

				$cmp = is_string( $x ) && ! is_numeric( $x ) ? strcasecmp( $x, (string) $y ) : ( (float) $x <=> (float) $y );

				return 'asc' === $order ? $cmp : -$cmp;
			}
		);

		return $rows;
	}
}
