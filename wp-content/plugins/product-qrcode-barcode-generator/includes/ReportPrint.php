<?php
/**
 * Print-friendly end of day (Phase 9B): admin-post.php?action=pqbg_report_print
 * (GET/HEAD only), the Phase 8 approach: a standalone document with no wp-admin
 * chrome and no wp_head(), a stylesheet for screen and print, and a Print button
 * (assets/pqbg-print.js, the only script; Ctrl+P works without it).
 *
 * Read-only: pqbg_view_all_sales and a nonce. Headers: ScanRoute::security_headers()
 * with a CSP that allows only the two same-origin files (no inline styles or scripts).
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * End-of-day print page.
 */
final class ReportPrint {

	const ACTION = 'pqbg_report_print';

	/**
	 * URL of the print page for a context's period.
	 *
	 * @param array<string, string> $args ReportsAdmin::args() (only the period is used).
	 */
	public static function url( array $args ): string {
		$keep = array_intersect_key( $args, array_flip( array( 'range', 'from', 'to' ) ) );

		return add_query_arg(
			array_map( 'rawurlencode', array_merge( array( 'action' => self::ACTION ), $keep, array( '_wpnonce' => wp_create_nonce( self::ACTION ) ) ) ),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * The headers of every response of this page.
	 *
	 * @return array<string, string>
	 */
	public static function headers(): array {
		return array_merge(
			ScanRoute::security_headers(),
			array(
				'Content-Security-Policy' => "default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
			)
		);
	}

	/**
	 * GET/HEAD handler.
	 */
	public static function handle(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			self::send( 405, self::error( __( 'Method not allowed.', 'product-qrcode-barcode-generator' ) ), array( 'Allow' => 'GET, HEAD' ) );
		}

		if ( ! Permissions::can_view_all_sales() ) {
			self::send( 403, self::error( __( 'Sorry, you are not allowed to see in-store reports.', 'product-qrcode-barcode-generator' ) ) );
		}

		$nonce = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			self::send( 403, self::error( __( 'This link has expired. Go back to In-store reports and print again.', 'product-qrcode-barcode-generator' ) ) );
		}

		$ctx = ReportsAdmin::context( array_merge( wp_unslash( $_GET ), array( 'tab' => 'eod' ) ), Permissions::can_view_costs() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by context().
		$p   = $ctx['period'];

		self::send(
			200,
			array(
				'mode'   => 'eod',
				'title'  => __( 'End of day', 'product-qrcode-barcode-generator' ) . ' — ' . ReportPeriod::span_label( $p['from'], $p['to'] ),
				'label'  => ReportPeriod::span_label( $p['from'], $p['to'] ),
				'data'   => ReportsQuery::end_of_day( ReportPeriod::where_filters( $p ) ),
				'store'  => get_bloginfo( 'name' ),
				'back'   => ReportsAdmin::url( ReportsAdmin::args( $ctx ) ),
			)
		);
	}

	/**
	 * An error view.
	 *
	 * @param string $message Message.
	 * @return array<string, mixed>
	 */
	private static function error( string $message ): array {
		return array(
			'mode'    => 'error',
			'title'   => __( 'End of day', 'product-qrcode-barcode-generator' ),
			'message' => $message,
			'back'    => admin_url( 'admin.php?page=' . ReportsAdmin::SLUG . '&tab=eod' ),
		);
	}

	/**
	 * The page HTML for a view (every value escaped in the template).
	 *
	 * @param array<string, mixed> $view View.
	 */
	public static function render( array $view ): string {
		ob_start();
		include PQBG_PLUGIN_DIR . 'templates/pqbg-report-print.php';
		return (string) ob_get_clean();
	}

	/**
	 * Sends a view with its status and headers, and ends the request.
	 *
	 * @param int                   $status  HTTP status.
	 * @param array<string, mixed>  $view    View.
	 * @param array<string, string> $headers Extra headers.
	 * @return never
	 */
	private static function send( int $status, array $view, array $headers = array() ): void {
		nocache_headers();

		foreach ( array_merge( self::headers(), $headers ) as $name => $value ) {
			header( $name . ': ' . $value );
		}

		header_remove( 'Last-Modified' );
		status_header( $status );
		header( 'Content-Type: text/html; charset=utf-8' );

		if ( 'HEAD' !== strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key().
			echo self::render( $view ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes every value.
		}

		exit;
	}
}
