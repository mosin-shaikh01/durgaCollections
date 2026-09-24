<?php
/**
 * Generates random product codes in the form DC-XXXX-XXXX-XXXX.
 *
 * Every character comes from random_int() (a CSPRNG) indexing into ALPHABET.
 * Nothing about the product, the time or the request goes into the code, so
 * the code can't be predicted from product data.
 *
 * ALPHABET has 31 symbols. It leaves out 0/O, 1/I/L and all lowercase
 * letters, so a code stays readable when printed or typed by hand.
 * 12 symbols give 31^12 ≈ 7.9e17 codes (about 59 bits).
 *
 * This class only produces a free code string. It never writes to the
 * database; ProductCodeService saves the code through CodeRepository.
 *
 * @package Durga\ProductCodes
 */

namespace Durga\ProductCodes;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Random, collision-checked product-code strings.
 */
final class CodeGenerator {

	const PREFIX = 'DC';

	/** Unambiguous uppercase symbols: A-Z without I, L, O; 2-9 (no 0, 1). */
	const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

	const GROUPS       = 3;
	const GROUP_LENGTH = 4;

	/**
	 * Exact format. The character class matches ALPHABET exactly: A-H, J, K, M, N, P-Z, 2-9.
	 * The D modifier stops "$" from also matching before a trailing newline.
	 */
	const FORMAT_PATTERN = '/^DC(-[A-HJKMNP-Z2-9]{4}){3}$/D';

	/**
	 * How many candidates to try before giving up.
	 *
	 * Even with a million codes stored, one random candidate collides with
	 * probability about 1.3e-12. Ten collisions in a row therefore means the
	 * random source or the data is broken, and failing loudly is safer than
	 * looping.
	 */
	const MAX_ATTEMPTS = 10;

	/**
	 * Random integer source, called as fn( int $min, int $max ): int.
	 *
	 * @var callable
	 */
	private $random_int;

	/**
	 * Whether a code has already been issued, called as fn( string $code ): bool.
	 *
	 * @var callable
	 */
	private $is_taken;

	/**
	 * Both arguments exist so tests can force collisions. Production code
	 * passes nothing and gets random_int() and CodeRepository::code_exists().
	 *
	 * @param callable|null $random_int Random integer source. Defaults to random_int().
	 * @param callable|null $is_taken   Issued-code check. Defaults to CodeRepository::code_exists().
	 */
	public function __construct( ?callable $random_int = null, ?callable $is_taken = null ) {
		$this->random_int = $random_int ?? 'random_int';
		$this->is_taken   = $is_taken ?? array( CodeRepository::class, 'code_exists' );
	}

	/**
	 * Whether a string is exactly a well-formed product code (case-sensitive, no whitespace).
	 *
	 * @param string $code Code to check.
	 */
	public static function is_valid_format( string $code ): bool {
		return 1 === preg_match( self::FORMAT_PATTERN, $code );
	}

	/**
	 * Returns one random code. It does not check whether the code is already taken.
	 *
	 * @throws \Exception When the random source fails or returns an out-of-range value.
	 */
	public function generate(): string {
		$max    = strlen( self::ALPHABET ) - 1;
		$groups = array();

		for ( $g = 0; $g < self::GROUPS; $g++ ) {
			$group = '';

			for ( $i = 0; $i < self::GROUP_LENGTH; $i++ ) {
				$index = ( $this->random_int )( 0, $max );

				if ( ! is_int( $index ) || $index < 0 || $index > $max ) {
					throw new \UnexpectedValueException( 'Random source returned an out-of-range value.' );
				}

				$group .= self::ALPHABET[ $index ];
			}

			$groups[] = $group;
		}

		$code = self::PREFIX . '-' . implode( '-', $groups );

		if ( ! self::is_valid_format( $code ) ) {
			throw new \UnexpectedValueException( 'Generated code failed format validation.' );
		}

		return $code;
	}

	/**
	 * Returns a code that has never been issued, retrying on collision up to MAX_ATTEMPTS times.
	 *
	 * The check is advisory: a concurrent request can still take the same
	 * code before it is saved. The UNIQUE(code) index catches that, and
	 * ProductCodeService retries.
	 *
	 * @return string|WP_Error
	 */
	public function generate_unique() {
		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			try {
				$code = $this->generate();
			} catch ( \Exception $e ) {
				return new WP_Error( 'dpc_random_unavailable', __( 'A product code could not be generated.', 'durga-product-codes' ) );
			}

			if ( ! ( $this->is_taken )( $code ) ) {
				return $code;
			}
		}

		return new WP_Error( 'dpc_code_generation_failed', __( 'A unique product code could not be generated.', 'durga-product-codes' ) );
	}
}
