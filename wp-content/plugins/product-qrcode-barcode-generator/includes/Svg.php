<?php
/**
 * Minimal SVG writer shared by the QR and barcode renderers.
 *
 * The libraries only encode (bits and bars); this class writes the markup,
 * so the output is fully under our control:
 *   - only <svg>, <rect>, <path> and <text> elements
 *   - attribute values are integers or fixed constants
 *   - the only free text is a validated product code, escaped for XML
 *   - no XML prolog, DOCTYPE, ids, scripts, event handlers, styles or links,
 *     so the SVG can be inlined in HTML safely and more than once per page
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * Safe SVG document assembly.
 */
final class Svg {

	/**
	 * A complete SVG document on a white background.
	 *
	 * Coordinates are in modules (one QR module, or one Code 128 bar unit).
	 * width/height give the default display size; the viewBox lets CSS or
	 * print styles scale it without losing sharpness.
	 *
	 * @param int    $width        Width in modules.
	 * @param int    $height       Height in modules.
	 * @param int    $px_per_unit  Default pixels per module.
	 * @param string $label        Accessible name (a validated product code).
	 * @param string $content      Inner markup built with this class.
	 */
	public static function document( int $width, int $height, int $px_per_unit, string $label, string $content ): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '"'
			. ' width="' . ( $width * $px_per_unit ) . '" height="' . ( $height * $px_per_unit ) . '"'
			. ' role="img" aria-label="' . self::escape( $label ) . '" shape-rendering="crispEdges">'
			. '<rect width="' . $width . '" height="' . $height . '" fill="#fff"/>'
			. $content
			. '</svg>';
	}

	/**
	 * One black path made of filled horizontal runs.
	 *
	 * @param array<int, array{0: int, 1: int, 2: int}> $runs List of [x, y, length] in modules.
	 * @param int                                       $height Height of every run in modules.
	 */
	public static function runs( array $runs, int $height ): string {
		if ( array() === $runs ) {
			return '';
		}

		$d = '';

		foreach ( $runs as $run ) {
			$d .= 'M' . (int) $run[0] . ' ' . (int) $run[1] . 'h' . (int) $run[2] . 'v' . $height . 'h-' . (int) $run[2] . 'z';
		}

		return '<path fill="#000" d="' . $d . '"/>';
	}

	/**
	 * Centred monospace text.
	 *
	 * @param int    $x    Centre x in modules.
	 * @param int    $y    Baseline y in modules.
	 * @param int    $size Font size in modules.
	 * @param string $text Text (a validated product code).
	 */
	public static function text( int $x, int $y, int $size, string $text ): string {
		return '<text x="' . $x . '" y="' . $y . '" font-family="monospace" font-size="' . $size . '" text-anchor="middle" fill="#000">'
			. self::escape( $text ) . '</text>';
	}

	/**
	 * Escapes text for XML content and double-quoted attributes.
	 *
	 * @param string $text Raw text.
	 */
	private static function escape( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
