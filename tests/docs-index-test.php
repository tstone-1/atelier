<?php
/**
 * Asserts AGENTS.md is still an index rather than a corpus.
 *
 *     php tests/docs-index-test.php
 *
 * AGENTS.md is read in full before every task, by every agent, and appending is always the
 * cheapest move — so it only ever grows. It reached 158,707 bytes before it was split, at which
 * point the harness reading it started refusing to load it. The entries did not shrink; they
 * moved, verbatim, into docs/lessons.md and docs/deploys.md, and what stayed behind is one line
 * each. This is the guard on that arrangement, and it exists because the arrangement is a habit
 * rather than a mechanism: nothing else would notice the file drifting back.
 *
 * Two things are checked beyond the size, and the second is the one that would actually catch a
 * mistake made in a hurry:
 *
 * - Every corpus file the index links to exists and is not empty. AGENTS.md is tracked and the
 *   corpus files were not, so committing the index alone would leave a fresh clone with an index
 *   pointing at nothing — a failure that is invisible on the machine where the split was made.
 * - Every entry named in an index line exists as a heading in exactly one corpus file. That is
 *   what stops an entry being summarised into its own hook and deleted: the hook is a claim, and
 *   this asserts the thing it is a claim about is still there to read.
 *
 * The population examined is printed for the same reason the render suite prints it. A check
 * that silently examines nothing is indistinguishable from one that passes, so an index with no
 * entries is reported as a failure rather than a clean run.
 *
 * @package Atelier\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

ini_set( 'display_errors', 'stderr' );

$root = dirname( __DIR__ );

/**
 * The ceiling, in bytes. Not the harness's own limit: the point is to be told while there is
 * still room to think, rather than when the file has already stopped loading. At the time of
 * writing AGENTS.md is a little over 45 KB, so this allows roughly 40% of growth before it asks
 * for another split. Raise it only with an argument for why the next entry belongs in the index.
 */
const AGENTS_MAX_BYTES = 65536;

$agents = $root . '/AGENTS.md';
$text   = file_get_contents( $agents );
$ok     = true;

if ( false === $text ) {
	printf( "  [FAIL] AGENTS.md is unreadable\n" );
	exit( 1 );
}

// ---------------------------------------------------------------------------
// 1. the size
// ---------------------------------------------------------------------------
$bytes = strlen( $text );

printf( "AGENTS.md:           %d bytes of %d (%d%%)\n",
	$bytes, AGENTS_MAX_BYTES, (int) round( $bytes / AGENTS_MAX_BYTES * 100 ) );

if ( $bytes > AGENTS_MAX_BYTES ) {
	printf( "  [FAIL] AGENTS.md is %d bytes over the ceiling; move an entry to docs/ and\n"
		. "         leave one line in its place, rather than raising this\n",
		$bytes - AGENTS_MAX_BYTES );
	$ok = false;
}

// ---------------------------------------------------------------------------
// 2. the corpus files the index points at
// ---------------------------------------------------------------------------
preg_match_all( '#\((docs/[A-Za-z0-9._-]+\.md)\)#', $text, $links );
$corpus = array_values( array_unique( $links[1] ) );

printf( "corpus files linked: %d\n", count( $corpus ) );

if ( empty( $corpus ) ) {
	printf( "  [FAIL] the index links to no corpus file at all\n" );
	$ok = false;
}

$bodies = array();

foreach ( $corpus as $rel ) {
	$path = $root . '/' . $rel;

	if ( ! is_readable( $path ) ) {
		printf( "  [FAIL] %s is linked from AGENTS.md but is not here; a committed index\n"
			. "         pointing at an uncommitted corpus is the whole failure mode\n", $rel );
		$ok = false;
		continue;
	}

	$body = file_get_contents( $path );

	if ( '' === trim( (string) $body ) ) {
		printf( "  [FAIL] %s is empty\n", $rel );
		$ok = false;
		continue;
	}

	$bodies[ $rel ] = $body;
}

// ---------------------------------------------------------------------------
// 3. every index line names an entry that is still there to read
// ---------------------------------------------------------------------------
/**
 * Collects the index lines, reassembling those that wrap.
 *
 * An index line is `- *Title* — hook`, wrapped to the file's own column, so the title itself can
 * straddle a line break. Matching per line finds most of them and silently misses the ones that
 * wrap, which is the same defect the i18n extractor was written to avoid: an extraction that
 * looks complete is worse than one that fails.
 *
 * @param string $text AGENTS.md.
 * @return string[] Entry titles, in the order they appear.
 */
function atelier_index_titles( $text ) {
	$lines  = explode( "\n", $text );
	$blocks = array();
	$cur    = null;

	// `- *Title*` and never `- **Bold**`: the file is full of ordinary bullets opening on a
	// bold run, and matching those reported five entries that were never entries at all.
	foreach ( $lines as $line ) {
		if ( preg_match( '/^\s*- \*(?!\*)/', $line ) ) {
			if ( null !== $cur ) {
				$blocks[] = $cur;
			}
			$cur = $line;
			continue;
		}

		// A continuation line is indented and is not itself a list item.
		if ( null !== $cur && preg_match( '/^\s+\S/', $line ) && ! preg_match( '/^\s*[-*] /', $line ) ) {
			$cur .= ' ' . trim( $line );
			continue;
		}

		if ( null !== $cur ) {
			$blocks[] = $cur;
			$cur      = null;
		}
	}

	if ( null !== $cur ) {
		$blocks[] = $cur;
	}

	$titles = array();

	foreach ( $blocks as $block ) {
		$flat = trim( preg_replace( '/\s+/', ' ', $block ) );

		if ( preg_match( '/^- \*(?!\*)(.+?)\*(?!\*) \x{2014} /u', $flat, $m ) ) {
			$titles[] = $m[1];
		}
	}

	return $titles;
}

$titles = atelier_index_titles( $text );

printf( "index entries:       %d\n", count( $titles ) );

if ( empty( $titles ) ) {
	printf( "  [FAIL] the index names no entries; either the format changed or every entry\n"
		. "         was folded back into this file\n" );
	$ok = false;
}

foreach ( $titles as $title ) {
	$found = 0;

	foreach ( $bodies as $body ) {
		$found += preg_match_all( '/^#{2,6} ' . preg_quote( $title, '/' ) . '\s*$/m', $body );
	}

	if ( 1 !== $found ) {
		printf( "  [FAIL] the index names an entry that is in the corpus %d times (want 1): %s\n",
			$found, $title );
		$ok = false;
	}
}

printf( "\n%s\n", $ok ? 'docs index: intact' : 'docs index: BROKEN' );

exit( $ok ? 0 : 1 );
