<?php
/**
 * Server-rendered SVG charts for the in-store reports (Phase 9B). No JavaScript
 * and no library: the markup is built here and styled by assets/pqbg-reports.css.
 *
 * Accessibility: every chart is role="img" with a <title> and <desc>; every mark is
 * a focusable group with its own <title> (the browser's tooltip on hover, read by
 * screen readers on focus); the caller always prints the same numbers as a data
 * table next to the chart, so nothing is available only in the picture.
 *
 * Marks follow one spec: columns/bars at most 24 px thick with a 4 px rounded data
 * end and a square baseline, hairline gridlines, one series colour (blue; negative
 * values red, the diverging pair), a one-hue sequential ramp for the heatmap, and
 * text in text colours only. Every label and title is escaped here.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * SVG chart builder.
 */
final class ReportChart {

	const WIDTH = 720;

	/** Sequential steps of the heatmap (0 = no sales). */
	const HEAT_STEPS = 6;

	/** @var int Unique ID counter for this request. */
	private static int $seq = 0;

	/**
	 * A column chart (one series; values may be negative).
	 *
	 * @param array<int, array{label: string, value: float, text: string}> $points Points in order.
	 * @param string                                                        $title  Chart title (also the accessible name).
	 * @param string                                                        $desc   One-sentence description.
	 * @param callable                                                      $tick   Formats an axis value.
	 * @return string SVG markup.
	 */
	public static function columns( array $points, string $title, string $desc, callable $tick ): string {
		$w      = self::WIDTH;
		$h      = 260;
		$left   = 72;
		$right  = 12;
		$top    = 14;
		$bottom = 34;
		$values = array_map( static fn( $p ) => (float) $p['value'], $points );
		$max    = max( array_merge( array( 0.0 ), $values ) );
		$min    = min( array_merge( array( 0.0 ), $values ) );
		$ticks  = self::ticks( $min, $max );
		$lo     = min( $ticks );
		$hi     = max( $ticks );
		$plot_h = $h - $top - $bottom;
		$y      = static fn( float $v ): float => $top + ( $hi - $v ) / ( $hi - $lo ) * $plot_h;
		$n      = max( 1, count( $points ) );
		$slot   = ( $w - $left - $right ) / $n;
		$bar    = min( 24.0, max( 2.0, $slot * 0.62 ) );
		$every  = (int) ceil( $n / 12 );
		$id     = self::id();
		$out    = self::open( $id, $w, $h, $title, $desc );

		foreach ( $ticks as $t ) {
			$ty   = self::n( $y( $t ) );
			$out .= '<line class="pqbg-chart__grid' . ( 0.0 === (float) $t ? ' pqbg-chart__grid--zero' : '' ) . '" x1="' . $left . '" x2="' . ( $w - $right ) . '" y1="' . $ty . '" y2="' . $ty . '"/>';
			$out .= '<text class="pqbg-chart__tick" x="' . ( $left - 8 ) . '" y="' . self::n( $y( $t ) + 4 ) . '" text-anchor="end">' . esc_html( (string) $tick( $t ) ) . '</text>';
		}

		foreach ( array_values( $points ) as $i => $p ) {
			$cx   = $left + $slot * $i + $slot / 2;
			$x    = $cx - $bar / 2;
			$v    = (float) $p['value'];
			$y0   = $y( 0.0 );
			$y1   = $y( $v );
			$tip  = $p['label'] . ': ' . $p['text'];
			$out .= '<g class="pqbg-chart__item" tabindex="0"><title>' . esc_html( $tip ) . '</title>';
			$out .= '<rect class="pqbg-chart__hit" x="' . self::n( $left + $slot * $i ) . '" y="' . $top . '" width="' . self::n( $slot ) . '" height="' . $plot_h . '"/>';

			if ( abs( $y1 - $y0 ) >= 0.5 ) {
				$out .= '<path class="pqbg-chart__mark' . ( $v < 0 ? ' pqbg-chart__mark--neg' : '' ) . '" d="' . self::column_path( $x, $bar, $y0, $y1 ) . '"/>';
			}

			$out .= '</g>';

			if ( 0 === $i % $every ) {
				$out .= '<text class="pqbg-chart__tick" x="' . self::n( $cx ) . '" y="' . ( $h - $bottom + 18 ) . '" text-anchor="middle">' . esc_html( $p['label'] ) . '</text>';
			}
		}

		return $out . '</svg>';
	}

	/**
	 * A horizontal bar chart (one series, non-negative values), value at the bar tip.
	 *
	 * @param array<int, array{label: string, value: float, text: string}> $items Items in display order.
	 * @param string                                                       $title Chart title.
	 * @param string                                                       $desc  Description.
	 * @param int                                                          $width Width in px (narrow panels: 480).
	 * @return string SVG markup.
	 */
	public static function bars( array $items, string $title, string $desc, int $width = self::WIDTH ): string {
		$w     = max( 360, $width );
		$row   = 30;
		$label = (int) round( $w * 0.3 );
		$value = 130;
		$top   = 6;
		$h     = $top * 2 + $row * max( 1, count( $items ) );
		$max   = max( array_merge( array( 0.0 ), array_map( static fn( $i ) => (float) $i['value'], $items ) ) );
		$span  = $w - $label - $value - 8;
		$id    = self::id();
		$out   = self::open( $id, $w, $h, $title, $desc );

		$out .= '<line class="pqbg-chart__grid pqbg-chart__grid--zero" x1="' . $label . '" x2="' . $label . '" y1="' . $top . '" y2="' . ( $h - $top ) . '"/>';

		foreach ( array_values( $items ) as $i => $item ) {
			$y    = $top + $row * $i;
			$len  = $max > 0 ? max( 0.0, (float) $item['value'] ) / $max * $span : 0.0;
			$bar  = 18;
			$by   = $y + ( $row - $bar ) / 2;
			$out .= '<g class="pqbg-chart__item" tabindex="0"><title>' . esc_html( $item['label'] . ': ' . $item['text'] ) . '</title>';
			$out .= '<rect class="pqbg-chart__hit" x="0" y="' . $y . '" width="' . $w . '" height="' . $row . '"/>';
			$out .= '<text class="pqbg-chart__label" x="' . ( $label - 8 ) . '" y="' . ( $y + 19 ) . '" text-anchor="end">' . esc_html( self::shorten( $item['label'], (int) floor( $label / 7.5 ) ) ) . '</text>';

			if ( $len >= 0.5 ) {
				$out .= '<path class="pqbg-chart__mark" d="' . self::bar_path( $label, $len, $by, $bar ) . '"/>';
			}

			$out .= '<text class="pqbg-chart__value" x="' . self::n( $label + $len + 6 ) . '" y="' . ( $y + 19 ) . '">' . esc_html( $item['text'] ) . '</text></g>';
		}

		return $out . '</svg>';
	}

	/**
	 * Hour-of-day × day-of-week heatmap.
	 *
	 * @param array<int, array<int, float>>  $values grid[weekday 1..7][hour 0..23] => value.
	 * @param array<int, array<int, string>> $tips   Same shape: the tooltip text of each cell.
	 * @param array<int, string>             $days   Weekday labels 1..7, in display order (keys = weekday).
	 * @param string                         $title  Title.
	 * @param string                         $desc   Description.
	 * @return string SVG markup.
	 */
	public static function heatmap( array $values, array $tips, array $days, string $title, string $desc ): string {
		$cell = 26;
		$gap  = 2;
		$left = 48;
		$top  = 22;
		$w    = $left + 24 * $cell + 8;
		$h    = $top + count( $days ) * $cell + 40;
		$max  = 0.0;

		foreach ( $values as $row ) {
			$max = max( $max, (float) max( $row ) );
		}

		$id  = self::id();
		$out = self::open( $id, $w, $h, $title, $desc );

		for ( $hour = 0; $hour < 24; $hour += 3 ) {
			$out .= '<text class="pqbg-chart__tick" x="' . ( $left + $hour * $cell ) . '" y="14">' . esc_html( sprintf( '%02d:00', $hour ) ) . '</text>';
		}

		$r = 0;

		foreach ( $days as $day => $label ) {
			$y    = $top + $r * $cell;
			$out .= '<text class="pqbg-chart__tick" x="' . ( $left - 8 ) . '" y="' . ( $y + 17 ) . '" text-anchor="end">' . esc_html( $label ) . '</text>';

			for ( $hour = 0; $hour < 24; $hour++ ) {
				$v     = (float) ( $values[ $day ][ $hour ] ?? 0 );
				$step  = $v <= 0 || $max <= 0 ? 0 : max( 1, (int) ceil( $v / $max * self::HEAT_STEPS ) );
				$out  .= '<g class="pqbg-chart__item" tabindex="0"><title>' . esc_html( (string) ( $tips[ $day ][ $hour ] ?? '' ) ) . '</title>';
				$out  .= '<rect class="pqbg-heat pqbg-heat--' . $step . '" x="' . ( $left + $hour * $cell ) . '" y="' . $y . '" width="' . ( $cell - $gap ) . '" height="' . ( $cell - $gap ) . '" rx="3"/></g>';
			}

			$r++;
		}

		// Legend: fewer → more.
		$ly   = $top + count( $days ) * $cell + 14;
		$out .= '<text class="pqbg-chart__tick" x="' . $left . '" y="' . ( $ly + 12 ) . '">' . esc_html__( 'None', 'product-qrcode-barcode-generator' ) . '</text>';
		$lx   = $left + 44;

		for ( $s = 0; $s <= self::HEAT_STEPS; $s++ ) {
			$out .= '<rect class="pqbg-heat pqbg-heat--' . $s . '" x="' . ( $lx + $s * 20 ) . '" y="' . $ly . '" width="18" height="16" rx="3"/>';
		}

		$out .= '<text class="pqbg-chart__tick" x="' . ( $lx + ( self::HEAT_STEPS + 1 ) * 20 + 6 ) . '" y="' . ( $ly + 12 ) . '">' . esc_html__( 'Most', 'product-qrcode-barcode-generator' ) . '</text>';

		return $out . '</svg>';
	}

	/**
	 * Clean axis ticks covering [min, max] (always including 0), 3–6 of them.
	 *
	 * @param float $min Smallest value (≤ 0).
	 * @param float $max Largest value (≥ 0).
	 * @return float[]
	 */
	public static function ticks( float $min, float $max ): array {
		$range = $max - $min;

		if ( $range <= 0 ) {
			return array( 0.0, 1.0 );
		}

		$raw  = $range / 4;
		$mag  = 10 ** floor( log10( $raw ) );
		$step = $mag;

		foreach ( array( 1, 2, 2.5, 5, 10 ) as $m ) {
			if ( $m * $mag >= $raw ) {
				$step = $m * $mag;
				break;
			}
		}

		$out = array();

		for ( $t = floor( $min / $step ) * $step; $t <= ceil( $max / $step ) * $step + $step / 2; $t += $step ) {
			$out[] = round( $t, 6 );
		}

		return $out;
	}

	/**
	 * The opening <svg> with its accessible name and description.
	 *
	 * @param string $id    Unique ID.
	 * @param int    $w     Width.
	 * @param int    $h     Height.
	 * @param string $title Title.
	 * @param string $desc  Description.
	 */
	private static function open( string $id, int $w, int $h, string $title, string $desc ): string {
		return '<svg class="pqbg-chart" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" role="img" aria-labelledby="' . esc_attr( $id ) . '-t ' . esc_attr( $id ) . '-d">'
			. '<title id="' . esc_attr( $id ) . '-t">' . esc_html( $title ) . '</title><desc id="' . esc_attr( $id ) . '-d">' . esc_html( $desc ) . '</desc>';
	}

	/**
	 * Path of a column with a 4 px rounded data end and a square baseline end.
	 *
	 * @param float $x  Left.
	 * @param float $w  Width.
	 * @param float $y0 Baseline y.
	 * @param float $y1 Data end y.
	 */
	private static function column_path( float $x, float $w, float $y0, float $y1 ): string {
		$r   = min( 4.0, $w / 2, abs( $y1 - $y0 ) );
		$dir = $y1 < $y0 ? 1 : -1; // Up for positive values.

		return 'M' . self::n( $x ) . ',' . self::n( $y0 )
			. 'V' . self::n( $y1 + $dir * $r )
			. 'Q' . self::n( $x ) . ',' . self::n( $y1 ) . ' ' . self::n( $x + $r ) . ',' . self::n( $y1 )
			. 'H' . self::n( $x + $w - $r )
			. 'Q' . self::n( $x + $w ) . ',' . self::n( $y1 ) . ' ' . self::n( $x + $w ) . ',' . self::n( $y1 + $dir * $r )
			. 'V' . self::n( $y0 ) . 'Z';
	}

	/**
	 * Path of a horizontal bar with a 4 px rounded data end.
	 *
	 * @param float $x0 Baseline x.
	 * @param float $len Length.
	 * @param float $y  Top.
	 * @param float $h  Thickness.
	 */
	private static function bar_path( float $x0, float $len, float $y, float $h ): string {
		$r = min( 4.0, $h / 2, $len );
		$x = $x0 + $len;

		return 'M' . self::n( $x0 ) . ',' . self::n( $y )
			. 'H' . self::n( $x - $r )
			. 'Q' . self::n( $x ) . ',' . self::n( $y ) . ' ' . self::n( $x ) . ',' . self::n( $y + $r )
			. 'V' . self::n( $y + $h - $r )
			. 'Q' . self::n( $x ) . ',' . self::n( $y + $h ) . ' ' . self::n( $x - $r ) . ',' . self::n( $y + $h )
			. 'H' . self::n( $x0 ) . 'Z';
	}

	/**
	 * A label cut to $max characters with an ellipsis (the full text stays in the tooltip and table).
	 *
	 * @param string $text Text.
	 * @param int    $max  Characters.
	 */
	private static function shorten( string $text, int $max ): string {
		return mb_strlen( $text ) > $max ? rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…' : $text;
	}

	/**
	 * A coordinate with at most one decimal.
	 *
	 * @param float $v Value.
	 */
	private static function n( float $v ): string {
		return rtrim( rtrim( number_format( $v, 1, '.', '' ), '0' ), '.' );
	}

	/**
	 * A unique element ID for this request.
	 */
	private static function id(): string {
		return 'pqbg-chart-' . ( ++self::$seq );
	}
}
