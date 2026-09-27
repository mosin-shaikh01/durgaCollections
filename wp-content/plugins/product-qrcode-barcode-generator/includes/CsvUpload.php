<?php
/**
 * Reads an uploaded CSV file for the Phase 10 imports (decision D10).
 *
 *   - limits: MAX_BYTES, MAX_ROWS data rows, MAX_COLUMNS columns, MAX_CELL bytes per cell
 *   - a .csv name, a text MIME type (finfo), no NUL bytes
 *   - UTF-8 with or without a byte order mark; a file that is not valid UTF-8 is read
 *     as Windows-1252 (Excel's "CSV (Comma delimited)"), and the result says so
 *   - comma separated, or semicolon when the header row has semicolons and no commas
 *   - empty rows are ignored
 * The file is read straight from PHP's temporary upload folder (outside the web root)
 * after is_uploaded_file(), and deleted as soon as it has been read, whatever the
 * outcome; it is never moved anywhere, so it is never in a public location.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Uploaded CSV reader.
 */
final class CsvUpload {

	const MAX_BYTES   = 1048576;
	const MAX_ROWS    = 5000;
	const MAX_COLUMNS = 20;
	const MAX_CELL    = 1000;

	/** MIME types finfo reports for CSV files. */
	const MIME_TYPES = array( 'text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'text/comma-separated-values' );

	/**
	 * Reads and deletes an uploaded file ($_FILES entry).
	 *
	 * @param mixed $file The $_FILES entry.
	 * @return array{name: string, sha256: string, encoding: string, delimiter: string, header: string[], rows: array<int, array{line: int, cells: string[]}>}|WP_Error
	 */
	public static function from_upload( $file ) {
		$tmp = is_array( $file ) && isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';

		try {
			if ( ! is_array( $file ) || ! isset( $file['error'], $file['name'], $file['size'] ) || is_array( $file['error'] ) ) {
				return self::error( __( 'Choose a CSV file to upload.', 'product-qrcode-barcode-generator' ) );
			}

			if ( UPLOAD_ERR_INI_SIZE === (int) $file['error'] || UPLOAD_ERR_FORM_SIZE === (int) $file['error'] || (int) $file['size'] > self::MAX_BYTES ) {
				return self::too_big();
			}

			if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
				return self::error( __( 'Choose a CSV file to upload.', 'product-qrcode-barcode-generator' ) );
			}

			if ( UPLOAD_ERR_OK !== (int) $file['error'] || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
				return self::error( __( 'The file could not be uploaded. Try again.', 'product-qrcode-barcode-generator' ) );
			}

			$name = sanitize_file_name( wp_basename( (string) $file['name'] ) );

			if ( 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
				return self::error( __( 'Only .csv files can be imported. In Excel use File → Save As → "CSV UTF-8 (Comma delimited)".', 'product-qrcode-barcode-generator' ) );
			}

			$finfo = function_exists( 'finfo_open' ) ? finfo_open( FILEINFO_MIME_TYPE ) : false;
			$mime  = $finfo ? (string) finfo_file( $finfo, $tmp ) : '';

			if ( '' !== $mime && ! in_array( $mime, self::MIME_TYPES, true ) ) {
				return self::error( __( 'This file is not a CSV text file.', 'product-qrcode-barcode-generator' ) );
			}

			$bytes = file_get_contents( $tmp, false, null, 0, self::MAX_BYTES + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temporary upload.

			if ( false === $bytes ) {
				return self::error( __( 'The file could not be read. Try again.', 'product-qrcode-barcode-generator' ) );
			}

			return self::parse( $bytes, $name );
		} finally {
			if ( '' !== $tmp && is_file( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	/**
	 * Deletes an uploaded file without reading it (refused requests).
	 *
	 * @param mixed $file The $_FILES entry.
	 */
	public static function delete( $file ): void {
		$tmp = is_array( $file ) && isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';

		if ( '' !== $tmp && is_uploaded_file( $tmp ) && is_file( $tmp ) ) {
			wp_delete_file( $tmp );
		}
	}

	/**
	 * Parses CSV bytes.
	 *
	 * @param string $bytes File contents.
	 * @param string $name  File name (for display).
	 * @return array{name: string, sha256: string, encoding: string, delimiter: string, header: string[], rows: array<int, array{line: int, cells: string[]}>}|WP_Error
	 */
	public static function parse( string $bytes, string $name ) {
		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			return self::too_big();
		}

		if ( false !== strpos( $bytes, "\0" ) ) {
			return self::error( __( 'This file is not a CSV text file.', 'product-qrcode-barcode-generator' ) );
		}

		$sha      = hash( 'sha256', $bytes );
		$encoding = 'UTF-8';

		if ( str_starts_with( $bytes, "\xEF\xBB\xBF" ) ) {
			$bytes = substr( $bytes, 3 );
		}

		if ( ! self::is_utf8( $bytes ) ) {
			if ( ! function_exists( 'mb_convert_encoding' ) ) {
				return self::error( __( 'Save the file as "CSV UTF-8 (Comma delimited)" and upload it again.', 'product-qrcode-barcode-generator' ) );
			}

			$bytes    = (string) mb_convert_encoding( $bytes, 'UTF-8', 'Windows-1252' );
			$encoding = 'Windows-1252';
		}

		$first     = strtok( $bytes, "\r\n" );
		$first     = false === $first ? '' : $first;
		$delimiter = ( str_contains( $first, ';' ) && ! str_contains( $first, ',' ) ) ? ';' : ',';

		$fh = fopen( 'php://temp', 'w+' );
		fwrite( $fh, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- memory stream.
		rewind( $fh );

		$header = null;
		$rows   = array();
		$line   = 0;

		try {
			while ( false !== ( $cells = fgetcsv( $fh, 0, $delimiter, '"', '' ) ) ) {
				++$line;
				$cells = array_map( static fn( $c ) => (string) $c, $cells );

				if ( array() === array_filter( $cells, static fn( $c ) => '' !== trim( $c ) ) ) {
					continue; // Empty row.
				}

				if ( count( $cells ) > self::MAX_COLUMNS ) {
					/* translators: 1: line number, 2: maximum columns. */
					return self::error( sprintf( __( 'Line %1$d has more than %2$d columns.', 'product-qrcode-barcode-generator' ), $line, self::MAX_COLUMNS ) );
				}

				foreach ( $cells as $cell ) {
					if ( strlen( $cell ) > self::MAX_CELL ) {
						/* translators: 1: line number, 2: maximum bytes. */
						return self::error( sprintf( __( 'Line %1$d has a cell longer than %2$d characters.', 'product-qrcode-barcode-generator' ), $line, self::MAX_CELL ) );
					}
				}

				if ( null === $header ) {
					$header = $cells;
					continue;
				}

				if ( count( $rows ) >= self::MAX_ROWS ) {
					/* translators: %d: maximum rows. */
					return self::error( sprintf( __( 'The file has more than %d rows. Split it into smaller files.', 'product-qrcode-barcode-generator' ), self::MAX_ROWS ) );
				}

				$rows[] = array(
					'line'  => $line,
					'cells' => $cells,
				);
			}
		} finally {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- memory stream.
		}

		if ( null === $header ) {
			return self::error( __( 'The file is empty.', 'product-qrcode-barcode-generator' ) );
		}

		if ( array() === $rows ) {
			return self::error( __( 'The file has a header row but no data rows.', 'product-qrcode-barcode-generator' ) );
		}

		return array(
			'name'      => '' === $name ? 'upload.csv' : $name,
			'sha256'    => $sha,
			'encoding'  => $encoding,
			'delimiter' => $delimiter,
			'header'    => $header,
			'rows'      => $rows,
		);
	}

	/**
	 * Undoes what a spreadsheet or our own export did to a cell: surrounding spaces
	 * (including no-break spaces), the apostrophe SalesExport::neutralise() adds before
	 * a cell SalesExport::formula_risk() flags, and Excel's ="…" text wrapper.
	 *
	 * @param string $cell Cell.
	 */
	public static function unwrap( string $cell ): string {
		$cell = trim( str_replace( array( "\u{00A0}", "\u{202F}" ), ' ', $cell ) );

		if ( 1 === preg_match( '/^="(.*)"$/sD', $cell, $m ) ) {
			$cell = str_replace( '""', '"', $m[1] );
		}

		if ( strlen( $cell ) > 1 && "'" === $cell[0] && SalesExport::formula_risk( substr( $cell, 1 ) ) ) {
			$cell = substr( $cell, 1 );
		}

		return trim( $cell );
	}

	/**
	 * Whether bytes are valid UTF-8.
	 *
	 * @param string $bytes Bytes.
	 */
	private static function is_utf8( string $bytes ): bool {
		return function_exists( 'mb_check_encoding' ) ? mb_check_encoding( $bytes, 'UTF-8' ) : 1 === preg_match( '//u', $bytes );
	}

	/**
	 * Error for a file over the size limit.
	 */
	private static function too_big(): WP_Error {
		/* translators: %s: size limit, e.g. "1 MB". */
		return self::error( sprintf( __( 'The file is larger than %s. Split it into smaller files.', 'product-qrcode-barcode-generator' ), size_format( self::MAX_BYTES ) ) );
	}

	/**
	 * An import error.
	 *
	 * @param string $message Message.
	 */
	private static function error( string $message ): WP_Error {
		return new WP_Error( 'pqbg_import_file', $message );
	}
}
