<?php
/**
 * A print job: the selected products, the items (simple products and
 * variations) that get labels, the items that are skipped and why, the number
 * of copies, the job limit, and the print options with their validation.
 *
 * Printing never generates codes: an item without an ACTIVE code is listed as
 * "skipped – no code yet". Only rows from CodeRepository::find_active_for_products()
 * are printed, so a retired code is never printed.
 *
 * Read-only apart from the user's remembered options (save_prefs(), called by
 * the POST handler only).
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

use WC_Product;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Print job resolution and options.
 */
final class PrintJob {

	/**
	 * Most labels in one job. Cold rendering takes about 50 ms per QR code, so 300 new
	 * codes take about 15 s; the page is about 1.5 MB, which print preview handles.
	 */
	const MAX_LABELS = 300;

	/** Most selected products (or variations) in one job; keeps the URL well under server limits. */
	const MAX_ITEMS = 300;

	const MAX_COPIES = 100;

	/** User meta holding the user's last-used options. */
	const PREFS_META = 'pqbg_print_prefs';

	/**
	 * Parses a selection: "12,34,56" or a list of IDs. Duplicates are removed, the order kept.
	 *
	 * @param mixed $raw Raw selection.
	 * @return int[]|WP_Error
	 */
	public static function parse_ids( $raw ) {
		$parts = is_array( $raw ) ? $raw : ( is_string( $raw ) ? explode( ',', $raw ) : array() );
		$ids   = array();

		foreach ( $parts as $part ) {
			if ( ! is_scalar( $part ) || ! preg_match( '/^[1-9][0-9]{0,18}$/D', trim( (string) $part ) ) ) {
				return new WP_Error( 'pqbg_print_bad_items', __( 'The product selection is not valid. Go back to the products and try again.', 'product-qrcode-barcode-generator' ) );
			}

			$ids[ (int) $part ] = (int) $part;
		}

		if ( array() === $ids ) {
			return new WP_Error( 'pqbg_print_bad_items', __( 'No products were selected.', 'product-qrcode-barcode-generator' ) );
		}

		if ( count( $ids ) > self::MAX_ITEMS ) {
			/* translators: 1: selected count, 2: maximum. */
			return new WP_Error( 'pqbg_print_too_many_items', sprintf( __( '%1$d products are selected; the maximum is %2$d. Select fewer products.', 'product-qrcode-barcode-generator' ), count( $ids ), self::MAX_ITEMS ) );
		}

		return array_values( $ids );
	}

	/**
	 * Nonce action bound to a selection (order and duplicates do not matter).
	 *
	 * @param string $verb "print_view" (setup and print pages) or "print_prepare" (the POST).
	 * @param int[]  $ids  Selection.
	 */
	public static function nonce_action( string $verb, array $ids ): string {
		$ids = array_unique( array_map( 'intval', $ids ) );
		sort( $ids );

		return Permissions::nonce_action( $verb . '_' . md5( implode( ',', $ids ) ) );
	}

	/**
	 * Default options (A4 3 × 7, one copy, name, attributes, SKU and price).
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'layout'      => PrintLayout::DEFAULT_PRESET,
			'custom'      => array(
				'type'    => 'sheet',
				'dpi'     => '203',
				'page_w'  => '210',
				'page_h'  => '297',
				'label_w' => '63.5',
				'label_h' => '38.1',
				'cols'    => '3',
				'rows'    => '7',
				'left'    => '7.25',
				'top'     => '15.15',
				'gap_x'   => '2.5',
				'gap_y'   => '0',
			),
			'start'       => '1',
			'copies_mode' => 'fixed',
			'copies'      => '1',
			'fields'      => array( 'name', 'attributes', 'sku', 'price' ),
			'dx'          => '0',
			'dy'          => '0',
		);
	}

	/**
	 * Validates print options strictly. The result carries the layout as 'spec'.
	 *
	 * @param mixed $input Raw options (the "opt" request array).
	 * @return array<string, mixed>|WP_Error pqbg_print_invalid_option or pqbg_invalid_layout, with data['field'].
	 */
	public static function options( $input ) {
		$in       = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$str      = static fn( string $key ) => isset( $in[ $key ] ) && is_string( $in[ $key ] ) ? trim( $in[ $key ] ) : null;

		$layout = $str( 'layout' ) ?? $defaults['layout'];

		if ( 'custom' === $layout ) {
			$custom = isset( $in['custom'] ) && is_array( $in['custom'] ) ? $in['custom'] : array();
			$spec   = PrintLayout::custom( $custom );

			if ( is_wp_error( $spec ) ) {
				return $spec;
			}
		} elseif ( isset( PrintLayout::presets()[ $layout ] ) ) {
			$spec   = PrintLayout::presets()[ $layout ];
			$custom = null;
		} else {
			return self::invalid( 'layout', __( 'Choose a label layout.', 'product-qrcode-barcode-generator' ) );
		}

		$per   = PrintLayout::per_sheet( $spec );
		$start = $str( 'start' ) ?? '1';

		if ( 'sheet' !== $spec['type'] ) {
			$start = '1';
		} elseif ( ! preg_match( '/^[0-9]{1,3}$/D', $start ) || (int) $start < 1 || (int) $start > $per ) {
			/* translators: %d: labels per sheet. */
			return self::invalid( 'start', sprintf( __( 'The start position must be a whole number from 1 to %d.', 'product-qrcode-barcode-generator' ), $per ) );
		}

		$mode = $str( 'copies_mode' ) ?? 'fixed';

		if ( ! in_array( $mode, array( 'fixed', 'stock' ), true ) ) {
			return self::invalid( 'copies_mode', __( 'Choose how many copies to print.', 'product-qrcode-barcode-generator' ) );
		}

		$copies = $str( 'copies' ) ?? '1';

		if ( 'fixed' === $mode && ( ! preg_match( '/^[0-9]{1,3}$/D', $copies ) || (int) $copies < 1 || (int) $copies > self::MAX_COPIES ) ) {
			/* translators: %d: maximum copies. */
			return self::invalid( 'copies', sprintf( __( 'Copies per item must be a whole number from 1 to %d.', 'product-qrcode-barcode-generator' ), self::MAX_COPIES ) );
		}

		$fields = isset( $in['fields'] ) ? $in['fields'] : array();

		if ( ! is_array( $fields ) || array() !== array_diff( array_map( static fn( $f ) => is_string( $f ) ? $f : '', $fields ), PrintLayout::OPTIONAL_FIELDS ) ) {
			return self::invalid( 'fields', __( 'Unknown label field.', 'product-qrcode-barcode-generator' ) );
		}

		$offsets = array();

		foreach ( array( 'dx', 'dy' ) as $key ) {
			$value = $str( $key ) ?? '0';

			if ( '' === $value ) {
				$value = '0';
			}

			if ( ! preg_match( '/^-?[0-9](\.[0-9]{1,2})?$/D', $value ) || abs( (float) $value ) > PrintLayout::MAX_OFFSET_MM ) {
				/* translators: %s: maximum offset in mm. */
				return self::invalid( $key, sprintf( __( 'The printer offset must be between -%1$s and %1$s mm, with at most 2 decimals.', 'product-qrcode-barcode-generator' ), PrintLayout::mm( PrintLayout::MAX_OFFSET_MM ) ) );
			}

			$offsets[ $key ] = 'sheet' === $spec['type'] ? (float) $value : 0.0;
		}

		return array(
			'layout'      => $layout,
			'custom'      => $custom,
			'spec'        => $spec,
			'start'       => (int) $start,
			'copies_mode' => $mode,
			'copies'      => 'fixed' === $mode ? (int) $copies : 1,
			'fields'      => array_values( array_intersect( PrintLayout::OPTIONAL_FIELDS, $fields ) ),
			'dx'          => $offsets['dx'],
			'dy'          => $offsets['dy'],
		);
	}

	/**
	 * Validated options as request arguments ("opt[...]"), for the print page URL.
	 *
	 * @param array<string, mixed> $options From options().
	 * @return array<string, mixed>
	 */
	public static function query_args( array $options ): array {
		$opt = array(
			'layout'      => $options['layout'],
			'start'       => (string) $options['start'],
			'copies_mode' => $options['copies_mode'],
			'copies'      => (string) $options['copies'],
			'fields'      => $options['fields'],
			'dx'          => PrintLayout::mm( $options['dx'] ),
			'dy'          => PrintLayout::mm( $options['dy'] ),
		);

		if ( 'custom' === $options['layout'] ) {
			$opt['custom'] = array_map( 'strval', array_intersect_key( $options['custom'], array_flip( array_merge( array( 'type', 'dpi' ), array_keys( PrintLayout::custom_fields() ) ) ) ) );
		}

		return array( 'opt' => $opt );
	}

	/**
	 * The user's last-used options, merged over the defaults (raw strings, validated on use).
	 *
	 * @param int $user_id User ID.
	 * @return array<string, mixed>
	 */
	public static function prefs( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::PREFS_META, true );

		return self::sanitize_prefs( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Remembers a user's options. Called only from the POST handler.
	 *
	 * @param int   $user_id User ID.
	 * @param mixed $input   Submitted options.
	 */
	public static function save_prefs( int $user_id, $input ): void {
		update_user_meta( $user_id, self::PREFS_META, self::sanitize_prefs( is_array( $input ) ? $input : array() ) );
	}

	/**
	 * Keeps only the known option keys as short plain strings, so invalid input can be shown again in the form.
	 *
	 * @param array<string, mixed> $input Raw options.
	 * @return array<string, mixed>
	 */
	public static function sanitize_prefs( array $input ): array {
		$prefs = self::defaults();
		$clean = static fn( $v ): ?string => is_scalar( $v ) ? substr( (string) preg_replace( '/[^a-z0-9.\-]/', '', strtolower( (string) $v ) ), 0, 12 ) : null;

		foreach ( array( 'layout', 'start', 'copies_mode', 'copies', 'dx', 'dy' ) as $key ) {
			if ( array_key_exists( $key, $input ) && null !== $clean( $input[ $key ] ) ) {
				$prefs[ $key ] = $clean( $input[ $key ] );
			}
		}

		if ( isset( $input['custom'] ) && is_array( $input['custom'] ) ) {
			foreach ( array_keys( $prefs['custom'] ) as $key ) {
				if ( array_key_exists( $key, $input['custom'] ) && null !== $clean( $input['custom'][ $key ] ) ) {
					$prefs['custom'][ $key ] = $clean( $input['custom'][ $key ] );
				}
			}
		}

		if ( array_key_exists( 'fields', $input ) || array_key_exists( 'layout', $input ) ) {
			$fields          = isset( $input['fields'] ) && is_array( $input['fields'] ) ? $input['fields'] : array();
			$prefs['fields'] = array_values( array_intersect( PrintLayout::OPTIONAL_FIELDS, array_filter( $fields, 'is_string' ) ) );
		}

		return $prefs;
	}

	/**
	 * Resolves a selection into printable items (with their ACTIVE codes) and skipped items.
	 *
	 * A simple product is one item; a variable product expands to its variations
	 * (published and private, in menu order); a variation ID is one item.
	 *
	 * @param int[] $ids Selection.
	 * @return array{items: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>}
	 */
	public static function resolve( array $ids ): array {
		$items   = array();
		$skipped = array();

		// Posts and their meta for the whole selection in two queries, instead of several per product.
		_prime_post_caches( $ids, false, true );

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( ! $post || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) || 'auto-draft' === $post->post_status ) {
				$skipped[] = self::skip( $id, 'not_found', null );
				continue;
			}

			$product = wc_get_product( $id );

			if ( ! $product instanceof WC_Product ) {
				$skipped[] = self::skip( $id, 'not_found', null );
				continue;
			}

			$parent = $product->is_type( 'variation' ) ? get_post( $product->get_parent_id() ) : null;

			if ( 'trash' === $post->post_status || ( $parent && 'trash' === $parent->post_status ) ) {
				$skipped[] = self::skip( $id, 'trash', $product );
				continue;
			}

			if ( $product->is_type( 'simple' ) || $product->is_type( 'variation' ) ) {
				$candidates = array( $product );
			} elseif ( $product->is_type( 'variable' ) ) {
				$candidates = self::variations( $id );

				if ( array() === $candidates ) {
					$skipped[] = self::skip( $id, 'no_variations', $product );
					continue;
				}
			} else {
				$skipped[] = self::skip( $id, 'type', $product );
				continue;
			}

			foreach ( $candidates as $candidate ) {
				if ( ! isset( $items[ $candidate->get_id() ] ) ) {
					$items[ $candidate->get_id() ] = $candidate;
				}
			}
		}

		$codes  = CodeRepository::find_active_for_products( array_keys( $items ) );
		$result = array();

		// Variation parents (names, statuses) in one go as well.
		_prime_post_caches( array_values( array_unique( array_filter( array_map( static fn( $p ) => $p->get_parent_id(), $items ) ) ) ), false, true );

		foreach ( $items as $item_id => $product ) {
			$row = $codes[ $item_id ] ?? null;

			if ( null === $row || ! CodeGenerator::is_valid_format( (string) $row['code'] ) || CodeRepository::STATUS_ACTIVE !== $row['status'] ) {
				$skipped[] = self::skip( $item_id, 'no_code', $product );
				continue;
			}

			$result[] = self::item( $product, (string) $row['code'] );
		}

		return array(
			'items'   => $result,
			'skipped' => $skipped,
		);
	}

	/**
	 * Expands items into labels by the copies option, and applies the job limit.
	 *
	 * "One label per unit in stock" needs the item's own tracked stock. Stock shared
	 * with the parent product, or not tracked, gives one label with a note; stock at
	 * or below zero skips the item.
	 *
	 * @param array<int, array<string, mixed>> $items   From resolve().
	 * @param array<string, mixed>             $options From options().
	 * @return array{labels: int[], notes: array<int, string>, skipped: array<int, array<string, mixed>>, total: int}|WP_Error
	 *         labels holds indexes into $items, one per label.
	 */
	public static function labels( array $items, array $options ) {
		$counts  = array();
		$notes   = array();
		$skipped = array();

		foreach ( $items as $i => $item ) {
			if ( 'fixed' === $options['copies_mode'] ) {
				$counts[ $i ] = (int) $options['copies'];
				continue;
			}

			$manage = $item['manage_stock'];

			if ( true === $manage ) {
				$stock = (int) $item['stock'];

				if ( $stock <= 0 ) {
					$skipped[] = array_merge( self::skip( $item['id'], 'out_of_stock', null ), array( 'name' => $item['title'], 'edit_url' => $item['edit_url'] ) );
					continue;
				}

				$counts[ $i ] = $stock;
			} else {
				$counts[ $i ]          = 1;
				$notes[ $item['id'] ] = 'parent' === $manage
					? __( 'Stock is shared with the other variations: 1 label.', 'product-qrcode-barcode-generator' )
					: __( 'Stock is not tracked: 1 label.', 'product-qrcode-barcode-generator' );
			}
		}

		$total = array_sum( $counts );

		if ( $total > self::MAX_LABELS ) {
			return new WP_Error(
				'pqbg_print_too_many_labels',
				/* translators: 1: labels in the job, 2: maximum. */
				sprintf( __( 'This job has %1$d labels; the maximum is %2$d. Select fewer products or print fewer copies.', 'product-qrcode-barcode-generator' ), $total, self::MAX_LABELS ),
				array( 'total' => $total )
			);
		}

		if ( 0 === $total ) {
			return new WP_Error( 'pqbg_print_nothing', __( 'Nothing to print: none of the selected items has an active product code (or stock, when printing one label per unit).', 'product-qrcode-barcode-generator' ) );
		}

		$labels = array();

		foreach ( $counts as $i => $n ) {
			$labels = array_merge( $labels, array_fill( 0, $n, $i ) );
		}

		return array(
			'labels'  => $labels,
			'notes'   => $notes,
			'skipped' => $skipped,
			'total'   => $total,
		);
	}

	/**
	 * Explanation of a skip reason.
	 *
	 * @param string $reason Reason key.
	 */
	public static function reason_text( string $reason ): string {
		$texts = array(
			'no_code'       => __( 'Skipped – no code yet', 'product-qrcode-barcode-generator' ),
			'trash'         => __( 'Skipped – in the trash', 'product-qrcode-barcode-generator' ),
			'not_found'     => __( 'Skipped – not found', 'product-qrcode-barcode-generator' ),
			'type'          => __( 'Skipped – this product type has no code (grouped or external product)', 'product-qrcode-barcode-generator' ),
			'no_variations' => __( 'Skipped – this variable product has no variations', 'product-qrcode-barcode-generator' ),
			'out_of_stock'  => __( 'Skipped – no stock (one label per unit in stock)', 'product-qrcode-barcode-generator' ),
		);

		return $texts[ $reason ] ?? __( 'Skipped', 'product-qrcode-barcode-generator' );
	}

	/**
	 * Label data of an item: plain text only (escaped when printed).
	 *
	 * @param WC_Product $product Simple product or variation.
	 * @param string     $code    Its active code.
	 * @return array<string, mixed>
	 */
	private static function item( WC_Product $product, string $code ): array {
		$is_variation = $product->is_type( 'variation' );
		$parent_id    = $is_variation ? $product->get_parent_id() : 0;
		$name         = $is_variation ? get_post_field( 'post_title', $parent_id, 'raw' ) : $product->get_name( 'edit' );
		$attributes   = $is_variation ? self::plain( wc_get_formatted_variation( $product, true, true, false ) ) : '';
		$price        = (string) $product->get_price();
		$status       = get_post_status( $product->get_id() );

		if ( $is_variation && 'publish' === $status ) {
			$status = get_post_status( $parent_id );
		}

		return array(
			'id'           => $product->get_id(),
			'parent_id'    => $parent_id,
			'code'         => $code,
			'name'         => self::text( (string) $name ),
			'attributes'   => $attributes,
			'title'        => self::text( (string) $name ) . ( '' !== $attributes ? ' — ' . $attributes : '' ),
			'sku'          => self::text( (string) $product->get_sku( 'edit' ) ),
			'price'        => '' !== $price && (float) $price > 0 ? self::plain( wc_price( $price ) ) : '',
			'status'       => (string) $status,
			'manage_stock' => $product->get_manage_stock(),
			'stock'        => $product->get_stock_quantity( 'edit' ),
			'edit_url'     => (string) get_edit_post_link( $is_variation ? $parent_id : $product->get_id(), 'raw' ),
		);
	}

	/**
	 * A skipped entry.
	 *
	 * @param int             $id      Selected or expanded ID.
	 * @param string          $reason  Reason key.
	 * @param WC_Product|null $product Product, when it exists.
	 * @return array<string, mixed>
	 */
	private static function skip( int $id, string $reason, ?WC_Product $product ): array {
		$name = '';
		$edit = '';

		if ( $product instanceof WC_Product ) {
			$is_variation = $product->is_type( 'variation' );
			$name         = self::text( $is_variation ? (string) get_post_field( 'post_title', $product->get_parent_id(), 'raw' ) : $product->get_name( 'edit' ) );
			$attributes   = $is_variation ? self::plain( wc_get_formatted_variation( $product, true, true, false ) ) : '';
			$name        .= '' !== $attributes ? ' — ' . $attributes : '';
			$edit         = (string) get_edit_post_link( $is_variation ? $product->get_parent_id() : $id, 'raw' );
		}

		return array(
			'id'       => $id,
			'reason'   => $reason,
			'name'     => $name,
			'edit_url' => $edit,
		);
	}

	/**
	 * A variable product's variations (published and private), in menu order.
	 *
	 * @param int $parent_id Variable product ID.
	 * @return WC_Product[]
	 */
	private static function variations( int $parent_id ): array {
		$posts = get_posts(
			array(
				'post_parent'      => $parent_id,
				'post_type'        => 'product_variation',
				'post_status'      => array( 'publish', 'private' ),
				'numberposts'      => -1,
				'orderby'          => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'suppress_filters' => true,
			)
		);

		$result = array();

		foreach ( $posts as $post ) {
			$object = wc_get_product( $post );

			if ( $object instanceof WC_Product && $object->is_type( 'variation' ) ) {
				$result[] = $object;
			}
		}

		return $result;
	}

	/**
	 * Stored plain text (product names, SKUs) for a label: entities decoded, whitespace
	 * collapsed, and anything that looks like markup kept literally (escaped when printed).
	 *
	 * @param string $text Stored text.
	 */
	private static function text( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * WooCommerce HTML output (a formatted price or variation) to plain text (escaped again when printed).
	 *
	 * @param string $html Markup.
	 */
	private static function plain( string $html ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * An option validation error.
	 *
	 * @param string $field   Field.
	 * @param string $message Message.
	 */
	private static function invalid( string $field, string $message ): WP_Error {
		return new WP_Error( 'pqbg_print_invalid_option', $message, array( 'field' => $field ) );
	}
}
