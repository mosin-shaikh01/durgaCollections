<?php
/**
 * The in-store sales history table (WooCommerce → In-store sales, Phase 9A).
 *
 * Rows come from SalesQuery with the filters in the URL. Sorting and pagination
 * links are built by WP_List_Table from the current URL, so every filter stays
 * in the URL (shareable, bookmarkable). The unit cost and profit columns exist
 * only for users with pqbg_view_costs. Every cell is escaped here.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table', false ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Sales history list table.
 */
final class SalesListTable extends \WP_List_Table {

	const PER_PAGE = 50;

	/** @var array<string, mixed> Filters from SalesQuery::filters(). */
	private array $filters;

	/** Whether cost columns are shown. */
	private bool $costs;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param bool                 $costs   Show cost and profit.
	 */
	public function __construct( array $filters, bool $costs ) {
		$this->filters = $filters;
		$this->costs   = $costs;

		parent::__construct(
			array(
				'singular' => 'pqbg-sale',
				'plural'   => 'pqbg-sales',
				'ajax'     => false,
				'screen'   => get_current_screen(),
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		$columns = array(
			'date'    => __( 'Date', 'product-qrcode-barcode-generator' ),
			'id'      => __( 'Sale #', 'product-qrcode-barcode-generator' ),
			'product' => __( 'Product', 'product-qrcode-barcode-generator' ),
			'sku'     => __( 'SKU', 'product-qrcode-barcode-generator' ),
			'qty'     => __( 'Qty', 'product-qrcode-barcode-generator' ),
			'unit'    => __( 'Unit price', 'product-qrcode-barcode-generator' ),
			'total'   => __( 'Total', 'product-qrcode-barcode-generator' ),
			'method'  => __( 'Paid by', 'product-qrcode-barcode-generator' ),
			'seller'  => __( 'Seller', 'product-qrcode-barcode-generator' ),
			'status'  => __( 'Status', 'product-qrcode-barcode-generator' ),
		);

		if ( $this->costs ) {
			$columns['cost']   = __( 'Unit cost', 'product-qrcode-barcode-generator' );
			$columns['profit'] = __( 'Profit', 'product-qrcode-barcode-generator' );
		}

		return $columns;
	}

	/**
	 * Sortable columns (keys of SalesQuery::SORTS).
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns() {
		return array(
			'date'    => array( 'date', true ),
			'id'      => array( 'id', true ),
			'product' => array( 'product', false ),
			'qty'     => array( 'qty', true ),
			'total'   => array( 'total', true ),
		);
	}

	/**
	 * Loads one page of rows.
	 */
	public function prepare_items() {
		$total = SalesQuery::count( $this->filters );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page  = min( (int) $this->filters['paged'], $pages );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'date' );
		$this->items           = SalesQuery::rows( $this->filters, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => $pages,
			)
		);
	}

	/**
	 * Message when nothing matches.
	 */
	public function no_items() {
		esc_html_e( 'No in-store sales match these filters.', 'product-qrcode-barcode-generator' );
	}

	/**
	 * No bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions() {
		return array();
	}

	/**
	 * A cell.
	 *
	 * @param array<string, mixed> $item        Row.
	 * @param string               $column_name Column.
	 * @return string Escaped HTML.
	 */
	protected function column_default( $item, $column_name ) {
		$currency = (string) $item['currency'];

		switch ( $column_name ) {
			case 'date':
				return '<a href="' . esc_url( SalesAdmin::detail_url( (int) $item['id'] ) ) . '">' . esc_html( SalePresenter::datetime( $item['created_at_gmt'] ) ) . '</a>';
			case 'id':
				return '<a href="' . esc_url( SalesAdmin::detail_url( (int) $item['id'] ) ) . '">' . esc_html( (string) $item['id'] ) . '</a>';
			case 'product':
				return esc_html( SalePresenter::item( $item ) );
			case 'sku':
				return esc_html( (string) $item['sku'] );
			case 'qty':
				return esc_html( number_format_i18n( (int) $item['quantity'] ) );
			case 'unit':
				return esc_html( SalePresenter::money( $item['unit_price'], $currency ) );
			case 'total':
				return esc_html( SalePresenter::money( $item['line_total'], $currency ) );
			case 'method':
				return esc_html( PaymentMethods::label( $item['payment_method'] ) );
			case 'seller':
				return esc_html( SalePresenter::seller( $item ) );
			case 'status':
				return '<span class="pqbg-status pqbg-status--' . esc_attr( (string) $item['status'] ) . '">' . esc_html( SalePresenter::status( (string) $item['status'] ) ) . '</span>';
			case 'cost':
				return null === $item['unit_cost'] ? esc_html__( 'unknown', 'product-qrcode-barcode-generator' ) : esc_html( SalePresenter::money( $item['unit_cost'], $currency ) );
			case 'profit':
				if ( SaleRepository::STATUS_COMPLETED !== $item['status'] ) {
					return '—';
				}

				$profit = SalePresenter::profit( $item );

				return null === $profit ? esc_html__( 'unknown', 'product-qrcode-barcode-generator' ) : esc_html( SalePresenter::money( $profit, $currency ) );
		}

		return '';
	}
}
