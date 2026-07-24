/**
 * @jest-environment jsdom
 *
 * CON10 B1 — the per-row Reprocess button confirms via IDS.confirmModal
 * (variant: danger), never the native window.confirm(). On confirm the form
 * submits; on cancel it does not.
 */

// Attach the delegated submit listener once (the module is an IIFE).
window.IDS = { confirmModal: jest.fn() };
require( '../../src/js/in-reprocess.js' );

describe( 'CON10 B1 — Reprocess confirm modal', () => {
	let form;

	beforeEach( () => {
		document.body.innerHTML = '';
		window.IDS.confirmModal = jest.fn();

		form = document.createElement( 'form' );
		form.method = 'post';
		form.innerHTML =
			'<input type="hidden" name="in_reprocess_post_id" value="42">' +
			'<button type="submit" name="in_reprocess">Reprocess</button>';
		document.body.appendChild( form );
		// jsdom does not implement HTMLFormElement.submit().
		form.submit = jest.fn();
	} );

	function dispatchSubmit( target ) {
		const evt = new Event( 'submit', { bubbles: true, cancelable: true } );
		target.dispatchEvent( evt );
		return evt;
	}

	test( 'shows IDS.confirmModal and suppresses the immediate submit', () => {
		const evt = dispatchSubmit( form );

		expect( evt.defaultPrevented ).toBe( true );
		expect( window.IDS.confirmModal ).toHaveBeenCalledTimes( 1 );
		expect( form.submit ).not.toHaveBeenCalled();
	} );

	test( 'uses the danger variant', () => {
		dispatchSubmit( form );
		const opts = window.IDS.confirmModal.mock.calls[ 0 ][ 0 ];
		expect( opts.variant ).toBe( 'danger' );
		expect( typeof opts.onConfirm ).toBe( 'function' );
	} );

	test( 'submits the form when the user confirms', () => {
		dispatchSubmit( form );
		const opts = window.IDS.confirmModal.mock.calls[ 0 ][ 0 ];
		opts.onConfirm();
		expect( form.submit ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'does not submit when the user cancels (onConfirm never fires)', () => {
		dispatchSubmit( form );
		expect( form.submit ).not.toHaveBeenCalled();
	} );

	test( 'ignores submits from non-Reprocess forms', () => {
		const other = document.createElement( 'form' );
		other.innerHTML =
			'<button type="submit" name="in_check_now">Check Now</button>';
		document.body.appendChild( other );
		other.submit = jest.fn();

		const evt = dispatchSubmit( other );

		expect( window.IDS.confirmModal ).not.toHaveBeenCalled();
		expect( evt.defaultPrevented ).toBe( false );
	} );

	test( 'does not reference the native confirm()', () => {
		const src = require( 'fs' ).readFileSync(
			require( 'path' ).resolve(
				__dirname,
				'../../src/js/in-reprocess.js'
			),
			'utf8'
		);
		expect( src ).not.toMatch( /\bconfirm\s*\(/ );
	} );
} );
