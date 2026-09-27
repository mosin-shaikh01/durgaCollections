<?php
/**
 * Audit trail of the Phase 10 bulk tools (decision D12).
 *
 * Storage: the non-autoloaded option OPTION, newest entry first, at most MAX
 * entries. Each entry: time (UTC), user ID and name at that moment, tool, counts,
 * and for imports the file name and its SHA-256. Every entry is also written as one
 * line to the WooCommerce log (source product-qrcode-barcode-generator).
 *
 * Visibility: generation and code-export entries for pqbg_manage_codes; cost
 * entries (template download and cost import) only for pqbg_view_costs. The log
 * never holds a cost value, only counts.
 *
 * Codes carry their own per-row audit (created_by / created_at_gmt in pqbg_codes).
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * Bulk tools log.
 */
final class BulkLog {

	const OPTION = 'pqbg_bulk_log';

	const MAX = 200;

	/** Seconds add() waits for another request's log write (Phase 11, D8). */
	const LOCK_TIMEOUT = 5;

	const TOOL_GENERATE      = 'generate';
	const TOOL_CODES_EXPORT  = 'codes_export';
	const TOOL_COST_TEMPLATE = 'cost_template';
	const TOOL_COST_IMPORT   = 'cost_import';

	/** Tools whose entries only pqbg_view_costs may see. */
	const COST_TOOLS = array( self::TOOL_COST_TEMPLATE, self::TOOL_COST_IMPORT );

	/**
	 * Adds an entry.
	 *
	 * @param string               $tool    One of the TOOL_* constants.
	 * @param array<string, mixed> $details Counts and other plain values (no costs).
	 * @param int|null             $user_id Acting user; defaults to the current user.
	 */
	public static function add( string $tool, array $details, ?int $user_id = null ): void {
		$user_id = null === $user_id ? get_current_user_id() : $user_id;
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		$entry   = array(
			'time'      => gmdate( 'Y-m-d H:i:s' ),
			'user_id'   => $user_id,
			'user_name' => $user ? (string) $user->display_name : '',
			'tool'      => $tool,
			'details'   => $details,
		);

		// Two requests writing at the same instant would each re-read the log and one entry would be
		// lost, so the read-modify-write runs under a named lock (Phase 11, D8). An activity log must
		// never block a tool: without the lock after LOCK_TIMEOUT seconds the entry is written anyway.
		$locked = self::lock();

		try {
			// Re-read the stored log: another request may have added an entry since this one first read it.
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$entries = self::all();
			array_unshift( $entries, $entry );
			$entries = array_slice( $entries, 0, self::MAX );

			if ( false === get_option( self::OPTION, false ) ) {
				add_option( self::OPTION, $entries, '', false );
			} else {
				update_option( self::OPTION, $entries, false );
			}
		} finally {
			if ( $locked ) {
				self::unlock();
			}
		}

		if ( ! $locked && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( 'Bulk log written without its lock (another write took longer than ' . self::LOCK_TIMEOUT . ' s).', array( 'source' => 'product-qrcode-barcode-generator' ) );
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info( 'Bulk tool ' . $tool . ' by user #' . $user_id . ': ' . wp_json_encode( $details ), array( 'source' => 'product-qrcode-barcode-generator' ) );
		}
	}

	/**
	 * The log lock's name (per database and table prefix, like StockLock).
	 */
	public static function lock_name(): string {
		global $wpdb;

		return 'pqbg:' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 12 ) . ':bulklog';
	}

	/**
	 * Takes the log lock, waiting up to LOCK_TIMEOUT seconds.
	 */
	private static function lock(): bool {
		global $wpdb;

		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name(), self::LOCK_TIMEOUT ) );
	}

	/**
	 * Releases the log lock.
	 */
	private static function unlock(): void {
		global $wpdb;

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
	}

	/**
	 * Every stored entry, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$entries = get_option( self::OPTION, array() );

		return is_array( $entries ) ? array_values( array_filter( $entries, 'is_array' ) ) : array();
	}

	/**
	 * The entries a user may see, newest first.
	 *
	 * @param int $limit   Maximum entries.
	 * @param int $user_id User.
	 * @return array<int, array<string, mixed>>
	 */
	public static function visible( int $limit, int $user_id ): array {
		$costs = Permissions::can_view_costs( $user_id );
		$codes = Permissions::can_manage_codes( $user_id );

		$entries = array_filter(
			self::all(),
			static function ( array $entry ) use ( $costs, $codes ): bool {
				$tool = (string) ( $entry['tool'] ?? '' );

				return in_array( $tool, self::COST_TOOLS, true ) ? $costs : $codes;
			}
		);

		return array_slice( array_values( $entries ), 0, max( 1, $limit ) );
	}

	/**
	 * A tool's label.
	 *
	 * @param string $tool Tool.
	 */
	public static function label( string $tool ): string {
		switch ( $tool ) {
			case self::TOOL_GENERATE:
				return __( 'Generate missing codes', 'product-qrcode-barcode-generator' );
			case self::TOOL_CODES_EXPORT:
				return __( 'Codes CSV export', 'product-qrcode-barcode-generator' );
			case self::TOOL_COST_TEMPLATE:
				return __( 'Cost price template download', 'product-qrcode-barcode-generator' );
			case self::TOOL_COST_IMPORT:
				return __( 'Cost price import', 'product-qrcode-barcode-generator' );
			default:
				return $tool;
		}
	}
}
