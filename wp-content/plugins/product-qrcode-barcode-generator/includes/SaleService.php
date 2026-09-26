<?php
/**
 * Mark as Sold: sells scanned items, undoes a seller's own recent sale, and
 * voids sales (service level; the manager UI is Phase 9).
 *
 * Rules (Phase 1 locked decisions + Phase 7):
 *   - The item comes from the code, server-side. Price and stock are read from
 *     WooCommerce at the moment of sale; nothing from the form is trusted.
 *   - Unit price = get_price() at that moment. An empty or zero price blocks the sale.
 *   - Stock must be managed (on the item, or on the parent for variations with
 *     parent-level stock) and cover the quantity. Backorders are never used.
 *   - Sellable only in the full-product-screen states: a published or private
 *     simple product; a published (enabled) variation whose parent is published
 *     or private. Never trash, draft, pending, scheduled, retired or unknown.
 *   - No WooCommerce order is created. Every attempt that reaches the stock is
 *     journalled in pqbg_sales with snapshots (see SaleRepository).
 *   - Phase 9A: every sale needs a payment method that is enabled at that moment
 *     (PaymentMethods). The pending row also snapshots the method, the effective
 *     cost price (CostPrice; NULL when unknown) and the seller's display name.
 *     A repeated request ID returns the original sale, whatever method it carries.
 *
 * Sale sequence, under StockLock for the stock holder (the object that holds
 * the stock: the parent when a variation uses parent-level stock):
 *   recover stale pending rows → fresh reads (stock, product, price) → checks →
 *   pending row → wc_update_product_stock() with WooCommerce's UPDATE replaced by
 *   SaleRepository::stock_sql() (stock and "completed" in one statement) → fresh
 *   stock read → compensate if negative (an online order won) or on any error.
 *
 * Transactions: none. Nothing here runs START TRANSACTION, COMMIT or ROLLBACK,
 * so it can never implicitly commit a WooCommerce (or any other) transaction,
 * and a hook that opens its own transaction during the product save (for
 * example the Phase 5 code sweep) cannot break our atomicity, which comes from
 * single statements. Called inside an open transaction, the service refuses.
 *
 * Online checkout boundary: WooCommerce checkout does not take our lock. A
 * reduction landing between our read and our decrement leaves the stock
 * negative, which is detected and compensated. An online order paid after our
 * sale can still oversell, which is WooCommerce's own behaviour. Stock held
 * for unpaid checkouts is not counted (approved decision D2).
 *
 * Side effects that compensation, undo or void cannot take back: e-mails or
 * other work done by hooks on the stock change or the product save (including
 * the low/no-stock notification sent after a completed sale), and the
 * product's modified date.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WC_Product;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sales, undo and void.
 */
final class SaleService {

	/** Seconds after a sale during which its seller may undo it. */
	const UNDO_WINDOW = 600;

	/** Upper bound for a quantity, far above any real sale (int(10) column). */
	const MAX_QUANTITY = 1000000;

	/** The WooCommerce filter whose SQL is replaced for one stock change. */
	const STOCK_QUERY_FILTER = 'woocommerce_update_product_stock_query';

	const VOID_REASON_UNDO = 'undo';

	/**
	 * Resolves a code row to a sellable item (everything except the stock level).
	 *
	 * @param array<string, string>|null $row pqbg_codes row, or null for an unknown code.
	 * @return array{product: WC_Product, parent: ?WC_Product, holder: WC_Product}|WP_Error
	 */
	public static function check_item( ?array $row ) {
		if ( null === $row ) {
			return self::error( 'pqbg_code_not_found', __( 'Code not found.', 'product-qrcode-barcode-generator' ) );
		}

		if ( CodeRepository::STATUS_ACTIVE !== $row['status'] ) {
			return self::error( 'pqbg_code_retired', __( 'This label is out of date.', 'product-qrcode-barcode-generator' ) );
		}

		$product = wc_get_product( (int) $row['product_id'] );
		$parent  = null;

		if ( $product instanceof WC_Product && $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$parent = $parent instanceof WC_Product && $parent->is_type( 'variable' ) ? $parent : null;
		}

		if ( ! $product instanceof WC_Product || ( $product->is_type( 'variation' ) && ! $parent ) || ! ( $product->is_type( 'simple' ) || $product->is_type( 'variation' ) ) ) {
			return self::error( 'pqbg_item_missing', __( 'This label is no longer valid.', 'product-qrcode-barcode-generator' ) );
		}

		if ( 'trash' === $product->get_status() || ( $parent && 'trash' === $parent->get_status() ) ) {
			return self::error( 'pqbg_in_trash', __( 'This product is in the trash – cannot be sold.', 'product-qrcode-barcode-generator' ) );
		}

		$status = $parent ? $parent->get_status() : $product->get_status();

		if ( ! in_array( $status, array( 'publish', 'private' ), true ) ) {
			return self::error( 'pqbg_unpublished', __( 'Not published – cannot be sold yet.', 'product-qrcode-barcode-generator' ) );
		}

		if ( $parent && 'publish' !== $product->get_status() ) {
			return self::error( 'pqbg_variation_disabled', __( 'This variation is disabled – cannot be sold.', 'product-qrcode-barcode-generator' ) );
		}

		// Wording matches the WooCommerce 11.1.2 product editor: the Inventory tab's "Stock management"
		// checkbox reads "Track stock quantity for this product"; a variation's checkbox reads "Manage stock?".
		if ( ! $product->managing_stock() ) {
			return self::error(
				'pqbg_stock_unmanaged',
				$parent
					? __( 'Stock tracking is off for this variation. Tick \'Manage stock?\' on the variation, or \'Track stock quantity for this product\' on the product\'s Inventory tab, to sell from a scan.', 'product-qrcode-barcode-generator' )
					: __( 'Stock tracking is off for this product. On the Inventory tab, tick \'Track stock quantity for this product\' to sell from a scan.', 'product-qrcode-barcode-generator' )
			);
		}

		$price = self::normalize_price( $product->get_price() );

		if ( '' === $price ) {
			return self::error( 'pqbg_no_price', __( 'This item has no price. Set a price before selling.', 'product-qrcode-barcode-generator' ) );
		}

		if ( 0.0 === (float) $price ) {
			/* translators: %s: zero in the store currency, e.g. ₹0. */
			return self::error( 'pqbg_zero_price', sprintf( __( 'This item has no price (%s). Set a price before selling.', 'product-qrcode-barcode-generator' ), html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) . '0' ) );
		}

		$holder_id = (int) $product->get_stock_managed_by_id();
		$holder    = $holder_id === $product->get_id() ? $product : wc_get_product( $holder_id );

		if ( ! $holder instanceof WC_Product ) {
			return self::error( 'pqbg_item_missing', __( 'This label is no longer valid.', 'product-qrcode-barcode-generator' ) );
		}

		return array(
			'product' => $product,
			'parent'  => $parent,
			'holder'  => $holder,
		);
	}

	/**
	 * A price as a decimal string with the store's price decimals ('' when not set),
	 * so "1499", "1499.00" and 1499.0 compare equal.
	 *
	 * @param mixed $price Price.
	 */
	public static function normalize_price( $price ): string {
		if ( null === $price || '' === $price || ! is_numeric( $price ) ) {
			return '';
		}

		return (string) wc_format_decimal( $price, wc_get_price_decimals() );
	}

	/**
	 * Line total for a quantity at a unit price, rounded to the store's price decimals.
	 *
	 * @param string $unit_price Unit price.
	 * @param int    $quantity   Quantity.
	 */
	public static function line_total( string $unit_price, int $quantity ): string {
		return (string) wc_format_decimal( round( (float) $unit_price * $quantity, wc_get_price_decimals() ), wc_get_price_decimals() );
	}

	/**
	 * Sells an item.
	 *
	 * @param array{code: string, quantity: int, request_id: string, seller_id: int, payment_method: string, seen_price?: ?string, seen_stock?: ?int} $args Request.
	 * @return array{status: string, sale: array<string, string>}|WP_Error
	 *         status 'completed' or 'failed' (the row says why); a WP_Error when nothing was journalled.
	 */
	public static function sell( array $args ) {
		$seller   = (int) $args['seller_id'];
		$quantity = (int) $args['quantity'];
		$request  = (string) $args['request_id'];

		if ( ! Permissions::can_sell( $seller ) ) {
			return self::error( 'pqbg_forbidden', __( 'You do not have permission to sell.', 'product-qrcode-barcode-generator' ) );
		}

		if ( ! wp_is_uuid( $request, 4 ) ) {
			return self::error( 'pqbg_bad_request', __( 'This form is not valid. Check the item and confirm again.', 'product-qrcode-barcode-generator' ) );
		}

		if ( $quantity < 1 || $quantity > self::MAX_QUANTITY ) {
			return self::error( 'pqbg_invalid_quantity', __( 'Choose a valid quantity.', 'product-qrcode-barcode-generator' ) );
		}

		if ( self::in_transaction() ) {
			return self::error( 'pqbg_in_transaction', __( 'The sale could not be completed. Stock was not changed.', 'product-qrcode-barcode-generator' ) );
		}

		$existing = SaleRepository::find_by_request_id( $request );

		if ( null !== $existing ) {
			return self::outcome( $existing, $seller );
		}

		$method = self::check_payment_method( $args['payment_method'] ?? null );

		if ( is_wp_error( $method ) ) {
			return $method;
		}

		$row  = CodeRepository::find_by_code( (string) $args['code'] );
		$item = self::check_item( $row );

		if ( is_wp_error( $item ) ) {
			return $item;
		}

		$holder_id = $item['holder']->get_id();

		if ( ! StockLock::acquire( $holder_id ) ) {
			return self::error( 'pqbg_busy', __( 'Someone else is selling this item right now. Try again.', 'product-qrcode-barcode-generator' ) );
		}

		try {
			$result = self::sell_locked( $row, $holder_id, $quantity, $request, $seller, $args );
		} finally {
			StockLock::release( $holder_id );
		}

		// After the lock: an e-mail must not hold up the next sale of this item.
		if ( is_array( $result ) ) {
			if ( ! empty( $result['notify'] ) ) {
				self::notify( $holder_id );
			}

			unset( $result['notify'] );
		}

		return $result;
	}

	/**
	 * The part of a sale that runs under the stock holder's lock.
	 *
	 * @param array<string, string> $row       Code row.
	 * @param int                   $holder_id Stock holder ID.
	 * @param int                   $quantity  Quantity.
	 * @param string                $request   Request ID.
	 * @param int                   $seller    Seller.
	 * @param array<string, mixed>  $args      Original request.
	 * @return array{status: string, sale: array<string, string>}|WP_Error
	 */
	private static function sell_locked( array $row, int $holder_id, int $quantity, string $request, int $seller, array $args ) {
		// A concurrent duplicate of this request may have finished while we waited for the lock.
		$existing = SaleRepository::find_by_request_id( $request );

		if ( null !== $existing ) {
			return self::outcome( $existing, $seller );
		}

		SaleRepository::recover_stale( $holder_id );

		// Fresh objects: the product, its parent and the holder may have changed while we waited.
		self::forget( array( (int) $row['product_id'], $holder_id ) );
		$item = self::check_item( $row );

		if ( is_wp_error( $item ) ) {
			return $item;
		}

		if ( $item['holder']->get_id() !== $holder_id ) {
			return self::error( 'pqbg_stock_changed', __( 'Stock settings changed since you opened this page. Check the item and confirm again.', 'product-qrcode-barcode-generator' ) );
		}

		$product = $item['product'];
		$parent  = $item['parent'];

		// The parent holds the default cost; with variation-level stock it was not refreshed above.
		self::forget( array( $parent ? $parent->get_id() : 0 ) );

		$stock   = SaleRepository::read_stock( $holder_id );
		$stock   = null === $stock ? 0 : $stock;
		$seen    = isset( $args['seen_stock'] ) && null !== $args['seen_stock'] ? (int) $args['seen_stock'] : null;

		if ( $stock <= 0 ) {
			return self::error( 'pqbg_out_of_stock', __( 'Out of stock – cannot be sold.', 'product-qrcode-barcode-generator' ), array( 'stock' => 0 ) );
		}

		if ( $quantity > $stock ) {
			return null !== $seen && $seen !== (int) $stock
				/* translators: %s: current stock quantity. */
				? self::error( 'pqbg_stock_changed', sprintf( __( 'Stock changed since you opened this page. Now %s in stock.', 'product-qrcode-barcode-generator' ), number_format_i18n( $stock ) ), array( 'stock' => $stock ) )
				/* translators: %s: current stock quantity. */
				: self::error( 'pqbg_insufficient_stock', sprintf( __( 'Only %1$s in stock. Choose a quantity between 1 and %1$s.', 'product-qrcode-barcode-generator' ), number_format_i18n( $stock ) ), array( 'stock' => $stock ) );
		}

		$price = (string) $product->get_price();

		if ( isset( $args['seen_price'] ) && null !== $args['seen_price'] && self::normalize_price( $args['seen_price'] ) !== self::normalize_price( $price ) ) {
			return self::error( 'pqbg_price_changed', __( 'The price changed since you opened this page. Check the new price and confirm again.', 'product-qrcode-barcode-generator' ) );
		}

		$regular = (string) $product->get_regular_price();
		$sku     = (string) $product->get_sku();
		$attrs   = $parent ? self::attributes( $product ) : array();
		$sale_id = SaleRepository::insert_pending(
			array(
				'request_id'      => $request,
				'code_id'         => (int) $row['id'],
				'product_id'      => $parent ? $parent->get_id() : $product->get_id(),
				'variation_id'    => $parent ? $product->get_id() : 0,
				'seller_id'       => $seller,
				'quantity'        => $quantity,
				'unit_price'      => $price,
				'regular_price'   => '' === $regular ? null : $regular,
				'line_total'      => self::line_total( $price, $quantity ),
				'currency'        => get_woocommerce_currency(),
				'product_name'    => $parent ? $parent->get_name() : $product->get_name(),
				'sku'             => '' === $sku ? null : $sku,
				'attributes_json' => array() === $attrs ? null : wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE ),
				'stock_before'    => (int) $stock,
				'stock_holder_id' => $holder_id,
				'payment_method'  => (string) $args['payment_method'],
				'unit_cost'       => CostPrice::effective( $product, $parent ),
				'seller_name'     => self::seller_name( $seller ),
				// source: the column default ('scan').
			)
		);

		if ( is_wp_error( $sale_id ) ) {
			$existing = SaleRepository::find_by_request_id( $request );
			return null !== $existing ? self::outcome( $existing, $seller ) : $sale_id;
		}

		$change = self::change_stock( $item['holder'], $quantity, 'decrease', $sale_id, SaleRepository::STATUS_PENDING, array( 'status' => SaleRepository::STATUS_COMPLETED ) );
		$sale   = SaleRepository::find( $sale_id );

		if ( null !== $sale && SaleRepository::STATUS_PENDING === $sale['status'] ) {
			$sale = self::resolve_pending( $sale, $stock, $quantity, $change );
		}

		if ( null === $sale || SaleRepository::STATUS_COMPLETED !== $sale['status'] ) {
			self::log( 'pqbg_sale_not_applied', null !== $change['error'] ? get_class( $change['error'] ) : '' );
			return array(
				'status' => SaleRepository::STATUS_FAILED,
				'sale'   => $sale ?? array(),
			);
		}

		$after = SaleRepository::read_stock( $holder_id );

		if ( null !== $change['error'] || null === $after || $after < 0 ) {
			$reason = null === $change['error'] && null !== $after ? SaleRepository::FAILURE_SOLD_ONLINE : SaleRepository::FAILURE_ERROR;

			return self::compensate( $sale, $item['holder'], $quantity, $reason, $change['error'] );
		}

		SaleRepository::set_stock_after( $sale_id, $stock, $after );

		return array(
			'status' => SaleRepository::STATUS_COMPLETED,
			'sale'   => SaleRepository::find( $sale_id ) ?? $sale,
			'notify' => true,
		);
	}

	/**
	 * A submitted payment method, if it is one the sale form offers now.
	 *
	 * @param mixed $method Submitted value.
	 * @return string|WP_Error
	 */
	public static function check_payment_method( $method ) {
		if ( ! is_string( $method ) || '' === $method ) {
			return self::error( 'pqbg_payment_required', __( 'Choose how the customer paid.', 'product-qrcode-barcode-generator' ) );
		}

		if ( ! PaymentMethods::is_enabled( $method ) ) {
			return self::error( 'pqbg_payment_invalid', __( 'That payment method is not available. Choose another.', 'product-qrcode-barcode-generator' ) );
		}

		return $method;
	}

	/**
	 * Undo: the seller's own completed sale, within UNDO_WINDOW, once.
	 *
	 * @param int $sale_id Sale ID.
	 * @param int $user_id Acting user.
	 * @return array{status: string, sale: array<string, string>}|WP_Error
	 */
	public static function undo( int $sale_id, int $user_id ) {
		if ( ! Permissions::can_sell( $user_id ) ) {
			return self::error( 'pqbg_forbidden', __( 'You do not have permission to sell.', 'product-qrcode-barcode-generator' ) );
		}

		$sale = SaleRepository::find( $sale_id );
		$ok   = self::undo_refusal( $sale, $user_id );

		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		return self::restore( $sale, $user_id, self::VOID_REASON_UNDO, static fn( array $fresh ) => self::undo_refusal( $fresh, $user_id ) );
	}

	/**
	 * Voids any completed sale (manager action; the UI is Phase 9). Needs pqbg_void_sale.
	 *
	 * @param int    $sale_id Sale ID.
	 * @param int    $user_id Acting user.
	 * @param string $reason  Reason recorded in void_reason.
	 * @param bool   $restock Whether to put the quantity back into stock.
	 * @return array{status: string, sale: array<string, string>}|WP_Error
	 */
	public static function void_sale( int $sale_id, int $user_id, string $reason, bool $restock = true ) {
		if ( ! Permissions::can_void_sale( $user_id ) ) {
			return self::error( 'pqbg_forbidden', __( 'You do not have permission to void sales.', 'product-qrcode-barcode-generator' ) );
		}

		$sale   = SaleRepository::find( $sale_id );
		$reason = '' === trim( $reason ) ? 'void' : sanitize_textarea_field( $reason );
		$check  = static fn( ?array $row ) => null !== $row && SaleRepository::STATUS_COMPLETED === $row['status'] ? true : self::error( 'pqbg_not_voidable', __( 'Only a completed sale can be voided.', 'product-qrcode-barcode-generator' ) );

		if ( is_wp_error( $check( $sale ) ) ) {
			return $check( $sale );
		}

		if ( ! $restock ) {
			$done = SaleRepository::transition( (int) $sale['id'], SaleRepository::STATUS_COMPLETED, self::void_fields( $user_id, $reason ) );

			return $done
				? array(
					'status' => SaleRepository::STATUS_VOIDED,
					'sale'   => SaleRepository::find( (int) $sale['id'] ),
				)
				: self::error( 'pqbg_not_voidable', __( 'Only a completed sale can be voided.', 'product-qrcode-barcode-generator' ) );
		}

		return self::restore( $sale, $user_id, $reason, $check );
	}

	/**
	 * Whether the user may undo this sale now (for showing the Undo button).
	 *
	 * @param array<string, string> $sale    Sale row.
	 * @param int                   $user_id User.
	 */
	public static function can_undo( array $sale, int $user_id ): bool {
		return Permissions::can_sell( $user_id ) && ! is_wp_error( self::undo_refusal( $sale, $user_id ) );
	}

	/**
	 * Unix time until which a sale can be undone.
	 *
	 * @param array<string, string> $sale Sale row.
	 */
	public static function undo_until( array $sale ): int {
		return self::created_ts( $sale ) + self::UNDO_WINDOW;
	}

	/**
	 * Unix time of a sale.
	 *
	 * @param array<string, string> $sale Sale row.
	 */
	public static function created_ts( array $sale ): int {
		return (int) strtotime( $sale['created_at_gmt'] . ' UTC' );
	}

	/**
	 * Why the user may not undo this sale, or true.
	 *
	 * @param array<string, string>|null $sale    Sale row.
	 * @param int                        $user_id User.
	 * @return true|WP_Error
	 */
	private static function undo_refusal( ?array $sale, int $user_id ) {
		if ( null === $sale ) {
			return self::error( 'pqbg_sale_not_found', __( 'Sale not found.', 'product-qrcode-barcode-generator' ) );
		}

		if ( (int) $sale['seller_id'] !== $user_id ) {
			return self::error( 'pqbg_not_own_sale', __( 'You can only undo your own sale.', 'product-qrcode-barcode-generator' ) );
		}

		if ( SaleRepository::STATUS_VOIDED === $sale['status'] ) {
			return self::error( 'pqbg_already_undone', __( 'This sale was already undone.', 'product-qrcode-barcode-generator' ) );
		}

		if ( SaleRepository::STATUS_COMPLETED !== $sale['status'] ) {
			return self::error( 'pqbg_not_undoable', __( 'This sale cannot be undone.', 'product-qrcode-barcode-generator' ) );
		}

		if ( time() > self::undo_until( $sale ) ) {
			return self::error( 'pqbg_undo_expired', __( 'Undo is no longer available (10-minute limit).', 'product-qrcode-barcode-generator' ) );
		}

		return true;
	}

	/**
	 * Puts a completed sale's quantity back into the recorded stock holder and
	 * marks the sale voided, in one statement, under the holder's lock.
	 *
	 * @param array<string, string> $sale    Sale row.
	 * @param int                   $user_id Acting user (voided_by).
	 * @param string                $reason  void_reason.
	 * @param callable              $recheck Re-validates the fresh row under the lock: true or WP_Error.
	 * @return array{status: string, sale: array<string, string>}|WP_Error
	 */
	private static function restore( array $sale, int $user_id, string $reason, callable $recheck ) {
		if ( self::in_transaction() ) {
			return self::error( 'pqbg_in_transaction', __( 'The sale could not be changed.', 'product-qrcode-barcode-generator' ) );
		}

		$holder_id = (int) $sale['stock_holder_id'];
		$quantity  = (int) $sale['quantity'];

		if ( $holder_id <= 0 ) {
			return self::error( 'pqbg_undo_unavailable', __( 'This sale cannot be undone here.', 'product-qrcode-barcode-generator' ) );
		}

		if ( ! StockLock::acquire( $holder_id ) ) {
			return self::error( 'pqbg_busy', __( 'Someone else is selling this item right now. Try again.', 'product-qrcode-barcode-generator' ) );
		}

		try {
			$fresh = SaleRepository::find( (int) $sale['id'] );
			$ok    = $recheck( $fresh );

			if ( is_wp_error( $ok ) ) {
				return $ok;
			}

			self::forget( array( $holder_id ) );
			$holder = wc_get_product( $holder_id );

			if ( ! $holder instanceof WC_Product || ! $holder->managing_stock() || (int) $holder->get_stock_managed_by_id() !== $holder_id ) {
				return self::error( 'pqbg_undo_unavailable', __( 'Stock tracking changed for this product, so the sale cannot be undone here.', 'product-qrcode-barcode-generator' ) );
			}

			$before = SaleRepository::read_stock( $holder_id );
			$fields = self::void_fields( $user_id, $reason );
			$change = self::change_stock( $holder, $quantity, 'increase', (int) $fresh['id'], SaleRepository::STATUS_COMPLETED, $fields );
			$row    = SaleRepository::find( (int) $fresh['id'] );

			if ( null !== $row && SaleRepository::STATUS_COMPLETED === $row['status'] ) {
				// Our statement did not run (the SQL was overridden or not issued). Decide from the stock itself.
				$after = SaleRepository::read_stock( $holder_id );

				if ( null !== $before && null !== $after && $after - $before >= $quantity && SaleRepository::transition( (int) $row['id'], SaleRepository::STATUS_COMPLETED, $fields ) ) {
					self::log( 'pqbg_stock_marker_missing' );
					$row = SaleRepository::find( (int) $row['id'] );
				} else {
					self::log( 'pqbg_restore_failed', null !== $change['error'] ? get_class( $change['error'] ) : '' );
					return self::error( 'pqbg_restore_failed', __( 'The sale could not be undone. Stock was not changed.', 'product-qrcode-barcode-generator' ) );
				}
			}

			return array(
				'status' => SaleRepository::STATUS_VOIDED,
				'sale'   => $row,
			);
		} finally {
			StockLock::release( $holder_id );
		}
	}

	/**
	 * Takes back a decrement whose sale must not stand, and marks the row failed
	 * in the same statement.
	 *
	 * @param array<string, string> $sale     Completed sale row.
	 * @param WC_Product            $holder   Stock holder.
	 * @param int                   $quantity Quantity to put back.
	 * @param string                $reason   failure_code.
	 * @param \Throwable|null       $error    Error that caused it, if any.
	 * @return array{status: string, sale: array<string, string>}|WP_Error
	 */
	private static function compensate( array $sale, WC_Product $holder, int $quantity, string $reason, ?\Throwable $error ) {
		$holder_id = $holder->get_id();
		$before    = SaleRepository::read_stock( $holder_id );
		$fields    = array(
			'status'       => SaleRepository::STATUS_FAILED,
			'failure_code' => $reason,
		);

		self::forget( array( $holder_id ) );
		$fresh  = wc_get_product( $holder_id );
		$change = self::change_stock( $fresh instanceof WC_Product ? $fresh : $holder, $quantity, 'increase', (int) $sale['id'], SaleRepository::STATUS_COMPLETED, $fields );
		$row    = SaleRepository::find( (int) $sale['id'] );

		if ( null !== $row && SaleRepository::STATUS_COMPLETED === $row['status'] ) {
			$after = SaleRepository::read_stock( $holder_id );

			if ( null !== $before && null !== $after && $after - $before >= $quantity && SaleRepository::transition( (int) $row['id'], SaleRepository::STATUS_COMPLETED, $fields ) ) {
				self::log( 'pqbg_stock_marker_missing' );
				$row = SaleRepository::find( (int) $row['id'] );
			} else {
				// The stock stays decremented and the row says completed: they agree, but the seller must be told.
				self::log( 'pqbg_compensation_failed', null !== $change['error'] ? get_class( $change['error'] ) : '' );
				return self::error( 'pqbg_compensation_failed', __( 'The sale was recorded, but it could not be reversed. Tell a manager.', 'product-qrcode-barcode-generator' ) );
			}
		}

		self::log( 'pqbg_sale_compensated_' . $reason, null !== $error ? get_class( $error ) : '' );

		return array(
			'status' => SaleRepository::STATUS_FAILED,
			'sale'   => $row ?? $sale,
		);
	}

	/**
	 * Fallback when WooCommerce ran but the row is still pending (our statement
	 * was not used, e.g. another plugin replaced the SQL, or WooCommerce threw
	 * before its UPDATE). Decides from a fresh _stock read whether the decrement
	 * happened: a drop of at least the quantity since the read under the lock
	 * means it did → completed (conditional on pending); otherwise → failed.
	 * Under our lock only online orders can also lower the stock, so this can be
	 * wrong only if one lands in the same milliseconds (documented).
	 *
	 * @param array<string, string>                    $sale     Pending row.
	 * @param int|float                                $before   Stock read under the lock.
	 * @param int                                      $quantity Quantity.
	 * @param array{fired: bool, replaced: bool, shape: ?bool, error: ?\Throwable} $change Result of change_stock().
	 * @return array<string, string>|null Fresh row.
	 */
	private static function resolve_pending( array $sale, $before, int $quantity, array $change ): ?array {
		$after   = SaleRepository::read_stock( (int) $sale['stock_holder_id'] );
		$applied = null !== $after && $before - $after >= $quantity;

		self::log( $change['fired'] ? ( false === $change['shape'] ? 'pqbg_stock_sql_unexpected' : 'pqbg_stock_marker_missing' ) : 'pqbg_stock_filter_not_fired', $applied ? 'applied' : 'not_applied' );

		if ( $applied ) {
			SaleRepository::transition( (int) $sale['id'], SaleRepository::STATUS_PENDING, array( 'status' => SaleRepository::STATUS_COMPLETED ) );
		} else {
			SaleRepository::transition(
				(int) $sale['id'],
				SaleRepository::STATUS_PENDING,
				array(
					'status'       => SaleRepository::STATUS_FAILED,
					'failure_code' => SaleRepository::FAILURE_ERROR,
				)
			);
		}

		return SaleRepository::find( (int) $sale['id'] );
	}

	/**
	 * Changes the holder's stock through WooCommerce, with WooCommerce's own
	 * UPDATE replaced (once) by SaleRepository::stock_sql(), so the stock and the
	 * sale row change in one statement. The filter is removed in `finally`.
	 *
	 * The replacement is used only if the SQL WooCommerce hands over has the
	 * expected shape (see is_expected_stock_sql()); otherwise WooCommerce's SQL
	 * runs unchanged and the caller's fallback decides from the stock level.
	 *
	 * @param WC_Product           $holder   Stock holder.
	 * @param int                  $quantity Quantity.
	 * @param string               $op       'decrease' or 'increase'.
	 * @param int                  $sale_id  Sale row.
	 * @param string               $from     Row status required for the change.
	 * @param array<string, mixed> $set      Row columns set in the same statement.
	 * @return array{fired: bool, replaced: bool, shape: ?bool, error: ?\Throwable}
	 */
	private static function change_stock( WC_Product $holder, int $quantity, string $op, int $sale_id, string $from, array $set ): array {
		$holder_id = $holder->get_id();
		$state     = array(
			'fired'    => false,
			'replaced' => false,
			'shape'    => null,
			'error'    => null,
		);
		$sql       = SaleRepository::stock_sql( $holder_id, 'decrease' === $op ? -$quantity : $quantity, $sale_id, $from, $set );
		$filter    = static function ( $query, $product_id = 0, $new_stock = null, $operation = '' ) use ( &$state, $holder_id, $quantity, $op, $sql ) {
			$state['fired'] = true;

			if ( $state['replaced'] || (int) $product_id !== $holder_id || $operation !== $op ) {
				return $query;
			}

			$state['shape'] = self::is_expected_stock_sql( (string) $query, $holder_id, $quantity, $op );

			if ( ! $state['shape'] ) {
				return $query;
			}

			$state['replaced'] = true;

			return $sql;
		};

		add_filter( self::STOCK_QUERY_FILTER, $filter, PHP_INT_MAX, 4 );

		try {
			wc_update_product_stock( $holder, $quantity, $op );
		} catch ( \Throwable $e ) {
			$state['error'] = $e;
		} finally {
			remove_filter( self::STOCK_QUERY_FILTER, $filter, PHP_INT_MAX );
		}

		return $state;
	}

	/**
	 * Whether SQL handed to woocommerce_update_product_stock_query is the plain
	 * relative UPDATE WooCommerce 3.6–11.x issues for this holder and quantity:
	 *   UPDATE {postmeta} SET meta_value = meta_value -2.000000 WHERE post_id = 12 AND meta_key='_stock'
	 * Replacing anything else could change its meaning, so it is left alone.
	 *
	 * @param string $sql       SQL.
	 * @param int    $holder_id Stock holder ID.
	 * @param int    $quantity  Quantity.
	 * @param string $op        'decrease' or 'increase'.
	 */
	public static function is_expected_stock_sql( string $sql, int $holder_id, int $quantity, string $op ): bool {
		global $wpdb;

		if ( ! preg_match( '/^UPDATE ' . preg_quote( $wpdb->postmeta, '/' ) . " SET meta_value = meta_value ([+-][0-9]+(?:\\.[0-9]+)?) WHERE post_id = ([0-9]+) AND meta_key='_stock'$/", trim( $sql ), $m ) ) {
			return false;
		}

		$expected = 'decrease' === $op ? -$quantity : $quantity;

		return (int) $m[2] === $holder_id && abs( (float) $m[1] - $expected ) < 0.000001;
	}

	/**
	 * Result of an earlier request with the same request ID.
	 *
	 * @param array<string, string> $sale   Row.
	 * @param int                   $seller Acting seller.
	 * @return array{status: string, sale: array<string, string>}|WP_Error
	 */
	private static function outcome( array $sale, int $seller ) {
		if ( (int) $sale['seller_id'] !== $seller ) {
			return self::error( 'pqbg_bad_request', __( 'This form is not valid. Check the item and confirm again.', 'product-qrcode-barcode-generator' ) );
		}

		// A pending row of a finished request can only be one whose process died; it never changed stock.
		$status = SaleRepository::STATUS_PENDING === $sale['status'] ? SaleRepository::STATUS_FAILED : $sale['status'];

		return array(
			'status' => in_array( $status, array( SaleRepository::STATUS_COMPLETED, SaleRepository::STATUS_VOIDED ), true ) ? SaleRepository::STATUS_COMPLETED : SaleRepository::STATUS_FAILED,
			'sale'   => $sale,
		);
	}

	/**
	 * Columns set when a sale is voided.
	 *
	 * @param int    $user_id User.
	 * @param string $reason  Reason.
	 * @return array<string, mixed>
	 */
	private static function void_fields( int $user_id, string $reason ): array {
		return array(
			'status'        => SaleRepository::STATUS_VOIDED,
			'voided_by'     => $user_id,
			'voided_at_gmt' => current_time( 'mysql', true ),
			'void_reason'   => $reason,
		);
	}

	/**
	 * Variation attributes as label => value, for the snapshot.
	 *
	 * @param WC_Product $variation Variation.
	 * @return array<string, string>
	 */
	private static function attributes( WC_Product $variation ): array {
		$out = array();

		foreach ( $variation->get_attributes() as $name => $value ) {
			$value = (string) $value;

			if ( '' !== $value && taxonomy_exists( $name ) ) {
				$term  = get_term_by( 'slug', $value, $name );
				$value = $term ? $term->name : $value;
			}

			$out[ wc_attribute_label( $name, $variation ) ] = $value;
		}

		return $out;
	}

	/**
	 * The seller's display name for the snapshot (NULL when the user does not exist).
	 *
	 * @param int $seller User ID.
	 */
	private static function seller_name( int $seller ): ?string {
		$user = get_userdata( $seller );

		return $user ? mb_substr( (string) $user->display_name, 0, 250 ) : null;
	}

	/**
	 * Store low/no-stock notification for a completed sale (approved decision D4).
	 * WooCommerce sends these itself only for order-based stock changes.
	 *
	 * @param int $holder_id Stock holder ID.
	 */
	private static function notify( int $holder_id ): void {
		try {
			self::forget( array( $holder_id ) );
			$holder = wc_get_product( $holder_id );

			if ( $holder instanceof WC_Product && function_exists( 'wc_trigger_stock_change_actions' ) ) {
				wc_trigger_stock_change_actions( $holder );
			}
		} catch ( \Throwable $e ) {
			self::log( 'pqbg_stock_notification_failed', get_class( $e ) );
		}
	}

	/**
	 * Drops cached copies of products so the next read comes from the database.
	 *
	 * @param int[] $ids Product IDs.
	 */
	private static function forget( array $ids ): void {
		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			clean_post_cache( $id );
			wp_cache_delete( $id, 'post_meta' );
		}
	}

	/**
	 * Whether this database connection is inside an open transaction.
	 */
	private static function in_transaction(): bool {
		global $wpdb;

		return '1' === (string) $wpdb->get_var( 'SELECT @@in_transaction' );
	}

	/**
	 * Logs a warning by error code only (no personal or product data).
	 *
	 * @param string $code   Error code.
	 * @param string $detail Optional short technical detail (an exception class, a branch).
	 */
	private static function log( string $code, string $detail = '' ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( 'Sale: ' . $code . ( '' !== $detail ? ' (' . $detail . ')' : '' ), array( 'source' => 'product-qrcode-barcode-generator' ) );
		}
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
