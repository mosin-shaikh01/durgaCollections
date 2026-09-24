<?php
/**
 * Assigns product codes to WooCommerce items.
 *
 * Eligible items (the purchasable unit a code identifies):
 *   - simple products                          parent_id = 0
 *   - variations whose parent is variable      parent_id = variable parent ID
 * Not eligible: variable parents (they only contain variations), grouped
 * and external products, orphaned variations, and any other or custom
 * product type. Product status is not checked; no status rule has been
 * decided yet.
 *
 * This service is the authorization boundary for assigning codes: the
 * acting user must have Permissions::MANAGE_CODES. It is not called from
 * any hook, endpoint or screen yet.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WC_Product;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Product eligibility and code assignment.
 */
final class ProductCodeService {

	/**
	 * How many times to retry saving after the database rejects a code as a
	 * duplicate. This only happens when another request saves the same code
	 * between the uniqueness check and the insert.
	 */
	const MAX_SAVE_ATTEMPTS = 3;

	/**
	 * Code source.
	 *
	 * @var CodeGenerator
	 */
	private $generator;

	/**
	 * @param CodeGenerator|null $generator Code source. Defaults to a CSPRNG-backed generator.
	 */
	public function __construct( ?CodeGenerator $generator = null ) {
		$this->generator = $generator ?? new CodeGenerator();
	}

	/**
	 * Resolves an ID to an eligible item.
	 *
	 * @param int $product_id Simple product or variation ID.
	 * @return array{product_id: int, parent_id: int}|WP_Error
	 */
	public static function eligibility( int $product_id ) {
		// wc_get_product( 0 ) would fall back to the global post, so reject non-positive IDs first.
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return self::invalid_product();
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product || $product->get_id() !== $product_id ) {
			return self::invalid_product();
		}

		if ( $product->is_type( 'simple' ) ) {
			return array(
				'product_id' => $product_id,
				'parent_id'  => 0,
			);
		}

		if ( $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();
			$parent    = $parent_id > 0 ? wc_get_product( $parent_id ) : false;

			if ( $parent instanceof WC_Product && $parent->is_type( 'variable' ) ) {
				return array(
					'product_id' => $product_id,
					'parent_id'  => $parent_id,
				);
			}
		}

		return new WP_Error( 'pqbg_ineligible_product', __( 'This product type cannot have a product code.', 'product-qrcode-barcode-generator' ) );
	}

	/**
	 * Whether an item can have a product code.
	 *
	 * @param int $product_id Product or variation ID.
	 */
	public static function is_eligible( int $product_id ): bool {
		return ! is_wp_error( self::eligibility( $product_id ) );
	}

	/**
	 * Returns the item's active code row, creating one if it has none.
	 *
	 * An existing active code is always returned unchanged; this never makes
	 * a second active code. A retired code is never reactivated or reused.
	 * An item whose code was retired gets a newly generated code.
	 *
	 * @param int $product_id Simple product or variation ID.
	 * @param int $user_id    Acting user; must have the pqbg_manage_codes capability.
	 * @return array<string, string>|WP_Error The active pqbg_codes row.
	 */
	public function get_or_create( int $product_id, int $user_id ) {
		if ( ! Permissions::can_manage_codes( $user_id ) ) {
			return new WP_Error( 'pqbg_forbidden', __( 'You are not allowed to manage product codes.', 'product-qrcode-barcode-generator' ) );
		}

		$item = self::eligibility( $product_id );

		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$existing = CodeRepository::find_active_for_product( $item['product_id'] );

		if ( null !== $existing ) {
			return $existing;
		}

		for ( $attempt = 1; $attempt <= self::MAX_SAVE_ATTEMPTS; $attempt++ ) {
			$code = $this->generator->generate_unique();

			if ( is_wp_error( $code ) ) {
				self::log_failure( $code );
				return $code;
			}

			$result = CodeRepository::create_active( $code, $item['product_id'], $item['parent_id'], $user_id );

			if ( ! is_wp_error( $result ) ) {
				$row = CodeRepository::find_by_code( $code );

				return null !== $row ? $row : new WP_Error( 'pqbg_code_unavailable', __( 'The product code could not be loaded.', 'product-qrcode-barcode-generator' ) );
			}

			// A concurrent request assigned this item a code first: use that one.
			$existing = CodeRepository::find_active_for_product( $item['product_id'] );

			if ( null !== $existing ) {
				return $existing;
			}

			// Only a duplicate code string is worth retrying with a new code.
			if ( 'pqbg_code_conflict' !== $result->get_error_code() ) {
				return $result;
			}
		}

		$error = new WP_Error( 'pqbg_code_generation_failed', __( 'A unique product code could not be generated.', 'product-qrcode-barcode-generator' ) );
		self::log_failure( $error );

		return $error;
	}

	/**
	 * Error for an ID that is not a WooCommerce product.
	 */
	private static function invalid_product(): WP_Error {
		return new WP_Error( 'pqbg_invalid_product', __( 'Product not found.', 'product-qrcode-barcode-generator' ) );
	}

	/**
	 * Logs a generation failure. Repeated collisions mean a broken random source or bad data.
	 *
	 * @param WP_Error $error Failure.
	 */
	private static function log_failure( WP_Error $error ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( 'Product code generation failed: ' . $error->get_error_code(), array( 'source' => 'product-qrcode-barcode-generator' ) );
		}
	}
}
