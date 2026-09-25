<?php
/**
 * Label layouts for printing: the presets, custom-layout validation, sheet
 * geometry and the fit of one label's content (QR size, text lines, barcode).
 *
 * Pure calculation: no database, no output. Every length is in millimetres.
 *
 * The QR code is never printed smaller than MIN_MODULE_MM per module, with its
 * 4-module quiet zone inside the label's safe area. If a layout cannot fit that
 * with the chosen fields, optional text is dropped first (lowest priority
 * first); if it still does not fit, the layout is refused. The module count
 * comes from the real encoding of the job's scan URLs (see PrintPage), not from
 * a table.
 *
 * Why 0.40 mm: the Phase 8 round-trip tests rasterise labels at exactly this
 * module size at 203 dpi and 300 dpi and decode every one. For reference, GS1
 * General Specifications Release 26.0, Table 5-46, gives 0.396 mm as the minimum
 * X-dimension (0.990 mm maximum) for QR codes carrying GS1 Digital Link URIs on
 * retail consumer items; our scan URLs are not GS1 Digital Link URIs, so this is
 * a reference point, not a conformance claim.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Print layouts and label fitting.
 */
final class PrintLayout {

	/** Smallest QR module printed, in mm. */
	const MIN_MODULE_MM = 0.40;

	/** Largest QR module printed, in mm. */
	const MAX_MODULE_MM = 0.99;

	/** Gap between the QR code (outside its quiet zone) and the text. */
	const QR_GAP_MM = 0.5;

	/** Minimum text column next to the QR code when optional fields are printed. */
	const TEXT_MIN_MM = 15.0;

	/** Minimum text column for the code text alone ("XXXX-XXXX" on its second line). */
	const CODE_ONLY_MIN_MM = 10.0;

	/** Code 128 bar module (X-dimension): 2 dots at 203 dpi. */
	const BARCODE_X_MM = 0.25;

	/** Widest possible barcode: 222 modules for a 17-character code in code set B, plus 2 × 10 quiet modules. */
	const BARCODE_MAX_MODULES = 242;

	/** Bar height of a printed barcode. */
	const BARCODE_BAR_MM = 7.0;

	/** Gap above the barcode strip. */
	const BARCODE_GAP_MM = 0.5;

	/** Conservative advance width of one monospace character, in em. */
	const MONO_EM = 0.62;

	/** Line height, as a multiple of the font size. */
	const LINE_HEIGHT = 1.2;

	/** Millimetres per typographic point. */
	const PT_MM = 0.352778;

	/** Characters of the code text: DC-XXXX-XXXX-XXXX, and its longer half when wrapped. */
	const CODE_CHARS      = 17;
	const CODE_HALF_CHARS = 9;

	/** Safe inset of a custom layout. */
	const CUSTOM_INSET_MM = 1.5;

	const MAX_PER_SHEET = 500;

	/** Printer offset limit (sheets only), in mm either way. */
	const MAX_OFFSET_MM = 5.0;

	const DEFAULT_PRESET = 'a4-3x7';

	/** Optional label fields, highest priority first. They are dropped from the end. */
	const OPTIONAL_FIELDS = array( 'name', 'attributes', 'sku', 'price', 'store' );

	/**
	 * The preset layouts, keyed by id. A4 is 210 × 297 mm; thermal presets print one label per page.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function presets(): array {
		$a4 = static fn( string $id, int $cols, int $rows, float $w, float $h, float $left, float $top, float $gx, float $gy, float $ix, float $iy, string $warning = '' ): array => array(
			'id'      => $id,
			'type'    => 'sheet',
			'page_w'  => 210.0,
			'page_h'  => 297.0,
			'label_w' => $w,
			'label_h' => $h,
			'cols'    => $cols,
			'rows'    => $rows,
			'left'    => $left,
			'top'     => $top,
			'gap_x'   => $gx,
			'gap_y'   => $gy,
			'inset_x' => $ix,
			'inset_y' => $iy,
			'dpi'     => 0,
			'warning' => $warning,
		);

		$thermal = static fn( string $id, float $w, float $h, float $inset ): array => array(
			'id'      => $id,
			'type'    => 'thermal',
			'page_w'  => $w,
			'page_h'  => $h,
			'label_w' => $w,
			'label_h' => $h,
			'cols'    => 1,
			'rows'    => 1,
			'left'    => 0.0,
			'top'     => 0.0,
			'gap_x'   => 0.0,
			'gap_y'   => 0.0,
			'inset_x' => $inset,
			'inset_y' => $inset,
			'dpi'     => 203,
			'warning' => '',
		);

		return array(
			'a4-3x7'    => $a4( 'a4-3x7', 3, 7, 63.5, 38.1, 7.25, 15.15, 2.5, 0.0, 1.5, 1.5 ),
			'a4-3x8'    => $a4( 'a4-3x8', 3, 8, 70.0, 37.0, 0.0, 0.5, 0.0, 0.0, 4.0, 2.5, __( 'Edge-to-edge sheet: your printer must print within about 3 mm of the paper edge. Test on plain paper first.', 'product-qrcode-barcode-generator' ) ),
			'a4-4x10'   => $a4( 'a4-4x10', 4, 10, 48.5, 25.4, 8.0, 21.5, 0.0, 0.0, 1.5, 1.5 ),
			'a4-5x13'   => $a4( 'a4-5x13', 5, 13, 38.1, 21.2, 4.75, 10.7, 2.5, 0.0, 1.0, 1.0 ),
			'th-50x25'  => $thermal( 'th-50x25', 50.0, 25.0, 1.5 ),
			'th-38x25'  => $thermal( 'th-38x25', 38.0, 25.0, 1.5 ),
			'th-100x50' => $thermal( 'th-100x50', 100.0, 50.0, 2.0 ),
		);
	}

	/**
	 * Human-readable name of a layout, e.g. "A4 · 3 × 7 · 63.5 × 38.1 mm (21 per sheet)".
	 *
	 * @param array<string, mixed> $spec Layout.
	 */
	public static function name( array $spec ): string {
		$custom = 'custom' === $spec['id'] ? __( 'Custom', 'product-qrcode-barcode-generator' ) . ' · ' : '';

		if ( 'thermal' === $spec['type'] ) {
			/* translators: 1: label width, 2: label height (mm). */
			return $custom . sprintf( __( 'Thermal · %1$s × %2$s mm (one label per page)', 'product-qrcode-barcode-generator' ), self::mm( $spec['label_w'] ), self::mm( $spec['label_h'] ) );
		}

		$paper = 210.0 === (float) $spec['page_w'] && 297.0 === (float) $spec['page_h'] ? 'A4' : self::mm( $spec['page_w'] ) . ' × ' . self::mm( $spec['page_h'] ) . ' mm';

		/* translators: 1: paper, 2: columns, 3: rows, 4: label width, 5: label height, 6: labels per sheet. */
		return $custom . sprintf( __( '%1$s · %2$d × %3$d · %4$s × %5$s mm (%6$d per sheet)', 'product-qrcode-barcode-generator' ), $paper, $spec['cols'], $spec['rows'], self::mm( $spec['label_w'] ), self::mm( $spec['label_h'] ), self::per_sheet( $spec ) );
	}

	/**
	 * The editable fields of a custom layout, with their limits: [label, min, max, integer?].
	 *
	 * @return array<string, array{0: string, 1: float, 2: float, 3: bool}>
	 */
	public static function custom_fields(): array {
		return array(
			'page_w'  => array( __( 'Page width', 'product-qrcode-barcode-generator' ), 20, 1000, false ),
			'page_h'  => array( __( 'Page height', 'product-qrcode-barcode-generator' ), 20, 1000, false ),
			'label_w' => array( __( 'Label width', 'product-qrcode-barcode-generator' ), 10, 300, false ),
			'label_h' => array( __( 'Label height', 'product-qrcode-barcode-generator' ), 10, 300, false ),
			'cols'    => array( __( 'Columns', 'product-qrcode-barcode-generator' ), 1, 20, true ),
			'rows'    => array( __( 'Rows', 'product-qrcode-barcode-generator' ), 1, 50, true ),
			'left'    => array( __( 'Left margin', 'product-qrcode-barcode-generator' ), 0, 100, false ),
			'top'     => array( __( 'Top margin', 'product-qrcode-barcode-generator' ), 0, 100, false ),
			'gap_x'   => array( __( 'Gap between columns', 'product-qrcode-barcode-generator' ), 0, 50, false ),
			'gap_y'   => array( __( 'Gap between rows', 'product-qrcode-barcode-generator' ), 0, 50, false ),
		);
	}

	/**
	 * Validates a custom layout.
	 *
	 * Lengths are decimal numbers with at most 2 decimals; counts are whole numbers.
	 * A thermal layout prints one label per page, so its page is the label.
	 *
	 * @param mixed $input Raw input: type, dpi and the custom_fields() keys, as strings.
	 * @return array<string, mixed>|WP_Error Layout, or pqbg_invalid_layout with data['field'].
	 */
	public static function custom( $input ) {
		$input = is_array( $input ) ? $input : array();
		$type  = isset( $input['type'] ) && is_string( $input['type'] ) ? $input['type'] : '';

		if ( ! in_array( $type, array( 'sheet', 'thermal' ), true ) ) {
			return self::invalid( 'type', __( 'Choose a label sheet or a thermal printer.', 'product-qrcode-barcode-generator' ) );
		}

		$dpi = 0;

		if ( 'thermal' === $type ) {
			$dpi = isset( $input['dpi'] ) && is_string( $input['dpi'] ) && in_array( $input['dpi'], array( '203', '300' ), true ) ? (int) $input['dpi'] : 0;

			if ( 0 === $dpi ) {
				return self::invalid( 'dpi', __( 'Choose the thermal printer resolution: 203 or 300 dpi.', 'product-qrcode-barcode-generator' ) );
			}
		}

		$keys   = 'thermal' === $type ? array( 'label_w', 'label_h' ) : array_keys( self::custom_fields() );
		$values = array();

		foreach ( $keys as $key ) {
			[ $label, $min, $max, $integer ] = self::custom_fields()[ $key ];

			$raw     = isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? trim( $input[ $key ] ) : '';
			$pattern = $integer ? '/^[0-9]{1,3}$/D' : '/^[0-9]{1,4}(\.[0-9]{1,2})?$/D';

			if ( ! preg_match( $pattern, $raw ) || (float) $raw < $min || (float) $raw > $max ) {
				return self::invalid(
					$key,
					$integer
						/* translators: 1: field name, 2: minimum, 3: maximum. */
						? sprintf( __( '%1$s must be a whole number from %2$s to %3$s.', 'product-qrcode-barcode-generator' ), $label, $min, $max )
						/* translators: 1: field name, 2: minimum, 3: maximum. */
						: sprintf( __( '%1$s must be a number from %2$s to %3$s mm, with at most 2 decimals.', 'product-qrcode-barcode-generator' ), $label, $min, $max )
				);
			}

			$values[ $key ] = $integer ? (int) $raw : (float) $raw;
		}

		if ( 'thermal' === $type ) {
			$values = array_merge(
				$values,
				array(
					'page_w' => $values['label_w'],
					'page_h' => $values['label_h'],
					'cols'   => 1,
					'rows'   => 1,
					'left'   => 0.0,
					'top'    => 0.0,
					'gap_x'  => 0.0,
					'gap_y'  => 0.0,
				)
			);
		} else {
			if ( $values['cols'] * $values['rows'] > self::MAX_PER_SHEET ) {
				/* translators: %d: maximum labels per sheet. */
				return self::invalid( 'rows', sprintf( __( 'A sheet can have at most %d labels.', 'product-qrcode-barcode-generator' ), self::MAX_PER_SHEET ) );
			}

			$width  = $values['left'] + $values['cols'] * $values['label_w'] + ( $values['cols'] - 1 ) * $values['gap_x'];
			$height = $values['top'] + $values['rows'] * $values['label_h'] + ( $values['rows'] - 1 ) * $values['gap_y'];

			if ( $width > $values['page_w'] + 0.005 ) {
				/* translators: 1: needed width, 2: page width (mm). */
				return self::invalid( 'cols', sprintf( __( 'The labels do not fit across the page: they need %1$s mm, the page is %2$s mm wide.', 'product-qrcode-barcode-generator' ), self::mm( $width ), self::mm( $values['page_w'] ) ) );
			}

			if ( $height > $values['page_h'] + 0.005 ) {
				/* translators: 1: needed height, 2: page height (mm). */
				return self::invalid( 'rows', sprintf( __( 'The labels do not fit down the page: they need %1$s mm, the page is %2$s mm high.', 'product-qrcode-barcode-generator' ), self::mm( $height ), self::mm( $values['page_h'] ) ) );
			}
		}

		return array_merge(
			array(
				'id'      => 'custom',
				'type'    => $type,
				'inset_x' => self::CUSTOM_INSET_MM,
				'inset_y' => self::CUSTOM_INSET_MM,
				'dpi'     => $dpi,
				'warning' => '',
			),
			$values
		);
	}

	/**
	 * Labels per sheet (1 for thermal layouts).
	 *
	 * @param array<string, mixed> $spec Layout.
	 */
	public static function per_sheet( array $spec ): int {
		return (int) $spec['cols'] * (int) $spec['rows'];
	}

	/**
	 * Places labels on sheets, row by row, starting at a 1-based position (sheets only).
	 *
	 * @param array<string, mixed> $spec  Layout.
	 * @param int                  $count Number of labels.
	 * @param int                  $start First position on the first sheet (1 for thermal).
	 * @return array<int, array{sheet: int, slot: int}> One entry per label.
	 */
	public static function paginate( array $spec, int $count, int $start ): array {
		$per    = self::per_sheet( $spec );
		$offset = 'sheet' === $spec['type'] ? max( 0, min( $per, $start ) - 1 ) : 0;
		$result = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$k        = $offset + $i;
			$result[] = array(
				'sheet' => intdiv( $k, $per ),
				'slot'  => $k % $per,
			);
		}

		return $result;
	}

	/**
	 * Top-left corner of a slot on its sheet, including the printer offset (sheets only).
	 *
	 * @param array<string, mixed> $spec Layout.
	 * @param int                  $slot 0-based slot, row by row.
	 * @param float                $dx   Printer offset to the right.
	 * @param float                $dy   Printer offset downwards.
	 * @return array{0: float, 1: float}
	 */
	public static function slot_position( array $spec, int $slot, float $dx = 0.0, float $dy = 0.0 ): array {
		$cols = (int) $spec['cols'];
		$row  = intdiv( $slot, $cols );
		$col  = $slot % $cols;

		if ( 'sheet' !== $spec['type'] ) {
			$dx = 0.0;
			$dy = 0.0;
		}

		return array(
			round( $spec['left'] + $col * ( $spec['label_w'] + $spec['gap_x'] ) + $dx, 4 ),
			round( $spec['top'] + $row * ( $spec['label_h'] + $spec['gap_y'] ) + $dy, 4 ),
		);
	}

	/**
	 * QR modules per side, quiet zone included, from a QrRenderer SVG ("viewBox="0 0 N N"").
	 *
	 * @param string $svg QR SVG.
	 */
	public static function qr_modules( string $svg ): int {
		return preg_match( '/viewBox="0 0 ([0-9]+) \1"/', $svg, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Fits one label's content.
	 *
	 * The QR code sits left of the text (right of it for labels at least 1.3 times as
	 * wide as high), or above it for narrow labels. The barcode, when requested, is a
	 * full-width strip at the bottom; it is left off (with a reason) when the label is
	 * too narrow for it or when the QR code would no longer fit.
	 *
	 * @param array<string, mixed> $spec      Layout.
	 * @param int                  $modules   QR modules per side, quiet zone included.
	 * @param string[]             $fields    Requested optional fields (see OPTIONAL_FIELDS).
	 * @param bool                 $barcode   Whether barcodes are wanted (enabled in Settings).
	 * @param bool                 $test_mark Whether every label carries "TEST – NOT FOR USE".
	 * @return array<string, mixed>|WP_Error pqbg_layout_too_small when not even the code text fits.
	 */
	public static function fit( array $spec, int $modules, array $fields, bool $barcode, bool $test_mark ) {
		$fields = array_values( array_intersect( self::OPTIONAL_FIELDS, $fields ) );
		$w      = $spec['label_w'] - 2 * $spec['inset_x'];
		$h      = $spec['label_h'] - 2 * $spec['inset_y'];
		$pt     = $h < 24 ? 5.5 : ( $h < 36 ? 6.5 : ( $h < 45 ? 8.0 : 9.0 ) );
		$omit   = '';
		$tries  = array();

		if ( $barcode ) {
			if ( $w + 1e-6 < self::BARCODE_MAX_MODULES * self::BARCODE_X_MM ) {
				$omit = 'width';
			} else {
				$tries[] = true;
			}
		}

		$tries[] = false;

		foreach ( $tries as $with_barcode ) {
			for ( $keep = count( $fields ); $keep >= 0; $keep-- ) {
				$area   = $with_barcode ? $h - self::BARCODE_BAR_MM - self::BARCODE_GAP_MM : $h;
				$result = self::attempt( $spec, $w, $area, $pt, $modules, array_slice( $fields, 0, $keep ), $test_mark );

				if ( null === $result ) {
					continue;
				}

				$result['dropped'] = array_values( array_diff( $fields, $result['fields'] ) );
				$result['barcode'] = $with_barcode ? array(
					'x'      => round( $spec['inset_x'], 4 ),
					'y'      => round( $spec['inset_y'] + $area + self::BARCODE_GAP_MM, 4 ),
					'w'      => round( $w, 4 ),
					'h'      => self::BARCODE_BAR_MM,
					'module' => self::BARCODE_X_MM,
				) : null;

				$result['barcode_omitted'] = $barcode && ! $with_barcode ? ( '' !== $omit ? $omit : 'room' ) : '';

				return $result;
			}
		}

		$room = max( 0.0, min( $h, $w - self::CODE_ONLY_MIN_MM - self::QR_GAP_MM ), min( $w, $h - ( 2 + ( $test_mark ? 1 : 0 ) ) * $pt * self::PT_MM * self::LINE_HEIGHT - self::QR_GAP_MM ) );

		return new WP_Error(
			'pqbg_layout_too_small',
			sprintf(
				/* translators: 1: scan URL length, 2: minimum QR size (mm), 3: modules per side, 4: minimum module size (mm), 5: available QR size (mm). */
				__( 'Your scan URL (%1$d characters) needs a QR code of at least %2$s mm (%3$d × %3$d modules including the quiet zone, at %4$s mm per module) next to the code text. This label has room for %5$s mm. Use a larger label or a shorter scan base URL.', 'product-qrcode-barcode-generator' ),
				strlen( ScanUrl::base() ),
				self::mm( $modules * self::MIN_MODULE_MM ),
				$modules,
				self::mm( self::MIN_MODULE_MM, 2 ),
				self::mm( $room )
			)
		);
	}

	/**
	 * Formats millimetres: at most $decimals decimals, no trailing zeros.
	 *
	 * @param float|int $mm       Length.
	 * @param int       $decimals Decimals.
	 */
	public static function mm( $mm, int $decimals = 2 ): string {
		$s = number_format( (float) $mm, $decimals, '.', '' );

		return str_contains( $s, '.' ) ? rtrim( rtrim( $s, '0' ), '.' ) : $s;
	}

	/**
	 * One fitting attempt with a fixed set of optional fields; null when the QR code or the code text does not fit.
	 *
	 * @param array<string, mixed> $spec      Layout.
	 * @param float                $w         Inner width.
	 * @param float                $area      Inner height available to the QR code and text.
	 * @param float                $pt        Font size.
	 * @param int                  $modules   QR modules per side.
	 * @param string[]             $fields    Optional fields to place, by priority.
	 * @param bool                 $test_mark Whether the TEST line is required.
	 * @return array<string, mixed>|null
	 */
	private static function attempt( array $spec, float $w, float $area, float $pt, int $modules, array $fields, bool $test_mark ): ?array {
		if ( $area <= 0 || $w <= 0 ) {
			return null;
		}

		$line     = $pt * self::PT_MM * self::LINE_HEIGHT;
		$required = $test_mark ? 1 : 0;
		$row      = $w >= 1.3 * $area;

		if ( $row ) {
			$side   = min( $area, $w - ( array() === $fields ? self::CODE_ONLY_MIN_MM : self::TEXT_MIN_MM ) - self::QR_GAP_MM );
			$module = self::module( $spec, $side / $modules );

			if ( null === $module ) {
				return null;
			}

			$qr   = $module * $modules;
			$text = array(
				'x' => $spec['inset_x'] + $qr + self::QR_GAP_MM,
				'y' => $spec['inset_y'],
				'w' => $w - $qr - self::QR_GAP_MM,
				'h' => $area,
			);
			$qr_x = $spec['inset_x'];
			$qr_y = $spec['inset_y'] + ( $area - $qr ) / 2;
		} else {
			// Stacked: reserve the text lines first, the QR code takes the rest.
			$code_lines = self::code_lines( $w, $pt );
			$wanted     = $required + $code_lines + ( in_array( 'name', $fields, true ) ? 2 : 0 ) + count( array_diff( $fields, array( 'name' ) ) );
			$side       = min( $w, $area - $wanted * $line - self::QR_GAP_MM );
			$module     = 0 === $code_lines ? null : self::module( $spec, $side / $modules );

			if ( null === $module ) {
				return null;
			}

			$qr   = $module * $modules;
			$text = array(
				'x' => $spec['inset_x'],
				'y' => $spec['inset_y'] + $qr + self::QR_GAP_MM,
				'w' => $w,
				'h' => $area - $qr - self::QR_GAP_MM,
			);
			$qr_x = $spec['inset_x'] + ( $w - $qr ) / 2;
			$qr_y = $spec['inset_y'];
		}

		$code_lines = self::code_lines( $text['w'], $pt );
		$available  = (int) floor( $text['h'] / $line + 1e-6 ) - $required - $code_lines;

		if ( 0 === $code_lines || $available < 0 ) {
			return null;
		}

		// Optional fields by priority; the name gets two lines when there is room.
		$lines = array();

		foreach ( $fields as $field ) {
			$want = 'name' === $field ? 2 : 1;
			$n    = min( $want, $available );

			if ( $n <= 0 ) {
				break;
			}

			$lines[ $field ] = $n;
			$available      -= $n;
		}

		return array(
			'orientation' => $row ? 'row' : 'stack',
			'module'      => $module,
			'qr'          => round( $qr, 4 ),
			'qr_x'        => round( $qr_x, 4 ),
			'qr_y'        => round( $qr_y, 4 ),
			'text'        => array_map( static fn( $v ) => round( $v, 4 ), $text ),
			'font_pt'     => $pt,
			'line'        => round( $line, 4 ),
			'code_lines'  => $code_lines,
			'lines'       => $lines,
			'fields'      => array_keys( $lines ),
			'test_mark'   => $test_mark,
		);
	}

	/**
	 * Module size for an available size per module, or null below the minimum.
	 *
	 * On thermal printers the module is rounded down to whole printer dots when that
	 * keeps it at or above the minimum.
	 *
	 * @param array<string, mixed> $spec Layout.
	 * @param float                $mm   Available size per module.
	 */
	private static function module( array $spec, float $mm ): ?float {
		$mm = min( self::MAX_MODULE_MM, $mm );

		if ( $mm + 1e-9 < self::MIN_MODULE_MM ) {
			return null;
		}

		if ( $spec['dpi'] > 0 ) {
			$dot     = 25.4 / $spec['dpi'];
			$snapped = floor( $mm / $dot + 1e-9 ) * $dot;

			if ( $snapped + 1e-9 >= self::MIN_MODULE_MM ) {
				return round( $snapped, 5 );
			}
		}

		return max( self::MIN_MODULE_MM, floor( $mm * 1000 ) / 1000 );
	}

	/**
	 * Lines the code text needs in a text column: 1, 2 (wrapped after the second hyphen), or 0 if it cannot fit.
	 *
	 * @param float $width Text column width.
	 * @param float $pt    Font size.
	 */
	private static function code_lines( float $width, float $pt ): int {
		$char = self::MONO_EM * $pt * self::PT_MM;

		if ( self::CODE_CHARS * $char <= $width ) {
			return 1;
		}

		return self::CODE_HALF_CHARS * $char <= $width ? 2 : 0;
	}

	/**
	 * A custom-layout validation error.
	 *
	 * @param string $field   Offending field.
	 * @param string $message Message.
	 */
	private static function invalid( string $field, string $message ): WP_Error {
		return new WP_Error( 'pqbg_invalid_layout', $message, array( 'field' => $field ) );
	}
}
