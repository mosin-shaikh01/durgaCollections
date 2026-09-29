<?php
/**
 * The UPI payment QR shown on the sell screen (Phase 16).
 *
 * Settings (administrators, Settings → UPI payment QR):
 *   upi_id          the shop's UPI ID (VPA), e.g. shopname@okaxis; '' = not set
 *   upi_payee_name  the name UPI apps show, ASCII only;             '' = not set
 *
 * The QR is offered only when both are set, the store currency is INR and UPI is
 * an enabled payment method (is_active()). It is display only: the plugin cannot
 * see whether the customer paid (no gateway, no bank API), so the seller confirms
 * the sale after checking the customer's payment success screen.
 *
 * Payload (uri()): upi://pay?pa={UPI ID}&pn={payee}&am={amount}&cu=INR&tn={note}
 *   pa  written literally ("@" is not percent-encoded; some apps refuse %40); the
 *       ID pattern allows only characters that need no encoding
 *   pn  rawurlencode()d
 *   am  two decimals, "." as separator, no thousands separator
 *   tn  "{shop name, ASCII, at most 20 characters} {reference}", rawurlencode()d
 * No tr, mc or tid: those are for registered merchants, and some apps refuse them
 * on personal UPI IDs.
 *
 * Reference: the first 8 hexadecimal characters of the sale's request_id,
 * uppercased. The request_id is stored in the sale row, so the receipt shows the
 * same reference the customer sees in their UPI app, with no new column.
 *
 * Nothing here writes to the database.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * UPI settings, validation and the payment URI.
 */
final class UpiPayment {

	/** A UPI ID (VPA): handle@provider. Only characters that need no URL encoding. */
	const ID_PATTERN = '/^[A-Za-z0-9._-]{2,256}@[A-Za-z][A-Za-z0-9.-]{1,63}$/D';

	/** Payee name: 2-50 ASCII characters, starting with a letter or digit. */
	const PAYEE_PATTERN = "/^[A-Za-z0-9][A-Za-z0-9 .&'-]{1,49}$/D";

	/** The only currency UPI supports. */
	const CURRENCY = 'INR';

	/** Longest transaction note, and the part of it taken by the shop name. */
	const NOTE_MAX      = 50;
	const NOTE_SHOP_MAX = 20;

	/** A reference: 8 uppercase hexadecimal characters. */
	const REFERENCE_PATTERN = '/^[0-9A-F]{8}$/D';

	/** An amount as it appears in the payload. */
	const AMOUNT_PATTERN = '/^[0-9]{1,12}\.[0-9]{2}$/D';

	/**
	 * The stored UPI ID when valid, else ''.
	 */
	public static function id(): string {
		$stored = Settings::get()['upi_id'];

		return is_string( $stored ) && 1 === preg_match( self::ID_PATTERN, $stored ) ? $stored : '';
	}

	/**
	 * The stored payee name when valid, else ''.
	 */
	public static function payee(): string {
		$stored = Settings::get()['upi_payee_name'];

		return is_string( $stored ) && 1 === preg_match( self::PAYEE_PATTERN, $stored ) ? $stored : '';
	}

	/**
	 * Whether both the UPI ID and the payee name are set.
	 */
	public static function is_configured(): bool {
		return '' !== self::id() && '' !== self::payee();
	}

	/**
	 * Whether the sell screen shows the UPI QR: configured, the store currency is INR
	 * and UPI is an enabled payment method.
	 */
	public static function is_active(): bool {
		return '' === self::inactive_reason() && self::is_configured();
	}

	/**
	 * Why a configured UPI QR is not shown, for the Settings page; '' when nothing prevents it.
	 */
	public static function inactive_reason(): string {
		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';

		if ( self::CURRENCY !== $currency ) {
			/* translators: %s: the store currency code, e.g. USD. */
			return sprintf( __( 'The store currency is %s, not INR. The UPI QR is offered only for INR.', 'product-qrcode-barcode-generator' ), $currency );
		}

		if ( ! PaymentMethods::is_enabled( PaymentMethods::UPI ) ) {
			return __( 'UPI is not an enabled payment method (In-store sales → Payment methods offered).', 'product-qrcode-barcode-generator' );
		}

		return '';
	}

	/**
	 * Validates and normalises a UPI ID: spaces around it removed, lowercased. '' clears it.
	 *
	 * @param mixed $value Submitted value.
	 * @return string|WP_Error
	 */
	public static function validate_id( $value ) {
		$id = is_string( $value ) ? strtolower( trim( $value ) ) : null;

		if ( '' === $id ) {
			return '';
		}

		if ( null === $id || 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			return new WP_Error( 'pqbg_invalid_upi_id', __( 'The UPI ID must look like name@bank (letters, digits, dot, hyphen or underscore before the @). The previous UPI ID was kept.', 'product-qrcode-barcode-generator' ) );
		}

		return $id;
	}

	/**
	 * Validates a payee name: spaces around it removed, inner runs of spaces made single. '' clears it.
	 *
	 * @param mixed $value Submitted value.
	 * @return string|WP_Error
	 */
	public static function validate_payee( $value ) {
		$name = is_string( $value ) ? trim( (string) preg_replace( '/ {2,}/', ' ', $value ) ) : null;

		if ( '' === $name ) {
			return '';
		}

		if ( null === $name || 1 !== preg_match( self::PAYEE_PATTERN, $name ) ) {
			return new WP_Error( 'pqbg_invalid_upi_payee', __( 'The payee name must be 2 to 50 characters: English letters, digits, spaces and . & \' -. The previous name was kept.', 'product-qrcode-barcode-generator' ) );
		}

		return $name;
	}

	/**
	 * The reference for a sale's request ID, e.g. "3F9A0C1B"; '' for anything that is not a UUID.
	 *
	 * @param string $request_id Sale request ID.
	 */
	public static function reference( string $request_id ): string {
		return wp_is_uuid( $request_id ) ? strtoupper( substr( $request_id, 0, 8 ) ) : '';
	}

	/**
	 * An amount for the payload: two decimals, "." separator.
	 *
	 * @param string $amount Decimal amount.
	 */
	public static function amount( string $amount ): string {
		return number_format( (float) $amount, 2, '.', '' );
	}

	/**
	 * The transaction note: the shop name reduced to ASCII (at most NOTE_SHOP_MAX characters) and the reference.
	 *
	 * @param string $reference Reference.
	 */
	public static function note( string $reference ): string {
		$shop = (string) preg_replace( '/[^A-Za-z0-9 .&-]+/', '', remove_accents( Receipt::shop_name() ) );
		$shop = trim( substr( trim( (string) preg_replace( '/ {2,}/', ' ', $shop ) ), 0, self::NOTE_SHOP_MAX ) );

		return substr( trim( $shop . ' ' . $reference ), 0, self::NOTE_MAX );
	}

	/**
	 * The upi://pay URI, or '' when UPI is not configured or an argument is malformed.
	 *
	 * @param string $amount    Amount from amount().
	 * @param string $reference Reference from reference().
	 */
	public static function uri( string $amount, string $reference ): string {
		if ( ! self::is_configured() || 1 !== preg_match( self::AMOUNT_PATTERN, $amount ) || 1 !== preg_match( self::REFERENCE_PATTERN, $reference ) || (float) $amount <= 0 ) {
			return '';
		}

		return 'upi://pay?pa=' . self::id()
			. '&pn=' . rawurlencode( self::payee() )
			. '&am=' . $amount
			. '&cu=' . self::CURRENCY
			. '&tn=' . rawurlencode( self::note( $reference ) );
	}
}
