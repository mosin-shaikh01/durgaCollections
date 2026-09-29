<?php
/**
 * The two-step UPI sale on the scan page (Phase 16).
 *
 * Every POST to /scan/{CODE}/ passes through handle(). Anything that is not a sale
 * paid by UPI while the UPI payment QR is active (UpiPayment::is_active()) goes to
 * SaleRequest::handle() unchanged. The sale path (SaleRequest, SaleService,
 * SaleRepository) is not modified.
 *
 *   step 1  "Confirm sale" with UPI chosen: the nonce, the signed form token, the
 *           quantity, the price and the stock are checked, and nothing is sold. The
 *           product screen comes back with the UPI panel: the payment QR for
 *           price × quantity, the amount, the payee, the reference and the note
 *           "Check the customer's payment success screen before confirming."
 *   step 2  "Payment received – confirm sale" posts the same token (same request_id,
 *           so a double tap never sells twice), quantity and method, plus
 *           upi_confirmed=1 and upi_sig, an HMAC over the user, the code row, the
 *           request_id, the quantity and the amount shown. A bad signature is
 *           refused; a good one goes to SaleRequest::handle(), which sells.
 *
 * Failures that SaleRequest itself refuses before selling (nonce, token, expiry,
 * quantity, a request_id that already has a sale) are passed to it in step 1, so
 * the messages are the usual ones. Price and stock are compared here, read-only,
 * before any QR is shown.
 *
 * The form token is not re-issued in step 1: the 30 minutes (SaleRequest::FORM_TTL)
 * count from when the product page was opened.
 *
 * After step 2, any refusal (price or stock changed, sold online, expired form,
 * UPI turned off) adds a note that the customer may already have paid. When the QR
 * is no longer active at step 2 (settings cleared), the sale is still recorded as UPI.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * UPI payment step in front of SaleRequest.
 */
final class UpiSale {

	const CONFIRMED_FIELD = 'upi_confirmed';
	const SIG_FIELD       = 'upi_sig';

	/**
	 * Answers a POST to /scan/{CODE}/ (ScanRoute has checked login, pqbg_view_products and the URL).
	 *
	 * @param string               $code Valid, canonical code.
	 * @param array<string, mixed> $post Unslashed POST fields.
	 * @return array{status: int, location?: string, view?: array<string, mixed>, headers?: array<string, string>}
	 */
	public static function handle( string $code, array $post ): array {
		$action    = isset( $post[ SaleRequest::ACTION_FIELD ] ) && is_string( $post[ SaleRequest::ACTION_FIELD ] ) ? $post[ SaleRequest::ACTION_FIELD ] : '';
		$method    = isset( $post['payment_method'] ) && is_string( $post['payment_method'] ) ? $post['payment_method'] : '';
		$confirmed = isset( $post[ self::CONFIRMED_FIELD ] ) && '1' === $post[ self::CONFIRMED_FIELD ];

		if ( 'sell' !== $action || PaymentMethods::UPI !== $method ) {
			return SaleRequest::handle( $code, $post );
		}

		if ( $confirmed ) {
			return self::confirm( $code, $post );
		}

		if ( ! UpiPayment::is_active() ) {
			return SaleRequest::handle( $code, $post );
		}

		return self::show_qr( $code, $post ) ?? SaleRequest::handle( $code, $post );
	}

	/**
	 * Step 1: the UPI panel, an error, or null to let SaleRequest refuse the request.
	 *
	 * @param string               $code Code.
	 * @param array<string, mixed> $post Fields.
	 * @return array<string, mixed>|null
	 */
	private static function show_qr( string $code, array $post ): ?array {
		$row   = CodeRepository::find_by_code( $code );
		$user  = get_current_user_id();
		$nonce = isset( $post[ Permissions::NONCE_FIELD ] ) && is_string( $post[ Permissions::NONCE_FIELD ] ) ? $post[ Permissions::NONCE_FIELD ] : '';

		if ( null === $row || ! Permissions::can_sell() || ! wp_verify_nonce( $nonce, Permissions::nonce_action( 'sell_' . $row['id'] ) ) ) {
			return null;
		}

		$token    = SaleRequest::verify_token( $post, $user, (int) $row['id'] );
		$quantity = self::quantity( $post['quantity'] ?? null );

		if ( is_wp_error( $token ) || $token['expired'] || null === $quantity || null !== SaleRepository::find_by_request_id( $token['request_id'] ) ) {
			return null;
		}

		$prefill = array(
			'quantity'       => $quantity,
			'payment_method' => PaymentMethods::UPI,
		);
		$item    = SaleService::check_item( $row );

		if ( is_wp_error( $item ) ) {
			return self::error( $code, SaleRequest::status_for( $item->get_error_code() ), $item->get_error_message(), $prefill );
		}

		$price = SaleService::normalize_price( $item['product']->get_price() );

		if ( SaleService::normalize_price( $token['seen_price'] ) !== $price ) {
			return self::error( $code, 409, __( 'The price changed since you opened this page. Check the new price and confirm again.', 'product-qrcode-barcode-generator' ), $prefill );
		}

		$stock = SaleRepository::read_stock( $item['holder']->get_id() );
		$stock = null === $stock ? 0 : (int) $stock;

		if ( $stock <= 0 ) {
			return self::error( $code, 409, __( 'Out of stock – cannot be sold.', 'product-qrcode-barcode-generator' ), $prefill );
		}

		if ( $quantity > $stock ) {
			return self::error(
				$code,
				409,
				$token['seen_stock'] !== $stock
					/* translators: %s: current stock quantity. */
					? sprintf( __( 'Stock changed since you opened this page. Now %s in stock.', 'product-qrcode-barcode-generator' ), number_format_i18n( $stock ) )
					/* translators: %s: current stock quantity. */
					: sprintf( __( 'Only %1$s in stock. Choose a quantity between 1 and %1$s.', 'product-qrcode-barcode-generator' ), number_format_i18n( $stock ) ),
				$prefill
			);
		}

		$amount    = UpiPayment::amount( SaleService::line_total( $price, $quantity ) );
		$reference = UpiPayment::reference( $token['request_id'] );
		$qr        = ( new QrRenderer() )->render_upi( $amount, $reference );
		$fields    = array();

		foreach ( array( 'request_id', 'issued', 'seen_price', 'seen_stock', 'sig' ) as $key ) {
			$fields[ $key ] = (string) $post[ $key ];
		}

		$fields['quantity']            = (string) $quantity;
		$fields['payment_method']      = PaymentMethods::UPI;
		$fields[ self::CONFIRMED_FIELD ] = '1';
		$fields[ self::SIG_FIELD ]     = self::sign( $user, (int) $row['id'], $token['request_id'], $quantity, $amount );

		return array(
			'status' => 200,
			'view'   => ScanScreen::upi_step(
				$code,
				$prefill,
				array(
					'qr'        => is_wp_error( $qr ) ? '' : $qr,
					'amount'    => SalePresenter::money( $amount ),
					'payee'     => UpiPayment::payee(),
					'upi_id'    => UpiPayment::id(),
					'reference' => $reference,
					'quantity'  => number_format_i18n( $quantity ),
					'action'    => ScanUrl::site_url( $code ),
					'nonce'     => $nonce,
					'fields'    => $fields,
					'back'      => ScanUrl::site_url( $code ),
				)
			),
		);
	}

	/**
	 * Step 2: checks the UPI signature, then sells through SaleRequest; refusals get the "may already have paid" note.
	 *
	 * @param string               $code Code.
	 * @param array<string, mixed> $post Fields.
	 * @return array<string, mixed>
	 */
	private static function confirm( string $code, array $post ): array {
		$row = CodeRepository::find_by_code( $code );

		// Settings cleared meanwhile: the QR was only a display, so the sale is recorded as UPI as usual.
		if ( null !== $row && UpiPayment::is_active() ) {
			$token    = SaleRequest::verify_token( $post, get_current_user_id(), (int) $row['id'] );
			$quantity = self::quantity( $post['quantity'] ?? null );
			$sig      = isset( $post[ self::SIG_FIELD ] ) && is_string( $post[ self::SIG_FIELD ] ) ? $post[ self::SIG_FIELD ] : '';

			if ( ! is_wp_error( $token ) && null !== $quantity ) {
				$amount = UpiPayment::amount( SaleService::line_total( SaleService::normalize_price( $token['seen_price'] ), $quantity ) );

				if ( ! hash_equals( self::sign( get_current_user_id(), (int) $row['id'], $token['request_id'], $quantity, $amount ), $sig ) ) {
					return self::with_note( self::error( $code, 400, __( 'This form is not valid. Check the item and confirm again.', 'product-qrcode-barcode-generator' ), array() ) );
				}
			}
		}

		$response = SaleRequest::handle( $code, $post );

		return isset( $response['view'] ) && (int) $response['status'] >= 400 ? self::with_note( $response ) : $response;
	}

	/**
	 * Adds the "customer may already have paid" warning under the first notice.
	 *
	 * @param array<string, mixed> $response Response with a view.
	 * @return array<string, mixed>
	 */
	private static function with_note( array $response ): array {
		$notes = $response['view']['notices'];

		array_splice( $notes, 1, 0, array( array( 'warning', __( 'The customer may already have paid by UPI. Check their payment before you show a new QR, and refund or settle any difference yourself.', 'product-qrcode-barcode-generator' ) ) ) );

		$response['view']['notices'] = $notes;

		return $response;
	}

	/**
	 * The product screen (with a fresh sale form where sellable) and an error.
	 *
	 * @param string               $code    Code.
	 * @param int                  $status  HTTP status.
	 * @param string               $message Message.
	 * @param array<string, mixed> $prefill Quantity and method to keep.
	 * @return array<string, mixed>
	 */
	private static function error( string $code, int $status, string $message, array $prefill ): array {
		return array(
			'status' => $status,
			'view'   => ScanScreen::with_error( ScanScreen::resolve( $code, $prefill ), $status, $message ),
		);
	}

	/**
	 * A quantity field as a positive integer, or null (the same rule as SaleRequest).
	 *
	 * @param mixed $value Submitted value.
	 */
	private static function quantity( $value ): ?int {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 7 || ! ctype_digit( $value ) ) {
			return null;
		}

		$quantity = (int) $value;

		return $quantity >= 1 && $quantity <= SaleService::MAX_QUANTITY ? $quantity : null;
	}

	/**
	 * The step 2 signature.
	 *
	 * @param int    $user_id    User.
	 * @param int    $code_id    Code row ID.
	 * @param string $request_id Sale request ID.
	 * @param int    $quantity   Quantity.
	 * @param string $amount     Amount shown on the QR.
	 */
	private static function sign( int $user_id, int $code_id, string $request_id, int $quantity, string $amount ): string {
		return hash_hmac( 'sha256', implode( '|', array( 'pqbg_upi', $user_id, $code_id, $request_id, $quantity, $amount ) ), wp_salt( 'nonce' ) );
	}
}
