/*
 * Print page (PrintPage): the Print button opens the browser's print dialog.
 * The page works without this script (Ctrl+P / Cmd+P).
 */
( function () {
	'use strict';

	var button = document.getElementById( 'pqbg-print-button' );

	if ( button ) {
		button.addEventListener( 'click', function () {
			window.print();
		} );
	}
}() );
