<?php
/**
 * Labels and plain-text formatting of sale rows, shared by the sales history,
 * the sale detail, the CSV export and My sales (Phase 9A). Every value returned
 * is plain text; callers escape it for their output (HTML or CSV).
 *
 * Seller shown for a sale: the name snapshot taken at the moment of sale
 * (seller_name); for older rows without one, the user's current display name.
 * A deleted user is shown as "Name (deleted user)", or "User #ID (deleted)"
 * when there is no snapshot. voided_by = 0 is "System".
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * Sale row presentation.
 */
final class SalePresenter {

	/** @var array<int, \WP_User|false> Users looked up during this request (deleted users included). */
	private static array $users = array();

	/**
	 * Status label.
	 *
	 * @param string $status Row status.
	 */
	public static function status( string $status ): string {
		$labels = array(
			SaleRepository::STATUS_COMPLETED => __( 'Completed', 'product-qrcode-barcode-generator' ),
			SaleRepository::STATUS_VOIDED    => __( 'Voided', 'product-qrcode-barcode-generator' ),
			SaleRepository::STATUS_FAILED    => __( 'Failed', 'product-qrcode-barcode-generator' ),
			SaleRepository::STATUS_PENDING   => __( 'In progress', 'product-qrcode-barcode-generator' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * The seller of a sale (see the class notes).
	 *
	 * @param array<string, mixed> $sale Row.
	 */
	public static function seller( array $sale ): string {
		$id   = (int) $sale['seller_id'];
		$user = self::user( $id );
		$name = isset( $sale['seller_name'] ) && null !== $sale['seller_name'] && '' !== $sale['seller_name'] ? (string) $sale['seller_name'] : ( $user ? (string) $user->display_name : '' );

		if ( $user ) {
			return $name;
		}

		return '' !== $name
			/* translators: %s: seller name at the time of sale. */
			? sprintf( __( '%s (deleted user)', 'product-qrcode-barcode-generator' ), $name )
			/* translators: %d: user ID. */
			: sprintf( __( 'User #%d (deleted)', 'product-qrcode-barcode-generator' ), $id );
	}

	/**
	 * A user by ID for "voided by": "System" for 0, "User #ID (deleted)" when gone.
	 *
	 * @param int $user_id User ID.
	 */
	public static function user_label( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return __( 'System', 'product-qrcode-barcode-generator' );
		}

		$user = self::user( $user_id );

		/* translators: %d: user ID. */
		return $user ? (string) $user->display_name : sprintf( __( 'User #%d (deleted)', 'product-qrcode-barcode-generator' ), $user_id );
	}

	/**
	 * Product name, with the variation's attributes: "Kurta – Size: M, Colour: Red".
	 *
	 * @param array<string, mixed> $sale Row.
	 */
	public static function item( array $sale ): string {
		$attributes = self::attributes( $sale );

		return '' === $attributes ? (string) $sale['product_name'] : $sale['product_name'] . ' – ' . $attributes;
	}

	/**
	 * The attribute snapshot as "Size: M, Colour: Red" ('' for a simple product).
	 *
	 * @param array<string, mixed> $sale Row.
	 */
	public static function attributes( array $sale ): string {
		$attrs = json_decode( (string) ( $sale['attributes_json'] ?? '' ), true );
		$pairs = array();

		foreach ( is_array( $attrs ) ? $attrs : array() as $label => $value ) {
			$pairs[] = $label . ': ' . $value;
		}

		return implode( ', ', $pairs );
	}

	/**
	 * An amount as plain text in the sale's currency, e.g. "₹1,499.00".
	 *
	 * @param string|null $amount   Amount.
	 * @param string      $currency Currency code ('' for the store's).
	 */
	public static function money( ?string $amount, string $currency = '' ): string {
		$args = '' === $currency ? array() : array( 'currency' => $currency );

		return trim( html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount, $args ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * A stored UTC datetime in the site timezone with the site's formats ('—' when empty).
	 *
	 * @param string|null $gmt    "Y-m-d H:i:s" UTC.
	 * @param string      $format PHP date format; '' for the site's date and time formats.
	 */
	public static function datetime( ?string $gmt, string $format = '' ): string {
		$timestamp = null === $gmt || '' === $gmt ? false : strtotime( $gmt . ' UTC' );

		if ( false === $timestamp ) {
			return '—';
		}

		return (string) wp_date( '' === $format ? get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) : $format, $timestamp );
	}

	/**
	 * Cost of the line (quantity × unit cost), or null when the cost is unknown.
	 *
	 * @param array<string, mixed> $sale Row.
	 */
	public static function line_cost( array $sale ): ?string {
		if ( ! isset( $sale['unit_cost'] ) || null === $sale['unit_cost'] ) {
			return null;
		}

		return SaleService::line_total( (string) $sale['unit_cost'], (int) $sale['quantity'] );
	}

	/**
	 * Profit of the line (total − quantity × unit cost), or null when the cost is unknown.
	 *
	 * @param array<string, mixed> $sale Row.
	 */
	public static function profit( array $sale ): ?string {
		$cost = self::line_cost( $sale );

		if ( null === $cost ) {
			return null;
		}

		$decimals = wc_get_price_decimals();

		return (string) wc_format_decimal( round( (float) $sale['line_total'] - (float) $cost, $decimals ), $decimals );
	}

	/**
	 * Forgets the memoised users (a long-running process that deletes users, e.g. tests).
	 */
	public static function flush(): void {
		self::$users = array();
	}

	/**
	 * A user, memoised for this request (false when deleted).
	 *
	 * @param int $user_id User ID.
	 * @return \WP_User|false
	 */
	private static function user( int $user_id ) {
		if ( ! array_key_exists( $user_id, self::$users ) ) {
			self::$users[ $user_id ] = $user_id > 0 ? get_userdata( $user_id ) : false;
		}

		return self::$users[ $user_id ];
	}
}
