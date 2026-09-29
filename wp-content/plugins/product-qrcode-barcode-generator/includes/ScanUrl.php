<?php
/**
 * Builds scan URLs: {scan base URL}/scan/{CODE}/
 *
 * This is the ONLY place scan URLs and the scan path are put together.
 * Nothing else may concatenate them. The route itself is served by ScanRoute.
 *
 * Two kinds of URL:
 *   - for_code(): the label payload, on the scan base URL setting. Its format
 *     is PERMANENT because labels are printed with it.
 *   - site_url(): the same path on this site's home_url(), used for the
 *     scan page's own redirects, form and links.
 *
 * A scan URL carries the product code and nothing else: no product name,
 * SKU, price, stock or any other product data.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Scan URL construction.
 */
final class ScanUrl {

	const PATH = 'scan';

	/**
	 * The seller's "My sales" page, {home}/scan/my-sales/ (Phase 9A). Served by the
	 * existing scan rule and compared as the raw path segment before any code check;
	 * even uppercased ("MY-SALES") it has no 3 groups of 4, so it can never be a
	 * product code (CodeGenerator::FORMAT_PATTERN), whatever the code prefix.
	 */
	const MY_SALES = 'my-sales';

	/**
	 * A sale's receipt, {home}/scan/receipt/{sale id}/ (Phase 16). Reached by the existing
	 * scan rule (its code segment is "receipt/{id}") and checked before any code check;
	 * it has no 3 groups of 4, so it can never be a product code either.
	 */
	const RECEIPT = 'receipt';

	/** Query argument of a receipt's paper layout when it is not the default one. */
	const PAPER_ARG = 'paper';

	/**
	 * The effective scan base URL (no trailing slash).
	 */
	public static function base(): string {
		return Settings::get_scan_base_url();
	}

	/**
	 * Scan URL for a product code.
	 *
	 * @param string $code Product code; must match CodeGenerator::FORMAT_PATTERN exactly.
	 * @return string|WP_Error
	 */
	public static function for_code( string $code ) {
		if ( ! CodeGenerator::is_valid_format( $code ) ) {
			return self::invalid_code_error();
		}

		return self::build( $code );
	}

	/**
	 * Example scan URL with a placeholder code in the current prefix, for display only. Never encode it.
	 */
	public static function example(): string {
		return self::build( CodeGenerator::example_code() );
	}

	/**
	 * The error every renderer returns for a string that is not a well-formed product code.
	 */
	public static function invalid_code_error(): WP_Error {
		return new WP_Error( 'pqbg_invalid_code', __( 'Invalid product code format.', 'product-qrcode-barcode-generator' ) );
	}

	/**
	 * The scan page on this site: {home_url}/scan/ or {home_url}/scan/{CODE}/.
	 *
	 * @param string $code Well-formed product code, or '' for the entry page.
	 */
	public static function site_url( string $code = '' ): string {
		if ( '' !== $code && ! CodeGenerator::is_valid_format( $code ) ) {
			$code = '';
		}

		return home_url( '/' . self::PATH . '/' . ( '' === $code ? '' : $code . '/' ) );
	}

	/**
	 * The My sales page on this site, optionally for a range other than today.
	 *
	 * @param string $range '' or 'today' (canonical, no argument), or another range key.
	 */
	public static function my_sales_url( string $range = '' ): string {
		$url = home_url( '/' . self::PATH . '/' . self::MY_SALES . '/' );

		return '' === $range || 'today' === $range ? $url : add_query_arg( 'range', rawurlencode( $range ), $url );
	}

	/**
	 * A sale's receipt page on this site (Phase 16), with ?paper= only for a layout other than the default.
	 *
	 * @param int    $sale_id Sale ID.
	 * @param string $paper   Key of Receipt::PAPERS, or '' for the default layout.
	 */
	public static function receipt_url( int $sale_id, string $paper = '' ): string {
		$url = home_url( '/' . self::PATH . '/' . self::RECEIPT . '/' . max( 1, $sale_id ) . '/' );

		return '' === $paper || Receipt::default_paper() === $paper || ! in_array( $paper, Receipt::PAPERS, true ) ? $url : add_query_arg( self::PAPER_ARG, rawurlencode( $paper ), $url );
	}

	/**
	 * Whether a raw code segment is the receipt path ("receipt" or "receipt/…").
	 *
	 * @param string|null $segment Raw code segment from the rewrite rule.
	 */
	public static function is_receipt_segment( ?string $segment ): bool {
		return null !== $segment && ( self::RECEIPT === $segment || str_starts_with( $segment, self::RECEIPT . '/' ) );
	}

	/**
	 * The sale ID in a receipt segment "receipt/{id}", or 0 when malformed.
	 *
	 * @param string $segment Raw code segment.
	 */
	public static function receipt_id( string $segment ): int {
		return 1 === preg_match( '#^' . self::RECEIPT . '/([1-9][0-9]{0,18})$#D', $segment, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Path of the entry page on this site, e.g. "/sharayu/scan/".
	 */
	public static function site_path(): string {
		return (string) wp_parse_url( self::site_url(), PHP_URL_PATH );
	}

	/**
	 * Whether a URL is a scan page on this site: same host and port as home_url(),
	 * and a path at or below the scan path. The scheme is not compared (http/https).
	 *
	 * @param mixed $url Candidate URL.
	 */
	public static function is_site_scan_url( $url ): bool {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url() );

		if ( ! is_array( $parts ) || ! is_array( $home ) || empty( $parts['host'] ) || empty( $home['host'] ) ) {
			return false;
		}

		if ( strtolower( $parts['host'] ) !== strtolower( $home['host'] ) || ( $parts['port'] ?? null ) !== ( $home['port'] ?? null ) ) {
			return false;
		}

		$path = $parts['path'] ?? '';
		$base = self::site_path();

		return $path === untrailingslashit( $base ) || str_starts_with( $path, $base );
	}

	/**
	 * Turns typed, scanned or pasted input into a candidate code: a pasted URL
	 * gives the path segment after the scan path, then all whitespace is removed
	 * and the result is uppercased. The result may still be malformed; callers
	 * check it with CodeGenerator::is_valid_format().
	 *
	 * @param string $input Raw input.
	 */
	public static function extract_code( string $input ): string {
		$input = trim( substr( $input, 0, 500 ) );

		if ( str_contains( $input, '://' ) || str_starts_with( $input, '/' ) ) {
			$path   = (string) wp_parse_url( $input, PHP_URL_PATH );
			$marker = '/' . self::PATH . '/';
			$at     = strripos( $path, $marker );

			if ( false === $at ) {
				return '';
			}

			$input = rawurldecode( (string) strtok( substr( $path, $at + strlen( $marker ) ), '/' ) );
		}

		return strtoupper( (string) preg_replace( '/\s+/u', '', $input ) );
	}

	/**
	 * Rewrite rules for the scan route, relative to the home path, in match order.
	 *
	 * @param string $route_var Query var marking a scan request.
	 * @param string $code_var  Query var receiving the raw code segment.
	 * @return array<string, string> Regex => query.
	 */
	public static function rewrite_rules( string $route_var, string $code_var ): array {
		$path = preg_quote( self::PATH, '#' );

		return array(
			'^' . $path . '/?$'        => 'index.php?' . $route_var . '=1',
			'^' . $path . '/(.+?)/?$' => 'index.php?' . $route_var . '=1&' . $code_var . '=$matches[1]',
		);
	}

	/**
	 * Joins the base URL and a code. The code is uppercase letters, digits and hyphens, so it needs no encoding.
	 *
	 * @param string $code Code or placeholder.
	 */
	private static function build( string $code ): string {
		return self::base() . '/' . self::PATH . '/' . $code . '/';
	}
}
