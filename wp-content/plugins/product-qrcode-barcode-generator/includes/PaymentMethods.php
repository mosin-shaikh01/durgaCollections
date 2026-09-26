<?php
/**
 * How the customer paid for an in-store sale (Phase 9A).
 *
 * Internal keys are fixed and stored in pqbg_sales.payment_method:
 *   cash, upi, card, other
 * NULL in that column means "not recorded" (sales made before schema version 3).
 *
 * Administrators choose which methods the sale form offers (the payment_methods
 * setting, at least one). The sale form's radio group is required and nothing
 * is pre-selected unless exactly one method is enabled. The method is validated
 * against the enabled list at the moment of sale (SaleRequest and SaleService).
 *
 * Split or mixed payments are not supported: one sale has one method.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * Payment method keys, labels and the enabled list.
 */
final class PaymentMethods {

	const CASH  = 'cash';
	const UPI   = 'upi';
	const CARD  = 'card';
	const OTHER = 'other';

	/** Methods enabled on a new install. */
	const DEFAULT_ENABLED = array( self::CASH, self::UPI, self::CARD );

	/**
	 * Every method in display order, key => label.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			self::CASH  => __( 'Cash', 'product-qrcode-barcode-generator' ),
			self::UPI   => __( 'UPI', 'product-qrcode-barcode-generator' ),
			self::CARD  => __( 'Card', 'product-qrcode-barcode-generator' ),
			self::OTHER => __( 'Other', 'product-qrcode-barcode-generator' ),
		);
	}

	/**
	 * Keys of the methods the sale form offers now, in display order. Never empty:
	 * a missing or broken setting falls back to the defaults.
	 *
	 * @return string[]
	 */
	public static function enabled(): array {
		$stored = Settings::get()['payment_methods'];
		$keys   = self::clean( is_array( $stored ) ? $stored : array() );

		return array() === $keys ? self::DEFAULT_ENABLED : $keys;
	}

	/**
	 * Whether a submitted value is a method the sale form offers now.
	 *
	 * @param mixed $method Submitted value.
	 */
	public static function is_enabled( $method ): bool {
		return is_string( $method ) && in_array( $method, self::enabled(), true );
	}

	/**
	 * Known keys from a list, without duplicates, in display order.
	 *
	 * @param array<int|string, mixed> $keys Candidate keys.
	 * @return string[]
	 */
	public static function clean( array $keys ): array {
		return array_values( array_intersect( array_keys( self::all() ), array_filter( $keys, 'is_string' ) ) );
	}

	/**
	 * Label for a stored value: the method's label, "Not recorded" for NULL/'',
	 * or the raw value for an unknown key (never expected; escaped by callers).
	 *
	 * @param string|null $method Stored value.
	 */
	public static function label( ?string $method ): string {
		if ( null === $method || '' === $method ) {
			return __( 'Not recorded', 'product-qrcode-barcode-generator' );
		}

		return self::all()[ $method ] ?? $method;
	}
}
