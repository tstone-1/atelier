<?php
/**
 * The normalised album settings schema, and the translation into it from Envira's.
 *
 * @package Lichtbild
 */

defined( 'ABSPATH' ) || exit;

/**
 * Defines what an album's settings are, and converts Envira's version of them.
 *
 * The gallery twin of this class, `Lichtbild_Config`, exists because Envira writes ~281 keys per
 * gallery and twenty-six of them matter. Albums are the same story at a smaller scale: 170 keys
 * on each of this site's two real albums, of which **twelve vary at all** and three decide what a
 * visitor sees.
 *
 * Albums went without this for a whole release, and the gap was not obvious from either side.
 * The migration renamed album *rows* while `Lichtbild_Repository::build_album()` went on reading
 * `_eg_album_data` whatever the schema said — so a "migrated" site still had its albums in
 * Envira's format, and an album editor written against that would have written Envira's format
 * back. Galleries own their data after the migration; albums did not, and nothing said so.
 *
 * Two conversions are deliberately *not* faithful, because Envira's own record is wrong about
 * them:
 *
 * - **The cover is `cover_image_id` and nothing else.** The v1 reader used to fall back through
 *   `cover`, `thumb_id` and finally `id` — but `id` in an album entry is the **gallery's** ID,
 *   not an attachment's. That fallback could only ever produce a wrong cover.
 *   It was masked because every real entry sets `cover_image_id`, and because a gallery ID
 *   handed to `wp_get_attachment_image_src()` returns false, which drops through to the first
 *   image anyway. Right answer, wrong reasoning, and it would have been copied into the record.
 * - **Both frozen titles are dropped — the members' and the album's own.** Envira stores a copy
 *   of each member gallery's title inside the album, and stores the album's title in its config
 *   as well. In both cases the copy is frozen at the moment it was written, and in both cases
 *   WordPress already holds the live value in `wp_posts`. The renderer has always used the
 *   member gallery's own title; `Lichtbild_Album::title()` now uses the album post's own for the
 *   same reason.
 *
 *   The album's own title is the one that was nearly kept, because unlike the members' it was
 *   in the schema rather than merely in Envira's record — and keeping it would have been worse
 *   than harmless. Nothing read it, so it could not be seen to be wrong; but it was written as
 *   an *override*, so the day a theme called `title()` a rename of the album post would have
 *   silently had no effect. A stored value nothing reads is not neutral when the code that
 *   would read it prefers it to the truth. The album editor is what forced the question: a
 *   title field there would have been a second place to edit one title.
 */
class Lichtbild_Album_Config {

	/**
	 * Schema version written into converted records.
	 */
	const VERSION = 2;

	/**
	 * Returns the settings an album has when nothing says otherwise.
	 *
	 * Each default matches Envira's own, so an album saved by a version predating a key renders
	 * the way it does today rather than changing under the reader.
	 *
	 * @return array Normalised settings.
	 */
	public static function defaults() {
		return array(
			'columns'     => 3,
			'show_titles' => true,
			'show_counts' => true,
		);
	}

	/**
	 * Converts one Envira album config array into normalised settings.
	 *
	 * @param array $envira Envira's album config array.
	 *
	 * @return array Normalised settings.
	 */
	public static function from_envira( array $envira ) {
		$settings = self::defaults();

		$columns             = isset( $envira['columns'] ) && is_numeric( $envira['columns'] )
			? (int) $envira['columns']
			: 0;
		$settings['columns'] = $columns > 0 ? $columns : 3;

		// `display_titles` is a *position* in Envira — `below`, `above`, or `0` for off — and
		// Lichtbild renders the title in one place, so only on-or-off survives the conversion.
		// Anything non-empty and not the string "0" means on.
		$position                = isset( $envira['display_titles'] ) ? (string) $envira['display_titles'] : 'below';
		$settings['show_titles'] = ( '' !== $position && '0' !== $position );

		$settings['show_counts'] = self::flag( $envira, 'display_image_count', true );

		return $settings;
	}

	/**
	 * Converts one Envira album entry into a normalised item.
	 *
	 * @param int   $gallery_id Gallery post ID, which is the map key in Envira's record.
	 * @param array $entry      Envira's per-gallery entry.
	 *
	 * @return array{id:int,cover_id:int,caption:string} Normalised item.
	 */
	public static function item_from_envira( $gallery_id, array $entry ) {
		$cover = 0;

		// `cover_image_id` only. See the class docblock for why the old fallback chain ending
		// in `id` was not merely redundant but wrong.
		if ( ! empty( $entry['cover_image_id'] ) && is_numeric( $entry['cover_image_id'] ) ) {
			$cover = (int) $entry['cover_image_id'];
		}

		return array(
			'id'       => (int) $gallery_id,
			'cover_id' => $cover,
			'caption'  => isset( $entry['caption'] ) ? (string) $entry['caption'] : '',
		);
	}

	/**
	 * Fills a stored settings array out to the full schema.
	 *
	 * For records read back from the database: a key absent because the version that wrote the
	 * record predated it should take the default.
	 *
	 * @param array $settings Stored settings.
	 *
	 * @return array Settings with every key present.
	 */
	public static function fill( array $settings ) {
		$out = self::defaults();

		foreach ( $out as $key => $default ) {
			if ( array_key_exists( $key, $settings ) ) {
				$out[ $key ] = is_bool( $default ) ? (bool) $settings[ $key ] : $settings[ $key ];
			}
		}

		$out['columns'] = max( 1, min( 12, (int) $out['columns'] ) );

		return $out;
	}

	/**
	 * Sanitises a submitted settings form into the schema.
	 *
	 * **This is not `fill()` with sanitising bolted on**, and the difference is the same one the
	 * gallery schema records: an absent key means opposite things to the two. A *stored* record
	 * is missing one because the version that wrote it predated it, so the default is right. A
	 * *submitted form* is missing one because an unchecked checkbox sends nothing at all, so a
	 * default of `true` would make that box impossible to switch off.
	 *
	 * @param array $input Raw submitted values.
	 *
	 * @return array Normalised settings.
	 */
	public static function sanitize( array $input ) {
		return array(
			'columns'     => max( 1, min( 12, isset( $input['columns'] ) ? (int) $input['columns'] : 3 ) ),
			'show_titles' => ! empty( $input['show_titles'] ),
			'show_counts' => ! empty( $input['show_counts'] ),
		);
	}

	/**
	 * Reads a boolean out of Envira's config, whatever it decided to serialise it as.
	 *
	 * Envira spells true as `1`, `'1'`, `true` and `'True'` in different places and different
	 * versions, so a direct comparison is wrong somewhere. Kept separate from `Lichtbild_Config`'s
	 * copy rather than shared: they read different records, and coupling the album schema to the
	 * gallery one for four lines would mean a change for galleries could not be made without
	 * reasoning about albums.
	 *
	 * Decoupled is not the same as free to drift, and this had. A non-scalar value — an array
	 * left by a hand-edit or an older writer — took the final `(string)` cast, which is an
	 * "Array to string conversion" warning and then a flat `false`, whatever the caller asked
	 * for as a default. The gallery twin returns the default there, and that is the right
	 * answer: an unreadable value says nothing about the setting, so the setting keeps the
	 * value it would have had if the key were absent.
	 *
	 * @param array  $config  Envira config array.
	 * @param string $key     Key to read.
	 * @param bool   $default Value when the key is absent.
	 *
	 * @return bool The flag.
	 */
	private static function flag( array $config, $key, $default = false ) {
		if ( ! array_key_exists( $key, $config ) ) {
			return $default;
		}

		$value = $config[ $key ];

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value > 0;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return $default;
	}
}
