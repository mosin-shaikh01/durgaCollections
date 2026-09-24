<?php
/**
 * What the scan page shows for a code: resolves the code to a screen (the
 * approved status → screen matrix) and renders the plugin's own template.
 *
 * Read-only. Product data is read live from WooCommerce on every request and
 * nothing is cached or written. Only display data is collected: no cost,
 * supplier, notes or customer data.
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

	/**
	 * The screen for a well-formed code.
	 *
	 * @param string $code Code matching CodeGenerator::FORMAT_PATTERN.
	 * @return array<string, mixed>
	 */
	public static function resolve( string $code ): array {
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

		return self::view(
			200,
			$notices,
			array(
				'code'    => $code,
				'product' => self::product_details( $product, $parent ),
				'links'   => $links,
			)
		);
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
				'status'  => $status,
				'notices' => $notices,
				'product' => null,
				'summary' => null,
				'code'    => '',
				'links'   => array(),
				'box'     => true,
				'value'   => '',
			),
			$extra
		);
	}
}
