<?php
/**
 * The one place that builds wp-admin URLs for this plugin (Phase 10B, decision D7).
 *
 * Every link, form action and redirect target in wp-admin comes from here, and this
 * is the only class that calls admin_url() and names the plugin's page slugs. The
 * Phase 10B suite has a scope check that fails on a URL built anywhere else.
 *
 *   admin.php?page=pqbg-dashboard     Dashboard (the "QR & Barcodes" top-level item)
 *   admin.php?page=pqbg-sales         In-store sales (slug kept from Phase 9A)
 *   admin.php?page=pqbg-reports       In-store reports (slug kept from Phase 9B)
 *   admin.php?page=pqbg-bulk-tools    Bulk tools (&tab=tools|costs)
 *   admin.php?page=pqbg-settings      Settings (slug kept; the old &tab= forms redirect, see AdminMenu)
 *   edit.php?post_type=product&page=pqbg-print       hidden print setup (Phase 8)
 *   edit.php?post_type=product&page=pqbg-regenerate  hidden Regenerate confirmation (Phase 5)
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * Admin URL builder.
 */
final class AdminUrl {

	const DASHBOARD  = 'pqbg-dashboard';
	const SALES      = 'pqbg-sales';
	const REPORTS    = 'pqbg-reports';
	const BULK_TOOLS = 'pqbg-bulk-tools';
	const SETTINGS   = 'pqbg-settings';
	const PRINT      = 'pqbg-print';
	const REGENERATE = 'pqbg-regenerate';

	/**
	 * A plugin page under admin.php. Values are URL-encoded (arrays element by element).
	 *
	 * @param string               $slug Page slug (one of the constants).
	 * @param array<string, mixed> $args More query arguments.
	 */
	public static function page( string $slug, array $args = array() ): string {
		return add_query_arg( self::encode( array_merge( array( 'page' => $slug ), $args ) ), admin_url( 'admin.php' ) );
	}

	/**
	 * The Dashboard.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	public static function dashboard( array $args = array() ): string {
		return self::page( self::DASHBOARD, $args );
	}

	/**
	 * In-store sales (the list, with filters).
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	public static function sales( array $args = array() ): string {
		return self::page( self::SALES, $args );
	}

	/**
	 * A sale's detail screen.
	 *
	 * @param int $sale_id Sale ID.
	 */
	public static function sale( int $sale_id ): string {
		return self::sales( array( 'sale' => (string) $sale_id ) );
	}

	/**
	 * A sale's void confirmation.
	 *
	 * @param int $sale_id Sale ID.
	 */
	public static function sale_void( int $sale_id ): string {
		return self::sales(
			array(
				'sale'                 => (string) $sale_id,
				SalesAdmin::VIEW_ARG => 'void',
			)
		);
	}

	/**
	 * In-store reports.
	 *
	 * @param array<string, mixed> $args Query arguments (tab, range, …).
	 */
	public static function reports( array $args = array() ): string {
		return self::page( self::REPORTS, $args );
	}

	/**
	 * Bulk tools.
	 *
	 * @param string               $tab  ToolsAdmin::TAB_TOOLS or TAB_COSTS; '' opens the user's first tab.
	 * @param array<string, mixed> $args More query arguments.
	 */
	public static function bulk_tools( string $tab = '', array $args = array() ): string {
		return self::page( self::BULK_TOOLS, array_merge( '' === $tab ? array() : array( 'tab' => $tab ), $args ) );
	}

	/**
	 * Settings.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	public static function settings( array $args = array() ): string {
		return self::page( self::SETTINGS, $args );
	}

	/**
	 * The hidden label print setup screen for a selection (with its nonce).
	 *
	 * @param int[] $ids Products and/or variations.
	 */
	public static function print_setup( array $ids ): string {
		return add_query_arg(
			array(
				'post_type'              => 'product',
				'page'                   => self::PRINT,
				'items'                  => implode( ',', array_map( 'intval', $ids ) ),
				Permissions::NONCE_FIELD => wp_create_nonce( PrintJob::nonce_action( 'print_view', $ids ) ),
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * The hidden Regenerate confirmation for an item (with its nonce).
	 *
	 * @param int $item_id Product or variation ID.
	 */
	public static function regenerate_confirm( int $item_id ): string {
		return add_query_arg(
			array(
				'post_type'              => 'product',
				'page'                   => self::REGENERATE,
				'item'                   => $item_id,
				Permissions::NONCE_FIELD => wp_create_nonce( AdminActions::nonce_action( 'regenerate_confirm', $item_id ) ),
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * admin-post.php: a form's action (no arguments) or a GET link. The arguments are used
	 * as given, so the caller encodes them (as each handler's url() method always has).
	 *
	 * @param array<string, mixed> $args Already-encoded query arguments.
	 */
	public static function admin_post( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	/**
	 * admin.php with no page: the action of GET forms that carry "page" as a hidden field.
	 */
	public static function admin_php(): string {
		return admin_url( 'admin.php' );
	}

	/**
	 * options.php (the Settings form's action).
	 */
	public static function options(): string {
		return admin_url( 'options.php' );
	}

	/**
	 * The Products list.
	 *
	 * @param array<string, mixed> $args More query arguments (e.g. product_cat).
	 */
	public static function products( array $args = array() ): string {
		return add_query_arg( self::encode( array_merge( array( 'post_type' => 'product' ), $args ) ), admin_url( 'edit.php' ) );
	}

	/**
	 * A product's edit screen.
	 *
	 * @param int                  $product_id Product ID.
	 * @param array<string, mixed> $args       More query arguments.
	 */
	public static function product_edit( int $product_id, array $args = array() ): string {
		return add_query_arg(
			self::encode(
				array_merge(
					array(
						'post'   => $product_id,
						'action' => 'edit',
					),
					$args
				)
			),
			admin_url( 'post.php' )
		);
	}

	/**
	 * Whether a login's requested destination is just "wp-admin" (the admin home or the
	 * profile), which ScanRoute replaces with the scan page for staff without wp-admin.
	 *
	 * @param string $requested Requested redirect.
	 */
	public static function is_admin_home( string $requested ): bool {
		return in_array( $requested, array( '', 'wp-admin/', admin_url(), admin_url( 'profile.php' ) ), true );
	}

	/**
	 * URL-encodes query values (arrays element by element).
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	private static function encode( array $args ): array {
		return array_map( static fn( $v ) => is_array( $v ) ? urlencode_deep( $v ) : rawurlencode( (string) $v ), $args );
	}
}
