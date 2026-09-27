/**
 * QR & Barcodes → Code tools / Import cost prices: continues a running batch job
 * by submitting its "Continue" form after a short pause. Without JavaScript the
 * user presses Continue; nothing else on these screens needs this script.
 */
( function () {
	'use strict';

	var form = document.querySelector( 'form.pqbg-auto-continue' );

	if ( ! form ) {
		return;
	}

	var button = form.querySelector( '[type="submit"]' );

	window.setTimeout( function () {
		if ( button ) {
			button.disabled = true;
		}
		form.submit();
	}, 800 );
}() );
