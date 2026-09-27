<?php
/**
 * The performance signal (Phase 11, decision D9 as changed by the owner): tells
 * administrators on the Dashboard when the reports may need the roll-up table or
 * result cache recorded in progress.md, instead of building either before it is
 * needed. It warns only on a repeated condition, never after one slow page:
 *   - at least SLOW_MIN of the last SAMPLES Dashboard renders took more than
 *     SLOW_MS to compute their figures, or
 *   - completed in-store sales over the last DAYS days average more than
 *     PER_DAY a day.
 *
 * Stored: only the last SAMPLES compute times, in whole milliseconds, and the time
 * of the last write, in the non-autoloaded option OPTION (runtime state, removed on
 * every uninstall). At most one write per THROTTLE seconds.
 * Nothing else is stored and nothing is scheduled.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard timing samples and the signal.
 */
final class PerfSignal {

	const OPTION   = 'pqbg_perf_samples';
	const SAMPLES  = 10;
	const SLOW_MS  = 2000;
	const SLOW_MIN = 3;
	const DAYS     = 30;
	const PER_DAY  = 300;
	const THROTTLE = 60;

	/**
	 * Records one Dashboard compute time (milliseconds), at most one write per THROTTLE
	 * seconds (renders in between are not sampled), and returns the samples kept.
	 *
	 * Stored as array( 'at' => Unix time of the last write, 'samples' => int[] ).
	 *
	 * @param float    $ms  Milliseconds.
	 * @param int|null $now Unix time (tests).
	 * @return int[] Newest last.
	 */
	public static function record( float $ms, ?int $now = null ): array {
		$now     = null === $now ? time() : $now;
		$stored  = get_option( self::OPTION, false );
		$samples = self::samples();

		if ( is_array( $stored ) && isset( $stored['at'] ) && $now - (int) $stored['at'] < self::THROTTLE && $now >= (int) $stored['at'] ) {
			return $samples; // Written less than a minute ago: nothing is written.
		}

		$samples[] = max( 0, (int) round( $ms ) );
		$samples   = array_slice( $samples, -self::SAMPLES );
		$value     = array(
			'at'      => $now,
			'samples' => $samples,
		);

		if ( false === $stored ) {
			add_option( self::OPTION, $value, '', false );
		} else {
			update_option( self::OPTION, $value, false );
		}

		return $samples;
	}

	/**
	 * The stored samples, newest last (also reads a plain list of samples).
	 *
	 * @return int[]
	 */
	public static function samples(): array {
		$stored = get_option( self::OPTION, array() );
		$list   = is_array( $stored ) && isset( $stored['samples'] ) && is_array( $stored['samples'] ) ? $stored['samples'] : $stored;

		return is_array( $list ) ? array_values( array_map( 'intval', array_filter( $list, 'is_numeric' ) ) ) : array();
	}

	/**
	 * Completed in-store sales per day over the last DAYS days.
	 *
	 * @param int|null $now Unix time (tests).
	 */
	public static function sales_per_day( ?int $now = null ): float {
		global $wpdb;

		$now   = null === $now ? time() : $now;
		$table = Schema::sales_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed identifier.
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'completed' AND created_at_gmt >= %s AND created_at_gmt <= %s", gmdate( 'Y-m-d H:i:s', $now - self::DAYS * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $now ) ) );

		return $n / self::DAYS;
	}

	/**
	 * The reasons to warn, if any (pure; tested with given numbers).
	 *
	 * @param int[] $samples Compute times, newest last.
	 * @param float $per_day Sales per day.
	 * @return array{slow?: array{0: int, 1: int}, busy?: float} slow: [slow renders, samples]; busy: sales per day.
	 */
	public static function evaluate( array $samples, float $per_day ): array {
		$out  = array();
		$last = array_slice( $samples, -self::SAMPLES );
		$slow = count( array_filter( $last, static fn( $ms ) => (int) $ms > self::SLOW_MS ) );

		if ( $slow >= self::SLOW_MIN ) {
			$out['slow'] = array( $slow, count( $last ) );
		}

		if ( $per_day > self::PER_DAY ) {
			$out['busy'] = $per_day;
		}

		return $out;
	}

	/**
	 * The Dashboard message for the reasons, or '' when there are none.
	 *
	 * @param array{slow?: array{0: int, 1: int}, busy?: float} $reasons From evaluate().
	 */
	public static function message( array $reasons ): string {
		$parts = array();

		if ( isset( $reasons['slow'] ) ) {
			/* translators: 1: slow renders, 2: renders counted, 3: seconds. */
			$parts[] = sprintf( __( '%1$d of the last %2$d Dashboard loads took more than %3$s s to compute their figures.', 'product-qrcode-barcode-generator' ), $reasons['slow'][0], $reasons['slow'][1], number_format_i18n( self::SLOW_MS / 1000 ) );
		}

		if ( isset( $reasons['busy'] ) ) {
			/* translators: 1: average sales per day, 2: days, 3: threshold. */
			$parts[] = sprintf( __( 'In-store sales averaged %1$s a day over the last %2$d days (more than %3$d).', 'product-qrcode-barcode-generator' ), number_format_i18n( $reasons['busy'], 1 ), self::DAYS, self::PER_DAY );
		}

		if ( array() === $parts ) {
			return '';
		}

		return implode( ' ', $parts ) . ' ' . __( 'In-store reports may be getting slow: the plugin README (In-store reports → Performance) describes the planned daily summary table or result cache.', 'product-qrcode-barcode-generator' );
	}
}
