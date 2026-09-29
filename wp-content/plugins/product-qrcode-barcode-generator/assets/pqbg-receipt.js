/*
 * Receipt page (Phase 16): the Print button opens the browser's print dialog.
 * Loaded only by the receipt page, with that response's CSP nonce. The page works
 * without this script (the browser's Print, or Share → Print on a phone).
 */
( function () {
	'use strict';

	var button = document.getElementById( 'pqbg-receipt-print' );

	if ( button ) {
		button.addEventListener( 'click', function () {
			window.print();
		} );
	}
}() );
