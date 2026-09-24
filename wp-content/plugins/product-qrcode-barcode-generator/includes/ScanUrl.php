<?php
/**
 * Builds scan URLs: {scan base URL}/scan/{CODE}/
 *
 * This is the ONLY place scan URLs are put together. Nothing else may
 * concatenate them. It only builds URLs: the /scan/ route and scan page
 * belong to a later phase, so these URLs return 404 for now.
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

	/** Placeholder shown in the admin UI in place of a real code. */
	const EXAMPLE_CODE = 'DC-XXXX-XXXX-XXXX';

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
	 * Example scan URL with a placeholder code, for display only. Never encode it.
	 */
	public static function example(): string {
		return self::build( self::EXAMPLE_CODE );
	}

	/**
	 * The error every renderer returns for a string that is not a well-formed product code.
	 */
	public static function invalid_code_error(): WP_Error {
		return new WP_Error( 'pqbg_invalid_code', __( 'Invalid product code format.', 'product-qrcode-barcode-generator' ) );
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
