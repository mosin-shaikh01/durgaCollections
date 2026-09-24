<?php
/**
 * Keeps product codes in step with the WooCommerce product lifecycle.
 *
 * Saves: WooCommerce CRUD hooks (woocommerce_new_product, woocommerce_update_product
 * and the _variation equivalents). They fire from the product data stores after
 * WooCommerce has written the object, its type and its parent, on every path that
 * uses WC_Product::save(): the classic edit screen, "Add variation" and "Save
 * changes" in the variations panel (AJAX), Quick Edit, Bulk Edit, the CSV importer,
 * the REST API and Duplicate. They do NOT fire for the auto-draft WordPress creates
 * for the "Add new" screen. save_post was rejected: it fires for auto-drafts and
 * revisions, and on the edit screen before WooCommerce has written the product type.
 *
 * On a save:
 *   - an eligible item without a code gets one, if the current user has
 *     pqbg_manage_codes (otherwise nothing happens: no code, no error);
 *   - a variable product also gives its variations that lack a code their code
 *     (the "parent sweep": covers first save, simple -> variable, duplicates and
 *     imports where variations arrive before the parent);
 *   - a product that is no longer eligible (simple -> variable/grouped/external)
 *     has its active code retired.
 * Skipped: auto-drafts, trashed items, the CSV importer's "importing" placeholders,
 * variations whose parent is in one of those states, and revisions.
 *
 * Deletion: WordPress core `deleted_post`, which fires after the row is gone on every
 * permanent-delete path (post.php, Empty Trash, WooCommerce data stores, REST force,
 * auto-draft cleanup). WooCommerce deletes a variable product's variations, and the
 * variations of a product that stops being variable, with wp_delete_post(), so each
 * variation passes through here too. Trash and untrash change nothing: the code stays
 * active so a restored product keeps working labels.
 *
 * Retirement (delete, type change) is not gated by pqbg_manage_codes; see
 * ProductCodeService::retire_for_item(). Generation always is.
 *
 * A failed generation never blocks or changes the save: the error code is logged and
 * the saving user sees a dismissible notice on their next admin page.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WC_Product;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Product lifecycle hooks.
 */
final class CodeLifecycle {

	/** Statuses whose items never get a code on save. */
	const SKIP_STATUSES = array( 'auto-draft', 'trash', 'importing' );

	/** User transient holding the last save failure's error code. */
	const NOTICE_TRANSIENT = 'pqbg_save_failure_';

	/**
	 * Code service.
	 *
	 * @var ProductCodeService|null
	 */
	private static $service = null;

	/**
	 * Hooks the lifecycle. Runs on every request (REST, import and cron are not admin requests).
	 */
	public static function register(): void {
		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_product_saved' ), 20, 2 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_saved' ), 20, 2 );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'on_variation_saved' ), 20, 2 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'on_variation_saved' ), 20, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'on_deleted_post' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'failure_notice' ) );
	}

	/**
	 * Replaces the code service (tests inject a failing generator). Null restores the default.
	 *
	 * @param ProductCodeService|null $service Service.
	 */
	public static function set_service( ?ProductCodeService $service ): void {
		self::$service = $service;
	}

	/**
	 * Product (non-variation) saved.
	 *
	 * @param int             $product_id Product ID.
	 * @param WC_Product|null $product    Saved product.
	 */
	public static function on_product_saved( $product_id, $product = null ): void {
		$product_id = (int) $product_id;
		$product    = $product instanceof WC_Product ? $product : wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product || $product_id <= 0 || wp_is_post_revision( $product_id ) ) {
			return;
		}

		$user_id = get_current_user_id();

		// Type change: an item that is no longer simple must not keep its code.
		if ( ! $product->is_type( 'simple' ) ) {
			ProductCodeService::retire_for_item( $product_id, $user_id );
		}

		if ( in_array( $product->get_status(), self::SKIP_STATUSES, true ) || ! Permissions::can_manage_codes( $user_id ) ) {
			return;
		}

		if ( $product->is_type( 'simple' ) ) {
			self::assign( $product_id, $user_id );
		} elseif ( $product->is_type( 'variable' ) ) {
			self::sweep_variations( $product_id, $user_id );
		}
	}

	/**
	 * Variation saved.
	 *
	 * @param int             $variation_id Variation ID.
	 * @param WC_Product|null $variation    Saved variation.
	 */
	public static function on_variation_saved( $variation_id, $variation = null ): void {
		$variation_id = (int) $variation_id;
		$user_id      = get_current_user_id();

		if ( $variation_id <= 0 || ! Permissions::can_manage_codes( $user_id ) ) {
			return;
		}

		$variation = $variation instanceof WC_Product ? $variation : wc_get_product( $variation_id );

		if ( ! $variation instanceof WC_Product || in_array( $variation->get_status(), self::SKIP_STATUSES, true ) ) {
			return;
		}

		$parent_status = get_post_status( $variation->get_parent_id() );

		if ( false === $parent_status || in_array( $parent_status, self::SKIP_STATUSES, true ) ) {
			return; // The parent sweep assigns the code when the parent is really saved.
		}

		// Ineligible (e.g. the parent is not variable in the database yet): stays without a code, silently.
		if ( ProductCodeService::is_eligible( $variation_id ) ) {
			self::assign( $variation_id, $user_id );
		}
	}

	/**
	 * Permanently deleted post: retire the code of a deleted product or variation,
	 * and any variation codes still active under a deleted product.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Deleted post.
	 */
	public static function on_deleted_post( $post_id, $post = null ): void {
		$post_type = $post instanceof WP_Post ? $post->post_type : '';

		if ( 'product' !== $post_type && 'product_variation' !== $post_type ) {
			return;
		}

		$post_id = (int) $post_id;
		$user_id = get_current_user_id();

		ProductCodeService::retire_for_item( $post_id, $user_id );

		if ( 'product' === $post_type ) {
			// Safety net: WooCommerce deletes variations one by one first, so this is normally empty.
			foreach ( CodeRepository::find_active_by_parent( $post_id ) as $row ) {
				ProductCodeService::retire_for_item( (int) $row['product_id'], $user_id );
			}
		}
	}

	/**
	 * Gives a variable product's saved variations their missing codes.
	 *
	 * @param int $parent_id Variable product ID.
	 * @param int $user_id   Acting user.
	 */
	private static function sweep_variations( int $parent_id, int $user_id ): void {
		$ids = get_posts(
			array(
				'post_parent'      => $parent_id,
				'post_type'        => 'product_variation',
				'post_status'      => array( 'publish', 'private' ),
				'fields'           => 'ids',
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);

		$ids = array_map( 'intval', $ids );

		if ( array() === $ids ) {
			return;
		}

		$have = CodeRepository::find_active_for_products( $ids );

		foreach ( $ids as $id ) {
			if ( ! isset( $have[ $id ] ) ) {
				self::assign( $id, $user_id );
			}
		}
	}

	/**
	 * Assigns a code; on failure logs the error code and queues a notice. Never throws.
	 *
	 * @param int $product_id Item ID.
	 * @param int $user_id    Acting user.
	 */
	private static function assign( int $product_id, int $user_id ): void {
		try {
			$result = ( self::$service ?? new ProductCodeService() )->get_or_create( $product_id, $user_id );
		} catch ( \Throwable $e ) {
			$result = new WP_Error( 'pqbg_code_generation_failed', get_class( $e ) );
			ProductCodeService::log_error( $result );
		}

		if ( ! is_wp_error( $result ) ) {
			return;
		}

		// Ineligibility is not a failure of the save; everything else is reported.
		if ( in_array( $result->get_error_code(), array( 'pqbg_ineligible_product', 'pqbg_ineligible_status', 'pqbg_invalid_product', 'pqbg_forbidden' ), true ) ) {
			return;
		}

		// get_or_create() already logged generation failures; log the rest by code only.
		if ( 'pqbg_code_generation_failed' !== $result->get_error_code() && 'pqbg_random_unavailable' !== $result->get_error_code() ) {
			ProductCodeService::log_error( $result );
		}

		set_transient( self::NOTICE_TRANSIENT . $user_id, $result->get_error_code(), DAY_IN_SECONDS );
	}

	/**
	 * Shows (once) that a code could not be assigned during the user's last save.
	 */
	public static function failure_notice(): void {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return;
		}

		$code = get_transient( self::NOTICE_TRANSIENT . $user_id );

		if ( false === $code ) {
			return;
		}

		delete_transient( self::NOTICE_TRANSIENT . $user_id );

		echo '<div class="notice notice-error is-dismissible pqbg-save-failure"><p>';
		echo esc_html__( 'The product was saved, but a product code could not be assigned. Open the product and use "Generate code" to try again.', 'product-qrcode-barcode-generator' );
		echo ' <code>' . esc_html( (string) $code ) . '</code></p></div>';
	}
}
