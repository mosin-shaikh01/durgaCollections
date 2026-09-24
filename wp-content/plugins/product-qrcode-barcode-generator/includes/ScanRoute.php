<?php
/**
 * The front-end scan route: {home}/scan/ (entry box) and {home}/scan/{CODE}/.
 *
 * Request flow (parse_request, before the main query, canonical redirects,
 * the theme and WooCommerce Coming Soon, which all run later):
 *
 *   route unavailable (plain or index.php permalinks) → not handled
 *   method other than GET/HEAD                       → 405
 *   logged out                                       → 302 to the login page, back to the scan URL
 *   no pqbg_view_products                            → 403, identical for every code (no lookup)
 *   non-canonical path or query string               → 301 to the canonical URL
 *   entry box ?code=                                 → 302 to /scan/{CODE}/, or 400 "Not a valid product code."
 *   otherwise                                        → ScanScreen::resolve()
 *
 * Read-only: nothing here writes to the database except the rewrite-rules flag.
 * No REST routes, AJAX handlers or shortcodes.
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

		$code = isset( $wp->query_vars[ self::CODE_VAR ] ) && is_string( $wp->query_vars[ self::CODE_VAR ] ) ? $wp->query_vars[ self::CODE_VAR ] : null;
		$box  = isset( $_GET['code'] ) && is_string( $_GET['code'] ) ? wp_unslash( $_GET['code'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only lookup; normalised by ScanUrl::extract_code() and escaped on output.

		self::send(
			self::decide(
				isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET',
				isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only its path is compared.
				$code,
				$box
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
	 * @return array{status: int, location?: string, view?: array<string, mixed>}
	 */
	public static function decide( string $method, string $request_uri, ?string $code, ?string $box ): array {
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return array(
				'status' => 405,
				'view'   => ScanScreen::method_not_allowed(),
			);
		}

		$candidate = null === $code ? '' : ScanUrl::extract_code( rawurldecode( $code ) );
		$valid     = '' !== $candidate && CodeGenerator::is_valid_format( $candidate );

		if ( ! is_user_logged_in() ) {
			// The login page returns to the canonical code URL, or to the entry page. Existence is never checked here.
			return array(
				'status'   => 302,
				'location' => wp_login_url( ScanUrl::site_url( $valid ? $candidate : '' ) ),
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

		if ( (string) wp_parse_url( $canonical, PHP_URL_PATH ) !== $path || '' !== $query ) {
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
			'Content-Security-Policy' => "default-src 'none'; style-src 'self'; img-src 'self' https: data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
		);
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

		if ( ! user_can( $user, 'edit_posts' ) && in_array( $requested, array( '', 'wp-admin/', admin_url(), admin_url( 'profile.php' ) ), true ) ) {
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
	 * Admin notices for users who can manage settings: permalinks that cannot
	 * serve scan URLs, and content whose address is taken over by the scan route.
	 */
	public static function admin_notices(): void {
		if ( ! Permissions::can_manage_settings() ) {
			return;
		}

		$messages = array();

		if ( ! self::is_available() ) {
			$messages[] = sprintf(
				/* translators: %s: scan page address. */
				__( 'Scan links such as %s need pretty permalinks. They do not work with the "Plain" permalink setting or with permalinks that contain index.php. Choose another structure under Settings → Permalinks.', 'product-qrcode-barcode-generator' ),
				ScanUrl::site_url()
			);
		}

		foreach ( self::conflicts() as $label ) {
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
	 * @param array{status: int, location?: string, view?: array<string, mixed>} $response Response.
	 */
	private static function send( array $response ): void {
		foreach ( self::security_headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}

		header_remove( 'Last-Modified' );

		if ( 405 === $response['status'] ) {
			header( 'Allow: GET, HEAD' );
		}

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
	 * Value stored in FLAG_OPTION once the current rules are flushed.
	 */
	private static function flag_value(): string {
		return PQBG_VERSION . ':' . self::RULES_VERSION;
	}
}
