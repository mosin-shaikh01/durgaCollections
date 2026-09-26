<?php
/**
 * Optional cost price of products and variations (Phase 9A), and its snapshot
 * on every in-store sale (pqbg_sales.unit_cost).
 *
 * Storage: post meta META_KEY ('_pqbg_cost_price', protected), a decimal string
 * normalised to the store's price decimals; no meta means "unknown".
 *   - simple product or variation: its cost price
 *   - variable product: the DEFAULT for its variations that have none of their own
 * Effective cost of a variation = its own, else the parent's default, else unknown.
 * Unknown cost means profit "unknown", never zero. A later change never touches
 * past sales, which keep their snapshot.
 *
 * Visibility: only users with pqbg_view_costs (administrators) ever see or edit
 * it, and only on the classic product edit screen. Everything else is closed:
 *   - read_meta filter: the key never enters any WooCommerce object's meta_data,
 *     so it is not in the WC REST API (products/variations), the product CSV
 *     exporter, Duplicate, or anything else built on WC_Data meta, for anyone;
 *   - add/update guards: only CostPrice::set() can write the key, so REST
 *     meta_data, the product CSV importer, the WordPress importer (WXR) and other
 *     code cannot;
 *   - delete guard: a logged-in user without pqbg_view_costs cannot delete a single
 *     product's cost through the meta API. Permanent post deletion (which removes
 *     meta by ID, delete_post_metadata_by_mid, never hooked) and bulk removal
 *     ($delete_all, e.g. the delete-all uninstall) are never blocked;
 *   - WXR export (Tools → Export) skips the key for everyone: the importer could
 *     not write it back anyway, so an exported cost could only leak.
 * The scan screens, labels, My sales, the Store API and storefront never read it.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WC_Admin_Meta_Boxes;
use WC_Product;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Cost price field, validation, guards and the effective cost of an item.
 */
final class CostPrice {

	const META_KEY = '_pqbg_cost_price';

	/** Field names on the product edit screen. */
	const FIELD         = 'pqbg_cost_price';
	const DEFAULT_FIELD = 'pqbg_cost_price_default';

	/** Largest accepted cost (12 integer digits; the sales column is decimal(26,8)). */
	const MAX_INTEGER_DIGITS = 12;

	/** True only while set() writes, so the guards let exactly that write through. */
	private static bool $writing = false;

	/**
	 * Hooks used on every request (REST, cron, importers and exports run outside wp-admin too).
	 */
	public static function register(): void {
		add_filter( 'woocommerce_data_store_wp_post_read_meta', array( __CLASS__, 'hide_from_meta_data' ), 10, 1 );
		add_filter( 'add_post_metadata', array( __CLASS__, 'guard_write' ), 10, 3 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'guard_write' ), 10, 3 );
		add_filter( 'delete_post_metadata', array( __CLASS__, 'guard_delete' ), 10, 5 );
		add_filter( 'wxr_export_skip_postmeta', array( __CLASS__, 'skip_in_wxr' ), 10, 2 );
	}

	/**
	 * Hooks used on admin requests (the edit screen and the variations AJAX).
	 */
	public static function register_admin(): void {
		add_action( 'woocommerce_product_options_pricing', array( __CLASS__, 'render_simple_field' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_default_field' ) );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'render_variation_field' ), 10, 3 );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product' ) );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'save_variation' ), 10, 2 );
	}

	/**
	 * The stored cost of one product/variation ('' when unknown). Read straight
	 * from post meta (never through WC_Data, which does not see the key).
	 *
	 * @param int $post_id Product or variation ID.
	 */
	public static function get( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return '';
		}

		$value = get_post_meta( $post_id, self::META_KEY, true );

		return is_string( $value ) && is_numeric( $value ) ? (string) wc_format_decimal( $value, wc_get_price_decimals() ) : '';
	}

	/**
	 * The effective cost of a sellable item: its own, else (for a variation) the
	 * parent's default; null when unknown.
	 *
	 * @param WC_Product      $product Simple product or variation.
	 * @param WC_Product|null $parent  Variation's parent.
	 */
	public static function effective( WC_Product $product, ?WC_Product $parent = null ): ?string {
		$own = self::get( $product->get_id() );

		if ( '' !== $own ) {
			return $own;
		}

		$default = $parent ? self::get( $parent->get_id() ) : '';

		return '' === $default ? null : $default;
	}

	/**
	 * Validates and normalises a submitted cost: '' (unknown) or a decimal >= 0
	 * with at most the store's price decimals, written with the store's decimal
	 * separator and no thousands separators.
	 *
	 * @param mixed $raw Submitted value.
	 * @return string|WP_Error Normalised decimal string, or '' for "unknown".
	 */
	public static function normalize( $raw ) {
		if ( ! is_string( $raw ) && ! is_int( $raw ) && ! is_float( $raw ) ) {
			return self::invalid();
		}

		$value    = trim( (string) $raw );
		$decimals = wc_get_price_decimals();

		if ( '' === $value ) {
			return '';
		}

		$separator = wc_get_price_decimal_separator();

		if ( '.' !== $separator && '' !== $separator ) {
			$value = str_replace( $separator, '.', $value );
		}

		$pattern = $decimals > 0
			? '/^[0-9]{1,' . self::MAX_INTEGER_DIGITS . '}(\.[0-9]{0,' . $decimals . '})?$/D'
			: '/^[0-9]{1,' . self::MAX_INTEGER_DIGITS . '}$/D';

		if ( 1 !== preg_match( $pattern, $value ) ) {
			return self::invalid();
		}

		return (string) wc_format_decimal( $value, $decimals );
	}

	/**
	 * Writes (or with '' deletes) a cost. The only writer of META_KEY. No capability
	 * check here: callers check pqbg_view_costs.
	 *
	 * @param int    $post_id Product or variation ID.
	 * @param string $value   Normalised value from normalize(), or ''.
	 */
	public static function set( int $post_id, string $value ): void {
		self::$writing = true;

		try {
			if ( '' === $value ) {
				delete_post_meta( $post_id, self::META_KEY );
			} else {
				update_post_meta( $post_id, self::META_KEY, $value );
			}
		} finally {
			self::$writing = false;
		}
	}

	/**
	 * woocommerce_data_store_wp_post_read_meta: keeps the key out of every WC_Data object's meta_data.
	 *
	 * @param array<int|string, object> $meta_data Meta rows (objects with meta_key).
	 * @return array<int|string, object>
	 */
	public static function hide_from_meta_data( $meta_data ) {
		if ( ! is_array( $meta_data ) ) {
			return $meta_data;
		}

		return array_filter( $meta_data, static fn( $meta ) => ! ( is_object( $meta ) && isset( $meta->meta_key ) && self::META_KEY === $meta->meta_key ) );
	}

	/**
	 * add_post_metadata / update_post_metadata: only set() may write the key.
	 *
	 * @param mixed  $check    Short-circuit value (null to continue).
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return mixed
	 */
	public static function guard_write( $check, $post_id, $meta_key ) {
		return self::META_KEY === $meta_key && ! self::$writing ? false : $check;
	}

	/**
	 * delete_post_metadata: a logged-in user without pqbg_view_costs cannot delete one
	 * item's cost. Bulk removal ($delete_all) and requests without a user (cron,
	 * CLI, uninstall) pass; permanent post deletion never comes here.
	 *
	 * @param mixed  $check      Short-circuit value (null to continue).
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @param bool   $delete_all Whether all matching rows of every post are deleted.
	 * @return mixed
	 */
	public static function guard_delete( $check, $post_id, $meta_key, $meta_value = '', $delete_all = false ) {
		if ( self::META_KEY !== $meta_key || self::$writing || $delete_all || get_current_user_id() <= 0 || Permissions::can_view_costs() ) {
			return $check;
		}

		return false;
	}

	/**
	 * wxr_export_skip_postmeta: never export the cost (for anyone; see the class notes).
	 *
	 * @param bool   $skip     Whether to skip the row.
	 * @param string $meta_key Meta key.
	 */
	public static function skip_in_wxr( $skip, $meta_key ) {
		return self::META_KEY === $meta_key ? true : $skip;
	}

	/**
	 * Simple product: "Cost price (₹)" in the General tab's pricing block.
	 */
	public static function render_simple_field(): void {
		global $post;

		if ( ! Permissions::can_view_costs() || ! $post instanceof \WP_Post ) {
			return;
		}

		woocommerce_wp_text_input(
			array(
				'id'            => self::FIELD,
				'label'         => self::label( __( 'Cost price (%s)', 'product-qrcode-barcode-generator' ) ),
				'value'         => wc_format_localized_price( self::get( (int) $post->ID ) ),
				'data_type'     => 'price',
				'wrapper_class' => 'show_if_simple', // The pricing block is also shown for external products, which are never sold here.
				'desc_tip'      => true,
				'description'   => __( 'Optional. Only administrators see this. Used for profit in In-store sales. Leave empty if unknown.', 'product-qrcode-barcode-generator' ),
			)
		);
	}

	/**
	 * Variable product: the default cost for variations, in the General tab.
	 */
	public static function render_default_field(): void {
		global $post;

		if ( ! Permissions::can_view_costs() || ! $post instanceof \WP_Post ) {
			return;
		}

		echo '<div class="options_group show_if_variable">';
		woocommerce_wp_text_input(
			array(
				'id'          => self::DEFAULT_FIELD,
				'label'       => self::label( __( 'Default cost price (%s)', 'product-qrcode-barcode-generator' ) ),
				'value'       => wc_format_localized_price( self::get( (int) $post->ID ) ),
				'data_type'   => 'price',
				'desc_tip'    => true,
				'description' => __( 'Optional. Used for variations without their own cost price. Only administrators see this.', 'product-qrcode-barcode-generator' ),
			)
		);
		echo '</div>';
	}

	/**
	 * Variation: "Cost price (₹)" after the price fields, with the parent's default as placeholder.
	 *
	 * @param int      $loop           Variation index.
	 * @param array    $variation_data Unused.
	 * @param \WP_Post $variation      Variation post.
	 */
	public static function render_variation_field( $loop, $variation_data, $variation ): void {
		if ( ! Permissions::can_view_costs() || ! $variation instanceof \WP_Post ) {
			return;
		}

		$default = self::get( (int) $variation->post_parent );

		woocommerce_wp_text_input(
			array(
				'id'            => self::FIELD . '_' . (int) $loop,
				'name'          => self::FIELD . '[' . (int) $loop . ']',
				'label'         => self::label( __( 'Cost price (%s)', 'product-qrcode-barcode-generator' ) ),
				'value'         => wc_format_localized_price( self::get( (int) $variation->ID ) ),
				'data_type'     => 'price',
				/* translators: %s: default cost price with currency symbol. */
				'placeholder'   => '' === $default ? '' : sprintf( __( 'Default: %s', 'product-qrcode-barcode-generator' ), self::plain_price( $default ) ),
				'wrapper_class' => 'form-row form-row-full',
				'desc_tip'      => true,
				'description'   => __( 'Optional. Empty uses the product\'s default cost price. Only administrators see this.', 'product-qrcode-barcode-generator' ),
			)
		);
	}

	/**
	 * woocommerce_admin_process_product_object: saves the simple product's cost or
	 * the variable product's default. WooCommerce has verified its nonce and the
	 * user's edit capability; the field is saved only for pqbg_view_costs and only
	 * when it was on the form, so other users' saves never touch it.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public static function save_product( $product ): void {
		if ( ! $product instanceof WC_Product || ! Permissions::can_view_costs() ) {
			return;
		}

		$field = $product->is_type( 'variable' ) ? self::DEFAULT_FIELD : ( $product->is_type( 'simple' ) ? self::FIELD : '' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified woocommerce_meta_nonce before this action.
		if ( '' === $field || ! isset( $_POST[ $field ] ) || ! is_string( $_POST[ $field ] ) ) {
			return;
		}

		self::save( $product->get_id(), wp_unslash( $_POST[ $field ] ), '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by WooCommerce; validated by normalize().
	}

	/**
	 * woocommerce_admin_process_variation_object: saves one variation's cost (classic
	 * form or the variations AJAX save, whose nonce WooCommerce has verified).
	 *
	 * @param WC_Product $variation Variation being saved.
	 * @param int        $index     Its index in the submitted arrays.
	 */
	public static function save_variation( $variation, $index ): void {
		if ( ! $variation instanceof WC_Product || ! Permissions::can_view_costs() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the save-variations nonce.
		if ( ! isset( $_POST[ self::FIELD ][ $index ] ) || ! is_string( $_POST[ self::FIELD ][ $index ] ) ) {
			return;
		}

		/* translators: %d: variation ID. */
		self::save( $variation->get_id(), wp_unslash( $_POST[ self::FIELD ][ $index ] ), sprintf( __( 'Variation #%d: ', 'product-qrcode-barcode-generator' ), $variation->get_id() ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by WooCommerce; validated by normalize().
	}

	/**
	 * Validates and writes a submitted value; an invalid one keeps the previous value and shows an admin error.
	 *
	 * @param int    $post_id Product or variation ID.
	 * @param string $raw     Submitted value.
	 * @param string $prefix  Error message prefix.
	 */
	private static function save( int $post_id, string $raw, string $prefix ): void {
		$value = self::normalize( $raw );

		if ( is_wp_error( $value ) ) {
			if ( class_exists( WC_Admin_Meta_Boxes::class ) ) {
				WC_Admin_Meta_Boxes::add_error( $prefix . $value->get_error_message() );
			}
			return;
		}

		if ( $value !== self::get( $post_id ) ) {
			self::set( $post_id, $value );
		}
	}

	/**
	 * A label with the currency symbol, e.g. "Cost price (₹)".
	 *
	 * @param string $format Label with one %s.
	 */
	private static function label( string $format ): string {
		return sprintf( $format, html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * A price as plain text, e.g. "₹450.00".
	 *
	 * @param string $amount Amount.
	 */
	private static function plain_price( string $amount ): string {
		return trim( html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Error for an unusable cost.
	 */
	private static function invalid(): WP_Error {
		return new WP_Error(
			'pqbg_invalid_cost',
			/* translators: %d: number of decimals. */
			sprintf( __( 'The cost price must be empty or a number of 0 or more with at most %d decimals, without thousands separators (e.g. 1499.00). The previous value was kept.', 'product-qrcode-barcode-generator' ), wc_get_price_decimals() )
		);
	}
}
