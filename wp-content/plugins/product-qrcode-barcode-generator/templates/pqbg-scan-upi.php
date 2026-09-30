<?php
/**
 * The UPI payment panel of the scan page (Phase 16; a partial of templates/pqbg-scan.php
 * since Phase 17, when the basket shows it too). $pqbg_upi['basket'] marks the basket's
 * panel, whose form posts basket_confirm to /scan/basket/ instead of sell.
 *
 * @package ProductQrBarcode
 */

defined( 'ABSPATH' ) || exit;

/** @var array<string, mixed> $pqbg_upi */
?>
	<form class="pqbg-scan__sell pqbg-scan__upi" method="post" action="<?php echo esc_url( $pqbg_upi['action'] ); ?>">
		<h2 class="pqbg-scan__sell-title"><?php esc_html_e( 'Pay by UPI', 'product-qrcode-barcode-generator' ); ?></h2>
		<p class="pqbg-scan__upi-amount"><?php echo esc_html( $pqbg_upi['amount'] ); ?></p>
		<p class="pqbg-scan__hint">
			<?php
			echo esc_html(
				empty( $pqbg_upi['basket'] )
					/* translators: %s: quantity. */
					? sprintf( __( 'Quantity: %s', 'product-qrcode-barcode-generator' ), $pqbg_upi['quantity'] )
					/* translators: %s: number of items in the basket. */
					: sprintf( __( 'Items: %s', 'product-qrcode-barcode-generator' ), $pqbg_upi['quantity'] )
			);
			?>
		</p>
		<?php if ( '' !== $pqbg_upi['qr'] ) : ?>
			<div class="pqbg-scan__upi-qr"><?php echo $pqbg_upi['qr']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built by QrRenderer/Svg from integers and escaped text. ?></div>
		<?php else : ?>
			<p class="pqbg-scan__notice pqbg-scan__notice--warning" role="alert">
				<?php
				/* translators: %s: UPI ID. */
				echo esc_html( sprintf( __( 'The payment QR could not be shown. Ask the customer to pay to %s.', 'product-qrcode-barcode-generator' ), $pqbg_upi['upi_id'] ) );
				?>
			</p>
		<?php endif; ?>
		<dl class="pqbg-scan__facts">
			<dt><?php esc_html_e( 'Pay to', 'product-qrcode-barcode-generator' ); ?></dt>
			<dd><?php echo esc_html( $pqbg_upi['payee'] ); ?></dd>
			<dt><?php esc_html_e( 'UPI ID', 'product-qrcode-barcode-generator' ); ?></dt>
			<dd><?php echo esc_html( $pqbg_upi['upi_id'] ); ?></dd>
			<dt><?php esc_html_e( 'Reference', 'product-qrcode-barcode-generator' ); ?></dt>
			<dd class="pqbg-scan__code"><?php echo esc_html( $pqbg_upi['reference'] ); ?></dd>
		</dl>
		<p class="pqbg-scan__notice pqbg-scan__notice--warning"><?php esc_html_e( 'Check the customer\'s payment success screen before confirming.', 'product-qrcode-barcode-generator' ); ?></p>
		<input type="hidden" name="pqbg_action" value="<?php echo esc_attr( empty( $pqbg_upi['basket'] ) ? 'sell' : 'basket_confirm' ); ?>">
		<input type="hidden" name="_pqbg_nonce" value="<?php echo esc_attr( $pqbg_upi['nonce'] ); ?>">
		<?php foreach ( $pqbg_upi['fields'] as $pqbg_name => $pqbg_value ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $pqbg_name ); ?>" value="<?php echo esc_attr( $pqbg_value ); ?>">
		<?php endforeach; ?>
		<button class="pqbg-scan__button pqbg-scan__button--sell" type="submit"><?php esc_html_e( 'Payment received – confirm sale', 'product-qrcode-barcode-generator' ); ?></button>
		<p><a class="pqbg-scan__button pqbg-scan__button--link pqbg-scan__button--back" href="<?php echo esc_url( $pqbg_upi['back'] ); ?>"><?php esc_html_e( 'Back', 'product-qrcode-barcode-generator' ); ?></a></p>
	</form>
