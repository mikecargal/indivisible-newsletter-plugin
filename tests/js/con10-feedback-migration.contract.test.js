/**
 * @jest-environment node
 *
 * CON10 satisfy-guard — the aggregate invariant suite (the path the
 * contract-status `satisfy` guard checks for):
 *
 *   G1: no native alert()/confirm()/prompt() in the plugin's user-facing code.
 *   G2: no deprecated .ids-notice / .ids-message anywhere in plugin source.
 *
 * Comments are stripped before scanning so prose mentioning these tokens does
 * not trip the guard — only real occurrences in code count.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const SRC = path.resolve( __dirname, '../../src' );

function walk( dir, exts ) {
	const out = [];
	for ( const entry of fs.readdirSync( dir, { withFileTypes: true } ) ) {
		const full = path.join( dir, entry.name );
		if ( entry.isDirectory() ) {
			out.push( ...walk( full, exts ) );
		} else if ( exts.includes( path.extname( entry.name ) ) ) {
			out.push( full );
		}
	}
	return out;
}

// Strip /* block */, // line, and # line comments so descriptive prose that
// mentions a forbidden token never counts as a violation.
function stripComments( code ) {
	return code
		.replace( /\/\*[\s\S]*?\*\//g, '' )
		.replace( /(^|[^:])\/\/.*$/gm, '$1' )
		.replace( /^\s*#.*$/gm, '' );
}

describe( 'CON10 satisfy-guard — feedback migration invariants', () => {
	test( 'G1: no native alert/confirm/prompt anywhere in plugin source', () => {
		const files = walk( SRC, [ '.js', '.php' ] );
		expect( files.length ).toBeGreaterThan( 0 );

		const offenders = [];
		for ( const file of files ) {
			const code = stripComments( fs.readFileSync( file, 'utf8' ) );
			// Native global modal calls. ids_render_alert() / IDS.confirmModal()
			// / IDS.promptModal() do not match (word boundary excludes
			// "_alert", and "Modal" follows "confirm"/"prompt").
			if ( /\b(?:window\.)?(?:alert|confirm|prompt)\s*\(/.test( code ) ) {
				offenders.push( path.relative( SRC, file ) );
			}
		}
		expect( offenders ).toEqual( [] );
	} );

	test( 'G2: no deprecated .ids-notice / .ids-message in plugin source', () => {
		const files = walk( SRC, [ '.js', '.php', '.css' ] );

		const offenders = [];
		for ( const file of files ) {
			const code = stripComments( fs.readFileSync( file, 'utf8' ) );
			if ( /ids-notice|ids-message/.test( code ) ) {
				offenders.push( path.relative( SRC, file ) );
			}
		}
		expect( offenders ).toEqual( [] );
	} );
} );
