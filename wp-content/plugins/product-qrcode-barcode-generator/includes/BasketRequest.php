<?php
/**
 * The basket's POST actions on the scan pages (Phase 17, decisions D2–D6, D15).
 *
 * ScanRoute has already checked, before calling in: logged in, pqbg_view_products and
 * the canonical URL. Every action here also needs pqbg_sell and a nonce.
 *
 *   POST /scan/{CODE}/  pqbg_action=basket_add, or pqbg_basket=1 (the "Add to basket"
 *                       button of the sale form): adds the chosen quantity → 303 to the basket
 *   POST /scan/basket/  pqbg_action =
 *     basket_scan     the "Scan to add" box: 1 of the scanned item (typed, pasted scan
 *                     URL or a hardware scanner's code + Enter) → 303 back to the basket
 *     basket_qty      a line's new quantity (needs the basket rev)
 *     basket_remove   removes a line (needs the rev)
 *     basket_clear    shows "Clear the basket?"; with confirm=1 empties it (needs the rev)
 *     basket_confirm  sells the basket (BasketService::confirm()); UPI in two steps as in
 *                     Phase 16 (the QR for the basket total, then "Payment received")
 *     basket_undo     undoes a sold basket (BasketService::undo())
 *
 * Confirm form token (issued with every basket screen that can be confirmed):
 *   request_id  the basket's request ID (stored with the basket, renewed on every change)
 *   rev         the basket revision shown
 *   issued      Unix time the form was rendered (SaleRequest::FORM_TTL applies)
 *   lines       "CODE:quantity:price|…", the lines and prices shown
 *   sig         HMAC-SHA256 over the fields above and the user, keyed with wp_salt( 'nonce' )
 * Order: nonce → signature → an existing sale with this request ID (its outcome is
 * returned even for an expired form or a changed basket, so a double tap never sells
 * twice) → expiry → the basket's rev → payment method → BasketService::confirm().
 *
 * Changes answer 303 (a reload never repeats them); refusals show the basket again
 * with the message and change nothing.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Basket requests.
 */
final class BasketRequest {

	/** Hidden field on the sale form's "Add to basket" button. */
	const ADD_FIELD = 'pqbg_basket';

	const ACTIONS = array( 'basket_scan', 'basket_qty', 'basket_remove', 'basket_clear', 'basket_confirm', 'basket_undo' );

	/**
	 * Whether a POST to /scan/{CODE}/ is an "Add to basket".
	 *
	 * @param array<string, mixed> $post Unslashed POST fields.
	 */
	public static function is_add( array $post ): bool {
		return ( isset( $post[ SaleRequest::ACTION_FIELD ] ) && 'basket_add' === $post[ SaleRequest::ACTION_FIELD ] )
			|| ( isset( $post[ self::ADD_FIELD ] ) && '1' === $post[ self::ADD_FIELD ] );
	}

	/**
	 * "Add to basket" from the product screen.
	 *
	 * @param string               $code Valid, canonical code.
	 * @param array<string, mixed> $post Unslashed POST fields.
	 * @return array{status: int, location?: string, view?: array<string, mixed>}
	 */
	public static function add_from_product( string $code, array $post ): array {
		if ( ! Permissions::can_sell() ) {
			return self::product_error( $code, 403, __( 'You do not have permission to sell.', 'product-qrcode-barcode-generator' ) );
		}

		$row   = CodeRepository::find_by_code( $code );
		$user  = get_current_user_id();
		$nonce = self::field( $post, Permissions::NONCE_FIELD );

		if ( null === $row ) {
			$view = ScanScreen::resolve( $code );
			return array(
				'status' => (int) $view['status'],
				'view'   => $view,
			);
		}

		if ( ! wp_verify_nonce( $nonce, Permissions::nonce_action( 'sell_' . $row['id'] ) ) ) {
			return self::product_error( $code, 403, __( 'This form is no longer valid. Check the item and try again.', 'product-qrcode-barcode-generator' ) );
		}

		if ( ! BasketService::db_supported() ) {
			return self::product_error( $code, 409, BasketService::unsupported()->get_error_message() );
		}

		$quantity = self::quantity( $post['quantity'] ?? null );

		if ( null === $quantity ) {
			return self::product_error( $code, 400, __( 'Choose a valid quantity.', 'product-qrcode-barcode-generator' ) );
		}

		$token = SaleRequest::verify_token( $post, $user, (int) $row['id'] );
		$added = self::add( $user, $code, $quantity, is_wp_error( $token ) ? null : $token['seen_price'] );

		if ( is_wp_error( $added ) ) {
			return self::product_error(
				$code,
				SaleRequest::status_for( $added->get_error_code() ),
				$added->get_error_message(),
				array(
					'quantity'       => $quantity,
					'payment_method' => '',
				)
			);
		}

		return array(
			'status'   => 303,
			'location' => ScanUrl::basket_url( array( 'added' => $code ) ),
		);
	}

	/**
	 * A POST to /scan/basket/.
	 *
	 * @param array<string, mixed> $post Unslashed POST fields.
	 * @return array{status: int, location?: string, view?: array<string, mixed>, headers?: array<string, string>}
	 */
	public static function handle( array $post ): array {
		$action = self::field( $post, SaleRequest::ACTION_FIELD );
		$user   = get_current_user_id();

		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return self::basket_error( 400, __( 'This request could not be understood.', 'product-qrcode-barcode-generator' ) );
		}

		if ( ! Permissions::can_sell() ) {
			return array(
				'status' => 403,
				'view'   => ScanScreen::sell_forbidden(),
			);
		}

		if ( 'basket_undo' === $action ) {
			return self::undo( $post );
		}

		$verb = 'basket_scan' === $action || 'basket_confirm' === $action ? $action : 'basket_edit';

		if ( ! wp_verify_nonce( self::field( $post, Permissions::NONCE_FIELD ), Permissions::nonce_action( $verb ) ) ) {
			return self::basket_error( 403, __( 'This form is no longer valid. Check the basket and try again.', 'product-qrcode-barcode-generator' ) );
		}

		if ( ! BasketService::db_supported() ) {
			return self::basket_error( 409, BasketService::unsupported()->get_error_message() );
		}

		switch ( $action ) {
			case 'basket_scan':
				return self::scan( $user, $post );
			case 'basket_confirm':
				return self::confirm( $user, $post );
		}

		$rev  = self::field( $post, 'rev' );
		$rev  = ctype_digit( $rev ) && strlen( $rev ) < 10 ? (int) $rev : -1;
		$code = CodeRepository::normalize_code( self::field( $post, 'code' ) );

		if ( 'basket_clear' === $action ) {
			if ( '1' !== self::field( $post, 'confirm' ) ) {
				return array(
					'status' => 200,
					'view'   => ScanScreen::basket( $user, array(), array( 'clear_confirm' => true ) ),
				);
			}

			$done = BasketStore::clear( $user, $rev );
			$msg  = 'cleared';
		} elseif ( 'basket_remove' === $action ) {
			$done = BasketStore::remove( $user, $rev, $code );
			$msg  = 'removed';
		} else {
			$quantity = self::quantity( $post['quantity'] ?? null );

			if ( null === $quantity ) {
				return self::basket_error( 400, __( 'Choose a valid quantity.', 'product-qrcode-barcode-generator' ) );
			}

			$cap  = self::cap( $code );
			$done = is_wp_error( $cap ) ? $cap : BasketStore::set_quantity( $user, $rev, $code, $quantity, $cap );
			$msg  = 'updated';
		}

		if ( is_wp_error( $done ) ) {
			return self::basket_error( SaleRequest::status_for( $done->get_error_code() ), $done->get_error_message() );
		}

		return array(
			'status'   => 303,
			'location' => ScanUrl::basket_url( array( 'msg' => $msg ) ),
		);
	}

	/**
	 * The "Scan to add" box: 1 of the item, or its line + 1.
	 *
	 * @param int                  $user User.
	 * @param array<string, mixed> $post Fields.
	 * @return array<string, mixed>
	 */
	private static function scan( int $user, array $post ): array {
		$typed = trim( self::field( $post, 'code' ) );
		$code  = ScanUrl::extract_code( $typed );

		if ( '' === $code || ! CodeGenerator::is_valid_format( $code ) ) {
			return self::basket_error( 400, __( 'Not a valid product code.', 'product-qrcode-barcode-generator' ), mb_substr( $typed, 0, 100 ) );
		}

		$added = self::add( $user, $code, 1, null );

		if ( is_wp_error( $added ) ) {
			/* translators: 1: item or code, 2: reason. */
			return self::basket_error( SaleRequest::status_for( $added->get_error_code() ), sprintf( __( '%1$s: %2$s', 'product-qrcode-barcode-generator' ), ScanScreen::code_name( $code ), $added->get_error_message() ), $code );
		}

		return array(
			'status'   => 303,
			'location' => ScanUrl::basket_url( array( 'added' => $code ) ),
		);
	}

	/**
	 * Adds a quantity of a code, checked like a sale (sellable item, stock of the holder
	 * minus what other lines of the basket already take from it).
	 *
	 * @param int         $user     User.
	 * @param string      $code     Code.
	 * @param int         $quantity Quantity.
	 * @param string|null $seen     The price the seller saw, or null for the current price.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function add( int $user, string $code, int $quantity, ?string $seen ) {
		$item = SaleService::check_item( CodeRepository::find_by_code( $code ) );

		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$cap = self::cap( $code );

		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		$price = SaleService::normalize_price( null === $seen ? $item['product']->get_price() : $seen );

		return BasketStore::add( $user, $code, $quantity, $price, $cap );
	}

	/**
	 * The highest quantity a code's line may have: its holder's stock minus the other
	 * lines of the basket that take stock from the same holder.
	 *
	 * @param string $code Code.
	 * @return callable|WP_Error Receives the basket's lines.
	 */
	private static function cap( string $code ) {
		$item = SaleService::check_item( CodeRepository::find_by_code( $code ) );

		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$holder_id = $item['holder']->get_id();
		$stock     = SaleRepository::read_stock( $holder_id );
		$stock     = null === $stock ? 0 : (int) $stock;

		return static function ( array $lines ) use ( $code, $holder_id, $stock ): int {
			$others = 0;

			foreach ( $lines as $line ) {
				if ( $line['code'] !== $code && ScanScreen::holder_of( $line['code'] ) === $holder_id ) {
					$others += (int) $line['quantity'];
				}
			}

			return max( 0, $stock - $others );
		};
	}

	/**
	 * Confirm: the UPI step, or the sale.
	 *
	 * @param int                  $user User.
	 * @param array<string, mixed> $post Fields.
	 * @return array<string, mixed>
	 */
	private static function confirm( int $user, array $post ): array {
		$token = self::verify_token( $post, $user );

		if ( is_wp_error( $token ) ) {
			return self::basket_error( 400, $token->get_error_message() );
		}

		$method    = self::field( $post, 'payment_method' );
		$confirmed = '1' === self::field( $post, UpiSale::CONFIRMED_FIELD );
		$upi       = PaymentMethods::UPI === $method && UpiPayment::is_active();
		$existing  = SaleRepository::find_by_request_id( $token['request_id'] );
		$prefill   = array( 'payment_method' => PaymentMethods::is_enabled( $method ) ? $method : '' );

		if ( null === $existing ) {
			if ( $token['expired'] ) {
				return self::basket_error( 400, __( 'This form has expired. Check the basket and confirm again.', 'product-qrcode-barcode-generator' ), '', $prefill, $confirmed );
			}

			if ( BasketStore::get( $user )['rev'] !== $token['rev'] ) {
				return self::basket_error( 409, BasketStore::changed_error()->get_error_message(), '', $prefill, $confirmed );
			}

			$checked = SaleService::check_payment_method( $method );

			if ( is_wp_error( $checked ) ) {
				return self::basket_error( 400, $checked->get_error_message(), '', $prefill );
			}

			$amount = UpiPayment::amount( self::total( $token['lines'] ) );

			if ( $upi && ! $confirmed ) {
				return self::show_qr( $user, $post, $token, $amount );
			}

			if ( $upi && ! hash_equals( self::upi_sign( $user, $token['request_id'], $token['rev'], $amount ), self::field( $post, UpiSale::SIG_FIELD ) ) ) {
				return self::basket_error( 400, __( 'This form is not valid. Check the basket and confirm again.', 'product-qrcode-barcode-generator' ), '', $prefill, true );
			}
		}

		$result = BasketService::confirm(
			array(
				'seller_id'      => $user,
				'request_id'     => $token['request_id'],
				'payment_method' => null === $existing ? $method : (string) $existing['payment_method'],
				'lines'          => $token['lines'],
			)
		);

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$line = is_array( $data ) && isset( $data['line'] ) ? (int) $data['line'] : -1;

			return self::basket_error( SaleRequest::status_for( $result->get_error_code() ), self::line_message( $token['lines'], $line, $result->get_error_message() ), '', $prefill, $confirmed );
		}

		if ( SaleRepository::STATUS_COMPLETED === $result['status'] ) {
			BasketStore::clear_sold( $user, $token['request_id'] );

			return array(
				'status'   => 303,
				'location' => ScanUrl::basket_sale_url( (int) $result['basket_id'] ),
			);
		}

		// Nothing was sold (D13): the basket stays for another try, with a new request ID.
		BasketStore::renew( $user );

		$name    = ScanScreen::code_name( (string) ( $token['lines'][ $result['line'] ]['code'] ?? '' ) );
		$message = SaleRepository::FAILURE_SOLD_ONLINE === $result['failure']
			/* translators: %s: item. */
			? sprintf( __( 'Nothing was sold. "%s" just sold online. Stock was not changed.', 'product-qrcode-barcode-generator' ), $name )
			/* translators: %s: item. */
			: sprintf( __( 'Nothing was sold: "%s" could not be sold. Stock was not changed.', 'product-qrcode-barcode-generator' ), $name );

		return self::basket_error( SaleRepository::FAILURE_SOLD_ONLINE === $result['failure'] ? 409 : 500, $message, '', $prefill, $confirmed );
	}

	/**
	 * UPI step 1 for a basket (read-only): every line still sellable at the price shown
	 * and within stock, then the payment QR for the total.
	 *
	 * @param int                  $user   User.
	 * @param array<string, mixed> $post   Fields.
	 * @param array<string, mixed> $token  Verified token.
	 * @param string               $amount Total for the QR.
	 * @return array<string, mixed>
	 */
	private static function show_qr( int $user, array $post, array $token, string $amount ): array {
		$need    = array();
		$stock   = array();
		$prefill = array( 'payment_method' => PaymentMethods::UPI );

		foreach ( $token['lines'] as $i => $line ) {
			$item = SaleService::check_item( CodeRepository::find_by_code( $line['code'] ) );

			if ( is_wp_error( $item ) ) {
				return self::basket_error( SaleRequest::status_for( $item->get_error_code() ), self::line_message( $token['lines'], $i, $item->get_error_message() ), '', $prefill );
			}

			if ( SaleService::normalize_price( $item['product']->get_price() ) !== SaleService::normalize_price( $line['price'] ) ) {
				return self::basket_error( 409, self::line_message( $token['lines'], $i, __( 'The price changed since you opened this page. Check the new price and confirm again.', 'product-qrcode-barcode-generator' ) ), '', $prefill );
			}

			$holder_id = $item['holder']->get_id();

			if ( ! isset( $stock[ $holder_id ] ) ) {
				$read                = SaleRepository::read_stock( $holder_id );
				$stock[ $holder_id ] = null === $read ? 0 : (int) $read;
			}

			$need[ $holder_id ] = ( $need[ $holder_id ] ?? 0 ) + $line['quantity'];

			if ( $need[ $holder_id ] > $stock[ $holder_id ] ) {
				return self::basket_error(
					409,
					self::line_message(
						$token['lines'],
						$i,
						$stock[ $holder_id ] <= 0
							? __( 'Out of stock – cannot be sold.', 'product-qrcode-barcode-generator' )
							/* translators: %s: stock quantity. */
							: sprintf( __( 'Only %s in stock.', 'product-qrcode-barcode-generator' ), number_format_i18n( $stock[ $holder_id ] ) )
					),
					'',
					$prefill
				);
			}
		}

		$reference = UpiPayment::reference( $token['request_id'] );
		$qr        = ( new QrRenderer() )->render_upi( $amount, $reference );
		$fields    = array();

		foreach ( array( 'request_id', 'rev', 'issued', 'lines', 'sig' ) as $key ) {
			$fields[ $key ] = self::field( $post, $key );
		}

		$fields['payment_method']        = PaymentMethods::UPI;
		$fields[ UpiSale::CONFIRMED_FIELD ] = '1';
		$fields[ UpiSale::SIG_FIELD ]       = self::upi_sign( $user, $token['request_id'], $token['rev'], $amount );

		return array(
			'status' => 200,
			'view'   => ScanScreen::basket_upi(
				$user,
				array(
					'qr'        => is_wp_error( $qr ) ? '' : $qr,
					'amount'    => SalePresenter::money( $amount ),
					'payee'     => UpiPayment::payee(),
					'upi_id'    => UpiPayment::id(),
					'reference' => $reference,
					'quantity'  => number_format_i18n( (int) array_sum( array_column( $token['lines'], 'quantity' ) ) ),
					'action'    => ScanUrl::basket_url(),
					'nonce'     => self::field( $post, Permissions::NONCE_FIELD ),
					'fields'    => $fields,
					'back'      => ScanUrl::basket_url(),
					'basket'    => true,
				)
			),
		);
	}

	/**
	 * Undo of a sold basket (from its page).
	 *
	 * @param array<string, mixed> $post Fields.
	 * @return array<string, mixed>
	 */
	private static function undo( array $post ): array {
		$raw = self::field( $post, 'basket' );
		$id  = ctype_digit( $raw ) && strlen( $raw ) < 20 ? (int) $raw : 0;

		if ( $id <= 0 || ! wp_verify_nonce( self::field( $post, Permissions::NONCE_FIELD ), Permissions::nonce_action( 'basket_undo_' . $id ) ) ) {
			return self::basket_error( 403, __( 'This form is no longer valid. Reload the page and try again.', 'product-qrcode-barcode-generator' ) );
		}

		$result = BasketService::undo( $id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$lines = BasketService::lines( $id );

			if ( array() === $lines || ! Permissions::can_view_sale( (int) $lines[0]['seller_id'] ) ) {
				return self::basket_error( SaleRequest::status_for( $result->get_error_code() ), $result->get_error_message() );
			}

			return array(
				'status'  => SaleRequest::status_for( $result->get_error_code() ),
				'view'    => ScanScreen::with_error( ScanScreen::basket_sale( $lines ), SaleRequest::status_for( $result->get_error_code() ), $result->get_error_message() ),
				'headers' => 'pqbg_busy' === $result->get_error_code() ? array( 'Retry-After' => '2' ) : array(),
			);
		}

		return array(
			'status'   => 303,
			'location' => ScanUrl::basket_sale_url( $id ),
		);
	}

	/**
	 * Hidden fields of the confirm form for a basket shown with these lines and prices.
	 *
	 * @param int                               $user_id    User.
	 * @param string                            $request_id The basket's request ID.
	 * @param int                               $rev        The basket's revision.
	 * @param array<int, array<string, mixed>>  $lines      code, quantity, price (normalised) per line.
	 * @param int|null                          $now        Issue time (tests).
	 * @return array<string, string>
	 */
	public static function issue_token( int $user_id, string $request_id, int $rev, array $lines, ?int $now = null ): array {
		$fields = array(
			'request_id' => $request_id,
			'rev'        => (string) $rev,
			'issued'     => (string) ( $now ?? time() ),
			'lines'      => implode( '|', array_map( static fn( $l ) => $l['code'] . ':' . (int) $l['quantity'] . ':' . $l['price'], $lines ) ),
		);

		$fields['sig'] = self::sign( $fields, $user_id );

		return $fields;
	}

	/**
	 * Checks a submitted confirm token.
	 *
	 * @param array<string, mixed> $post    Fields.
	 * @param int                  $user_id User.
	 * @return array{request_id: string, rev: int, lines: array<int, array{code: string, quantity: int, price: string}>, expired: bool}|WP_Error
	 */
	public static function verify_token( array $post, int $user_id ) {
		$bad    = new WP_Error( 'pqbg_bad_token', __( 'This form is not valid. Check the basket and confirm again.', 'product-qrcode-barcode-generator' ) );
		$fields = array();

		foreach ( array( 'request_id', 'rev', 'issued', 'lines', 'sig' ) as $key ) {
			if ( ! isset( $post[ $key ] ) || ! is_string( $post[ $key ] ) ) {
				return $bad;
			}
			$fields[ $key ] = $post[ $key ];
		}

		$sig = $fields['sig'];
		unset( $fields['sig'] );

		if ( ! wp_is_uuid( $fields['request_id'], 4 ) || ! ctype_digit( $fields['issued'] ) || ! ctype_digit( $fields['rev'] ) || ! hash_equals( self::sign( $fields, $user_id ), $sig ) ) {
			return $bad;
		}

		$lines = array();

		foreach ( '' === $fields['lines'] ? array() : explode( '|', $fields['lines'] ) as $part ) {
			$bits = explode( ':', $part );

			if ( 3 !== count( $bits ) || ! CodeGenerator::is_valid_format( $bits[0] ) || ! ctype_digit( $bits[1] ) ) {
				return $bad;
			}

			$lines[] = array(
				'code'     => $bits[0],
				'quantity' => (int) $bits[1],
				'price'    => $bits[2],
			);
		}

		$age = time() - (int) $fields['issued'];

		return array(
			'request_id' => $fields['request_id'],
			'rev'        => (int) $fields['rev'],
			'lines'      => $lines,
			'expired'    => $age > SaleRequest::FORM_TTL || $age < -60,
		);
	}

	/**
	 * Sum of the lines at the prices shown.
	 *
	 * @param array<int, array<string, mixed>> $lines Lines.
	 */
	public static function total( array $lines ): string {
		$sum = 0.0;

		foreach ( $lines as $line ) {
			$sum += (float) SaleService::line_total( SaleService::normalize_price( $line['price'] ), (int) $line['quantity'] );
		}

		return (string) wc_format_decimal( round( $sum, wc_get_price_decimals() ), wc_get_price_decimals() );
	}

	/**
	 * "Line 2, Cotton kurti – M: Only 1 in stock." (or the message alone when no line is to blame).
	 *
	 * @param array<int, array<string, mixed>> $lines   Lines.
	 * @param int                              $index   Line index, or -1.
	 * @param string                           $message Message.
	 */
	private static function line_message( array $lines, int $index, string $message ): string {
		if ( $index < 0 || ! isset( $lines[ $index ] ) ) {
			return $message;
		}

		/* translators: 1: line number, 2: item, 3: reason. */
		return sprintf( __( 'Line %1$s, %2$s: %3$s', 'product-qrcode-barcode-generator' ), number_format_i18n( $index + 1 ), ScanScreen::code_name( (string) $lines[ $index ]['code'] ), $message );
	}

	/**
	 * The basket screen with an error (and the UPI "may already have paid" note after step 2).
	 *
	 * @param int                  $status  HTTP status.
	 * @param string               $message Message.
	 * @param string               $value   Value to keep in the scan box.
	 * @param array<string, mixed> $prefill Payment method to keep.
	 * @param bool                 $paid    Whether the customer may already have paid by UPI.
	 * @return array<string, mixed>
	 */
	private static function basket_error( int $status, string $message, string $value = '', array $prefill = array(), bool $paid = false ): array {
		$view = ScanScreen::with_error( ScanScreen::basket( get_current_user_id(), $prefill, array( 'value' => $value ) ), $status, $message );

		if ( $paid ) {
			array_splice( $view['notices'], 1, 0, array( array( 'warning', __( 'The customer may already have paid by UPI. Check their payment before you show a new QR, and refund or settle any difference yourself.', 'product-qrcode-barcode-generator' ) ) ) );
		}

		return array(
			'status'  => $status,
			'view'    => $view,
			'headers' => 503 === $status ? array( 'Retry-After' => '2' ) : array(),
		);
	}

	/**
	 * The product screen with an error.
	 *
	 * @param string               $code    Code.
	 * @param int                  $status  HTTP status.
	 * @param string               $message Message.
	 * @param array<string, mixed> $prefill Quantity and method to keep.
	 * @return array<string, mixed>
	 */
	private static function product_error( string $code, int $status, string $message, array $prefill = array() ): array {
		return array(
			'status' => $status,
			'view'   => ScanScreen::with_error( ScanScreen::resolve( $code, $prefill ), $status, $message ),
		);
	}

	/**
	 * A string POST field, or ''.
	 *
	 * @param array<string, mixed> $post Fields.
	 * @param string               $key  Key.
	 */
	private static function field( array $post, string $key ): string {
		return isset( $post[ $key ] ) && is_string( $post[ $key ] ) ? $post[ $key ] : '';
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
	 * Token signature.
	 *
	 * @param array<string, string> $fields  Token fields without sig.
	 * @param int                   $user_id User.
	 */
	private static function sign( array $fields, int $user_id ): string {
		return hash_hmac( 'sha256', implode( '|', array( 'pqbg_basket', $user_id, $fields['request_id'], $fields['rev'], $fields['issued'], $fields['lines'] ) ), wp_salt( 'nonce' ) );
	}

	/**
	 * The UPI step 2 signature: the user, the basket's request ID and revision, and the amount shown.
	 *
	 * @param int    $user_id    User.
	 * @param string $request_id Request ID.
	 * @param int    $rev        Revision.
	 * @param string $amount     Amount shown on the QR.
	 */
	private static function upi_sign( int $user_id, string $request_id, int $rev, string $amount ): string {
		return hash_hmac( 'sha256', implode( '|', array( 'pqbg_basket_upi', $user_id, $request_id, $rev, $amount ) ), wp_salt( 'nonce' ) );
	}
}
