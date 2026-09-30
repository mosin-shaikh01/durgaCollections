<?php
/**
 * Receipt page template (Phase 16). A complete, standalone HTML document like the
 * scan page: no theme, no wp_head()/wp_footer(); only this markup, the plugin's own
 * receipt stylesheet, one style element and one script element, both carrying this
 * response's CSP nonces (ScanRoute::receipt_csp()).
 *
 *   style   the @page rule for the chosen paper (A4, 80 mm or 58 mm)
 *   script  assets/pqbg-receipt.js: the Print button calls window.print(). The page
 *           works without it (the browser's Print / Share → Print).
 *
 * The tools (paper links, Print, Share on WhatsApp) are hidden in print. The
 * receipt is plain text from Receipt::data(): every value is escaped here.
 *
 * Loaded by ScanScreen::render() with $view in scope ($entry_url, $logout_url and
 * $sales_url too, unused here).
 *
 * @package ProductQrBarcode
 */

defined( 'ABSPATH' ) || exit;

/** @var array<string, mixed> $view */

$pqbg_receipt = $view['receipt'];
$pqbg_data    = $pqbg_receipt['data'];
$pqbg_pages   = array(
	'a4' => '@page{size:A4 portrait;margin:12mm}',
	'80' => '@page{margin:0}',
	'58' => '@page{margin:0}',
);
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<title>
<?php
/* translators: %s: receipt number. */
echo esc_html( sprintf( __( 'Receipt no. %s', 'product-qrcode-barcode-generator' ), $pqbg_data['number'] ) . ' – ' . $pqbg_data['shop'] );
?>
</title>
<?php wp_print_styles( \ProductQrBarcode\ScanScreen::RECEIPT_STYLE_HANDLE ); ?>
<style nonce="<?php echo esc_attr( $view['style_nonce'] ); ?>"><?php echo esc_html( $pqbg_pages[ $pqbg_receipt['paper'] ] ?? $pqbg_pages['a4'] ); ?></style>
<script src="<?php echo esc_url( PQBG_PLUGIN_URL . 'assets/pqbg-receipt.js?ver=' . PQBG_VERSION ); ?>" nonce="<?php echo esc_attr( $view['script_nonce'] ); ?>" defer></script>
</head>
<body class="pqbg-receipt pqbg-receipt--paper-<?php echo esc_attr( $pqbg_receipt['paper'] ); ?><?php echo $pqbg_data['void'] ? ' pqbg-receipt--void' : ''; ?>">
<nav class="pqbg-receipt__tools" aria-label="<?php esc_attr_e( 'Receipt tools', 'product-qrcode-barcode-generator' ); ?>">
	<a class="pqbg-receipt__back" href="<?php echo esc_url( $pqbg_receipt['back'] ); ?>"><?php esc_html_e( 'Scan', 'product-qrcode-barcode-generator' ); ?></a>
	<span class="pqbg-receipt__papers">
		<span class="pqbg-receipt__papers-label"><?php esc_html_e( 'Paper:', 'product-qrcode-barcode-generator' ); ?></span>
		<?php foreach ( $pqbg_receipt['papers'] as $pqbg_paper ) : ?>
			<a class="pqbg-receipt__paper-link<?php echo $pqbg_paper[2] ? ' pqbg-receipt__paper-link--current' : ''; ?>" href="<?php echo esc_url( $pqbg_paper[0] ); ?>"<?php echo $pqbg_paper[2] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $pqbg_paper[1] ); ?></a>
		<?php endforeach; ?>
	</span>
	<button type="button" class="pqbg-receipt__button" id="pqbg-receipt-print"><?php esc_html_e( 'Print', 'product-qrcode-barcode-generator' ); ?></button>
	<?php if ( '' !== $pqbg_receipt['whatsapp'] ) : ?>
		<a class="pqbg-receipt__button pqbg-receipt__button--share" href="<?php echo esc_url( $pqbg_receipt['whatsapp'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Share on WhatsApp', 'product-qrcode-barcode-generator' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'product-qrcode-barcode-generator' ); ?></span></a>
	<?php endif; ?>
	<p class="pqbg-receipt__hint"><?php esc_html_e( 'Or use your browser\'s Print (or Share → Print). WhatsApp asks which chat to send the receipt to; nothing about the customer is kept.', 'product-qrcode-barcode-generator' ); ?></p>
</nav>
<main class="pqbg-receipt__paper">
	<?php if ( $pqbg_data['void'] ) : ?>
		<p class="pqbg-receipt__void"><?php esc_html_e( 'VOID', 'product-qrcode-barcode-generator' ); ?></p>
		<div class="pqbg-receipt__watermark" aria-hidden="true"><?php esc_html_e( 'VOID', 'product-qrcode-barcode-generator' ); ?></div>
	<?php endif; ?>
	<header class="pqbg-receipt__shop">
		<h1 class="pqbg-receipt__shop-name"><?php echo esc_html( $pqbg_data['shop'] ); ?></h1>
		<?php foreach ( $pqbg_data['address'] as $pqbg_line ) : ?>
			<p class="pqbg-receipt__line"><?php echo esc_html( $pqbg_line ); ?></p>
		<?php endforeach; ?>
		<?php if ( '' !== $pqbg_data['phone'] ) : ?>
			<p class="pqbg-receipt__line">
				<?php
				/* translators: %s: phone number. */
				echo esc_html( sprintf( __( 'Phone: %s', 'product-qrcode-barcode-generator' ), $pqbg_data['phone'] ) );
				?>
			</p>
		<?php endif; ?>
		<?php if ( '' !== $pqbg_data['gstin'] ) : ?>
			<p class="pqbg-receipt__line">
				<?php
				/* translators: %s: GSTIN. */
				echo esc_html( sprintf( __( 'GSTIN: %s', 'product-qrcode-barcode-generator' ), $pqbg_data['gstin'] ) );
				?>
			</p>
		<?php endif; ?>
	</header>
	<h2 class="pqbg-receipt__title"><?php esc_html_e( 'Receipt', 'product-qrcode-barcode-generator' ); ?></h2>
	<dl class="pqbg-receipt__facts">
		<dt><?php esc_html_e( 'Receipt no.', 'product-qrcode-barcode-generator' ); ?></dt>
		<dd><?php echo esc_html( $pqbg_data['number'] ); ?></dd>
		<dt><?php esc_html_e( 'Date', 'product-qrcode-barcode-generator' ); ?></dt>
		<dd><?php echo esc_html( $pqbg_data['date'] ); ?></dd>
	</dl>
	<ul class="pqbg-receipt__items">
		<?php foreach ( $pqbg_data['lines'] as $pqbg_item ) : ?>
			<li class="pqbg-receipt__item<?php echo empty( $pqbg_item['void'] ) ? '' : ' pqbg-receipt__item--void'; ?>">
				<span class="pqbg-receipt__item-name"><?php echo esc_html( $pqbg_item['name'] ); ?><?php echo empty( $pqbg_item['void'] ) ? '' : ' (' . esc_html__( 'voided', 'product-qrcode-barcode-generator' ) . ')'; ?></span>
				<?php if ( '' !== $pqbg_item['attributes'] ) : ?>
					<span class="pqbg-receipt__item-variant"><?php echo esc_html( $pqbg_item['attributes'] ); ?></span>
				<?php endif; ?>
				<span class="pqbg-receipt__item-amount pqbg-receipt__amount">
					<?php
					/* translators: 1: quantity, 2: unit price, 3: total. */
					echo esc_html( sprintf( __( '%1$s × %2$s = %3$s', 'product-qrcode-barcode-generator' ), $pqbg_item['quantity'], $pqbg_item['unit'], $pqbg_item['total'] ) );
					?>
				</span>
			</li>
		<?php endforeach; ?>
	</ul>
	<p class="pqbg-receipt__total">
		<span><?php esc_html_e( 'Total', 'product-qrcode-barcode-generator' ); ?></span>
		<span class="pqbg-receipt__amount"><?php echo esc_html( $pqbg_data['total'] ); ?></span>
	</p>
	<dl class="pqbg-receipt__facts">
		<dt><?php esc_html_e( 'Paid by', 'product-qrcode-barcode-generator' ); ?></dt>
		<dd><?php echo esc_html( $pqbg_data['payment'] ); ?></dd>
		<?php if ( '' !== $pqbg_data['reference'] ) : ?>
			<dt><?php esc_html_e( 'UPI ref.', 'product-qrcode-barcode-generator' ); ?></dt>
			<dd><?php echo esc_html( $pqbg_data['reference'] ); ?></dd>
		<?php endif; ?>
		<?php if ( '' !== $pqbg_data['seller'] ) : ?>
			<dt><?php esc_html_e( 'Served by', 'product-qrcode-barcode-generator' ); ?></dt>
			<dd><?php echo esc_html( $pqbg_data['seller'] ); ?></dd>
		<?php endif; ?>
	</dl>
	<?php if ( '' !== $pqbg_data['void_note'] ) : ?>
		<p class="pqbg-receipt__void-note"><?php echo esc_html( $pqbg_data['void_note'] ); ?></p>
	<?php endif; ?>
	<?php if ( array() !== $pqbg_data['footer'] ) : ?>
		<footer class="pqbg-receipt__footer">
			<?php foreach ( $pqbg_data['footer'] as $pqbg_line ) : ?>
				<p class="pqbg-receipt__line"><?php echo esc_html( $pqbg_line ); ?></p>
			<?php endforeach; ?>
		</footer>
	<?php endif; ?>
</main>
</body>
</html>
