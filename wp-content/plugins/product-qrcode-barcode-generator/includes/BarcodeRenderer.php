<?php
/**
 * Renders a product code as a Code 128 barcode (SVG), for hardware scanners.
 *
 * Barcodes are OPTIONAL and OFF by default (Settings::is_barcode_enabled()).
 * The setting is checked before anything else. While barcodes are disabled
 * this returns pqbg_barcode_disabled and no barcode library class is ever
 * loaded: the library is only reached through the autoloader, after the checks.
 *
 * Content: the product code only (e.g. DC-7K4M-9P2X-Q8RT), the same code the
 * QR code's URL carries, so enabling barcodes later needs no regeneration.
 * Quiet zone of 10 modules on each side; the code is printed beneath the bars.
 *
 * picqer/php-barcode-generator (scoped under ProductQrBarcode\Vendor\) only
 * encodes the bars; Svg writes the markup. The renderer never touches the
 * database, never generates codes and never writes files.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use ProductQrBarcode\Vendor\Picqer\Barcode\Types\TypeCode128;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Product code to Code 128 SVG.
 */
final class BarcodeRenderer {

	/** Quiet zone left and right of the bars, in modules (Code 128 minimum is 10). */
	const QUIET_ZONE = 10;

	/** Space above the bars, in modules. */
	const TOP_MARGIN = 4;

	/** Bar height in modules. */
	const BAR_HEIGHT = 50;

	/** Font size of the human-readable code, in modules. */
	const TEXT_SIZE = 12;

	/** Default display size of one module, in pixels. */
	const MODULE_PX = 2;

	/**
	 * Renders the barcode for a product code.
	 *
	 * Optional arguments (for printed labels, which print the code text themselves):
	 *   - bar_height (int, 1–500): bar height in modules; default BAR_HEIGHT
	 *   - text (bool): the code beneath the bars; default true. Without it there is
	 *     no top margin either, so the SVG is exactly the bars and quiet zones.
	 * With no arguments the output is unchanged.
	 *
	 * @param string              $code Product code; must match CodeGenerator::FORMAT_PATTERN exactly.
	 * @param array<string, mixed> $args Optional arguments, see above.
	 * @return string|WP_Error SVG markup.
	 */
	public function render( string $code, array $args = array() ) {
		if ( ! Settings::is_barcode_enabled() ) {
			return new WP_Error( 'pqbg_barcode_disabled', __( 'Barcodes are disabled.', 'product-qrcode-barcode-generator' ) );
		}

		if ( ! CodeGenerator::is_valid_format( $code ) ) {
			return ScanUrl::invalid_code_error();
		}

		try {
			$barcode = ( new TypeCode128() )->getBarcode( $code );
		} catch ( \Throwable $e ) {
			return self::failed( $e );
		}

		$bar_height = isset( $args['bar_height'] ) && is_int( $args['bar_height'] ) && $args['bar_height'] >= 1 && $args['bar_height'] <= 500 ? $args['bar_height'] : self::BAR_HEIGHT;
		$with_text  = ! array_key_exists( 'text', $args ) || true === $args['text'];
		$top        = $with_text ? self::TOP_MARGIN : 0;

		$runs = array();
		$x    = self::QUIET_ZONE;

		foreach ( $barcode->getBars() as $bar ) {
			$width = (int) $bar->getWidth();

			if ( $bar->isBar() && $width > 0 ) {
				$runs[] = array( $x, $top, $width );
			}

			$x += $width;
		}

		$width  = (int) $barcode->getWidth() + 2 * self::QUIET_ZONE;
		$height = $with_text ? self::TOP_MARGIN + $bar_height + self::TEXT_SIZE + 4 : $bar_height;
		$text   = $with_text ? Svg::text( intdiv( $width, 2 ), self::TOP_MARGIN + $bar_height + self::TEXT_SIZE, self::TEXT_SIZE, $code ) : '';

		return Svg::document( $width, $height, self::MODULE_PX, $code, Svg::runs( $runs, $bar_height ) . $text );
	}

	/**
	 * Logs a library failure (exception class only) and returns a generic error.
	 *
	 * @param \Throwable $e Library exception.
	 */
	private static function failed( \Throwable $e ): WP_Error {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( 'Barcode rendering failed: ' . get_class( $e ), array( 'source' => 'product-qrcode-barcode-generator' ) );
		}

		return new WP_Error( 'pqbg_render_failed', __( 'The barcode could not be rendered.', 'product-qrcode-barcode-generator' ) );
	}
}
