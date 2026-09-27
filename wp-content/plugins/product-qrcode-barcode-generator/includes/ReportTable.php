<?php
/**
 * A sortable, paged table of report rows (Phase 9B), on WP_List_Table so it looks
 * and behaves like the rest of wp-admin. Rows are already aggregated (a few
 * thousand at most), so sorting and paging happen in PHP on the raw values; the
 * cells arrive escaped from ReportsAdmin. orderby/order/paged are read from the URL
 * and checked against the column list, so every view stays a plain, shareable GET.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table', false ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Report table.
 */
final class ReportTable extends \WP_List_Table {

	/** @var array<string, array{0: string, 1: bool}> Column key => [label, sortable]. */
	private array $spec;

	/** @var array<int, array{cells: array<string, string>, sort: array<string, mixed>}> */
	private array $rows;

	private string $default_sort;

	private string $default_order;

	private int $per_page;

	private string $empty;

	/** @var string[] Numeric columns (right-aligned). */
	private array $numeric;

	/**
	 * Constructor.
	 *
	 * @param array<string, array{0: string, 1: bool}> $spec          Columns.
	 * @param array<int, array>                        $rows          Rows: cells (escaped HTML) and sort (raw values).
	 * @param string                                   $default_sort  Column sorted by default.
	 * @param string                                   $default_order asc|desc.
	 * @param int                                      $per_page      Rows per page.
	 * @param string                                   $empty         Message when there are no rows.
	 * @param string[]                                 $numeric       Right-aligned columns.
	 */
	public function __construct( array $spec, array $rows, string $default_sort, string $default_order = 'desc', int $per_page = 100, string $empty = '', array $numeric = array() ) {
		$this->spec          = $spec;
		$this->rows          = $rows;
		$this->default_sort  = $default_sort;
		$this->default_order = $default_order;
		$this->per_page      = $per_page;
		$this->empty         = $empty;
		$this->numeric       = $numeric;

		parent::__construct(
			array(
				'singular' => 'pqbg-report-row',
				'plural'   => 'pqbg-report-rows',
				'ajax'     => false,
				'screen'   => get_current_screen() ?? AdminUrl::REPORTS,
			)
		);
	}

	/**
	 * The sort column and direction requested in the URL (validated), or the defaults.
	 *
	 * @param array<string, array{0: string, 1: bool}> $spec          Columns.
	 * @param string                                   $default_sort  Default column.
	 * @param string                                   $default_order Default direction.
	 * @return array{0: string, 1: string}
	 */
	public static function requested_sort( array $spec, string $default_sort, string $default_order ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view; validated against the column list.
		$by    = isset( $_GET['orderby'] ) && is_string( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$order = isset( $_GET['order'] ) && is_string( $_GET['order'] ) ? strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : '';
		// phpcs:enable

		$by = isset( $spec[ $by ] ) && $spec[ $by ][1] ? $by : $default_sort;

		return array( $by, in_array( $order, array( 'asc', 'desc' ), true ) ? $order : $default_order );
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array_map( static fn( $c ) => $c[0], $this->spec );
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns() {
		$out = array();

		foreach ( $this->spec as $key => $c ) {
			if ( $c[1] ) {
				$out[ $key ] = array( $key, in_array( $key, $this->numeric, true ) );
			}
		}

		return $out;
	}

	/**
	 * Sorts and pages the rows.
	 */
	public function prepare_items() {
		list( $by, $order ) = self::requested_sort( $this->spec, $this->default_sort, $this->default_order );

		$rows = $this->rows;
		usort(
			$rows,
			static function ( $a, $b ) use ( $by, $order ) {
				$x = $a['sort'][ $by ] ?? null;
				$y = $b['sort'][ $by ] ?? null;

				// Unknown values (null) always go last, whatever the direction.
				if ( null === $x || null === $y ) {
					return ( null === $x ) <=> ( null === $y );
				}

				$cmp = is_string( $x ) && ! is_numeric( $x ) ? strcasecmp( $x, (string) $y ) : ( (float) $x <=> (float) $y );

				return 'asc' === $order ? $cmp : -$cmp;
			}
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only paging.
		$paged = isset( $_GET['paged'] ) && is_string( $_GET['paged'] ) && ctype_digit( $_GET['paged'] ) && strlen( $_GET['paged'] ) < 7 ? max( 1, (int) $_GET['paged'] ) : 1;
		$pages = max( 1, (int) ceil( count( $rows ) / $this->per_page ) );
		$paged = min( $paged, $pages );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), array_key_first( $this->spec ) );
		$this->items           = array_slice( $rows, ( $paged - 1 ) * $this->per_page, $this->per_page );

		$this->set_pagination_args(
			array(
				'total_items' => count( $rows ),
				'per_page'    => $this->per_page,
				'total_pages' => $pages,
			)
		);
	}

	/**
	 * Message when there are no rows.
	 */
	public function no_items() {
		echo esc_html( '' === $this->empty ? __( 'Nothing to show for this period.', 'product-qrcode-barcode-generator' ) : $this->empty );
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
	 * A cell (already escaped).
	 *
	 * @param array<string, mixed> $item        Row.
	 * @param string               $column_name Column.
	 */
	protected function column_default( $item, $column_name ) {
		return $item['cells'][ $column_name ] ?? '';
	}

	/**
	 * Table classes: right-align the numeric columns through CSS.
	 *
	 * @return string[]
	 */
	protected function get_table_classes() {
		return array_merge( parent::get_table_classes(), array( 'pqbg-report-table' ) );
	}

	/**
	 * Adds a class to numeric cells.
	 *
	 * @param array<string, mixed> $item Row.
	 */
	public function single_row( $item ) {
		echo '<tr>';

		list( $columns ) = $this->get_column_info();

		foreach ( array_keys( $columns ) as $key ) {
			$class = 'column-' . $key . ( in_array( $key, $this->numeric, true ) ? ' pqbg-num' : '' );
			echo '<td class="' . esc_attr( $class ) . '" data-colname="' . esc_attr( wp_strip_all_tags( $columns[ $key ] ) ) . '">' . $this->column_default( $item, $key ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cells are escaped by ReportsAdmin.
		}

		echo '</tr>';
	}
}
