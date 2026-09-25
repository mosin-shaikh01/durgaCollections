<?php
/**
 * The scan page's POST actions (sell, undo), the sale result page and the
 * sale form token.
 *
 * ScanRoute has already checked, before calling in: logged in,
 * pqbg_view_products, a well-formed code and the canonical URL.
 *
 * Sale form token (issued with every rendered sale form):
 *   request_id  UUID v4 from random_bytes(): the sale's idempotency key
 *   issued      Unix time the form was rendered (FORM_TTL applies)
 *   seen_price  price shown, normalised to the store's decimals
 *   seen_stock  stock shown
 *   sig         HMAC-SHA256 over the fields above, the user and the code row,
 *               keyed with wp_salt( 'nonce' )
 * seen_price and seen_stock are only compared with fresh values; the recorded
 * price and stock always come from WooCommerce (SaleService).
 *
 * Order: nonce → signature → an existing sale with this request ID (its
 * outcome is returned even for an expired form, so a resubmission never sells
 * twice) → expiry → quantity → SaleService::sell().
 *
 * Results: a completed sale answers 303 to /scan/{CODE}/?sale={id} (reloading
 * it is a read-only GET). Errors show the product screen with the message and,
 * where the item is still sellable, a fresh form.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sell and undo requests.
 */
final class SaleRequest {

	/** Seconds a rendered sale form stays usable. */
	const FORM_TTL = 1800;

	const ACTION_FIELD = 'pqbg_action';

	/** Query argument of the sale result page. */
	const SALE_ARG = 'sale';

	/**
	 * Hidden fields for a new sale form.
	 *
	 * @param int      $user_id User the form is for.
	 * @param int      $code_id Code row ID.
	 * @param string   $price   Normalised price shown.
	 * @param int      $stock   Stock shown.
	 * @param int|null $now     Issue time (tests).
	 * @return array<string, string>
	 */
	public static function issue_token( int $user_id, int $code_id, string $price, int $stock, ?int $now = null ): array {
		$fields = array(
			'request_id' => self::uuid4(),
			'issued'     => (string) ( $now ?? time() ),
			'seen_price' => $price,
			'seen_stock' => (string) $stock,
		);

		$fields['sig'] = self::sign( $fields, $user_id, $code_id );

		return $fields;
	}

	/**
	 * Checks a submitted token.
	 *
	 * @param array<string, mixed> $post    Submitted fields.
	 * @param int                  $user_id Current user.
	 * @param int                  $code_id Code row ID.
	 * @return array{request_id: string, seen_price: string, seen_stock: int, expired: bool}|WP_Error
	 */
	public static function verify_token( array $post, int $user_id, int $code_id ) {
		$fields = array();

		foreach ( array( 'request_id', 'issued', 'seen_price', 'seen_stock', 'sig' ) as $key ) {
			if ( ! isset( $post[ $key ] ) || ! is_string( $post[ $key ] ) ) {
				return new WP_Error( 'pqbg_bad_token', __( 'This form is not valid. Check the item and confirm again.', 'product-qrcode-barcode-generator' ) );
			}
			$fields[ $key ] = $post[ $key ];
		}

		$sig = $fields['sig'];
		unset( $fields['sig'] );

		if ( ! wp_is_uuid( $fields['request_id'], 4 ) || ! ctype_digit( $fields['issued'] ) || ! ctype_digit( $fields['seen_stock'] ) || ! hash_equals( self::sign( $fields, $user_id, $code_id ), $sig ) ) {
			return new WP_Error( 'pqbg_bad_token', __( 'This form is not valid. Check the item and confirm again.', 'product-qrcode-barcode-generator' ) );
		}

		$age = time() - (int) $fields['issued'];

		return array(
			'request_id' => $fields['request_id'],
			'seen_price' => $fields['seen_price'],
			'seen_stock' => (int) $fields['seen_stock'],
			'expired'    => $age > self::FORM_TTL || $age < -60,
		);
	}

	/**
	 * Answers a POST to /scan/{CODE}/.
	 *
	 * @param string               $code Valid, canonical code.
	 * @param array<string, mixed> $post Unslashed POST fields.
	 * @return array{status: int, location?: string, view?: array<string, mixed>, headers?: array<string, string>}
	 */
	public static function handle( string $code, array $post ): array {
		$action = isset( $post[ self::ACTION_FIELD ] ) && is_string( $post[ self::ACTION_FIELD ] ) ? $post[ self::ACTION_FIELD ] : '';

		if ( ! in_array( $action, array( 'sell', 'undo' ), true ) ) {
			return self::product_error( $code, 400, __( 'This request could not be understood.', 'product-qrcode-barcode-generator' ) );
		}

		if ( ! Permissions::can_sell() ) {
			return self::product_error( $code, 403, __( 'You do not have permission to sell.', 'product-qrcode-barcode-generator' ) );
		}

		$row = CodeRepository::find_by_code( $code );

		if ( null === $row ) {
			$view = ScanScreen::resolve( $code );
			return array(
				'status' => (int) $view['status'],
				'view'   => $view,
			);
		}

		return 'sell' === $action ? self::sell( $code, $row, $post ) : self::undo( $code, $row, $post );
	}

	/**
	 * The sale result page, or null when the sale does not exist, belongs to
	 * another code, or the user may not see it (the caller redirects then).
	 *
	 * @param string $code    Valid, canonical code.
	 * @param int    $sale_id Sale ID.
	 * @return array<string, mixed>|null View.
	 */
	public static function sale_page( string $code, int $sale_id ): ?array {
		$row  = CodeRepository::find_by_code( $code );
		$sale = SaleRepository::find( $sale_id );

		if ( null === $row || null === $sale || (int) $sale['code_id'] !== (int) $row['id'] || ! Permissions::can_view_sale( (int) $sale['seller_id'] ) ) {
			return null;
		}

		return ScanScreen::sale( $sale, $code );
	}

	/**
	 * URL of a sale's result page.
	 *
	 * @param string $code    Code.
	 * @param int    $sale_id Sale ID.
	 */
	public static function sale_url( string $code, int $sale_id ): string {
		return add_query_arg( self::SALE_ARG, $sale_id, ScanUrl::site_url( $code ) );
	}

	/**
	 * Sell.
	 *
	 * @param string                $code Code.
	 * @param array<string, string> $row  Code row.
	 * @param array<string, mixed>  $post Fields.
	 * @return array<string, mixed>
	 */
	private static function sell( string $code, array $row, array $post ): array {
		$user  = get_current_user_id();
		$nonce = isset( $post[ Permissions::NONCE_FIELD ] ) && is_string( $post[ Permissions::NONCE_FIELD ] ) ? $post[ Permissions::NONCE_FIELD ] : '';

		if ( ! wp_verify_nonce( $nonce, Permissions::nonce_action( 'sell_' . $row['id'] ) ) ) {
			return self::product_error( $code, 403, __( 'This form is no longer valid. Check the item and confirm again.', 'product-qrcode-barcode-generator' ) );
		}

		$token = self::verify_token( $post, $user, (int) $row['id'] );

		if ( is_wp_error( $token ) ) {
			return self::product_error( $code, 400, $token->get_error_message() );
		}

		$existing = SaleRepository::find_by_request_id( $token['request_id'] );
		$quantity = null !== $existing ? (int) $existing['quantity'] : self::quantity( $post['quantity'] ?? null );

		if ( null === $existing && $token['expired'] ) {
			return self::product_error( $code, 400, __( 'This sale form has expired. Check the item and confirm again.', 'product-qrcode-barcode-generator' ) );
		}

		if ( null === $quantity ) {
			/* translators: %s: stock quantity shown on the form. */
			return self::product_error( $code, 400, sprintf( __( 'Choose a quantity between 1 and %s.', 'product-qrcode-barcode-generator' ), number_format_i18n( max( 1, $token['seen_stock'] ) ) ) );
		}

		$result = SaleService::sell(
			array(
				'code'       => $code,
				'quantity'   => $quantity,
				'request_id' => $token['request_id'],
				'seller_id'  => $user,
				'seen_price' => $token['seen_price'],
				'seen_stock' => $token['seen_stock'],
			)
		);

		if ( is_wp_error( $result ) ) {
			return self::error_response( $code, $result );
		}

		if ( SaleRepository::STATUS_COMPLETED === $result['status'] ) {
			return array(
				'status'   => 303,
				'location' => self::sale_url( $code, (int) $result['sale']['id'] ),
			);
		}

		$failure = (string) ( $result['sale']['failure_code'] ?? '' );

		return SaleRepository::FAILURE_SOLD_ONLINE === $failure
			? self::product_error( $code, 409, __( 'This item just sold online. Stock was not changed.', 'product-qrcode-barcode-generator' ) )
			: self::product_error( $code, 500, __( 'The sale could not be completed. Stock was not changed.', 'product-qrcode-barcode-generator' ) );
	}

	/**
	 * Undo.
	 *
	 * @param string                $code Code.
	 * @param array<string, string> $row  Code row.
	 * @param array<string, mixed>  $post Fields.
	 * @return array<string, mixed>
	 */
	private static function undo( string $code, array $row, array $post ): array {
		$user    = get_current_user_id();
		$sale_id = isset( $post['sale'] ) && is_string( $post['sale'] ) && ctype_digit( $post['sale'] ) ? (int) $post['sale'] : 0;
		$nonce   = isset( $post[ Permissions::NONCE_FIELD ] ) && is_string( $post[ Permissions::NONCE_FIELD ] ) ? $post[ Permissions::NONCE_FIELD ] : '';
		$sale    = SaleRepository::find( $sale_id );

		if ( null === $sale || (int) $sale['code_id'] !== (int) $row['id'] ) {
			return self::product_error( $code, 400, __( 'This request could not be understood.', 'product-qrcode-barcode-generator' ) );
		}

		if ( ! wp_verify_nonce( $nonce, Permissions::nonce_action( 'undo_' . $sale_id ) ) ) {
			return self::sale_error( $code, $sale, 403, __( 'This form is no longer valid. Reload the page and try again.', 'product-qrcode-barcode-generator' ) );
		}

		$result = SaleService::undo( $sale_id, $user );

		if ( is_wp_error( $result ) ) {
			return self::sale_error( $code, SaleRepository::find( $sale_id ) ?? $sale, self::status_for( $result->get_error_code() ), $result->get_error_message(), self::headers_for( $result->get_error_code() ) );
		}

		return array(
			'status'   => 303,
			'location' => self::sale_url( $code, $sale_id ),
		);
	}

	/**
	 * A quantity field as a positive integer, or null.
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
	 * Product screen (with a fresh form where sellable) plus an error.
	 *
	 * @param string                $code    Code.
	 * @param int                   $status  HTTP status.
	 * @param string                $message Message.
	 * @param array<string, string> $headers Extra headers.
	 * @return array<string, mixed>
	 */
	private static function product_error( string $code, int $status, string $message, array $headers = array() ): array {
		return array(
			'status'  => $status,
			'view'    => ScanScreen::with_error( ScanScreen::resolve( $code ), $status, $message ),
			'headers' => $headers,
		);
	}

	/**
	 * Sale page (or the product screen, if the user may not see the sale) plus an error.
	 *
	 * @param string                $code    Code.
	 * @param array<string, string> $sale    Sale row.
	 * @param int                   $status  HTTP status.
	 * @param string                $message Message.
	 * @param array<string, string> $headers Extra headers.
	 * @return array<string, mixed>
	 */
	private static function sale_error( string $code, array $sale, int $status, string $message, array $headers = array() ): array {
		if ( ! Permissions::can_view_sale( (int) $sale['seller_id'] ) ) {
			return self::product_error( $code, $status, $message, $headers );
		}

		return array(
			'status'  => $status,
			'view'    => ScanScreen::with_error( ScanScreen::sale( $sale, $code ), $status, $message ),
			'headers' => $headers,
		);
	}

	/**
	 * Response for a refused sale.
	 *
	 * @param string   $code  Code.
	 * @param WP_Error $error Error.
	 * @return array<string, mixed>
	 */
	private static function error_response( string $code, WP_Error $error ): array {
		return self::product_error( $code, self::status_for( $error->get_error_code() ), $error->get_error_message(), self::headers_for( $error->get_error_code() ) );
	}

	/**
	 * HTTP status for an error code.
	 *
	 * @param string $code Error code.
	 */
	public static function status_for( string $code ): int {
		$map = array(
			'pqbg_forbidden'           => 403,
			'pqbg_not_own_sale'        => 403,
			'pqbg_bad_request'         => 400,
			'pqbg_invalid_quantity'    => 400,
			'pqbg_insufficient_stock'  => 400,
			'pqbg_code_not_found'      => 404,
			'pqbg_sale_not_found'      => 404,
			'pqbg_busy'                => 503,
			'pqbg_in_transaction'      => 500,
			'pqbg_sale_write_failed'   => 500,
			'pqbg_restore_failed'      => 500,
			'pqbg_compensation_failed' => 500,
		);

		return $map[ $code ] ?? 409;
	}

	/**
	 * Extra headers for an error code.
	 *
	 * @param string $code Error code.
	 * @return array<string, string>
	 */
	private static function headers_for( string $code ): array {
		return 'pqbg_busy' === $code ? array( 'Retry-After' => '2' ) : array();
	}

	/**
	 * Token signature.
	 *
	 * @param array<string, string> $fields  Token fields without sig.
	 * @param int                   $user_id User.
	 * @param int                   $code_id Code row ID.
	 */
	private static function sign( array $fields, int $user_id, int $code_id ): string {
		return hash_hmac( 'sha256', implode( '|', array( 'pqbg_sale', $user_id, $code_id, $fields['request_id'], $fields['issued'], $fields['seen_price'], $fields['seen_stock'] ) ), wp_salt( 'nonce' ) );
	}

	/**
	 * RFC 4122 version 4 UUID from the CSPRNG.
	 */
	private static function uuid4(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}
}
