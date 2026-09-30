<?php
/**
 * What the scan page shows for a code: resolves the code to a screen (the
 * approved status → screen matrix) and renders the plugin's own template.
 *
 * Read-only. Product data is read live from WooCommerce on every request and
 * nothing is cached or written. Only display data is collected: no cost,
 * supplier, notes or customer data. Selling is done by SaleRequest and
 * SaleService; this class only builds the sale form (for users with pqbg_sell
 * on a sellable item) and the sale result page.
 *
 * Access control is NOT done here; ScanRoute checks it before calling in.
 *
 * A view is a plain array:
 *   status   int      HTTP status
 *   notices  array    list of [ type, text ]; type is info, warning or error
 *   product  ?array   full product details (see product_details())
 *   summary  ?array   name and SKU only (trash, retired)
 *   code     string   the code shown on the page, '' for none
 *   links    array    list of [ url, label ]
 *   box      bool     whether to show the "Scan or type a code" box
 *   value    string   value to prefill in the box
 *   sell     ?array   the sale form (see sell_form())
 *   sale     ?array   a recorded sale (see sale())
 *   undo     ?array   the Undo form for that sale
 *   box_label string  label of the box; '' for the default
 *   mine     ?array   the seller's own sales (see my_sales(); Phase 9A)
 *   upi      ?array   the UPI payment panel instead of the sale form (see upi_step(); Phase 16)
 *   receipt  ?array   a receipt (see receipt(); Phase 16), rendered with its own template
 *   script_nonce string CSP nonce of the receipt page's one script element; '' elsewhere
 *   basket   ?array   the seller's open basket (see basket(); Phase 17)
 *   basket_sale ?array a sold basket (see basket_sale(); Phase 17)
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Scan page view models and rendering.
 */
final class ScanScreen {

	const STYLE_HANDLE = 'pqbg-scan';

	/** Phase 16: the receipt page's stylesheet. */
	const RECEIPT_STYLE_HANDLE = 'pqbg-receipt';

	/** Parent statuses that mean "not published yet". */
	const UNPUBLISHED = array( 'draft', 'pending', 'future', 'auto-draft' );

	/** Above this stock level the quantity is a number field instead of a list with totals. */
	const QUANTITY_LIST_MAX = 100;

	/** Sale refusals shown as a notice on the product screen (the status ones already have their own). */
	const SELL_NOTICES = array( 'pqbg_stock_unmanaged', 'pqbg_no_price', 'pqbg_zero_price' );

	/** My sales ranges: URL value => SalesQuery preset. 'today' is the canonical page without an argument. */
	const MY_SALES_RANGES = array(
		'today'     => 'today',
		'yesterday' => 'yesterday',
		'7d'        => 'last7',
	);

	/** At most this many lines on My sales (the summary always covers the whole range). */
	const MY_SALES_LINES = 300;

	/**
	 * The screen for a well-formed code.
	 *
	 * @param string               $code    Code matching CodeGenerator::FORMAT_PATTERN.
	 * @param array<string, mixed> $prefill Quantity and payment method to keep on a sale form shown again.
	 * @return array<string, mixed>
	 */
	public static function resolve( string $code, array $prefill = array() ): array {
		if ( ! CodeGenerator::is_valid_format( $code ) ) {
			return self::invalid( $code );
		}

		$row = CodeRepository::find_by_code( $code );

		if ( null === $row ) {
			return self::view( 404, array( array( 'error', __( 'Code not found.', 'product-qrcode-barcode-generator' ) ) ), array( 'code' => $code ) );
		}

		$product = wc_get_product( (int) $row['product_id'] );
		$product = $product instanceof WC_Product ? $product : null;
		$parent  = null;

		if ( $product && $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$parent = $parent instanceof WC_Product ? $parent : null;

			if ( ! $parent ) {
				$product = null; // A variation without its parent is not a usable item.
			}
		}

		if ( CodeRepository::STATUS_ACTIVE !== $row['status'] ) {
			return self::retired( $code, $product, $parent );
		}

		if ( ! $product ) {
			return self::no_longer_valid( $code, false );
		}

		$in_trash = 'trash' === $product->get_status() || ( $parent && 'trash' === $parent->get_status() );

		if ( $in_trash ) {
			return self::view(
				200,
				array( array( 'error', __( 'This product is in the trash.', 'product-qrcode-barcode-generator' ) ) ),
				array(
					'code'    => $code,
					'summary' => array(
						'name' => self::display_name( $product, $parent ),
						'sku'  => (string) $product->get_sku(),
					),
				)
			);
		}

		$notices = array();
		$status  = $parent ? $parent->get_status() : $product->get_status();

		if ( in_array( $status, self::UNPUBLISHED, true ) ) {
			$notices[] = array( 'warning', __( 'Not published – cannot be sold yet.', 'product-qrcode-barcode-generator' ) );
		}

		// A private variation is WooCommerce's "disabled" variation. A private simple product is normal.
		if ( $parent && 'private' === $product->get_status() ) {
			$notices[] = array( 'warning', __( 'This variation is disabled – cannot be sold.', 'product-qrcode-barcode-generator' ) );
		}

		$links     = array();
		$edit_link = current_user_can( 'edit_products' ) ? get_edit_post_link( $parent ? $parent->get_id() : $product->get_id(), 'raw' ) : '';

		if ( is_string( $edit_link ) && '' !== $edit_link ) {
			$links[] = array( $edit_link, __( 'Edit product', 'product-qrcode-barcode-generator' ) );
		}

		$sell = null;

		// Users without pqbg_sell see the product screen exactly as in Phase 6.
		if ( Permissions::can_sell() ) {
			$item = SaleService::check_item( $row );

			if ( is_wp_error( $item ) ) {
				if ( in_array( $item->get_error_code(), self::SELL_NOTICES, true ) ) {
					$notices[] = array( 'warning', $item->get_error_message() );
				}
			} else {
				$stock = SaleRepository::read_stock( $item['holder']->get_id() );

				if ( null === $stock || $stock <= 0 ) {
					$notices[] = array( 'warning', __( 'Out of stock – cannot be sold.', 'product-qrcode-barcode-generator' ) );
				} else {
					$sell           = self::sell_form( $code, $row, $item['product'], (int) $stock, $prefill );
					$sell['basket'] = self::basket_bar( get_current_user_id() ); // Phase 17 (D3).
				}
			}
		}

		return self::view(
			200,
			$notices,
			array(
				'code'    => $code,
				'product' => self::product_details( $product, $parent ),
				'links'   => $links,
				'sell'    => $sell,
			)
		);
	}

	/**
	 * A view with an error notice first and another HTTP status.
	 *
	 * @param array<string, mixed> $view    View.
	 * @param int                  $status  HTTP status.
	 * @param string               $message Message.
	 * @return array<string, mixed>
	 */
	public static function with_error( array $view, int $status, string $message ): array {
		$view['status']  = $status;
		$view['notices'] = array_merge( array( array( 'error', $message ) ), $view['notices'] );

		return $view;
	}

	/**
	 * A recorded sale, shown from the row's snapshots (not live product data).
	 *
	 * @param array<string, string> $sale Sale row.
	 * @param string                $code Code in the URL.
	 * @return array<string, mixed>
	 */
	public static function sale( array $sale, string $code ): array {
		$args  = array( 'currency' => $sale['currency'] );
		$attrs = json_decode( (string) $sale['attributes_json'], true );
		$pairs = array();

		foreach ( is_array( $attrs ) ? $attrs : array() as $label => $value ) {
			$pairs[] = $label . ': ' . $value;
		}

		if ( SaleRepository::STATUS_COMPLETED === $sale['status'] ) {
			$notice = array( 'success', __( 'Sold.', 'product-qrcode-barcode-generator' ) );
		} elseif ( SaleRepository::STATUS_VOIDED === $sale['status'] ) {
			$when   = wp_date( get_option( 'time_format' ), (int) strtotime( $sale['voided_at_gmt'] . ' UTC' ) );
			$notice = array(
				'info',
				SaleService::VOID_REASON_UNDO === $sale['void_reason']
					/* translators: %s: time. */
					? sprintf( __( 'This sale was undone at %s. Stock was restored.', 'product-qrcode-barcode-generator' ), $when )
					/* translators: %s: time. */
					: sprintf( __( 'This sale was voided at %s.', 'product-qrcode-barcode-generator' ), $when ),
			);
		} else {
			$notice = array(
				'error',
				SaleRepository::FAILURE_SOLD_ONLINE === $sale['failure_code']
					? __( 'This item just sold online. Stock was not changed.', 'product-qrcode-barcode-generator' )
					: __( 'The sale could not be completed. Stock was not changed.', 'product-qrcode-barcode-generator' ),
			);
		}

		$undo = null;

		if ( SaleRepository::STATUS_COMPLETED === $sale['status'] && SaleService::can_undo( $sale, get_current_user_id() ) ) {
			$undo = array(
				'action'    => ScanUrl::site_url( $code ),
				'nonce'     => wp_create_nonce( Permissions::nonce_action( 'undo_' . $sale['id'] ) ),
				'sale_id'   => (string) $sale['id'],
				'until'     => wp_date( get_option( 'time_format' ), SaleService::undo_until( $sale ) ),
				// Phase 11: seconds left, for the CSS that hides the form when the window ends.
				'remaining' => max( 0, SaleService::undo_until( $sale ) - time() ),
			);
		}

		return self::view(
			200,
			array( $notice ),
			array(
				'code'        => $code,
				'sale'        => array(
					'status'     => $sale['status'],
					'name'       => $sale['product_name'],
					'attributes' => implode( ', ', $pairs ),
					'sku'        => (string) $sale['sku'],
					'quantity'   => number_format_i18n( (int) $sale['quantity'] ),
					'unit'       => self::plain_price( $sale['unit_price'], $args ),
					'total'      => self::plain_price( $sale['line_total'], $args ),
					'stock'      => SaleRepository::STATUS_COMPLETED === $sale['status'] && null !== $sale['stock_after'] ? number_format_i18n( (int) $sale['stock_after'] ) : '',
					'payment'    => PaymentMethods::label( $sale['payment_method'] ?? null ),
					'time'       => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), SaleService::created_ts( $sale ) ),
					// Phase 16: completed and voided sales have a receipt.
					'receipt'    => Receipt::has_receipt( $sale ) ? ScanUrl::receipt_url( (int) $sale['id'] ) : '',
				),
				'undo'        => $undo,
				'style_nonce' => null === $undo ? '' : self::style_nonce(),
				'box_label'   => __( 'Scan next item', 'product-qrcode-barcode-generator' ),
			)
		);
	}

	/**
	 * The product screen with the UPI payment panel instead of the sale form (Phase 16,
	 * built by UpiSale). If the item can no longer be sold, the product screen as it is
	 * now, with its notices.
	 *
	 * @param string               $code    Code.
	 * @param array<string, mixed> $prefill Quantity and payment method.
	 * @param array<string, mixed> $panel   QR markup (from QrRenderer, or '' when it failed), amount, payee, form.
	 * @return array<string, mixed>
	 */
	public static function upi_step( string $code, array $prefill, array $panel ): array {
		$view = self::resolve( $code, $prefill );

		if ( ! is_array( $view['sell'] ) ) {
			return $view;
		}

		$view['sell'] = null;
		$view['upi']  = $panel;
		$view['box']  = false; // No code box: Enter or a scanner must not leave the payment by accident.

		return $view;
	}

	/**
	 * A receipt (Phase 16). The caller has checked Receipt::can_see().
	 *
	 * @param array<string, mixed> $sale  Sale row with a receipt.
	 * @param string               $paper Key of Receipt::PAPERS.
	 * @return array<string, mixed>
	 */
	public static function receipt( array $sale, string $paper ): array {
		$data   = Receipt::data( $sale );
		$papers = array();

		foreach ( Receipt::papers() as $key => $label ) {
			$papers[] = array( ScanUrl::receipt_url( (int) $data['id'], $key ), $label, $key === $paper );
		}

		return self::view(
			200,
			array(),
			array(
				'box'          => false,
				'receipt'      => array(
					'data'     => $data,
					'paper'    => $paper,
					'papers'   => $papers,
					'whatsapp' => $data['void'] ? '' : Receipt::whatsapp_url( Receipt::text( $data ) ),
					'back'     => ScanUrl::site_url(),
				),
				'style_nonce'  => self::style_nonce(),
				'script_nonce' => self::style_nonce(),
			)
		);
	}

	/**
	 * The seller's open basket (Phase 17, D4): every line with current prices and stock,
	 * the "Scan to add" box, the quantity / remove / clear forms and, when every line can
	 * be sold, the confirm form (payment method and a signed token, BasketRequest).
	 *
	 * @param int                  $user_id Seller.
	 * @param array<string, mixed> $prefill Payment method to keep.
	 * @param array<string, mixed> $extra   notices (list), value (scan box), clear_confirm (bool).
	 * @return array<string, mixed>
	 */
	public static function basket( int $user_id, array $prefill = array(), array $extra = array() ): array {
		$basket  = BasketStore::get( $user_id );
		$items   = array();
		$stock   = array();
		$need    = array();
		$entries = array();
		$tokens  = array();
		$total   = 0.0;
		$bad     = 0;

		foreach ( $basket['lines'] as $i => $line ) {
			$item        = SaleService::check_item( CodeRepository::find_by_code( $line['code'] ) );
			$items[ $i ] = $item;

			if ( ! is_wp_error( $item ) ) {
				$holder_id = $item['holder']->get_id();

				if ( ! isset( $stock[ $holder_id ] ) ) {
					$read                = SaleRepository::read_stock( $holder_id );
					$stock[ $holder_id ] = null === $read ? 0 : (int) $read;
				}

				$need[ $holder_id ] = ( $need[ $holder_id ] ?? 0 ) + $line['quantity'];
			}
		}

		foreach ( $basket['lines'] as $i => $line ) {
			$item     = $items[ $i ];
			$quantity = $line['quantity'];
			$problem  = is_wp_error( $item ) ? $item->get_error_message() : '';
			$price    = is_wp_error( $item ) ? '' : SaleService::normalize_price( $item['product']->get_price() );
			$max      = $quantity;
			$note     = '';

			if ( ! is_wp_error( $item ) ) {
				$holder_id = $item['holder']->get_id();
				$max       = max( $quantity, $stock[ $holder_id ] - ( $need[ $holder_id ] - $quantity ) );

				if ( $stock[ $holder_id ] <= 0 ) {
					$problem = __( 'Out of stock – cannot be sold.', 'product-qrcode-barcode-generator' );
				} elseif ( $need[ $holder_id ] > $stock[ $holder_id ] ) {
					/* translators: %s: stock quantity. */
					$problem = sprintf( __( 'Only %s in stock.', 'product-qrcode-barcode-generator' ), number_format_i18n( $stock[ $holder_id ] ) );
				}

				if ( '' !== $line['price'] && SaleService::normalize_price( $line['price'] ) !== $price ) {
					/* translators: %s: the price when the item was added. */
					$note = sprintf( __( 'Price changed since added (was %s).', 'product-qrcode-barcode-generator' ), self::plain_price( $line['price'] ) );
				}
			}

			$line_total = '' === $price ? '0' : SaleService::line_total( $price, $quantity );
			$entries[]  = array(
				'code'     => $line['code'],
				'name'     => self::code_name( $line['code'] ),
				'quantity' => $quantity,
				'max'      => $max,
				'unit'     => '' === $price ? '–' : self::plain_price( $price ),
				'total'    => '' === $price ? '–' : self::plain_price( $line_total ),
				'problem'  => $problem,
				'note'     => $note,
				'url'      => ScanUrl::site_url( $line['code'] ),
			);

			if ( '' === $problem ) {
				$total   += (float) $line_total;
				$tokens[] = array(
					'code'     => $line['code'],
					'quantity' => $quantity,
					'price'    => $price,
				);
			} else {
				++$bad;
			}
		}

		$confirm = null;

		if ( array() !== $entries && 0 === $bad && BasketService::db_supported() ) {
			$methods = array_intersect_key( PaymentMethods::all(), array_flip( PaymentMethods::enabled() ) );
			$chosen  = isset( $prefill['payment_method'] ) && PaymentMethods::is_enabled( (string) $prefill['payment_method'] ) ? (string) $prefill['payment_method'] : '';
			$confirm = array(
				'action'  => ScanUrl::basket_url(),
				'nonce'   => wp_create_nonce( Permissions::nonce_action( 'basket_confirm' ) ),
				'fields'  => BasketRequest::issue_token( $user_id, $basket['request_id'], $basket['rev'], $tokens ),
				'methods' => $methods,
				'method'  => 1 === count( $methods ) ? (string) key( $methods ) : $chosen,
			);
		}

		$notices = isset( $extra['notices'] ) && is_array( $extra['notices'] ) ? $extra['notices'] : array();

		if ( ! BasketService::db_supported() ) {
			$notices[] = array( 'error', BasketService::unsupported()->get_error_message() );
		} elseif ( $bad > 0 ) {
			$notices[] = array( 'warning', __( 'Remove the items marked in red before confirming.', 'product-qrcode-barcode-generator' ) );
		}

		return self::view(
			200,
			$notices,
			array(
				'box'    => false,
				'basket' => array(
					'lines'         => $entries,
					'items'         => (int) array_sum( array_column( $basket['lines'], 'quantity' ) ),
					'total'         => self::plain_price( (string) $total ),
					'rev'           => (string) $basket['rev'],
					'action'        => ScanUrl::basket_url(),
					'scan_nonce'    => wp_create_nonce( Permissions::nonce_action( 'basket_scan' ) ),
					'edit_nonce'    => wp_create_nonce( Permissions::nonce_action( 'basket_edit' ) ),
					'value'         => isset( $extra['value'] ) ? (string) $extra['value'] : '',
					'confirm'       => $confirm,
					'clear_confirm' => ! empty( $extra['clear_confirm'] ) && array() !== $entries,
					'scan'          => BasketService::db_supported(),
				),
			)
		);
	}

	/**
	 * The basket with the UPI payment panel instead of the confirm form (Phase 17, D5).
	 * If the basket can no longer be confirmed, the basket as it is now.
	 *
	 * @param int                  $user_id Seller.
	 * @param array<string, mixed> $panel   QR markup, amount, payee, reference, form.
	 * @return array<string, mixed>
	 */
	public static function basket_upi( int $user_id, array $panel ): array {
		$view = self::basket( $user_id, array( 'payment_method' => PaymentMethods::UPI ) );

		if ( ! is_array( $view['basket']['confirm'] ) ) {
			return $view;
		}

		$view['basket']['confirm'] = null;
		$view['basket']['scan']    = false; // No scan box: Enter or a scanner must not leave the payment by accident.
		$view['upi']               = $panel;

		return $view;
	}

	/**
	 * A sold basket (Phase 17): its lines from the rows' snapshots, the receipt link and
	 * the Undo form within 10 minutes (D19).
	 *
	 * @param array<int, array<string, string>> $lines The basket's lines.
	 * @return array<string, mixed>
	 */
	public static function basket_sale( array $lines ): array {
		$lead     = $lines[0];
		$id       = BasketService::number( $lead );
		$statuses = array_values( array_unique( array_column( $lines, 'status' ) ) );
		$args     = array( 'currency' => $lead['currency'] );
		$entries  = array();
		$total    = 0.0;

		if ( array( SaleRepository::STATUS_COMPLETED ) === $statuses ) {
			$notice = array( 'success', __( 'Sold.', 'product-qrcode-barcode-generator' ) );
		} elseif ( array( SaleRepository::STATUS_VOIDED ) === $statuses ) {
			$when   = wp_date( get_option( 'time_format' ), (int) strtotime( $lead['voided_at_gmt'] . ' UTC' ) );
			$notice = array(
				'info',
				SaleService::VOID_REASON_UNDO === $lead['void_reason']
					/* translators: %s: time. */
					? sprintf( __( 'This sale was undone at %s. Stock was restored.', 'product-qrcode-barcode-generator' ), $when )
					/* translators: %s: time. */
					: sprintf( __( 'This sale was voided at %s.', 'product-qrcode-barcode-generator' ), $when ),
			);
		} elseif ( array() === array_diff( $statuses, array( SaleRepository::STATUS_COMPLETED, SaleRepository::STATUS_VOIDED ) ) ) {
			$notice = array( 'warning', __( 'This sale was only partly voided. Ask a manager to finish it.', 'product-qrcode-barcode-generator' ) );
		} else {
			$notice = array( 'error', __( 'The sale could not be completed. Stock was not changed.', 'product-qrcode-barcode-generator' ) );
		}

		foreach ( $lines as $line ) {
			$total    += (float) $line['line_total'];
			$entries[] = array(
				'name'   => SalePresenter::item( $line ),
				/* translators: 1: quantity, 2: unit price, 3: total. */
				'amount' => sprintf( __( '%1$s × %2$s = %3$s', 'product-qrcode-barcode-generator' ), number_format_i18n( (int) $line['quantity'] ), self::plain_price( $line['unit_price'], $args ), self::plain_price( $line['line_total'], $args ) ),
				'status' => (string) $line['status'],
				'label'  => 1 === count( $statuses ) ? '' : SalePresenter::status( (string) $line['status'] ),
			);
		}

		$undo = null;

		if ( BasketService::can_undo( $lines, get_current_user_id() ) ) {
			$undo = array(
				'action'    => ScanUrl::basket_url(),
				'nonce'     => wp_create_nonce( Permissions::nonce_action( 'basket_undo_' . $id ) ),
				'basket_id' => (string) $id,
				'until'     => wp_date( get_option( 'time_format' ), BasketService::undo_until( $lines ) ),
				'remaining' => max( 0, BasketService::undo_until( $lines ) - time() ),
			);
		}

		$receipt = array() !== array_intersect( $statuses, array( SaleRepository::STATUS_COMPLETED, SaleRepository::STATUS_VOIDED ) );

		return self::view(
			200,
			array( $notice ),
			array(
				'basket_sale' => array(
					'number'  => (string) $id,
					'lines'   => $entries,
					/* translators: %s: number of items. */
					'items'   => sprintf( _n( '%s item', '%s items', (int) array_sum( array_column( $lines, 'quantity' ) ), 'product-qrcode-barcode-generator' ), number_format_i18n( (int) array_sum( array_column( $lines, 'quantity' ) ) ) ),
					'total'   => self::plain_price( (string) $total, $args ),
					'payment' => PaymentMethods::label( $lead['payment_method'] ?? null ),
					'time'    => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), SaleService::created_ts( $lead ) ),
					'receipt' => $receipt ? ScanUrl::receipt_url( $id ) : '',
				),
				'undo'        => $undo,
				'style_nonce' => null === $undo ? '' : self::style_nonce(),
				'box_label'   => __( 'Scan next item', 'product-qrcode-barcode-generator' ),
			)
		);
	}

	/**
	 * The item behind a code as "Name – attributes", or the code itself when it is unknown.
	 *
	 * @param string $code Code.
	 */
	public static function code_name( string $code ): string {
		$row     = CodeRepository::find_by_code( $code );
		$product = null === $row ? null : wc_get_product( (int) $row['product_id'] );

		if ( ! $product instanceof WC_Product ) {
			return $code;
		}

		$parent = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : null;

		return self::display_name( $product, $parent instanceof WC_Product ? $parent : null );
	}

	/**
	 * The stock holder of a code's item (0 when unknown).
	 *
	 * @param string $code Code.
	 */
	public static function holder_of( string $code ): int {
		$row     = CodeRepository::find_by_code( $code );
		$product = null === $row ? null : wc_get_product( (int) $row['product_id'] );

		return $product instanceof WC_Product ? (int) $product->get_stock_managed_by_id() : 0;
	}

	/**
	 * The page for users without pqbg_sell on a selling page (the basket).
	 *
	 * @return array<string, mixed>
	 */
	public static function sell_forbidden(): array {
		return self::view( 403, array( array( 'error', __( 'You do not have permission to sell.', 'product-qrcode-barcode-generator' ) ) ), array( 'box' => false ) );
	}

	/**
	 * "Receipt not found": the same answer for a missing sale, a sale without a receipt and another seller's sale.
	 *
	 * @return array<string, mixed>
	 */
	public static function receipt_not_found(): array {
		return self::view( 404, array( array( 'error', __( 'Receipt not found.', 'product-qrcode-barcode-generator' ) ) ) );
	}

	/**
	 * The product screen's basket state (Phase 17, D3): whether "Add to basket" is offered
	 * and, while a basket is open, its summary and link (the form then only adds to it).
	 *
	 * @param int $user_id Seller.
	 * @return array{ok: bool, open: bool, text: string, url: string}
	 */
	private static function basket_bar( int $user_id ): array {
		$out = array(
			'ok'   => BasketService::db_supported(),
			'open' => false,
			'text' => '',
			'url'  => ScanUrl::basket_url(),
		);

		if ( ! $out['ok'] ) {
			return $out;
		}

		$lines = BasketStore::get( $user_id )['lines'];

		if ( array() === $lines ) {
			return $out;
		}

		$total = 0.0;

		foreach ( $lines as $line ) {
			$item   = SaleService::check_item( CodeRepository::find_by_code( $line['code'] ) );
			$total += is_wp_error( $item ) ? 0.0 : (float) SaleService::line_total( SaleService::normalize_price( $item['product']->get_price() ), $line['quantity'] );
		}

		$items = (int) array_sum( array_column( $lines, 'quantity' ) );

		$out['open'] = true;
		/* translators: 1: number of items, 2: total. */
		$out['text'] = sprintf( __( 'Basket: %1$s · %2$s', 'product-qrcode-barcode-generator' ), sprintf( /* translators: %s: number of items. */ _n( '%s item', '%s items', $items, 'product-qrcode-barcode-generator' ), number_format_i18n( $items ) ), self::plain_price( (string) $total ) );

		return $out;
	}

	/**
	 * The sale form for a sellable item.
	 *
	 * @param string                $code    Code.
	 * @param array<string, string> $row     Code row.
	 * @param WC_Product            $product Item.
	 * @param int                   $stock   The holder's stock, read from the database.
	 * @param array<string, mixed>  $prefill Quantity and payment method to keep (a form shown again).
	 * @return array<string, mixed>
	 */
	private static function sell_form( string $code, array $row, WC_Product $product, int $stock, array $prefill = array() ): array {
		$price   = SaleService::normalize_price( $product->get_price() );
		$unit    = self::plain_price( $price );
		$options = array();

		if ( $stock <= self::QUANTITY_LIST_MAX ) {
			for ( $qty = 1; $qty <= $stock; $qty++ ) {
				/* translators: 1: quantity, 2: unit price, 3: total. */
				$options[ $qty ] = sprintf( __( '%1$s × %2$s = %3$s', 'product-qrcode-barcode-generator' ), number_format_i18n( $qty ), $unit, self::plain_price( SaleService::line_total( $price, $qty ) ) );
			}
		}

		// Payment: required, nothing pre-selected unless only one method is offered (or the seller chose one before an error).
		$methods  = array_intersect_key( PaymentMethods::all(), array_flip( PaymentMethods::enabled() ) );
		$chosen   = isset( $prefill['payment_method'] ) && PaymentMethods::is_enabled( $prefill['payment_method'] ) ? (string) $prefill['payment_method'] : '';
		$quantity = isset( $prefill['quantity'] ) && is_int( $prefill['quantity'] ) && $prefill['quantity'] >= 1 && $prefill['quantity'] <= $stock ? $prefill['quantity'] : 1;

		return array(
			'action'   => ScanUrl::site_url( $code ),
			'nonce'    => wp_create_nonce( Permissions::nonce_action( 'sell_' . $row['id'] ) ),
			'fields'   => SaleRequest::issue_token( get_current_user_id(), (int) $row['id'], $price, $stock ),
			'options'  => $options,
			'max'      => $stock,
			'unit'     => $unit,
			'quantity' => $quantity,
			'methods'  => $methods,
			'method'   => 1 === count( $methods ) ? (string) key( $methods ) : $chosen,
		);
	}

	/**
	 * A price as plain text (e.g. "₹1,499.00") for places where HTML is not allowed.
	 *
	 * @param string               $amount Amount.
	 * @param array<string, mixed> $args   wc_price() arguments.
	 */
	private static function plain_price( string $amount, array $args = array() ): string {
		return trim( html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount, $args ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * The entry page, optionally with the invalid-code message and the rejected input.
	 *
	 * @param string $value   Input to prefill (escaped when rendered).
	 * @param bool   $invalid Whether the input was rejected.
	 * @return array<string, mixed>
	 */
	public static function entry( string $value = '', bool $invalid = false ): array {
		if ( $invalid ) {
			return self::invalid( $value );
		}

		return self::view( 200, array() );
	}

	/**
	 * "Not a valid product code." with the rejected input in the box.
	 *
	 * @param string $value Rejected input.
	 * @return array<string, mixed>
	 */
	public static function invalid( string $value ): array {
		return self::view(
			400,
			array( array( 'error', __( 'Not a valid product code.', 'product-qrcode-barcode-generator' ) ) ),
			array( 'value' => mb_substr( $value, 0, 100 ) )
		);
	}

	/**
	 * The page for users who may not view products. It never depends on the
	 * requested code, so it is identical for every code and for the entry page.
	 *
	 * @return array<string, mixed>
	 */
	public static function forbidden(): array {
		return self::view( 403, array( array( 'error', __( 'You do not have permission to view products.', 'product-qrcode-barcode-generator' ) ) ), array( 'box' => false ) );
	}

	/**
	 * My sales without pqbg_view_own_sales.
	 *
	 * @return array<string, mixed>
	 */
	public static function sales_forbidden(): array {
		return self::view( 403, array( array( 'error', __( 'You do not have permission to view sales.', 'product-qrcode-barcode-generator' ) ) ), array( 'box' => false ) );
	}

	/**
	 * The seller's own sales for a range: completed and voided lines (failed
	 * attempts changed no stock and are left out), newest first, and a summary of
	 * completed sales per payment method. Never shows cost or profit. The seller
	 * is the logged-in user, never a value from the request.
	 *
	 * @param int    $user_id Current user.
	 * @param string $range   Key of MY_SALES_RANGES.
	 * @return array<string, mixed>
	 */
	public static function my_sales( int $user_id, string $range ): array {
		$range   = array_key_exists( $range, self::MY_SALES_RANGES ) ? $range : 'today';
		$dates   = SalesQuery::range( self::MY_SALES_RANGES[ $range ] );
		$filters = array(
			'start'    => $dates['start'],
			'end'      => $dates['end'],
			'seller'   => max( 1, $user_id ),
			'statuses' => array( SaleRepository::STATUS_COMPLETED, SaleRepository::STATUS_VOIDED ),
			'orderby'  => 'date',
			'order'    => 'desc',
		);
		$labels  = array(
			'today'     => __( 'Today', 'product-qrcode-barcode-generator' ),
			'yesterday' => __( 'Yesterday', 'product-qrcode-barcode-generator' ),
			'7d'        => __( 'Last 7 days', 'product-qrcode-barcode-generator' ),
		);
		$totals  = SalesQuery::totals( $filters );
		$count   = SalesQuery::count( $filters );
		$time    = '7d' === $range ? 'M j, ' . get_option( 'time_format' ) : get_option( 'time_format' );
		$lines   = array();

		$baskets = array(); // Phase 17 (D25): basket number => position in $lines.

		foreach ( SalesQuery::rows( $filters, self::MY_SALES_LINES ) as $sale ) {
			$code   = (string) ( $sale['code'] ?? '' );
			/* translators: 1: quantity, 2: unit price, 3: total. */
			$amount = sprintf( __( '%1$s × %2$s = %3$s', 'product-qrcode-barcode-generator' ), number_format_i18n( (int) $sale['quantity'] ), SalePresenter::money( $sale['unit_price'], (string) $sale['currency'] ), SalePresenter::money( $sale['line_total'], (string) $sale['currency'] ) );

			if ( ! empty( $sale['basket_id'] ) ) {
				$number = (int) $sale['basket_id'];

				if ( ! isset( $baskets[ $number ] ) ) {
					$baskets[ $number ] = count( $lines );
					$lines[]            = array(
						'time'     => SalePresenter::datetime( $sale['created_at_gmt'], $time ),
						'method'   => PaymentMethods::label( $sale['payment_method'] ),
						'url'      => ScanUrl::basket_sale_url( $number ),
						'receipt'  => ScanUrl::receipt_url( $number ),
						'parts'    => array(),
						'sum'      => 0.0,
						'qty'      => 0,
						'currency' => (string) $sale['currency'],
						'statuses' => array(),
					);
				}

				$at                           = $baskets[ $number ];
				$lines[ $at ]['parts'][]      = array( SalePresenter::item( $sale ), $amount );
				$lines[ $at ]['sum']         += (float) $sale['line_total'];
				$lines[ $at ]['qty']         += (int) $sale['quantity'];
				$lines[ $at ]['statuses'][]   = (string) $sale['status'];
				continue;
			}

			$lines[] = array(
				'time'    => SalePresenter::datetime( $sale['created_at_gmt'], $time ),
				'item'    => SalePresenter::item( $sale ),
				'amount'  => $amount,
				'method'  => PaymentMethods::label( $sale['payment_method'] ),
				'status'  => (string) $sale['status'],
				'label'   => SalePresenter::status( (string) $sale['status'] ),
				'url'     => CodeGenerator::is_valid_format( $code ) ? SaleRequest::sale_url( $code, (int) $sale['id'] ) : '',
				'receipt' => ScanUrl::receipt_url( (int) $sale['id'] ), // Phase 16: completed and voided lines only, so every line has one.
				'parts'   => array(),
			);
		}

		foreach ( $baskets as $at ) {
			$statuses = array_values( array_unique( $lines[ $at ]['statuses'] ) );
			$status   = 1 === count( $statuses ) ? $statuses[0] : SaleRepository::STATUS_VOIDED;

			$lines[ $at ] = array(
				'time'    => $lines[ $at ]['time'],
				/* translators: %s: number of items. */
				'item'    => sprintf( _n( 'Sale of %s item', 'Sale of %s items', $lines[ $at ]['qty'], 'product-qrcode-barcode-generator' ), number_format_i18n( $lines[ $at ]['qty'] ) ),
				'amount'  => SalePresenter::money( (string) $lines[ $at ]['sum'], $lines[ $at ]['currency'] ),
				'method'  => $lines[ $at ]['method'],
				'status'  => $status,
				'label'   => 1 === count( $statuses ) ? SalePresenter::status( $status ) : __( 'Partly voided', 'product-qrcode-barcode-generator' ),
				'url'     => $lines[ $at ]['url'],
				'receipt' => $lines[ $at ]['receipt'],
				'parts'   => array_reverse( $lines[ $at ]['parts'] ), // Rows come newest first; list the lines in the order they were added.
			);
		}

		// Every offered method (even without sales), then any other method that has sales, then "Not recorded".
		$shown   = array_merge( array_fill_keys( PaymentMethods::enabled(), null ), $totals['methods'] );
		$summary = array();

		foreach ( array_merge( array_keys( PaymentMethods::all() ), array( '' ) ) as $key ) {
			if ( array_key_exists( $key, $shown ) ) {
				$summary[] = self::summary_line( PaymentMethods::label( '' === $key ? null : $key ), $shown[ $key ] );
			}
		}

		$day   = static fn( string $ymd ): string => (string) wp_date( get_option( 'date_format' ), ( new \DateTimeImmutable( $ymd . ' 12:00:00', wp_timezone() ) )->getTimestamp() );
		$title = $dates['from'] === $dates['to'] ? $day( $dates['from'] ) : $day( $dates['from'] ) . ' – ' . $day( $dates['to'] );
		$tabs  = array();

		foreach ( $labels as $key => $label ) {
			$tabs[] = array( ScanUrl::my_sales_url( $key ), $label, $key === $range );
		}

		return self::view(
			200,
			array(),
			array(
				'mine' => array(
					'tabs'    => $tabs,
					'title'   => $labels[ $range ] . ' – ' . $title,
					'summary' => $summary,
					'total'   => self::summary_line( __( 'Total', 'product-qrcode-barcode-generator' ), $totals['all'] ),
					'voided'  => (int) $totals['voided'],
					'lines'   => $lines,
					'more'    => max( 0, $count - count( $lines ) ),
					// 1.0.1: a plain link to the static seller guide PDF; the page's headers and CSP are unchanged.
					'guide'   => AdminUrl::seller_guide(),
				),
			)
		);
	}

	/**
	 * One summary line: label, "3 sales · 4 items" and the amount ('–' when none).
	 *
	 * @param string                    $label Label.
	 * @param array<string, mixed>|null $total A total from SalesQuery::totals(), or null.
	 * @return array{0: string, 1: string, 2: string}
	 */
	private static function summary_line( string $label, ?array $total ): array {
		if ( null === $total || 0 === $total['count'] ) {
			return array( $label, '', '–' );
		}

		/* translators: %s: number of sales. */
		$sales = sprintf( _n( '%s sale', '%s sales', $total['count'], 'product-qrcode-barcode-generator' ), number_format_i18n( $total['count'] ) );
		/* translators: %s: number of items. */
		$items = sprintf( _n( '%s item', '%s items', $total['items'], 'product-qrcode-barcode-generator' ), number_format_i18n( $total['items'] ) );

		return array( $label, $sales . ' · ' . $items, SalePresenter::money( $total['revenue'] ) );
	}

	/**
	 * Answer to a request method other than GET or HEAD.
	 *
	 * @return array<string, mixed>
	 */
	public static function method_not_allowed(): array {
		return self::view( 405, array( array( 'error', __( 'This page can only be opened, not submitted to.', 'product-qrcode-barcode-generator' ) ) ), array( 'box' => false ) );
	}

	/**
	 * Renders a view with the plugin template.
	 *
	 * @param array<string, mixed> $view View from one of the builders above.
	 */
	public static function render( array $view ): string {
		wp_register_style( self::STYLE_HANDLE, PQBG_PLUGIN_URL . 'assets/pqbg-scan.css', array(), PQBG_VERSION );

		$entry_url  = ScanUrl::site_url();
		$logout_url = wp_logout_url( $entry_url );
		$sales_url  = Permissions::can_view_own_sales() ? ScanUrl::my_sales_url() : '';
		$basket_url = '';

		// Phase 17: a header link to the open basket on every other scan page.
		if ( ! is_array( $view['basket'] ?? null ) && ! is_array( $view['receipt'] ?? null ) && Permissions::can_sell() && BasketService::db_supported() && array() !== BasketStore::get( get_current_user_id() )['lines'] ) {
			$basket_url = ScanUrl::basket_url();
		}

		// Phase 16: the receipt has its own standalone template and stylesheet.
		if ( is_array( $view['receipt'] ?? null ) ) {
			wp_register_style( self::RECEIPT_STYLE_HANDLE, PQBG_PLUGIN_URL . 'assets/pqbg-receipt.css', array(), PQBG_VERSION );
		}

		ob_start();
		require PQBG_PLUGIN_DIR . ( is_array( $view['receipt'] ?? null ) ? 'templates/pqbg-receipt.php' : 'templates/pqbg-scan.php' );
		return (string) ob_get_clean();
	}

	/**
	 * Retired code: "out of date", with the item's name and a reprint link for code managers.
	 *
	 * @param string          $code    Code.
	 * @param WC_Product|null $product Item, or null when deleted.
	 * @param WC_Product|null $parent  Variation's parent.
	 * @return array<string, mixed>
	 */
	private static function retired( string $code, ?WC_Product $product, ?WC_Product $parent ): array {
		if ( ! $product ) {
			return self::no_longer_valid( $code, true );
		}

		$links     = array();
		$target    = $parent ?? $product;
		$in_trash  = 'trash' === $product->get_status() || 'trash' === $target->get_status();
		$edit_link = ( ! $in_trash && Permissions::can_manage_codes() ) ? get_edit_post_link( $target->get_id(), 'raw' ) : '';

		if ( is_string( $edit_link ) && '' !== $edit_link ) {
			$links[] = array( $edit_link, __( 'Open the product to reprint its label', 'product-qrcode-barcode-generator' ) );
		}

		return self::view(
			200,
			array( array( 'error', __( 'This label is out of date.', 'product-qrcode-barcode-generator' ) ) ),
			array(
				'code'    => $code,
				'summary' => array(
					'name' => self::display_name( $product, $parent ),
					'sku'  => '',
				),
				'links'   => $links,
			)
		);
	}

	/**
	 * The item behind the code no longer exists.
	 *
	 * @param string $code    Code.
	 * @param bool   $retired Whether the code is retired (adds "out of date").
	 * @return array<string, mixed>
	 */
	private static function no_longer_valid( string $code, bool $retired ): array {
		$notices = array();

		if ( $retired ) {
			$notices[] = array( 'error', __( 'This label is out of date.', 'product-qrcode-barcode-generator' ) );
		}

		$notices[] = array( 'error', __( 'This label is no longer valid.', 'product-qrcode-barcode-generator' ) );

		return self::view( 200, $notices, array( 'code' => $code ) );
	}

	/**
	 * Display details of a live item. Plain values; the template escapes them.
	 *
	 * @param WC_Product      $product Simple product or variation.
	 * @param WC_Product|null $parent  Variation's parent.
	 * @return array<string, mixed>
	 */
	private static function product_details( WC_Product $product, ?WC_Product $parent ): array {
		$stock_labels = array(
			'instock'     => __( 'In stock', 'product-qrcode-barcode-generator' ),
			'outofstock'  => __( 'Out of stock', 'product-qrcode-barcode-generator' ),
			'onbackorder' => __( 'On backorder', 'product-qrcode-barcode-generator' ),
		);
		$stock_status = (string) $product->get_stock_status();
		$image_id     = (int) $product->get_image_id(); // A variation without an image falls back to its parent's.
		$categories   = wc_get_product_terms( $parent ? $parent->get_id() : $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );

		return array(
			'name'        => $parent ? $parent->get_name() : $product->get_name(),
			'attributes'  => $parent ? wc_get_formatted_variation( $product, true, true, false ) : '',
			'sku'         => (string) $product->get_sku(),
			'price_html'  => self::price_html( $product ),
			'stock'       => $stock_labels[ $stock_status ] ?? $stock_status,
			'stock_qty'   => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
			'categories'  => is_array( $categories ) ? array_map( 'strval', $categories ) : array(),
			'image_html'  => $image_id > 0 ? (string) wp_get_attachment_image(
				$image_id,
				'woocommerce_thumbnail',
				false,
				array(
					'loading'  => 'lazy',
					'decoding' => 'async',
					'class'    => 'pqbg-scan__image',
				)
			) : '',
		);
	}

	/**
	 * Current price formatted by WooCommerce (INR), with the regular price struck
	 * through when on sale. '' when the item has no price.
	 *
	 * @param WC_Product $product Item.
	 */
	private static function price_html( WC_Product $product ): string {
		if ( '' === (string) $product->get_price() ) {
			return '';
		}

		if ( $product->is_on_sale() && '' !== (string) $product->get_regular_price() ) {
			return wc_format_sale_price(
				wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) ),
				wc_get_price_to_display( $product )
			);
		}

		return wc_price( wc_get_price_to_display( $product ) );
	}

	/**
	 * Name for the short screens: the product name, or "Parent – attributes" for a variation.
	 *
	 * @param WC_Product      $product Item.
	 * @param WC_Product|null $parent  Variation's parent.
	 */
	private static function display_name( WC_Product $product, ?WC_Product $parent ): string {
		if ( ! $parent ) {
			return $product->get_name();
		}

		$attributes = wc_get_formatted_variation( $product, true, true, false );

		return '' === trim( $attributes ) ? $parent->get_name() : $parent->get_name() . ' – ' . $attributes;
	}

	/**
	 * Builds a view with defaults.
	 *
	 * @param int                  $status  HTTP status.
	 * @param array<int, array>    $notices Notices.
	 * @param array<string, mixed> $extra   Other keys.
	 * @return array<string, mixed>
	 */
	private static function view( int $status, array $notices, array $extra = array() ): array {
		return array_merge(
			array(
				'status'      => $status,
				'notices'     => $notices,
				'product'     => null,
				'summary'     => null,
				'code'        => '',
				'links'       => array(),
				'box'         => true,
				'value'       => '',
				'placeholder' => CodeGenerator::example_code(),
				'sell'        => null,
				'sale'        => null,
				'undo'        => null,
				'box_label'   => '',
				'mine'         => null,
				'style_nonce'  => '',
				'upi'          => null,
				'receipt'      => null,
				'script_nonce' => '',
				'basket'       => null,
				'basket_sale'  => null,
			),
			$extra
		);
	}

	/**
	 * A fresh CSP nonce for the page's one style element (Phase 11: the Undo expiry delay;
	 * Phase 16: the receipt's @page rules) or the receipt's one script element.
	 * ScanRoute adds it to the Content-Security-Policy of that response only.
	 */
	private static function style_nonce(): string {
		return rtrim( strtr( base64_encode( random_bytes( 18 ) ), '+/', '-_' ), '=' );
	}
}
