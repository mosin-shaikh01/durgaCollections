/*
 * QR & Barcodes → Dashboard (1.0.1): the "Plugin guide" tooltip can be dismissed with Escape
 * (WCAG 1.4.13). The tooltip itself is shown by CSS on hover and keyboard focus
 * (pqbg-dashboard.css); this only hides it until the pointer or focus leaves the button.
 */
( function () {
	var help = document.querySelector( '.pqbg-help' );

	if ( ! help ) {
		return;
	}

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && help.matches( ':hover, :focus-within' ) ) {
			help.classList.add( 'pqbg-help--dismissed' );
		}
	} );

	// Shown again only after both the pointer and the focus have left.
	help.addEventListener( 'mouseleave', function () {
		if ( ! help.matches( ':focus-within' ) ) {
			help.classList.remove( 'pqbg-help--dismissed' );
		}
	} );
	help.addEventListener( 'focusout', function ( event ) {
		if ( ! help.contains( event.relatedTarget ) && ! help.matches( ':hover' ) ) {
			help.classList.remove( 'pqbg-help--dismissed' );
		}
	} );
}() );
