<?php
/**
 * Basket sales (Phase 17, decisions D11–D20): several different items sold as ONE
 * sale, all or nothing, with the Phase 7 stock journal and locks.
 *
 * Confirm (confirm()):
 *   1. permission, request ID, database support (D16), no open transaction;
 *   2. idempotency first: a row with this request ID returns its basket's outcome
 *      (waiting for the basket's locks while it may still be running, D15);
 *   3. payment method; every line resolved with SaleService::check_item();
 *   4. the StockLock of every distinct stock holder, in ascending ID order (no
 *      deadlock: single sales, undo and void take one lock; baskets always take them
 *      in the same order);
 *   5. under the locks: the request ID again, stale pending and held rows of each
 *      holder recovered, fresh reads, every line checked (sellable, price as signed,
 *      the quantities per holder within the stock). Any failure: nothing is written,
 *      and the error names the line (data 'line', 0-based);
 *   6. the journal: the first line pending with the basket's request ID and
 *      basket_id = its own ID, then the other lines pending (fresh request IDs);
 *   7. each line pending → held in the statement that lowers its stock
 *      (SaleService::change_stock()), then a fresh stock read: negative means an online
 *      order won; that or an error rolls the whole basket back (D13);
 *   8. all held lines → completed in ONE statement (SaleRepository::complete_basket());
 *   9. stock snapshots, locks released, low-stock notifications per holder.
 * No DB transaction anywhere.
 *
 * Rollback (D13): the failing line is compensated with its own reason (sold_online or
 * error), every other held line with basket_rollback, pending lines are marked failed.
 * Failed rows stay in the journal (the existing audit rule); no sale is recorded.
 *
 * Undo (D19, the seller, own basket, 10 minutes, once) and void (D20, pqbg_void_sale)
 * work on the whole basket: every line is checked under all locks before anything
 * changes, then each line is put back (one statement each). A void without restock is
 * one statement. A DB error in the middle can leave a partly voided basket; the Health
 * check lists it and a manager's void finishes it (only still-completed lines are voided).
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WC_Product;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Basket confirm, outcome, undo and void.
 */
final class BasketService {

	/** Oldest servers that can hold several named locks at once (D16). */
	const MIN_MYSQL   = '5.7.5';
	const MIN_MARIADB = '10.0.2';

	/** @var bool|null Whether the database server supports baskets (checked once per request). */
	private static ?bool $supported = null;

	/**
	 * Whether the database server can hold several GET_LOCKs at once (MySQL 5.7.5+,
	 * MariaDB 10.0.2+). Before that, a second GET_LOCK silently released the first.
	 */
	public static function db_supported(): bool {
		if ( null === self::$supported ) {
			self::$supported = self::version_supported( self::server_version() );
		}

		return self::$supported;
	}

	/**
	 * The database server's version string (SELECT VERSION()).
	 */
	public static function server_version(): string {
		global $wpdb;

		return (string) $wpdb->get_var( 'SELECT VERSION()' );
	}

	/**
	 * Whether a version string (e.g. "10.4.32-MariaDB", "8.0.36") supports baskets.
	 *
	 * @param string $version Server version.
	 */
	public static function version_supported( string $version ): bool {
		$version = preg_replace( '/^5\.5\.5-/', '', trim( $version ) ); // Some MariaDB builds report a 5.5.5- prefix.

		if ( 1 !== preg_match( '/^(\d+\.\d+\.\d+)/', (string) $version, $m ) ) {
			return false;
		}

		return version_compare( $m[1], false !== stripos( (string) $version, 'mariadb' ) ? self::MIN_MARIADB : self::MIN_MYSQL, '>=' );
	}

	/**
	 * Sells a basket.
	 *
	 * @param array{seller_id: int, request_id: string, payment_method: string, lines: array<int, array{code: string, quantity: int, price: string}>} $args
	 *        lines: the basket as the seller confirmed it, price = the price shown (signed in the form).
	 * @return array{status: string, basket_id: int, line: int, failure: string}|WP_Error
	 *         status completed or failed (line = the failing line, failure = its failure code); a WP_Error
	 *         (data 'line' when a line is to blame) when nothing was written.
	 */
	public static function confirm( array $args ) {
		$seller  = (int) $args['seller_id'];
		$request = (string) $args['request_id'];
		$lines   = array_values( (array) $args['lines'] );

		if ( ! Permissions::can_sell( $seller ) ) {
			return self::error( 'pqbg_forbidden', __( 'You do not have permission to sell.', 'product-qrcode-barcode-generator' ) );
		}

		if ( ! wp_is_uuid( $request, 4 ) ) {
			return self::error( 'pqbg_bad_request', __( 'This form is not valid. Check the basket and confirm again.', 'product-qrcode-barcode-generator' ) );
		}

		if ( ! self::db_supported() ) {
			return self::unsupported();
		}

		if ( SaleService::in_transaction() ) {
			return self::error( 'pqbg_in_transaction', __( 'The sale could not be completed. Stock was not changed.', 'product-qrcode-barcode-generator' ) );
		}

		$existing = SaleRepository::find_by_request_id( $request );

		if ( null !== $existing ) {
			return self::outcome( $existing, $seller );
		}

		if ( array() === $lines ) {
			return self::error( 'pqbg_basket_empty', __( 'The basket is empty.', 'product-qrcode-barcode-generator' ) );
		}

		if ( count( $lines ) > BasketStore::MAX_LINES ) {
			/* translators: %d: maximum number of different items. */
			return self::error( 'pqbg_basket_full', sprintf( __( 'A basket can hold %d different items. Confirm this sale first.', 'product-qrcode-barcode-generator' ), BasketStore::MAX_LINES ) );
		}

		$method = SaleService::check_payment_method( $args['payment_method'] ?? null );

		if ( is_wp_error( $method ) ) {
			return $method;
		}

		$holders = array();

		foreach ( $lines as $i => $line ) {
			$quantity = (int) $line['quantity'];

			if ( $quantity < 1 || $quantity > SaleService::MAX_QUANTITY ) {
				return self::line_error( $i, $line, self::error( 'pqbg_invalid_quantity', __( 'Choose a valid quantity.', 'product-qrcode-barcode-generator' ) ) );
			}

			$item = SaleService::check_item( CodeRepository::find_by_code( (string) $line['code'] ) );

			if ( is_wp_error( $item ) ) {
				return self::line_error( $i, $line, $item );
			}

			$holders[ $item['holder']->get_id() ] = $i;
		}

		$locked = self::lock_all( array_keys( $holders ) );

		if ( 0 !== $locked ) {
			return self::line_error( $holders[ $locked ], $lines[ $holders[ $locked ] ], self::error( 'pqbg_busy', __( 'Someone else is selling this item right now. Try again.', 'product-qrcode-barcode-generator' ) ) );
		}

		try {
			$result = self::confirm_locked( $lines, $request, $seller, (string) $method, array_keys( $holders ) );
		} finally {
			self::release_all( array_keys( $holders ) );
		}

		// After the locks: an e-mail must not hold up the next sale of these items.
		if ( is_array( $result ) && SaleRepository::STATUS_COMPLETED === $result['status'] ) {
			foreach ( array_keys( $holders ) as $holder_id ) {
				SaleService::notify( (int) $holder_id );
			}
		}

		return $result;
	}

	/**
	 * The part of confirm() that runs under every holder's lock.
	 *
	 * @param array<int, array<string, mixed>> $lines   Lines.
	 * @param string                           $request Request ID.
	 * @param int                              $seller  Seller.
	 * @param string                           $method  Payment method.
	 * @param int[]                            $locked  The holders whose locks are held.
	 * @return array{status: string, basket_id: int, line: int, failure: string}|WP_Error
	 */
	private static function confirm_locked( array $lines, string $request, int $seller, string $method, array $locked ) {
		// A concurrent duplicate of this request may have finished while we waited for the locks.
		$existing = SaleRepository::find_by_request_id( $request );

		if ( null !== $existing ) {
			return self::outcome( $existing, $seller );
		}

		$items = array();
		$need  = array();
		$stock = array_fill_keys( array_map( 'intval', $locked ), null );

		foreach ( array_keys( $stock ) as $holder_id ) {
			SaleRepository::recover_stale( $holder_id );
			SaleService::recover_held( $holder_id );
		}

		foreach ( $lines as $i => $line ) {
			$row  = CodeRepository::find_by_code( (string) $line['code'] );
			$item = null === $row ? null : SaleService::check_item( $row );

			if ( null !== $item && ! is_wp_error( $item ) ) {
				// Fresh objects: the product, its parent and the holder may have changed while we waited.
				SaleService::forget( array( (int) $row['product_id'], $item['holder']->get_id(), $item['parent'] ? $item['parent']->get_id() : 0 ) );
				$item = SaleService::check_item( $row );
			}

			if ( null === $item || is_wp_error( $item ) ) {
				return self::line_error( $i, $line, $item ?? self::error( 'pqbg_code_not_found', __( 'Code not found.', 'product-qrcode-barcode-generator' ) ) );
			}

			if ( ! array_key_exists( $item['holder']->get_id(), $stock ) ) {
				return self::line_error( $i, $line, self::error( 'pqbg_stock_changed', __( 'Stock settings changed since you opened this page. Check the item and confirm again.', 'product-qrcode-barcode-generator' ) ) );
			}

			$price = (string) $item['product']->get_price();

			if ( SaleService::normalize_price( (string) $line['price'] ) !== SaleService::normalize_price( $price ) ) {
				return self::line_error( $i, $line, self::error( 'pqbg_price_changed', __( 'The price changed since you opened this page. Check the new price and confirm again.', 'product-qrcode-barcode-generator' ) ) );
			}

			$holder_id           = $item['holder']->get_id();
			$need[ $holder_id ]  = ( $need[ $holder_id ] ?? 0 ) + (int) $line['quantity'];
			$items[ $i ]         = $item + array( 'row' => $row, 'price' => $price );
		}

		foreach ( $items as $i => $item ) {
			$holder_id = $item['holder']->get_id();

			if ( null === $stock[ $holder_id ] ) {
				$read                = SaleRepository::read_stock( $holder_id );
				$stock[ $holder_id ] = null === $read ? 0 : $read;
			}

			if ( $stock[ $holder_id ] <= 0 ) {
				return self::line_error( $i, $lines[ $i ], self::error( 'pqbg_out_of_stock', __( 'Out of stock – cannot be sold.', 'product-qrcode-barcode-generator' ) ) );
			}

			if ( $need[ $holder_id ] > $stock[ $holder_id ] ) {
				/* translators: %s: current stock quantity. */
				return self::line_error( $i, $lines[ $i ], self::error( 'pqbg_insufficient_stock', sprintf( __( 'Only %s in stock.', 'product-qrcode-barcode-generator' ), number_format_i18n( $stock[ $holder_id ] ) ) ) );
			}
		}

		// Every check passed: write the journal.
		$ids       = array();
		$basket_id = 0;

		foreach ( $items as $i => $item ) {
			$product  = $item['product'];
			$parent   = $item['parent'];
			$quantity = (int) $lines[ $i ]['quantity'];
			$regular  = (string) $product->get_regular_price();
			$sku      = (string) $product->get_sku();
			$attrs    = $parent ? SaleService::attributes( $product ) : array();
			$data     = array(
				'request_id'      => 0 === $i ? $request : wp_generate_uuid4(),
				'code_id'         => (int) $item['row']['id'],
				'product_id'      => $parent ? $parent->get_id() : $product->get_id(),
				'variation_id'    => $parent ? $product->get_id() : 0,
				'seller_id'       => $seller,
				'quantity'        => $quantity,
				'unit_price'      => $item['price'],
				'regular_price'   => '' === $regular ? null : $regular,
				'line_total'      => SaleService::line_total( $item['price'], $quantity ),
				'currency'        => get_woocommerce_currency(),
				'product_name'    => $parent ? $parent->get_name() : $product->get_name(),
				'sku'             => '' === $sku ? null : $sku,
				'attributes_json' => array() === $attrs ? null : wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE ),
				'stock_holder_id' => $item['holder']->get_id(),
				'payment_method'  => $method,
				'unit_cost'       => CostPrice::effective( $product, $parent ),
				'seller_name'     => SaleService::seller_name( $seller ),
			);

			if ( $basket_id > 0 ) {
				$data['basket_id'] = $basket_id;
			}

			$id = SaleRepository::insert_pending( $data );

			if ( is_wp_error( $id ) ) {
				if ( 0 === $i ) {
					$existing = SaleRepository::find_by_request_id( $request );
					return null !== $existing ? self::outcome( $existing, $seller ) : $id;
				}

				return self::rollback( $ids, $basket_id, $i, SaleRepository::FAILURE_ERROR );
			}

			if ( 0 === $i ) {
				$basket_id = $id;

				if ( ! SaleRepository::set_basket_id( $id ) ) {
					return self::rollback( array( 0 => $id ), $basket_id, 0, SaleRepository::FAILURE_ERROR );
				}
			}

			$ids[ $i ] = $id;
		}

		// Lower the stock, line by line: pending → held in the same statement.
		$before = array();
		$after  = array();

		foreach ( $items as $i => $item ) {
			$holder    = $item['holder'];
			$holder_id = $holder->get_id();
			$quantity  = (int) $lines[ $i ]['quantity'];
			$level     = SaleRepository::read_stock( $holder_id );
			$change    = SaleService::change_stock( $holder, $quantity, 'decrease', $ids[ $i ], SaleRepository::STATUS_PENDING, array( 'status' => SaleRepository::STATUS_HELD ) );
			$row       = SaleRepository::find( $ids[ $i ] );

			if ( null !== $row && SaleRepository::STATUS_PENDING === $row['status'] ) {
				// Our statement was not used: decide from the stock itself (as SaleService::resolve_pending()).
				$now     = SaleRepository::read_stock( $holder_id );
				$applied = null !== $level && null !== $now && $level - $now >= $quantity;

				if ( ! $applied || ! SaleRepository::transition( $ids[ $i ], SaleRepository::STATUS_PENDING, array( 'status' => SaleRepository::STATUS_HELD ) ) ) {
					return self::rollback( $ids, $basket_id, $i, SaleRepository::FAILURE_ERROR );
				}

				SaleService::log( 'pqbg_stock_marker_missing' );
				$row = SaleRepository::find( $ids[ $i ] );
			}

			if ( null === $row || SaleRepository::STATUS_HELD !== $row['status'] ) {
				return self::rollback( $ids, $basket_id, $i, SaleRepository::FAILURE_ERROR );
			}

			$now = SaleRepository::read_stock( $holder_id );

			if ( null !== $change['error'] || null === $now || $now < 0 ) {
				return self::rollback( $ids, $basket_id, $i, null === $change['error'] && null !== $now ? SaleRepository::FAILURE_SOLD_ONLINE : SaleRepository::FAILURE_ERROR );
			}

			$before[ $i ] = null === $level ? 0 : $level;
			$after[ $i ]  = $now;
		}

		// The whole basket becomes a sale in one statement.
		$done = SaleRepository::complete_basket( $basket_id );

		if ( count( $ids ) !== $done ) {
			SaleService::log( 'pqbg_basket_complete_mismatch', $done . '/' . count( $ids ) );
			return self::rollback( $ids, $basket_id, 0, SaleRepository::FAILURE_ERROR );
		}

		foreach ( $ids as $i => $id ) {
			SaleRepository::set_stock_after( $id, $before[ $i ], $after[ $i ] );
		}

		return array(
			'status'    => SaleRepository::STATUS_COMPLETED,
			'basket_id' => $basket_id,
			'line'      => -1,
			'failure'   => '',
		);
	}

	/**
	 * Takes a basket back after a line failed: every held (or, after a failed final
	 * statement, completed) line gets its stock back, pending lines are marked failed.
	 * Runs under the basket's locks.
	 *
	 * @param array<int, int> $ids       Line index => row ID (the lines written so far).
	 * @param int             $basket_id Basket ID.
	 * @param int             $culprit   Index of the failing line.
	 * @param string          $reason    Its failure code.
	 * @return array{status: string, basket_id: int, line: int, failure: string}|WP_Error
	 */
	private static function rollback( array $ids, int $basket_id, int $culprit, string $reason ) {
		$stuck = false;

		foreach ( array_reverse( $ids, true ) as $i => $id ) {
			$row  = SaleRepository::find( $id );
			$set  = array(
				'status'       => SaleRepository::STATUS_FAILED,
				'failure_code' => $i === $culprit ? $reason : SaleRepository::FAILURE_BASKET,
			);

			if ( null === $row ) {
				continue;
			}

			if ( SaleRepository::STATUS_PENDING === $row['status'] ) {
				SaleRepository::transition( $id, SaleRepository::STATUS_PENDING, $set );
			} elseif ( in_array( $row['status'], array( SaleRepository::STATUS_HELD, SaleRepository::STATUS_COMPLETED ), true ) ) {
				$stuck = ! SaleService::put_back( $row, (string) $row['status'], $set ) || $stuck;
			}
		}

		SaleService::log( 'pqbg_basket_rolled_back_' . $reason );

		if ( $stuck ) {
			// A held line keeps its lowered stock; it is not a sale, and the Health check lists it.
			SaleService::log( 'pqbg_basket_rollback_failed' );
			return self::error( 'pqbg_compensation_failed', __( 'Nothing was sold, but the stock of one item could not be put back. Tell a manager.', 'product-qrcode-barcode-generator' ), array( 'line' => $culprit ) );
		}

		return array(
			'status'    => SaleRepository::STATUS_FAILED,
			'basket_id' => $basket_id,
			'line'      => $culprit,
			'failure'   => $reason,
		);
	}

	/**
	 * Result of an earlier request with the same request ID (D15). While a line may still
	 * be running (pending or held), waits for all the basket's locks; a pending or held
	 * line seen under them belongs to a process that died and is recovered first.
	 *
	 * @param array<string, string> $row    The row with the request ID (the basket's first line).
	 * @param int                   $seller Acting seller.
	 * @return array{status: string, basket_id: int, line: int, failure: string}|WP_Error
	 */
	public static function outcome( array $row, int $seller ) {
		if ( (int) $row['seller_id'] !== $seller ) {
			return self::error( 'pqbg_bad_request', __( 'This form is not valid. Check the basket and confirm again.', 'product-qrcode-barcode-generator' ) );
		}

		$basket_id = (int) $row['id'];
		$lines     = self::lines_or_row( $basket_id, $row );
		$running   = array( SaleRepository::STATUS_PENDING, SaleRepository::STATUS_HELD );

		if ( array() !== array_intersect( array_column( $lines, 'status' ), $running ) ) {
			$holders = array_values( array_unique( array_filter( array_map( 'intval', array_column( $lines, 'stock_holder_id' ) ) ) ) );
			$locked  = self::lock_all( $holders );

			if ( 0 !== $locked ) {
				return self::error( 'pqbg_busy', __( 'Someone else is selling this item right now. Try again.', 'product-qrcode-barcode-generator' ) );
			}

			try {
				foreach ( $holders as $holder_id ) {
					SaleRepository::recover_stale( $holder_id );
					SaleService::recover_held( $holder_id );
				}

				$lines = self::lines_or_row( $basket_id, SaleRepository::find( $basket_id ) ?? $row );
			} finally {
				self::release_all( $holders );
			}
		}

		$statuses = array_column( $lines, 'status' );
		$sold     = array() !== array_intersect( $statuses, array( SaleRepository::STATUS_COMPLETED, SaleRepository::STATUS_VOIDED ) );
		$line     = -1;
		$failure  = '';

		foreach ( $lines as $i => $l ) {
			if ( SaleRepository::STATUS_FAILED === $l['status'] && SaleRepository::FAILURE_BASKET !== $l['failure_code'] ) {
				$line    = $i;
				$failure = (string) $l['failure_code'];
				break;
			}
		}

		return array(
			'status'    => $sold ? SaleRepository::STATUS_COMPLETED : SaleRepository::STATUS_FAILED,
			'basket_id' => $basket_id,
			'line'      => $sold ? -1 : max( 0, $line ),
			'failure'   => $sold ? '' : ( '' === $failure ? SaleRepository::FAILURE_ERROR : $failure ),
		);
	}

	/**
	 * A basket's lines (an empty list when the ID is not a basket's first line).
	 *
	 * @param int $basket_id Basket ID.
	 * @return array<int, array<string, string>>
	 */
	public static function lines( int $basket_id ): array {
		return SaleRepository::basket_lines( $basket_id );
	}

	/**
	 * The lines of the basket a sale row belongs to; a single sale is a basket of one.
	 *
	 * @param array<string, string> $row Sale row.
	 * @return array<int, array<string, string>>
	 */
	public static function transaction_of( array $row ): array {
		if ( empty( $row['basket_id'] ) ) {
			return array( $row );
		}

		$lines = SaleRepository::basket_lines( (int) $row['basket_id'] );

		return array() === $lines ? array( $row ) : $lines;
	}

	/**
	 * The receipt / transaction number of a sale row: its basket ID, or its own ID for a single sale.
	 *
	 * @param array<string, mixed> $row Sale row.
	 */
	public static function number( array $row ): int {
		return empty( $row['basket_id'] ) ? (int) $row['id'] : (int) $row['basket_id'];
	}

	/**
	 * Whether the user may undo this basket now (for showing the Undo button).
	 *
	 * @param array<int, array<string, string>> $lines   The basket's lines.
	 * @param int                               $user_id User.
	 */
	public static function can_undo( array $lines, int $user_id ): bool {
		return Permissions::can_sell( $user_id ) && ! is_wp_error( self::undo_refusal( $lines, $user_id ) );
	}

	/**
	 * Unix time until which a basket can be undone (10 minutes after its sale).
	 *
	 * @param array<int, array<string, string>> $lines The basket's lines.
	 */
	public static function undo_until( array $lines ): int {
		return SaleService::created_ts( $lines[0] ) + SaleService::UNDO_WINDOW;
	}

	/**
	 * Undo (D19): the seller's own completed basket, within 10 minutes, once, as a whole.
	 *
	 * @param int $basket_id Basket ID.
	 * @param int $user_id   Acting user.
	 * @return array{status: string, basket_id: int}|WP_Error
	 */
	public static function undo( int $basket_id, int $user_id ) {
		if ( ! Permissions::can_sell( $user_id ) ) {
			return self::error( 'pqbg_forbidden', __( 'You do not have permission to sell.', 'product-qrcode-barcode-generator' ) );
		}

		$ok = self::undo_refusal( self::lines( $basket_id ), $user_id );

		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		return self::restore_all( $basket_id, $user_id, SaleService::VOID_REASON_UNDO, static fn( array $fresh ) => self::undo_refusal( $fresh, $user_id ) );
	}

	/**
	 * Manager void (D20) of a whole basket: every still-completed line. Needs pqbg_void_sale.
	 *
	 * @param int    $basket_id Basket ID.
	 * @param int    $user_id   Acting user.
	 * @param string $reason    Reason recorded in void_reason.
	 * @param bool   $restock   Whether to put the quantities back into stock.
	 * @return array{status: string, basket_id: int}|WP_Error
	 */
	public static function void( int $basket_id, int $user_id, string $reason, bool $restock = true ) {
		if ( ! Permissions::can_void_sale( $user_id ) ) {
			return self::error( 'pqbg_forbidden', __( 'You do not have permission to void sales.', 'product-qrcode-barcode-generator' ) );
		}

		$reason = '' === trim( $reason ) ? 'void' : sanitize_textarea_field( $reason );
		$check  = static fn( array $lines ) => in_array( SaleRepository::STATUS_COMPLETED, array_column( $lines, 'status' ), true ) ? true : self::error( 'pqbg_not_voidable', __( 'Only a completed sale can be voided.', 'product-qrcode-barcode-generator' ) );
		$ok     = $check( self::lines( $basket_id ) );

		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		if ( ! $restock ) {
			$n = SaleRepository::void_basket( $basket_id, SaleService::void_fields( $user_id, $reason, false ) );

			return $n > 0
				? array(
					'status'    => SaleRepository::STATUS_VOIDED,
					'basket_id' => $basket_id,
				)
				: self::error( 'pqbg_not_voidable', __( 'Only a completed sale can be voided.', 'product-qrcode-barcode-generator' ) );
		}

		return self::restore_all( $basket_id, $user_id, $reason, $check );
	}

	/**
	 * Puts every still-completed line of a basket back into stock and marks it voided,
	 * under all the basket's locks, after checking every line first.
	 *
	 * @param int      $basket_id Basket ID.
	 * @param int      $user_id   Acting user (voided_by).
	 * @param string   $reason    void_reason.
	 * @param callable $recheck   Re-validates the fresh lines under the locks: true or WP_Error.
	 * @return array{status: string, basket_id: int}|WP_Error
	 */
	private static function restore_all( int $basket_id, int $user_id, string $reason, callable $recheck ) {
		if ( SaleService::in_transaction() ) {
			return self::error( 'pqbg_in_transaction', __( 'The sale could not be changed.', 'product-qrcode-barcode-generator' ) );
		}

		if ( ! self::db_supported() ) {
			return self::unsupported();
		}

		$lines   = self::lines( $basket_id );
		$holders = array_values( array_unique( array_map( 'intval', array_column( $lines, 'stock_holder_id' ) ) ) );

		if ( in_array( 0, $holders, true ) ) {
			return self::error( 'pqbg_undo_unavailable', __( 'This sale cannot be undone here.', 'product-qrcode-barcode-generator' ) );
		}

		if ( 0 !== self::lock_all( $holders ) ) {
			return self::error( 'pqbg_busy', __( 'Someone else is selling this item right now. Try again.', 'product-qrcode-barcode-generator' ) );
		}

		try {
			foreach ( $holders as $holder_id ) {
				SaleRepository::recover_stale( $holder_id );
				SaleService::recover_held( $holder_id );
			}

			$fresh = self::lines( $basket_id );
			$ok    = $recheck( $fresh );

			if ( is_wp_error( $ok ) ) {
				return $ok;
			}

			$todo = array_values( array_filter( $fresh, static fn( $l ) => SaleRepository::STATUS_COMPLETED === $l['status'] ) );

			// Check every line before changing anything.
			foreach ( $todo as $line ) {
				$holder_id = (int) $line['stock_holder_id'];
				SaleService::forget( array( $holder_id ) );
				$holder = wc_get_product( $holder_id );

				if ( ! $holder instanceof WC_Product || ! $holder->managing_stock() || (int) $holder->get_stock_managed_by_id() !== $holder_id ) {
					return self::error(
						'pqbg_undo_unavailable',
						/* translators: %s: item name. */
						sprintf( __( 'Stock tracking changed for "%s", so the sale cannot be undone here. Nothing was changed.', 'product-qrcode-barcode-generator' ), SalePresenter::item( $line ) )
					);
				}
			}

			$fields = SaleService::void_fields( $user_id, $reason, true );
			$done   = 0;

			foreach ( $todo as $line ) {
				$done += SaleService::put_back( $line, SaleRepository::STATUS_COMPLETED, $fields ) ? 1 : 0;
			}

			if ( $done < count( $todo ) ) {
				SaleService::log( 'pqbg_basket_restore_partial', $done . '/' . count( $todo ) );

				return 0 === $done
					? self::error( 'pqbg_restore_failed', __( 'The sale could not be undone. Stock was not changed.', 'product-qrcode-barcode-generator' ) )
					: self::error( 'pqbg_partly_restored', __( 'The sale was only partly undone. Tell a manager.', 'product-qrcode-barcode-generator' ) );
			}

			return array(
				'status'    => SaleRepository::STATUS_VOIDED,
				'basket_id' => $basket_id,
			);
		} finally {
			self::release_all( $holders );
		}
	}

	/**
	 * Why the user may not undo this basket now, or true.
	 *
	 * @param array<int, array<string, string>> $lines   The basket's lines.
	 * @param int                               $user_id User.
	 * @return true|WP_Error
	 */
	private static function undo_refusal( array $lines, int $user_id ) {
		if ( array() === $lines ) {
			return self::error( 'pqbg_sale_not_found', __( 'Sale not found.', 'product-qrcode-barcode-generator' ) );
		}

		if ( (int) $lines[0]['seller_id'] !== $user_id ) {
			return self::error( 'pqbg_not_own_sale', __( 'You can only undo your own sale.', 'product-qrcode-barcode-generator' ) );
		}

		$statuses = array_unique( array_column( $lines, 'status' ) );

		if ( array( SaleRepository::STATUS_VOIDED ) === $statuses ) {
			return self::error( 'pqbg_already_undone', __( 'This sale was already undone.', 'product-qrcode-barcode-generator' ) );
		}

		if ( array( SaleRepository::STATUS_COMPLETED ) !== $statuses ) {
			return self::error( 'pqbg_not_undoable', __( 'This sale cannot be undone. Ask a manager to void it.', 'product-qrcode-barcode-generator' ) );
		}

		if ( time() > self::undo_until( $lines ) ) {
			return self::error( 'pqbg_undo_expired', __( 'Undo is no longer available (10-minute limit).', 'product-qrcode-barcode-generator' ) );
		}

		return true;
	}

	/**
	 * Takes the lock of every holder in ascending order; on failure releases those taken.
	 *
	 * @param int[] $holders Holder IDs.
	 * @return int 0, or the holder whose lock could not be taken.
	 */
	private static function lock_all( array $holders ): int {
		$holders = array_values( array_unique( array_map( 'intval', $holders ) ) );
		sort( $holders );
		$taken = array();

		foreach ( $holders as $holder_id ) {
			if ( ! StockLock::acquire( $holder_id ) ) {
				self::release_all( $taken );
				return $holder_id;
			}

			$taken[] = $holder_id;
		}

		return 0;
	}

	/**
	 * Releases the locks of these holders.
	 *
	 * @param int[] $holders Holder IDs.
	 */
	private static function release_all( array $holders ): void {
		foreach ( array_unique( array_map( 'intval', $holders ) ) as $holder_id ) {
			StockLock::release( $holder_id );
		}
	}

	/**
	 * The basket's lines, or the row alone when it has no basket ID yet (a process that
	 * died between its first insert and setting the basket ID).
	 *
	 * @param int                        $basket_id Basket ID.
	 * @param array<string, string>|null $row       The first line.
	 * @return array<int, array<string, string>>
	 */
	private static function lines_or_row( int $basket_id, ?array $row ): array {
		$lines = SaleRepository::basket_lines( $basket_id );

		return array() !== $lines || null === $row ? $lines : array( $row );
	}

	/**
	 * An error naming the line (data: line index and item code).
	 *
	 * @param int                  $index Line index.
	 * @param array<string, mixed> $line  Line.
	 * @param WP_Error             $error Error.
	 */
	private static function line_error( int $index, array $line, WP_Error $error ): WP_Error {
		$data = $error->get_error_data();

		return new WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			array_merge(
				is_array( $data ) ? $data : array(),
				array(
					'line' => $index,
					'code' => (string) ( $line['code'] ?? '' ),
				)
			)
		);
	}

	/**
	 * "Basket sales need a newer database server" (D16).
	 */
	public static function unsupported(): WP_Error {
		return self::error( 'pqbg_basket_unsupported', __( 'Basket sales need a newer database server (MySQL 5.7.5+ or MariaDB 10.0.2+). Sell the items one at a time.', 'product-qrcode-barcode-generator' ) );
	}

	/**
	 * WP_Error helper.
	 *
	 * @param string               $code    Code.
	 * @param string               $message Message for the seller.
	 * @param array<string, mixed> $data    Data.
	 */
	private static function error( string $code, string $message, array $data = array() ): WP_Error {
		return new WP_Error( $code, $message, $data );
	}
}
