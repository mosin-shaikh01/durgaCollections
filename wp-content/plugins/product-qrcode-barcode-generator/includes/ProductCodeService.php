<?php
/**
 * Assigns product codes to WooCommerce items.
 *
 * Eligible items (the purchasable unit a code identifies):
 *   - simple products                          parent_id = 0
 *   - variations whose parent is variable      parent_id = variable parent ID
 * Not eligible: variable parents (they only contain variations), grouped
 * and external products, orphaned variations, and any other or custom
 * product type.
 *
 * Status rule (Phase 5): auto-drafts never get a code, and neither do
 * variations of an auto-draft parent. Drafts, pending, private and
 * published items are eligible.
 *
 * This service is the authorization boundary for assigning and replacing
 * codes: the acting user must have Permissions::MANAGE_CODES. The one
 * exception is retire_for_item(), the lifecycle path used when WordPress
 * deletes an item or it stops being eligible (see CodeLifecycle).
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

	/** Status of a product WordPress created for the "Add new" screen that has never been saved. */
	const AUTO_DRAFT = 'auto-draft';

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

		if ( self::AUTO_DRAFT === $product->get_status() ) {
			return self::auto_draft();
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
				if ( self::AUTO_DRAFT === $parent->get_status() ) {
					return self::auto_draft();
				}

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
	 * Replaces the item's active code with a newly generated one, atomically
	 * (see CodeRepository::replace_active()). The old code is retired with
	 * retired_at/retired_by and is never reused. Printed labels that carry the
	 * old code stop working.
	 *
	 * @param int      $product_id  Simple product or variation ID.
	 * @param int      $user_id     Acting user; must have the pqbg_manage_codes capability.
	 * @param int|null $expected_id The active code row ID the caller saw; if the item's
	 *                              code changed since, nothing happens (pqbg_code_changed).
	 * @return array<string, string>|WP_Error The new active pqbg_codes row.
	 */
	public function regenerate( int $product_id, int $user_id, ?int $expected_id = null ) {
		if ( ! Permissions::can_manage_codes( $user_id ) ) {
			return new WP_Error( 'pqbg_forbidden', __( 'You are not allowed to manage product codes.', 'product-qrcode-barcode-generator' ) );
		}

		$item = self::eligibility( $product_id );

		if ( is_wp_error( $item ) ) {
			return $item;
		}

		for ( $attempt = 1; $attempt <= self::MAX_SAVE_ATTEMPTS; $attempt++ ) {
			$code = $this->generator->generate_unique();

			if ( is_wp_error( $code ) ) {
				self::log_failure( $code );
				return $code;
			}

			$result = CodeRepository::replace_active( $item['product_id'], $item['parent_id'], $code, $user_id, $expected_id );

			if ( ! is_wp_error( $result ) ) {
				$row = CodeRepository::find_by_code( $code );

				return null !== $row ? $row : new WP_Error( 'pqbg_code_unavailable', __( 'The product code could not be loaded.', 'product-qrcode-barcode-generator' ) );
			}

			// Only a duplicate code string is worth retrying with a new code; the rollback kept the old code active.
			if ( 'pqbg_code_conflict' !== $result->get_error_code() ) {
				return $result;
			}
		}

		$error = new WP_Error( 'pqbg_code_generation_failed', __( 'A unique product code could not be generated.', 'product-qrcode-barcode-generator' ) );
		self::log_failure( $error );

		return $error;
	}

	/**
	 * Retires the item's active code, if it has one. Lifecycle path only: used
	 * when WordPress permanently deletes the item or it stops being eligible
	 * (type change). Deliberately NOT gated by pqbg_manage_codes: WordPress has
	 * already authorised the delete/save, and a deleted or ineligible item must
	 * never keep an active code. It never creates codes.
	 *
	 * @param int $product_id Product or variation ID (the item may no longer exist).
	 * @param int $user_id    Acting user, or 0 when there is none (cron, CLI).
	 * @return bool Whether a code was retired.
	 */
	public static function retire_for_item( int $product_id, int $user_id ): bool {
		$row = CodeRepository::find_active_for_product( $product_id );

		if ( null === $row ) {
			return false;
		}

		return true === CodeRepository::retire( (int) $row['id'], max( 0, $user_id ) );
	}

	/**
	 * Error for an item that has never been saved.
	 */
	private static function auto_draft(): WP_Error {
		return new WP_Error( 'pqbg_ineligible_status', __( 'Save the product first; unsaved products do not get a product code.', 'product-qrcode-barcode-generator' ) );
	}

	/**
	 * Logs a failure by error code only (public for CodeLifecycle).
	 *
	 * @param WP_Error $error Failure.
	 */
	public static function log_error( WP_Error $error ): void {
		self::log_failure( $error );
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
