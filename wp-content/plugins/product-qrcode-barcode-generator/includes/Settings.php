<?php
/**
 * Reads, validates and sanitises the pqbg_settings option.
 *
 * Keys (defaults in Plugin::default_settings()):
 *   barcodes_enabled  bool    Code 128 barcodes for hardware scanners. Off by default.
 *   scan_base_url     string  Absolute http(s) base for scan URLs. '' means home_url().
 *
 * Only the scan base URL is stored, never a full scan URL. Codes live in
 * pqbg_codes, so changing the base URL never touches any code.
 *
 * sanitize() is the Settings API callback. It changes only the known keys
 * present in its input and keeps every other stored key.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings access and validation.
 */
final class Settings {

	/** Longer base URLs make the QR code denser and harder to scan from a small label. */
	const MAX_URL_LENGTH = 100;

	/**
	 * Stored settings merged over the defaults (unknown keys dropped).
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		return Plugin::settings();
	}

	/**
	 * Whether barcodes may be rendered. Anything other than a stored boolean true counts as off.
	 */
	public static function is_barcode_enabled(): bool {
		return true === self::get()['barcodes_enabled'];
	}

	/**
	 * Whether an admin has set a scan base URL (instead of using the site URL).
	 */
	public static function has_scan_base_url_override(): bool {
		$stored = self::get()['scan_base_url'];

		return is_string( $stored ) && '' !== $stored && ! is_wp_error( self::validate_base_url( $stored ) );
	}

	/**
	 * The effective scan base URL, without a trailing slash, query string or fragment.
	 *
	 * The stored override is used when it is valid; otherwise the site URL.
	 */
	public static function get_scan_base_url(): string {
		if ( self::has_scan_base_url_override() ) {
			return self::get()['scan_base_url'];
		}

		$home  = home_url();
		$valid = self::validate_base_url( $home );

		if ( ! is_wp_error( $valid ) ) {
			return $valid;
		}

		// The site URL is always used, even if it fails the stricter override rules (e.g. its length).
		return untrailingslashit( (string) preg_replace( '/[?#].*$/s', '', $home ) );
	}

	/**
	 * Validates and normalises a scan base URL.
	 *
	 * Accepts only absolute http(s) URLs with a valid host, made of printable
	 * ASCII, without credentials. Path segments may contain only unreserved
	 * characters (A-Z a-z 0-9 . _ ~ -) or %XX escapes, and may not be empty,
	 * "." or "..". The scheme and host are lowercased; the port and path are
	 * kept; any query string, fragment and trailing slash are removed.
	 *
	 * @param string $url Candidate URL.
	 * @return string|WP_Error Normalised URL.
	 */
	public static function validate_base_url( string $url ) {
		if ( '' === $url || strlen( $url ) > self::MAX_URL_LENGTH || preg_match( '/[^\x21-\x7E]/', $url ) ) {
			return self::invalid_url();
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return self::invalid_url();
		}

		$scheme = strtolower( $parts['scheme'] );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return self::invalid_url();
		}

		// The URL must really start with "scheme://host", not rely on lenient parsing.
		if ( 0 !== stripos( $url, $scheme . '://' . $parts['host'] ) ) {
			return self::invalid_url();
		}

		$host = strtolower( $parts['host'] );

		if ( ! self::is_valid_host( $host ) ) {
			return self::invalid_url();
		}

		$port = '';

		if ( isset( $parts['port'] ) ) {
			if ( $parts['port'] < 1 || $parts['port'] > 65535 ) {
				return self::invalid_url();
			}

			$port = ':' . $parts['port'];
		}

		$path = rtrim( $parts['path'] ?? '', '/' );

		if ( '' !== $path ) {
			// Non-empty segments of unreserved characters or %XX escapes only.
			if ( ! preg_match( '#^(/([A-Za-z0-9._~-]|%[0-9A-Fa-f]{2})+)+$#D', $path ) ) {
				return self::invalid_url();
			}

			foreach ( explode( '/', substr( $path, 1 ) ) as $segment ) {
				if ( '.' === $segment || '..' === $segment ) {
					return self::invalid_url();
				}
			}
		}

		return $scheme . '://' . $host . $port . $path;
	}

	/**
	 * Whether a URL's host is a local or private address that phones outside the
	 * shop cannot reach: localhost and *.localhost, *.local, *.test,
	 * single-label hostnames, and any IP outside the global range (loopback,
	 * private, link-local, unique-local, reserved). A URL without a host counts as local.
	 *
	 * @param string $url URL to check.
	 */
	public static function is_local_url( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return true;
		}

		$host = rtrim( strtolower( trim( $host, '[]' ) ), '.' );

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE );
		}

		if ( 'localhost' === $host || ! str_contains( $host, '.' ) ) {
			return true;
		}

		foreach ( array( '.localhost', '.local', '.test' ) as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a URL uses plain http:// (a warning for printed labels, never a validation error).
	 *
	 * @param string $url URL to check.
	 */
	public static function is_http_url( string $url ): bool {
		return 'http' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	}

	/**
	 * Settings API sanitize callback for pqbg_settings.
	 *
	 * Starts from the stored option and changes only the known keys present in
	 * $input, so unrelated keys are never overwritten. An invalid scan base URL
	 * keeps the previous value and reports a settings error.
	 *
	 * @param mixed $input Submitted value.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$stored = get_option( Plugin::SETTINGS_OPTION, array() );
		$output = array_merge( Plugin::default_settings(), is_array( $stored ) ? $stored : array() );

		if ( ! is_array( $input ) ) {
			return $output;
		}

		if ( array_key_exists( 'barcodes_enabled', $input ) ) {
			$output['barcodes_enabled'] = in_array( $input['barcodes_enabled'], array( true, 1, '1' ), true );
		}

		if ( array_key_exists( 'scan_base_url', $input ) ) {
			$url = is_string( $input['scan_base_url'] ) ? trim( $input['scan_base_url'] ) : null;

			if ( '' === $url ) {
				$output['scan_base_url'] = '';
			} else {
				$valid = null === $url ? self::invalid_url() : self::validate_base_url( $url );

				if ( is_wp_error( $valid ) ) {
					if ( function_exists( 'add_settings_error' ) ) {
						add_settings_error( Plugin::SETTINGS_OPTION, $valid->get_error_code(), $valid->get_error_message() );
					}
				} else {
					$output['scan_base_url'] = $valid;
				}
			}
		}

		return $output;
	}

	/**
	 * Whether a lowercase host is a syntactically valid DNS name, IPv4 address or bracketed IPv6 address.
	 *
	 * @param string $host Host from wp_parse_url().
	 */
	private static function is_valid_host( string $host ): bool {
		if ( str_starts_with( $host, '[' ) ) {
			return str_ends_with( $host, ']' ) && false !== filter_var( substr( $host, 1, -1 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
		}

		if ( preg_match( '/^[0-9.]+$/', $host ) ) {
			return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
		}

		return strlen( $host ) <= 253 && 1 === preg_match( '/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/D', $host );
	}

	/**
	 * Error for an unusable scan base URL.
	 */
	private static function invalid_url(): WP_Error {
		return new WP_Error(
			'pqbg_invalid_scan_base_url',
			/* translators: %d: maximum number of characters. */
			sprintf( __( 'The scan base URL must be an absolute http:// or https:// address of at most %d characters, without spaces or login details. The previous value was kept.', 'product-qrcode-barcode-generator' ), self::MAX_URL_LENGTH )
		);
	}
}
