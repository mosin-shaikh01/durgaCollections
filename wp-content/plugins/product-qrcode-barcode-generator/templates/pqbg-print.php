<?php
/**
 * Print page template (standalone document; see PrintPage).
 *
 * Every value is escaped here. The QR and barcode SVGs come from the renderers
 * (fixed markup, the only text is the validated, XML-escaped code) and the
 * geometry CSS from PrintPage::css() (numbers and fixed selectors only).
 *
 * @package ProductQrBarcode
 *
 * @var array<string, mixed> $view
 */

use ProductQrBarcode\{PrintJob, PrintLayout, PrintPage};

defined( 'ABSPATH' ) || exit;

$pqbg_v     = $view;
$pqbg_mode  = $pqbg_v['mode'];
$pqbg_texts = array(
	'local'   => __( 'QR codes currently point to a local address. Do not print labels until the production URL is set.', 'product-qrcode-barcode-generator' ),
	'http'    => __( 'Labels should use an https:// scan URL in production.', 'product-qrcode-barcode-generator' ),
	'name'    => __( 'product name', 'product-qrcode-barcode-generator' ),
	'attributes' => __( 'variation attributes', 'product-qrcode-barcode-generator' ),
	'sku'     => __( 'SKU', 'product-qrcode-barcode-generator' ),
	'price'   => __( 'price', 'product-qrcode-barcode-generator' ),
	'store'   => __( 'store name', 'product-qrcode-barcode-generator' ),
);
?><!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $pqbg_v['title'] ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( PQBG_PLUGIN_URL . 'assets/pqbg-print.css?ver=' . PQBG_VERSION ); ?>">
<?php if ( 'labels' === $pqbg_mode ) : ?>
<style nonce="<?php echo esc_attr( $pqbg_v['nonce'] ); ?>">
<?php echo $pqbg_v['css']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numbers and fixed selectors only (PrintPage::css()). ?>

</style>
<script src="<?php echo esc_url( PQBG_PLUGIN_URL . 'assets/pqbg-print.js?ver=' . PQBG_VERSION ); ?>" defer></script>
<?php endif; ?>
</head>
<body class="pqbg-print pqbg-print--<?php echo esc_attr( $pqbg_mode ); ?>">
<div class="pqbg-toolbar">
	<h1><?php echo esc_html( $pqbg_v['title'] ); ?></h1>

<?php if ( 'error' === $pqbg_mode ) : ?>
	<p class="pqbg-error" role="alert"><?php echo esc_html( $pqbg_v['message'] ); ?></p>
	<p><a class="pqbg-button" href="<?php echo esc_url( $pqbg_v['back_url'] ); ?>"><?php esc_html_e( 'Back', 'product-qrcode-barcode-generator' ); ?></a></p>

<?php elseif ( 'confirm' === $pqbg_mode ) : ?>
	<div class="pqbg-warning pqbg-warning--big" role="alert">
		<p><strong><?php esc_html_e( 'QR codes point to a LOCAL address:', 'product-qrcode-barcode-generator' ); ?></strong> <code><?php echo esc_html( $pqbg_v['base'] ); ?></code></p>
		<p><?php esc_html_e( 'Phones cannot open this address from outside this computer. Set the production scan URL before printing real labels.', 'product-qrcode-barcode-generator' ); ?></p>
		<p><?php esc_html_e( 'You can still print test labels: every label will say "TEST – NOT FOR USE".', 'product-qrcode-barcode-generator' ); ?></p>
	</div>
	<p>
		<a class="pqbg-button pqbg-button--primary pqbg-confirm" href="<?php echo esc_url( $pqbg_v['confirm_url'] ); ?>"><?php esc_html_e( 'Print TEST labels anyway', 'product-qrcode-barcode-generator' ); ?></a>
		<a class="pqbg-button" href="<?php echo esc_url( $pqbg_v['setup_url'] ); ?>"><?php esc_html_e( 'Back', 'product-qrcode-barcode-generator' ); ?></a>
	</p>

<?php else : ?>
	<?php
	$pqbg_fit     = $pqbg_v['fit'];
	$pqbg_spec    = $pqbg_v['spec'];
	$pqbg_thermal = 'thermal' === $pqbg_spec['type'];
	?>
	<p class="pqbg-summary">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: labels, 2: layout name, 3: pages. */
				_n( '%1$d label · %2$s · %3$d page', '%1$d labels · %2$s · %3$d pages', $pqbg_v['total'], 'product-qrcode-barcode-generator' ),
				$pqbg_v['total'],
				$pqbg_v['layout_name'],
				$pqbg_v['sheets']
			)
		);
		echo ' · ';
		/* translators: 1: QR size (mm), 2: module size (mm). */
		echo esc_html( sprintf( __( 'QR code %1$s mm (%2$s mm per module)', 'product-qrcode-barcode-generator' ), PrintLayout::mm( $pqbg_fit['qr'], 1 ), PrintLayout::mm( $pqbg_fit['module'], 3 ) ) );
		?>
	</p>
	<p class="pqbg-actions">
		<button type="button" class="pqbg-button pqbg-button--primary" id="pqbg-print-button"><?php esc_html_e( 'Print', 'product-qrcode-barcode-generator' ); ?></button>
		<a class="pqbg-button" href="<?php echo esc_url( $pqbg_v['setup_url'] ); ?>"><?php esc_html_e( 'Change options', 'product-qrcode-barcode-generator' ); ?></a>
		<a class="pqbg-button" href="<?php echo esc_url( $pqbg_v['back_url'] ); ?>"><?php esc_html_e( 'Back to products', 'product-qrcode-barcode-generator' ); ?></a>
	</p>
	<div class="pqbg-help">
		<p><strong><?php esc_html_e( 'In the print dialog:', 'product-qrcode-barcode-generator' ); ?></strong></p>
		<ul>
			<li><?php esc_html_e( 'Scale: 100% ("Default" in Chrome and Edge; "Actual size" in other dialogs). Never "Fit to page".', 'product-qrcode-barcode-generator' ); ?></li>
			<li><?php esc_html_e( 'Margins: None.', 'product-qrcode-barcode-generator' ); ?></li>
			<li><?php esc_html_e( 'Headers and footers: off.', 'product-qrcode-barcode-generator' ); ?></li>
			<?php if ( $pqbg_thermal ) : ?>
				<?php /* translators: 1: label width, 2: label height (mm). */ ?>
				<li><?php echo esc_html( sprintf( __( 'Paper size: %1$s × %2$s mm (set this label size in the thermal printer\'s own settings too). One label per page.', 'product-qrcode-barcode-generator' ), PrintLayout::mm( $pqbg_spec['label_w'] ), PrintLayout::mm( $pqbg_spec['label_h'] ) ) ); ?></li>
			<?php else : ?>
				<?php /* translators: 1: page width, 2: page height (mm). */ ?>
				<li><?php echo esc_html( sprintf( __( 'Paper: %1$s × %2$s mm, portrait. Print one page on plain paper first and hold it against a label sheet to check the alignment.', 'product-qrcode-barcode-generator' ), PrintLayout::mm( $pqbg_spec['page_w'] ), PrintLayout::mm( $pqbg_spec['page_h'] ) ) ); ?></li>
			<?php endif; ?>
			<li><?php esc_html_e( 'Background graphics are not needed: labels are printed in black on white.', 'product-qrcode-barcode-generator' ); ?></li>
		</ul>
	</div>
	<?php if ( $pqbg_v['local'] ) : ?>
		<p class="pqbg-warning pqbg-test-notice" role="alert"><strong><?php esc_html_e( 'TEST labels.', 'product-qrcode-barcode-generator' ); ?></strong> <?php echo esc_html( $pqbg_texts['local'] ); ?> <?php esc_html_e( 'Every label is marked "TEST – NOT FOR USE".', 'product-qrcode-barcode-generator' ); ?></p>
	<?php elseif ( $pqbg_v['http'] ) : ?>
		<p class="pqbg-warning pqbg-http-notice"><?php echo esc_html( $pqbg_texts['http'] ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $pqbg_spec['warning'] ) : ?>
		<p class="pqbg-warning"><?php echo esc_html( $pqbg_spec['warning'] ); ?></p>
	<?php endif; ?>
	<?php if ( array() !== $pqbg_fit['dropped'] ) : ?>
		<?php /* translators: %s: list of fields. */ ?>
		<p class="pqbg-note pqbg-dropped"><?php echo esc_html( sprintf( __( 'Not printed on this label size (not enough room): %s.', 'product-qrcode-barcode-generator' ), implode( ', ', array_map( static fn( $f ) => $pqbg_texts[ $f ], $pqbg_fit['dropped'] ) ) ) ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $pqbg_fit['barcode_omitted'] ) : ?>
		<?php /* translators: %s: barcode width in mm. */ ?>
		<p class="pqbg-note pqbg-barcode-omitted"><?php echo esc_html( sprintf( __( 'Barcode not printed on this label size: it needs a label at least %s mm wide inside its margins, with room left for the QR code.', 'product-qrcode-barcode-generator' ), PrintLayout::mm( PrintLayout::BARCODE_MAX_MODULES * PrintLayout::BARCODE_X_MM ) ) ); ?></p>
	<?php endif; ?>
<?php endif; ?>

<?php if ( 'error' !== $pqbg_mode && ( array() !== $pqbg_v['skipped'] || array() !== $pqbg_v['notes'] ) ) : ?>
	<ul class="pqbg-skipped">
		<?php foreach ( $pqbg_v['skipped'] as $pqbg_s ) : ?>
			<li class="pqbg-skip pqbg-skip--<?php echo esc_attr( $pqbg_s['reason'] ); ?>"><?php echo esc_html( PrintJob::reason_text( $pqbg_s['reason'] ) ); ?>: <?php echo esc_html( '' !== $pqbg_s['name'] ? $pqbg_s['name'] : '#' . $pqbg_s['id'] ); ?> <span class="pqbg-muted">#<?php echo esc_html( (string) $pqbg_s['id'] ); ?></span>
				<?php if ( '' !== $pqbg_s['edit_url'] ) : ?>
					<a href="<?php echo esc_url( $pqbg_s['edit_url'] ); ?>"><?php esc_html_e( 'Open product', 'product-qrcode-barcode-generator' ); ?></a>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
		<?php foreach ( $pqbg_v['notes'] as $pqbg_id => $pqbg_note ) : ?>
			<li class="pqbg-stock-note">#<?php echo esc_html( (string) $pqbg_id ); ?>: <?php echo esc_html( $pqbg_note ); ?></li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
</div>
<?php if ( 'labels' === $pqbg_mode ) : ?>
<main class="pqbg-sheets">
	<?php
	$pqbg_sheet = -1;
	foreach ( $pqbg_v['labels'] as $pqbg_label ) :
		if ( $pqbg_label['sheet'] !== $pqbg_sheet ) :
			if ( $pqbg_sheet >= 0 ) :
				echo "</section>\n";
			endif;
			$pqbg_sheet = $pqbg_label['sheet'];
			/* translators: 1: page number, 2: pages. */
			echo '<section class="pqbg-sheet" aria-label="' . esc_attr( sprintf( __( 'Page %1$d of %2$d', 'product-qrcode-barcode-generator' ), $pqbg_sheet + 1, $pqbg_v['sheets'] ) ) . '">' . "\n";
		endif;
		$pqbg_code = $pqbg_label['code'];
		?>
		<div class="pqbg-label pqbg-s<?php echo (int) $pqbg_label['slot']; ?>" data-code="<?php echo esc_attr( $pqbg_code ); ?>">
			<div class="pqbg-qr"><?php echo $pqbg_label['qr_svg']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer SVG. ?></div>
			<div class="pqbg-text">
				<?php if ( $pqbg_fit['test_mark'] ) : ?>
					<div class="pqbg-l pqbg-l-test"><?php echo esc_html( PrintPage::TEST_MARK ); ?></div>
				<?php endif; ?>
				<?php
				$pqbg_values = array(
					'name'       => $pqbg_label['name'],
					'attributes' => $pqbg_label['attributes'],
					'sku'        => $pqbg_label['sku'],
					'price'      => $pqbg_label['price'],
					'store'      => $pqbg_v['store'],
				);
				foreach ( array_keys( $pqbg_fit['lines'] ) as $pqbg_field ) :
					if ( '' !== $pqbg_values[ $pqbg_field ] ) :
						?>
						<div class="pqbg-l pqbg-l-<?php echo esc_attr( $pqbg_field ); ?>"><?php echo esc_html( $pqbg_values[ $pqbg_field ] ); ?></div>
						<?php
					endif;
				endforeach;
				?>
				<div class="pqbg-l pqbg-l-code"><?php echo 2 === $pqbg_fit['code_lines'] ? esc_html( substr( $pqbg_code, 0, 8 ) ) . '<br>' . esc_html( substr( $pqbg_code, 8 ) ) : esc_html( $pqbg_code ); ?></div>
			</div>
			<?php if ( '' !== $pqbg_label['barcode_svg'] ) : ?>
				<div class="pqbg-bc"><?php echo $pqbg_label['barcode_svg']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer SVG. ?></div>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</section>
</main>
<?php endif; ?>
</body>
</html>
