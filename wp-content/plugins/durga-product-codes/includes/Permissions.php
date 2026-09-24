<?php
/**
 * Central capability model. Every privileged operation in later phases must
 * check one of these capabilities server-side (REST permission_callback,
 * admin handlers, etc.). Never use __return_true for privileged endpoints.
 *
 * Nonce convention for later phases:
 *   action: self::nonce_action( 'void_sale' )  =>  "dpc_void_sale"
 *   field:  self::NONCE_FIELD                 =>  "_dpc_nonce"
 *   verify with check_admin_referer()/wp_verify_nonce() AND a capability check.
 *   REST requests use the core `wp_rest` nonce plus a capability check.
 *
 * @package Durga\ProductCodes
 */

namespace Durga\ProductCodes;

defined( 'ABSPATH' ) || exit;

/**
 * Capabilities, role map and permission helpers.
 */
final class Permissions {

	const VIEW_PRODUCTS   = 'dpc_view_products';
	const SELL            = 'dpc_sell';
	const VIEW_OWN_SALES  = 'dpc_view_own_sales';
	const VIEW_ALL_SALES  = 'dpc_view_all_sales';
	const VOID_SALE       = 'dpc_void_sale';
	const MANAGE_CODES    = 'dpc_manage_codes';
	const MANAGE_SETTINGS = 'dpc_manage_settings';

	const SELLER_ROLE = 'dpc_seller';

	const NONCE_FIELD = '_dpc_nonce';

	/**
	 * Every capability this plugin owns.
	 *
	 * @return string[]
	 */
	public static function all_caps(): array {
		return array(
			self::VIEW_PRODUCTS,
			self::SELL,
			self::VIEW_OWN_SALES,
			self::VIEW_ALL_SALES,
			self::VOID_SALE,
			self::MANAGE_CODES,
			self::MANAGE_SETTINGS,
		);
	}

	/**
	 * DPC capabilities granted per role. Roles not listed here are never touched.
	 *
	 * @return array<string, string[]>
	 */
	public static function role_map(): array {
		$seller = array( self::VIEW_PRODUCTS, self::SELL, self::VIEW_OWN_SALES );

		$operations = array_merge( $seller, array( self::VIEW_ALL_SALES, self::VOID_SALE, self::MANAGE_CODES ) );

		return array(
			self::SELLER_ROLE => $seller,
			'shop_manager'    => $operations,                                              // No settings access.
			'administrator'   => array_merge( $operations, array( self::MANAGE_SETTINGS ) ),
		);
	}

	/**
	 * Creates the Seller role if missing and reconciles DPC capabilities on the mapped roles.
	 *
	 * Only dpc_* capabilities are added or removed; every other capability on
	 * every role is left exactly as it is. Safe to run repeatedly.
	 */
	public static function sync_roles(): void {
		if ( ! get_role( self::SELLER_ROLE ) ) {
			// Seller gets `read` (log in / profile) and nothing else outside DPC.
			add_role( self::SELLER_ROLE, 'Seller', array( 'read' => true ) );
		}

		foreach ( self::role_map() as $role_name => $granted ) {
			$role = get_role( $role_name );

			if ( ! $role ) {
				continue; // e.g. shop_manager when WooCommerce has not created it.
			}

			foreach ( self::all_caps() as $cap ) {
				$should_have = in_array( $cap, $granted, true );
				$has         = ! empty( $role->capabilities[ $cap ] );

				if ( $should_have && ! $has ) {
					$role->add_cap( $cap );
				} elseif ( ! $should_have && array_key_exists( $cap, $role->capabilities ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}

	/**
	 * Removes every dpc_* capability from all roles and deletes the Seller role.
	 * Only called from uninstall.php when data deletion is explicitly enabled.
	 */
	public static function remove_all(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::all_caps() as $cap ) {
				if ( array_key_exists( $cap, $role->capabilities ) ) {
					$role->remove_cap( $cap );
				}
			}
		}

		remove_role( self::SELLER_ROLE );
	}

	/**
	 * Nonce action name for a future DPC operation, e.g. "dpc_void_sale".
	 *
	 * @param string $verb Operation name.
	 */
	public static function nonce_action( string $verb ): string {
		return 'dpc_' . sanitize_key( $verb );
	}

	/**
	 * Whether the user may see product information for a scanned code.
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 */
	public static function can_view_products( ?int $user_id = null ): bool {
		return self::user_can( self::VIEW_PRODUCTS, $user_id );
	}

	/**
	 * Whether the user may record a sale.
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 */
	public static function can_sell( ?int $user_id = null ): bool {
		return self::user_can( self::SELL, $user_id );
	}

	/**
	 * Whether the user may view a sale recorded by $seller_id.
	 *
	 * @param int      $seller_id Seller who recorded the sale.
	 * @param int|null $user_id   User ID, or null for the current user.
	 */
	public static function can_view_sale( int $seller_id, ?int $user_id = null ): bool {
		$user_id = null === $user_id ? get_current_user_id() : $user_id;

		if ( self::user_can( self::VIEW_ALL_SALES, $user_id ) ) {
			return true;
		}

		return $user_id > 0 && $seller_id === $user_id && self::user_can( self::VIEW_OWN_SALES, $user_id );
	}

	/**
	 * Whether the user may void a sale.
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 */
	public static function can_void_sale( ?int $user_id = null ): bool {
		return self::user_can( self::VOID_SALE, $user_id );
	}

	/**
	 * Whether the user may create/retire product codes.
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 */
	public static function can_manage_codes( ?int $user_id = null ): bool {
		return self::user_can( self::MANAGE_CODES, $user_id );
	}

	/**
	 * Whether the user may change plugin settings (administrators only).
	 *
	 * @param int|null $user_id User ID, or null for the current user.
	 */
	public static function can_manage_settings( ?int $user_id = null ): bool {
		return self::user_can( self::MANAGE_SETTINGS, $user_id );
	}

	/**
	 * Capability check for the current user or a specific user. Logged-out users always fail.
	 *
	 * @param string   $cap     DPC capability.
	 * @param int|null $user_id User ID, or null for the current user.
	 */
	private static function user_can( string $cap, ?int $user_id ): bool {
		$user_id = null === $user_id ? get_current_user_id() : $user_id;

		return $user_id > 0 && user_can( $user_id, $cap );
	}
}
