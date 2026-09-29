<?php
/**
 * Receipts for in-store sales (Phase 16): who may see one, what it says, and the
 * plain text shared on WhatsApp.
 *
 * A receipt is a page, /scan/receipt/{sale id}/ (ScanUrl::receipt_url(), served by
 * ScanRoute), never a stored document. It is called "Receipt", never "Tax invoice":
 * it is not a GST tax invoice (no consecutive serial per financial year, HSN codes or
 * tax breakdown).
 *
 * Which sales: completed and voided ones (undone sales included, marked VOID).
 * Pending and failed sales have no receipt. Who: Permissions::can_view_sale(), i.e.
 * a seller their own sales, shop managers and administrators every sale.
 *
 * Content comes from the sale row's snapshots (name, attributes, prices, currency,
 * payment method, seller name) and the receipt settings, never from live product
 * data. The receipt never reads the cost: data() drops unit_cost before anything
 * else, and no stock, profit, SKU, code, user ID or void reason is shown.
 *
 * Number: the sale ID ("Sale #123" in the In-store sales history). Seller: the first
 * word of the seller_name snapshot, unless the setting turns it off.
 *
 * Share on WhatsApp: https://wa.me/?text=… with no phone number (WhatsApp asks which
 * chat), so nothing about the customer reaches the plugin; the text never contains a
 * link to the receipt. Not offered for a voided sale.
 *
 * Settings keys (Plugin::default_settings()): receipt_shop_name, receipt_address,
 * receipt_phone, receipt_gstin, receipt_footer, receipt_show_seller, receipt_paper.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * Receipt access, content and share text.
 */
final class Receipt {

	/** Paper layout keys (labels in papers()). */
	const PAPERS = array( 'a4', '80', '58' );

	/** Layout used until an administrator chooses another. */
	const DEFAULT_PAPER = 'a4';

	/** Longest WhatsApp text, in characters. */
	const TEXT_MAX = 1500;

	/**
	 * Paper labels in display order.
	 *
	 * @return array<string, string>
	 */
	public static function papers(): array {
		return array(
			'a4' => __( 'A4', 'product-qrcode-barcode-generator' ),
			'80' => __( '80 mm', 'product-qrcode-barcode-generator' ),
			'58' => __( '58 mm', 'product-qrcode-barcode-generator' ),
		);
	}

	/**
	 * The default paper from Settings (a bad stored value gives A4).
	 */
	public static function default_paper(): string {
		$stored = Settings::get()['receipt_paper'];

		return is_string( $stored ) && in_array( $stored, self::PAPERS, true ) ? $stored : self::DEFAULT_PAPER;
	}

	/**
	 * Whether a sale has a receipt: completed or voided.
	 *
	 * @param array<string, mixed> $sale Sale row.
	 */
	public static function has_receipt( array $sale ): bool {
		return in_array( (string) ( $sale['status'] ?? '' ), array( SaleRepository::STATUS_COMPLETED, SaleRepository::STATUS_VOIDED ), true );
	}

	/**
	 * Whether the current user may see this sale's receipt. A missing sale, a sale
	 * without a receipt and another seller's sale all give false, so the caller can
	 * answer them identically.
	 *
	 * @param array<string, mixed>|null $sale Sale row, or null.
	 */
	public static function can_see( ?array $sale ): bool {
		return null !== $sale && self::has_receipt( $sale ) && Permissions::can_view_sale( (int) $sale['seller_id'] );
	}

	/**
	 * The shop name for receipts: the setting, or the site title when empty.
	 */
	public static function shop_name(): string {
		$stored = Settings::get()['receipt_shop_name'];

		return is_string( $stored ) && '' !== trim( $stored ) ? trim( $stored ) : html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * The seller's first name for a receipt, or '' when turned off or unknown.
	 *
	 * @param array<string, mixed> $sale Sale row.
	 */
	public static function seller_first_name( array $sale ): string {
		if ( true !== Settings::get()['receipt_show_seller'] ) {
			return '';
		}

		$name = trim( (string) ( $sale['seller_name'] ?? '' ) );

		$words = '' === $name ? false : preg_split( '/\s+/u', $name );

		return is_array( $words ) ? (string) $words[0] : '';
	}

	/**
	 * The receipt's content as plain text values (the template and text() escape or encode them).
	 *
	 * @param array<string, mixed> $sale Sale row with a receipt.
	 * @return array<string, mixed>
	 */
	public static function data( array $sale ): array {
		unset( $sale['unit_cost'] ); // Never on a receipt.

		$settings = Settings::get();
		$currency = (string) $sale['currency'];
		$void     = SaleRepository::STATUS_VOIDED === $sale['status'];
		$method   = isset( $sale['payment_method'] ) ? (string) $sale['payment_method'] : '';
		$lines    = array(
			array(
				'name'       => (string) $sale['product_name'],
				'attributes' => SalePresenter::attributes( $sale ),
				'quantity'   => number_format_i18n( (int) $sale['quantity'] ),
				'unit'       => SalePresenter::money( (string) $sale['unit_price'], $currency ),
				'total'      => SalePresenter::money( (string) $sale['line_total'], $currency ),
			),
		);

		$void_note = '';

		if ( $void ) {
			$when      = SalePresenter::datetime( isset( $sale['voided_at_gmt'] ) ? (string) $sale['voided_at_gmt'] : null );
			$void_note = SaleService::VOID_REASON_UNDO === ( $sale['void_reason'] ?? '' )
				/* translators: %s: date and time. */
				? sprintf( __( 'This sale was undone on %s.', 'product-qrcode-barcode-generator' ), $when )
				/* translators: %s: date and time. */
				: sprintf( __( 'This sale was voided on %s.', 'product-qrcode-barcode-generator' ), $when );
		}

		return array(
			'id'        => (int) $sale['id'],
			'number'    => (string) (int) $sale['id'],
			'shop'      => self::shop_name(),
			'address'   => self::lines_of( $settings['receipt_address'] ),
			'phone'     => is_string( $settings['receipt_phone'] ) ? $settings['receipt_phone'] : '',
			'gstin'     => is_string( $settings['receipt_gstin'] ) ? $settings['receipt_gstin'] : '',
			'footer'    => self::lines_of( $settings['receipt_footer'] ),
			'date'      => SalePresenter::datetime( (string) $sale['created_at_gmt'] ),
			'lines'     => $lines,
			'total'     => SalePresenter::money( (string) $sale['line_total'], $currency ),
			'payment'   => PaymentMethods::label( '' === $method ? null : $method ),
			'reference' => PaymentMethods::UPI === $method ? UpiPayment::reference( (string) $sale['request_id'] ) : '',
			'seller'    => self::seller_first_name( $sale ),
			'void'      => $void,
			'void_note' => $void_note,
		);
	}

	/**
	 * The receipt as plain text for WhatsApp (no link to the receipt), at most TEXT_MAX characters.
	 *
	 * @param array<string, mixed> $data From data().
	 */
	public static function text( array $data ): string {
		$out = array( '*' . $data['shop'] . '*' );

		foreach ( $data['address'] as $line ) {
			$out[] = $line;
		}

		if ( '' !== $data['phone'] ) {
			/* translators: %s: phone number. */
			$out[] = sprintf( __( 'Phone: %s', 'product-qrcode-barcode-generator' ), $data['phone'] );
		}

		if ( '' !== $data['gstin'] ) {
			/* translators: %s: GSTIN. */
			$out[] = sprintf( __( 'GSTIN: %s', 'product-qrcode-barcode-generator' ), $data['gstin'] );
		}

		$out[] = '';

		if ( $data['void'] ) {
			$out[] = '*' . __( 'VOID', 'product-qrcode-barcode-generator' ) . '*';
		}

		/* translators: %s: receipt number. */
		$out[] = sprintf( __( 'Receipt no. %s', 'product-qrcode-barcode-generator' ), $data['number'] );
		$out[] = $data['date'];
		$out[] = '';

		foreach ( $data['lines'] as $line ) {
			$out[] = '' === $line['attributes'] ? $line['name'] : $line['name'] . ' – ' . $line['attributes'];
			/* translators: 1: quantity, 2: unit price, 3: total. */
			$out[] = sprintf( __( '%1$s × %2$s = %3$s', 'product-qrcode-barcode-generator' ), $line['quantity'], $line['unit'], $line['total'] );
		}

		$out[] = '';
		/* translators: %s: total amount. */
		$out[] = '*' . sprintf( __( 'Total: %s', 'product-qrcode-barcode-generator' ), $data['total'] ) . '*';
		/* translators: %s: payment method. */
		$out[] = sprintf( __( 'Paid by: %s', 'product-qrcode-barcode-generator' ), $data['payment'] );

		if ( '' !== $data['reference'] ) {
			/* translators: %s: UPI reference. */
			$out[] = sprintf( __( 'UPI ref. %s', 'product-qrcode-barcode-generator' ), $data['reference'] );
		}

		if ( '' !== $data['seller'] ) {
			/* translators: %s: seller's first name. */
			$out[] = sprintf( __( 'Served by: %s', 'product-qrcode-barcode-generator' ), $data['seller'] );
		}

		if ( array() !== $data['footer'] ) {
			$out[] = '';

			foreach ( $data['footer'] as $line ) {
				$out[] = $line;
			}
		}

		return mb_substr( implode( "\n", $out ), 0, self::TEXT_MAX );
	}

	/**
	 * The wa.me link that opens WhatsApp with the text and lets the seller choose the chat.
	 *
	 * @param string $text Text from text().
	 */
	public static function whatsapp_url( string $text ): string {
		return 'https://wa.me/?text=' . rawurlencode( $text );
	}

	/**
	 * A stored multi-line setting as a list of non-empty lines.
	 *
	 * @param mixed $value Stored value.
	 * @return string[]
	 */
	private static function lines_of( $value ): array {
		if ( ! is_string( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( "\n", str_replace( "\r", '', $value ) ) ), static fn( $line ) => '' !== $line ) );
	}
}
