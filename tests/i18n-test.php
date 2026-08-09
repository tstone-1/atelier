<?php
/**
 * Asserts every translatable string in the plugin has a German translation.
 *
 *     php tests/i18n-test.php
 *
 * A translation catalogue rots the moment someone adds a string and does not translate it, and
 * nothing about that is visible: the untranslated string simply renders in English next to
 * German ones. This is the guard, and it deliberately needs no gettext binaries so it can run
 * anywhere the rest of the suite does.
 *
 * Strings are extracted with PHP's own tokenizer rather than a regular expression. That is not
 * fastidiousness — the catalogue holds 32 strings that span several source lines, and a
 * line-oriented regex silently drops every one of them. Getting that wrong the first time is
 * what suggested writing this at all: the first extraction pass reported 153 of 180 strings and
 * looked complete.
 *
 * @package Atelier\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

ini_set( 'display_errors', 'stderr' );

$root = dirname( __DIR__ );

/** Functions whose first argument is a translatable string. */
$single = array( '__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e' );

/** Functions whose first two arguments are the singular and the plural. */
$plural = array( '_n', '_nx' );

/** Functions whose first argument is the string and second is a context. */
$context = array( '_x', 'esc_html_x', 'esc_attr_x' );

/**
 * Extracts every translatable literal from one PHP file.
 *
 * Only literal single- or double-quoted strings are collected. A call whose argument is a
 * variable or a concatenation cannot be extracted by any tool, including `xgettext`, and is
 * reported separately rather than silently skipped — an unextractable string is untranslatable,
 * which is a defect in the source rather than in this file.
 *
 * @param string $path      File to read.
 * @param array  $single    Function names taking one string.
 * @param array  $plural    Function names taking singular and plural.
 * @param array  $context   Function names taking a string and a context.
 * @param array  $dynamic   Collects `file:line` for calls whose argument is not a literal.
 *
 * @return string[] The literals found, in source order.
 */
function atelier_extract( $path, array $single, array $plural, array $context, array &$dynamic ) {
	$tokens = token_get_all( (string) file_get_contents( $path ) );
	$found  = array();
	$names  = array_merge( $single, $plural, $context );
	$count  = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( ! is_array( $token ) || T_STRING !== $token[0] || ! in_array( $token[1], $names, true ) ) {
			continue;
		}

		// A method or property of the same name is not a translation call.
		for ( $back = $i - 1; $back >= 0; $back-- ) {
			if ( is_array( $tokens[ $back ] ) && T_WHITESPACE === $tokens[ $back ][0] ) {
				continue;
			}

			break;
		}

		if ( $back >= 0 && is_array( $tokens[ $back ] ) &&
			in_array( $tokens[ $back ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}

		// How many leading arguments of this call are translatable strings.
		$wanted = in_array( $token[1], $plural, true ) ? 2 : 1;
		$taken  = 0;

		for ( $j = $i + 1; $j < $count && $taken < $wanted; $j++ ) {
			$next = $tokens[ $j ];

			if ( is_array( $next ) && T_WHITESPACE === $next[0] ) {
				continue;
			}

			if ( '(' === $next || ',' === $next ) {
				continue;
			}

			if ( is_array( $next ) && T_CONSTANT_ENCAPSED_STRING === $next[0] ) {
				$raw = $next[1];
				// Undo PHP's own quoting to get the value gettext would see.
				$found[] = "'" === $raw[0]
					? str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), substr( $raw, 1, -1 ) )
					: stripcslashes( substr( $raw, 1, -1 ) );
				$taken++;

				continue;
			}

			$dynamic[] = basename( $path ) . ':' . $token[2] . ' (' . $token[1] . ')';

			break;
		}
	}

	return $found;
}

/**
 * Returns every msgid in a PO or POT file, including the multi-line ones.
 *
 * @param string $path Catalogue to read.
 *
 * @return array<string,bool> Msgids as keys.
 */
function atelier_msgids( $path ) {
	$ids     = array();
	$buffer  = '';
	$reading = false;

	foreach ( file( $path ) as $line ) {
		$line = rtrim( $line, "\r\n" );

		if ( 0 === strpos( $line, 'msgid_plural ' ) || 0 === strpos( $line, 'msgid ' ) ) {
			if ( $reading && '' !== $buffer ) {
				$ids[ $buffer ] = true;
			}

			$buffer  = '';
			$reading = true;
			$line    = substr( $line, strpos( $line, ' ' ) + 1 );
		} elseif ( 0 === strpos( $line, 'msgstr' ) ) {
			if ( $reading && '' !== $buffer ) {
				$ids[ $buffer ] = true;
			}

			$buffer  = '';
			$reading = false;

			continue;
		} elseif ( ! $reading ) {
			continue;
		}

		if ( preg_match( '/^\s*"(.*)"\s*$/', $line, $match ) ) {
			$buffer .= stripcslashes( $match[1] );
		}
	}

	if ( $reading && '' !== $buffer ) {
		$ids[ $buffer ] = true;
	}

	return $ids;
}

/**
 * Returns msgids whose translation is empty, and those marked fuzzy.
 *
 * A fuzzy entry is one gettext guessed at; WordPress ignores it entirely, so it renders in
 * English exactly as an empty one does and must be treated the same way.
 *
 * @param string $path PO file to read.
 *
 * @return array{empty:string[],fuzzy:string[]} The two lists.
 */
function atelier_untranslated( $path ) {
	$body   = (string) file_get_contents( $path );
	$blocks = explode( "\n\n", $body );
	$empty  = array();
	$fuzzy  = array();

	foreach ( $blocks as $block ) {
		if ( false === strpos( $block, 'msgid ' ) ) {
			continue;
		}

		if ( preg_match( '/^msgid ""\s*$/m', $block ) && false === strpos( $block, 'msgid "' ) ) {
			continue; // the header
		}

		$ids = array_keys( atelier_msgids_from_block( $block ) );

		if ( empty( $ids ) ) {
			continue;
		}

		if ( preg_match( '/^#,.*\bfuzzy\b/m', $block ) ) {
			$fuzzy[] = $ids[0];
		}

		if ( preg_match( '/^msgstr(\[\d+\])? ""\s*$/m', $block ) && ! preg_match( '/^msgstr(\[\d+\])? ""\s*\n"/m', $block ) ) {
			$empty[] = $ids[0];
		}
	}

	return array(
		'empty' => $empty,
		'fuzzy' => $fuzzy,
	);
}

/**
 * The msgid extractor above, applied to a single block.
 *
 * @param string $block One PO entry.
 *
 * @return array<string,bool> Msgids as keys.
 */
function atelier_msgids_from_block( $block ) {
	$path = tempnam( sys_get_temp_dir(), 'atelier-po' );
	file_put_contents( $path, $block . "\n\n" );
	$ids = atelier_msgids( $path );
	unlink( $path );

	return $ids;
}

/**
 * Returns the strings WordPress translates out of one block's metadata.
 *
 * These reach a user without ever appearing in a `__()` call: `register_block_type()` runs the
 * metadata through `translate_settings_using_i18n_schema()`, which applies `_x()` with the
 * contexts core's own `block-i18n.json` names — `block title`, `block description`,
 * `block keyword`. So a `block.json` is a **source file for translation**, and an extractor
 * that reads only PHP reports every one of these as an orphan sitting in the catalogue, which
 * is the tell of a catalogue nobody regenerated rather than of strings nobody needs.
 *
 * Translation happens only when the metadata declares a `textdomain`. Without one, core skips
 * the whole schema silently, so the missing key is reported here as a failure rather than left
 * to be discovered by a German editor reading "Atelier Gallery" in the inserter.
 *
 * @param string $path     Path to a `block.json`.
 * @param array  $problems Collects a description of anything that makes the file untranslatable.
 *
 * @return string[] The translatable literals.
 */
function atelier_block_strings( $path, array &$problems ) {
	$meta = json_decode( (string) file_get_contents( $path ), true );

	if ( ! is_array( $meta ) ) {
		$problems[] = basename( dirname( $path ) ) . '/block.json is not valid JSON';

		return array();
	}

	if ( empty( $meta['textdomain'] ) ) {
		$problems[] = basename( dirname( $path ) ) . '/block.json declares no textdomain, so none of it is translated';
	}

	$out = array();

	foreach ( array( 'title', 'description' ) as $key ) {
		if ( ! empty( $meta[ $key ] ) ) {
			$out[] = (string) $meta[ $key ];
		}
	}

	foreach ( (array) ( $meta['keywords'] ?? array() ) as $keyword ) {
		$out[] = (string) $keyword;
	}

	return $out;
}

/**
 * Returns the plugin-header fields WordPress runs through the textdomain.
 *
 * `_get_plugin_data_markup_translate()` translates the header WordPress shows on the Plugins
 * screen, so those lines are translatable source too — and they live in a **comment**, which is
 * the one place a tokenizer walking `__()` calls structurally cannot look. Left out, all four
 * sat in the catalogue and were reported as orphans "no longer in the source", which is both
 * false and the kind of permanent warning that teaches people to stop reading warnings.
 *
 * The field list matches what `wp i18n make-pot` extracts, because the catalogue is generated
 * with it and a check that disagreed with the generator would fail on every regeneration.
 *
 * @param string $path Plugin bootstrap file.
 *
 * @return string[] The header values, in file order.
 */
function atelier_header_strings( $path ) {
	$head   = substr( (string) file_get_contents( $path ), 0, 8192 );
	$wanted = array( 'Plugin Name', 'Plugin URI', 'Description', 'Author' );
	$out    = array();

	foreach ( $wanted as $field ) {
		if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':(.*)$/mi', $head, $match ) ) {
			$value = trim( $match[1] );

			if ( '' !== $value ) {
				$out[] = $value;
			}
		}
	}

	return $out;
}

$files = array_merge( glob( $root . '/includes/*.php' ), array( $root . '/atelier.php' ) );
sort( $files );

$dynamic = array();
$source  = array();

foreach ( $files as $file ) {
	foreach ( atelier_extract( $file, $single, $plural, $context, $dynamic ) as $string ) {
		$source[ $string ] = true;
	}
}

$header = atelier_header_strings( $root . '/atelier.php' );

foreach ( $header as $string ) {
	$source[ $string ] = true;
}

// Stated rather than assumed: the four fields are read out of a comment by a regular
// expression, so "found nothing" and "the header has no Description" look identical, and an
// empty result would quietly turn all four into orphans again.
printf( "plugin header fields: %d\n", count( $header ) );

$blocks   = glob( $root . '/blocks/*/block.json' );
$problems = array();

sort( $blocks );

foreach ( $blocks as $block ) {
	foreach ( atelier_block_strings( $block, $problems ) as $string ) {
		$source[ $string ] = true;
	}
}

printf( "block metadata files: %d\n", count( $blocks ) );

$po  = $root . '/languages/atelier-de_DE.po';
$pot = $root . '/languages/atelier.pot';
$ok  = true;

printf( "source strings:      %d\n", count( $source ) );

foreach ( array( 'atelier.pot' => $pot, 'atelier-de_DE.po' => $po ) as $label => $path ) {
	if ( ! is_readable( $path ) ) {
		printf( "[FAIL] %s is missing\n", $label );
		$ok = false;

		continue;
	}

	$catalogue = atelier_msgids( $path );
	$missing   = array_diff_key( $source, $catalogue );
	$orphaned  = array_diff_key( $catalogue, $source );

	printf( "%-20s %d strings, %d missing, %d orphaned\n", $label . ':', count( $catalogue ), count( $missing ), count( $orphaned ) );

	foreach ( $missing as $string => $unused ) {
		printf( "  [FAIL] not in %s: %s\n", $label, substr( $string, 0, 70 ) );
		$ok = false;
	}

	// An orphan is a string deleted from the source but left in the catalogue. Harmless at
	// runtime and reported anyway: it is the tell that the catalogue was not regenerated, which
	// is also how a *missing* one gets in.
	foreach ( $orphaned as $string => $unused ) {
		printf( "  [WARN] no longer in the source: %s\n", substr( $string, 0, 70 ) );
	}
}

$gaps = atelier_untranslated( $po );

printf( "untranslated:        %d\nfuzzy:               %d\n", count( $gaps['empty'] ), count( $gaps['fuzzy'] ) );

foreach ( array_merge( $gaps['empty'], $gaps['fuzzy'] ) as $string ) {
	printf( "  [FAIL] renders in English: %s\n", substr( $string, 0, 70 ) );
	$ok = false;
}

// A call whose argument is a variable cannot be extracted by any tool, so it can never be
// translated. Reported as a failure rather than a note: it is a defect in the source.
if ( ! empty( $dynamic ) ) {
	printf( "non-literal calls:   %d\n", count( $dynamic ) );

	foreach ( $dynamic as $where ) {
		printf( "  [FAIL] not a literal, so untranslatable: %s\n", $where );
		$ok = false;
	}
}

foreach ( $problems as $problem ) {
	printf( "  [FAIL] %s\n", $problem );
	$ok = false;
}

// The compiled catalogue is what WordPress actually reads. A .po with no .mo beside it is a
// translation nobody sees, which is indistinguishable from no translation at all.
if ( ! is_readable( $root . '/languages/atelier-de_DE.mo' ) ) {
	printf( "  [FAIL] atelier-de_DE.mo is missing; the .po alone is never read\n" );
	$ok = false;
}

printf( "\n%s\n", $ok ? 'i18n: complete' : 'i18n: INCOMPLETE' );

exit( $ok ? 0 : 1 );
