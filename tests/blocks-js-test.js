/**
 * Runs the block editor script against a mocked `wp`, and asserts what it registered.
 *
 *     node tests/blocks-js-test.js
 *
 * WHY THIS EXISTS WHEN NOTHING ELSE HERE TESTS JAVASCRIPT
 * ======================================================
 *
 * Every other check in this repository asserts something about markup or about PHP, and both
 * are blind to the way this file fails. `blocks.js` runs once, at the top of the block editor,
 * and if it throws — a typo, a renamed `wp` package, an option that no longer exists — the
 * exception is caught by nothing, `registerBlockType` is never reached, and **both blocks are
 * simply absent from the inserter**. No PHP notice, no failing request, no missing asset: the
 * editor page still serves 200 with the script tag, the picker data and the server-side block
 * definitions all present, which is exactly what the live checks in `tests/live-block.php`
 * assert. Every one of them passes over a file that crashed on its first line.
 *
 * So this is deliberately not a rendering test. It does not care what the editor looks like.
 * It cares that the script runs to completion and that the two `registerBlockType` calls
 * happened with the names the metadata declares.
 *
 * The mock is the smallest thing the script will accept, and that is a property worth keeping:
 * every `wp.*` member it stubs is one the script genuinely uses, so a member added to the mock
 * without the script needing it is dead weight, and one the script starts using without being
 * added here fails loudly rather than quietly.
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

let failures = 0;

/**
 * Reports one check.
 *
 * @param {string}  label  What is being asserted.
 * @param {boolean} ok     Whether it holds.
 * @param {string}  detail Context, printed either way.
 */
function check( label, ok, detail ) {
	console.log( `${ ok ? '[OK]  ' : '[FAIL]' } ${ label.padEnd( 52 ) } ${ detail || '' }` );

	if ( ! ok ) {
		failures++;
	}
}

/**
 * A stand-in for a React component or element type.
 *
 * @param {string} name Readable name, so a failure says which one.
 *
 * @return {Function} The sentinel.
 */
function component( name ) {
	const fn = function () {
		return null;
	};

	fn.displayName = name;

	return fn;
}

const registered = {};

/**
 * The elements `createElement` produced, flattened, so a check can look for one by type.
 *
 * @param {Object} node Element returned by the mocked `createElement`.
 *
 * @return {Object[]} Every element in the tree, including the root.
 */
function flatten( node ) {
	if ( ! node || 'object' !== typeof node || ! node.__el ) {
		return [];
	}

	return node.children.reduce( ( all, child ) => all.concat( flatten( child ) ), [ node ] );
}

const wp = {
	element: {
		createElement: ( type, props, ...children ) => ( {
			__el: true,
			type,
			props: props || {},
			children: children.flat( Infinity ).filter( ( c ) => null !== c && undefined !== c )
		} )
	},
	blocks: {
		registerBlockType: ( name, settings ) => {
			registered[ name ] = settings;
		}
	},
	blockEditor: {
		useBlockProps: () => ( { className: 'wp-block-lichtbild' } ),
		InspectorControls: component( 'InspectorControls' )
	},
	components: {
		Placeholder: component( 'Placeholder' ),
		PanelBody: component( 'PanelBody' ),
		SelectControl: component( 'SelectControl' )
	},
	serverSideRender: component( 'ServerSideRender' )
};

// Shaped like what `Lichtbild_Block::editor_data()` prints, with two galleries and one album so a
// picker that offered a constant would be visible.
const data = {
	galleries: [
		{ value: 11, label: 'Zoo' },
		{ value: 22, label: 'Alps' }
	],
	albums: [ { value: 33, label: 'Travel' } ],
	i18n: {
		galleryTitle: 'Lichtbild-Galerie',
		albumTitle: 'Lichtbild-Album',
		chooseGallery: 'Galerie auswählen',
		chooseAlbum: 'Album auswählen',
		galleryInstructions: 'gallery instructions',
		albumInstructions: 'album instructions',
		settings: 'Galerie',
		albumSettings: 'Album',
		none: '— Auswählen —',
		noGalleries: 'no galleries',
		noAlbums: 'no albums',
		emptyGallery: 'empty gallery',
		emptyAlbum: 'empty album'
	}
};

const sandbox = { window: { wp, LichtbildBlocks: data }, console };
sandbox.global = sandbox;

const source = fs.readFileSync( path.join( __dirname, '..', 'assets', 'js', 'blocks.js' ), 'utf8' );

// A throw here is the whole point, so it is reported as a failing check rather than left to
// crash the process with a stack trace and no summary line.
try {
	vm.runInNewContext( source, sandbox, { filename: 'assets/js/blocks.js' } );
	check( 'the script runs to completion', true, '' );
} catch ( error ) {
	check( 'the script runs to completion', false, String( error ) );
	console.log( `\nchecks: 1, failing: ${ failures }` );
	process.exit( 1 );
}

const names = Object.keys( registered ).sort();

check(
	'both blocks registered themselves',
	2 === names.length && 'lichtbild/album' === names[ 0 ] && 'lichtbild/gallery' === names[ 1 ],
	`registered: ${ names.length ? names.join( ', ' ) : '(nothing)' }`
);

if ( 2 !== names.length ) {
	console.log( `\nchecks: 2, failing: ${ failures }` );
	process.exit( 1 );
}

// Dynamic blocks save nothing into the post content but the block comment. A `save` returning
// markup would freeze a snapshot of the gallery into every post that embeds it, which is the
// one thing this plugin's whole design is against.
check(
	'both blocks are dynamic',
	names.every( ( name ) => null === registered[ name ].save() ),
	'save() returned markup for: ' +
		( names.filter( ( name ) => null !== registered[ name ].save() ).join( ', ' ) || '(none)' )
);

// Only Lichtbild's own shortcode. `[envira-gallery]` still renders under a rollback because Envira
// registers it again; a one-click conversion with no confirmation is the wrong place to spend
// that, so a transform naming it is a regression rather than a feature.
const tags = names.flatMap( ( name ) =>
	( registered[ name ].transforms.from || [] ).flatMap( ( t ) => [].concat( t.tag ) )
).sort();

check(
	'only our own shortcodes transform',
	2 === tags.length && 'lichtbild-album' === tags[ 0 ] && 'lichtbild-gallery' === tags[ 1 ],
	`transforms from: ${ tags.join( ', ' ) || '(nothing)' }`
);

const shortcodeAttr = registered[ 'lichtbild/gallery' ].transforms.from[ 0 ].attributes.id.shortcode;

check(
	'the shortcode transform reads a number',
	11 === shortcodeAttr( { named: { id: '11' } } ) && 0 === shortcodeAttr( { named: {} } ),
	`id="11" -> ${ JSON.stringify( shortcodeAttr( { named: { id: '11' } } ) ) }, absent -> ` +
		JSON.stringify( shortcodeAttr( { named: {} } ) )
);

// --- the three states of the edit component ------------------------------------------------
//
// Each is rendered and inspected for what it contains. `edit` is a plain function here because
// nothing in it uses a hook the mock cannot answer, which is itself worth knowing: the day it
// does, this file stops running rather than quietly testing less.
const edit = registered[ 'lichtbild/gallery' ].edit;

/**
 * Renders `edit` once and returns every element it produced.
 *
 * @param {number} id       Chosen gallery.
 * @param {Object} override Extra props merged into the mocked block props.
 *
 * @return {Object[]} The flattened element tree.
 */
function render( id, override ) {
	return flatten( edit( Object.assign( { attributes: { id }, setAttributes: () => {} }, override ) ) );
}

const unchosen = render( 0 );
const chosen = render( 11 );

check(
	'an unchosen block offers the picker',
	unchosen.some( ( el ) => 'Placeholder' === el.type.displayName ) &&
		unchosen.some( ( el ) => 'SelectControl' === el.type.displayName ) &&
		! unchosen.some( ( el ) => 'ServerSideRender' === el.type.displayName ),
	'elements: ' + unchosen.map( ( el ) => el.type.displayName || el.type ).join( ', ' )
);

check(
	'a chosen block previews from the server',
	chosen.some( ( el ) => 'ServerSideRender' === el.type.displayName ) &&
		chosen.some( ( el ) => 'InspectorControls' === el.type.displayName ) &&
		! chosen.some( ( el ) => 'Placeholder' === el.type.displayName ),
	'elements: ' + chosen.map( ( el ) => el.type.displayName || el.type ).join( ', ' )
);

// The preview must ask the server for the block it belongs to, and hand it the chosen id.
// Getting either wrong previews a different gallery, or somebody else's block entirely.
const preview = chosen.find( ( el ) => 'ServerSideRender' === el.type.displayName );

check(
	'the preview names its own block and id',
	'lichtbild/gallery' === preview.props.block && 11 === preview.props.attributes.id,
	`block ${ preview.props.block }, id ${ JSON.stringify( preview.props.attributes.id ) }`
);

// The dropdown must carry every gallery plus the empty choice, or a site's galleries are
// missing from the picker while everything else looks correct.
const select = unchosen.find( ( el ) => 'SelectControl' === el.type.displayName );

check(
	'the picker lists every gallery it was given',
	3 === select.props.options.length &&
		0 === select.props.options[ 0 ].value &&
		11 === select.props.options[ 1 ].value &&
		22 === select.props.options[ 2 ].value,
	`${ select.props.options.length } options for 2 galleries plus the empty choice`
);

// A `<select>` hands back a string. Stored unconverted, the block's id would be `"11"` where
// block.json promises a number, and WordPress would re-serialise the post on every save.
let written = null;

edit( { attributes: { id: 0 }, setAttributes: ( attrs ) => { written = attrs; } } );
flatten( edit( { attributes: { id: 0 }, setAttributes: ( attrs ) => { written = attrs; } } ) )
	.find( ( el ) => 'SelectControl' === el.type.displayName )
	.props.onChange( '22' );

check(
	'a chosen id is stored as a number',
	null !== written && 22 === written.id && 'number' === typeof written.id,
	`stored: ${ JSON.stringify( written ) }`
);

// A site with nothing to pick gets a statement, not an empty dropdown that reads as a bug.
const emptyEdit = ( () => {
	const bare = Object.assign( {}, data, { galleries: [] } );
	const box = { window: { wp, LichtbildBlocks: bare }, console };
	box.global = box;

	vm.runInNewContext( source, box, { filename: 'assets/js/blocks.js' } );

	return registered[ 'lichtbild/gallery' ].edit;
} )();

const nothing = flatten( emptyEdit( { attributes: { id: 0 }, setAttributes: () => {} } ) );

check(
	'a site with no galleries says so',
	nothing.some( ( el ) => 'Placeholder' === el.type.displayName ) &&
		! nothing.some( ( el ) => 'SelectControl' === el.type.displayName ),
	'elements: ' + nothing.map( ( el ) => el.type.displayName || el.type ).join( ', ' )
);

// The guard at the top of the script. A `wp` without the packages it needs must return quietly
// rather than throw, because throwing inside the editor's own bundle takes the editor with it.
const guarded = { window: {}, console };
guarded.global = guarded;

let threw = false;

try {
	vm.runInNewContext( source, guarded, { filename: 'assets/js/blocks.js' } );
} catch ( error ) {
	threw = true;
}

check( 'a missing wp is survived, not thrown on', ! threw, threw ? 'it threw' : 'returned quietly' );

console.log( `\nchecks: 12, failing: ${ failures }` );

process.exit( failures > 0 ? 1 : 0 );
