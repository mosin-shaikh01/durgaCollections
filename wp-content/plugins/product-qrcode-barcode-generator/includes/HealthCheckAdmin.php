<?php
/**
 * QR & Barcodes → Settings → Health check (Phase 11, decisions D1–D5):
 * admin.php?page=pqbg-settings&tab=health. The Settings page's own gate
 * (SettingsPage::load(), pqbg_manage_settings, 403 before any output) covers it.
 * GET only and read-only: it shows HealthCheck::run() and changes nothing.
 * Report only: no repair buttons (D4).
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * The Health check tab.
 */
final class HealthCheckAdmin {

	const TAB = 'health';

	/**
	 * Renders the tab's content (after the page heading and tabs).
	 */
	public static function render(): void {
		$start   = microtime( true );
		$results = HealthCheck::run();
		$ms      = ( microtime( true ) - $start ) * 1000;
		$n       = HealthCheck::problems( $results );

		echo '<p class="description">' . esc_html__( 'Read-only checks of the plugin\'s data. Nothing on this page changes anything.', 'product-qrcode-barcode-generator' ) . '</p>';

		if ( 0 === $n ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'No problems found.', 'product-qrcode-barcode-generator' ) . '</p></div>';
		} else {
			/* translators: %s: number of problems. */
			echo '<div class="notice notice-error inline"><p>' . esc_html( sprintf( _n( '%s problem found.', '%s problems found.', $n, 'product-qrcode-barcode-generator' ), number_format_i18n( $n ) ) ) . '</p></div>';
		}

		foreach ( $results as $id => $check ) {
			self::render_check( $id, $check );
		}

		/* translators: %s: milliseconds. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Checked in %s ms.', 'product-qrcode-barcode-generator' ), number_format_i18n( $ms ) ) ) . '</p>';
	}

	/**
	 * One check: heading, severity, explanation, count and rows.
	 *
	 * @param string               $id    Check ID.
	 * @param array<string, mixed> $check Result.
	 */
	private static function render_check( string $id, array $check ): void {
		$labels   = self::labels();
		$severity = array(
			HealthCheck::ERROR   => __( 'Error', 'product-qrcode-barcode-generator' ),
			HealthCheck::WARNING => __( 'Warning', 'product-qrcode-barcode-generator' ),
			HealthCheck::INFO    => __( 'Information', 'product-qrcode-barcode-generator' ),
		);
		$count    = (int) $check['count'];

		echo '<section class="pqbg-health pqbg-health--' . esc_attr( $check['severity'] ) . '" aria-labelledby="pqbg-health-' . esc_attr( $id ) . '">';
		echo '<h2 id="pqbg-health-' . esc_attr( $id ) . '">' . esc_html( $labels[ $id ][0] ) . '</h2>';
		echo '<p><strong>' . esc_html( $severity[ $check['severity'] ] ) . ':</strong> ';
		echo 0 === $count
			? esc_html__( 'none found.', 'product-qrcode-barcode-generator' )
			/* translators: %s: count. */
			: esc_html( sprintf( __( '%s found.', 'product-qrcode-barcode-generator' ), number_format_i18n( $count ) ) );
		echo '</p><p class="description">' . esc_html( $labels[ $id ][1] ) . '</p>';

		if ( array() !== $check['rows'] ) {
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Item or sale', 'product-qrcode-barcode-generator' ) . '</th><th scope="col">' . esc_html__( 'Details', 'product-qrcode-barcode-generator' ) . '</th></tr></thead><tbody>';

			foreach ( $check['rows'] as $row ) {
				echo '<tr><td>' . self::subject( $row ) . '</td><td>' . esc_html( self::details( $row ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- subject() escapes.
			}

			echo '</tbody></table>';

			if ( $count > count( $check['rows'] ) ) {
				/* translators: 1: rows shown, 2: total. */
				echo '<p class="description">' . esc_html( sprintf( __( 'Showing %1$s of %2$s.', 'product-qrcode-barcode-generator' ), number_format_i18n( count( $check['rows'] ) ), number_format_i18n( $count ) ) ) . '</p>';
			}
		}

		echo '</section>';
	}

	/**
	 * The linked sale or item (escaped HTML).
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private static function subject( array $row ): string {
		if ( isset( $row['sale'] ) ) {
			/* translators: %s: sale number. */
			return '<a href="' . esc_url( AdminUrl::sale( (int) $row['sale'] ) ) . '">' . esc_html( sprintf( __( 'Sale #%s', 'product-qrcode-barcode-generator' ), $row['sale'] ) ) . '</a>';
		}

		if ( isset( $row['item'] ) ) {
			$item = (int) $row['item'];
			$post = get_post( $item );
			/* translators: %s: item ID. */
			$label = sprintf( __( 'Item #%s', 'product-qrcode-barcode-generator' ), $item ) . ( $post ? ' ' . $post->post_title : '' );

			if ( $post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
				$edit = 'product_variation' === $post->post_type ? (int) $post->post_parent : $item;

				return '<a href="' . esc_url( AdminUrl::product_edit( $edit ) ) . '">' . esc_html( $label ) . '</a>';
			}

			return esc_html( $label );
		}

		return '—';
	}

	/**
	 * A row's details as plain text.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private static function details( array $row ): string {
		$reasons = array(
			'tables_missing' => __( 'A plugin table is missing. Deactivate and reactivate the plugin to recreate it.', 'product-qrcode-barcode-generator' ),
			'plain'          => __( 'Permalinks are set to "Plain".', 'product-qrcode-barcode-generator' ),
			'index_php'      => __( 'The permalink structure contains index.php.', 'product-qrcode-barcode-generator' ),
			'missing'        => __( 'The item no longer exists.', 'product-qrcode-barcode-generator' ),
			'not_product'    => __( 'Not a product or variation.', 'product-qrcode-barcode-generator' ),
			'type'           => __( 'The product is no longer a simple product (e.g. now variable, grouped or external).', 'product-qrcode-barcode-generator' ),
			'parent'         => __( 'The variation\'s parent is missing or no longer a variable product.', 'product-qrcode-barcode-generator' ),
			'auto_draft'     => __( 'The item is an auto-draft.', 'product-qrcode-barcode-generator' ),
			'invariant'      => __( 'The code row breaks the one-active-code rule.', 'product-qrcode-barcode-generator' ),
			'duplicate'      => __( 'More than one entry.', 'product-qrcode-barcode-generator' ),
			'not_number'     => __( 'The stored cost price is not a valid amount.', 'product-qrcode-barcode-generator' ),
		);
		$parts   = array();

		if ( isset( $row['reason'] ) && 'version' === $row['reason'] ) {
			/* translators: 1: stored version, 2: expected version. */
			$parts[] = sprintf( __( 'Stored schema version %1$d, expected %2$d.', 'product-qrcode-barcode-generator' ), $row['stored'], $row['code'] );
		} elseif ( isset( $row['reason'], $row['n'] ) && 'duplicate' === $row['reason'] ) {
			/* translators: %d: number of active codes. */
			$parts[] = sprintf( __( '%d active codes for one item.', 'product-qrcode-barcode-generator' ), $row['n'] );
		} elseif ( isset( $row['reason'], $reasons[ $row['reason'] ] ) ) {
			$parts[] = $reasons[ $row['reason'] ];
		}

		if ( isset( $row['code'] ) ) {
			/* translators: %s: product code. */
			$parts[] = sprintf( __( 'Code %s.', 'product-qrcode-barcode-generator' ), $row['code'] );
		}

		if ( isset( $row['stock'] ) ) {
			/* translators: %s: stock quantity. */
			$parts[] = sprintf( __( 'Stock %s.', 'product-qrcode-barcode-generator' ), wc_stock_amount( $row['stock'] ) );
		}

		if ( isset( $row['created'] ) ) {
			$ts = strtotime( $row['created'] . ' UTC' );
			/* translators: 1: date and time, 2: product name. */
			$parts[] = sprintf( __( '%1$s, %2$s.', 'product-qrcode-barcode-generator' ), false === $ts ? $row['created'] : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ), $row['name'] );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Heading and explanation per check.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function labels(): array {
		return array(
			'schema'           => array( __( 'Database tables', 'product-qrcode-barcode-generator' ), __( 'The plugin\'s tables exist and their version matches the plugin.', 'product-qrcode-barcode-generator' ) ),
			/* translators: %s: example scan page address. */
			'permalinks'       => array( __( 'Scan links (permalinks)', 'product-qrcode-barcode-generator' ), sprintf( __( 'Printed labels open addresses such as %s. They work only with a permalink structure other than "Plain" and without index.php; otherwise every label opens an error page. Choose another structure under Settings → Permalinks (the labels need no reprint).', 'product-qrcode-barcode-generator' ), ScanUrl::example() ) ),
			'negative_stock'   => array( __( 'Negative stock', 'product-qrcode-barcode-generator' ), __( 'Items with a product code (or the parent product that holds their stock) with stock below zero. Count the item and correct its stock on the product screen.', 'product-qrcode-barcode-generator' ) ),
			'stock_after_null' => array( __( 'Sales without a stock snapshot', 'product-qrcode-barcode-generator' ), __( 'Completed sales whose "stock after" was not recorded because the request stopped right after changing the stock. The stock and the sale agree; only the snapshot is missing. Nothing to repair.', 'product-qrcode-barcode-generator' ) ),
			'stale_pending'    => array( __( 'Interrupted sales', 'product-qrcode-barcode-generator' ), __( 'Sales still "in progress" after 15 minutes: the request stopped before changing the stock, so no stock changed. The next sale of the same item marks them failed automatically.', 'product-qrcode-barcode-generator' ) ),
			'code_items'       => array( __( 'Codes on missing or unsuitable items', 'product-qrcode-barcode-generator' ), __( 'Active product codes whose item was deleted or can no longer have a code. Check the product; printed labels with these codes will not sell.', 'product-qrcode-barcode-generator' ) ),
			'active_codes'     => array( __( 'One active code per item', 'product-qrcode-barcode-generator' ), __( 'Every item has at most one active code, and every code row is consistent. This should never find anything; if it does, keep a database backup and investigate before changing anything.', 'product-qrcode-barcode-generator' ) ),
			'cost_meta'        => array( __( 'Cost prices', 'product-qrcode-barcode-generator' ), __( 'Stored cost prices that are not valid amounts, stored twice, or stored on something that is not a product. Re-enter the cost on the product screen or with Import cost prices.', 'product-qrcode-barcode-generator' ) ),
			'code_items_trash' => array( __( 'Codes on trashed items', 'product-qrcode-barcode-generator' ), __( 'Codes stay active while an item is in the trash (so restoring it keeps its labels); they are retired when it is deleted permanently. For information.', 'product-qrcode-barcode-generator' ) ),
			'sales_no_item'    => array( __( 'Sales of deleted items', 'product-qrcode-barcode-generator' ), __( 'Sales keep the product name, SKU and prices from the moment of sale, so deleting a product later is allowed; reports show these as deleted. For information.', 'product-qrcode-barcode-generator' ) ),
		);
	}
}
