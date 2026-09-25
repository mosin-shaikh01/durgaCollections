<?php
/**
 * Scan page template. A complete, standalone HTML document: it does not use
 * the active theme (no get_header()/get_footer()) and does not call
 * wp_head()/wp_footer(), so nothing but this markup and the plugin's own
 * stylesheet is sent. No JavaScript.
 *
 * Every value is escaped here. The only HTML taken from elsewhere is the
 * price (WooCommerce's price functions, passed through wp_kses_post()) and
 * the image tag (wp_get_attachment_image(), which escapes its attributes).
 *
 * The sale and Undo forms are separate from the code box, so pressing Enter
 * in the box (or a scanner that types a code and Enter) only looks up a code
 * and never submits a sale.
 *
 * Loaded by ScanScreen::render() with $view, $entry_url and $logout_url in scope.
 *
 * @package ProductQrBarcode
 */

defined( 'ABSPATH' ) || exit;

/** @var array<string, mixed> $view */
/** @var string $entry_url */
/** @var string $logout_url */

$pqbg_product = $view['product'];
$pqbg_summary = $view['summary'];
$pqbg_sell    = $view['sell'];
$pqbg_sale    = $view['sale'];
$pqbg_undo    = $view['undo'];
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<title><?php echo esc_html( __( 'Scan', 'product-qrcode-barcode-generator' ) . ' – ' . get_bloginfo( 'name' ) ); ?></title>
<?php wp_print_styles( \ProductQrBarcode\ScanScreen::STYLE_HANDLE ); ?>
</head>
<body class="pqbg-scan">
<header class="pqbg-scan__bar">
	<span class="pqbg-scan__site"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
	<a class="pqbg-scan__logout" href="<?php echo esc_url( $logout_url ); ?>"><?php esc_html_e( 'Log out', 'product-qrcode-barcode-generator' ); ?></a>
</header>
<main class="pqbg-scan__main">
<?php if ( $view['box'] ) : ?>
	<form class="pqbg-scan__form" method="get" action="<?php echo esc_url( $entry_url ); ?>" role="search">
		<label class="pqbg-scan__label" for="pqbg-code"><?php echo esc_html( '' !== $view['box_label'] ? $view['box_label'] : __( 'Scan or type a code', 'product-qrcode-barcode-generator' ) ); ?></label>
		<div class="pqbg-scan__row">
			<input class="pqbg-scan__input" id="pqbg-code" name="code" type="text" value="<?php echo esc_attr( $view['value'] ); ?>" placeholder="DC-XXXX-XXXX-XXXX" maxlength="200" required autofocus autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false" enterkeyhint="go">
			<button class="pqbg-scan__button" type="submit"><?php esc_html_e( 'Look up', 'product-qrcode-barcode-generator' ); ?></button>
		</div>
	</form>
<?php endif; ?>

<?php foreach ( $view['notices'] as $pqbg_notice ) : ?>
	<p class="pqbg-scan__notice pqbg-scan__notice--<?php echo esc_attr( $pqbg_notice[0] ); ?>" role="alert"><?php echo esc_html( $pqbg_notice[1] ); ?></p>
<?php endforeach; ?>

<?php if ( is_array( $pqbg_product ) ) : ?>
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
					/* translators: 1: stock status, 2: quantity in stock. */
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
		<form class="pqbg-scan__sell" method="post" action="<?php echo esc_url( $pqbg_sell['action'] ); ?>">
			<h2 class="pqbg-scan__sell-title"><?php esc_html_e( 'Sell', 'product-qrcode-barcode-generator' ); ?></h2>
			<input type="hidden" name="pqbg_action" value="sell">
			<input type="hidden" name="_pqbg_nonce" value="<?php echo esc_attr( $pqbg_sell['nonce'] ); ?>">
			<?php foreach ( $pqbg_sell['fields'] as $pqbg_name => $pqbg_value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $pqbg_name ); ?>" value="<?php echo esc_attr( $pqbg_value ); ?>">
			<?php endforeach; ?>
			<label class="pqbg-scan__label" for="pqbg-quantity"><?php esc_html_e( 'Quantity', 'product-qrcode-barcode-generator' ); ?></label>
			<?php if ( array() !== $pqbg_sell['options'] ) : ?>
				<select class="pqbg-scan__quantity" id="pqbg-quantity" name="quantity">
					<?php foreach ( $pqbg_sell['options'] as $pqbg_qty => $pqbg_label ) : ?>
						<option value="<?php echo esc_attr( (string) $pqbg_qty ); ?>"<?php selected( 1, $pqbg_qty ); ?>><?php echo esc_html( $pqbg_label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<input class="pqbg-scan__quantity" id="pqbg-quantity" name="quantity" type="number" inputmode="numeric" min="1" max="<?php echo esc_attr( (string) $pqbg_sell['max'] ); ?>" step="1" value="1" required>
				<p class="pqbg-scan__hint">
					<?php
					/* translators: %s: unit price. */
					echo esc_html( sprintf( __( 'Total = quantity × %s', 'product-qrcode-barcode-generator' ), $pqbg_sell['unit'] ) );
					?>
				</p>
			<?php endif; ?>
			<button class="pqbg-scan__button pqbg-scan__button--sell" type="submit"><?php esc_html_e( 'Confirm sale', 'product-qrcode-barcode-generator' ); ?></button>
		</form>
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
