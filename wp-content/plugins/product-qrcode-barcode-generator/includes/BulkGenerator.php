<?php
/**
 * Bulk generation of missing product codes (Phase 10, decisions D1–D3).
 *
 * Which items qualify (D1, the same rules as automatic generation on save):
 *   - simple products whose own status is one of the selected STATUSES;
 *   - variations (enabled or disabled) of a variable product whose status is one
 *     of the selected STATUSES;
 *   - never: variable parents, grouped/external/other types, orphan variations,
 *     auto-drafts, the importer's "importing" placeholders, trashed items or
 *     variations of a trashed parent.
 * Stock tracking plays no part. Before each code is created the item is checked
 * again (status, ProductCodeService::eligibility()), so an item trashed or changed
 * during a run is skipped.
 *
 * How it runs (D2): in batches of up to BATCH items or TIME_BUDGET seconds, each one
 * admin-post request (ToolsAdmin). There is no stored queue: every batch asks again
 * for "qualifying, no active code, ID above the cursor", so a run can be stopped,
 * abandoned (closed tab, crash) and continued, and a new run is always safe. Codes are
 * created only through ProductCodeService::get_or_create(), which never makes a second
 * code for an item (the unique index backs this up). A MySQL named lock (GET_LOCK,
 * released automatically if the request dies) makes batches run one at a time, so the
 * counts stay exact.
 *
 * Run state: the non-autoloaded option OPTION. One run at a time: a new run cannot
 * start while another is running and was active in the last STALE seconds.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Missing-code generation.
 */
final class BulkGenerator {

	const OPTION = 'pqbg_bulk_run';

	/** Product statuses whose items (or whose variations) qualify. Trash, auto-draft and importing never do. */
	const STATUSES = array( 'publish', 'private', 'draft', 'pending', 'future' );

	/** Variation statuses: enabled (publish) and disabled (private). */
	const VARIATION_STATUSES = array( 'publish', 'private' );

	const BATCH = 100;

	/** Seconds of work per batch request before it hands back to the browser. */
	const TIME_BUDGET = 10.0;

	/** A running run with no batch for this long may be replaced by a new one. */
	const STALE = 300;

	/** Seconds a batch waits for another batch to finish. */
	const LOCK_TIMEOUT = 10;

	/** Created item IDs kept for the "Print labels" links. */
	const MAX_CREATED_IDS = 10000;

	/** Item errors kept for the summary. */
	const MAX_ERRORS = 50;

	const RUNNING = 'running';
	const STOPPED = 'stopped';
	const DONE    = 'done';

	/**
	 * Validates a status selection.
	 *
	 * @param mixed $input Submitted statuses.
	 * @return string[]|WP_Error
	 */
	public static function statuses( $input ) {
		$picked = is_array( $input ) ? array_values( array_intersect( self::STATUSES, array_map( 'strval', $input ) ) ) : array();

		if ( array() === $picked ) {
			return new WP_Error( 'pqbg_bulk_no_status', __( 'Choose at least one product status.', 'product-qrcode-barcode-generator' ) );
		}

		return $picked;
	}

	/**
	 * Qualifying items without an active code, by type and status (status = the
	 * product's own for simple products, the parent's for variations).
	 *
	 * @return array{simple: array<string, int>, variation: array<string, int>}
	 */
	public static function counts(): array {
		global $wpdb;

		$out = array(
			'simple'    => array_fill_keys( self::STATUSES, 0 ),
			'variation' => array_fill_keys( self::STATUSES, 0 ),
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from fixed literals and integers by sql().
		foreach ( (array) $wpdb->get_results( 'SELECT type, status, COUNT(*) AS n FROM (' . self::sql( self::STATUSES, 0 ) . ') x GROUP BY type, status', ARRAY_A ) as $row ) {
			if ( isset( $out[ $row['type'] ][ $row['status'] ] ) ) {
				$out[ $row['type'] ][ $row['status'] ] = (int) $row['n'];
			}
		}

		return $out;
	}

	/**
	 * How many qualifying items without a code a selection has.
	 *
	 * @param string[] $statuses Selection.
	 */
	public static function total( array $statuses ): int {
		$counts = self::counts();
		$total  = 0;

		foreach ( $statuses as $status ) {
			$total += ( $counts['simple'][ $status ] ?? 0 ) + ( $counts['variation'][ $status ] ?? 0 );
		}

		return $total;
	}

	/**
	 * The next qualifying items without a code, by ID.
	 *
	 * @param string[] $statuses Selection.
	 * @param int      $after    Cursor: only IDs above this.
	 * @param int      $limit    Maximum.
	 * @return int[]
	 */
	public static function candidates( array $statuses, int $after, int $limit ): array {
		return array_map( static fn( $row ) => (int) $row['id'], self::missing( $statuses, $after, $limit ) );
	}

	/**
	 * The next qualifying items without a code, by ID, with their type and status (CodesExport).
	 *
	 * @param string[] $statuses Selection.
	 * @param int      $after    Cursor: only IDs above this.
	 * @param int      $limit    Maximum.
	 * @return array<int, array{id: string, status: string, type: string}>
	 */
	public static function missing( array $statuses, int $after, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from fixed literals and integers by sql().
		return (array) $wpdb->get_results( 'SELECT id, status, type FROM (' . self::sql( $statuses, $after ) . ') x ORDER BY id LIMIT ' . max( 1, $limit ), ARRAY_A );
	}

	/**
	 * The current run's state, read fresh from the database, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function state(): ?array {
		// Another request may have created, changed or deleted the run since this one last looked:
		// forget both the cached value and a cached "does not exist".
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$state = get_option( self::OPTION, null );

		return is_array( $state ) && isset( $state['id'] ) ? $state : null;
	}

	/**
	 * Whether a state is a run that is still active (running, with a batch recently).
	 *
	 * @param array<string, mixed>|null $state State.
	 */
	public static function is_active( ?array $state ): bool {
		return null !== $state && self::RUNNING === $state['status'] && time() - (int) $state['updated'] < self::STALE;
	}

	/**
	 * Starts a new run. Refused while another run is active.
	 *
	 * @param string[] $statuses Validated selection.
	 * @param int      $user_id  Acting user (pqbg_manage_codes).
	 * @return array<string, mixed>|WP_Error New state.
	 */
	public static function start( array $statuses, int $user_id ) {
		if ( ! Permissions::can_manage_codes( $user_id ) ) {
			return new WP_Error( 'pqbg_forbidden', __( 'You are not allowed to manage product codes.', 'product-qrcode-barcode-generator' ) );
		}

		$old = self::state();

		if ( self::is_active( $old ) ) {
			return new WP_Error( 'pqbg_bulk_busy', __( 'Another code generation run is in progress. Wait for it to finish, or continue it.', 'product-qrcode-barcode-generator' ) );
		}

		if ( null !== $old && self::RUNNING === $old['status'] ) {
			self::log( $old, 'abandoned' ); // A run nobody continued; it is replaced now.
		}

		$state = array(
			'id'          => wp_generate_password( 16, false, false ),
			'status'      => self::RUNNING,
			'statuses'    => $statuses,
			'user_id'     => $user_id,
			'started'     => time(),
			'updated'     => time(),
			'finished'    => 0,
			'cursor'      => 0,
			'total'       => self::total( $statuses ),
			'batches'     => 0,
			'created'     => 0,
			'had_code'    => 0,
			'skipped'     => 0,
			'failed'      => 0,
			'errors'      => array(),
			'created_ids' => array(),
		);

		self::save( $state );

		return $state;
	}

	/**
	 * Runs one batch of a run.
	 *
	 * @param string $run_id  The run the request belongs to.
	 * @param int    $user_id Acting user (pqbg_manage_codes).
	 * @param float  $budget  Seconds of work before returning.
	 * @param int    $batch   Maximum items.
	 * @return array<string, mixed>|WP_Error The updated state.
	 */
	public static function run_batch( string $run_id, int $user_id, float $budget = self::TIME_BUDGET, int $batch = self::BATCH ) {
		if ( ! Permissions::can_manage_codes( $user_id ) ) {
			return new WP_Error( 'pqbg_forbidden', __( 'You are not allowed to manage product codes.', 'product-qrcode-barcode-generator' ) );
		}

		if ( ! self::lock() ) {
			return new WP_Error( 'pqbg_bulk_locked', __( 'Another batch is being processed. Try again in a moment.', 'product-qrcode-barcode-generator' ) );
		}

		try {
			$state = self::state();

			if ( null === $state || $state['id'] !== $run_id ) {
				return new WP_Error( 'pqbg_bulk_no_run', __( 'This code generation run no longer exists. Start a new one.', 'product-qrcode-barcode-generator' ) );
			}

			if ( self::RUNNING !== $state['status'] ) {
				return $state; // Stopped or finished: nothing to do.
			}

			$service = new ProductCodeService();
			$start   = microtime( true );
			$ids     = self::candidates( $state['statuses'], (int) $state['cursor'], $batch );
			$done    = 0;

			// Posts and meta of the batch (and of the variations' parents) in a few queries.
			_prime_post_caches( $ids, false, true );
			_prime_post_caches( array_values( array_unique( array_filter( array_map( 'wp_get_post_parent_id', $ids ) ) ) ), false, true );

			foreach ( $ids as $id ) {
				if ( $done > 0 && microtime( true ) - $start >= $budget ) {
					break;
				}

				++$done;
				$state['cursor'] = $id;
				$reason          = self::recheck( $id, $state['statuses'] );

				if ( '' !== $reason ) {
					++$state['skipped'];
					continue;
				}

				$created = false;

				try {
					$row = $service->get_or_create( $id, $user_id, $created );
				} catch ( \Throwable $e ) {
					$row = new WP_Error( 'pqbg_code_generation_failed', get_class( $e ) );
				}

				if ( is_wp_error( $row ) ) {
					++$state['failed'];

					if ( count( $state['errors'] ) < self::MAX_ERRORS ) {
						$state['errors'][ $id ] = $row->get_error_code();
					}
				} elseif ( $created ) {
					++$state['created'];

					if ( count( $state['created_ids'] ) < self::MAX_CREATED_IDS ) {
						$state['created_ids'][] = $id;
					}
				} else {
					++$state['had_code']; // Got a code between the query and now (another request).
				}
			}

			++$state['batches'];
			$state['updated'] = time();

			// Everything the query returned was processed and it was not a full batch: nothing is left.
			if ( $done === count( $ids ) && count( $ids ) < $batch ) {
				$state['status']   = self::DONE;
				$state['finished'] = time();
				self::log( $state, 'done' );
			}

			self::save( $state );

			return $state;
		} finally {
			self::unlock();
		}
	}

	/**
	 * Stops a run (it can be continued later).
	 *
	 * @param string $run_id Run.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function stop( string $run_id ) {
		if ( ! self::lock() ) {
			return new WP_Error( 'pqbg_bulk_locked', __( 'Another batch is being processed. Try again in a moment.', 'product-qrcode-barcode-generator' ) );
		}

		try {
			$state = self::state();

			if ( null === $state || $state['id'] !== $run_id ) {
				return new WP_Error( 'pqbg_bulk_no_run', __( 'This code generation run no longer exists. Start a new one.', 'product-qrcode-barcode-generator' ) );
			}

			if ( self::RUNNING === $state['status'] ) {
				$state['status']  = self::STOPPED;
				$state['updated'] = time();
				self::save( $state );
				self::log( $state, 'stopped' );
			}

			return $state;
		} finally {
			self::unlock();
		}
	}

	/**
	 * Continues a stopped run.
	 *
	 * @param string $run_id Run.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function resume( string $run_id ) {
		$state = self::state();

		if ( null === $state || $state['id'] !== $run_id ) {
			return new WP_Error( 'pqbg_bulk_no_run', __( 'This code generation run no longer exists. Start a new one.', 'product-qrcode-barcode-generator' ) );
		}

		if ( self::STOPPED === $state['status'] ) {
			$state['status']  = self::RUNNING;
			$state['updated'] = time();
			self::save( $state );
		}

		return $state;
	}

	/**
	 * Forgets a finished or stopped run (its log entry stays).
	 *
	 * @param string $run_id Run.
	 * @return true|WP_Error
	 */
	public static function dismiss( string $run_id ) {
		$state = self::state();

		if ( null === $state || $state['id'] !== $run_id ) {
			return true;
		}

		if ( self::is_active( $state ) ) {
			return new WP_Error( 'pqbg_bulk_busy', __( 'Stop the run before dismissing it.', 'product-qrcode-barcode-generator' ) );
		}

		if ( self::RUNNING === $state['status'] ) {
			self::log( $state, 'abandoned' );
		}

		delete_option( self::OPTION );

		return true;
	}

	/**
	 * Why an item must be skipped now ('' = go ahead): it is gone, trashed, no longer
	 * in the selection, or no longer eligible.
	 *
	 * @param int      $id       Item.
	 * @param string[] $statuses Selection.
	 */
	public static function recheck( int $id, array $statuses ): string {
		$post = get_post( $id );

		if ( ! $post ) {
			return 'gone';
		}

		if ( 'product_variation' === $post->post_type ) {
			$parent = get_post( (int) $post->post_parent );

			if ( ! in_array( $post->post_status, self::VARIATION_STATUSES, true ) || ! $parent || ! in_array( $parent->post_status, $statuses, true ) ) {
				return 'status';
			}
		} elseif ( 'product' !== $post->post_type || ! in_array( $post->post_status, $statuses, true ) ) {
			return 'status';
		}

		return ProductCodeService::is_eligible( $id ) ? '' : 'ineligible';
	}

	/**
	 * The run's created items in groups the print setup screen accepts (PrintJob::MAX_ITEMS).
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<int, int[]>
	 */
	public static function print_chunks( array $state ): array {
		return array_chunk( array_map( 'intval', (array) ( $state['created_ids'] ?? array() ) ), PrintJob::MAX_ITEMS );
	}

	/**
	 * The qualifying-items query (one row per item: id, status, type).
	 *
	 * @param string[] $statuses Selection (validated against STATUSES here as well).
	 * @param int      $after    Cursor.
	 */
	private static function sql( array $statuses, int $after ): string {
		global $wpdb;

		$statuses = array_values( array_intersect( self::STATUSES, $statuses ) );
		$in       = array() === $statuses ? "''" : "'" . implode( "','", $statuses ) . "'";
		$vin      = "'" . implode( "','", self::VARIATION_STATUSES ) . "'";
		$codes    = Schema::codes_table();
		$after    = max( 0, $after );
		// WooCommerce treats a product without a product_type term as simple.
		$type = static fn( string $alias ) => "COALESCE((SELECT t.slug FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type' JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id = {$alias}.ID LIMIT 1), 'simple')";

		return "SELECT p.ID AS id, p.post_status AS status, 'simple' AS type FROM {$wpdb->posts} p LEFT JOIN {$codes} c ON c.active_product_id = p.ID"
			. " WHERE p.post_type = 'product' AND p.post_status IN ({$in}) AND p.ID > {$after} AND c.id IS NULL AND " . $type( 'p' ) . " = 'simple'"
			. ' UNION ALL '
			. "SELECT v.ID AS id, pp.post_status AS status, 'variation' AS type FROM {$wpdb->posts} v JOIN {$wpdb->posts} pp ON pp.ID = v.post_parent AND pp.post_type = 'product' AND pp.post_status IN ({$in}) LEFT JOIN {$codes} c ON c.active_product_id = v.ID"
			. " WHERE v.post_type = 'product_variation' AND v.post_status IN ({$vin}) AND v.ID > {$after} AND c.id IS NULL AND " . $type( 'pp' ) . " = 'variable'";
	}

	/**
	 * Writes the state (not autoloaded).
	 *
	 * @param array<string, mixed> $state State.
	 */
	private static function save( array $state ): void {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $state, '', false );
		} else {
			update_option( self::OPTION, $state, false );
		}
	}

	/**
	 * Logs a run's outcome (BulkLog).
	 *
	 * @param array<string, mixed> $state   State.
	 * @param string               $outcome done|stopped|abandoned.
	 */
	private static function log( array $state, string $outcome ): void {
		BulkLog::add(
			BulkLog::TOOL_GENERATE,
			array(
				'outcome'  => $outcome,
				'statuses' => implode( ',', (array) $state['statuses'] ),
				'created'  => (int) $state['created'],
				'had_code' => (int) $state['had_code'],
				'skipped'  => (int) $state['skipped'],
				'failed'   => (int) $state['failed'],
				'seconds'  => max( 0, (int) $state['updated'] - (int) $state['started'] ),
			),
			(int) $state['user_id']
		);
	}

	/**
	 * The batch lock's name (per database and table prefix, like StockLock).
	 */
	private static function lock_name(): string {
		global $wpdb;

		return 'pqbg:' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 12 ) . ':bulk';
	}

	/**
	 * Takes the batch lock, waiting up to LOCK_TIMEOUT seconds.
	 */
	private static function lock(): bool {
		global $wpdb;

		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name(), self::LOCK_TIMEOUT ) );
	}

	/**
	 * Releases the batch lock.
	 */
	private static function unlock(): void {
		global $wpdb;

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
	}
}
