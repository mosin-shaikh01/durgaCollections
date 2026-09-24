<?php
/**
 * Activation, deactivation, versioned migrations and the install lock.
 *
 * Adding a migration in a later phase:
 *   1. Update Schema::statements() to the new current schema (if tables change).
 *   2. Add `N => array( __CLASS__, 'migrate_N' )` to migrations() and bump DB_VERSION to N.
 *   3. migrate_N() must be idempotent: call Schema::create_or_update() for
 *      additive changes, then any data transforms guarded so re-running is safe.
 * Migrations never drop tables or delete rows.
 *
 * @package Durga\ProductCodes
 */

namespace Durga\ProductCodes;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Installs and upgrades the plugin's database state.
 */
final class Install {

	/** Current schema version. Must equal the highest key in migrations(). */
	const DB_VERSION = 1;

	const DB_VERSION_OPTION = 'dpc_db_version';
	const LOCK_OPTION       = 'dpc_install_lock';

	/** Seconds after which an abandoned lock (crashed request) may be taken over. */
	const LOCK_TTL = 300;

	/**
	 * Ordered migrations: target version => callback returning true or WP_Error.
	 *
	 * @return array<int, callable>
	 */
	public static function migrations(): array {
		return array(
			1 => array( __CLASS__, 'migrate_1' ),
		);
	}

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			wp_die(
				esc_html__( 'Durga Product Codes cannot be network activated. Activate it on each site individually.', 'durga-product-codes' ),
				esc_html__( 'Plugin activation failed', 'durga-product-codes' ),
				array( 'back_link' => true )
			);
		}

		$errors = Requirements::errors();

		if ( array() !== $errors ) {
			wp_die(
				'<p>' . implode( '</p><p>', array_map( 'esc_html', $errors ) ) . '</p>',
				esc_html__( 'Plugin activation failed', 'durga-product-codes' ),
				array( 'back_link' => true )
			);
		}

		$result = self::install();

		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html( $result->get_error_message() ),
				esc_html__( 'Plugin activation failed', 'durga-product-codes' ),
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Deactivation hook. Intentionally non-destructive: tables, codes, sales,
	 * settings, the Seller role and capabilities are all kept. Phase 2 adds no
	 * rewrite rules or cron events, so there is nothing to clean up.
	 */
	public static function deactivate(): void {
	}

	/**
	 * Runs pending migrations when the stored schema version is behind the code.
	 * Called on every boot; costs a single (autoloaded) option read when current.
	 */
	public static function maybe_upgrade(): void {
		if ( self::stored_version() >= self::DB_VERSION ) {
			return;
		}

		$result = self::install();

		// A held lock means another request is already upgrading; it is not an error.
		if ( is_wp_error( $result ) && 'dpc_install_locked' !== $result->get_error_code() && function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( 'Upgrade failed: ' . $result->get_error_message(), array( 'source' => 'durga-product-codes' ) );
		}
	}

	/**
	 * Applies pending migrations, ensures settings exist and syncs roles. Idempotent.
	 *
	 * @return true|WP_Error
	 */
	public static function install() {
		$token = self::acquire_lock();

		if ( false === $token ) {
			return new WP_Error( 'dpc_install_locked', __( 'Another Durga Product Codes installation or upgrade is in progress. Try again in a few minutes.', 'durga-product-codes' ) );
		}

		try {
			$version = self::stored_version();

			// Tables missing despite a stored version (e.g. dropped manually): rebuild from scratch.
			if ( $version > 0 && ! Schema::tables_exist() ) {
				$version = 0;
			}

			foreach ( self::migrations() as $target => $callback ) {
				if ( $target <= $version ) {
					continue;
				}

				$result = call_user_func( $callback );

				if ( is_wp_error( $result ) ) {
					return $result; // Version stays at the last successful migration.
				}

				update_option( self::DB_VERSION_OPTION, $target, true );
				$version = $target;
			}

			self::ensure_settings();
			Permissions::sync_roles();

			return true;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'dpc_install_exception', $e->getMessage() );
		} finally {
			self::release_lock( $token );
		}
	}

	/**
	 * Schema version 1: initial dpc_codes and dpc_sales tables.
	 *
	 * @return true|WP_Error
	 */
	public static function migrate_1() {
		global $wpdb;

		Schema::create_or_update();

		if ( ! Schema::tables_exist() ) {
			return new WP_Error( 'dpc_schema_failed', __( 'Durga Product Codes could not create its database tables.', 'durga-product-codes' ) . ' ' . $wpdb->last_error );
		}

		// Defence in depth only; CodeRepository enforces the invariant on every server.
		Schema::ensure_active_check();

		return true;
	}

	/**
	 * Schema version recorded in the database (0 when never installed).
	 */
	public static function stored_version(): int {
		return (int) get_option( self::DB_VERSION_OPTION, 0 );
	}

	/**
	 * Creates the non-autoloaded settings option with defaults if it does not exist.
	 */
	private static function ensure_settings(): void {
		if ( false === get_option( Plugin::SETTINGS_OPTION, false ) ) {
			add_option( Plugin::SETTINGS_OPTION, Plugin::default_settings(), '', false );
		}
	}

	/**
	 * Takes the install lock.
	 *
	 * Uses INSERT IGNORE on the options table because add_option() performs
	 * INSERT ... ON DUPLICATE KEY UPDATE and is therefore not an atomic lock.
	 * A lock older than LOCK_TTL is treated as abandoned and replaced with a
	 * compare-and-delete, so a crashed request can never block upgrades forever.
	 *
	 * @return string|false Lock token, or false when another process holds a live lock.
	 */
	public static function acquire_lock() {
		global $wpdb;

		$token = ( time() + self::LOCK_TTL ) . ':' . wp_generate_uuid4();

		if ( self::insert_lock( $token ) ) {
			return $token;
		}

		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );

		if ( null !== $held && (int) strtok( (string) $held, ':' ) > time() ) {
			return false;
		}

		if ( null !== $held ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $held ) );
		}

		return self::insert_lock( $token ) ? $token : false;
	}

	/**
	 * Releases the lock only if it is still ours.
	 *
	 * @param string $token Token returned by acquire_lock().
	 */
	public static function release_lock( string $token ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $token ) );
	}

	/**
	 * Atomically inserts the lock row.
	 *
	 * @param string $token Lock token.
	 */
	private static function insert_lock( string $token ): bool {
		global $wpdb;

		return 1 === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::LOCK_OPTION, $token ) );
	}
}
