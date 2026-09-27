<?php
/**
 * The plugin's own top-level wp-admin menu, "QR & Barcodes" (Phase 10B, owner's Option 1).
 *
 *   QR & Barcodes (directly below Products)            pqbg_view_all_sales
 *     Dashboard          admin.php?page=pqbg-dashboard   pqbg_view_all_sales   DashboardAdmin
 *     In-store sales     admin.php?page=pqbg-sales       pqbg_view_all_sales   SalesAdmin
 *     In-store reports   admin.php?page=pqbg-reports     pqbg_view_all_sales   ReportsAdmin
 *     Bulk tools         admin.php?page=pqbg-bulk-tools  pqbg_manage_codes     ToolsAdmin (tabs: Code tools,
 *                                                                           Import cost prices = pqbg_view_costs)
 *     Settings           admin.php?page=pqbg-settings    pqbg_manage_settings  SettingsPage
 *
 * Nothing is registered under WooCommerce any more. This class is the one place that
 * registers the pages, stores the hook suffix WordPress returns for each (the screen
 * ID; its "_page_" prefix comes from the translated menu title, so it is never
 * written by hand) and answers "is this that page?" for the page classes. It also
 * prints the shared tab row at the top of every page, and redirects the Phase 10
 * addresses admin.php?page=pqbg-settings&tab=… (GET/HEAD) to their new pages.
 *
 * Every page still checks its own capability on its load- hook (403 before output).
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level menu, screen detection, shared navigation and old-URL redirects.
 */
final class AdminMenu {

	/** Sidebar position: WooCommerce is 55.5 and its menu_order filter puts Products right after it; Analytics is 57. */
	const POSITION = '55.7';

	/** A generic dashicon (no brand marks). */
	const ICON = 'dashicons-grid-view';

	/** @var array<string, string> Page slug => hook suffix (screen ID) returned by WordPress. */
	private static array $hooks = array();

	/**
	 * Hooks used on admin requests.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_pages' ) );
		// After every page is registered, but before wp-admin/menu.php checks access to the requested page.
		add_action( 'admin_menu', array( __CLASS__, 'redirect_old_url' ), PHP_INT_MAX );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * The pages in menu order.
	 *
	 * @return array<string, array{label: string, cap: string, can: callable, render: callable, load: callable}>
	 */
	public static function pages(): array {
		return array(
			AdminUrl::DASHBOARD  => array(
				'label'  => __( 'Dashboard', 'product-qrcode-barcode-generator' ),
				'cap'    => Permissions::VIEW_ALL_SALES,
				'can'    => array( Permissions::class, 'can_view_all_sales' ),
				'render' => array( DashboardAdmin::class, 'render' ),
				'load'   => array( DashboardAdmin::class, 'load' ),
			),
			AdminUrl::SALES      => array(
				'label'  => __( 'In-store sales', 'product-qrcode-barcode-generator' ),
				'cap'    => Permissions::VIEW_ALL_SALES,
				'can'    => array( Permissions::class, 'can_view_all_sales' ),
				'render' => array( SalesAdmin::class, 'render' ),
				'load'   => array( SalesAdmin::class, 'load' ),
			),
			AdminUrl::REPORTS    => array(
				'label'  => __( 'In-store reports', 'product-qrcode-barcode-generator' ),
				'cap'    => Permissions::VIEW_ALL_SALES,
				'can'    => array( Permissions::class, 'can_view_all_sales' ),
				'render' => array( ReportsAdmin::class, 'render' ),
				'load'   => array( ReportsAdmin::class, 'load' ),
			),
			AdminUrl::BULK_TOOLS => array(
				'label'  => __( 'Bulk tools', 'product-qrcode-barcode-generator' ),
				'cap'    => Permissions::MANAGE_CODES,
				'can'    => array( Permissions::class, 'can_manage_codes' ),
				'render' => array( ToolsAdmin::class, 'render' ),
				'load'   => array( ToolsAdmin::class, 'load_page' ),
			),
			AdminUrl::SETTINGS   => array(
				'label'  => __( 'Settings', 'product-qrcode-barcode-generator' ),
				'cap'    => Permissions::MANAGE_SETTINGS,
				'can'    => array( Permissions::class, 'can_manage_settings' ),
				'render' => array( SettingsPage::class, 'render' ),
				'load'   => array( SettingsPage::class, 'load' ),
			),
		);
	}

	/**
	 * admin_menu: the top-level item and its sub-items, in order.
	 */
	public static function add_pages(): void {
		$pages = self::pages();
		$title = __( 'QR & Barcodes', 'product-qrcode-barcode-generator' );

		add_menu_page( $title, $title, $pages[ AdminUrl::DASHBOARD ]['cap'], AdminUrl::DASHBOARD, $pages[ AdminUrl::DASHBOARD ]['render'], self::ICON, self::POSITION );

		foreach ( $pages as $slug => $page ) {
			// The first sub-item has the top-level slug, so WordPress shows it as "Dashboard".
			$hook = add_submenu_page( AdminUrl::DASHBOARD, $page['label'], $page['label'], $page['cap'], $slug, $page['render'] );

			if ( is_string( $hook ) && '' !== $hook ) {
				self::$hooks[ $slug ] = $hook;
				add_action( 'load-' . $hook, $page['load'] );
			}
		}
	}

	/**
	 * The hook suffix (screen ID) of a page, or '' before admin_menu.
	 *
	 * @param string $slug Page slug.
	 */
	public static function hook( string $slug ): string {
		return self::$hooks[ $slug ] ?? '';
	}

	/**
	 * Whether a hook suffix (admin_enqueue_scripts) or screen ID is the given plugin page.
	 *
	 * @param string $slug        Page slug.
	 * @param string $hook_suffix Hook suffix or screen ID.
	 */
	public static function is_page( string $slug, string $hook_suffix ): bool {
		return '' !== $hook_suffix && self::hook( $slug ) === $hook_suffix;
	}

	/**
	 * The plugin page a hook suffix belongs to, or ''.
	 *
	 * @param string $hook_suffix Hook suffix or screen ID.
	 */
	public static function page_of( string $hook_suffix ): string {
		$slug = array_search( $hook_suffix, self::$hooks, true );

		return is_string( $slug ) && '' !== $hook_suffix ? $slug : '';
	}

	/**
	 * The shared navigation stylesheet, on every plugin page.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue( $hook_suffix ): void {
		if ( '' !== self::page_of( (string) $hook_suffix ) ) {
			wp_enqueue_style( 'pqbg-menu', PQBG_PLUGIN_URL . 'assets/pqbg-menu.css', array(), PQBG_VERSION );
		}
	}

	/**
	 * The tab row at the top of every plugin page: one tab per page the user may open,
	 * in menu order, the current one marked. Page-internal tabs follow as a second row.
	 *
	 * @param string $current Current page slug.
	 */
	public static function render_nav( string $current ): void {
		echo '<nav class="nav-tab-wrapper wp-clearfix pqbg-plugin-nav" aria-label="' . esc_attr__( 'QR & Barcodes', 'product-qrcode-barcode-generator' ) . '">';

		foreach ( self::pages() as $slug => $page ) {
			if ( ! call_user_func( $page['can'] ) ) {
				continue;
			}

			$active = $slug === $current;
			echo '<a href="' . esc_url( AdminUrl::page( $slug ) ) . '" class="nav-tab' . ( $active ? ' nav-tab-active' : '' ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $page['label'] ) . '</a>';
		}

		echo '</nav>';
	}

	/**
	 * admin_menu (last): redirects a Phase 10 address of the old "QR & Barcodes" page
	 * (admin.php?page=pqbg-settings&tab=…) to where it lives now, keeping every other
	 * query argument. GET/HEAD only; POSTs (options.php, admin-post.php) never come here.
	 * A user who may not open the target is not redirected, so they get exactly the
	 * refusal they got before (WordPress's 403, or the page's own 404).
	 */
	public static function redirect_old_url(): void {
		global $pagenow;

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		if ( 'admin.php' !== $pagenow || ! in_array( $method, array( 'GET', 'HEAD' ), true ) || ! isset( $_GET['page'] ) || AdminUrl::SETTINGS !== $_GET['page'] ) {
			return;
		}

		$target = self::old_url_target( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- navigation; values are only re-encoded into the target URL.

		if ( null !== $target ) {
			wp_safe_redirect( $target, 302 );
			exit;
		}
	}

	/**
	 * Where an old admin.php?page=pqbg-settings address goes now, or null to stay.
	 *
	 *   no tab        administrators stay (Settings); anyone else who may use Bulk tools → Bulk tools
	 *   tab=settings  → Settings without the tab (administrators)
	 *   tab=tools     → Bulk tools, Code tools (pqbg_manage_codes)
	 *   tab=costs     → Bulk tools, Import cost prices (pqbg_view_costs)
	 *   anything else stays (the Settings page answers 404)
	 *
	 * @param array<string, mixed> $query   Unslashed query arguments of the request.
	 * @param int|null             $user_id User, or null for the current user.
	 */
	public static function old_url_target( array $query, ?int $user_id = null ): ?string {
		$tab  = isset( $query['tab'] ) && is_string( $query['tab'] ) ? sanitize_key( $query['tab'] ) : '';
		$rest = $query;
		unset( $rest['page'], $rest['tab'] );

		switch ( $tab ) {
			case '':
				return Permissions::can_manage_settings( $user_id ) || ! Permissions::can_manage_codes( $user_id ) ? null : AdminUrl::bulk_tools( '', $rest );
			case ToolsAdmin::TAB_SETTINGS:
				return Permissions::can_manage_settings( $user_id ) ? AdminUrl::settings( $rest ) : null;
			case ToolsAdmin::TAB_TOOLS:
				return Permissions::can_manage_codes( $user_id ) ? AdminUrl::bulk_tools( ToolsAdmin::TAB_TOOLS, $rest ) : null;
			case ToolsAdmin::TAB_COSTS:
				return Permissions::can_view_costs( $user_id ) ? AdminUrl::bulk_tools( ToolsAdmin::TAB_COSTS, $rest ) : null;
			default:
				return null;
		}
	}
}
