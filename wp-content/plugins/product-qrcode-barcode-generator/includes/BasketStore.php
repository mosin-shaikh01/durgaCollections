<?php
/**
 * The seller's open basket (Phase 17, decisions D8–D10): several items collected on
 * the scan pages before they are sold together as one sale.
 *
 * Stored server-side in user meta, one basket per seller (the logged-in user), so it
 * is the same basket on every phone the seller uses:
 *   pqbg_basket     rev (int), request_id (UUID v4), updated (Unix time),
 *                   lines = list of { code, quantity, price (shown when added), added }
 *   pqbg_basket_at  the updated time on its own, for the Health check's count
 * Only codes and quantities are kept; name, price and stock are always read fresh.
 * Stock is not reserved.
 *
 * Every change is a read-modify-write under a per-seller named lock and sets a new
 * rev and a new request_id, so a confirm form made before a change cannot sell the
 * changed basket. Changes to existing lines (quantity, remove, clear) also need the
 * rev the seller saw; adding does not (adding from two screens is harmless).
 *
 * Expiry: TTL seconds after the last change the basket reads as empty. GET requests
 * never write; the next change overwrites it. A basket whose request_id already has a
 * completed or voided sale (the process died after selling, before clearing) also
 * reads as empty. No cron (D10): uninstall.php removes both keys, and WordPress removes
 * them with the user.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Open basket storage.
 */
final class BasketStore {

	const META    = 'pqbg_basket';
	const META_AT = 'pqbg_basket_at';

	/** Seconds after the last change before an open basket reads as empty (D9). */
	const TTL = 7200;

	/** Different items in one basket (D7). */
	const MAX_LINES = 30;

	/** Seconds to wait for another change of the same basket. */
	const LOCK_TIMEOUT = 5;

	/**
	 * The seller's basket as it stands (never writes).
	 *
	 * @param int      $user_id Seller.
	 * @param int|null $now     Unix time (tests).
	 * @return array{rev: int, request_id: string, updated: int, lines: array<int, array{code: string, quantity: int, price: string, added: int}>}
	 */
	public static function get( int $user_id, ?int $now = null ): array {
		wp_cache_delete( $user_id, 'user_meta' );

		$basket = self::normalize( get_user_meta( $user_id, self::META, true ) );
		$now    = null === $now ? time() : $now;

		if ( array() === $basket['lines'] ) {
			return $basket;
		}

		if ( $now - $basket['updated'] > self::TTL ) {
			$basket['lines'] = array();
			return $basket;
		}

		$sold = SaleRepository::find_by_request_id( $basket['request_id'] );

		if ( null !== $sold && in_array( $sold['status'], array( SaleRepository::STATUS_COMPLETED, SaleRepository::STATUS_VOIDED ), true ) ) {
			$basket['lines'] = array();
		}

		return $basket;
	}

	/**
	 * Adds a quantity of an item, or raises the quantity of its line (D4).
	 *
	 * @param int      $user_id  Seller.
	 * @param string   $code     Valid product code.
	 * @param int      $quantity Quantity to add.
	 * @param string   $price    Price the seller saw (normalised).
	 * @param callable $cap      Receives the current lines and returns the highest allowed quantity for this code.
	 * @return array{quantity: int}|WP_Error The line's new quantity.
	 */
	public static function add( int $user_id, string $code, int $quantity, string $price, callable $cap ) {
		return self::change(
			$user_id,
			null,
			static function ( array $lines ) use ( $code, $quantity, $price, $cap ) {
				$index = self::index( $lines, $code );

				if ( null === $index && count( $lines ) >= self::MAX_LINES ) {
					/* translators: %d: maximum number of different items. */
					return new WP_Error( 'pqbg_basket_full', sprintf( __( 'A basket can hold %d different items. Confirm this sale first.', 'product-qrcode-barcode-generator' ), self::MAX_LINES ) );
				}

				$new = ( null === $index ? 0 : $lines[ $index ]['quantity'] ) + $quantity;
				$max = (int) $cap( $lines );

				if ( $new > $max ) {
					return $max <= 0
						? new WP_Error( 'pqbg_out_of_stock', __( 'Out of stock – cannot be sold.', 'product-qrcode-barcode-generator' ) )
						/* translators: %s: stock quantity. */
						: new WP_Error( 'pqbg_insufficient_stock', sprintf( __( 'Only %s in stock.', 'product-qrcode-barcode-generator' ), number_format_i18n( $max ) ) );
				}

				if ( null === $index ) {
					$lines[] = array(
						'code'     => $code,
						'quantity' => $new,
						'price'    => $price,
						'added'    => time(),
					);
				} else {
					$lines[ $index ]['quantity'] = $new;
				}

				return array( $lines, array( 'quantity' => $new ) );
			}
		);
	}

	/**
	 * Sets a line's quantity.
	 *
	 * @param int      $user_id  Seller.
	 * @param int      $rev      Revision the seller saw.
	 * @param string   $code     Line's code.
	 * @param int      $quantity New quantity (at least 1).
	 * @param callable $cap      Receives the current lines and returns the highest allowed quantity for this code.
	 * @return array{quantity: int}|WP_Error
	 */
	public static function set_quantity( int $user_id, int $rev, string $code, int $quantity, callable $cap ) {
		return self::change(
			$user_id,
			$rev,
			static function ( array $lines ) use ( $code, $quantity, $cap ) {
				$index = self::index( $lines, $code );

				if ( null === $index ) {
					return self::changed_error();
				}

				$max = (int) $cap( $lines );

				if ( $quantity > $max ) {
					return $max <= 0
						? new WP_Error( 'pqbg_out_of_stock', __( 'Out of stock – cannot be sold.', 'product-qrcode-barcode-generator' ) )
						/* translators: %s: stock quantity. */
						: new WP_Error( 'pqbg_insufficient_stock', sprintf( __( 'Only %s in stock.', 'product-qrcode-barcode-generator' ), number_format_i18n( $max ) ) );
				}

				$lines[ $index ]['quantity'] = $quantity;

				return array( $lines, array( 'quantity' => $quantity ) );
			}
		);
	}

	/**
	 * Removes a line.
	 *
	 * @param int    $user_id Seller.
	 * @param int    $rev     Revision the seller saw.
	 * @param string $code    Line's code.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function remove( int $user_id, int $rev, string $code ) {
		return self::change(
			$user_id,
			$rev,
			static function ( array $lines ) use ( $code ) {
				$index = self::index( $lines, $code );

				if ( null === $index ) {
					return self::changed_error();
				}

				array_splice( $lines, $index, 1 );

				return array( $lines, array() );
			}
		);
	}

	/**
	 * Empties the basket.
	 *
	 * @param int $user_id Seller.
	 * @param int $rev     Revision the seller saw.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function clear( int $user_id, int $rev ) {
		return self::change( $user_id, $rev, static fn( array $lines ) => array( array(), array() ) );
	}

	/**
	 * A new request_id (and rev) for the same lines: after a confirm that sold nothing (D13).
	 *
	 * @param int $user_id Seller.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function renew( int $user_id ) {
		return self::change( $user_id, null, static fn( array $lines ) => array( $lines, array() ) );
	}

	/**
	 * Empties the basket after its sale, if it is still the basket that was sold.
	 *
	 * @param int    $user_id    Seller.
	 * @param string $request_id The sold basket's request ID.
	 */
	public static function clear_sold( int $user_id, string $request_id ): void {
		self::change( $user_id, null, static fn( array $lines, array $basket ) => $basket['request_id'] === $request_id ? array( array(), array() ) : self::changed_error() );
	}

	/**
	 * Summary for the bar on the product screen: lines, items and the total at current prices.
	 *
	 * @param array<string, mixed> $basket From get().
	 * @return array{lines: int, items: int}
	 */
	public static function counts( array $basket ): array {
		return array(
			'lines' => count( $basket['lines'] ),
			'items' => (int) array_sum( array_column( $basket['lines'], 'quantity' ) ),
		);
	}

	/**
	 * Removes the stored basket of every user (uninstall).
	 */
	public static function delete_all(): void {
		delete_metadata( 'user', 0, self::META, '', true );
		delete_metadata( 'user', 0, self::META_AT, '', true );
	}

	/**
	 * Read-modify-write under the seller's lock.
	 *
	 * @param int      $user_id Seller.
	 * @param int|null $rev     Revision the seller saw, or null when any revision will do.
	 * @param callable $apply   Receives (lines, basket); returns [new lines, result] or a WP_Error.
	 * @return array<string, mixed>|WP_Error The result, plus rev and request_id of the new basket.
	 */
	private static function change( int $user_id, ?int $rev, callable $apply ) {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return new WP_Error( 'pqbg_forbidden', __( 'You do not have permission to sell.', 'product-qrcode-barcode-generator' ) );
		}

		$lock = self::lock_name( $user_id );

		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT ) ) ) {
			return new WP_Error( 'pqbg_busy', __( 'The basket is being changed on another screen. Try again.', 'product-qrcode-barcode-generator' ) );
		}

		try {
			$basket = self::get( $user_id );

			if ( null !== $rev && $rev !== $basket['rev'] ) {
				return self::changed_error();
			}

			$out = $apply( $basket['lines'], $basket );

			if ( is_wp_error( $out ) ) {
				return $out;
			}

			$now   = time();
			$saved = array(
				'rev'        => $basket['rev'] + 1,
				'request_id' => wp_generate_uuid4(),
				'updated'    => $now,
				'lines'      => array_values( $out[0] ),
			);

			if ( array() === $saved['lines'] ) {
				delete_user_meta( $user_id, self::META_AT );
			} else {
				update_user_meta( $user_id, self::META_AT, $now );
			}

			// The rev must survive an empty basket, so the meta row stays (an empty basket is a few bytes).
			update_user_meta( $user_id, self::META, $saved );
			wp_cache_delete( $user_id, 'user_meta' );

			return array_merge(
				$out[1],
				array(
					'rev'        => $saved['rev'],
					'request_id' => $saved['request_id'],
				)
			);
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * A stored value as a well-formed basket (anything malformed is dropped).
	 *
	 * @param mixed $stored Stored meta value.
	 * @return array{rev: int, request_id: string, updated: int, lines: array<int, array{code: string, quantity: int, price: string, added: int}>}
	 */
	private static function normalize( $stored ): array {
		$stored = is_array( $stored ) ? $stored : array();
		$lines  = array();
		$seen   = array();

		foreach ( is_array( $stored['lines'] ?? null ) ? $stored['lines'] : array() as $line ) {
			$code     = is_array( $line ) && is_string( $line['code'] ?? null ) ? $line['code'] : '';
			$quantity = is_array( $line ) && is_int( $line['quantity'] ?? null ) ? $line['quantity'] : 0;

			if ( ! CodeGenerator::is_valid_format( $code ) || $quantity < 1 || $quantity > SaleService::MAX_QUANTITY || isset( $seen[ $code ] ) || count( $lines ) >= self::MAX_LINES ) {
				continue;
			}

			$seen[ $code ] = true;
			$lines[]       = array(
				'code'     => $code,
				'quantity' => $quantity,
				'price'    => is_string( $line['price'] ?? null ) ? $line['price'] : '',
				'added'    => is_int( $line['added'] ?? null ) ? $line['added'] : 0,
			);
		}

		$request = is_string( $stored['request_id'] ?? null ) && wp_is_uuid( $stored['request_id'], 4 ) ? $stored['request_id'] : '';

		return array(
			'rev'        => is_int( $stored['rev'] ?? null ) && $stored['rev'] >= 0 ? $stored['rev'] : 0,
			'request_id' => $request,
			'updated'    => is_int( $stored['updated'] ?? null ) ? $stored['updated'] : 0,
			'lines'      => '' === $request ? array() : $lines,
		);
	}

	/**
	 * Position of a code's line, or null.
	 *
	 * @param array<int, array<string, mixed>> $lines Lines.
	 * @param string                           $code  Code.
	 */
	private static function index( array $lines, string $code ): ?int {
		foreach ( $lines as $i => $line ) {
			if ( $line['code'] === $code ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * "Changed on another screen".
	 */
	public static function changed_error(): WP_Error {
		return new WP_Error( 'pqbg_basket_changed', __( 'The basket changed on another screen. Check it and try again.', 'product-qrcode-barcode-generator' ) );
	}

	/**
	 * Lock name for a seller's basket (server-wide names carry a hash of the site, as StockLock's do).
	 *
	 * @param int $user_id Seller.
	 */
	private static function lock_name( int $user_id ): string {
		global $wpdb;

		return 'pqbg:' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 12 ) . ':basket:' . $user_id;
	}
}
