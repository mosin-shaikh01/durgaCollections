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
					$sell = self::sell_form( $code, $row, $item['product'], (int) $stock, $prefill );
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
				'action'  => ScanUrl::site_url( $code ),
				'nonce'   => wp_create_nonce( Permissions::nonce_action( 'undo_' . $sale['id'] ) ),
				'sale_id' => (string) $sale['id'],
				'until'   => wp_date( get_option( 'time_format' ), SaleService::undo_until( $sale ) ),
			);
		}

		return self::view(
			200,
			array( $notice ),
			array(
				'code'      => $code,
				'sale'      => array(
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
				),
				'undo'      => $undo,
				'box_label' => __( 'Scan next item', 'product-qrcode-barcode-generator' ),
			)
		);
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

		foreach ( SalesQuery::rows( $filters, self::MY_SALES_LINES ) as $sale ) {
			$code    = (string) ( $sale['code'] ?? '' );
			$lines[] = array(
				'time'   => SalePresenter::datetime( $sale['created_at_gmt'], $time ),
				'item'   => SalePresenter::item( $sale ),
				/* translators: 1: quantity, 2: unit price, 3: total. */
				'amount' => sprintf( __( '%1$s × %2$s = %3$s', 'product-qrcode-barcode-generator' ), number_format_i18n( (int) $sale['quantity'] ), SalePresenter::money( $sale['unit_price'], (string) $sale['currency'] ), SalePresenter::money( $sale['line_total'], (string) $sale['currency'] ) ),
				'method' => PaymentMethods::label( $sale['payment_method'] ),
				'status' => (string) $sale['status'],
				'label'  => SalePresenter::status( (string) $sale['status'] ),
				'url'    => CodeGenerator::is_valid_format( $code ) ? SaleRequest::sale_url( $code, (int) $sale['id'] ) : '',
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

		ob_start();
		require PQBG_PLUGIN_DIR . 'templates/pqbg-scan.php';
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
				'status'    => $status,
				'notices'   => $notices,
				'product'   => null,
				'summary'   => null,
				'code'      => '',
				'links'     => array(),
				'box'       => true,
				'value'     => '',
				'sell'      => null,
				'sale'      => null,
				'undo'      => null,
				'box_label' => '',
				'mine'      => null,
			),
			$extra
		);
	}
}
