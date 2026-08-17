/**
 * Marine Unit — admin scripts.
 *
 * Confirms the destructive-looking actions before they run, and wires up the
 * branding colour pickers.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		$( '.mup-color-picker' ).wpColorPicker();

		var strings = window.mupAdmin || {};

		$( '.mup-admin' ).on( 'click', 'a.mup-danger', function ( event ) {
			var message = strings.confirmRemoveType || 'Remove this item?';

			if ( ! window.confirm( message ) ) {
				event.preventDefault();
			}
		} );
	} );
} )( jQuery );
