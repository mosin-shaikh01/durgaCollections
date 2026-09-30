<?php
/**
 * The front-end scan route: {home}/scan/ (entry box) and {home}/scan/{CODE}/.
 *
 * Request flow (parse_request, before the main query, canonical redirects,
 * the theme and WooCommerce Coming Soon, which all run later):
 *
 *   route unavailable (plain or index.php permalinks) → not handled
 *   logged out (any method)                          → 302 to the login page, back to the scan URL
 *   method other than GET/HEAD (or POST on a code)   → 405
 *   no pqbg_view_products                            → 403, identical for every code (no lookup)
 *   /scan/receipt/{id}/ (Phase 16, GET/HEAD only)     → the sale's receipt when Receipt::can_see(), else an
 *                                                      identical 404; 301 to the canonical URL (?paper=)
 *   POST to /scan/{CODE}/ (sell, undo; Phase 7)      → UpiSale::handle() (Phase 16: the UPI payment step),
 *                                                      which passes everything else to SaleRequest::handle();
 *                                                      400 unless the URL is canonical
 *   /scan/{CODE}/?sale={id} (sale result page)       → SaleRequest::sale_page(), or 303 to the code URL
 *                                                      when the sale is not the user's to see
 *   /scan/basket/ (Phase 17)                          → the seller's open basket (GET; 301 to the canonical
 *                                                      URL, ?added= or ?msg= after a change), POST →
 *                                                      BasketRequest::handle(); 403 without pqbg_sell
 *   /scan/basket/{id}/ (Phase 17, GET/HEAD only)      → a sold basket when the user may see it, else 303 to
 *                                                      the entry page
 *   POST to /scan/{CODE}/ with "Add to basket"       → BasketRequest::add_from_product() (Phase 17)
 *   /scan/my-sales/ (Phase 9A, GET/HEAD only)         → the seller's own sales, or 403 without
 *                                                      pqbg_view_own_sales; 301 to the canonical URL
 *   non-canonical path or query string               → 301 to the canonical URL
 *   entry box ?code=                                 → 302 to /scan/{CODE}/, or 400 "Not a valid product code."
 *   otherwise                                        → ScanScreen::resolve()
 *
 * GET and HEAD are read-only: nothing here writes to the database except the
 * rewrite-rules flag. Writes happen only on POST, in SaleService.
 * No REST routes, AJAX handlers or shortcodes.
 *
 * Page caches (Phase 12): a logged-out visitor only ever gets the 302 to the login
 * page, whatever the method, because some page caches store any logged-out HTML
 * response of a non-POST request (a 405 page stored after one PUT was then served
 * to every logged-out GET instead of the login redirect). Every response also sets
 * DONOTCACHEPAGE, DONOTMINIFY and DONOTCDN, the constants most cache and
 * optimisation plugins honour, besides the no-store headers.
 *
 * Rewrite rules are flushed once on activation and when RULES_VERSION or the
 * plugin version changes (flag option), never on ordinary requests, and are
 * removed on deactivation.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WP;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Scan route, access control, redirects and headers.
 */
final class ScanRoute {

	const ROUTE_VAR = 'pqbg_scan';
	const CODE_VAR  = 'pqbg_code';

	/** Stores the rules version last flushed; autoloaded because init reads it on every request. */
	const FLAG_OPTION = 'pqbg_rewrite_version';

	/** Bump when the rules in ScanUrl::rewrite_rules() change. */
	const RULES_VERSION = '1';

	/** Constants set on every scan response, see no_page_cache(). */
	const NO_CACHE_CONSTANTS = array( 'DONOTCACHEPAGE', 'DONOTMINIFY', 'DONOTCDN' );

	/**
	 * Hooks used on every request.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'add_rules' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'parse_request', array( __CLASS__, 'handle' ), 1 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
		add_filter( 'woocommerce_login_redirect', array( __CLASS__, 'woocommerce_login_redirect' ), 10, 2 );
	}

	/**
	 * Hooks used on admin requests.
	 */
	public static function register_admin(): void {
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
	}

	/**
	 * Adds the scan rules at the top, ahead of page and post rules.
	 */
	public static function add_rules(): void {
		foreach ( ScanUrl::rewrite_rules( self::ROUTE_VAR, self::CODE_VAR ) as $regex => $query ) {
			add_rewrite_rule( $regex, $query, 'top' );
		}
	}

	/**
	 * Flushes the rules once when they are new or changed. A soft flush: .htaccess is not rewritten.
	 */
	public static function maybe_flush(): void {
		if ( get_option( self::FLAG_OPTION ) === self::flag_value() ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::FLAG_OPTION, self::flag_value(), true );
	}

	/**
	 * Activation: adds and flushes the rules straight away.
	 */
	public static function activate(): void {
		self::add_rules();
		flush_rewrite_rules( false );
		update_option( self::FLAG_OPTION, self::flag_value(), true );
	}

	/**
	 * Deactivation: removes the rules from this request's rewrite object, flushes
	 * and deletes the flag, so a later activation flushes again.
	 */
	public static function deactivate(): void {
		global $wp_rewrite;

		remove_action( 'init', array( __CLASS__, 'add_rules' ) );

		if ( $wp_rewrite instanceof \WP_Rewrite ) {
			foreach ( array_keys( ScanUrl::rewrite_rules( self::ROUTE_VAR, self::CODE_VAR ) ) as $regex ) {
				unset( $wp_rewrite->extra_rules_top[ $regex ] );
			}
		}

		flush_rewrite_rules( false );
		delete_option( self::FLAG_OPTION );
	}

	/**
	 * Registers the public query vars that the rules fill.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::ROUTE_VAR;
		$vars[] = self::CODE_VAR;

		return $vars;
	}

	/**
	 * Whether /scan/ paths can reach WordPress: pretty permalinks without "index.php".
	 */
	public static function is_available(): bool {
		global $wp_rewrite;

		return '' !== (string) get_option( 'permalink_structure' ) && ! ( $wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->using_index_permalinks() );
	}

	/**
	 * parse_request: answers scan requests and exits.
	 *
	 * @param WP $wp Current request.
	 */
	public static function handle( $wp ): void {
		if ( ! $wp instanceof WP || ! isset( $wp->query_vars[ self::ROUTE_VAR ] ) || ! self::is_available() ) {
			return;
		}

		$code   = isset( $wp->query_vars[ self::CODE_VAR ] ) && is_string( $wp->query_vars[ self::CODE_VAR ] ) ? $wp->query_vars[ self::CODE_VAR ] : null;
		$box    = isset( $_GET['code'] ) && is_string( $_GET['code'] ) ? wp_unslash( $_GET['code'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only lookup; normalised by ScanUrl::extract_code() and escaped on output.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		self::send(
			self::decide(
				$method,
				isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only its path is compared.
				$code,
				$box,
				'POST' === $method ? wp_unslash( $_POST ) : array() // phpcs:ignore WordPress.Security.NonceVerification.Missing -- SaleRequest verifies the nonce, the capability and a signed token.
			)
		);
	}

	/**
	 * Decides the response for a scan request as the current user. No output.
	 *
	 * @param string      $method      Request method.
	 * @param string      $request_uri Request URI (path and query).
	 * @param string|null $code        Raw code segment from the path, or null for the entry page.
	 * @param string|null $box         Raw ?code= value from the entry box, or null.
	 * @param array       $post        Unslashed POST fields (POST only).
	 * @return array{status: int, location?: string, view?: array<string, mixed>, headers?: array<string, string>}
	 */
	public static function decide( string $method, string $request_uri, ?string $code, ?string $box, array $post = array() ): array {
		$is_mine    = ScanUrl::MY_SALES === $code;
		$is_receipt = ScanUrl::is_receipt_segment( $code );
		$is_basket  = ScanUrl::is_basket_segment( $code );
		$is_post    = 'POST' === $method && null !== $code && ! $is_mine && ! $is_receipt && ( ! $is_basket || ScanUrl::BASKET === $code );
		$candidate  = null === $code || $is_receipt || $is_basket ? '' : ScanUrl::extract_code( rawurldecode( $code ) );
		$valid      = '' !== $candidate && CodeGenerator::is_valid_format( $candidate );

		if ( ! is_user_logged_in() ) {
			// Any method (Phase 12: a page cache must never get a cacheable page for a logged-out visitor).
			// The login page returns to the canonical code URL, or to the entry page. Existence is never checked here.
			if ( $is_mine ) {
				$back = ScanUrl::my_sales_url();
			} elseif ( $is_receipt ) {
				$receipt = ScanUrl::receipt_id( (string) $code );
				$back    = $receipt > 0 ? ScanUrl::receipt_url( $receipt ) : ScanUrl::site_url();
			} elseif ( $is_basket ) {
				$basket = ScanUrl::basket_id( (string) $code );
				$back   = 0 === $basket ? ScanUrl::basket_url() : ( $basket > 0 ? ScanUrl::basket_sale_url( $basket ) : ScanUrl::site_url() );
			} else {
				$back = ScanUrl::site_url( $valid ? $candidate : '' );
			}

			return array(
				'status'   => 302,
				'location' => wp_login_url( $back ),
			);
		}

		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) && ! $is_post ) {
			return array(
				'status'  => 405,
				'view'    => ScanScreen::method_not_allowed(),
				'headers' => array( 'Allow' => null === $code || $is_mine || $is_receipt || ( $is_basket && ScanUrl::BASKET !== $code ) ? 'GET, HEAD' : 'GET, HEAD, POST' ),
			);
		}

		if ( ! Permissions::can_view_products() ) {
			return array(
				'status' => 403,
				'view'   => ScanScreen::forbidden(),
			);
		}

		$path  = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$query = (string) wp_parse_url( $request_uri, PHP_URL_QUERY );

		if ( $is_mine ) {
			return self::my_sales( $path, $query );
		}

		if ( $is_receipt ) {
			return self::receipt( (string) $code, $path, $query );
		}

		if ( $is_basket ) {
			return self::basket( (string) $code, $is_post, $path, $query, $post );
		}

		if ( null === $code ) {
			$entry = ScanUrl::site_url();

			if ( ScanUrl::site_path() !== $path ) {
				return array(
					'status'   => 301,
					'location' => null === $box ? $entry : add_query_arg( 'code', rawurlencode( $box ), $entry ),
				);
			}

			if ( null === $box || '' === trim( $box ) ) {
				return array(
					'status' => 200,
					'view'   => ScanScreen::entry(),
				);
			}

			$typed = ScanUrl::extract_code( $box );

			if ( '' !== $typed && CodeGenerator::is_valid_format( $typed ) ) {
				return array(
					'status'   => 302,
					'location' => ScanUrl::site_url( $typed ),
				);
			}

			return array(
				'status' => 400,
				'view'   => ScanScreen::entry( trim( $box ), true ),
			);
		}

		if ( ! $valid ) {
			return array(
				'status' => 400,
				'view'   => ScanScreen::invalid( trim( rawurldecode( $code ) ) ),
			);
		}

		$canonical = ScanUrl::site_url( $candidate );
		$is_path   = (string) wp_parse_url( $canonical, PHP_URL_PATH ) === $path;

		if ( $is_post ) {
			// A POST is never redirected: the browser would turn it into a GET and drop it.
			if ( ! $is_path || '' !== $query ) {
				return array(
					'status' => 400,
					'view'   => ScanScreen::with_error( ScanScreen::resolve( $candidate ), 400, __( 'This request could not be understood.', 'product-qrcode-barcode-generator' ) ),
				);
			}

			// Phase 17: "Add to basket" on the sale form.
			if ( BasketRequest::is_add( $post ) ) {
				return BasketRequest::add_from_product( $candidate, $post );
			}

			return UpiSale::handle( $candidate, $post );
		}

		$sale_id = $is_path ? self::sale_query( $query ) : 0;

		if ( $sale_id > 0 ) {
			$row = SaleRepository::find( $sale_id );

			// Phase 17: a basket line's page is its basket's page.
			if ( null !== $row && ! empty( $row['basket_id'] ) ) {
				return array(
					'status'   => 303,
					'location' => ScanUrl::basket_sale_url( (int) $row['basket_id'] ),
				);
			}

			$view = SaleRequest::sale_page( $candidate, $sale_id );

			// 303, never 301: whether the page may be seen depends on the user, so it must not be cached.
			return null === $view
				? array(
					'status'   => 303,
					'location' => $canonical,
				)
				: array(
					'status' => 200,
					'view'   => $view,
				);
		}

		if ( ! $is_path || '' !== $query ) {
			return array(
				'status'   => 301,
				'location' => $canonical,
			);
		}

		$view = ScanScreen::resolve( $candidate );

		return array(
			'status' => (int) $view['status'],
			'view'   => $view,
		);
	}

	/**
	 * The My sales page: the canonical URL is /scan/my-sales/ (today) or with
	 * ?range=yesterday|7d; anything else is redirected there (301).
	 *
	 * @param string $path  Request path.
	 * @param string $query Query string.
	 * @return array{status: int, location?: string, view?: array<string, mixed>}
	 */
	private static function my_sales( string $path, string $query ): array {
		$args = array();
		wp_parse_str( $query, $args );

		$range     = isset( $args['range'] ) && is_string( $args['range'] ) && array_key_exists( $args['range'], ScanScreen::MY_SALES_RANGES ) ? $args['range'] : 'today';
		$canonical = ScanUrl::my_sales_url( $range );

		if ( (string) wp_parse_url( $canonical, PHP_URL_PATH ) !== $path || (string) wp_parse_url( $canonical, PHP_URL_QUERY ) !== $query ) {
			return array(
				'status'   => 301,
				'location' => $canonical,
			);
		}

		if ( ! Permissions::can_view_own_sales() ) {
			return array(
				'status' => 403,
				'view'   => ScanScreen::sales_forbidden(),
			);
		}

		return array(
			'status' => 200,
			'view'   => ScanScreen::my_sales( get_current_user_id(), $range ),
		);
	}

	/**
	 * A receipt (Phase 16): the canonical URL is /scan/receipt/{id}/, with ?paper= only
	 * for a layout other than the default; anything else about the address is redirected
	 * there (301; it depends only on the address, not on the user). A malformed ID, a
	 * missing sale, a sale without a receipt and another seller's sale get the same 404.
	 *
	 * @param string $segment Raw code segment ("receipt/{id}").
	 * @param string $path    Request path.
	 * @param string $query   Query string.
	 * @return array{status: int, location?: string, view?: array<string, mixed>}
	 */
	private static function receipt( string $segment, string $path, string $query ): array {
		$id = ScanUrl::receipt_id( $segment );

		if ( 0 === $id ) {
			return array(
				'status' => 404,
				'view'   => ScanScreen::receipt_not_found(),
			);
		}

		$args = array();
		wp_parse_str( $query, $args );

		$paper     = isset( $args[ ScanUrl::PAPER_ARG ] ) && is_string( $args[ ScanUrl::PAPER_ARG ] ) && in_array( $args[ ScanUrl::PAPER_ARG ], Receipt::PAPERS, true ) ? $args[ ScanUrl::PAPER_ARG ] : Receipt::default_paper();
		$canonical = ScanUrl::receipt_url( $id, $paper );

		if ( (string) wp_parse_url( $canonical, PHP_URL_PATH ) !== $path || (string) wp_parse_url( $canonical, PHP_URL_QUERY ) !== $query ) {
			return array(
				'status'   => 301,
				'location' => $canonical,
			);
		}

		$sale = SaleRepository::find( $id );

		if ( ! Receipt::can_see( $sale ) ) {
			return array(
				'status' => 404,
				'view'   => ScanScreen::receipt_not_found(),
			);
		}

		// Phase 17 (D21): one receipt per basket, under the basket's number (303: only for users who may see it).
		if ( BasketService::number( $sale ) !== $id ) {
			return array(
				'status'   => 303,
				'location' => ScanUrl::receipt_url( BasketService::number( $sale ), $paper ),
			);
		}

		return array(
			'status' => 200,
			'view'   => ScanScreen::receipt( $sale, $paper ),
		);
	}

	/**
	 * The basket pages (Phase 17). /scan/basket/: the open basket (GET) or its changes
	 * (POST); the canonical GET address has no query string, or exactly one of
	 * added={CODE} and msg={updated|removed|cleared} after a change. /scan/basket/{id}/:
	 * a sold basket, for its seller and managers; anyone else gets a 303 to the entry page.
	 *
	 * @param string               $segment Raw code segment ("basket" or "basket/{id}").
	 * @param bool                 $is_post Whether this is a POST (only to "basket").
	 * @param string               $path    Request path.
	 * @param string               $query   Query string.
	 * @param array<string, mixed> $post    Unslashed POST fields.
	 * @return array{status: int, location?: string, view?: array<string, mixed>, headers?: array<string, string>}
	 */
	private static function basket( string $segment, bool $is_post, string $path, string $query, array $post ): array {
		$id = ScanUrl::basket_id( $segment );

		if ( $id < 0 ) {
			return array(
				'status' => 404,
				'view'   => ScanScreen::with_error( ScanScreen::entry(), 404, __( 'Sale not found.', 'product-qrcode-barcode-generator' ) ),
			);
		}

		if ( $id > 0 ) {
			$canonical = ScanUrl::basket_sale_url( $id );

			if ( (string) wp_parse_url( $canonical, PHP_URL_PATH ) !== $path || '' !== $query ) {
				return array(
					'status'   => 301,
					'location' => $canonical,
				);
			}

			$lines = BasketService::lines( $id );

			// 303, never 301: whether the page may be seen depends on the user.
			if ( array() === $lines || ! Permissions::can_view_sale( (int) $lines[0]['seller_id'] ) ) {
				return array(
					'status'   => 303,
					'location' => ScanUrl::site_url(),
				);
			}

			return array(
				'status' => 200,
				'view'   => ScanScreen::basket_sale( $lines ),
			);
		}

		$canonical = ScanUrl::basket_url();
		$is_path   = (string) wp_parse_url( $canonical, PHP_URL_PATH ) === $path;

		if ( $is_post ) {
			// A POST is never redirected: the browser would turn it into a GET and drop it.
			if ( ! $is_path || '' !== $query ) {
				return array(
					'status' => 400,
					'view'   => ScanScreen::with_error( ScanScreen::entry(), 400, __( 'This request could not be understood.', 'product-qrcode-barcode-generator' ) ),
				);
			}

			return BasketRequest::handle( $post );
		}

		$args = array();
		wp_parse_str( $query, $args );

		$added = isset( $args['added'] ) && is_string( $args['added'] ) && CodeGenerator::is_valid_format( $args['added'] ) ? $args['added'] : '';
		$msg   = isset( $args['msg'] ) && is_string( $args['msg'] ) && in_array( $args['msg'], array( 'updated', 'removed', 'cleared' ), true ) ? $args['msg'] : '';
		$want  = '' !== $added ? ScanUrl::basket_url( array( 'added' => $added ) ) : ( '' !== $msg ? ScanUrl::basket_url( array( 'msg' => $msg ) ) : $canonical );

		if ( ! $is_path || (string) wp_parse_url( $want, PHP_URL_QUERY ) !== $query ) {
			return array(
				'status'   => 301,
				'location' => $canonical,
			);
		}

		if ( ! Permissions::can_sell() ) {
			return array(
				'status' => 403,
				'view'   => ScanScreen::sell_forbidden(),
			);
		}

		$notices = array();

		if ( '' !== $added ) {
			$quantity = 0;

			foreach ( BasketStore::get( get_current_user_id() )['lines'] as $line ) {
				$quantity = $line['code'] === $added ? $line['quantity'] : $quantity;
			}

			if ( $quantity > 0 ) {
				/* translators: 1: item, 2: its quantity in the basket now. */
				$notices[] = array( 'success', sprintf( __( 'Added: %1$s (now %2$s in the basket).', 'product-qrcode-barcode-generator' ), ScanScreen::code_name( $added ), number_format_i18n( $quantity ) ) );
			}
		} elseif ( '' !== $msg ) {
			$texts     = array(
				'updated' => __( 'Quantity changed.', 'product-qrcode-barcode-generator' ),
				'removed' => __( 'Item removed from the basket.', 'product-qrcode-barcode-generator' ),
				'cleared' => __( 'The basket was cleared.', 'product-qrcode-barcode-generator' ),
			);
			$notices[] = array( 'success', $texts[ $msg ] );
		}

		return array(
			'status' => 200,
			'view'   => ScanScreen::basket( get_current_user_id(), array(), array( 'notices' => $notices ) ),
		);
	}

	/**
	 * The sale ID when the query string is exactly "sale={digits}", else 0.
	 *
	 * @param string $query Query string.
	 */
	private static function sale_query( string $query ): int {
		return 1 === preg_match( '/^' . SaleRequest::SALE_ARG . '=([1-9][0-9]{0,18})$/', $query, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Headers sent with every scan response, including redirects.
	 *
	 * @return array<string, string>
	 */
	public static function security_headers(): array {
		return array(
			'Cache-Control'           => 'no-store, no-cache, must-revalidate, max-age=0, private',
			'Expires'                 => 'Wed, 11 Jan 1984 05:00:00 GMT',
			'X-Robots-Tag'            => 'noindex, nofollow',
			'Referrer-Policy'         => 'same-origin',
			'X-Frame-Options'         => 'DENY',
			'X-Content-Type-Options'  => 'nosniff',
			'Content-Security-Policy' => self::csp(),
		);
	}

	/**
	 * The scan pages' Content-Security-Policy. No scripts at all. Styles only from this site,
	 * plus, when a page needs one (Phase 11: the Undo expiry delay), one style element
	 * carrying this response's nonce.
	 *
	 * @param string $style_nonce Nonce of the page's style element, or ''.
	 */
	public static function csp( string $style_nonce = '' ): string {
		$style = "style-src 'self'" . ( '' === $style_nonce ? '' : " 'nonce-" . $style_nonce . "'" );

		return "default-src 'none'; {$style}; img-src 'self' https: data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'";
	}

	/**
	 * The receipt page's Content-Security-Policy (Phase 16): csp() plus exactly one
	 * script, the element carrying this response's script nonce (the Print button,
	 * assets/pqbg-receipt.js). Every other scan page keeps csp(), with no script.
	 *
	 * @param string $style_nonce  Nonce of the page's style element.
	 * @param string $script_nonce Nonce of the page's script element.
	 */
	public static function receipt_csp( string $style_nonce, string $script_nonce ): string {
		return self::csp( $style_nonce ) . "; script-src 'nonce-" . $script_nonce . "'";
	}

	/**
	 * login_redirect (wp-login.php): staff who cannot use wp-admin and asked for no
	 * particular page go to the scan entry page instead of being bounced to My Account.
	 *
	 * @param string          $redirect_to Destination.
	 * @param string          $requested   Destination that was requested.
	 * @param WP_User|mixed   $user        User, or an error on a failed login.
	 * @return string
	 */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( ! $user instanceof WP_User || ! self::is_available() || ! Permissions::can_view_products( $user->ID ) ) {
			return $redirect_to;
		}

		$requested = is_string( $requested ) ? $requested : '';

		if ( ! user_can( $user, 'edit_posts' ) && AdminUrl::is_admin_home( $requested ) ) {
			return ScanUrl::site_url();
		}

		return $redirect_to;
	}

	/**
	 * woocommerce_login_redirect (My Account form): WooCommerce redirects to the
	 * referer, which is the My Account URL. Staff who reached it with a scan URL
	 * in redirect_to go there; staff who cannot use wp-admin and asked for no
	 * particular page go to the scan entry page.
	 *
	 * @param string        $redirect Destination.
	 * @param WP_User|mixed $user     User.
	 * @return string
	 */
	public static function woocommerce_login_redirect( $redirect, $user ) {
		if ( ! $user instanceof WP_User || ! is_string( $redirect ) || ! self::is_available() || ! Permissions::can_view_products( $user->ID ) ) {
			return $redirect;
		}

		if ( ScanUrl::is_site_scan_url( $redirect ) ) {
			return $redirect;
		}

		$args = array();
		wp_parse_str( (string) wp_parse_url( $redirect, PHP_URL_QUERY ), $args );

		if ( isset( $args['redirect_to'] ) && ScanUrl::is_site_scan_url( $args['redirect_to'] ) ) {
			return $args['redirect_to'];
		}

		$account = wc_get_page_permalink( 'myaccount' );

		if ( ! user_can( $user, 'edit_posts' ) && wp_parse_url( $redirect, PHP_URL_PATH ) === wp_parse_url( $account, PHP_URL_PATH ) ) {
			return ScanUrl::site_url();
		}

		return $redirect;
	}

	/**
	 * Admin notices: permalinks that cannot serve scan URLs (for users who manage settings
	 * or codes; code managers print labels, Phase 12), and content whose address is taken
	 * over by the scan route (for users who manage settings).
	 */
	public static function admin_notices(): void {
		$settings = Permissions::can_manage_settings();

		if ( ! $settings && ! Permissions::can_manage_codes() ) {
			return;
		}

		$messages = array();

		if ( ! self::is_available() ) {
			$messages[] = sprintf(
				/* translators: %s: scan page address. */
				__( 'Scan links such as %s need pretty permalinks. They do not work with the "Plain" permalink setting or with permalinks that contain index.php, so every printed label opens an error page. Choose another structure under Settings → Permalinks.', 'product-qrcode-barcode-generator' ),
				ScanUrl::site_url()
			);
		}

		foreach ( $settings ? self::conflicts() : array() as $label ) {
			$messages[] = sprintf(
				/* translators: 1: content title and type, 2: scan page address. */
				__( '%1$s uses an address at or below %2$s. The scan page takes precedence, so visitors cannot reach that content. Change its slug.', 'product-qrcode-barcode-generator' ),
				$label,
				ScanUrl::site_url()
			);
		}

		foreach ( $messages as $message ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Product QR Code and Barcode Generator:', 'product-qrcode-barcode-generator' ) . '</strong> ' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * Content whose permalink is the scan path or below it: public posts of any
	 * type (not trashed) and public terms whose slug is the scan path segment.
	 *
	 * @return string[] Labels such as "Page “Scan” (#12)".
	 */
	public static function conflicts(): array {
		$base   = ScanUrl::site_path();
		$labels = array();
		$taken  = static fn( $url ) => is_string( $url ) && ScanUrl::is_site_scan_url( $url ) && str_starts_with( (string) wp_parse_url( $url, PHP_URL_PATH ) . '/', $base );

		$posts = get_posts(
			array(
				'name'             => ScanUrl::PATH,
				'post_type'        => array_values( get_post_types( array( 'public' => true ) ) ),
				'post_status'      => array( 'publish', 'private', 'draft', 'pending', 'future' ),
				'numberposts'      => 10,
				'suppress_filters' => true,
			)
		);

		foreach ( $posts as $post ) {
			if ( $taken( get_permalink( $post ) ) ) {
				$type     = get_post_type_object( $post->post_type );
				$labels[] = sprintf( '%s “%s” (#%d)', $type ? $type->labels->singular_name : $post->post_type, get_the_title( $post ), $post->ID );
			}
		}

		$terms = get_terms(
			array(
				'taxonomy'   => array_values( get_taxonomies( array( 'public' => true ) ) ),
				'slug'       => ScanUrl::PATH,
				'hide_empty' => false,
			)
		);

		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$link = get_term_link( $term );

			if ( ! is_wp_error( $link ) && $taken( $link ) ) {
				$tax      = get_taxonomy( $term->taxonomy );
				$labels[] = sprintf( '%s “%s” (#%d)', $tax ? $tax->labels->singular_name : $term->taxonomy, $term->name, $term->term_id );
			}
		}

		return $labels;
	}

	/**
	 * Sends a decided response and ends the request.
	 *
	 * @param array{status: int, location?: string, view?: array<string, mixed>, headers?: array<string, string>} $response Response.
	 */
	private static function send( array $response ): void {
		self::no_page_cache();

		$headers = array_merge( self::security_headers(), $response['headers'] ?? array() );

		if ( isset( $response['view']['style_nonce'] ) && '' !== $response['view']['style_nonce'] ) {
			$headers['Content-Security-Policy'] = self::csp( (string) $response['view']['style_nonce'] );
		}

		// Phase 16: only the receipt page has a script nonce.
		if ( isset( $response['view']['script_nonce'] ) && '' !== $response['view']['script_nonce'] ) {
			$headers['Content-Security-Policy'] = self::receipt_csp( (string) $response['view']['style_nonce'], (string) $response['view']['script_nonce'] );
		}

		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}

		header_remove( 'Last-Modified' );

		if ( isset( $response['location'] ) ) {
			wp_safe_redirect( $response['location'], $response['status'], 'Product QR Code and Barcode Generator' );
			exit;
		}

		status_header( $response['status'] );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );

		if ( 'HEAD' !== strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key().
			echo ScanScreen::render( $response['view'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes every value.
		}

		exit;
	}

	/**
	 * The constants page-cache and optimisation plugins check before storing, minifying or
	 * CDN-rewriting a response (Phase 12). Scan pages are per-user, and their CSP allows only
	 * this site's own stylesheet, so an inlined, combined or CDN-hosted copy would be blocked.
	 * Defined for scan responses only; a constant already defined elsewhere is left alone.
	 */
	public static function no_page_cache(): void {
		foreach ( self::NO_CACHE_CONSTANTS as $name ) {
			if ( ! defined( $name ) ) {
				define( $name, true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- the unprefixed names cache plugins check.
			}
		}
	}

	/**
	 * Value stored in FLAG_OPTION once the current rules are flushed.
	 */
	private static function flag_value(): string {
		return PQBG_VERSION . ':' . self::RULES_VERSION;
	}
}
