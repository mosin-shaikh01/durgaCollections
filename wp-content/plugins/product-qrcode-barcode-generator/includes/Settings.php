<?php
/**
 * Reads, validates and sanitises the pqbg_settings option.
 *
 * Keys (defaults in Plugin::default_settings()):
 *   barcodes_enabled  bool    Code 128 barcodes for hardware scanners. Off by default.
 *   scan_base_url     string  Absolute http(s) base for scan URLs. '' means home_url().
 *   payment_methods   array   Payment methods the sale form offers (PaymentMethods keys, at least one).
 *   code_prefix       string  Prefix of NEW product codes (CodeGenerator::PREFIX_PATTERN), default DC.
 *                             Existing codes never change; every well-formed prefix keeps scanning.
 *   receipt_shop_name string  Shop name on receipts (Phase 16); '' = the site title. One line.
 *   receipt_address   string  Address on receipts, up to 4 lines.
 *   receipt_phone     string  Phone on receipts: digits, spaces, + - ( ).
 *   receipt_gstin     string  '' or a GSTIN (format and check character), uppercased.
 *   receipt_footer    string  Footer text on receipts, up to 3 lines.
 *   receipt_show_seller bool  Whether receipts show the seller's first name. On by default.
 *   receipt_paper     string  Default receipt layout: a4, 80 or 58 (Receipt::PAPERS).
 *   upi_id            string  '' or the shop's UPI ID (UpiPayment::ID_PATTERN), lowercased.
 *   upi_payee_name    string  '' or the payee name UPI apps show (UpiPayment::PAYEE_PATTERN).
 *
 * An invalid value keeps the previous one and reports a settings error (as the scan
 * base URL and the code prefix do).
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

	/** Receipt text settings (Phase 16): key => [ maximum characters, maximum lines ]. */
	const RECEIPT_TEXTS = array(
		'receipt_shop_name' => array( 80, 1 ),
		'receipt_address'   => array( 200, 4 ),
		'receipt_footer'    => array( 200, 3 ),
	);

	/** Longest receipt phone number. */
	const PHONE_MAX = 30;

	/** GSTIN: 2-digit state code, PAN, entity number, "Z", check character. */
	const GSTIN_PATTERN = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/D';

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
	 * The prefix for new codes. A stored value that fails the rule (e.g. edited in the database) gives the default.
	 */
	public static function get_code_prefix(): string {
		$stored = self::get()['code_prefix'];

		return is_string( $stored ) && CodeGenerator::is_valid_prefix( $stored ) ? $stored : CodeGenerator::DEFAULT_PREFIX;
	}

	/**
	 * Validates and normalises a code prefix: spaces around it removed, uppercased, then CodeGenerator::PREFIX_PATTERN.
	 *
	 * @param mixed $value Submitted value.
	 * @return string|WP_Error Normalised prefix.
	 */
	public static function validate_code_prefix( $value ) {
		$prefix = is_string( $value ) ? strtoupper( trim( $value ) ) : '';

		if ( ! CodeGenerator::is_valid_prefix( $prefix ) ) {
			return new WP_Error(
				'pqbg_invalid_code_prefix',
				sprintf(
					/* translators: 1: minimum length, 2: maximum length. */
					__( 'The code prefix must be %1$d to %2$d letters or digits (A-Z, 0-9), starting with a letter. The previous prefix was kept.', 'product-qrcode-barcode-generator' ),
					CodeGenerator::PREFIX_MIN,
					CodeGenerator::PREFIX_MAX
				)
			);
		}

		return $prefix;
	}

	/**
	 * The warning shown when barcodes are on and the prefix makes new codes' barcodes too wide
	 * for the A4 label sheets (longer than the default prefix); '' otherwise.
	 */
	public static function prefix_barcode_warning(): string {
		if ( ! self::is_barcode_enabled() || strlen( self::get_code_prefix() ) <= strlen( CodeGenerator::DEFAULT_PREFIX ) ) {
			return '';
		}

		return sprintf(
			/* translators: %d: number of characters. */
			__( 'With barcodes on, a code prefix longer than %d characters makes the barcode too wide for the A4 label sheets. The QR code still prints.', 'product-qrcode-barcode-generator' ),
			strlen( CodeGenerator::DEFAULT_PREFIX )
		);
	}

	/**
	 * Validates a receipt text setting (RECEIPT_TEXTS): tags removed, each line trimmed,
	 * empty lines dropped, then the character and line limits. '' clears it.
	 *
	 * @param string $key   Key of RECEIPT_TEXTS.
	 * @param mixed  $value Submitted value.
	 * @return string|WP_Error
	 */
	public static function validate_receipt_text( string $key, $value ) {
		if ( ! isset( self::RECEIPT_TEXTS[ $key ] ) ) {
			return new WP_Error( 'pqbg_invalid_receipt_text', __( 'Unknown receipt setting.', 'product-qrcode-barcode-generator' ) );
		}

		list( $max, $max_lines ) = self::RECEIPT_TEXTS[ $key ];

		$text  = is_string( $value ) ? sanitize_textarea_field( $value ) : '';
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", str_replace( "\r", '', $text ) ) ), static fn( $line ) => '' !== $line ) );
		$text  = implode( "\n", $lines );

		if ( ! is_string( $value ) || count( $lines ) > $max_lines || mb_strlen( $text ) > $max ) {
			return new WP_Error(
				'pqbg_invalid_' . $key,
				1 === $max_lines
					/* translators: %d: maximum number of characters. */
					? sprintf( __( 'The shop name can be at most %d characters on one line. The previous value was kept.', 'product-qrcode-barcode-generator' ), $max )
					/* translators: 1: maximum number of lines, 2: maximum number of characters. */
					: sprintf( __( 'Receipt address and footer text can be at most %1$d lines and %2$d characters. The previous value was kept.', 'product-qrcode-barcode-generator' ), $max_lines, $max )
			);
		}

		return $text;
	}

	/**
	 * Validates the receipt phone number: digits, spaces, + - ( ), at least one digit. '' clears it.
	 *
	 * @param mixed $value Submitted value.
	 * @return string|WP_Error
	 */
	public static function validate_phone( $value ) {
		$phone = is_string( $value ) ? trim( (string) preg_replace( '/ {2,}/', ' ', $value ) ) : null;

		if ( '' === $phone ) {
			return '';
		}

		if ( null === $phone || strlen( $phone ) > self::PHONE_MAX || 1 !== preg_match( '/^[0-9+()\- ]+$/D', $phone ) || 1 !== preg_match( '/[0-9]/', $phone ) ) {
			/* translators: %d: maximum number of characters. */
			return new WP_Error( 'pqbg_invalid_receipt_phone', sprintf( __( 'The phone number can contain digits, spaces and + - ( ), at most %d characters. The previous value was kept.', 'product-qrcode-barcode-generator' ), self::PHONE_MAX ) );
		}

		return $phone;
	}

	/**
	 * Validates a GSTIN: spaces removed, uppercased, the format and the check character. '' clears it.
	 *
	 * @param mixed $value Submitted value.
	 * @return string|WP_Error
	 */
	public static function validate_gstin( $value ) {
		$gstin = is_string( $value ) ? strtoupper( (string) preg_replace( '/\s+/', '', $value ) ) : null;

		if ( '' === $gstin ) {
			return '';
		}

		if ( null === $gstin || 1 !== preg_match( self::GSTIN_PATTERN, $gstin ) || ! self::gstin_check_ok( $gstin ) ) {
			return new WP_Error( 'pqbg_invalid_receipt_gstin', __( 'This is not a valid GSTIN (15 characters; the last one is a check character). The previous value was kept.', 'product-qrcode-barcode-generator' ) );
		}

		return $gstin;
	}

	/**
	 * Whether a GSTIN's 15th character is its check character (base 36, weights 1 and 2 alternating).
	 *
	 * @param string $gstin 15 characters matching GSTIN_PATTERN.
	 */
	public static function gstin_check_ok( string $gstin ): bool {
		$chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$sum   = 0;

		for ( $i = 0; $i < 14; $i++ ) {
			$product = (int) strpos( $chars, $gstin[ $i ] ) * ( 0 === $i % 2 ? 1 : 2 );
			$sum    += intdiv( $product, 36 ) + $product % 36;
		}

		return $chars[ ( 36 - $sum % 36 ) % 36 ] === $gstin[14];
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

		if ( array_key_exists( 'code_prefix', $input ) ) {
			$prefix = self::validate_code_prefix( $input['code_prefix'] );

			if ( is_wp_error( $prefix ) ) {
				if ( function_exists( 'add_settings_error' ) ) {
					add_settings_error( Plugin::SETTINGS_OPTION, $prefix->get_error_code(), $prefix->get_error_message() );
				}
			} else {
				$output['code_prefix'] = $prefix;
			}
		}

		// Receipts and the UPI payment QR (Phase 16): each value validated on its own; an invalid one keeps the previous value.
		$validators = array(
			'receipt_phone'  => array( __CLASS__, 'validate_phone' ),
			'receipt_gstin'  => array( __CLASS__, 'validate_gstin' ),
			'upi_id'         => array( UpiPayment::class, 'validate_id' ),
			'upi_payee_name' => array( UpiPayment::class, 'validate_payee' ),
		);

		foreach ( array_keys( self::RECEIPT_TEXTS ) as $key ) {
			$validators[ $key ] = static fn( $value ) => self::validate_receipt_text( $key, $value );
		}

		foreach ( $validators as $key => $validate ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$valid = call_user_func( $validate, $input[ $key ] );

			if ( is_wp_error( $valid ) ) {
				if ( function_exists( 'add_settings_error' ) ) {
					add_settings_error( Plugin::SETTINGS_OPTION, $valid->get_error_code(), $valid->get_error_message() );
				}
			} else {
				$output[ $key ] = $valid;
			}
		}

		if ( array_key_exists( 'receipt_show_seller', $input ) ) {
			$output['receipt_show_seller'] = in_array( $input['receipt_show_seller'], array( true, 1, '1' ), true );
		}

		if ( array_key_exists( 'receipt_paper', $input ) && is_string( $input['receipt_paper'] ) && in_array( $input['receipt_paper'], Receipt::PAPERS, true ) ) {
			$output['receipt_paper'] = $input['receipt_paper'];
		}

		// Checkboxes send nothing when unticked, so the form also sends a marker that the field was on it.
		if ( array_key_exists( 'payment_methods_present', $input ) ) {
			$methods = PaymentMethods::clean( isset( $input['payment_methods'] ) && is_array( $input['payment_methods'] ) ? $input['payment_methods'] : array() );

			if ( array() === $methods ) {
				if ( function_exists( 'add_settings_error' ) ) {
					add_settings_error( Plugin::SETTINGS_OPTION, 'pqbg_no_payment_method', __( 'At least one payment method must stay enabled. The previous choice was kept.', 'product-qrcode-barcode-generator' ) );
				}
			} else {
				$output['payment_methods'] = $methods;
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
