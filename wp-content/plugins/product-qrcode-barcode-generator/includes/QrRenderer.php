<?php
/**
 * Renders a product code as a QR code (SVG). QR codes are always available.
 *
 * Payload: the scan URL from ScanUrl::for_code(), and nothing else.
 * Error correction level M, a quiet zone of 4 modules, byte mode in
 * ISO-8859-1 (the URL is plain ASCII, so no ECI header is added, which
 * some older scanners misread).
 *
 * bacon/bacon-qr-code (scoped under ProductQrBarcode\Vendor\) only encodes
 * the bit matrix; Svg writes the markup. The renderer never touches the
 * database, never generates codes and never writes files.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use ProductQrBarcode\Vendor\BaconQrCode\Common\ErrorCorrectionLevel;
use ProductQrBarcode\Vendor\BaconQrCode\Encoder\Encoder;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Product code to QR code SVG.
 */
final class QrRenderer {

	/** Quiet zone around the symbol, in modules (the QR specification minimum). */
	const QUIET_ZONE = 4;

	/** Default display size of one module, in pixels. */
	const MODULE_PX = 4;

	/**
	 * Renders the QR code for a product code.
	 *
	 * @param string $code Product code; must match CodeGenerator::FORMAT_PATTERN exactly.
	 * @return string|WP_Error SVG markup.
	 */
	public function render( string $code ) {
		$url = ScanUrl::for_code( $code );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		if ( ! function_exists( 'iconv' ) ) {
			return new WP_Error( 'pqbg_qr_unavailable', __( 'QR codes need the PHP iconv extension, which is not available on this server.', 'product-qrcode-barcode-generator' ) );
		}

		try {
			$matrix = Encoder::encode( $url, ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ENCODING )->getMatrix();
		} catch ( \Throwable $e ) {
			return self::failed( $e );
		}

		$size = $matrix->getWidth();
		$runs = array();

		// Whole rows at once: much cheaper than one ByteMatrix::get() call per module.
		foreach ( $matrix->getArray() as $y => $row ) {
			$row   = $row->toArray();
			$start = null;

			for ( $x = 0; $x <= $size; $x++ ) {
				$dark = $x < $size && 1 === $row[ $x ];

				if ( $dark && null === $start ) {
					$start = $x;
				} elseif ( ! $dark && null !== $start ) {
					$runs[] = array( $start + self::QUIET_ZONE, $y + self::QUIET_ZONE, $x - $start );
					$start  = null;
				}
			}
		}

		$total = $size + 2 * self::QUIET_ZONE;

		return Svg::document( $total, $total, self::MODULE_PX, $code, Svg::runs( $runs, 1 ) );
	}

	/**
	 * Logs a library failure (exception class only) and returns a generic error.
	 *
	 * @param \Throwable $e Library exception.
	 */
	private static function failed( \Throwable $e ): WP_Error {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( 'QR rendering failed: ' . get_class( $e ), array( 'source' => 'product-qrcode-barcode-generator' ) );
		}

		return new WP_Error( 'pqbg_render_failed', __( 'The QR code could not be rendered.', 'product-qrcode-barcode-generator' ) );
	}
}
