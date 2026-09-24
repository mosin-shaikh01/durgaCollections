<?php
/**
 * Authenticated admin handlers for product codes, on admin-post.php.
 *
 *   pqbg_generate    POST  assign a code to an item that has none
 *   pqbg_regenerate  POST  atomically replace an item's code (after the confirmation page)
 *   pqbg_code_image  GET   the QR or barcode SVG of an item's ACTIVE code, inline
 *                          (mode=view) or as a download (mode=download); no side effects
 *
 * Every handler checks the request method, a nonce bound to the item and
 * Permissions::MANAGE_CODES. Only admin_post_* is hooked, never admin_post_nopriv_*,
 * so logged-out requests never reach this class. The item is always resolved
 * server-side from its ID: no handler accepts a code string, so a retired code
 * can never be served.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * admin-post.php handlers.
 */
final class AdminActions {

	const GENERATE   = 'pqbg_generate';
	const REGENERATE = 'pqbg_regenerate';
	const IMAGE      = 'pqbg_code_image';

	/** Messages AdminProductPanel may show after a redirect, keyed by the pqbg_msg value. */
	const MESSAGE_ARG = 'pqbg_msg';

	/**
	 * Hooks the handlers. Admin requests only.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::GENERATE, array( __CLASS__, 'handle_generate' ) );
		add_action( 'admin_post_' . self::REGENERATE, array( __CLASS__, 'handle_regenerate' ) );
		add_action( 'admin_post_' . self::IMAGE, array( __CLASS__, 'handle_image' ) );
	}

	/**
	 * Nonce action bound to one item, e.g. "pqbg_generate_code_123".
	 *
	 * @param string $verb    Operation.
	 * @param int    $item_id Product or variation ID.
	 */
	public static function nonce_action( string $verb, int $item_id ): string {
		return Permissions::nonce_action( $verb . '_' . $item_id );
	}

	/**
	 * URL of an item's QR or barcode SVG.
	 *
	 * @param int    $item_id Product or variation ID.
	 * @param string $type    "qr" or "barcode".
	 * @param string $mode    "view" or "download".
	 */
	public static function image_url( int $item_id, string $type, string $mode ): string {
		return add_query_arg(
			array(
				'action'                 => self::IMAGE,
				'item'                   => $item_id,
				'type'                   => $type,
				'mode'                   => $mode,
				Permissions::NONCE_FIELD => wp_create_nonce( self::nonce_action( 'code_image', $item_id ) ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * POST: assigns a code to an item without one.
	 */
	public static function handle_generate(): void {
		$item_id = self::guard( 'POST', 'generate_code' );
		$result  = ( new ProductCodeService() )->get_or_create( $item_id, get_current_user_id() );

		self::redirect( $item_id, is_wp_error( $result ) ? $result->get_error_code() : 'generated' );
	}

	/**
	 * POST: replaces an item's code. The form comes from the confirmation page.
	 */
	public static function handle_regenerate(): void {
		$item_id  = self::guard( 'POST', 'regenerate_code' );
		$expected = isset( $_POST['expected'] ) ? absint( wp_unslash( $_POST['expected'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().

		if ( $expected <= 0 ) {
			self::fail( 400, __( 'Missing code reference.', 'product-qrcode-barcode-generator' ) );
		}

		$result = ( new ProductCodeService() )->regenerate( $item_id, get_current_user_id(), $expected );

		self::redirect( $item_id, is_wp_error( $result ) ? $result->get_error_code() : 'regenerated' );
	}

	/**
	 * GET: serves the SVG for an item's active code. Never generates anything.
	 */
	public static function handle_image(): void {
		$item_id = self::guard( 'GET', 'code_image' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified in guard().
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '';
		// phpcs:enable

		if ( ! in_array( $type, array( 'qr', 'barcode' ), true ) || ! in_array( $mode, array( 'view', 'download' ), true ) ) {
			self::fail( 400, __( 'Invalid request.', 'product-qrcode-barcode-generator' ) );
		}

		// Checked before anything else so the barcode library is never reached while disabled.
		if ( 'barcode' === $type && ! Settings::is_barcode_enabled() ) {
			self::fail( 404, __( 'Barcodes are disabled.', 'product-qrcode-barcode-generator' ) );
		}

		$row = CodeRepository::find_active_for_product( $item_id );

		if ( null === $row ) {
			self::fail( 404, __( 'This item has no active product code.', 'product-qrcode-barcode-generator' ) );
		}

		$code = $row['code'];
		$svg  = 'qr' === $type ? ( new QrRenderer() )->render( $code ) : ( new BarcodeRenderer() )->render( $code );

		if ( is_wp_error( $svg ) ) {
			self::fail( 500, __( 'The image could not be rendered.', 'product-qrcode-barcode-generator' ) );
		}

		$disposition = 'download' === $mode ? 'attachment' : 'inline';

		nocache_headers();
		header( 'Content-Type: image/svg+xml; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		header( 'Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; sandbox' );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . $code . '-' . $type . '.svg"' );
		header( 'Content-Length: ' . strlen( $svg ) );

		echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Svg builds fixed markup; the only text is the validated, XML-escaped code.
		exit;
	}

	/**
	 * Checks method, item, nonce and capability; stops the request on failure.
	 *
	 * @param string $method Required HTTP method.
	 * @param string $verb   Nonce verb.
	 * @return int The item ID.
	 */
	private static function guard( string $method, string $verb ): int {
		$actual = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( $actual !== $method && ! ( 'GET' === $method && 'HEAD' === $actual ) ) {
			header( 'Allow: ' . $method );
			self::fail( 405, __( 'Method not allowed.', 'product-qrcode-barcode-generator' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification -- this is the nonce check.
		$source  = 'POST' === $method ? $_POST : $_GET;
		$item_id = isset( $source['item'] ) ? absint( wp_unslash( $source['item'] ) ) : 0;
		$nonce   = isset( $source[ Permissions::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $source[ Permissions::NONCE_FIELD ] ) ) : '';
		// phpcs:enable

		if ( ! Permissions::can_manage_codes() ) {
			self::fail( 403, __( 'You are not allowed to manage product codes.', 'product-qrcode-barcode-generator' ) );
		}

		if ( $item_id <= 0 || ! wp_verify_nonce( $nonce, self::nonce_action( $verb, $item_id ) ) ) {
			self::fail( 403, __( 'The link you followed has expired. Reload the product page and try again.', 'product-qrcode-barcode-generator' ) );
		}

		$type = get_post_type( $item_id );

		if ( 'product' !== $type && 'product_variation' !== $type ) {
			self::fail( 404, __( 'Product not found.', 'product-qrcode-barcode-generator' ) );
		}

		return $item_id;
	}

	/**
	 * Redirects to the product edit screen (the parent's, for a variation) with a result message.
	 *
	 * @param int    $item_id Product or variation ID.
	 * @param string $message Result key.
	 */
	private static function redirect( int $item_id, string $message ): void {
		$product_id = 'product_variation' === get_post_type( $item_id ) ? (int) wp_get_post_parent_id( $item_id ) : $item_id;

		$url = add_query_arg(
			array(
				'post'            => $product_id,
				'action'          => 'edit',
				self::MESSAGE_ARG => sanitize_key( $message ),
			),
			admin_url( 'post.php' )
		);

		wp_safe_redirect( $url, 303 );
		exit;
	}

	/**
	 * Stops with an error page and status.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Message.
	 * @return never
	 */
	private static function fail( int $status, string $message ): void {
		wp_die( esc_html( $message ), esc_html__( 'Product codes', 'product-qrcode-barcode-generator' ), array( 'response' => $status ) );
	}
}
