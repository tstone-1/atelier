<?php
/**
 * An album: an ordered set of galleries shown as a grid of covers.
 *
 * @package Lichtbild
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wraps one album, in Lichtbild's own shape.
 *
 * Like `Lichtbild_Gallery`, this reads normalised settings and an ordered item list and knows
 * nothing about Envira. `Lichtbild_Album_Config` is where the translation happens, so an album read
 * on a migrated site involves no Envira knowledge at all — which is what makes the migration
 * meaningful for albums rather than a rename of their rows.
 *
 * The items are an ordered **list**, not a map keyed by gallery ID. Envira keys by gallery, which
 * makes order an accident of array-key order and makes the same gallery impossible to list twice;
 * the gallery side moved off that shape for the same two reasons.
 */
class Lichtbild_Album {

	/**
	 * Post ID of the album.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Normalised settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Ordered items, each `{id, cover_id, caption}`.
	 *
	 * @var array<int,array>
	 */
	private $items;

	/**
	 * Builds an album.
	 *
	 * @param int              $id       Album post ID.
	 * @param array            $settings Normalised settings.
	 * @param array<int,array> $items    Ordered items.
	 */
	public function __construct( $id, array $settings, array $items ) {
		$this->id       = (int) $id;
		$this->settings = Lichtbild_Album_Config::fill( $settings );
		$this->items    = array_values( $items );
	}

	/**
	 * Returns the album post ID.
	 *
	 * @return int Post ID.
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * Returns the album title.
	 *
	 * The post's own title, and never a copy stored in the record. See `Lichtbild_Album_Config`
	 * for why the stored one was dropped rather than merely left unread: it was an override,
	 * so a rename of the album post would have had no effect on anything that called this.
	 *
	 * @return string Title text.
	 */
	public function title() {
		return (string) get_the_title( $this->id );
	}

	/**
	 * Returns the gallery IDs in album order.
	 *
	 * @return int[] Gallery post IDs.
	 */
	public function gallery_ids() {
		return array_map( 'intval', wp_list_pluck( $this->items, 'id' ) );
	}

	/**
	 * Returns the number of galleries in the album.
	 *
	 * @return int Gallery count.
	 */
	public function count() {
		return count( $this->items );
	}

	/**
	 * Returns the number of columns used for the cover grid.
	 *
	 * @return int Column count, at least one.
	 */
	public function columns() {
		return (int) $this->settings['columns'];
	}

	/**
	 * Reports whether member titles are shown under their covers.
	 *
	 * @return bool True when titles are shown.
	 */
	public function has_titles() {
		return (bool) $this->settings['show_titles'];
	}

	/**
	 * Reports whether the image count is shown under each cover.
	 *
	 * @return bool True when counts are shown.
	 */
	public function has_counts() {
		return (bool) $this->settings['show_counts'];
	}

	/**
	 * Returns the whole normalised settings array.
	 *
	 * @return array Settings.
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Returns the ordered items.
	 *
	 * @return array<int,array> Items.
	 */
	public function items() {
		return $this->items;
	}

}
