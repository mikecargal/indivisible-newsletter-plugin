/**
 * CON10 B1 — replace the per-row Reprocess native confirm dialog with the
 * canonical IDS.confirmModal (variant: danger). Delegated on document so it
 * covers every Reprocess form in the Recent Newsletters table.
 *
 * @package Indivisible_Newsletter
 */
( function () {
	'use strict';

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if (
			! form ||
			typeof form.querySelector !== 'function' ||
			! form.querySelector( 'button[name="in_reprocess"]' )
		) {
			return;
		}

		// No design system available — let the native submit proceed rather than
		// fall back to a native confirm dialog (which G1 forbids). The plugin's
		// dependency guard guarantees IDS is present in practice.
		if ( ! window.IDS || typeof window.IDS.confirmModal !== 'function' ) {
			return;
		}

		event.preventDefault();

		window.IDS.confirmModal( {
			message:
				'Reprocessing will replace the current content with a fresh clean of the original email. Any manual edits will be moved to a post revision. Continue?',
			confirmText: 'Reprocess',
			cancelText: 'Cancel',
			variant: 'danger',
			onConfirm: function () {
				form.submit();
			},
		} );
	} );
}() );
