/**
 * Asserts what the front-end script's deep-link pattern accepts.
 *
 *     node tests/frontend-js-test.js
 *
 * WHAT THIS CAN AND CANNOT SEE, STATED FIRST BECAUSE IT DECIDES WHAT THE RESULT MEANS
 * ===================================================================================
 *
 * `lichtbild.js` is a closed IIFE — nothing it defines is reachable from outside, and driving
 * `restoreFromHash()` for real would mean standing up a DOM, a gallery, a history object and a
 * PhotoSwipe import for the sake of one regular expression. So this does two different things,
 * and only one of them is a behavioural test:
 *
 * 1. **The pattern is extracted from the file and executed.** Not a copy of it — the literal is
 *    lifted out of the source and evaluated, so what runs here is the object that runs in the
 *    browser, and every assertion about what it accepts is a real assertion.
 * 2. **The call sites are checked by reading the source**, which is weaker and is the half worth
 *    being honest about: a grep proves a name appears, never that the code path reached at run
 *    time is the one that appears. It is here because the first half has an obvious hole — a
 *    correct pattern that nothing consults would pass every check above it — and closing that
 *    hole cheaply is worth more than leaving it open pending a DOM harness.
 *
 * WHY THE PATTERN IS WORTH TESTING AT ALL
 * =======================================
 *
 * A deep link is the one URL this plugin produces that leaves the site: people paste them into
 * messages and bookmark them. The plugin was renamed, so links carrying the former prefix are
 * out in the world and cannot be recalled, and a fragment that no longer resolves fails in the
 * quietest way available — the page loads, the photograph the link was about is not shown, and
 * nothing anywhere reports an error. No rendering check, no PHP check and no live URL check can
 * see it, because every one of them is satisfied by the page that loads.
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
	console.log( `${ ok ? '[OK]  ' : '[FAIL]' } ${ label.padEnd( 54 ) } ${ detail || '' }` );

	if ( ! ok ) {
		failures++;
	}
}

const file = path.join( __dirname, '..', 'assets', 'js', 'lichtbild.js' );
const source = fs.readFileSync( file, 'utf8' );

// Extracting by pattern means a reformatted declaration stops being found, and that has to be a
// failure rather than a skip: "the regex could not be located" and "the regex is fine" must not
// produce the same exit code. This is the same rule the PHP mutation harness enforces on itself
// — a target that is absent, or present twice, is BROKEN and never a pass.
const declarations = source.match( /^\tvar DEEP_LINK = (\/.+\/);$/m );

if ( ! declarations ) {
	check(
		'the deep-link pattern can be found in the source',
		false,
		'no `var DEEP_LINK = /.../;` line in assets/js/lichtbild.js'
	);

	process.exit( 1 );
}

/** @type {RegExp} The real literal from the shipped file, not a copy of it. */
const pattern = vm.runInNewContext( declarations[ 1 ] );

/**
 * Runs the pattern and returns what it captured.
 *
 * @param {string} hash Fragment including the leading hash.
 *
 * @return {?{gallery:number,image:number}} The ids, or null when it does not match.
 */
function resolve( hash ) {
	const match = pattern.exec( hash );

	return match
		? { gallery: parseInt( match[ 1 ], 10 ), image: parseInt( match[ 2 ], 10 ) }
		: null;
}

const current = resolve( '#lichtbild-1234-i5678' );

check(
	'a current deep link resolves to its gallery and image',
	!! current && 1234 === current.gallery && 5678 === current.image,
	current ? `gallery ${ current.gallery }, image ${ current.image }` : 'did not match'
);

// The reason this file exists. Links written before the plugin was renamed carry the former
// prefix, and they are in other people's messages and bookmarks.
const legacy = resolve( '#tivira-1234-i5678' );

check(
	'a deep link from before the rename resolves identically',
	!! legacy && !! current
		&& legacy.gallery === current.gallery && legacy.image === current.image,
	legacy ? `gallery ${ legacy.gallery }, image ${ legacy.image }` : 'did not match'
);

// The control, and the half that says the check above is a rule rather than a wildcard. A
// pattern loose enough to accept anything would pass every assertion so far.
check(
	'a fragment belonging to something else is not claimed',
	null === resolve( '#gallery-1234-i5678' )
		&& null === resolve( '#envira-1234-i5678' )
		&& null === resolve( '#lichtbild' )
		&& null === resolve( '#comment-1234' ),
	'four foreign fragments, none matched'
);

// The fragment names the image, never its position -- a position means nothing without the
// filter and page it was taken under, neither of which is in the URL. An index-shaped fragment
// must therefore not resolve, or a link built by hand would open a different photograph.
check(
	'a position-shaped fragment is not accepted as an image',
	null === resolve( '#lichtbild-1234-5678' ) && null === resolve( '#lichtbild-1234' ),
	'neither `-N` nor a bare gallery id matched'
);

// Anchored at both ends, so a fragment that merely starts or ends with one is not one.
check(
	'the pattern is anchored at both ends',
	null === resolve( '#lichtbild-12-i34-extra' ) && null === resolve( 'x#lichtbild-12-i34' ),
	'a suffix and a prefix, neither matched'
);

// --- the call sites, which is the source-reading half -------------------------------------
//
// Two methods read `location.hash`: one opens the lightbox from a link that was followed, the
// other clears the hash when it closes. Both have to use the same pattern, and the second is
// the one that quietly gets left behind, because it was a `indexOf( '#lichtbild-' )` string test
// where the first was already a regular expression -- two spellings of one rule is how half a
// rename survives review.
const usesPattern = ( source.match( /DEEP_LINK\.exec\(/g ) || [] ).length;

check(
	'both hash readers use the extracted pattern',
	2 === usesPattern,
	`${ usesPattern } call site(s) of DEEP_LINK.exec()`
);

// And nothing still matches a hash by string prefix, which is what the pattern replaced. This
// is the assertion that would have caught the half-done version of this change.
const literalPrefix = /hash[^\n]*(indexOf|startsWith|===)[^\n]*['"]#/.test( source );

check(
	'no hash is matched by a bare string prefix',
	! literalPrefix,
	literalPrefix ? 'a string comparison against a hash literal survives' : 'none'
);

// Writing is deliberately not bilingual: everything this file emits uses the current prefix, so
// a legacy link is upgraded in the address bar as soon as the lightbox it opened writes its
// own. A second writer emitting the old prefix would keep minting links that need the shim.
const writesLegacy = /return\s+'tivira-/.test( source ) || /'#tivira-/.test( source );

check(
	'nothing writes a fragment using the former prefix',
	! writesLegacy,
	writesLegacy ? 'a legacy prefix is being produced, not just accepted' : 'read-only shim'
);

// The localized bag is named in TWO languages, and neither one errors when they disagree: PHP
// hands the browser an object, JS reads a property that is simply `undefined`, and every lookup
// falls through to ''. That is how `loadFailed` was localized, announced to a live region, and
// empty, for as long as the message existed. So assert the two names against each other rather
// than either one alone.
//
// The PHP side is located from `loadFailed` outwards -- find the string, then the nearest
// enclosing `'<key>' => array(` -- so renaming the key on either side is what goes red, and a
// reformatted PHP array does not quietly stop being found.
const assets = fs.readFileSync(
	path.join( __dirname, '..', 'includes', 'class-lichtbild-assets.php' ),
	'utf8'
);
const loadFailedAt = assets.indexOf( "'loadFailed'" );
const enclosing = loadFailedAt === -1
	? null
	: [ ...assets.slice( 0, loadFailedAt ).matchAll( /'([A-Za-z0-9_]+)'\s*=> array\(/g ) ].pop();
const phpBag = enclosing ? enclosing[ 1 ] : null;
const jsBag = ( source.match( /window\.LichtbildSettings\.([A-Za-z0-9_]+)/ ) || [] )[ 1 ] || null;

check(
	'the localized bag has the same name in PHP and JS',
	phpBag !== null && jsBag !== null && phpBag === jsBag,
	phpBag === jsBag ? `both '${ phpBag }'` : `PHP localizes '${ phpBag }', JS reads '${ jsBag }'`
);

// A rejected promise is a SETTLED promise, so caching one caches the failure. `loadPhotoSwipe()`
// stores the import promise and every click reuses it, and the click handler has already called
// `preventDefault()` by then -- so one transient module-load failure made every later click on
// every image do nothing at all, permanently, with no way back short of a page reload. Nothing
// is logged and nothing is drawn; it is indistinguishable from a dead page.
//
// These three are source assertions, which is the weaker half this file is explicit about: they
// prove the recovery is written, never that the runtime path reaches it. Driving it for real
// needs a DOM, a module loader and a rejected dynamic import, which is the harness this
// repository has decided twice not to build for a static grid.
const loader = ( source.match( /function loadPhotoSwipe\(\)[\s\S]*?\n\t}/ ) || [ '' ] )[ 0 ];

check(
	'a failed module load clears its own cache',
	/\.catch\(/.test( loader ) && /photoSwipePromise = null/.test( loader ),
	loader ? 'catch and reset both present in loadPhotoSwipe()' : 'loadPhotoSwipe() could not be located'
);

const openBody = ( source.match( /Gallery\.prototype\.open = function[\s\S]*?\n\t};/ ) || [ '' ] )[ 0 ];

check(
	'a click that cannot open the lightbox follows the link',
	/\.catch\(/.test( openBody ) && /window\.location\.href/.test( openBody ),
	openBody ? 'open() falls back to the href' : 'open() could not be located'
);

const restoreBody = ( source.match( /Gallery\.prototype\.restoreFromHash = function[\s\S]*?\n\t};/ ) || [ '' ] )[ 0 ];

check(
	'the deep-link path consumes its rejection',
	/\.catch\(/.test( restoreBody ),
	restoreBody ? 'restoreFromHash() handles a rejected import' : 'restoreFromHash() could not be located'
);

console.log( `\nchecks: ${ 12 }, failing: ${ failures }` );

process.exit( failures ? 1 : 0 );
