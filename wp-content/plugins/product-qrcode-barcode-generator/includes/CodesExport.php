<?php
/**
 * CSV export of product codes (Phase 10, decisions D4 and D5).
 *
 * GET admin-post.php?action=pqbg_codes_csv&_wpnonce=…&code_status=…&missing=…&product_status=…&type=…
 *   - pqbg_manage_codes and a nonce; GET/HEAD only; read-only apart from one
 *     audit-log entry (BulkLog) per download
 *   - one row per code (active, retired or both), and optionally one row per
 *     qualifying item without a code (BulkGenerator's rules) at the end
 *   - scan URL only for ACTIVE codes, built only by ScanUrl::for_code(); retired
 *     codes get none, so nobody prints a dead label from this file
 *   - never any cost data, for anyone
 *   - the Phase 9A CSV rules: UTF-8 with a byte order mark, dates in the site
 *     timezone, formula injection neutralised (SalesExport::put()). SKUs are
 *     written exactly as stored ("00123" stays "00123"; Excel removes leading zeros
 *     only when a CSV is opened by double-click, see the README)
 *   - streamed CHUNK rows at a time
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * Codes CSV.
 */
final class CodesExport {

	const ACTION = 'pqbg_codes_csv';

	const CHUNK = 500;

	const CODE_STATUSES = array( 'active', 'retired', 'all' );

	const TYPES = array( 'simple', 'variation' );

	/** Product status filter values besides "any" (''): the qualifying statuses, trash, and deleted items. */
	const PRODUCT_STATUSES = array( 'publish', 'private', 'draft', 'pending', 'future', 'trash', 'deleted' );

	/**
	 * Validated filters from request input.
	 *
	 * @param array<string, mixed> $input Request values.
	 * @return array{code_status: string, missing: bool, product_status: string, type: string}
	 */
	public static function filters( array $input ): array {
		$pick = static fn( string $key, array $allowed, string $default ) => isset( $input[ $key ] ) && is_string( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ? $input[ $key ] : $default;

		return array(
			'code_status'    => $pick( 'code_status', self::CODE_STATUSES, 'active' ),
			'missing'        => isset( $input['missing'] ) && '1' === $input['missing'],
			'product_status' => $pick( 'product_status', self::PRODUCT_STATUSES, '' ),
			'type'           => $pick( 'type', self::TYPES, '' ),
		);
	}

	/**
	 * admin_post_pqbg_codes_csv.
	 */
	public static function handle(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			header( 'Allow: GET, HEAD' );
			wp_die( esc_html__( 'This export can only be downloaded.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 405 ) );
		}

		if ( ! Permissions::can_manage_codes() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export product codes.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_die( esc_html__( 'This export link has expired. Go back to Code tools and export again.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 403 ) );
		}

		$filters = self::filters( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is checked against a fixed list by filters().

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="product-codes-' . wp_date( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );

		if ( 'HEAD' !== $method ) {
			$out   = fopen( 'php://output', 'w' );
			$start = microtime( true );
			$rows  = self::write( $out, $filters, true );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output.

			BulkLog::add(
				BulkLog::TOOL_CODES_EXPORT,
				array(
					'rows'           => $rows,
					'code_status'    => $filters['code_status'],
					'missing'        => $filters['missing'] ? 1 : 0,
					'product_status' => '' === $filters['product_status'] ? 'any' : $filters['product_status'],
					'type'           => '' === $filters['type'] ? 'any' : $filters['type'],
					'seconds'        => (int) round( microtime( true ) - $start ),
				)
			);
		}

		exit;
	}

	/**
	 * Writes the CSV to a stream.
	 *
	 * @param resource                                                                   $out     Stream.
	 * @param array{code_status: string, missing: bool, product_status: string, type: string} $filters Filters.
	 * @param bool                                                                       $flush   Flush after each chunk (HTTP).
	 * @return int Rows written (without the header).
	 */
	public static function write( $out, array $filters, bool $flush = false ): int {
		global $wpdb;

		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- stream output.
		SalesExport::put( $out, self::header() );

		$codes = Schema::codes_table();
		$count = 0;
		$where = array();

		if ( 'all' !== $filters['code_status'] ) {
			$where[] = $wpdb->prepare( 'c.status = %s', $filters['code_status'] );
		}

		if ( '' !== $filters['type'] ) {
			$where[] = 'simple' === $filters['type'] ? 'c.parent_id = 0' : 'c.parent_id > 0';
		}

		// Status of the item: its own for a simple product, the parent's for a variation; "deleted" when the item is gone.
		$status_sql = "CASE WHEN p.ID IS NULL THEN 'deleted' WHEN c.parent_id > 0 THEN COALESCE(pp.post_status, 'deleted') ELSE p.post_status END";

		if ( '' !== $filters['product_status'] ) {
			$where[] = $wpdb->prepare( "{$status_sql} = %s", $filters['product_status'] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed SQL.
		}

		$extra = array() === $where ? '' : ' AND ' . implode( ' AND ', $where );
		$after = 0;

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- fixed identifiers; filters prepared above.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT c.id, c.code, c.status, c.product_id, c.parent_id, c.created_at_gmt, c.retired_at_gmt FROM {$codes} c LEFT JOIN {$wpdb->posts} p ON p.ID = c.product_id LEFT JOIN {$wpdb->posts} pp ON pp.ID = c.parent_id AND c.parent_id > 0 WHERE c.id > %d{$extra} ORDER BY c.id LIMIT %d", $after, self::CHUNK ), ARRAY_A );

			if ( array() === $rows ) {
				break;
			}

			$after = (int) end( $rows )['id'];
			self::prime( array_merge( array_column( $rows, 'product_id' ), array_column( $rows, 'parent_id' ) ) );

			foreach ( $rows as $row ) {
				SalesExport::put( $out, self::line( (int) $row['product_id'], (int) $row['parent_id'], $row ) );
				++$count;
			}

			self::flush( $out, $flush );
		} while ( count( $rows ) === self::CHUNK );

		if ( $filters['missing'] ) {
			$statuses = '' === $filters['product_status'] ? BulkGenerator::STATUSES : array_intersect( BulkGenerator::STATUSES, array( $filters['product_status'] ) );
			$after    = 0;

			while ( array() !== $statuses ) {
				$rows = BulkGenerator::missing( $statuses, $after, self::CHUNK );

				if ( array() === $rows ) {
					break;
				}

				$after = (int) end( $rows )['id'];
				$rows  = '' === $filters['type'] ? $rows : array_filter( $rows, static fn( $r ) => $r['type'] === $filters['type'] );
				$ids   = array_map( static fn( $r ) => (int) $r['id'], $rows );
				self::prime( array_merge( $ids, array_map( 'wp_get_post_parent_id', $ids ) ) );

				foreach ( $rows as $row ) {
					$id = (int) $row['id'];
					SalesExport::put( $out, self::line( $id, 'variation' === $row['type'] ? (int) wp_get_post_parent_id( $id ) : 0, null ) );
					++$count;
				}

				self::flush( $out, $flush );
			}
		}

		return $count;
	}

	/**
	 * The header row.
	 *
	 * @return string[]
	 */
	public static function header(): array {
		$tz = wp_timezone_string();

		return array(
			__( 'Item ID', 'product-qrcode-barcode-generator' ),
			__( 'Parent ID', 'product-qrcode-barcode-generator' ),
			__( 'Type', 'product-qrcode-barcode-generator' ),
			__( 'SKU', 'product-qrcode-barcode-generator' ),
			__( 'Product', 'product-qrcode-barcode-generator' ),
			__( 'Attributes', 'product-qrcode-barcode-generator' ),
			__( 'Product status', 'product-qrcode-barcode-generator' ),
			__( 'Code', 'product-qrcode-barcode-generator' ),
			__( 'Code status', 'product-qrcode-barcode-generator' ),
			__( 'Scan URL', 'product-qrcode-barcode-generator' ),
			/* translators: %s: site timezone, e.g. Asia/Kolkata. */
			sprintf( __( 'Code created (%s)', 'product-qrcode-barcode-generator' ), $tz ),
			/* translators: %s: site timezone, e.g. Asia/Kolkata or +05:30. */
			sprintf( __( 'Retired at (%s)', 'product-qrcode-barcode-generator' ), $tz ),
		);
	}

	/**
	 * One item as CSV cells (unescaped; SalesExport::put() neutralises and quotes them).
	 *
	 * @param int                        $id        Item (simple product or variation).
	 * @param int                        $parent_id Variation's parent, or 0.
	 * @param array<string, string>|null $code      Code row, or null for an item without a code.
	 * @return string[]
	 */
	public static function line( int $id, int $parent_id, ?array $code ): array {
		$post   = get_post( $id );
		$post   = $post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ? $post : null;
		$parent = $parent_id > 0 ? get_post( $parent_id ) : null;
		$active = null !== $code && CodeRepository::STATUS_ACTIVE === $code['status'];
		$url    = $active ? ScanUrl::for_code( (string) $code['code'] ) : '';
		$name   = $parent_id > 0 ? ( $parent ? $parent->post_title : '' ) : ( $post ? $post->post_title : '' );

		return array(
			(string) $id,
			(string) $parent_id,
			$parent_id > 0 ? 'variation' : 'simple',
			$post ? (string) get_post_meta( $id, '_sku', true ) : '',
			'' === $name ? __( '(deleted)', 'product-qrcode-barcode-generator' ) : html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ),
			$post && $parent_id > 0 ? self::attributes( $id ) : '',
			self::status_label( $post, $parent, $parent_id > 0 ),
			null === $code ? '' : (string) $code['code'],
			null === $code ? 'none' : (string) $code['status'],
			is_string( $url ) ? $url : '',
			null === $code ? '' : self::local( $code['created_at_gmt'] ?? null ),
			null === $code ? '' : self::local( $code['retired_at_gmt'] ?? null ),
		);
	}

	/**
	 * A variation's attributes as "Size: M, Colour: Red": the attribute summary WooCommerce keeps
	 * in the variation's post_excerpt (no product object needed), else built from the variation.
	 * Also used by the cost template (CostImport).
	 *
	 * @param int $id Variation.
	 */
	public static function attributes( int $id ): string {
		$post = get_post( $id );

		if ( $post && '' !== trim( (string) $post->post_excerpt ) ) {
			return html_entity_decode( wp_strip_all_tags( (string) $post->post_excerpt ), ENT_QUOTES, 'UTF-8' );
		}

		$variation = wc_get_product( $id );

		return $variation instanceof \WC_Product_Variation ? html_entity_decode( wp_strip_all_tags( wc_get_formatted_variation( $variation, true, true, false ) ), ENT_QUOTES, 'UTF-8' ) : '';
	}

	/**
	 * The item's product status label (the parent's for a variation, plus "variation disabled").
	 *
	 * @param \WP_Post|null $post         Item.
	 * @param \WP_Post|null $parent       Parent.
	 * @param bool          $is_variation Whether the item is a variation.
	 */
	private static function status_label( $post, $parent, bool $is_variation ): string {
		if ( ! $post || ( $is_variation && ! $parent ) ) {
			return __( 'Deleted', 'product-qrcode-barcode-generator' );
		}

		$labels = array(
			'publish' => __( 'Published', 'product-qrcode-barcode-generator' ),
			'private' => __( 'Private', 'product-qrcode-barcode-generator' ),
			'draft'   => __( 'Draft', 'product-qrcode-barcode-generator' ),
			'pending' => __( 'Pending review', 'product-qrcode-barcode-generator' ),
			'future'  => __( 'Scheduled', 'product-qrcode-barcode-generator' ),
			'trash'   => __( 'Trash', 'product-qrcode-barcode-generator' ),
		);
		$status = $is_variation ? $parent->post_status : $post->post_status;
		$label  = $labels[ $status ] ?? $status;

		if ( $is_variation && 'trash' === $post->post_status ) {
			return $labels['trash'];
		}

		return $is_variation && 'private' === $post->post_status ? $label . '; ' . __( 'variation disabled', 'product-qrcode-barcode-generator' ) : $label;
	}

	/**
	 * A UTC datetime in the site timezone ("Y-m-d H:i:s"), or ''.
	 *
	 * @param string|null $gmt UTC datetime.
	 */
	private static function local( ?string $gmt ): string {
		if ( null === $gmt || '' === $gmt || '0000-00-00 00:00:00' === $gmt ) {
			return '';
		}

		return ( new \DateTimeImmutable( $gmt, new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Loads posts and their meta for a chunk in a few queries.
	 *
	 * @param array<int, int|string> $ids Post IDs.
	 */
	private static function prime( array $ids ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( array() !== $ids ) {
			_prime_post_caches( $ids, false, true );
		}
	}

	/**
	 * Flushes a chunk to the client (HTTP) and frees the object cache the chunk filled.
	 *
	 * @param resource $out   Stream.
	 * @param bool     $flush Whether to flush.
	 */
	private static function flush( $out, bool $flush ): void {
		if ( $flush ) {
			fflush( $out );
			flush();
			wp_cache_flush_runtime();
		}
	}
}
