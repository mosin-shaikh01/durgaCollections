<?php
/**
 * End-of-day print template (standalone document; see ReportPrint).
 *
 * The end-of-day content comes from ReportsAdmin::render_eod_body(), which
 * escapes every value. No inline styles or scripts (the CSP allows only the two
 * same-origin files).
 *
 * @package ProductQrBarcode
 *
 * @var array<string, mixed> $view
 */

use ProductQrBarcode\ReportsAdmin;

defined( 'ABSPATH' ) || exit;

$pqbg_v = $view;
?><!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $pqbg_v['title'] ); ?></title>
<link rel="stylesheet" href="<?php echo esc_url( PQBG_PLUGIN_URL . 'assets/pqbg-report-print.css?ver=' . PQBG_VERSION ); ?>">
<script src="<?php echo esc_url( PQBG_PLUGIN_URL . 'assets/pqbg-print.js?ver=' . PQBG_VERSION ); ?>" defer></script>
</head>
<body class="pqbg-report-print">
<div class="pqbg-report-print__toolbar">
	<a href="<?php echo esc_url( $pqbg_v['back'] ); ?>"><?php esc_html_e( 'Back to In-store reports', 'product-qrcode-barcode-generator' ); ?></a>
<?php if ( 'eod' === $pqbg_v['mode'] ) : ?>
	<button type="button" id="pqbg-print-button"><?php esc_html_e( 'Print', 'product-qrcode-barcode-generator' ); ?></button>
<?php endif; ?>
</div>
<?php if ( 'error' === $pqbg_v['mode'] ) : ?>
<main>
	<h1><?php echo esc_html( $pqbg_v['title'] ); ?></h1>
	<p class="pqbg-report-print__error" role="alert"><?php echo esc_html( $pqbg_v['message'] ); ?></p>
</main>
<?php else : ?>
<main>
	<p class="pqbg-report-print__store"><?php echo esc_html( $pqbg_v['store'] ); ?> · <?php esc_html_e( 'In-store (scan) sales only', 'product-qrcode-barcode-generator' ); ?></p>
	<?php ReportsAdmin::render_eod_body( $pqbg_v['data'], $pqbg_v['label'] ); ?>
	<p class="pqbg-report-print__printed">
		<?php
		/* translators: %s: date and time. */
		echo esc_html( sprintf( __( 'Printed %s', 'product-qrcode-barcode-generator' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) );
		?>
	</p>
</main>
<?php endif; ?>
</body>
</html>
