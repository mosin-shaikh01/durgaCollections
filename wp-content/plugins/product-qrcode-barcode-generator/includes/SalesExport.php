<?php
/**
 * CSV export of the sales history's current view (Phase 9A).
 *
 * GET admin-post.php?action=pqbg_sales_csv&_wpnonce=…&{the history's filters}
 *   - pqbg_view_all_sales and a nonce; read-only (no writes of any kind)
 *   - UTF-8 with a byte order mark, so Excel shows ₹ and non-Latin names correctly
 *   - dates in the site timezone ("Y-m-d H:i:s"); amounts as plain decimals
 *   - unit cost, cost and profit columns only for pqbg_view_costs
 *   - spreadsheet formula injection neutralised: a text cell starting with
 *     = + - @, a tab or a carriage return is prefixed with an apostrophe. Plain
 *     numbers we generate (e.g. a negative profit "-120.00") stay numbers.
 *   - streamed: rows are read SalesQuery::CHUNK at a time and flushed, so the
 *     export never holds the whole set in memory
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * Sales CSV.
 */
final class SalesExport {

	const ACTION = 'pqbg_sales_csv';

	/**
	 * The export URL for a filter set.
	 *
	 * @param array<string, mixed> $filters Filters from SalesQuery::filters().
	 */
	public static function url( array $filters ): string {
		return add_query_arg(
			array_map( 'rawurlencode', array_merge( array( 'action' => self::ACTION ), SalesQuery::args( $filters ), array( '_wpnonce' => wp_create_nonce( self::ACTION ) ) ) ),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * admin_post_pqbg_sales_csv.
	 */
	public static function handle(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			header( 'Allow: GET, HEAD' );
			wp_die( esc_html__( 'This export can only be downloaded.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 405 ) );
		}

		if ( ! Permissions::can_view_all_sales() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export sales.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_die( esc_html__( 'This export link has expired. Go back to In-store sales and export again.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 403 ) );
		}

		$filters = SalesQuery::filters( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is validated by SalesQuery::filters().
		$name    = 'in-store-sales-' . $filters['range']['from'] . ( $filters['range']['from'] === $filters['range']['to'] ? '' : '-to-' . $filters['range']['to'] ) . '.csv';

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );

		if ( 'HEAD' !== $method ) {
			$out = fopen( 'php://output', 'w' );
			self::write( $out, $filters, Permissions::can_view_costs(), true );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output.
		}

		exit;
	}

	/**
	 * Writes the CSV for a filter set to a stream.
	 *
	 * @param resource             $out     Stream.
	 * @param array<string, mixed> $filters Filters.
	 * @param bool                 $costs   Include cost and profit.
	 * @param bool                 $flush   Flush the output after each chunk (HTTP).
	 * @return int Rows written (without the header).
	 */
	public static function write( $out, array $filters, bool $costs, bool $flush = false ): int {
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- stream output.
		self::put( $out, self::header( $costs ) );

		return SalesQuery::each_chunk(
			$filters,
			static function ( array $rows ) use ( $out, $costs, $flush ): void {
				foreach ( $rows as $row ) {
					self::put( $out, self::line( $row, $costs ) );
				}

				if ( $flush ) {
					fflush( $out );
					flush();
				}
			}
		);
	}

	/**
	 * The header row.
	 *
	 * @param bool $costs Include cost and profit.
	 * @return string[]
	 */
	public static function header( bool $costs ): array {
		$symbol  = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		/* translators: %s: currency symbol. */
		$money   = static fn( string $label ): string => sprintf( '%s (%s)', $label, $symbol );
		$columns = array(
			/* translators: %s: site timezone, e.g. Asia/Kolkata. */
			sprintf( __( 'Date (%s)', 'product-qrcode-barcode-generator' ), wp_timezone_string() ),
			__( 'Sale #', 'product-qrcode-barcode-generator' ),
			__( 'Status', 'product-qrcode-barcode-generator' ),
			__( 'Product', 'product-qrcode-barcode-generator' ),
			__( 'Attributes', 'product-qrcode-barcode-generator' ),
			__( 'SKU', 'product-qrcode-barcode-generator' ),
			__( 'Code', 'product-qrcode-barcode-generator' ),
			__( 'Quantity', 'product-qrcode-barcode-generator' ),
			$money( __( 'Unit price', 'product-qrcode-barcode-generator' ) ),
			$money( __( 'Total', 'product-qrcode-barcode-generator' ) ),
			__( 'Currency', 'product-qrcode-barcode-generator' ),
			__( 'Paid by', 'product-qrcode-barcode-generator' ),
			__( 'Seller', 'product-qrcode-barcode-generator' ),
			__( 'Voided at', 'product-qrcode-barcode-generator' ),
			__( 'Voided by', 'product-qrcode-barcode-generator' ),
			__( 'Void reason', 'product-qrcode-barcode-generator' ),
			__( 'Failure', 'product-qrcode-barcode-generator' ),
		);

		if ( $costs ) {
			$columns[] = $money( __( 'Unit cost', 'product-qrcode-barcode-generator' ) );
			$columns[] = $money( __( 'Cost', 'product-qrcode-barcode-generator' ) );
			$columns[] = $money( __( 'Profit', 'product-qrcode-barcode-generator' ) );
		}

		return $columns;
	}

	/**
	 * One sale as CSV cells (unescaped; put() neutralises and quotes them).
	 *
	 * @param array<string, mixed> $row   Sale row (with "code").
	 * @param bool                 $costs Include cost and profit.
	 * @return string[]
	 */
	public static function line( array $row, bool $costs ): array {
		// Per-row work is kept cheap (50,000 rows): labels are looked up once per value,
		// amounts use number_format() and dates one timezone conversion.
		static $cache = null;

		if ( null === $cache || $cache['tz'] !== wp_timezone_string() ) {
			$cache = array(
				'tz'       => wp_timezone_string(),
				'zone'     => wp_timezone(),
				'utc'      => new \DateTimeZone( 'UTC' ),
				'decimals' => wc_get_price_decimals(),
				'labels'   => array(),
			);
		}

		$decimals = $cache['decimals'];
		$amount   = static function ( $value ) use ( $decimals ): string {
			if ( null === $value || '' === $value ) {
				return '';
			}

			$number = round( (float) $value, $decimals );

			return number_format( 0.0 === $number ? 0.0 : $number, $decimals, '.', '' ); // No "-0.00".
		};
		$date     = static fn( $gmt ): string => null === $gmt || '' === $gmt ? '' : ( new \DateTimeImmutable( (string) $gmt, $cache['utc'] ) )->setTimezone( $cache['zone'] )->format( 'Y-m-d H:i:s' );
		$label    = static function ( string $kind, string $key, callable $make ) use ( &$cache ): string {
			if ( ! isset( $cache['labels'][ $kind ][ $key ] ) ) {
				$cache['labels'][ $kind ][ $key ] = (string) $make();
			}

			return $cache['labels'][ $kind ][ $key ];
		};
		$status   = (string) $row['status'];
		$voided   = SaleRepository::STATUS_VOIDED === $status;
		$cells    = array(
			$date( $row['created_at_gmt'] ),
			(string) $row['id'],
			$label( 'status', $status, static fn() => SalePresenter::status( $status ) ),
			(string) $row['product_name'],
			null === $row['attributes_json'] || '' === $row['attributes_json'] ? '' : SalePresenter::attributes( $row ),
			(string) $row['sku'],
			(string) ( $row['code'] ?? '' ),
			(string) (int) $row['quantity'],
			$amount( $row['unit_price'] ),
			$amount( $row['line_total'] ),
			(string) $row['currency'],
			$label( 'method', (string) $row['payment_method'], static fn() => PaymentMethods::label( $row['payment_method'] ) ),
			$label( 'seller', $row['seller_id'] . '|' . $row['seller_name'], static fn() => SalePresenter::seller( $row ) ),
			$voided ? $date( $row['voided_at_gmt'] ) : '',
			$voided ? $label( 'user', (string) $row['voided_by'], static fn() => SalePresenter::user_label( (int) $row['voided_by'] ) ) : '',
			$voided ? (string) $row['void_reason'] : '',
			(string) $row['failure_code'],
		);

		if ( $costs ) {
			$known   = null !== $row['unit_cost'];
			$cost    = $known ? round( (float) $row['unit_cost'] * (int) $row['quantity'], $decimals ) : null;
			$cells[] = $amount( $row['unit_cost'] );
			$cells[] = $known ? $amount( $cost ) : '';
			$cells[] = $known && SaleRepository::STATUS_COMPLETED === $status ? $amount( (float) $row['line_total'] - $cost ) : '';
		}

		return $cells;
	}

	/**
	 * Neutralises a cell against spreadsheet formula injection: a cell starting with
	 * = + - @, a tab or a carriage return gets a leading apostrophe, unless it is a
	 * plain decimal number.
	 *
	 * @param string $cell Cell.
	 */
	public static function neutralise( string $cell ): string {
		if ( 1 === preg_match( '/^-?[0-9]+(\.[0-9]+)?$/D', $cell ) ) {
			return $cell;
		}

		return '' !== $cell && false !== strpos( "=+-@\t\r", $cell[0] ) ? "'" . $cell : $cell;
	}

	/**
	 * Writes one CSV line.
	 *
	 * @param resource $out   Stream.
	 * @param string[] $cells Cells.
	 */
	private static function put( $out, array $cells ): void {
		fputcsv( $out, array_map( array( __CLASS__, 'neutralise' ), $cells ), ',', '"', '' );
	}
}
