<?php
/**
 * Cache of rendered QR and barcode SVGs, so a 300-label print job does not
 * re-encode every QR code (about 50 ms each).
 *
 * Storage: transients. On this kind of site they are rows in wp_options that
 * are not autoloaded; on a host with a persistent object cache WordPress keeps
 * them there instead. Nothing is written to files.
 *
 * Key: md5 of type | code | everything that affects the image:
 *   - QR: the exact payload from ScanUrl::for_code() (so the effective scan base
 *     URL), the quiet zone and the renderer/cache versions
 *   - barcode: the renderer arguments, its constants and the versions
 * Changing the scan base URL or the barcode arguments changes the key, so an old
 * image is never served. The stored value repeats the code and the fingerprint,
 * and both are checked on every read.
 *
 * Retired codes: callers only ask for codes they have just read as ACTIVE rows
 * (CodeRepository::find_active_for_products()); the cache has no lookup by
 * item, so a retired code can never come out of it.
 *
 * Bounds: entries expire after TTL, and an index (one option, not autoloaded)
 * keeps at most MAX_ENTRIES keys; the least recently used are evicted when it is
 * written, once per request (flush()). The cache is not "data": clear_all() runs
 * on every uninstall, whatever the data-preservation setting.
 *
 * Barcodes: barcode() checks Settings::is_barcode_enabled() before anything
 * else, so while barcodes are disabled nothing reaches the barcode library.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Rendered SVG cache.
 */
final class PrintCache {

	/** Bump when the stored format or anything that changes the output (outside the renderers' constants) changes. */
	const VERSION = 1;

	const PREFIX = 'pqbg_svg_';

	const INDEX_OPTION = 'pqbg_svg_cache_index';

	const TTL = 30 * DAY_IN_SECONDS;

	const MAX_ENTRIES = 2000;

	/** An index timestamp is refreshed on a hit only when it is older than this. */
	const TOUCH_AFTER = DAY_IN_SECONDS;

	/**
	 * Keys used in this request => time, written to the index by flush().
	 *
	 * @var array<string, int>
	 */
	private static $touched = array();

	/**
	 * Rendered in this request, by key (copies of one code are rendered once).
	 *
	 * @var array<string, string>
	 */
	private static $memory = array();

	/**
	 * Hit/miss counters for this request.
	 *
	 * @var array{hits: int, misses: int}
	 */
	private static $stats = array(
		'hits'   => 0,
		'misses' => 0,
	);

	/**
	 * The QR SVG for an ACTIVE code.
	 *
	 * @param string $code Product code (well-formed).
	 * @return string|WP_Error
	 */
	public static function qr( string $code ) {
		$url = ScanUrl::for_code( $code );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$fingerprint = implode( '|', array( 'qr', $code, $url, QrRenderer::QUIET_ZONE, self::VERSION, PQBG_VERSION ) );

		return self::remember( $code, $fingerprint, static fn() => ( new QrRenderer() )->render( $code ) );
	}

	/**
	 * The barcode SVG for an ACTIVE code, or pqbg_barcode_disabled.
	 *
	 * @param string              $code Product code (well-formed).
	 * @param array<string, mixed> $args BarcodeRenderer::render() arguments.
	 * @return string|WP_Error
	 */
	public static function barcode( string $code, array $args ) {
		// First, so the barcode library is never reached while barcodes are disabled.
		if ( ! Settings::is_barcode_enabled() ) {
			return new WP_Error( 'pqbg_barcode_disabled', __( 'Barcodes are disabled.', 'product-qrcode-barcode-generator' ) );
		}

		if ( ! CodeGenerator::is_valid_format( $code ) ) {
			return ScanUrl::invalid_code_error();
		}

		ksort( $args );
		$fingerprint = implode( '|', array( 'barcode', $code, wp_json_encode( $args ), BarcodeRenderer::QUIET_ZONE, BarcodeRenderer::TOP_MARGIN, BarcodeRenderer::BAR_HEIGHT, BarcodeRenderer::TEXT_SIZE, self::VERSION, PQBG_VERSION ) );

		return self::remember( $code, $fingerprint, static fn() => ( new BarcodeRenderer() )->render( $code, $args ) );
	}

	/**
	 * Writes the index for the keys used in this request and evicts the oldest beyond MAX_ENTRIES.
	 */
	public static function flush(): void {
		if ( array() === self::$touched ) {
			return;
		}

		$index = get_option( self::INDEX_OPTION, array() );
		$index = is_array( $index ) ? $index : array();
		$now   = time();

		$index = array_merge( $index, self::$touched );
		$index = array_filter( $index, static fn( $t ) => is_int( $t ) && $t > $now - self::TTL );
		arsort( $index );

		foreach ( array_slice( array_keys( $index ), self::MAX_ENTRIES ) as $key ) {
			delete_transient( $key );
			unset( $index[ $key ] );
		}

		update_option( self::INDEX_OPTION, $index, false );
		self::$touched = array();
	}

	/**
	 * Deletes every cached SVG and the index (uninstall, tests). Works without the plugin booted.
	 */
	public static function clear_all(): void {
		global $wpdb;

		$index = get_option( self::INDEX_OPTION, array() );

		// Through the transient API first, so a persistent object cache is cleared too.
		foreach ( array_keys( is_array( $index ) ? $index : array() ) as $key ) {
			if ( is_string( $key ) && str_starts_with( $key, self::PREFIX ) ) {
				delete_transient( $key );
			}
		}

		// Then any rows the index does not know about (e.g. lost in a concurrent index write).
		foreach ( array( '_transient_', '_transient_timeout_' ) as $kind ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $kind . self::PREFIX ) . '%' ) );
		}

		delete_option( self::INDEX_OPTION );
		wp_cache_delete( 'alloptions', 'options' );

		self::$touched = array();
		self::$memory  = array();
	}

	/**
	 * Hit/miss counters of this request.
	 *
	 * @return array{hits: int, misses: int}
	 */
	public static function stats(): array {
		return self::$stats;
	}

	/**
	 * Forgets this request's in-memory copies and counters (tests).
	 */
	public static function reset_request(): void {
		self::$memory  = array();
		self::$touched = array();
		self::$stats   = array(
			'hits'   => 0,
			'misses' => 0,
		);
	}

	/**
	 * Transient key of a fingerprint.
	 *
	 * @param string $fingerprint Everything that affects the image.
	 */
	public static function key( string $fingerprint ): string {
		return self::PREFIX . md5( $fingerprint );
	}

	/**
	 * Returns the cached SVG or renders and stores it. Render errors are never cached.
	 *
	 * @param string   $code        Code the image must belong to.
	 * @param string   $fingerprint Everything that affects the image.
	 * @param callable $render      Returns the SVG or a WP_Error.
	 * @return string|WP_Error
	 */
	private static function remember( string $code, string $fingerprint, callable $render ) {
		$key = self::key( $fingerprint );
		$now = time();

		if ( isset( self::$memory[ $key ] ) ) {
			return self::$memory[ $key ];
		}

		$stored = get_transient( $key );

		if ( is_array( $stored ) && ( $stored['c'] ?? null ) === $code && ( $stored['f'] ?? null ) === md5( $fingerprint ) && is_string( $stored['s'] ?? null ) && str_starts_with( $stored['s'], '<svg ' ) ) {
			++self::$stats['hits'];
			self::$memory[ $key ] = $stored['s'];

			if ( ! isset( $stored['t'] ) || (int) $stored['t'] < $now - self::TOUCH_AFTER ) {
				self::$touched[ $key ] = $now;
			}

			return $stored['s'];
		}

		++self::$stats['misses'];
		$svg = $render();

		if ( is_wp_error( $svg ) ) {
			return $svg;
		}

		set_transient(
			$key,
			array(
				'c' => $code,
				'f' => md5( $fingerprint ),
				't' => $now,
				's' => $svg,
			),
			self::TTL
		);

		self::$memory[ $key ]  = $svg;
		self::$touched[ $key ] = $now;

		return $svg;
	}
}
