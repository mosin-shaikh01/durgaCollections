<?php
/**
 * The print-ready label page: admin-post.php?action=pqbg_print (GET/HEAD only).
 *
 * A standalone HTML document (no wp-admin chrome, no wp_head()) with CSS @page
 * set to the layout's exact millimetre size, an on-screen preview with page and
 * label outlines, and a Print button (assets/pqbg-print.js, the only script).
 *
 * Read-only: checks Permissions::MANAGE_CODES and a nonce bound to the product
 * selection, re-validates every option from the query string, and never writes
 * anything except the render cache (PrintCache). Codes are never generated here
 * and only ACTIVE codes are printed. Only admin_post_* is hooked, never nopriv.
 *
 * Local scan base URL: the page shows a warning and renders no labels until the
 * user follows "Print TEST labels anyway" (confirm_test=1, still a plain GET);
 * then every label carries "TEST – NOT FOR USE". A public http:// base URL gets
 * the Phase 4 https warning, and no mark.
 *
 * Headers: ScanRoute::security_headers() with a print-specific CSP: the geometry
 * is in one <style> element carrying a per-request nonce, the stylesheet and
 * the script are same-origin files, and no inline style attributes are used.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Print page endpoint and view.
 */
final class PrintPage {

	const ACTION = 'pqbg_print';

	const CONFIRM_ARG = 'confirm_test';

	/** The TEST line printed on every label while the scan base URL is local. */
	const TEST_MARK = 'TEST – NOT FOR USE';

	/**
	 * Hooks the endpoint. Admin requests only.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * URL of the print page.
	 *
	 * @param int[]                $ids     Selection.
	 * @param array<string, mixed> $options Validated options (PrintJob::options()).
	 * @param bool                 $confirm Whether TEST labels were confirmed.
	 */
	public static function url( array $ids, array $options, bool $confirm = false ): string {
		$args = array_merge(
			array(
				'action'                 => self::ACTION,
				'items'                  => implode( ',', $ids ),
				Permissions::NONCE_FIELD => wp_create_nonce( PrintJob::nonce_action( 'print_view', $ids ) ),
			),
			PrintJob::query_args( $options )
		);

		if ( $confirm ) {
			$args[ self::CONFIRM_ARG ] = '1';
		}

		return add_query_arg( urlencode_deep( $args ), admin_url( 'admin-post.php' ) );
	}

	/**
	 * The headers of every print page response, errors included.
	 *
	 * @param string $nonce CSP nonce of the page's <style> element.
	 * @return array<string, string>
	 */
	public static function headers( string $nonce ): array {
		return array_merge(
			ScanRoute::security_headers(),
			array(
				'Content-Security-Policy' => "default-src 'none'; style-src 'self' 'nonce-" . $nonce . "'; script-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
			)
		);
	}

	/**
	 * GET/HEAD handler.
	 */
	public static function handle(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			self::send( 405, self::error_view( __( 'Method not allowed.', 'product-qrcode-barcode-generator' ) ), array( 'Allow' => 'GET, HEAD' ) );
		}

		if ( ! Permissions::can_manage_codes() ) {
			self::send( 403, self::error_view( __( 'You are not allowed to print product labels.', 'product-qrcode-barcode-generator' ) ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the nonce is checked right below, before anything is used.
		$ids     = PrintJob::parse_ids( isset( $_GET['items'] ) ? sanitize_text_field( wp_unslash( $_GET['items'] ) ) : '' );
		$nonce   = isset( $_GET[ Permissions::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_GET[ Permissions::NONCE_FIELD ] ) ) : '';
		$raw     = isset( $_GET['opt'] ) && is_array( $_GET['opt'] ) ? map_deep( wp_unslash( $_GET['opt'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- map_deep().
		$confirm = isset( $_GET[ self::CONFIRM_ARG ] ) && '1' === $_GET[ self::CONFIRM_ARG ];
		// phpcs:enable

		if ( is_wp_error( $ids ) || ! wp_verify_nonce( $nonce, PrintJob::nonce_action( 'print_view', $ids ) ) ) {
			self::send( 403, self::error_view( __( 'The link you followed has expired. Go back to the products and try again.', 'product-qrcode-barcode-generator' ) ) );
		}

		$options = PrintJob::options( $raw );

		if ( is_wp_error( $options ) ) {
			self::send( 400, self::error_view( $options->get_error_message(), PrintAdmin::setup_url( $ids ) ) );
		}

		$view = self::build( $ids, $options, $confirm );

		if ( is_wp_error( $view ) ) {
			self::send( 400, self::error_view( $view->get_error_message(), PrintAdmin::setup_url( $ids ) ) );
		}

		self::send( 200, $view );
	}

	/**
	 * Builds the page for a selection and options, without output. Read-only apart from the render cache.
	 *
	 * @param int[]                $ids     Selection.
	 * @param array<string, mixed> $options Validated options.
	 * @param bool                 $confirm Whether TEST labels were confirmed.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function build( array $ids, array $options, bool $confirm ) {
		$resolved = PrintJob::resolve( $ids );
		$job      = PrintJob::labels( $resolved['items'], $options );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$base  = ScanUrl::base();
		$local = Settings::is_local_url( $base );
		$spec  = $options['spec'];
		$view  = array(
			'mode'        => 'labels',
			'title'       => __( 'Print QR labels', 'product-qrcode-barcode-generator' ),
			'layout_name' => PrintLayout::name( $spec ),
			'spec'        => $spec,
			'base'        => $base,
			'local'       => $local,
			'http'        => ! $local && Settings::is_http_url( $base ),
			'skipped'     => array_merge( $resolved['skipped'], $job['skipped'] ),
			'notes'       => $job['notes'],
			'total'       => $job['total'],
			'setup_url'   => PrintAdmin::setup_url( $ids ),
			'back_url'    => admin_url( 'edit.php?post_type=product' ),
			'nonce'       => base64_encode( random_bytes( 18 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- CSP nonce.
		);

		if ( $local && ! $confirm ) {
			$view['mode']        = 'confirm';
			$view['confirm_url'] = self::url( $ids, $options, true );

			return $view;
		}

		// Every code of the job is the same length, so they share one QR version; take the largest to be safe.
		$items   = $resolved['items'];
		$qr      = array();
		$modules = 0;

		foreach ( array_unique( array_map( static fn( $i ) => $items[ $i ]['code'], $job['labels'] ) ) as $code ) {
			$svg = PrintCache::qr( $code );

			if ( is_wp_error( $svg ) ) {
				return $svg;
			}

			$qr[ $code ] = $svg;
			$modules     = max( $modules, PrintLayout::qr_modules( $svg ) );
		}

		$fit = PrintLayout::fit( $spec, $modules, $options['fields'], Settings::is_barcode_enabled(), $local );

		if ( is_wp_error( $fit ) ) {
			PrintCache::flush();
			return $fit;
		}

		$barcodes = array();

		if ( null !== $fit['barcode'] ) {
			foreach ( array_keys( $qr ) as $code ) {
				$svg = PrintCache::barcode( $code, self::barcode_args() );

				if ( is_wp_error( $svg ) ) {
					PrintCache::flush();
					return $svg;
				}

				$barcodes[ $code ] = $svg;
			}
		}

		PrintCache::flush();

		$pages  = PrintLayout::paginate( $spec, count( $job['labels'] ), $options['start'] );
		$labels = array();

		foreach ( $job['labels'] as $n => $i ) {
			$item     = $items[ $i ];
			$labels[] = array_merge(
				$item,
				$pages[ $n ],
				array(
					'qr_svg'      => $qr[ $item['code'] ],
					'barcode_svg' => $barcodes[ $item['code'] ] ?? '',
				)
			);
		}

		$store = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );

		return array_merge(
			$view,
			array(
				'fit'     => $fit,
				'store'   => $store,
				'modules' => $modules,
				'labels'  => $labels,
				'sheets'  => (int) end( $pages )['sheet'] + 1,
				'css'     => self::css( $spec, $fit, $options['dx'], $options['dy'] ),
			)
		);
	}

	/**
	 * Arguments for printed barcodes: bars only, BARCODE_BAR_MM high at BARCODE_X_MM per module.
	 *
	 * @return array<string, mixed>
	 */
	public static function barcode_args(): array {
		return array(
			'bar_height' => (int) round( PrintLayout::BARCODE_BAR_MM / PrintLayout::BARCODE_X_MM ),
			'text'       => false,
		);
	}

	/**
	 * The geometry stylesheet (inside the nonce'd <style> element).
	 *
	 * @param array<string, mixed> $spec Layout.
	 * @param array<string, mixed> $fit  PrintLayout::fit() result.
	 * @param float                $dx   Printer offset right.
	 * @param float                $dy   Printer offset down.
	 */
	public static function css( array $spec, array $fit, float $dx, float $dy ): string {
		$mm  = static fn( $v ) => PrintLayout::mm( $v, 4 ) . 'mm';
		$box = static fn( string $selector, array $b ) => $selector . '{left:' . $mm( $b['x'] ) . ';top:' . $mm( $b['y'] ) . ';width:' . $mm( $b['w'] ) . ';height:' . $mm( $b['h'] ) . '}';
		$css = array(
			'@page{size:' . $mm( $spec['page_w'] ) . ' ' . $mm( $spec['page_h'] ) . ';margin:0}',
			'.pqbg-sheet{width:' . $mm( $spec['page_w'] ) . ';height:' . $mm( $spec['page_h'] ) . '}',
			'.pqbg-label{width:' . $mm( $spec['label_w'] ) . ';height:' . $mm( $spec['label_h'] ) . '}',
			$box(
				'.pqbg-qr',
				array(
					'x' => $fit['qr_x'],
					'y' => $fit['qr_y'],
					'w' => $fit['qr'],
					'h' => $fit['qr'],
				)
			),
			$box( '.pqbg-text', $fit['text'] ) . '.pqbg-text{font-size:' . PrintLayout::mm( $fit['font_pt'] ) . 'pt;line-height:' . $mm( $fit['line'] ) . '}',
			'.pqbg-l{height:' . $mm( $fit['line'] ) . '}',
			'.pqbg-l-code{height:' . $mm( $fit['line'] * $fit['code_lines'] ) . '}',
		);

		if ( isset( $fit['lines']['name'] ) ) {
			$css[] = '.pqbg-l-name{height:' . $mm( $fit['line'] * $fit['lines']['name'] ) . ';-webkit-line-clamp:' . (int) $fit['lines']['name'] . ';line-clamp:' . (int) $fit['lines']['name'] . '}';
		}

		if ( null !== $fit['barcode'] ) {
			$css[] = $box( '.pqbg-bc', $fit['barcode'] );
			$css[] = '.pqbg-bc svg{height:' . $mm( $fit['barcode']['h'] ) . '}';
		}

		for ( $slot = 0, $per = PrintLayout::per_sheet( $spec ); $slot < $per; $slot++ ) {
			[ $x, $y ] = PrintLayout::slot_position( $spec, $slot, $dx, $dy );
			$css[]     = '.pqbg-s' . $slot . '{left:' . $mm( $x ) . ';top:' . $mm( $y ) . '}';
		}

		return implode( "\n", $css );
	}

	/**
	 * A standalone error page.
	 *
	 * @param string $message Plain-text message.
	 * @param string $back    Optional link back.
	 * @return array<string, mixed>
	 */
	public static function error_view( string $message, string $back = '' ): array {
		return array(
			'mode'     => 'error',
			'title'    => __( 'Print QR labels', 'product-qrcode-barcode-generator' ),
			'message'  => $message,
			'back_url' => '' !== $back ? $back : admin_url( 'edit.php?post_type=product' ),
			'nonce'    => base64_encode( random_bytes( 18 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- CSP nonce.
		);
	}

	/**
	 * Renders a view to a string (tests use this without sending headers).
	 *
	 * @param array<string, mixed> $view View.
	 */
	public static function render( array $view ): string {
		ob_start();
		include PQBG_PLUGIN_DIR . 'templates/pqbg-print.php';
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

		foreach ( array_merge( self::headers( $view['nonce'] ), $headers ) as $name => $value ) {
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
