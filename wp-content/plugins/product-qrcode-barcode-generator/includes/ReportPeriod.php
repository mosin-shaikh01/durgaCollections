<?php
/**
 * Report periods (Phase 9B): date-range presets, the comparison period and the
 * time buckets of a chart, all in the SITE timezone (wp_timezone()).
 *
 * A period is whole local days, half-open [start, end) in UTC for created_at_gmt,
 * exactly like SalesQuery::range() (the shared presets give identical bounds).
 *
 * Presets:
 *   today, yesterday, this_week, last_week (weeks start on the WordPress
 *   "Week starts on" setting), this_month, last_month, last7, last30, last90
 *   (today and the N−1 days before), last12m (today and the 364/365 days back to
 *   the same date a year ago, exclusive), custom (from/to, both inclusive, at most
 *   MAX_DAYS; swapped if reversed, the start moved forward if longer).
 *
 * Comparison (decision D2, "to the same point in time"):
 *   - today, this_week, this_month are "to date": they compare with the previous
 *     day/week/month up to the same local time (and, for a month, the same day of
 *     the month, clamped to that month's length: on 31 March the comparison is the
 *     whole of February);
 *   - yesterday, last_week, last_month compare with the whole period before;
 *   - last N days and custom compare with the same number of days immediately before.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Report date ranges, comparisons and buckets.
 */
final class ReportPeriod {

	const PRESETS = array( 'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'last7', 'last30', 'last90', 'last12m', 'custom' );

	/** Longest custom range (about five years). */
	const MAX_DAYS = 1830;

	/** Longest range that may be grouped by day. */
	const MAX_DAY_BUCKETS = 400;

	const GROUPINGS = array( 'hour', 'day', 'week', 'month' );

	/**
	 * Labels of the presets, in menu order.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'today'      => __( 'Today', 'product-qrcode-barcode-generator' ),
			'yesterday'  => __( 'Yesterday', 'product-qrcode-barcode-generator' ),
			'this_week'  => __( 'This week', 'product-qrcode-barcode-generator' ),
			'last_week'  => __( 'Last week', 'product-qrcode-barcode-generator' ),
			'this_month' => __( 'This month', 'product-qrcode-barcode-generator' ),
			'last_month' => __( 'Last month', 'product-qrcode-barcode-generator' ),
			'last7'      => __( 'Last 7 days', 'product-qrcode-barcode-generator' ),
			'last30'     => __( 'Last 30 days', 'product-qrcode-barcode-generator' ),
			'last90'     => __( 'Last 90 days', 'product-qrcode-barcode-generator' ),
			'last12m'    => __( 'Last 12 months', 'product-qrcode-barcode-generator' ),
		);
	}

	/**
	 * A period with its comparison.
	 *
	 * @param string   $preset One of PRESETS; anything else means $fallback.
	 * @param string   $from   Custom start, Y-m-d.
	 * @param string   $to     Custom end, Y-m-d (inclusive).
	 * @param int|null $now    Unix time "now" (tests).
	 * @param string   $fallback Preset used for an unknown or empty one.
	 * @return array<string, mixed> preset, start, end (UTC Y-m-d H:i:s), from, to (local Y-m-d, inclusive),
	 *         days, to_date, swapped, clamped, compare (start, end, from, to, partial_until).
	 */
	public static function resolve( string $preset, string $from = '', string $to = '', ?int $now = null, string $fallback = 'today' ): array {
		$tz      = wp_timezone();
		$now_dt  = ( new DateTimeImmutable( '@' . ( $now ?? time() ) ) )->setTimezone( $tz );
		$today   = $now_dt->setTime( 0, 0 );
		$preset  = in_array( $preset, self::PRESETS, true ) ? $preset : $fallback;
		$swapped = false;
		$clamped = false;
		$to_date = false;
		$week    = self::week_start( $today );

		switch ( $preset ) {
			case 'yesterday':
				$start = $today->modify( '-1 day' );
				$end   = $today;
				break;
			case 'this_week':
				$start   = $week;
				$end     = $today->modify( '+1 day' );
				$to_date = true;
				break;
			case 'last_week':
				$start = $week->modify( '-7 days' );
				$end   = $week;
				break;
			case 'this_month':
				$start   = $today->modify( 'first day of this month' );
				$end     = $today->modify( '+1 day' );
				$to_date = true;
				break;
			case 'last_month':
				$start = $today->modify( 'first day of last month' );
				$end   = $today->modify( 'first day of this month' );
				break;
			case 'last7':
			case 'last30':
			case 'last90':
				$start = $today->modify( '-' . ( (int) substr( $preset, 4 ) - 1 ) . ' days' );
				$end   = $today->modify( '+1 day' );
				break;
			case 'last12m':
				$start = $today->modify( '-1 year' )->modify( '+1 day' );
				$end   = $today->modify( '+1 day' );
				break;
			case 'custom':
				$a = self::parse_day( $from, $tz );
				$b = self::parse_day( $to, $tz );

				if ( null === $a && null === $b ) {
					return self::resolve( $fallback, '', '', $now, 'custom' === $fallback ? 'today' : $fallback );
				}

				$a = $a ?? $b;
				$b = $b ?? $a;

				if ( $a > $b ) {
					list( $a, $b ) = array( $b, $a );
					$swapped       = true;
				}

				if ( self::days_between( $a, $b ) + 1 > self::MAX_DAYS ) {
					$a       = $b->modify( '-' . ( self::MAX_DAYS - 1 ) . ' days' );
					$clamped = true;
				}

				$start = $a;
				$end   = $b->modify( '+1 day' );
				break;
			default: // today.
				$start   = $today;
				$end     = $today->modify( '+1 day' );
				$to_date = true;
		}

		$compare = self::comparison( $preset, $start, $end, $now_dt, $to_date );
		$utc     = new DateTimeZone( 'UTC' );

		return array(
			'preset'  => $preset,
			'start'   => $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'end'     => $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'from'    => $start->format( 'Y-m-d' ),
			'to'      => $end->modify( '-1 day' )->format( 'Y-m-d' ),
			'days'    => self::days_between( $start, $end ),
			'to_date' => $to_date,
			'swapped' => $swapped,
			'clamped' => $clamped,
			'compare' => array(
				'start'         => $compare[0]->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'end'           => $compare[1]->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				'from'          => $compare[0]->format( 'Y-m-d' ),
				'to'            => $compare[2]->format( 'Y-m-d' ),
				'partial_until' => $compare[3], // Local "Y-m-d H:i" when the comparison stops mid-day, else ''.
			),
		);
	}

	/**
	 * Filters for SalesQuery::where() from a period (other filters empty).
	 *
	 * @param array<string, mixed> $period  From resolve().
	 * @param bool                 $compare Use the comparison period.
	 * @return array<string, mixed>
	 */
	public static function where_filters( array $period, bool $compare = false ): array {
		$p = $compare ? $period['compare'] : $period;

		return array(
			'start'  => $p['start'],
			'end'    => $p['end'],
			'seller' => 0,
			'method' => '',
			'status' => '',
			'search' => '',
		);
	}

	/**
	 * The default grouping of a chart for a period.
	 *
	 * @param array<string, mixed> $period From resolve().
	 */
	public static function default_grouping( array $period ): string {
		if ( 1 === $period['days'] ) {
			return 'hour';
		}

		if ( $period['days'] <= 62 ) {
			return 'day';
		}

		return $period['days'] <= self::MAX_DAY_BUCKETS ? 'week' : 'month';
	}

	/**
	 * A requested grouping, or the default one when it does not suit the period
	 * (hours only for one day; days only up to MAX_DAY_BUCKETS).
	 *
	 * @param array<string, mixed> $period From resolve().
	 * @param string               $group  Requested grouping.
	 */
	public static function grouping( array $period, string $group ): string {
		if ( ! in_array( $group, self::GROUPINGS, true ) ) {
			return self::default_grouping( $period );
		}

		if ( 'hour' === $group && 1 !== $period['days'] ) {
			return self::default_grouping( $period );
		}

		if ( 'day' === $group && $period['days'] > self::MAX_DAY_BUCKETS ) {
			return 'week';
		}

		return $group;
	}

	/**
	 * The buckets of a period: local boundaries as Unix times, with labels.
	 * The first bucket starts at the period start and the last ends at its end
	 * (a week or month cut by the period is shortened, never widened).
	 *
	 * @param array<string, mixed> $period From resolve().
	 * @param string               $group  hour|day|week|month.
	 * @return array<int, array{start: int, end: int, label: string, from: string, to: string}>
	 */
	public static function buckets( array $period, string $group ): array {
		$tz    = wp_timezone();
		$start = ( new DateTimeImmutable( $period['start'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
		$end   = ( new DateTimeImmutable( $period['end'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
		$out   = array();

		for ( $cur = $start; $cur < $end; ) {
			switch ( $group ) {
				case 'hour':
					$next  = $cur->modify( '+1 hour' );
					$label = wp_date( 'H:i', $cur->getTimestamp() );
					break;
				case 'week':
					$next  = self::week_start( $cur )->modify( '+7 days' );
					$label = wp_date( 'j M', $cur->getTimestamp() );
					break;
				case 'month':
					$next  = $cur->modify( 'first day of next month' )->setTime( 0, 0 );
					$label = wp_date( 'M Y', $cur->getTimestamp() );
					break;
				default:
					$next  = $cur->modify( '+1 day' );
					$label = wp_date( 'j M', $cur->getTimestamp() );
			}

			$next  = min( $next, $end );
			$out[] = array(
				'start' => $cur->getTimestamp(),
				'end'   => $next->getTimestamp(),
				'label' => $label,
				'from'  => $cur->format( 'Y-m-d' ),
				'to'    => $next->modify( '-1 second' )->format( 'Y-m-d' ),
			);
			$cur   = $next;
		}

		return $out;
	}

	/**
	 * "1 Sep – 26 Sep 2026" for a period or its comparison ('' parts are fine).
	 *
	 * @param string $from Local Y-m-d.
	 * @param string $to   Local Y-m-d.
	 */
	public static function span_label( string $from, string $to ): string {
		$tz = wp_timezone();
		$a  = DateTimeImmutable::createFromFormat( '!Y-m-d', $from, $tz );
		$b  = DateTimeImmutable::createFromFormat( '!Y-m-d', $to, $tz );

		if ( ! $a || ! $b ) {
			return '';
		}

		if ( $from === $to ) {
			return wp_date( 'D j M Y', $a->getTimestamp() );
		}

		return wp_date( $a->format( 'Y' ) === $b->format( 'Y' ) ? 'j M' : 'j M Y', $a->getTimestamp() ) . ' – ' . wp_date( 'j M Y', $b->getTimestamp() );
	}

	/**
	 * Local midnight starting the week that contains $day (WordPress "Week starts on").
	 *
	 * @param DateTimeImmutable $day Local date/time.
	 */
	public static function week_start( DateTimeImmutable $day ): DateTimeImmutable {
		$first = (int) get_option( 'start_of_week', 1 );
		$back  = ( (int) $day->format( 'w' ) - $first + 7 ) % 7;

		return $day->setTime( 0, 0 )->modify( '-' . $back . ' days' );
	}

	/**
	 * The comparison of a period: [start, end, last local day shown, partial-until text].
	 *
	 * @param string            $preset  Preset.
	 * @param DateTimeImmutable $start   Local start.
	 * @param DateTimeImmutable $end     Local end (exclusive).
	 * @param DateTimeImmutable $now     Local now.
	 * @param bool              $to_date Whether the period runs to now.
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: DateTimeImmutable, 3: string}
	 */
	private static function comparison( string $preset, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $now, bool $to_date ): array {
		switch ( $preset ) {
			case 'this_month':
			case 'last_month':
				$p_start = $start->modify( 'first day of last month' );
				$p_end   = $start;
				break;
			case 'this_week':
			case 'last_week':
				$p_start = $start->modify( '-7 days' );
				$p_end   = $start;
				break;
			default: // A run of whole days: the same number of days immediately before.
				$days    = self::days_between( $start, $end );
				$p_start = $start->modify( '-' . $days . ' days' );
				$p_end   = $start;
		}

		if ( ! $to_date ) {
			return array( $p_start, $p_end, $p_end->modify( '-1 day' ), '' );
		}

		// The same point in time: as many whole days into the previous period, then the same time of day.
		$into = self::days_between( $start, $now->setTime( 0, 0 ) );
		$cut  = $p_start->modify( '+' . $into . ' days' )->setTime( (int) $now->format( 'H' ), (int) $now->format( 'i' ), (int) $now->format( 's' ) );

		if ( $cut >= $p_end ) {
			return array( $p_start, $p_end, $p_end->modify( '-1 day' ), '' );
		}

		return array( $p_start, $cut, $cut->setTime( 0, 0 ), $cut->format( 'Y-m-d H:i' ) );
	}

	/**
	 * Whole local days from $a to $b (both local midnights).
	 *
	 * @param DateTimeImmutable $a Earlier.
	 * @param DateTimeImmutable $b Later.
	 */
	private static function days_between( DateTimeImmutable $a, DateTimeImmutable $b ): int {
		return (int) $a->setTime( 0, 0 )->diff( $b->setTime( 0, 0 ) )->format( '%r%a' );
	}

	/**
	 * A Y-m-d day at local midnight, or null when malformed.
	 *
	 * @param string       $day Y-m-d.
	 * @param DateTimeZone $tz  Site timezone.
	 */
	private static function parse_day( string $day, DateTimeZone $tz ): ?DateTimeImmutable {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $day ) ) {
			return null;
		}

		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $day, $tz );

		return $date && $date->format( 'Y-m-d' ) === $day ? $date : null;
	}
}
