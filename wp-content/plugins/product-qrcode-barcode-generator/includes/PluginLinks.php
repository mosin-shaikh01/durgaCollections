<?php
/**
 * The plugin's row on the Plugins screen (1.0.1): a "User manual" link next to "Version | By".
 *
 * Row meta (plugin_row_meta), not an action link: the action links (Deactivate …) are for actions.
 * The Plugins screen is only for users who may manage plugins; the manual is a public static PDF
 * (sample data only), so the link needs no capability of its own. Its URL comes from AdminUrl.
 *
 * @package ProductQrBarcode
 */

namespace ProductQrBarcode;

defined( 'ABSPATH' ) || exit;

/**
 * Plugins screen links.
 */
final class PluginLinks {

	/**
	 * Hooks used on admin requests.
	 */
	public static function register(): void {
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
	}

	/**
	 * Adds "User manual" to this plugin's row only.
	 *
	 * @param string[]     $meta        Row meta links.
	 * @param string|mixed $plugin_file Plugin file relative to the plugins folder.
	 * @return string[]
	 */
	public static function row_meta( $meta, $plugin_file ): array {
		$meta = is_array( $meta ) ? $meta : array();

		if ( plugin_basename( PQBG_PLUGIN_FILE ) !== $plugin_file ) {
			return $meta;
		}

		$meta[] = '<a class="pqbg-manual-link" href="' . esc_url( AdminUrl::user_manual() ) . '" target="_blank" rel="noopener">' . esc_html__( 'User manual', 'product-qrcode-barcode-generator' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'product-qrcode-barcode-generator' ) . '</span></a>';

		return $meta;
	}
}
