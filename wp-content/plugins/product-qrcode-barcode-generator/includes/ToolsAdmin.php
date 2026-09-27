<?php
/**
 * The Phase 10 tabs of WooCommerce → QR & Barcodes, and their handlers.
 *
 *   ?page=pqbg-settings&tab=settings   Settings (SettingsPage)          pqbg_manage_settings
 *   ?page=pqbg-settings&tab=tools      Code tools: generate missing     pqbg_manage_codes
 *                                      codes, export codes, recent runs
 *   ?page=pqbg-settings&tab=costs      Import cost prices               pqbg_view_costs
 * Without a tab the page opens the first tab the user may use. A tab the user may not
 * use is not shown and its URL is refused with 403 (load_page(), before any output).
 *
 * Handlers (admin-post.php, admin requests only, never nopriv):
 *   pqbg_bulk_generate  POST  start / continue / stop / resume / dismiss a run   pqbg_manage_codes
 *   pqbg_codes_csv      GET   codes CSV (CodesExport)                           pqbg_manage_codes
 *   pqbg_cost_upload    POST  upload a cost file → preview                      pqbg_view_costs
 *   pqbg_cost_apply     POST  start / continue applying, or cancel              pqbg_view_costs
 *   pqbg_cost_report    GET   the import's report CSV                           pqbg_view_costs
 *   pqbg_cost_template  GET   the cost template CSV (current costs)             pqbg_view_costs
 * Each checks the request method, then the capability, then a nonce, so a user
 * without the capability is refused (403) even with a valid nonce or import token of
 * someone else. The cost import token is also bound to the user who uploaded the file.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * Code tools and cost import screens.
 */
final class ToolsAdmin {

	const GENERATE = 'pqbg_bulk_generate';
	const UPLOAD   = 'pqbg_cost_upload';
	const APPLY    = 'pqbg_cost_apply';
	const REPORT   = 'pqbg_cost_report';
	const TEMPLATE = 'pqbg_cost_template';

	const TAB_SETTINGS = 'settings';
	const TAB_TOOLS    = 'tools';
	const TAB_COSTS    = 'costs';

	/** Query argument that makes the progress screen continue by itself. */
	const AUTO_ARG = 'pqbg_auto';

	/** Query argument carrying a fixed message key after a redirect. */
	const MESSAGE_ARG = 'pqbg_msg';

	/** Preview rows shown on screen (the report has them all). */
	const SHOWN_ROWS = 200;

	/**
	 * Hooks the handlers and assets. Admin requests only.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::GENERATE, array( __CLASS__, 'handle_generate' ) );
		add_action( 'admin_post_' . CodesExport::ACTION, array( CodesExport::class, 'handle' ) );
		add_action( 'admin_post_' . self::UPLOAD, array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_' . self::APPLY, array( __CLASS__, 'handle_apply' ) );
		add_action( 'admin_post_' . self::REPORT, array( __CLASS__, 'handle_report' ) );
		add_action( 'admin_post_' . self::TEMPLATE, array( __CLASS__, 'handle_template' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * The tabs a user may use, in order.
	 *
	 * @param int|null $user_id User, or null for the current user.
	 * @return string[]
	 */
	public static function tabs( ?int $user_id = null ): array {
		$tabs = array();

		if ( Permissions::can_manage_settings( $user_id ) ) {
			$tabs[] = self::TAB_SETTINGS;
		}

		if ( Permissions::can_manage_codes( $user_id ) ) {
			$tabs[] = self::TAB_TOOLS;
		}

		if ( Permissions::can_view_costs( $user_id ) ) {
			$tabs[] = self::TAB_COSTS;
		}

		return $tabs;
	}

	/**
	 * The requested tab ('' → the user's first tab); unknown values are returned as they are.
	 */
	public static function requested_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( '' === $tab ) {
			$tabs = self::tabs();
			return $tabs[0] ?? '';
		}

		return $tab;
	}

	/**
	 * URL of a tab.
	 *
	 * @param string               $tab  Tab.
	 * @param array<string, string> $args More query arguments.
	 */
	public static function url( string $tab, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => SettingsPage::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * load-{page}: refuses a tab the user may not use (403) or an unknown tab (404)
	 * before any output, and removes every user's expired cost import preview.
	 */
	public static function load_page(): void {
		$tab = self::requested_tab();

		if ( ! in_array( $tab, array( self::TAB_SETTINGS, self::TAB_TOOLS, self::TAB_COSTS ), true ) ) {
			wp_die( esc_html__( 'This page does not exist.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 404 ) );
		}

		if ( ! in_array( $tab, self::tabs(), true ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 403 ) );
		}

		CostImport::prune();
	}

	/**
	 * The tab navigation (only the tabs the user may use).
	 *
	 * @param string $current Current tab.
	 */
	public static function render_nav( string $current ): void {
		$labels = array(
			self::TAB_SETTINGS => __( 'Settings', 'product-qrcode-barcode-generator' ),
			self::TAB_TOOLS    => __( 'Code tools', 'product-qrcode-barcode-generator' ),
			self::TAB_COSTS    => __( 'Import cost prices', 'product-qrcode-barcode-generator' ),
		);

		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'Secondary menu', 'product-qrcode-barcode-generator' ) . '">';

		foreach ( self::tabs() as $tab ) {
			echo '<a href="' . esc_url( self::url( $tab ) ) . '" class="nav-tab' . ( $tab === $current ? ' nav-tab-active' : '' ) . '"' . ( $tab === $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $labels[ $tab ] ) . '</a>';
		}

		echo '</nav>';
	}

	/**
	 * Enqueues the tools stylesheet and the auto-continue script on this screen.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue( $hook_suffix ): void {
		if ( 'woocommerce_page_' . SettingsPage::SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'pqbg-tools', PQBG_PLUGIN_URL . 'assets/pqbg-tools.css', array(), PQBG_VERSION );
		wp_enqueue_script( 'pqbg-tools', PQBG_PLUGIN_URL . 'assets/pqbg-tools.js', array(), PQBG_VERSION, true );
	}

	// ---------------------------------------------------------------- Code tools tab

	/**
	 * The Code tools tab.
	 */
	public static function render_tools(): void {
		if ( ! Permissions::can_manage_codes() ) {
			return;
		}

		self::message();

		echo '<h2>' . esc_html__( 'Generate missing codes', 'product-qrcode-barcode-generator' ) . '</h2>';
		echo '<p>' . esc_html__( 'Gives a product code to every simple product and every variation that does not have one yet, using the same rules as saving a product: variable products themselves never get a code (their variations do), and items in the trash never do. Existing codes are never changed. Codes are permanent: they are never deleted, so check the count first.', 'product-qrcode-barcode-generator' ) . '</p>';

		$state = BulkGenerator::state();

		if ( null !== $state ) {
			self::render_run( $state );
		}

		if ( ! BulkGenerator::is_active( $state ) && ! ( null !== $state && BulkGenerator::STOPPED === $state['status'] ) ) {
			self::render_start( $state );
		}

		self::render_export();
		self::render_log();
	}

	/**
	 * The current run: progress, or its summary with print links.
	 *
	 * @param array<string, mixed> $state Run state.
	 */
	private static function render_run( array $state ): void {
		$processed = (int) $state['created'] + (int) $state['had_code'] + (int) $state['skipped'] + (int) $state['failed'];
		$total     = max( (int) $state['total'], $processed );
		$user      = get_userdata( (int) $state['user_id'] );
		$active    = BulkGenerator::is_active( $state );
		$stale     = BulkGenerator::RUNNING === $state['status'] && ! $active;

		echo '<div class="pqbg-run pqbg-run--' . esc_attr( $stale ? 'stale' : $state['status'] ) . '">';

		if ( BulkGenerator::DONE === $state['status'] ) {
			echo '<h3>' . esc_html__( 'Finished', 'product-qrcode-barcode-generator' ) . '</h3>';
		} elseif ( BulkGenerator::STOPPED === $state['status'] ) {
			echo '<h3>' . esc_html__( 'Stopped', 'product-qrcode-barcode-generator' ) . '</h3>';
		} elseif ( $stale ) {
			echo '<h3>' . esc_html__( 'Interrupted', 'product-qrcode-barcode-generator' ) . '</h3><p>' . esc_html__( 'No batch has run for several minutes (the page was probably closed). Continue it, or start a new run below; codes already created stay.', 'product-qrcode-barcode-generator' ) . '</p>';
		} else {
			echo '<h3>' . esc_html__( 'Generating codes…', 'product-qrcode-barcode-generator' ) . '</h3><p>' . esc_html__( 'Keep this page open. It continues by itself; without JavaScript, press Continue.', 'product-qrcode-barcode-generator' ) . '</p>';
		}

		echo '<progress max="' . esc_attr( (string) max( 1, $total ) ) . '" value="' . esc_attr( (string) $processed ) . '"></progress> ';
		/* translators: 1: items processed, 2: items in the run. */
		echo '<span>' . esc_html( sprintf( __( '%1$s of about %2$s items', 'product-qrcode-barcode-generator' ), number_format_i18n( $processed ), number_format_i18n( $total ) ) ) . '</span>';

		echo '<ul class="pqbg-run__counts">';
		/* translators: %s: number. */
		echo '<li>' . esc_html( sprintf( __( 'Codes created: %s', 'product-qrcode-barcode-generator' ), number_format_i18n( (int) $state['created'] ) ) ) . '</li>';
		/* translators: %s: number. */
		echo '<li>' . esc_html( sprintf( __( 'Already had a code by then: %s', 'product-qrcode-barcode-generator' ), number_format_i18n( (int) $state['had_code'] ) ) ) . '</li>';
		/* translators: %s: number. */
		echo '<li>' . esc_html( sprintf( __( 'Skipped (changed or trashed during the run): %s', 'product-qrcode-barcode-generator' ), number_format_i18n( (int) $state['skipped'] ) ) ) . '</li>';
		/* translators: %s: number. */
		echo '<li>' . esc_html( sprintf( __( 'Failed: %s', 'product-qrcode-barcode-generator' ), number_format_i18n( (int) $state['failed'] ) ) ) . '</li>';
		echo '</ul>';

		if ( array() !== (array) $state['errors'] ) {
			$errors = array_map( static fn( $id, $code ) => '#' . (int) $id . ' ' . $code, array_keys( $state['errors'] ), $state['errors'] );
			echo '<p>' . esc_html__( 'Failed items (run again to retry them):', 'product-qrcode-barcode-generator' ) . ' <code>' . esc_html( implode( ', ', $errors ) ) . '</code></p>';
		}

		/* translators: 1: user name, 2: date and time, 3: statuses. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Started by %1$s on %2$s; product statuses: %3$s.', 'product-qrcode-barcode-generator' ), $user ? $user->display_name : '#' . (int) $state['user_id'], wp_date( 'Y-m-d H:i', (int) $state['started'] ), implode( ', ', array_map( array( __CLASS__, 'status_label' ), (array) $state['statuses'] ) ) ) ) . '</p>';

		$auto = $active && isset( $_GET[ self::AUTO_ARG ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.

		echo '<div class="pqbg-run__actions">';

		if ( BulkGenerator::RUNNING === $state['status'] ) {
			self::generate_form( 'continue', $state['id'], __( 'Continue', 'product-qrcode-barcode-generator' ), 'primary', $auto ? 'pqbg-auto-continue' : '' );
			self::generate_form( 'stop', $state['id'], __( 'Stop', 'product-qrcode-barcode-generator' ), 'secondary' );
		} elseif ( BulkGenerator::STOPPED === $state['status'] ) {
			self::generate_form( 'resume', $state['id'], __( 'Continue', 'product-qrcode-barcode-generator' ), 'primary' );
			self::generate_form( 'dismiss', $state['id'], __( 'Dismiss', 'product-qrcode-barcode-generator' ), 'secondary' );
		} else {
			self::generate_form( 'dismiss', $state['id'], __( 'Clear this summary', 'product-qrcode-barcode-generator' ), 'secondary' );
		}

		echo '</div>';

		$chunks = BulkGenerator::print_chunks( $state );

		if ( BulkGenerator::DONE === $state['status'] && array() !== $chunks ) {
			echo '<h3>' . esc_html__( 'Print labels for the codes just created', 'product-qrcode-barcode-generator' ) . '</h3><p>';
			/* translators: %d: items per print job. */
			echo esc_html( sprintf( __( 'Opens the label print setup (at most %d items per print job).', 'product-qrcode-barcode-generator' ), PrintJob::MAX_ITEMS ) ) . '</p><ul class="pqbg-print-links">';
			$from = 1;

			foreach ( $chunks as $chunk ) {
				$to = $from + count( $chunk ) - 1;
				/* translators: 1: first item number, 2: last item number. */
				echo '<li><a class="button" href="' . esc_url( PrintAdmin::setup_url( $chunk ) ) . '">' . esc_html( sprintf( __( 'Print labels: items %1$s–%2$s', 'product-qrcode-barcode-generator' ), number_format_i18n( $from ), number_format_i18n( $to ) ) ) . '</a></li>';
				$from = $to + 1;
			}

			echo '</ul>';

			if ( (int) $state['created'] > count( (array) $state['created_ids'] ) ) {
				/* translators: %s: number of items. */
				echo '<p class="description">' . esc_html( sprintf( __( 'Links cover the first %s items; print the rest from the Products list.', 'product-qrcode-barcode-generator' ), number_format_i18n( count( (array) $state['created_ids'] ) ) ) ) . '</p>';
			}
		}

		echo '</div>';
	}

	/**
	 * The start form: counts per status and type, the status selection and the confirmation.
	 *
	 * @param array<string, mixed>|null $state Current (inactive) run, if any.
	 */
	private static function render_start( ?array $state ): void {
		$counts = BulkGenerator::counts();
		$total  = array_sum( $counts['simple'] ) + array_sum( $counts['variation'] );

		if ( 0 === $total ) {
			echo '<p class="pqbg-all-coded"><strong>' . esc_html__( 'Every qualifying product and variation has a code.', 'product-qrcode-barcode-generator' ) . '</strong></p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pqbg-generate-form">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::GENERATE ) . '" /><input type="hidden" name="op" value="start" />';
		wp_nonce_field( self::GENERATE, Permissions::NONCE_FIELD );
		echo '<table class="widefat striped pqbg-counts"><thead><tr><th scope="col">' . esc_html__( 'Product status', 'product-qrcode-barcode-generator' ) . '</th><th scope="col" class="num">' . esc_html__( 'Simple products', 'product-qrcode-barcode-generator' ) . '</th><th scope="col" class="num">' . esc_html__( 'Variations', 'product-qrcode-barcode-generator' ) . '</th><th scope="col" class="num">' . esc_html__( 'Without a code', 'product-qrcode-barcode-generator' ) . '</th></tr></thead><tbody>';

		foreach ( BulkGenerator::STATUSES as $status ) {
			$row = $counts['simple'][ $status ] + $counts['variation'][ $status ];
			echo '<tr><td><label><input type="checkbox" name="statuses[]" value="' . esc_attr( $status ) . '" checked="checked" /> ' . esc_html( self::status_label( $status ) ) . '</label></td>';
			echo '<td class="num">' . esc_html( number_format_i18n( $counts['simple'][ $status ] ) ) . '</td><td class="num">' . esc_html( number_format_i18n( $counts['variation'][ $status ] ) ) . '</td><td class="num"><strong>' . esc_html( number_format_i18n( $row ) ) . '</strong></td></tr>';
		}

		echo '</tbody><tfoot><tr><th scope="row">' . esc_html__( 'Total', 'product-qrcode-barcode-generator' ) . '</th><td class="num">' . esc_html( number_format_i18n( array_sum( $counts['simple'] ) ) ) . '</td><td class="num">' . esc_html( number_format_i18n( array_sum( $counts['variation'] ) ) ) . '</td><td class="num"><strong>' . esc_html( number_format_i18n( $total ) ) . '</strong></td></tr></tfoot></table>';
		echo '<p class="description">' . esc_html__( 'A variation counts under its product\'s status; enabled and disabled variations are both included. Stock tracking does not matter. The "missing codes" alert on the In-store reports dashboard counts published products only.', 'product-qrcode-barcode-generator' ) . '</p>';

		if ( null !== $state && BulkGenerator::RUNNING === $state['status'] ) {
			echo '<p class="description">' . esc_html__( 'Starting a new run replaces the interrupted one above.', 'product-qrcode-barcode-generator' ) . '</p>';
		}

		/* translators: %s: number of items. */
		echo '<p><label><input type="checkbox" name="confirm" value="1" required="required" /> ' . esc_html( sprintf( __( 'I understand that up to %s new codes will be created for the ticked statuses, and that codes are permanent.', 'product-qrcode-barcode-generator' ), number_format_i18n( $total ) ) ) . '</label></p>';
		submit_button( __( 'Generate missing codes', 'product-qrcode-barcode-generator' ), 'primary', 'submit', false );
		echo '</form>';
	}

	/**
	 * The codes export form (GET).
	 */
	private static function render_export(): void {
		echo '<h2>' . esc_html__( 'Export codes (CSV)', 'product-qrcode-barcode-generator' ) . '</h2>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pqbg-export-form">';
		echo '<input type="hidden" name="action" value="' . esc_attr( CodesExport::ACTION ) . '" />';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( CodesExport::ACTION ) ) . '" />';
		echo '<p><label>' . esc_html__( 'Codes', 'product-qrcode-barcode-generator' ) . ' <select name="code_status">';
		echo '<option value="active">' . esc_html__( 'Active codes', 'product-qrcode-barcode-generator' ) . '</option><option value="retired">' . esc_html__( 'Retired codes', 'product-qrcode-barcode-generator' ) . '</option><option value="all">' . esc_html__( 'Active and retired', 'product-qrcode-barcode-generator' ) . '</option></select></label> ';
		echo '<label>' . esc_html__( 'Product status', 'product-qrcode-barcode-generator' ) . ' <select name="product_status"><option value="">' . esc_html__( 'Any', 'product-qrcode-barcode-generator' ) . '</option>';

		foreach ( CodesExport::PRODUCT_STATUSES as $status ) {
			echo '<option value="' . esc_attr( $status ) . '">' . esc_html( self::status_label( $status ) ) . '</option>';
		}

		echo '</select></label> <label>' . esc_html__( 'Type', 'product-qrcode-barcode-generator' ) . ' <select name="type"><option value="">' . esc_html__( 'Any', 'product-qrcode-barcode-generator' ) . '</option><option value="simple">' . esc_html__( 'Simple products', 'product-qrcode-barcode-generator' ) . '</option><option value="variation">' . esc_html__( 'Variations', 'product-qrcode-barcode-generator' ) . '</option></select></label></p>';
		echo '<p><label><input type="checkbox" name="missing" value="1" /> ' . esc_html__( 'Also list products and variations without a code (at the end, with an empty code)', 'product-qrcode-barcode-generator' ) . '</label></p>';
		submit_button( __( 'Download CSV', 'product-qrcode-barcode-generator' ), 'secondary', '', false );
		echo '<p class="description">' . esc_html__( 'Columns: item ID, parent ID, type, SKU, product, attributes, product status, code, code status, scan URL (active codes only), created and retired dates. It never contains cost data. SKUs are exact in the file; Excel removes leading zeros ("00123") when a CSV is opened by double-click: use Data → From Text/CSV and set the SKU column to Text.', 'product-qrcode-barcode-generator' ) . '</p>';
		echo '</form>';
	}

	/**
	 * Recent runs the user may see (cost entries only for pqbg_view_costs).
	 */
	private static function render_log(): void {
		$entries = BulkLog::visible( 20, get_current_user_id() );

		echo '<h2>' . esc_html__( 'Recent bulk runs', 'product-qrcode-barcode-generator' ) . '</h2>';

		if ( array() === $entries ) {
			echo '<p>' . esc_html__( 'None yet.', 'product-qrcode-barcode-generator' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped pqbg-log"><thead><tr><th scope="col">' . esc_html__( 'When', 'product-qrcode-barcode-generator' ) . '</th><th scope="col">' . esc_html__( 'User', 'product-qrcode-barcode-generator' ) . '</th><th scope="col">' . esc_html__( 'Tool', 'product-qrcode-barcode-generator' ) . '</th><th scope="col">' . esc_html__( 'Details', 'product-qrcode-barcode-generator' ) . '</th></tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$details = array();

			foreach ( (array) ( $entry['details'] ?? array() ) as $key => $value ) {
				$details[] = $key . ': ' . ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
			}

			$when = strtotime( (string) $entry['time'] . ' UTC' );
			echo '<tr><td>' . esc_html( false === $when ? (string) $entry['time'] : wp_date( 'Y-m-d H:i:s', $when ) ) . '</td><td>' . esc_html( '' !== (string) $entry['user_name'] ? (string) $entry['user_name'] : '#' . (int) $entry['user_id'] ) . '</td><td>' . esc_html( BulkLog::label( (string) $entry['tool'] ) ) . '</td><td><code>' . esc_html( implode( '; ', $details ) ) . '</code></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * A one-button POST form for the run.
	 *
	 * @param string $op     Operation.
	 * @param string $run_id Run.
	 * @param string $label  Button label.
	 * @param string $type   primary|secondary.
	 * @param string $class  Extra form class.
	 */
	private static function generate_form( string $op, string $run_id, string $label, string $type, string $class = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pqbg-inline-form ' . esc_attr( $class ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::GENERATE ) . '" /><input type="hidden" name="op" value="' . esc_attr( $op ) . '" /><input type="hidden" name="run" value="' . esc_attr( $run_id ) . '" />';
		wp_nonce_field( self::GENERATE, Permissions::NONCE_FIELD );
		submit_button( $label, $type, 'submit', false );
		echo '</form> ';
	}

	/**
	 * admin_post_pqbg_bulk_generate.
	 */
	public static function handle_generate(): void {
		self::require_post();

		if ( ! Permissions::can_manage_codes() ) {
			self::refuse( __( 'You are not allowed to manage product codes.', 'product-qrcode-barcode-generator' ) );
		}

		self::verify_nonce( self::GENERATE, self::TAB_TOOLS );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$op     = isset( $_POST['op'] ) && is_string( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$run_id = isset( $_POST['run'] ) && is_string( $_POST['run'] ) ? self::token( wp_unslash( $_POST['run'] ) ) : '';
		$user   = get_current_user_id();

		switch ( $op ) {
			case 'start':
				if ( ! isset( $_POST['confirm'] ) || '1' !== $_POST['confirm'] ) {
					self::fail( __( 'Tick the box to confirm that the codes will be created.', 'product-qrcode-barcode-generator' ), self::TAB_TOOLS );
				}

				$statuses = BulkGenerator::statuses( isset( $_POST['statuses'] ) ? wp_unslash( $_POST['statuses'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked against a fixed list.
				$result   = is_wp_error( $statuses ) ? $statuses : BulkGenerator::start( $statuses, $user );
				$result   = is_wp_error( $result ) ? $result : BulkGenerator::run_batch( (string) $result['id'], $user );
				break;
			case 'continue':
				$result = BulkGenerator::run_batch( $run_id, $user );
				break;
			case 'resume':
				$result = BulkGenerator::resume( $run_id );
				$result = is_wp_error( $result ) ? $result : BulkGenerator::run_batch( $run_id, $user );
				break;
			case 'stop':
				$result = BulkGenerator::stop( $run_id );
				break;
			case 'dismiss':
				$result = BulkGenerator::dismiss( $run_id );
				break;
			default:
				self::fail( __( 'Unknown request.', 'product-qrcode-barcode-generator' ), self::TAB_TOOLS );
		}
		// phpcs:enable

		if ( is_wp_error( $result ) ) {
			self::fail( $result->get_error_message(), self::TAB_TOOLS, 'pqbg_bulk_locked' === $result->get_error_code() || 'pqbg_bulk_busy' === $result->get_error_code() ? 409 : 400 );
		}

		$args = array();

		if ( is_array( $result ) ) {
			if ( BulkGenerator::RUNNING === $result['status'] ) {
				$args[ self::AUTO_ARG ] = '1';
			} else {
				$args[ self::MESSAGE_ARG ] = BulkGenerator::DONE === $result['status'] ? 'generate_done' : 'generate_stopped';
			}
		} else {
			$args[ self::MESSAGE_ARG ] = 'generate_dismissed';
		}

		self::redirect( self::url( self::TAB_TOOLS, $args ) );
	}

	// ---------------------------------------------------------------- Import cost prices tab

	/**
	 * The Import cost prices tab (pqbg_view_costs only; load_page() refuses everyone else).
	 */
	public static function render_costs(): void {
		if ( ! Permissions::can_view_costs() ) {
			return;
		}

		self::message();

		$import = CostImport::load( get_current_user_id() );

		echo '<h2>' . esc_html__( 'Import cost prices', 'product-qrcode-barcode-generator' ) . '</h2>';
		echo '<p>' . esc_html__( 'Only administrators see this. Upload a CSV file with an ID or SKU column and a "Cost price" column. You see a preview first; nothing changes until you apply it.', 'product-qrcode-barcode-generator' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( self::cost_url( self::TEMPLATE ) ) . '">' . esc_html__( 'Download the template (all products with their current cost prices)', 'product-qrcode-barcode-generator' ) . '</a></p>';
		echo '<ul class="pqbg-rules">';
		echo '<li>' . esc_html__( 'Rows are matched by ID; the SKU is used when the ID is empty. A variable product\'s row sets the default cost price for its variations.', 'product-qrcode-barcode-generator' ) . '</li>';
		echo '<li>' . esc_html__( 'An empty cost cell changes nothing. Write "clear" to remove a cost price (it becomes unknown). 0 is a known cost of zero.', 'product-qrcode-barcode-generator' ) . '</li>';
		/* translators: %d: number of decimals. */
		echo '<li>' . esc_html( sprintf( __( 'Numbers like 1200.50, 1,200.50, 1,20,000, ₹1200, Rs. 1200 or 1200/- are accepted, with at most %d decimals (never rounded).', 'product-qrcode-barcode-generator' ), wc_get_price_decimals() ) ) . '</li>';
		/* translators: 1: size limit, 2: row limit. */
		echo '<li>' . esc_html( sprintf( __( 'At most %1$s and %2$s rows per file. Save from Excel as "CSV UTF-8 (Comma delimited)".', 'product-qrcode-barcode-generator' ), size_format( CsvUpload::MAX_BYTES ), number_format_i18n( CsvUpload::MAX_ROWS ) ) ) . '</li>';
		echo '</ul>';

		if ( null === $import ) {
			echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pqbg-upload-form">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::UPLOAD ) . '" /><input type="hidden" name="MAX_FILE_SIZE" value="' . esc_attr( (string) CsvUpload::MAX_BYTES ) . '" />';
			wp_nonce_field( self::UPLOAD, Permissions::NONCE_FIELD );
			echo '<p><label>' . esc_html__( 'CSV file', 'product-qrcode-barcode-generator' ) . ' <input type="file" name="pqbg_file" accept=".csv,text/csv" required="required" /></label></p>';
			submit_button( __( 'Upload and preview', 'product-qrcode-barcode-generator' ), 'primary', 'submit', false );
			echo '</form>';
			return;
		}

		self::render_import( $import );
	}

	/**
	 * A stored import: preview, progress or result.
	 *
	 * @param array<string, mixed> $import Import.
	 */
	private static function render_import( array $import ): void {
		$c     = $import['counts'];
		$r     = $import['results'];
		$total = count( $import['rows'] );

		/* translators: 1: file name, 2: number of rows. */
		echo '<h3>' . esc_html( sprintf( __( '%1$s: %2$s rows', 'product-qrcode-barcode-generator' ), (string) $import['file']['name'], number_format_i18n( $total ) ) ) . '</h3>';

		if ( 'UTF-8' !== $import['file']['encoding'] ) {
			echo '<p class="notice notice-warning inline">' . esc_html__( 'The file was not saved as UTF-8; it was read as Windows-1252. Check the names and SKUs in the preview.', 'product-qrcode-barcode-generator' ) . '</p>';
		}

		echo '<ul class="pqbg-import-counts">';
		foreach ( array( CostImport::UPDATE, CostImport::CLEAR, CostImport::NO_CHANGE, CostImport::BLANK, CostImport::ERROR ) as $outcome ) {
			echo '<li class="pqbg-outcome--' . esc_attr( $outcome ) . '">' . esc_html( CostImport::outcome_label( $outcome ) ) . ': <strong>' . esc_html( number_format_i18n( (int) $c[ $outcome ] ) ) . '</strong></li>';
		}
		echo '</ul>';

		echo '<p><a class="button" href="' . esc_url( self::cost_url( self::REPORT, array( 'token' => (string) $import['token'] ) ) ) . '">' . esc_html__( 'Download the full report (CSV)', 'product-qrcode-barcode-generator' ) . '</a></p>';

		if ( CostImport::PREVIEW === $import['status'] ) {
			$apply = (int) $c[ CostImport::UPDATE ] + (int) $c[ CostImport::CLEAR ];

			if ( $apply > 0 ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pqbg-inline-form">';
				self::apply_fields( 'start', (string) $import['token'] );

				if ( $c[ CostImport::ERROR ] > 0 ) {
					/* translators: 1: rows to apply, 2: rows with errors. */
					echo '<p><label><input type="checkbox" name="ack" value="1" required="required" /> ' . esc_html( sprintf( __( 'Apply the %1$s valid changes and skip the %2$s rows with errors.', 'product-qrcode-barcode-generator' ), number_format_i18n( $apply ), number_format_i18n( (int) $c[ CostImport::ERROR ] ) ) ) . '</label></p>';
				}

				/* translators: %s: number of rows. */
				submit_button( sprintf( __( 'Apply %s changes', 'product-qrcode-barcode-generator' ), number_format_i18n( $apply ) ), 'primary', 'submit', false );
				echo '</form> ';
			} else {
				echo '<p><strong>' . esc_html__( 'Nothing to apply: no row changes a cost price.', 'product-qrcode-barcode-generator' ) . '</strong></p>';
			}

			self::cancel_form( (string) $import['token'], __( 'Cancel and upload another file', 'product-qrcode-barcode-generator' ) );
			self::render_rows( $import );
			return;
		}

		if ( CostImport::APPLYING === $import['status'] ) {
			echo '<h3>' . esc_html__( 'Applying…', 'product-qrcode-barcode-generator' ) . '</h3>';
			echo '<progress max="' . esc_attr( (string) max( 1, $total ) ) . '" value="' . esc_attr( (string) (int) $import['position'] ) . '"></progress> ';
			/* translators: 1: rows done, 2: rows. */
			echo '<span>' . esc_html( sprintf( __( '%1$s of %2$s rows', 'product-qrcode-barcode-generator' ), number_format_i18n( (int) $import['position'] ), number_format_i18n( $total ) ) ) . '</span>';
			$auto = isset( $_GET[ self::AUTO_ARG ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			echo '<div class="pqbg-run__actions"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pqbg-inline-form' . ( $auto ? ' pqbg-auto-continue' : '' ) . '">';
			self::apply_fields( 'continue', (string) $import['token'] );
			submit_button( __( 'Continue', 'product-qrcode-barcode-generator' ), 'primary', 'submit', false );
			echo '</form> ';
			self::cancel_form( (string) $import['token'], __( 'Stop (rows already applied stay)', 'product-qrcode-barcode-generator' ) );
			echo '</div>';
			return;
		}

		echo '<h3>' . esc_html__( 'Applied', 'product-qrcode-barcode-generator' ) . '</h3><ul class="pqbg-import-counts">';
		foreach ( array( CostImport::APPLIED_ROW, CostImport::ALREADY, CostImport::CHANGED, CostImport::FAILED ) as $result ) {
			echo '<li>' . esc_html( CostImport::result_label( $result ) ) . ': <strong>' . esc_html( number_format_i18n( (int) $r[ $result ] ) ) . '</strong></li>';
		}
		echo '</ul><p class="description">' . esc_html__( 'The report stays available for an hour. Keep it if you need a record of the old and new values.', 'product-qrcode-barcode-generator' ) . '</p>';
		self::cancel_form( (string) $import['token'], __( 'Start another import', 'product-qrcode-barcode-generator' ) );
	}

	/**
	 * The preview table: errors first, then changes, then the rest (SHOWN_ROWS at most).
	 *
	 * @param array<string, mixed> $import Import.
	 */
	private static function render_rows( array $import ): void {
		$order = array(
			CostImport::ERROR     => 0,
			CostImport::UPDATE    => 1,
			CostImport::CLEAR     => 2,
			CostImport::NO_CHANGE => 3,
			CostImport::BLANK     => 4,
		);
		$rows  = $import['rows'];
		usort( $rows, static fn( $a, $b ) => $order[ $a[ CostImport::F_OUTCOME ] ] <=> $order[ $b[ CostImport::F_OUTCOME ] ] ?: $a[ CostImport::F_LINE ] <=> $b[ CostImport::F_LINE ] );
		$shown = array_slice( $rows, 0, self::SHOWN_ROWS );
		_prime_post_caches( array_values( array_filter( array_map( static fn( $r ) => (int) $r[ CostImport::F_ID ], $shown ) ) ), false, false );

		echo '<table class="widefat striped pqbg-preview"><thead><tr>';
		foreach ( array( __( 'Line', 'product-qrcode-barcode-generator' ), __( 'Item ID', 'product-qrcode-barcode-generator' ), __( 'SKU', 'product-qrcode-barcode-generator' ), __( 'Product', 'product-qrcode-barcode-generator' ), __( 'Cost now', 'product-qrcode-barcode-generator' ), __( 'New cost', 'product-qrcode-barcode-generator' ), __( 'Outcome', 'product-qrcode-barcode-generator' ), __( 'Reason', 'product-qrcode-barcode-generator' ) ) as $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $shown as $row ) {
			$error = CostImport::ERROR === $row[ CostImport::F_OUTCOME ];
			echo '<tr class="pqbg-outcome--' . esc_attr( (string) $row[ CostImport::F_OUTCOME ] ) . '"><td>' . esc_html( (string) $row[ CostImport::F_LINE ] ) . '</td><td>' . esc_html( $row[ CostImport::F_ID ] > 0 ? (string) $row[ CostImport::F_ID ] : '' ) . '</td><td>' . esc_html( (string) $row[ CostImport::F_SKU ] ) . '</td><td>' . esc_html( CostImport::name( (int) $row[ CostImport::F_ID ] ) ) . '</td>';
			echo '<td>' . esc_html( $error ? '' : self::amount( (string) $row[ CostImport::F_CURRENT ] ) ) . '</td><td>' . esc_html( $error || CostImport::BLANK === $row[ CostImport::F_OUTCOME ] ? '' : self::amount( (string) $row[ CostImport::F_NEW ] ) ) . '</td>';
			echo '<td>' . esc_html( CostImport::outcome_label( (string) $row[ CostImport::F_OUTCOME ] ) ) . '</td><td>' . esc_html( CostImport::reason( (string) $row[ CostImport::F_REASON ], (string) $row[ CostImport::F_PARAM ], (string) $row[ CostImport::F_SKU ] ) ) . '</td></tr>';
		}

		echo '</tbody></table>';

		if ( count( $rows ) > self::SHOWN_ROWS ) {
			/* translators: 1: rows shown, 2: rows. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Showing %1$s of %2$s rows (errors first). The report has them all.', 'product-qrcode-barcode-generator' ), number_format_i18n( self::SHOWN_ROWS ), number_format_i18n( count( $rows ) ) ) ) . '</p>';
		}
	}

	/**
	 * A cost amount for display ("unknown" when empty).
	 *
	 * @param string $amount Amount.
	 */
	private static function amount( string $amount ): string {
		return '' === $amount ? __( 'unknown', 'product-qrcode-barcode-generator' ) : $amount;
	}

	/**
	 * Hidden fields of an apply form.
	 *
	 * @param string $op    Operation.
	 * @param string $token Import token.
	 */
	private static function apply_fields( string $op, string $token ): void {
		echo '<input type="hidden" name="action" value="' . esc_attr( self::APPLY ) . '" /><input type="hidden" name="op" value="' . esc_attr( $op ) . '" /><input type="hidden" name="token" value="' . esc_attr( $token ) . '" />';
		wp_nonce_field( self::APPLY, Permissions::NONCE_FIELD );
	}

	/**
	 * A cancel button.
	 *
	 * @param string $token Import token.
	 * @param string $label Label.
	 */
	private static function cancel_form( string $token, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pqbg-inline-form">';
		self::apply_fields( 'cancel', $token );
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * URL of a cost GET handler (report, template).
	 *
	 * @param string                $action Action.
	 * @param array<string, string> $args   More arguments.
	 */
	private static function cost_url( string $action, array $args = array() ): string {
		return add_query_arg( array_map( 'rawurlencode', array_merge( array( 'action' => $action ), $args, array( '_wpnonce' => wp_create_nonce( $action ) ) ) ), admin_url( 'admin-post.php' ) );
	}

	/**
	 * admin_post_pqbg_cost_upload.
	 */
	public static function handle_upload(): void {
		self::require_post();

		if ( ! Permissions::can_view_costs() ) {
			CsvUpload::delete( $_FILES['pqbg_file'] ?? null ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only deleted.
			self::refuse( __( 'Sorry, you are not allowed to see or change cost prices.', 'product-qrcode-barcode-generator' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		$nonce = isset( $_POST[ Permissions::NONCE_FIELD ] ) && is_string( $_POST[ Permissions::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ Permissions::NONCE_FIELD ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::UPLOAD ) ) {
			CsvUpload::delete( $_FILES['pqbg_file'] ?? null ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only deleted.
			self::fail( __( 'The link you followed has expired. Go back and try again.', 'product-qrcode-barcode-generator' ), self::TAB_COSTS, 403 );
		}

		$result = CostImport::upload( get_current_user_id(), $_FILES['pqbg_file'] ?? null ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by CsvUpload.

		if ( is_wp_error( $result ) ) {
			self::fail( $result->get_error_message(), self::TAB_COSTS, 'pqbg_import_busy' === $result->get_error_code() ? 409 : 400 );
		}

		self::redirect( self::url( self::TAB_COSTS ) );
	}

	/**
	 * admin_post_pqbg_cost_apply.
	 */
	public static function handle_apply(): void {
		self::require_post();

		if ( ! Permissions::can_view_costs() ) {
			self::refuse( __( 'Sorry, you are not allowed to see or change cost prices.', 'product-qrcode-barcode-generator' ) );
		}

		self::verify_nonce( self::APPLY, self::TAB_COSTS );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$op    = isset( $_POST['op'] ) && is_string( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$token = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? self::token( wp_unslash( $_POST['token'] ) ) : '';
		$ack   = isset( $_POST['ack'] ) && '1' === $_POST['ack'];
		// phpcs:enable
		$user = get_current_user_id();

		switch ( $op ) {
			case 'start':
				$result = CostImport::start_apply( $user, $token, $ack );
				$result = is_wp_error( $result ) ? $result : CostImport::apply_chunk( $user, $token );
				break;
			case 'continue':
				$result = CostImport::apply_chunk( $user, $token );
				break;
			case 'cancel':
				$result = CostImport::cancel( $user, $token );
				break;
			default:
				self::fail( __( 'Unknown request.', 'product-qrcode-barcode-generator' ), self::TAB_COSTS );
		}

		if ( is_wp_error( $result ) ) {
			self::fail( $result->get_error_message(), self::TAB_COSTS, 'pqbg_forbidden' === $result->get_error_code() ? 403 : 400 );
		}

		$args = array();

		if ( is_array( $result ) && CostImport::APPLYING === $result['status'] ) {
			$args[ self::AUTO_ARG ] = '1';
		} elseif ( is_array( $result ) && CostImport::APPLIED === $result['status'] ) {
			$args[ self::MESSAGE_ARG ] = 'import_applied';
		} elseif ( true === $result ) {
			$args[ self::MESSAGE_ARG ] = 'import_cancelled';
		}

		self::redirect( self::url( self::TAB_COSTS, $args ) );
	}

	/**
	 * admin_post_pqbg_cost_report.
	 */
	public static function handle_report(): void {
		self::require_get();

		if ( ! Permissions::can_view_costs() ) {
			self::refuse( __( 'Sorry, you are not allowed to see or change cost prices.', 'product-qrcode-barcode-generator' ) );
		}

		self::verify_get_nonce( self::REPORT );

		$token  = isset( $_GET['token'] ) && is_string( $_GET['token'] ) ? self::token( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		$import = CostImport::load( get_current_user_id() );

		if ( null === $import || '' === $token || ! hash_equals( (string) $import['token'], $token ) ) {
			self::fail( __( 'This import has expired or was replaced. Upload the file again.', 'product-qrcode-barcode-generator' ), self::TAB_COSTS, 404 );
		}

		self::csv_headers( 'cost-import-report-' . wp_date( 'Y-m-d-His', (int) $import['created'] ) . '.csv' );

		if ( 'HEAD' !== self::method() ) {
			$out = fopen( 'php://output', 'w' );
			CostImport::write_report( $out, $import );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output.
		}

		exit;
	}

	/**
	 * admin_post_pqbg_cost_template.
	 */
	public static function handle_template(): void {
		self::require_get();

		if ( ! Permissions::can_view_costs() ) {
			self::refuse( __( 'Sorry, you are not allowed to see or change cost prices.', 'product-qrcode-barcode-generator' ) );
		}

		self::verify_get_nonce( self::TEMPLATE );
		self::csv_headers( 'cost-prices-' . wp_date( 'Y-m-d' ) . '.csv' );

		if ( 'HEAD' !== self::method() ) {
			$out  = fopen( 'php://output', 'w' );
			$rows = CostImport::write_template( $out, true );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output.
			BulkLog::add( BulkLog::TOOL_COST_TEMPLATE, array( 'rows' => $rows ) );
		}

		exit;
	}

	// ---------------------------------------------------------------- helpers

	/**
	 * Shows the message of a redirect (fixed keys only).
	 */
	private static function message(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only; fixed keys.
		$key      = isset( $_GET[ self::MESSAGE_ARG ] ) && is_string( $_GET[ self::MESSAGE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::MESSAGE_ARG ] ) ) : '';
		$messages = array(
			'generate_done'      => __( 'Code generation finished.', 'product-qrcode-barcode-generator' ),
			'generate_stopped'   => __( 'Code generation stopped. You can continue it later.', 'product-qrcode-barcode-generator' ),
			'generate_dismissed' => __( 'The run summary was cleared. It stays in Recent bulk runs.', 'product-qrcode-barcode-generator' ),
			'import_applied'     => __( 'The cost prices were imported.', 'product-qrcode-barcode-generator' ),
			'import_cancelled'   => __( 'The import was closed.', 'product-qrcode-barcode-generator' ),
		);

		if ( isset( $messages[ $key ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
		}
	}

	/**
	 * A tab's label for a product status value.
	 *
	 * @param string $status Status.
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			'publish' => __( 'Published', 'product-qrcode-barcode-generator' ),
			'private' => __( 'Private', 'product-qrcode-barcode-generator' ),
			'draft'   => __( 'Draft', 'product-qrcode-barcode-generator' ),
			'pending' => __( 'Pending review', 'product-qrcode-barcode-generator' ),
			'future'  => __( 'Scheduled', 'product-qrcode-barcode-generator' ),
			'trash'   => __( 'Trash', 'product-qrcode-barcode-generator' ),
			'deleted' => __( 'Deleted', 'product-qrcode-barcode-generator' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * A run ID or import token from the request: letters and digits only, case kept
	 * (sanitize_key() would lowercase it and it would never match).
	 *
	 * @param string $value Raw value.
	 */
	private static function token( string $value ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9]/', '', $value ), 0, 64 );
	}

	/**
	 * The request method.
	 */
	private static function method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	}

	/**
	 * Only POST (405 otherwise).
	 */
	private static function require_post(): void {
		if ( 'POST' !== self::method() ) {
			header( 'Allow: POST' );
			wp_die( esc_html__( 'This action needs a form submission.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 405 ) );
		}
	}

	/**
	 * Only GET/HEAD (405 otherwise).
	 */
	private static function require_get(): void {
		if ( ! in_array( self::method(), array( 'GET', 'HEAD' ), true ) ) {
			header( 'Allow: GET, HEAD' );
			wp_die( esc_html__( 'This file can only be downloaded.', 'product-qrcode-barcode-generator' ), '', array( 'response' => 405 ) );
		}
	}

	/**
	 * Verifies a POST nonce (403 otherwise).
	 *
	 * @param string $action Nonce action.
	 * @param string $tab    Tab to link back to.
	 */
	private static function verify_nonce( string $action, string $tab ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this is the check.
		$nonce = isset( $_POST[ Permissions::NONCE_FIELD ] ) && is_string( $_POST[ Permissions::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ Permissions::NONCE_FIELD ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			self::fail( __( 'The link you followed has expired. Go back and try again.', 'product-qrcode-barcode-generator' ), $tab, 403 );
		}
	}

	/**
	 * Verifies a GET nonce (403 otherwise).
	 *
	 * @param string $action Nonce action.
	 */
	private static function verify_get_nonce( string $action ): void {
		$nonce = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			self::fail( __( 'This download link has expired. Go back and try again.', 'product-qrcode-barcode-generator' ), self::TAB_COSTS, 403 );
		}
	}

	/**
	 * Refuses a user without the capability (403, no link to a screen they cannot open).
	 *
	 * @param string $message Message.
	 */
	private static function refuse( string $message ): void {
		wp_die( esc_html( $message ), '', array( 'response' => 403 ) );
	}

	/**
	 * Ends the request with an error and a link back to the tab.
	 *
	 * @param string $message Message.
	 * @param string $tab     Tab.
	 * @param int    $status  HTTP status.
	 */
	private static function fail( string $message, string $tab, int $status = 400 ): void {
		wp_die(
			esc_html( $message ),
			esc_html__( 'Product QR Code and Barcode Generator', 'product-qrcode-barcode-generator' ),
			array(
				'response'  => $status,
				'link_url'  => esc_url( self::url( $tab ) ),
				'link_text' => esc_html__( 'Back', 'product-qrcode-barcode-generator' ),
			)
		);
	}

	/**
	 * 303 redirect.
	 *
	 * @param string $url URL.
	 */
	private static function redirect( string $url ): void {
		wp_safe_redirect( $url, 303 );
		exit;
	}

	/**
	 * CSV download headers.
	 *
	 * @param string $name File name.
	 */
	private static function csv_headers( string $name ): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
	}
}
