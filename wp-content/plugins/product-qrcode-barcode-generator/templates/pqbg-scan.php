<?php
/**
 * Scan page template. A complete, standalone HTML document: it does not use
 * the active theme (no get_header()/get_footer()) and does not call
 * wp_head()/wp_footer(), so nothing but this markup and the plugin's own
 * stylesheet is sent. No JavaScript. The only inline CSS is one style element with
 * the response's CSP nonce, on a sale page with an Undo form: it sets how many
 * seconds are left before the form hides itself (Phase 11; see pqbg-scan.css).
 *
 * Every value is escaped here. The only HTML taken from elsewhere is the
 * price (WooCommerce's price functions, passed through wp_kses_post()), the
 * image tag (wp_get_attachment_image(), which escapes its attributes) and the
 * UPI payment QR (Phase 16: SVG written by QrRenderer/Svg, integers and escaped
 * text only).
 *
 * Phase 16: the UPI panel replaces the sale form after "Confirm sale" with UPI
 * (UpiSale); its form posts the same sale fields plus the UPI confirmation. The
 * sale page and My sales link to each sale's receipt (a separate page with its own
 * template, templates/pqbg-receipt.php).
 *
 * Phase 17: the basket (open basket with its POST "Scan to add" box, line forms and
 * confirm form; a sold basket with Undo), "Add to basket" on the sale form, the basket
 * bar and header link, and basket entries on My sales. The UPI panel is in the partial
 * templates/pqbg-scan-upi.php, shared by the product screen and the basket.
 *
 * The sale and Undo forms are separate from the code box, so pressing Enter
 * in the box (or a scanner that types a code and Enter) only looks up a code
 * and never submits a sale.
 *
 * The header links to My sales for users who may see their own sales (Phase 9A),
 * and back to the scan page from My sales. My sales ends with a plain link to the seller
 * guide PDF (1.0.1; a link needs nothing from the page's CSP).
 *
 * Loaded by ScanScreen::render() with $view, $entry_url, $logout_url and
 * $sales_url ('' when the user may not see My sales) in scope.
 *
 * @package ProductQrBarcode
 */

defined( 'ABSPATH' ) || exit;

/** @var array<string, mixed> $view */
/** @var string $entry_url */
/** @var string $logout_url */
/** @var string $sales_url */
/** @var string $basket_url */

$pqbg_mine    = $view['mine'];
$pqbg_product = $view['product'];
$pqbg_summary = $view['summary'];
$pqbg_sell    = $view['sell'];
$pqbg_sale    = $view['sale'];
$pqbg_undo    = $view['undo'];
$pqbg_upi     = $view['upi'];
$pqbg_basket  = $view['basket'];
$pqbg_bsale   = $view['basket_sale'];
$pqbg_bbar    = is_array( $pqbg_sell ) ? ( $pqbg_sell['basket'] ?? null ) : null;
$pqbg_bopen   = is_array( $pqbg_bbar ) && $pqbg_bbar['open'];
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<title><?php echo esc_html( __( 'Scan', 'product-qrcode-barcode-generator' ) . ' – ' . get_bloginfo( 'name' ) ); ?></title>
<?php wp_print_styles( \ProductQrBarcode\ScanScreen::STYLE_HANDLE ); ?>
<?php if ( is_array( $pqbg_undo ) && '' !== $view['style_nonce'] ) : ?>
<style nonce="<?php echo esc_attr( $view['style_nonce'] ); ?>">.pqbg-scan__undo,.pqbg-scan__undo-expired{animation-delay:<?php echo (int) $pqbg_undo['remaining']; ?>s}</style>
<?php endif; ?>
</head>
<body class="pqbg-scan">
<header class="pqbg-scan__bar">
	<span class="pqbg-scan__site"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
	<?php if ( is_array( $pqbg_mine ) ) : ?>
		<a class="pqbg-scan__nav" href="<?php echo esc_url( $entry_url ); ?>"><?php esc_html_e( 'Scan', 'product-qrcode-barcode-generator' ); ?></a>
	<?php elseif ( '' !== $sales_url ) : ?>
		<a class="pqbg-scan__nav" href="<?php echo esc_url( $sales_url ); ?>"><?php esc_html_e( 'My sales', 'product-qrcode-barcode-generator' ); ?></a>
	<?php endif; ?>
	<?php if ( '' !== $basket_url ) : ?>
		<a class="pqbg-scan__nav" href="<?php echo esc_url( $basket_url ); ?>"><?php esc_html_e( 'Basket', 'product-qrcode-barcode-generator' ); ?></a>
	<?php endif; ?>
	<a class="pqbg-scan__logout" href="<?php echo esc_url( $logout_url ); ?>"><?php esc_html_e( 'Log out', 'product-qrcode-barcode-generator' ); ?></a>
</header>
<main class="pqbg-scan__main">
<?php if ( $view['box'] ) : ?>
	<form class="pqbg-scan__form" method="get" action="<?php echo esc_url( $entry_url ); ?>" role="search">
		<label class="pqbg-scan__label" for="pqbg-code"><?php echo esc_html( '' !== $view['box_label'] ? $view['box_label'] : __( 'Scan or type a code', 'product-qrcode-barcode-generator' ) ); ?></label>
		<div class="pqbg-scan__row">
			<input class="pqbg-scan__input" id="pqbg-code" name="code" type="text" value="<?php echo esc_attr( $view['value'] ); ?>" placeholder="<?php echo esc_attr( $view['placeholder'] ); ?>" maxlength="200" required autofocus autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false" enterkeyhint="go">
			<button class="pqbg-scan__button" type="submit"><?php esc_html_e( 'Look up', 'product-qrcode-barcode-generator' ); ?></button>
		</div>
	</form>
<?php endif; ?>

<?php foreach ( $view['notices'] as $pqbg_notice ) : ?>
	<p class="pqbg-scan__notice pqbg-scan__notice--<?php echo esc_attr( $pqbg_notice[0] ); ?>" role="alert"><?php echo esc_html( $pqbg_notice[1] ); ?></p>
<?php endforeach; ?>

<?php if ( is_array( $pqbg_mine ) ) : ?>
	<nav class="pqbg-scan__tabs">
		<?php foreach ( $pqbg_mine['tabs'] as $pqbg_tab ) : ?>
			<a class="pqbg-scan__tab<?php echo $pqbg_tab[2] ? ' pqbg-scan__tab--current' : ''; ?>" href="<?php echo esc_url( $pqbg_tab[0] ); ?>"<?php echo $pqbg_tab[2] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $pqbg_tab[1] ); ?></a>
		<?php endforeach; ?>
	</nav>
	<h1 class="pqbg-scan__name"><?php echo esc_html( $pqbg_mine['title'] ); ?></h1>
	<section class="pqbg-scan__summary">
		<h2 class="pqbg-scan__sell-title"><?php esc_html_e( 'Summary (completed sales)', 'product-qrcode-barcode-generator' ); ?></h2>
		<table class="pqbg-scan__table">
			<?php foreach ( array_merge( $pqbg_mine['summary'], array( $pqbg_mine['total'] ) ) as $pqbg_i => $pqbg_line ) : ?>
				<tr<?php echo count( $pqbg_mine['summary'] ) === $pqbg_i ? ' class="pqbg-scan__total-row"' : ''; ?>>
					<th scope="row"><?php echo esc_html( $pqbg_line[0] ); ?></th>
					<td><?php echo esc_html( $pqbg_line[1] ); ?></td>
					<td class="pqbg-scan__amount"><?php echo esc_html( $pqbg_line[2] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
		<p class="pqbg-scan__hint">
			<?php
			/* translators: %s: number of voided sales. */
			echo esc_html( sprintf( __( 'Voided: %s', 'product-qrcode-barcode-generator' ), number_format_i18n( $pqbg_mine['voided'] ) ) );
			?>
		</p>
	</section>
	<section class="pqbg-scan__lines">
		<h2 class="pqbg-scan__sell-title"><?php esc_html_e( 'Sales', 'product-qrcode-barcode-generator' ); ?></h2>
		<?php if ( array() === $pqbg_mine['lines'] ) : ?>
			<p class="pqbg-scan__hint"><?php esc_html_e( 'No sales in this period.', 'product-qrcode-barcode-generator' ); ?></p>
		<?php endif; ?>
		<ol class="pqbg-scan__list">
			<?php foreach ( $pqbg_mine['lines'] as $pqbg_line ) : ?>
				<li class="pqbg-scan__line pqbg-scan__line--<?php echo esc_attr( $pqbg_line['status'] ); ?>">
					<span class="pqbg-scan__line-time"><?php echo esc_html( $pqbg_line['time'] ); ?></span>
					<?php if ( '' !== $pqbg_line['url'] ) : ?>
						<a class="pqbg-scan__line-item" href="<?php echo esc_url( $pqbg_line['url'] ); ?>"><?php echo esc_html( $pqbg_line['item'] ); ?></a>
					<?php else : ?>
						<span class="pqbg-scan__line-item"><?php echo esc_html( $pqbg_line['item'] ); ?></span>
					<?php endif; ?>
					<span class="pqbg-scan__line-detail"><?php echo esc_html( $pqbg_line['amount'] . ' · ' . $pqbg_line['method'] . ' · ' . $pqbg_line['label'] ); ?> · <a class="pqbg-scan__line-receipt" href="<?php echo esc_url( $pqbg_line['receipt'] ); ?>"><?php esc_html_e( 'Receipt', 'product-qrcode-barcode-generator' ); ?></a></span>
					<?php if ( array() !== $pqbg_line['parts'] ) : ?>
						<ul class="pqbg-scan__parts">
							<?php foreach ( $pqbg_line['parts'] as $pqbg_part ) : ?>
								<li><?php echo esc_html( $pqbg_part[0] . ' · ' . $pqbg_part[1] ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php if ( $pqbg_mine['more'] > 0 ) : ?>
			<p class="pqbg-scan__hint">
				<?php
				/* translators: %s: number of older sales not listed. */
				echo esc_html( sprintf( __( '%s older sales in this period are not listed. The summary includes them.', 'product-qrcode-barcode-generator' ), number_format_i18n( $pqbg_mine['more'] ) ) );
				?>
			</p>
		<?php endif; ?>
	</section>
	<p class="pqbg-scan__hint pqbg-scan__guide"><a href="<?php echo esc_url( $pqbg_mine['guide'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'How to sell (guide)', 'product-qrcode-barcode-generator' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'product-qrcode-barcode-generator' ); ?></span></a></p>
<?php elseif ( is_array( $pqbg_product ) ) : ?>
	<article class="pqbg-scan__product">
		<?php if ( '' !== $pqbg_product['image_html'] ) : ?>
			<div class="pqbg-scan__media"><?php echo $pqbg_product['image_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image() escapes its output. ?></div>
		<?php endif; ?>
		<h1 class="pqbg-scan__name"><?php echo esc_html( $pqbg_product['name'] ); ?></h1>
		<?php if ( '' !== trim( $pqbg_product['attributes'] ) ) : ?>
			<p class="pqbg-scan__attributes"><?php echo esc_html( $pqbg_product['attributes'] ); ?></p>
		<?php endif; ?>
		<p class="pqbg-scan__price">
			<?php if ( '' !== $pqbg_product['price_html'] ) : ?>
				<?php echo wp_kses_post( $pqbg_product['price_html'] ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Price not set', 'product-qrcode-barcode-generator' ); ?>
			<?php endif; ?>
		</p>
		<p class="pqbg-scan__stock">
			<?php
			echo esc_html(
				null === $pqbg_product['stock_qty']
					? $pqbg_product['stock']
					/* translators: 1: an amount or a stock status, 2: the number of sales or the quantity in stock. */
					: sprintf( __( '%1$s (%2$s)', 'product-qrcode-barcode-generator' ), $pqbg_product['stock'], number_format_i18n( $pqbg_product['stock_qty'] ) )
			);
			?>
		</p>
		<dl class="pqbg-scan__facts">
			<?php if ( '' !== $pqbg_product['sku'] ) : ?>
				<dt><?php esc_html_e( 'SKU', 'product-qrcode-barcode-generator' ); ?></dt>
				<dd><?php echo esc_html( $pqbg_product['sku'] ); ?></dd>
			<?php endif; ?>
			<?php if ( array() !== $pqbg_product['categories'] ) : ?>
				<dt><?php esc_html_e( 'Categories', 'product-qrcode-barcode-generator' ); ?></dt>
				<dd><?php echo esc_html( implode( ', ', $pqbg_product['categories'] ) ); ?></dd>
			<?php endif; ?>
			<dt><?php esc_html_e( 'Code', 'product-qrcode-barcode-generator' ); ?></dt>
			<dd class="pqbg-scan__code"><?php echo esc_html( $view['code'] ); ?></dd>
		</dl>
	</article>
	<?php if ( is_array( $pqbg_sell ) ) : ?>
		<?php if ( $pqbg_bopen ) : ?>
			<p class="pqbg-scan__basket-bar"><?php echo esc_html( $pqbg_bbar['text'] ); ?> · <a href="<?php echo esc_url( $pqbg_bbar['url'] ); ?>"><?php esc_html_e( 'View basket', 'product-qrcode-barcode-generator' ); ?></a></p>
		<?php endif; ?>
		<form class="pqbg-scan__sell" method="post" action="<?php echo esc_url( $pqbg_sell['action'] ); ?>">
			<h2 class="pqbg-scan__sell-title"><?php echo esc_html( $pqbg_bopen ? __( 'Add to basket', 'product-qrcode-barcode-generator' ) : __( 'Sell', 'product-qrcode-barcode-generator' ) ); ?></h2>
			<input type="hidden" name="pqbg_action" value="<?php echo esc_attr( $pqbg_bopen ? 'basket_add' : 'sell' ); ?>">
			<input type="hidden" name="_pqbg_nonce" value="<?php echo esc_attr( $pqbg_sell['nonce'] ); ?>">
			<?php foreach ( $pqbg_sell['fields'] as $pqbg_name => $pqbg_value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $pqbg_name ); ?>" value="<?php echo esc_attr( $pqbg_value ); ?>">
			<?php endforeach; ?>
			<label class="pqbg-scan__label" for="pqbg-quantity"><?php esc_html_e( 'Quantity', 'product-qrcode-barcode-generator' ); ?></label>
			<?php if ( array() !== $pqbg_sell['options'] ) : ?>
				<select class="pqbg-scan__quantity" id="pqbg-quantity" name="quantity">
					<?php foreach ( $pqbg_sell['options'] as $pqbg_qty => $pqbg_label ) : ?>
						<option value="<?php echo esc_attr( (string) $pqbg_qty ); ?>"<?php selected( $pqbg_sell['quantity'], $pqbg_qty ); ?>><?php echo esc_html( $pqbg_label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<input class="pqbg-scan__quantity" id="pqbg-quantity" name="quantity" type="number" inputmode="numeric" min="1" max="<?php echo esc_attr( (string) $pqbg_sell['max'] ); ?>" step="1" value="<?php echo esc_attr( (string) $pqbg_sell['quantity'] ); ?>" required>
				<p class="pqbg-scan__hint">
					<?php
					/* translators: %s: unit price. */
					echo esc_html( sprintf( __( 'Total = quantity × %s', 'product-qrcode-barcode-generator' ), $pqbg_sell['unit'] ) );
					?>
				</p>
			<?php endif; ?>
			<?php if ( $pqbg_bopen ) : ?>
				<button class="pqbg-scan__button pqbg-scan__button--sell" type="submit"><?php esc_html_e( 'Add to basket', 'product-qrcode-barcode-generator' ); ?></button>
			<?php else : ?>
				<fieldset class="pqbg-scan__payment">
					<legend class="pqbg-scan__label"><?php esc_html_e( 'Paid by', 'product-qrcode-barcode-generator' ); ?></legend>
					<?php foreach ( $pqbg_sell['methods'] as $pqbg_key => $pqbg_method ) : ?>
						<label class="pqbg-scan__method"><input type="radio" name="payment_method" value="<?php echo esc_attr( $pqbg_key ); ?>"<?php checked( $pqbg_sell['method'], $pqbg_key ); ?> required> <?php echo esc_html( $pqbg_method ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<button class="pqbg-scan__button pqbg-scan__button--sell" type="submit"><?php esc_html_e( 'Confirm sale', 'product-qrcode-barcode-generator' ); ?></button>
				<?php if ( is_array( $pqbg_bbar ) && $pqbg_bbar['ok'] ) : ?>
					<button class="pqbg-scan__button pqbg-scan__button--basket" type="submit" name="pqbg_basket" value="1" formnovalidate><?php esc_html_e( 'Add to basket', 'product-qrcode-barcode-generator' ); ?></button>
				<?php endif; ?>
			<?php endif; ?>
		</form>
	<?php elseif ( is_array( $pqbg_upi ) ) : ?>
		<?php require __DIR__ . '/pqbg-scan-upi.php'; ?>
	<?php endif; ?>
<?php elseif ( is_array( $pqbg_sale ) ) : ?>
	<article class="pqbg-scan__product pqbg-scan__sale">
		<h1 class="pqbg-scan__name"><?php echo esc_html( $pqbg_sale['name'] ); ?></h1>
		<?php if ( '' !== trim( $pqbg_sale['attributes'] ) ) : ?>
			<p class="pqbg-scan__attributes"><?php echo esc_html( $pqbg_sale['attributes'] ); ?></p>
		<?php endif; ?>
		<dl class="pqbg-scan__facts">
			<dt><?php esc_html_e( 'Quantity', 'product-qrcode-barcode-generator' ); ?></dt>
			<dd><?php echo esc_html( $pqbg_sale['quantity'] . ' × ' . $pqbg_sale['unit'] ); ?></dd>
			<dt><?php esc_html_e( 'Total', 'product-qrcode-barcode-generator' ); ?></dt>
			<dd class="pqbg-scan__total"><?php echo esc_html( $pqbg_sale['total'] ); ?></dd>
			<dt><?php esc_html_e( 'Paid by', 'product-qrcode-barcode-generator' ); ?></dt>
			<dd><?php echo esc_html( $pqbg_sale['payment'] ); ?></dd>
			<?php if ( '' !== $pqbg_sale['stock'] ) : ?>
				<dt><?php esc_html_e( 'Stock now', 'product-qrcode-barcode-generator' ); ?></dt>
				<dd><?php echo esc_html( $pqbg_sale['stock'] ); ?></dd>
			<?php endif; ?>
			<dt><?php esc_html_e( 'Time', 'product-qrcode-barcode-generator' ); ?></dt>
			<dd><?php echo esc_html( $pqbg_sale['time'] ); ?></dd>
			<?php if ( '' !== $pqbg_sale['sku'] ) : ?>
				<dt><?php esc_html_e( 'SKU', 'product-qrcode-barcode-generator' ); ?></dt>
				<dd><?php echo esc_html( $pqbg_sale['sku'] ); ?></dd>
			<?php endif; ?>
			<dt><?php esc_html_e( 'Code', 'product-qrcode-barcode-generator' ); ?></dt>
			<dd class="pqbg-scan__code"><?php echo esc_html( $view['code'] ); ?></dd>
		</dl>
	</article>
	<?php if ( '' !== ( $pqbg_sale['receipt'] ?? '' ) ) : ?>
		<p><a class="pqbg-scan__button pqbg-scan__button--link pqbg-scan__button--receipt" href="<?php echo esc_url( $pqbg_sale['receipt'] ); ?>"><?php esc_html_e( 'Receipt', 'product-qrcode-barcode-generator' ); ?></a></p>
	<?php endif; ?>
	<?php if ( is_array( $pqbg_undo ) ) : ?>
		<form class="pqbg-scan__undo" method="post" action="<?php echo esc_url( $pqbg_undo['action'] ); ?>">
			<input type="hidden" name="pqbg_action" value="undo">
			<input type="hidden" name="_pqbg_nonce" value="<?php echo esc_attr( $pqbg_undo['nonce'] ); ?>">
			<input type="hidden" name="sale" value="<?php echo esc_attr( $pqbg_undo['sale_id'] ); ?>">
			<button class="pqbg-scan__button pqbg-scan__button--undo" type="submit"><?php esc_html_e( 'Undo this sale', 'product-qrcode-barcode-generator' ); ?></button>
			<p class="pqbg-scan__hint">
				<?php
				/* translators: %s: time. */
				echo esc_html( sprintf( __( 'Undo available until %s', 'product-qrcode-barcode-generator' ), $pqbg_undo['until'] ) );
				?>
			</p>
		</form>
		<p class="pqbg-scan__hint pqbg-scan__undo-expired"><?php esc_html_e( 'Undo is no longer available. Ask a manager to void the sale if needed.', 'product-qrcode-barcode-generator' ); ?></p>
	<?php endif; ?>
<?php elseif ( is_array( $pqbg_basket ) ) : ?>
	<h1 class="pqbg-scan__name"><?php esc_html_e( 'Basket', 'product-qrcode-barcode-generator' ); ?></h1>
	<?php if ( $pqbg_basket['scan'] ) : ?>
		<form class="pqbg-scan__form" method="post" action="<?php echo esc_url( $pqbg_basket['action'] ); ?>">
			<input type="hidden" name="pqbg_action" value="basket_scan">
			<input type="hidden" name="_pqbg_nonce" value="<?php echo esc_attr( $pqbg_basket['scan_nonce'] ); ?>">
			<label class="pqbg-scan__label" for="pqbg-code"><?php esc_html_e( 'Scan to add', 'product-qrcode-barcode-generator' ); ?></label>
			<div class="pqbg-scan__row">
				<input class="pqbg-scan__input" id="pqbg-code" name="code" type="text" value="<?php echo esc_attr( $pqbg_basket['value'] ); ?>" placeholder="<?php echo esc_attr( $view['placeholder'] ); ?>" maxlength="200" required autofocus autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false" enterkeyhint="go">
				<button class="pqbg-scan__button" type="submit"><?php esc_html_e( 'Add', 'product-qrcode-barcode-generator' ); ?></button>
			</div>
			<p class="pqbg-scan__hint"><?php esc_html_e( 'Each scan adds 1. Scanning the same item again adds 1 more.', 'product-qrcode-barcode-generator' ); ?></p>
		</form>
	<?php endif; ?>
	<?php if ( array() === $pqbg_basket['lines'] ) : ?>
		<p class="pqbg-scan__hint"><?php esc_html_e( 'The basket is empty. Scan an item to add it.', 'product-qrcode-barcode-generator' ); ?></p>
	<?php else : ?>
		<ol class="pqbg-scan__list pqbg-scan__basket">
			<?php foreach ( $pqbg_basket['lines'] as $pqbg_i => $pqbg_line ) : ?>
				<li class="pqbg-scan__line<?php echo '' !== $pqbg_line['problem'] ? ' pqbg-scan__line--problem' : ''; ?>">
					<a class="pqbg-scan__line-item" href="<?php echo esc_url( $pqbg_line['url'] ); ?>"><?php echo esc_html( $pqbg_line['name'] ); ?></a>
					<span class="pqbg-scan__line-detail"><?php echo esc_html( number_format_i18n( $pqbg_line['quantity'] ) . ' × ' . $pqbg_line['unit'] . ' = ' . $pqbg_line['total'] ); ?></span>
					<?php if ( '' !== $pqbg_line['problem'] ) : ?>
						<span class="pqbg-scan__line-problem" role="alert"><?php echo esc_html( $pqbg_line['problem'] ); ?></span>
					<?php endif; ?>
					<?php if ( '' !== $pqbg_line['note'] ) : ?>
						<span class="pqbg-scan__hint pqbg-scan__line-note"><?php echo esc_html( $pqbg_line['note'] ); ?></span>
					<?php endif; ?>
					<?php if ( ! is_array( $pqbg_upi ) ) : ?>
						<div class="pqbg-scan__line-actions">
							<form class="pqbg-scan__inline" method="post" action="<?php echo esc_url( $pqbg_basket['action'] ); ?>">
								<input type="hidden" name="pqbg_action" value="basket_qty">
								<input type="hidden" name="_pqbg_nonce" value="<?php echo esc_attr( $pqbg_basket['edit_nonce'] ); ?>">
								<input type="hidden" name="rev" value="<?php echo esc_attr( $pqbg_basket['rev'] ); ?>">
								<input type="hidden" name="code" value="<?php echo esc_attr( $pqbg_line['code'] ); ?>">
								<label class="screen-reader-text" for="pqbg-qty-<?php echo (int) $pqbg_i; ?>"><?php esc_html_e( 'Quantity', 'product-qrcode-barcode-generator' ); ?></label>
								<input class="pqbg-scan__quantity pqbg-scan__quantity--small" id="pqbg-qty-<?php echo (int) $pqbg_i; ?>" name="quantity" type="number" inputmode="numeric" min="1" max="<?php echo esc_attr( (string) $pqbg_line['max'] ); ?>" step="1" value="<?php echo esc_attr( (string) $pqbg_line['quantity'] ); ?>" required>
								<button class="pqbg-scan__button pqbg-scan__button--small" type="submit"><?php esc_html_e( 'Update', 'product-qrcode-barcode-generator' ); ?></button>
							</form>
							<form class="pqbg-scan__inline" method="post" action="<?php echo esc_url( $pqbg_basket['action'] ); ?>">
								<input type="hidden" name="pqbg_action" value="basket_remove">
								<input type="hidden" name="_pqbg_nonce" value="<?php echo esc_attr( $pqbg_basket['edit_nonce'] ); ?>">
								<input type="hidden" name="rev" value="<?php echo esc_attr( $pqbg_basket['rev'] ); ?>">
								<input type="hidden" name="code" value="<?php echo esc_attr( $pqbg_line['code'] ); ?>">
								<button class="pqbg-scan__button pqbg-scan__button--small pqbg-scan__button--remove" type="submit"><?php esc_html_e( 'Remove', 'product-qrcode-barcode-generator' ); ?></button>
							</form>
						</div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
		<p class="pqbg-scan__basket-total">
			<?php
			/* translators: 1: number of items, 2: total. */
			echo esc_html( sprintf( __( '%1$s items · Total %2$s', 'product-qrcode-barcode-generator' ), number_format_i18n( $pqbg_basket['items'] ), $pqbg_basket['total'] ) );
			?>
		</p>
		<?php if ( is_array( $pqbg_basket['confirm'] ) ) : ?>
			<form class="pqbg-scan__sell" method="post" action="<?php echo esc_url( $pqbg_basket['confirm']['action'] ); ?>">
				<h2 class="pqbg-scan__sell-title"><?php esc_html_e( 'Sell the basket', 'product-qrcode-barcode-generator' ); ?></h2>
				<input type="hidden" name="pqbg_action" value="basket_confirm">
				<input type="hidden" name="_pqbg_nonce" value="<?php echo esc_attr( $pqbg_basket['confirm']['nonce'] ); ?>">
				<?php foreach ( $pqbg_basket['confirm']['fields'] as $pqbg_name => $pqbg_value ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $pqbg_name ); ?>" value="<?php echo esc_attr( $pqbg_value ); ?>">
				<?php endforeach; ?>
				<fieldset class="pqbg-scan__payment">
					<legend class="pqbg-scan__label"><?php esc_html_e( 'Paid by', 'product-qrcode-barcode-generator' ); ?></legend>
					<?php foreach ( $pqbg_basket['confirm']['methods'] as $pqbg_key => $pqbg_method ) : ?>
						<label class="pqbg-scan__method"><input type="radio" name="payment_method" value="<?php echo esc_attr( $pqbg_key ); ?>"<?php checked( $pqbg_basket['confirm']['method'], $pqbg_key ); ?> required> <?php echo esc_html( $pqbg_method ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<button class="pqbg-scan__button pqbg-scan__button--sell" type="submit">
					<?php
					/* translators: %s: basket total. */
					echo esc_html( sprintf( __( 'Confirm sale – %s', 'product-qrcode-barcode-generator' ), $pqbg_basket['total'] ) );
					?>
				</button>
			</form>
		<?php elseif ( is_array( $pqbg_upi ) ) : ?>
			<?php require __DIR__ . '/pqbg-scan-upi.php'; ?>
		<?php endif; ?>
		<?php if ( $pqbg_basket['clear_confirm'] ) : ?>
			<form class="pqbg-scan__clear" method="post" action="<?php echo esc_url( $pqbg_basket['action'] ); ?>">
				<p class="pqbg-scan__notice pqbg-scan__notice--warning">
					<?php
					/* translators: %s: number of items. */
					echo esc_html( sprintf( _n( 'Clear the basket (%s item)?', 'Clear the basket (%s items)?', $pqbg_basket['items'], 'product-qrcode-barcode-generator' ), number_format_i18n( $pqbg_basket['items'] ) ) );
					?>
				</p>
				<input type="hidden" name="pqbg_action" value="basket_clear">
				<input type="hidden" name="_pqbg_nonce" value="<?php echo esc_attr( $pqbg_basket['edit_nonce'] ); ?>">
				<input type="hidden" name="rev" value="<?php echo esc_attr( $pqbg_basket['rev'] ); ?>">
				<input type="hidden" name="confirm" value="1">
				<button class="pqbg-scan__button pqbg-scan__button--remove" type="submit"><?php esc_html_e( 'Yes, clear the basket', 'product-qrcode-barcode-generator' ); ?></button>
				<p><a class="pqbg-scan__button pqbg-scan__button--link" href="<?php echo esc_url( $pqbg_basket['action'] ); ?>"><?php esc_html_e( 'No, keep it', 'product-qrcode-barcode-generator' ); ?></a></p>
			</form>
		<?php elseif ( ! is_array( $pqbg_upi ) ) : ?>
			<form class="pqbg-scan__clear" method="post" action="<?php echo esc_url( $pqbg_basket['action'] ); ?>">
				<input type="hidden" name="pqbg_action" value="basket_clear">
				<input type="hidden" name="_pqbg_nonce" value="<?php echo esc_attr( $pqbg_basket['edit_nonce'] ); ?>">
				<input type="hidden" name="rev" value="<?php echo esc_attr( $pqbg_basket['rev'] ); ?>">
				<button class="pqbg-scan__button pqbg-scan__button--link" type="submit"><?php esc_html_e( 'Clear basket', 'product-qrcode-barcode-generator' ); ?></button>
			</form>
		<?php endif; ?>
	<?php endif; ?>
<?php elseif ( is_array( $pqbg_bsale ) ) : ?>
	<article class="pqbg-scan__product pqbg-scan__sale">
		<h1 class="pqbg-scan__name">
			<?php
			/* translators: %s: sale (receipt) number. */
			echo esc_html( sprintf( __( 'Sale no. %s', 'product-qrcode-barcode-generator' ), $pqbg_bsale['number'] ) );
			?>
		</h1>
		<ol class="pqbg-scan__list">
			<?php foreach ( $pqbg_bsale['lines'] as $pqbg_line ) : ?>
				<li class="pqbg-scan__line pqbg-scan__line--<?php echo esc_attr( $pqbg_line['status'] ); ?>">
					<span class="pqbg-scan__line-item"><?php echo esc_html( $pqbg_line['name'] ); ?></span>
					<span class="pqbg-scan__line-detail"><?php echo esc_html( $pqbg_line['amount'] . ( '' !== $pqbg_line['label'] ? ' · ' . $pqbg_line['label'] : '' ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ol>
		<dl class="pqbg-scan__facts">
			<dt><?php esc_html_e( 'Total', 'product-qrcode-barcode-generator' ); ?></dt>
			<dd class="pqbg-scan__total"><?php echo esc_html( $pqbg_bsale['total'] . ' (' . $pqbg_bsale['items'] . ')' ); ?></dd>
			<dt><?php esc_html_e( 'Paid by', 'product-qrcode-barcode-generator' ); ?></dt>
			<dd><?php echo esc_html( $pqbg_bsale['payment'] ); ?></dd>
			<dt><?php esc_html_e( 'Time', 'product-qrcode-barcode-generator' ); ?></dt>
			<dd><?php echo esc_html( $pqbg_bsale['time'] ); ?></dd>
		</dl>
	</article>
	<?php if ( '' !== $pqbg_bsale['receipt'] ) : ?>
		<p><a class="pqbg-scan__button pqbg-scan__button--link pqbg-scan__button--receipt" href="<?php echo esc_url( $pqbg_bsale['receipt'] ); ?>"><?php esc_html_e( 'Receipt', 'product-qrcode-barcode-generator' ); ?></a></p>
	<?php endif; ?>
	<?php if ( is_array( $pqbg_undo ) ) : ?>
		<form class="pqbg-scan__undo" method="post" action="<?php echo esc_url( $pqbg_undo['action'] ); ?>">
			<input type="hidden" name="pqbg_action" value="basket_undo">
			<input type="hidden" name="_pqbg_nonce" value="<?php echo esc_attr( $pqbg_undo['nonce'] ); ?>">
			<input type="hidden" name="basket" value="<?php echo esc_attr( $pqbg_undo['basket_id'] ); ?>">
			<button class="pqbg-scan__button pqbg-scan__button--undo" type="submit"><?php esc_html_e( 'Undo whole sale', 'product-qrcode-barcode-generator' ); ?></button>
			<p class="pqbg-scan__hint">
				<?php
				/* translators: %s: time. */
				echo esc_html( sprintf( __( 'Undo available until %s', 'product-qrcode-barcode-generator' ), $pqbg_undo['until'] ) );
				?>
			</p>
		</form>
		<p class="pqbg-scan__hint pqbg-scan__undo-expired"><?php esc_html_e( 'Undo is no longer available. Ask a manager to void the sale if needed.', 'product-qrcode-barcode-generator' ); ?></p>
	<?php endif; ?>
<?php elseif ( is_array( $pqbg_summary ) ) : ?>
	<article class="pqbg-scan__product">
		<h1 class="pqbg-scan__name"><?php echo esc_html( $pqbg_summary['name'] ); ?></h1>
		<dl class="pqbg-scan__facts">
			<?php if ( '' !== $pqbg_summary['sku'] ) : ?>
				<dt><?php esc_html_e( 'SKU', 'product-qrcode-barcode-generator' ); ?></dt>
				<dd><?php echo esc_html( $pqbg_summary['sku'] ); ?></dd>
			<?php endif; ?>
			<dt><?php esc_html_e( 'Code', 'product-qrcode-barcode-generator' ); ?></dt>
			<dd class="pqbg-scan__code"><?php echo esc_html( $view['code'] ); ?></dd>
		</dl>
	</article>
<?php elseif ( '' !== $view['code'] ) : ?>
	<p class="pqbg-scan__code pqbg-scan__code--alone"><?php echo esc_html( $view['code'] ); ?></p>
<?php endif; ?>

<?php foreach ( $view['links'] as $pqbg_link ) : ?>
	<p><a class="pqbg-scan__button pqbg-scan__button--link" href="<?php echo esc_url( $pqbg_link[0] ); ?>"><?php echo esc_html( $pqbg_link[1] ); ?></a></p>
<?php endforeach; ?>
</main>
</body>
</html>
