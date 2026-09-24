/**
 * Product QR Code and Barcode Generator: click-to-load QR codes.
 *
 * "View QR" links point to an authenticated, side-effect-free admin-post.php URL.
 * Without JavaScript they open the SVG in a new tab; with it, the SVG is shown
 * in place as an <img> (scripts never run in an image). Delegated, because
 * WooCommerce loads variation panels over AJAX.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var link = event.target && event.target.closest ? event.target.closest( 'a.pqbg-view-qr' ) : null;

		if ( ! link ) {
			return;
		}

		var slot = link.parentNode ? link.parentNode.querySelector( '.pqbg-qr-slot' ) : null;

		if ( ! slot ) {
			return;
		}

		event.preventDefault();

		if ( slot.firstChild ) {
			slot.textContent = '';
			return;
		}

		var img = document.createElement( 'img' );
		img.src = link.href;
		img.alt = link.textContent;
		slot.appendChild( img );
	} );
}() );
