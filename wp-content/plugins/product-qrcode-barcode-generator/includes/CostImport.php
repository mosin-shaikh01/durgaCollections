<?php
/**
 * Bulk cost-price import (Phase 10, decisions D7, D9, D10, D11), administrators only
 * (pqbg_view_costs; ToolsAdmin checks it on every request, and every public method
 * here that reads or writes a cost checks it again).
 *
 * File: a header row naming an ID column ("ID", "Item ID", "Product ID") and/or a
 * "SKU" column, and a "Cost price" column (any "(₹)" suffix is ignored); other columns
 * are ignored, so the template (template()) can be edited and uploaded as it is.
 *
 * Each row (D7):
 *   - matched by ID when given, else by SKU (as WooCommerce compares SKUs: without
 *     regard to case); ID and SKU pointing to different items is an error, as is the
 *     same item on more than one row (every such row), an unknown item, a trashed one,
 *     and a type without a cost (grouped, external); a variable product's row sets the
 *     default for its variations, as on the edit screen;
 *   - cost: empty = no change; "clear" = remove the cost (unknown); otherwise a number,
 *     with an optional ₹ / Rs / Rs. / INR before or after it and an optional "/-",
 *     thousands separators only in real Western (1,200,000) or Indian (12,00,000)
 *     grouping, at most the store's price decimals (never rounded); negatives,
 *     scientific notation and text are errors. The result then goes through
 *     CostPrice::normalize() and is written only by CostPrice::set().
 *
 * Preview then apply (D10, D11): the upload only builds a preview (nothing is
 * written), stored in the user's own meta (META, one per user, with a random token,
 * expiring after TTL seconds; the uploaded file itself is already deleted). Apply
 * writes row by row, APPLY_CHUNK rows per request, and checks every row again: a cost
 * that changed since the preview is skipped ("changed since preview"), a row already at
 * its new value counts as done, so an interrupted apply can simply be continued. Rows
 * with errors are never applied; applying the valid rows of a file with errors needs
 * an explicit acknowledgement.
 *
 * Expired previews of every user are removed when the tools page loads (prune()) and
 * on uninstall.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Cost price import.
 */
final class CostImport {

	const META = 'pqbg_cost_import';

	/** Seconds a preview (and, after apply, its report) is kept. */
	const TTL = HOUR_IN_SECONDS;

	const APPLY_CHUNK = 500;

	/** Largest stored preview, below MariaDB's default 1 MB max_allowed_packet. */
	const MAX_STORED = 900000;

	/** Longest SKU kept in a preview row (display only). */
	const MAX_SKU = 100;

	const PREVIEW  = 'preview';
	const APPLYING = 'applying';
	const APPLIED  = 'applied';

	/** Row fields (rows are stored as compact lists). */
	const F_LINE    = 0;
	const F_ID      = 1;
	const F_SKU     = 2;
	const F_CURRENT = 3;
	const F_NEW     = 4;
	const F_OUTCOME = 5;
	const F_REASON  = 6;
	const F_PARAM   = 7;
	const F_RESULT  = 8;

	/** Outcomes. */
	const UPDATE    = 'update';
	const CLEAR     = 'clear';
	const NO_CHANGE = 'nochange';
	const BLANK     = 'blank';
	const ERROR     = 'error';

	/** Apply results. */
	const APPLIED_ROW = 'applied';
	const ALREADY     = 'already';
	const CHANGED     = 'changed';
	const FAILED      = 'failed';

	/** Header names (lowercase, without a "(…)" suffix). */
	const ID_HEADERS   = array( 'id', 'item id', 'product id', 'variation id' );
	const SKU_HEADERS  = array( 'sku' );
	const COST_HEADERS = array( 'cost price', 'cost' );

	/** Statuses whose products are listed in the template and may be imported. */
	const STATUSES = array( 'publish', 'private', 'draft', 'pending', 'future' );

	/**
	 * Reads an uploaded file and stores its preview for the user.
	 *
	 * @param int   $user_id User (pqbg_view_costs).
	 * @param mixed $file    The $_FILES entry.
	 * @return array<string, mixed>|WP_Error The stored import.
	 */
	public static function upload( int $user_id, $file ) {
		if ( ! Permissions::can_view_costs( $user_id ) ) {
			CsvUpload::delete( $file );
			return self::forbidden();
		}

		$current = self::load( $user_id );

		if ( null !== $current && self::APPLYING === $current['status'] ) {
			CsvUpload::delete( $file );
			return new WP_Error( 'pqbg_import_busy', __( 'An import is being applied. Continue it or cancel it before uploading another file.', 'product-qrcode-barcode-generator' ) );
		}

		$parsed = CsvUpload::from_upload( $file );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		return self::store_preview( $user_id, $parsed );
	}

	/**
	 * Builds and stores the preview of a parsed file (also used by the tests).
	 *
	 * @param int                  $user_id User (pqbg_view_costs).
	 * @param array<string, mixed> $parsed  From CsvUpload::parse().
	 * @return array<string, mixed>|WP_Error The stored import.
	 */
	public static function store_preview( int $user_id, array $parsed ) {
		if ( ! Permissions::can_view_costs( $user_id ) ) {
			return self::forbidden();
		}

		$rows = self::preview( $parsed );

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$import = array(
			'token'    => wp_generate_password( 32, false, false ),
			'created'  => time(),
			'expires'  => time() + self::TTL,
			'status'   => self::PREVIEW,
			'file'     => array(
				'name'      => (string) $parsed['name'],
				'sha256'    => (string) $parsed['sha256'],
				'encoding'  => (string) $parsed['encoding'],
				'delimiter' => (string) $parsed['delimiter'],
			),
			'counts'   => self::counts( $rows ),
			'position' => 0,
			'results'  => array_fill_keys( array( self::APPLIED_ROW, self::ALREADY, self::CHANGED, self::FAILED ), 0 ),
			'started'  => 0,
			'finished' => 0,
			'rows'     => $rows,
		);

		$saved = self::save( $user_id, $import );

		return is_wp_error( $saved ) ? $saved : $import;
	}

	/**
	 * Validates a parsed file into preview rows (writes nothing).
	 *
	 * @param array<string, mixed> $parsed From CsvUpload::parse().
	 * @return array<int, array<int, mixed>>|WP_Error
	 */
	public static function preview( array $parsed ) {
		$columns = self::columns( (array) $parsed['header'] );

		if ( is_wp_error( $columns ) ) {
			return $columns;
		}

		$cell  = static fn( array $cells, ?int $index ) => null === $index ? '' : CsvUpload::unwrap( (string) ( $cells[ $index ] ?? '' ) );
		$input = array();

		foreach ( (array) $parsed['rows'] as $row ) {
			$input[] = array(
				'line' => (int) $row['line'],
				'id'   => $cell( $row['cells'], $columns['id'] ),
				'sku'  => $cell( $row['cells'], $columns['sku'] ),
				'cost' => (string) ( $row['cells'][ $columns['cost'] ] ?? '' ),
			);
		}

		$ids   = array_map( 'intval', array_filter( array_column( $input, 'id' ), static fn( $v ) => 1 === preg_match( '/^[0-9]{1,19}$/D', $v ) ) );
		$by_id = self::items( $ids );
		$skus  = self::skus( array_values( array_unique( array_filter( array_column( $input, 'sku' ), static fn( $v ) => '' !== $v ) ) ) );
		$rows  = array();

		foreach ( $input as $in ) {
			list( $item, $reason, $param ) = self::match( $in['id'], $in['sku'], $by_id, $skus );

			if ( '' === $reason ) {
				$amount = self::parse_amount( $in['cost'] );

				if ( is_wp_error( $amount ) ) {
					$reason = $amount->get_error_code();
					$param  = (string) $amount->get_error_data();
				}
			}

			$rows[] = array(
				self::F_LINE    => $in['line'],
				self::F_ID      => $item,
				self::F_SKU     => function_exists( 'mb_substr' ) ? mb_substr( $in['sku'], 0, self::MAX_SKU ) : substr( $in['sku'], 0, self::MAX_SKU ),
				self::F_CURRENT => '',
				self::F_NEW     => '' === $reason ? $amount : null,
				self::F_OUTCOME => '' === $reason ? '' : self::ERROR,
				self::F_REASON  => $reason,
				self::F_PARAM   => $param,
				self::F_RESULT  => '',
			);
		}

		// The same item on more than one row: every one of those rows is an error.
		$lines = array();

		foreach ( $rows as $row ) {
			if ( self::ERROR !== $row[ self::F_OUTCOME ] ) {
				$lines[ $row[ self::F_ID ] ][] = $row[ self::F_LINE ];
			}
		}

		$current = CostPrice::get_many( array_keys( $lines ) );

		foreach ( $rows as $i => $row ) {
			if ( self::ERROR === $row[ self::F_OUTCOME ] ) {
				$rows[ $i ][ self::F_NEW ] = '';
				continue;
			}

			$id  = $row[ self::F_ID ];
			$now = $current[ $id ] ?? '';

			$rows[ $i ][ self::F_CURRENT ] = $now;

			if ( count( $lines[ $id ] ) > 1 ) {
				$rows[ $i ][ self::F_OUTCOME ] = self::ERROR;
				$rows[ $i ][ self::F_REASON ]  = 'duplicate';
				$rows[ $i ][ self::F_PARAM ]   = implode( ', ', $lines[ $id ] );
				$rows[ $i ][ self::F_NEW ]     = '';
				continue;
			}

			$amount = $row[ self::F_NEW ];

			if ( 'blank' === $amount['action'] ) {
				$rows[ $i ][ self::F_OUTCOME ] = self::BLANK;
				$rows[ $i ][ self::F_NEW ]     = $now;
			} elseif ( 'clear' === $amount['action'] ) {
				$rows[ $i ][ self::F_OUTCOME ] = '' === $now ? self::NO_CHANGE : self::CLEAR;
				$rows[ $i ][ self::F_NEW ]     = '';
			} else {
				$rows[ $i ][ self::F_OUTCOME ] = $amount['value'] === $now ? self::NO_CHANGE : self::UPDATE;
				$rows[ $i ][ self::F_NEW ]     = $amount['value'];
			}
		}

		return $rows;
	}

	/**
	 * Parses a cost cell (see the class notes).
	 *
	 * @param string $raw Cell.
	 * @return array{action: string, value: string}|WP_Error action blank|clear|set; the error code is the reason.
	 */
	public static function parse_amount( string $raw ) {
		$value = CsvUpload::unwrap( $raw );

		if ( '' === $value ) {
			return array(
				'action' => 'blank',
				'value'  => '',
			);
		}

		if ( 0 === strcasecmp( $value, 'clear' ) ) {
			return array(
				'action' => 'clear',
				'value'  => '',
			);
		}

		$symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		$money  = '(?:₹|rs\.?|inr' . ( '' === $symbol ? '' : '|' . preg_quote( $symbol, '/' ) ) . ')';
		$value  = (string) preg_replace( '/^' . $money . '\s*/iu', '', $value );
		$value  = (string) preg_replace( '/\s*\/-$/u', '', $value );
		$value  = trim( (string) preg_replace( '/\s*' . $money . '$/iu', '', $value ) );

		if ( str_starts_with( $value, '-' ) ) {
			return new WP_Error( 'negative' );
		}

		$decimal  = wc_get_price_decimal_separator();
		$decimal  = '' === $decimal ? '.' : $decimal;
		$group    = wc_get_price_thousand_separator();
		$d        = preg_quote( $decimal, '/' );
		$fraction = '(?:' . $d . '([0-9]+))?';
		$plain    = '/^([0-9]+)' . $fraction . '$/D';

		if ( 1 === preg_match( $plain, $value, $m ) ) {
			$int  = $m[1];
			$frac = $m[2] ?? '';
		} elseif ( '' !== $group && $group !== $decimal ) {
			$g       = preg_quote( $group, '/' );
			$western = '/^([0-9]{1,3}(?:' . $g . '[0-9]{3})+)' . $fraction . '$/D';
			$indian  = '/^([0-9]{1,2}(?:' . $g . '[0-9]{2})+' . $g . '[0-9]{3})' . $fraction . '$/D';

			if ( 1 !== preg_match( $western, $value, $m ) && 1 !== preg_match( $indian, $value, $m ) ) {
				return new WP_Error( 'not_number' );
			}

			$int  = str_replace( $group, '', $m[1] );
			$frac = $m[2] ?? '';
		} else {
			return new WP_Error( 'not_number' );
		}

		$decimals = wc_get_price_decimals();

		if ( strlen( $frac ) > $decimals ) {
			return new WP_Error( 'decimals', '', (string) $decimals );
		}

		if ( strlen( ltrim( $int, '0' ) ) > CostPrice::MAX_INTEGER_DIGITS ) {
			return new WP_Error( 'too_large' );
		}

		// CostPrice::normalize() reads the store's decimal separator.
		$normal = CostPrice::normalize( '' === $frac ? $int : $int . $decimal . $frac );

		return is_wp_error( $normal ) || '' === $normal ? new WP_Error( 'not_number' ) : array(
			'action' => 'set',
			'value'  => $normal,
		);
	}

	/**
	 * The user's stored import, or null (an expired one is deleted).
	 *
	 * @param int $user_id User.
	 * @return array<string, mixed>|null
	 */
	public static function load( int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}

		wp_cache_delete( $user_id, 'user_meta' );
		$stored = get_user_meta( $user_id, self::META, true );

		if ( ! is_array( $stored ) || ! isset( $stored['token'], $stored['expires'], $stored['data'] ) ) {
			return null;
		}

		if ( (int) $stored['expires'] < time() ) {
			self::discard( $user_id, $stored, 'expired' );
			return null;
		}

		$json = is_string( $stored['data'] ) && str_starts_with( $stored['data'], 'z:' ) && function_exists( 'gzinflate' ) ? gzinflate( (string) base64_decode( substr( $stored['data'], 2 ), true ) ) : $stored['data']; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- compressed preview rows.
		$rows = is_string( $json ) ? json_decode( $json, true ) : null;

		if ( ! is_array( $rows ) ) {
			delete_user_meta( $user_id, self::META );
			return null;
		}

		unset( $stored['data'] );
		$stored['rows'] = $rows;

		return $stored;
	}

	/**
	 * Starts applying the user's preview.
	 *
	 * @param int    $user_id User (pqbg_view_costs).
	 * @param string $token   The preview's token.
	 * @param bool   $ack     Whether the user acknowledged that rows with errors are skipped.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function start_apply( int $user_id, string $token, bool $ack ) {
		$import = self::owned( $user_id, $token );

		if ( is_wp_error( $import ) ) {
			return $import;
		}

		if ( self::PREVIEW !== $import['status'] ) {
			return $import;
		}

		if ( 0 === $import['counts'][ self::UPDATE ] + $import['counts'][ self::CLEAR ] ) {
			return new WP_Error( 'pqbg_import_nothing', __( 'Nothing to apply: no row changes a cost price.', 'product-qrcode-barcode-generator' ) );
		}

		if ( $import['counts'][ self::ERROR ] > 0 && ! $ack ) {
			return new WP_Error( 'pqbg_import_ack', __( 'Tick the box to confirm that the rows with errors are skipped.', 'product-qrcode-barcode-generator' ) );
		}

		$import['status']  = self::APPLYING;
		$import['started'] = time();
		$saved             = self::save( $user_id, $import );

		return is_wp_error( $saved ) ? $saved : $import;
	}

	/**
	 * Applies the next chunk of rows.
	 *
	 * @param int    $user_id User (pqbg_view_costs).
	 * @param string $token   The import's token.
	 * @param int    $chunk   Rows per call.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function apply_chunk( int $user_id, string $token, int $chunk = self::APPLY_CHUNK ) {
		$import = self::owned( $user_id, $token );

		if ( is_wp_error( $import ) ) {
			return $import;
		}

		if ( self::APPLYING !== $import['status'] ) {
			return $import;
		}

		$rows  = $import['rows'];
		$total = count( $rows );
		$end   = min( $total, (int) $import['position'] + max( 1, $chunk ) );

		for ( $i = (int) $import['position']; $i < $end; $i++ ) {
			$row = $rows[ $i ];

			if ( in_array( $row[ self::F_OUTCOME ], array( self::UPDATE, self::CLEAR ), true ) ) {
				$id = (int) $row[ self::F_ID ];
				wp_cache_delete( $id, 'post_meta' );
				$now = CostPrice::get( $id );

				if ( $now === $row[ self::F_NEW ] ) {
					$result = self::ALREADY;
				} elseif ( $now !== $row[ self::F_CURRENT ] ) {
					$result = self::CHANGED;
				} else {
					CostPrice::set( $id, (string) $row[ self::F_NEW ] );
					wp_cache_delete( $id, 'post_meta' );
					$result = CostPrice::get( $id ) === $row[ self::F_NEW ] ? self::APPLIED_ROW : self::FAILED;
				}

				$rows[ $i ][ self::F_RESULT ] = $result;
				++$import['results'][ $result ];
			}

			$import['position'] = $i + 1;
		}

		$import['rows'] = $rows;

		if ( $import['position'] >= $total ) {
			$import['status']   = self::APPLIED;
			$import['finished'] = time();
			$import['expires']  = time() + self::TTL; // Keep the report for another hour.
			self::log( $user_id, $import, 'applied' );
		}

		$saved = self::save( $user_id, $import );

		return is_wp_error( $saved ) ? $saved : $import;
	}

	/**
	 * Cancels the user's import (a partly applied one is logged as interrupted).
	 *
	 * @param int    $user_id User (pqbg_view_costs).
	 * @param string $token   Token.
	 * @return true|WP_Error
	 */
	public static function cancel( int $user_id, string $token ) {
		$import = self::owned( $user_id, $token );

		if ( is_wp_error( $import ) ) {
			return $import;
		}

		self::discard( $user_id, $import, 'cancelled' );

		return true;
	}

	/**
	 * Deletes every user's expired import (the tools page runs this on load; uninstall
	 * deletes them all). Returns how many were deleted.
	 */
	public static function prune(): int {
		global $wpdb;

		$deleted = 0;

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one row per user with an import.
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", self::META ) ) as $user_id ) {
			wp_cache_delete( (int) $user_id, 'user_meta' );
			$stored = get_user_meta( (int) $user_id, self::META, true );

			if ( ! is_array( $stored ) || (int) ( $stored['expires'] ?? 0 ) < time() ) {
				self::discard( (int) $user_id, is_array( $stored ) ? $stored : array(), 'expired' );
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Writes the report of an import (every row with its outcome, reason and result).
	 *
	 * @param resource             $out    Stream.
	 * @param array<string, mixed> $import Loaded import.
	 */
	public static function write_report( $out, array $import ): void {
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- stream output.

		$symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		SalesExport::put(
			$out,
			array(
				__( 'Line', 'product-qrcode-barcode-generator' ),
				__( 'Item ID', 'product-qrcode-barcode-generator' ),
				__( 'SKU', 'product-qrcode-barcode-generator' ),
				__( 'Product', 'product-qrcode-barcode-generator' ),
				/* translators: %s: currency symbol. */
				sprintf( __( 'Cost before (%s)', 'product-qrcode-barcode-generator' ), $symbol ),
				/* translators: %s: currency symbol. */
				sprintf( __( 'New cost (%s)', 'product-qrcode-barcode-generator' ), $symbol ),
				__( 'Outcome', 'product-qrcode-barcode-generator' ),
				__( 'Reason', 'product-qrcode-barcode-generator' ),
				__( 'Result', 'product-qrcode-barcode-generator' ),
			)
		);

		$ids = array_values( array_unique( array_filter( array_map( static fn( $r ) => (int) $r[ self::F_ID ], $import['rows'] ) ) ) );

		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			_prime_post_caches( $chunk, false, false );
		}

		foreach ( $import['rows'] as $row ) {
			$error = self::ERROR === $row[ self::F_OUTCOME ];
			SalesExport::put(
				$out,
				array(
					(string) $row[ self::F_LINE ],
					$row[ self::F_ID ] > 0 ? (string) $row[ self::F_ID ] : '',
					(string) $row[ self::F_SKU ],
					self::name( (int) $row[ self::F_ID ] ),
					$error ? '' : (string) $row[ self::F_CURRENT ],
					$error || self::BLANK === $row[ self::F_OUTCOME ] ? '' : (string) $row[ self::F_NEW ],
					self::outcome_label( (string) $row[ self::F_OUTCOME ] ),
					self::reason( (string) $row[ self::F_REASON ], (string) $row[ self::F_PARAM ], (string) $row[ self::F_SKU ] ),
					self::result_label( (string) $row[ self::F_RESULT ] ),
				)
			);
		}
	}

	/**
	 * Writes the template (D9): every simple product, variable product (its default for
	 * variations) and variation, with the current cost. Administrators only.
	 *
	 * @param resource $out   Stream.
	 * @param bool     $flush Flush after each chunk (HTTP).
	 * @return int Rows written.
	 */
	public static function write_template( $out, bool $flush = false ): int {
		global $wpdb;

		if ( ! Permissions::can_view_costs() ) {
			return 0;
		}

		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- stream output.

		$symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		SalesExport::put(
			$out,
			array(
				__( 'Item ID', 'product-qrcode-barcode-generator' ),
				__( 'Parent ID', 'product-qrcode-barcode-generator' ),
				__( 'Type', 'product-qrcode-barcode-generator' ),
				__( 'SKU', 'product-qrcode-barcode-generator' ),
				__( 'Product', 'product-qrcode-barcode-generator' ),
				__( 'Attributes', 'product-qrcode-barcode-generator' ),
				/* translators: %s: currency symbol. */
				sprintf( __( 'Cost price (%s)', 'product-qrcode-barcode-generator' ), $symbol ),
			)
		);

		$in       = "'" . implode( "','", self::STATUSES ) . "'";
		$products = (array) $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ({$in}) ORDER BY post_title, ID", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed literals.
		$count    = 0;

		foreach ( array_chunk( $products, 200 ) as $chunk ) {
			$ids   = array_map( static fn( $p ) => (int) $p['ID'], $chunk );
			$types = self::types( $ids );
			$vars  = array();
			$var   = array_keys( array_filter( $types, static fn( $t ) => 'variable' === $t ) );

			if ( array() !== $var ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table; integer IDs.
				foreach ( (array) $wpdb->get_results( "SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_status IN ('publish', 'private') AND post_parent IN (" . implode( ',', $var ) . ') ORDER BY menu_order, ID', ARRAY_A ) as $v ) {
					$vars[ (int) $v['post_parent'] ][] = (int) $v['ID'];
				}
			}

			$all  = array_merge( $ids, ...array_values( $vars ) );
			$cost = CostPrice::get_many( $all );
			_prime_post_caches( $all, false, true );

			foreach ( $chunk as $p ) {
				$id   = (int) $p['ID'];
				$type = $types[ $id ] ?? 'simple';

				if ( ! in_array( $type, array( 'simple', 'variable' ), true ) ) {
					continue;
				}

				$name = html_entity_decode( (string) $p['post_title'], ENT_QUOTES, 'UTF-8' );
				SalesExport::put( $out, array( (string) $id, '0', $type, (string) get_post_meta( $id, '_sku', true ), $name, 'variable' === $type ? __( '(default for variations without their own cost)', 'product-qrcode-barcode-generator' ) : '', $cost[ $id ] ?? '' ) );
				++$count;

				foreach ( $vars[ $id ] ?? array() as $vid ) {
					SalesExport::put( $out, array( (string) $vid, (string) $id, 'variation', (string) get_post_meta( $vid, '_sku', true ), $name, CodesExport::attributes( $vid ), $cost[ $vid ] ?? '' ) );
					++$count;
				}
			}

			if ( $flush ) {
				fflush( $out );
				flush();
				wp_cache_flush_runtime();
			}
		}

		return $count;
	}

	/**
	 * Counts of outcomes.
	 *
	 * @param array<int, array<int, mixed>> $rows Rows.
	 * @return array<string, int>
	 */
	public static function counts( array $rows ): array {
		$counts = array_fill_keys( array( self::UPDATE, self::CLEAR, self::NO_CHANGE, self::BLANK, self::ERROR ), 0 );

		foreach ( $rows as $row ) {
			++$counts[ $row[ self::F_OUTCOME ] ];
		}

		return $counts;
	}

	/**
	 * A row's reason as text.
	 *
	 * @param string $reason Reason code.
	 * @param string $param  Its parameter.
	 * @param string $sku    The row's SKU (for the leading-zero hint).
	 */
	public static function reason( string $reason, string $param = '', string $sku = '' ): string {
		switch ( $reason ) {
			case '':
				return '';
			case 'no_key':
				return __( 'Give an ID or a SKU.', 'product-qrcode-barcode-generator' );
			case 'bad_id':
				return __( 'The ID must be a whole number.', 'product-qrcode-barcode-generator' );
			case 'no_id':
				return __( 'No product or variation has this ID.', 'product-qrcode-barcode-generator' );
			case 'no_sku':
				$text = __( 'No product or variation has this SKU.', 'product-qrcode-barcode-generator' );
				return 1 === preg_match( '/^[0-9]+$/D', $sku ) ? $text . ' ' . __( 'If the SKU starts with 0, Excel may have removed the zeros: see the README.', 'product-qrcode-barcode-generator' ) : $text;
			case 'sku_ambiguous':
				return __( 'More than one product or variation has this SKU. Use the ID instead.', 'product-qrcode-barcode-generator' );
			case 'mismatch':
				return __( 'The ID and the SKU belong to different products.', 'product-qrcode-barcode-generator' );
			case 'trashed':
				return __( 'The product is in the trash or not saved yet.', 'product-qrcode-barcode-generator' );
			case 'bad_type':
				return __( 'This product type has no cost price (only simple products, variable products and variations have one).', 'product-qrcode-barcode-generator' );
			case 'orphan':
				return __( 'The variation\'s parent product is missing, in the trash or not a variable product.', 'product-qrcode-barcode-generator' );
			case 'duplicate':
				/* translators: %s: line numbers. */
				return sprintf( __( 'This product appears on more than one row (lines %s). Keep one row per product.', 'product-qrcode-barcode-generator' ), $param );
			case 'negative':
				return __( 'The cost price cannot be negative.', 'product-qrcode-barcode-generator' );
			case 'decimals':
				/* translators: %s: number of decimals. */
				return sprintf( __( 'More than %s decimals. The value is not rounded; correct it in the file.', 'product-qrcode-barcode-generator' ), $param );
			case 'too_large':
				/* translators: %d: number of digits. */
				return sprintf( __( 'The cost price is too large (at most %d digits before the decimal point).', 'product-qrcode-barcode-generator' ), CostPrice::MAX_INTEGER_DIGITS );
			case 'not_number':
				return __( 'Not a number. Use e.g. 1200.50, 1,200.50, 1,20,000 or ₹1200. Leave the cell empty for no change, or write "clear" to remove the cost price.', 'product-qrcode-barcode-generator' );
			default:
				return $reason;
		}
	}

	/**
	 * An outcome's label.
	 *
	 * @param string $outcome Outcome.
	 */
	public static function outcome_label( string $outcome ): string {
		$labels = array(
			self::UPDATE    => __( 'Update', 'product-qrcode-barcode-generator' ),
			self::CLEAR     => __( 'Clear (becomes unknown)', 'product-qrcode-barcode-generator' ),
			self::NO_CHANGE => __( 'No change (same value)', 'product-qrcode-barcode-generator' ),
			self::BLANK     => __( 'No change (empty cell)', 'product-qrcode-barcode-generator' ),
			self::ERROR     => __( 'Error', 'product-qrcode-barcode-generator' ),
		);

		return $labels[ $outcome ] ?? $outcome;
	}

	/**
	 * An apply result's label.
	 *
	 * @param string $result Result.
	 */
	public static function result_label( string $result ): string {
		$labels = array(
			self::APPLIED_ROW => __( 'Applied', 'product-qrcode-barcode-generator' ),
			self::ALREADY     => __( 'Already had this value', 'product-qrcode-barcode-generator' ),
			self::CHANGED     => __( 'Skipped: the cost was changed after the preview', 'product-qrcode-barcode-generator' ),
			self::FAILED      => __( 'Failed', 'product-qrcode-barcode-generator' ),
		);

		return $labels[ $result ] ?? '';
	}

	/**
	 * The name of an item for display ('' when unknown).
	 *
	 * @param int $id Item.
	 */
	public static function name( int $id ): string {
		$post = $id > 0 ? get_post( $id ) : null;

		return $post ? html_entity_decode( (string) $post->post_title, ENT_QUOTES, 'UTF-8' ) : '';
	}

	/**
	 * Maps the header row to column indexes.
	 *
	 * @param string[] $header Header cells.
	 * @return array{id: int|null, sku: int|null, cost: int}|WP_Error
	 */
	private static function columns( array $header ) {
		$found = array(
			'id'   => null,
			'sku'  => null,
			'cost' => null,
		);

		foreach ( $header as $index => $cell ) {
			$name = strtolower( trim( (string) preg_replace( '/\s+/u', ' ', (string) preg_replace( '/\s*\(.*\)\s*$/u', '', CsvUpload::unwrap( (string) $cell ) ) ) ) );
			$key  = in_array( $name, self::ID_HEADERS, true ) ? 'id' : ( in_array( $name, self::SKU_HEADERS, true ) ? 'sku' : ( in_array( $name, self::COST_HEADERS, true ) ? 'cost' : '' ) );

			if ( '' === $key ) {
				continue;
			}

			if ( null !== $found[ $key ] ) {
				/* translators: %s: column name. */
				return new WP_Error( 'pqbg_import_file', sprintf( __( 'The file has more than one "%s" column.', 'product-qrcode-barcode-generator' ), CsvUpload::unwrap( (string) $cell ) ) );
			}

			$found[ $key ] = (int) $index;
		}

		if ( null === $found['cost'] ) {
			return new WP_Error( 'pqbg_import_file', __( 'The file needs a "Cost price" column. Download the template to see the format.', 'product-qrcode-barcode-generator' ) );
		}

		if ( null === $found['id'] && null === $found['sku'] ) {
			return new WP_Error( 'pqbg_import_file', __( 'The file needs an "ID" or a "SKU" column. Download the template to see the format.', 'product-qrcode-barcode-generator' ) );
		}

		return $found;
	}

	/**
	 * Resolves a row's ID/SKU to an item.
	 *
	 * @param string                              $id    ID cell.
	 * @param string                              $sku   SKU cell.
	 * @param array<int, array<string, mixed>>    $by_id Items by ID (items()).
	 * @param array<string, int[]>                $skus  Lowercased SKU => item IDs (skus()).
	 * @return array{0: int, 1: string, 2: string} item ID, reason code ('' = fine), parameter
	 */
	private static function match( string $id, string $sku, array $by_id, array $skus ): array {
		if ( '' === $id && '' === $sku ) {
			return array( 0, 'no_key', '' );
		}

		$from_sku = 0;

		if ( '' !== $sku ) {
			$found = $skus[ self::sku_key( $sku ) ] ?? array();

			if ( count( $found ) > 1 ) {
				return array( 0, 'sku_ambiguous', '' );
			}

			$from_sku = (int) ( $found[0] ?? 0 );
		}

		if ( '' !== $id ) {
			if ( 1 !== preg_match( '/^[0-9]{1,19}$/D', $id ) ) {
				return array( 0, 'bad_id', '' );
			}

			$item = (int) $id;

			if ( ! isset( $by_id[ $item ] ) ) {
				return array( 0, 'no_id', '' );
			}

			if ( '' !== $sku && $from_sku !== $item ) {
				return array( $item, 'mismatch', '' );
			}
		} else {
			if ( 0 === $from_sku ) {
				return array( 0, 'no_sku', '' );
			}

			$item = $from_sku;
		}

		$reason = (string) ( $by_id[ $item ]['reason'] ?? '' );

		return array( $item, $reason, '' );
	}

	/**
	 * Items by ID with the reason they cannot have a cost ('' = they can).
	 *
	 * @param int[] $ids IDs.
	 * @return array<int, array{reason: string}>
	 */
	private static function items( array $ids ): array {
		global $wpdb;

		$ids   = array_values( array_unique( array_filter( $ids ) ) );
		$posts = array();

		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table; integer IDs.
			foreach ( (array) $wpdb->get_results( "SELECT ID, post_type, post_status, post_parent FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation') AND ID IN (" . implode( ',', $chunk ) . ')', ARRAY_A ) as $row ) {
				$posts[ (int) $row['ID'] ] = $row;
			}
		}

		$parents = array_values( array_unique( array_filter( array_map( static fn( $p ) => 'product_variation' === $p['post_type'] ? (int) $p['post_parent'] : 0, $posts ) ) ) );
		$pstatus = array();

		foreach ( array_chunk( $parents, 1000 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table; integer IDs.
			foreach ( (array) $wpdb->get_results( "SELECT ID, post_status FROM {$wpdb->posts} WHERE post_type = 'product' AND ID IN (" . implode( ',', $chunk ) . ')', ARRAY_A ) as $row ) {
				$pstatus[ (int) $row['ID'] ] = (string) $row['post_status'];
			}
		}

		$types = self::types( array_merge( array_keys( array_filter( $posts, static fn( $p ) => 'product' === $p['post_type'] ) ), $parents ) );
		$out   = array();

		foreach ( $posts as $id => $p ) {
			if ( ! in_array( $p['post_status'], self::STATUSES, true ) && ! ( 'product_variation' === $p['post_type'] && in_array( $p['post_status'], array( 'publish', 'private' ), true ) ) ) {
				$reason = 'trashed';
			} elseif ( 'product_variation' === $p['post_type'] ) {
				$parent = (int) $p['post_parent'];
				$reason = isset( $pstatus[ $parent ] ) && in_array( $pstatus[ $parent ], self::STATUSES, true ) && 'variable' === ( $types[ $parent ] ?? '' ) ? '' : 'orphan';
			} else {
				$reason = in_array( $types[ $id ] ?? 'simple', array( 'simple', 'variable' ), true ) ? '' : 'bad_type';
			}

			$out[ $id ] = array( 'reason' => $reason );
		}

		return $out;
	}

	/**
	 * Items by SKU (as WooCommerce compares them: without regard to case), ignoring the trash.
	 *
	 * @param string[] $skus SKUs.
	 * @return array<string, int[]> sku_key() => item IDs
	 */
	private static function skus( array $skus ): array {
		global $wpdb;

		$out = array();

		foreach ( array_chunk( $skus, 500 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- core tables; one %s per SKU.
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type IN ('product', 'product_variation') AND p.post_status NOT IN ('trash', 'auto-draft', 'importing') WHERE m.meta_key = '_sku' AND m.meta_value IN ({$in})", $chunk ), ARRAY_A ) as $row ) {
				$key = self::sku_key( (string) $row['meta_value'] );

				if ( ! in_array( (int) $row['post_id'], $out[ $key ] ?? array(), true ) ) {
					$out[ $key ][] = (int) $row['post_id'];
				}
			}
		}

		return $out;
	}

	/**
	 * A SKU's comparison key.
	 *
	 * @param string $sku SKU.
	 */
	private static function sku_key( string $sku ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $sku ), 'UTF-8' ) : strtolower( trim( $sku ) );
	}

	/**
	 * Product type slugs (a product without a type term is simple, as in WooCommerce).
	 *
	 * @param int[] $ids Products.
	 * @return array<int, string>
	 */
	private static function types( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$out = array_fill_keys( $ids, 'simple' );

		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core tables; integer IDs.
			foreach ( (array) $wpdb->get_results( "SELECT tr.object_id AS id, t.slug FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type' JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id IN (" . implode( ',', $chunk ) . ')', ARRAY_A ) as $row ) {
				$out[ (int) $row['id'] ] = (string) $row['slug'];
			}
		}

		return $out;
	}

	/**
	 * The user's import if the token matches.
	 *
	 * @param int    $user_id User (pqbg_view_costs).
	 * @param string $token   Token.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function owned( int $user_id, string $token ) {
		if ( ! Permissions::can_view_costs( $user_id ) ) {
			return self::forbidden();
		}

		$import = self::load( $user_id );

		if ( null === $import || '' === $token || ! hash_equals( (string) $import['token'], $token ) ) {
			return new WP_Error( 'pqbg_import_expired', __( 'This import has expired or was replaced. Upload the file again.', 'product-qrcode-barcode-generator' ) );
		}

		return $import;
	}

	/**
	 * Stores an import in the user's meta (rows compressed).
	 *
	 * @param int                  $user_id User.
	 * @param array<string, mixed> $import  Import.
	 * @return true|WP_Error
	 */
	private static function save( int $user_id, array $import ) {
		$json = (string) wp_json_encode( $import['rows'] );
		$data = function_exists( 'gzdeflate' ) ? 'z:' . base64_encode( (string) gzdeflate( $json, 6 ) ) : $json; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- compressed preview rows.

		if ( strlen( $data ) > self::MAX_STORED ) {
			return new WP_Error( 'pqbg_import_file', __( 'The file is too large to preview. Split it into smaller files.', 'product-qrcode-barcode-generator' ) );
		}

		unset( $import['rows'] );
		$import['data'] = $data;
		update_user_meta( $user_id, self::META, $import );

		return true;
	}

	/**
	 * Deletes a stored import; a partly applied one is logged as interrupted.
	 *
	 * @param int                  $user_id User.
	 * @param array<string, mixed> $import  Import (stored or loaded).
	 * @param string               $why     cancelled|expired.
	 */
	private static function discard( int $user_id, array $import, string $why ): void {
		if ( self::APPLYING === ( $import['status'] ?? '' ) ) {
			self::log( $user_id, $import, 'interrupted (' . $why . ')' );
		}

		delete_user_meta( $user_id, self::META );
	}

	/**
	 * Logs an import (counts only, never a cost).
	 *
	 * @param int                  $user_id User.
	 * @param array<string, mixed> $import  Import.
	 * @param string               $outcome Outcome.
	 */
	private static function log( int $user_id, array $import, string $outcome ): void {
		$counts  = (array) ( $import['counts'] ?? array() );
		$results = (array) ( $import['results'] ?? array() );

		BulkLog::add(
			BulkLog::TOOL_COST_IMPORT,
			array(
				'outcome'        => $outcome,
				'file'           => (string) ( $import['file']['name'] ?? '' ),
				'sha256'         => (string) ( $import['file']['sha256'] ?? '' ),
				'rows'           => array_sum( array_map( 'intval', $counts ) ),
				'changed'        => (int) ( $results[ self::APPLIED_ROW ] ?? 0 ),
				'already'        => (int) ( $results[ self::ALREADY ] ?? 0 ),
				'skipped_stale'  => (int) ( $results[ self::CHANGED ] ?? 0 ),
				'failed'         => (int) ( $results[ self::FAILED ] ?? 0 ),
				'errors_skipped' => (int) ( $counts[ self::ERROR ] ?? 0 ),
				'no_change'      => (int) ( $counts[ self::NO_CHANGE ] ?? 0 ) + (int) ( $counts[ self::BLANK ] ?? 0 ),
			),
			$user_id
		);
	}

	/**
	 * Refusal for users without pqbg_view_costs.
	 */
	private static function forbidden(): WP_Error {
		return new WP_Error( 'pqbg_forbidden', __( 'Sorry, you are not allowed to see or change cost prices.', 'product-qrcode-barcode-generator' ) );
	}
}
