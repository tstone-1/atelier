<?php
/**
 * Builds a synthetic render fixture carrying the same shapes as the live site's.
 *
 *     php tests/make-fixture.php [path/to/output.json]
 *
 * Writes tests/fixture-synthetic.json by default, in exactly the format
 * tests/export-fixture.py produces, so the suite takes it as an ordinary argument:
 *
 *     php tests/render-test.php tests/fixture-synthetic.json
 *
 * Why this exists: the real fixture is the site's own content and is gitignored, so every
 * claim in this repo rested on a file that existed on one laptop. There was no CI, and a
 * fresh clone could not run a single check. This file is committed, so it can.
 *
 * It is deliberately NOT a replacement. The real fixture is 52 galleries of photographs taken
 * over years, with the value distribution that produced most of the traps recorded in
 * AGENTS.md — four spellings of boolean true, a per-gallery translated label, per-field EXIF
 * toggles that disagree with each other. Nobody would have invented those. What this carries
 * is the same *shapes*, chosen from what the real corpus was measured to contain, so that the
 * suite exercises the same code paths anywhere.
 *
 * Three properties are load-bearing and none is decoration:
 *
 * - **It is deterministic.** No randomness, no clock, no ordering that depends on a hash
 *   seed. A fixture that differs between runs turns every equivalence check in the suite into
 *   a coin toss, and the failure would read as a defect in the code.
 * - **Every gallery exists to carry a named shape**, and the table below says which. A corpus
 *   assembled by inventing plausible data covers whatever its author happened to think of.
 * - **Galleries that are album members hold disjoint attachments.** The album editor checks
 *   assert that a cover belonging to another gallery is refused, and they pick the "foreign"
 *   cover from a sibling member — so two members sharing one photograph makes a correct
 *   refusal look like a bug. Found by that check going red, not by reading it.
 *
 * The shapes, one row per gallery:
 *
 *  | ID  | what it is here for                                                          |
 *  |-----|------------------------------------------------------------------------------|
 *  | 100 | justified + pagination + EXIF whose per-field toggles disagree; flags as '1'  |
 *  | 101 | tag filter on, with a site-owner-translated all-label; flags as 'True'        |
 *  | 102 | fixed columns rather than justified; flags as int 1                           |
 *  | 103 | captions, alt text and hand-written custom CSS; flags as bool true            |
 *  | 104 | a pending item, and one whose attachment has been deleted                     |
 *  | 105 | password-protected                                                            |
 *  | 106 | private                                                                       |
 *  | 107 | draft                                                                         |
 *  | 108 | draft, because the live site has two and one row is not a distribution        |
 *  | 109 | Envira's own stored defaults, which the reader skips                          |
 *
 *  | ID  | album                                                                        |
 *  |-----|------------------------------------------------------------------------------|
 *  | 200 | three members, titles and counts shown                                        |
 *  | 201 | two members, one listed twice, titles and counts switched off                 |
 *  | 202 | Envira's stored album defaults, in the older member-less shape                |
 *
 * One shape here exists in no real gallery, and it is the most valuable row in the file: an
 * item whose attachment has been deleted from the media library. All 2,264 live items resolve,
 * so the renderer's rule for one that cannot be measured — keep it in the grid as a plain link,
 * keep it out of the lightbox rather than hand PhotoSwipe a 0x0 slide to divide by — was
 * asserted only against a hand-built item and was **vacuously true** on every real gallery.
 *
 * Adding it turned four per-item checks red while the renderer behaved exactly as designed,
 * because those checks asserted a srcset, an intrinsic size, lightbox dimensions and a
 * non-original grid src for every item: properties of THAT corpus rather than of the renderer.
 * They are now guarded on the item being measurable, and the rule they used to imply is stated
 * as a per-gallery equality that this row is the only thing anywhere making non-vacuous.
 *
 * The transferable half: **a corpus that cannot construct a state cannot tell you what your
 * checks assume about it.** The four had been green for months and the assumption was
 * invisible, and it would have surfaced as four failures the day one photograph was deleted
 * from the live site.
 *
 * Serialized blobs are base64-encoded exactly as the exporter does, because the loader
 * base64-decodes and unserializes without caring where the bytes came from. Producing them
 * with PHP's own serialize() rather than a Python library is deliberate: it is the same
 * function that will read them back, so no length-prefix disagreement is possible.
 */

/**
 * Returns the intermediate sizes WordPress would generate for one original.
 *
 * Mirrors core's behaviour closely enough for the renderer to be exercised: `thumbnail` is
 * cropped square, and the others are bounded by their width and keep the aspect ratio. The
 * srcset stub drops any size whose aspect disagrees with the original, so the cropped
 * thumbnail being present *and* excluded is itself one of the shapes worth carrying.
 *
 * @param int    $width  Original width in pixels.
 * @param int    $height Original height in pixels.
 * @param string $stem   File name without its extension.
 *
 * @return array<string,array> Size entries keyed by registered size name.
 */
function atelier_fixture_sizes( $width, $height, $stem ) {
	$sizes = array( 'thumbnail' => array( 150, 150 ) );

	foreach ( array(
		'medium'       => 300,
		'medium_large' => 768,
		'large'        => 1024,
	) as $name => $bound ) {
		if ( $width <= $bound ) {
			continue;
		}

		$sizes[ $name ] = array( $bound, (int) round( $height * ( $bound / $width ) ) );
	}

	$entries = array();

	foreach ( $sizes as $name => $pair ) {
		$entries[ $name ] = array(
			'file'      => sprintf( '%s-%dx%d.jpg', $stem, $pair[0], $pair[1] ),
			'width'     => $pair[0],
			'height'    => $pair[1],
			'mime-type' => 'image/jpeg',
		);
	}

	return $entries;
}

/**
 * Returns a deterministic width and height for one attachment.
 *
 * Aspect ratios are varied on purpose. The justified grid sizes every item in proportion to
 * its own ratio, so a corpus of identically-shaped images would lay out correctly under a
 * renderer that ignored the ratio entirely. The cycle is fixed rather than random so that two
 * runs of this script produce the same bytes.
 *
 * @param int $index Position of the attachment within its gallery.
 *
 * @return int[] Width and height in pixels.
 */
function atelier_fixture_shape( $index ) {
	$shapes = array(
		array( 2000, 1333 ),
		array( 1600, 1600 ),
		array( 1200, 1800 ),
		array( 2400, 1350 ),
		array( 1800, 1200 ),
		array( 1000, 1500 ),
		array( 2048, 1152 ),
		array( 1500, 1000 ),
		array( 1920, 1280 ),
		array( 1365, 2048 ),
		array( 2560, 1440 ),
		array( 1400, 933 ),
	);

	return $shapes[ $index % count( $shapes ) ];
}

/**
 * Builds one attachment record in the exporter's shape.
 *
 * Every attachment carries a `title`, because WordPress sets one from the file name at upload
 * and all 2,243 on the live site have one. Leaving it blank is not a harmless simplification:
 * the renderer falls back to it for the `alt` attribute, so a corpus of untitled attachments
 * turns `image has alt attribute` red on every item. Found that way.
 *
 * @param int    $id      Attachment ID.
 * @param int    $width   Original width in pixels.
 * @param int    $height  Original height in pixels.
 * @param array  $options Optional excerpt, alt, tags and image_meta overrides.
 *
 * @return array The record, with its metadata already base64-encoded.
 */
function atelier_fixture_attachment( $id, $width, $height, array $options = array() ) {
	$stem = 'image-' . $id;

	$image_meta = array_merge(
		array(
			'aperture'          => '0',
			'credit'            => '',
			'camera'            => '',
			'caption'           => '',
			'created_timestamp' => '0',
			'copyright'         => '',
			'focal_length'      => '0',
			'iso'               => '0',
			'shutter_speed'     => '0',
			'title'             => '',
			'orientation'       => '1',
			'keywords'          => array(),
		),
		isset( $options['image_meta'] ) ? $options['image_meta'] : array()
	);

	$sizes = atelier_fixture_sizes( $width, $height, $stem );

	// One attachment on the live site has no `medium_large` entry despite being wide enough
	// for one — an upload predating the size, which WordPress never backfills. It matters
	// because a size that is absent falls back to the full-size original, so a gallery asking
	// for it silently serves the 239 KB file this plugin exists to stop serving.
	foreach ( (array) ( isset( $options['omit_sizes'] ) ? $options['omit_sizes'] : array() ) as $omitted ) {
		unset( $sizes[ $omitted ] );
	}

	$record = array(
		'title'   => isset( $options['title'] ) ? $options['title'] : 'Aufnahme ' . $id,
		'excerpt' => isset( $options['excerpt'] ) ? $options['excerpt'] : '',
		'meta'    => base64_encode(
			serialize(
				array(
					'width'      => $width,
					'height'     => $height,
					'file'       => '2019/07/' . $stem . '.jpg',
					'sizes'      => $sizes,
					'image_meta' => $image_meta,
				)
			)
		),
	);

	if ( isset( $options['alt'] ) ) {
		$record['alt'] = $options['alt'];
	}

	if ( ! empty( $options['tags'] ) ) {
		$record['tags'] = $options['tags'];
	}

	return $record;
}

/**
 * Builds one gallery item in Envira's own item shape.
 *
 * `src` and `link` are Envira's frozen copy of the full-size URL from when the image was
 * added, which is what the reader falls back to when an attachment has been deleted. They are
 * therefore database strings rather than URLs, which is why the reader runs both through a
 * scheme allowlist rather than trusting them.
 *
 * @param int   $attachment_id Attachment ID the item names.
 * @param array $options       Optional status, title, caption and alt.
 *
 * @return array The item.
 */
function atelier_fixture_item( $attachment_id, array $options = array() ) {
	$url = 'https://example.com/wp-content/uploads/2019/07/image-' . $attachment_id . '.jpg';

	return array(
		'status'       => isset( $options['status'] ) ? $options['status'] : 'active',
		'src'          => $url,
		'title'        => isset( $options['title'] ) ? $options['title'] : '',
		'link'         => $url,
		'alt'          => isset( $options['alt'] ) ? $options['alt'] : '',
		'caption'      => isset( $options['caption'] ) ? $options['caption'] : '',
		'thumb'        => '',
		'mobile_thumb' => '',
	);
}

/**
 * Returns Envira's gallery config with the keys the converter reads.
 *
 * Envira writes ~281 keys per gallery, nearly all of them defaults its settings screen
 * emitted, and the converter reads about forty. Carrying only those is deliberate: a key
 * nothing reads adds bytes and hides which ones matter, and the converter is separately proved
 * to default a key it does not find.
 *
 * @param array $overrides Keys to set on top of the defaults.
 *
 * @return array The config.
 */
function atelier_fixture_config( array $overrides = array() ) {
	return array_merge(
		array(
			'type'                                   => 'default',
			'title'                                  => '',
			'slug'                                   => '',
			'columns'                                => '0',
			'gutter'                                 => '10',
			'margin'                                 => '10',
			'justified_row_height'                   => '150',
			'isotope'                                => '1',
			'image_size'                             => 'default',
			'lightbox_image_size'                    => 'default',
			'title_display'                          => 'none',
			'lazy_loading'                           => '1',
			'pagination'                             => '0',
			'pagination_images_per_page'             => '0',
			'pagination_scroll'                      => '1',
			'pagination_lightbox_display_all_images' => '1',
			'lightbox_theme'                         => 'base_dark',
			'keyboard'                               => '1',
			'protection'                             => '0',
			'exif'                                   => '0',
			'exif_lightbox'                          => '0',
			'social'                                 => '0',
			'social_lightbox'                        => '0',
			'download_lightbox'                      => '0',
			'tags'                                   => '0',
			'tags_filter'                            => '0',
			'tags_position'                          => 'below',
			'tags_all_enabled'                       => '1',
			'tags_all'                               => '',
			'custom_css'                             => '',
		),
		$overrides
	);
}

/**
 * Builds one gallery record in the exporter's shape.
 *
 * @param int    $id      Gallery post ID.
 * @param string $title   Post title.
 * @param array  $items   Items keyed by attachment ID.
 * @param array  $config  Envira config.
 * @param array  $options Optional status, name and protected flag.
 *
 * @return array The record.
 */
function atelier_fixture_gallery( $id, $title, array $items, array $config, array $options = array() ) {
	return array(
		'id'        => $id,
		'title'     => $title,
		'status'    => isset( $options['status'] ) ? $options['status'] : 'publish',
		'name'      => isset( $options['name'] ) ? $options['name'] : 'gallery-' . $id,
		'protected' => ! empty( $options['protected'] ),
		'meta'      => base64_encode(
			serialize(
				array(
					'id'      => $id,
					'config'  => $config,
					'gallery' => $items,
				)
			)
		),
	);
}

/**
 * Builds one album member entry in Envira's own shape.
 *
 * @param int   $gallery_id Member gallery ID.
 * @param int   $cover_id   Attachment ID of the cover, or 0 for none.
 * @param array $options    Optional frozen title and caption.
 *
 * @return array The member entry.
 */
function atelier_fixture_member( $gallery_id, $cover_id, array $options = array() ) {
	return array(
		'id'                    => (string) $gallery_id,
		// Envira freezes a copy of the member's title here. WordPress already holds the live
		// value and the frozen one stops being true the moment the post is renamed, so the
		// converter drops it — which is only testable if the frozen copy disagrees with the
		// post title, as it does here and as it does on one real album member.
		'title'                 => isset( $options['title'] ) ? $options['title'] : 'Frozen title ' . $gallery_id,
		'caption'               => isset( $options['caption'] ) ? $options['caption'] : '',
		'alt'                   => '',
		'cover_image_id'        => (string) $cover_id,
		'cover_image_url'       => '',
		'link_new_window'       => '0',
		'gallery_lightbox'      => '0',
		'cover_image_url_thumb' => '',
		'publish_date'          => '',
	);
}

/**
 * Builds one album record in the exporter's shape.
 *
 * @param int    $id      Album post ID.
 * @param string $title   Post title.
 * @param array  $members Member entries keyed by gallery ID.
 * @param array  $order   Gallery IDs in display order, as strings.
 * @param array  $config  Envira album config.
 * @param array  $options Optional status and the older member-less shape.
 *
 * @return array The record.
 */
function atelier_fixture_album( $id, $title, array $members, array $order, array $config, array $options = array() ) {
	$data = array(
		'galleryIDs' => $order,
		'galleries'  => $members,
		'id'         => $id,
		'config'     => $config,
	);

	if ( ! empty( $options['legacy'] ) ) {
		// Envira's stored album defaults predate the member list and carry the older shape,
		// which is what the live site's defaults album has.
		$data = array(
			'config'  => $config,
			'id'      => $id,
			'gallery' => array(),
		);
	}

	return array(
		'id'        => $id,
		'title'     => $title,
		'status'    => isset( $options['status'] ) ? $options['status'] : 'publish',
		'protected' => ! empty( $options['protected'] ),
		'meta'      => base64_encode( serialize( $data ) ),
	);
}

// --- the corpus ---------------------------------------------------------------------------
//
// Attachment IDs are allocated in a disjoint block per gallery, which is what keeps the album
// editor's cover checks meaningful; see the note at the top. The blocks are sized so that the
// figures rendered on page one come to comfortably more than the hundred the suite requires
// before it will believe a run examined anything.

$attachments = array();
$galleries   = array();

/**
 * Allocates one block of attachments and returns the items naming them.
 *
 * @param array $attachments Attachment records, added to by reference.
 * @param int   $first       First attachment ID in the block.
 * @param int   $count       How many to allocate.
 * @param array $options     Per-attachment options, applied to every one in the block.
 *
 * @return array Items keyed by attachment ID.
 */
function atelier_fixture_block( array &$attachments, $first, $count, array $options = array() ) {
	$items = array();

	for ( $index = 0; $index < $count; $index++ ) {
		$attachment_id = $first + $index;
		$shape         = atelier_fixture_shape( $index );

		$attachments[ $attachment_id ] = atelier_fixture_attachment(
			$attachment_id,
			$shape[0],
			$shape[1],
			$options
		);

		$items[ $attachment_id ] = atelier_fixture_item( $attachment_id );
	}

	return $items;
}

// 100 - justified with pagination, and EXIF whose per-field toggles disagree with each other.
// Every real gallery turns camera make, model and capture time off while leaving the four
// exposure values on, so a renderer printing everything it can parse prints the camera body on
// a gallery whose settings say not to. Flags spelled '1'.
$items = atelier_fixture_block(
	$attachments,
	1001,
	30,
	array(
		'image_meta' => array(
			'camera'            => 'PENTAX K-3 II',
			'aperture'          => '5.6',
			'focal_length'      => '300',
			'iso'               => '400',
			'shutter_speed'     => '0.0025',
			'created_timestamp' => '1562000000',
		),
	)
);

$galleries[] = atelier_fixture_gallery(
	100,
	'Justified with pagination',
	$items,
	atelier_fixture_config(
		array(
			'pagination'                        => '1',
			'pagination_images_per_page'        => '10',
			'pagination_scroll'                 => '1',
			'exif_lightbox'                     => '1',
			'exif_lightbox_make'                => '0',
			'exif_lightbox_model'               => '0',
			'exif_lightbox_capture_time'        => '0',
			'exif_lightbox_focal_length'        => '1',
			'exif_lightbox_aperture'            => '1',
			'exif_lightbox_shutter_speed'       => '1',
			'exif_lightbox_iso'                 => '1',
			'exif_lightbox_capture_time_format' => 'd.m.Y',
			'protection'                        => '1',
		)
	),
	array( 'name' => 'justified-with-pagination' )
);

// 101 - the tag filter, with the all-button label the site owner translated. The stored label
// wins over the plugin's own translation, which is only visible on a gallery that has one.
// Flags spelled 'True', which is one of the four spellings Envira actually wrote — `keyboard`
// alone is split between '1' on 32 galleries and 'True' on 20.
//
// Tags are attachment terms rather than gallery data, which is what makes a filter mean the
// same thing wherever the image appears. Every tag has to match at least one item and every
// item on a filtered page has to carry the tag it was filtered by, so the assignment below is
// spread rather than given to one image.
$items = atelier_fixture_block( $attachments, 1101, 36 );

// Twelve terms across thirty-six images, in consecutive groups of three, and every third
// image left untagged.
//
// The grouping is what makes the bar's central property testable rather than incidental. The
// suite asserts the gallery carries strictly more tags than its first page shows — because a
// bar listing only the rendered page's tags is the DOM-hiding filter this design replaced, and
// on the live site 17 of one gallery's 40 buttons filter the visible page down to nothing.
// Spreading four terms evenly instead puts all four on page one, and the check went red saying
// so. Grouped, page one carries three of the twelve.
$terms = array(
	'vogel'       => 'Vogel',
	'saeugetier'  => 'Säugetier',
	'insekt'      => 'Insekt',
	'landschaft'  => 'Landschaft',
	'pflanze'     => 'Pflanze',
	'wasser'      => 'Wasser',
	'winter'      => 'Winter',
	'makro'       => 'Makro',
	'reptil'      => 'Reptil',
);

$slugs = array_keys( $terms );
$index = 0;

foreach ( array_keys( $items ) as $attachment_id ) {
	$slug = '';

	if ( $index < 13 ) {
		// One tag deliberately matches more images than fit on a page, and 13 is not a
		// multiple of 10.
		//
		// Both halves are load-bearing, and the measurement is what says so: with every tag
		// matching two images, `page_count()` rounding DOWN instead of up is invisible, because
		// `max( 1, floor( 2 / 10 ) )` and `ceil( 2 / 10 )` are both 1. Mutation B9 killed its
		// predicted check either way and lost one red — the filtered arithmetic — against the
		// first version of this corpus. A tag matching 13 gives ceil 2 against floor 1.
		$slug = $slugs[0];
	} elseif ( 2 !== ( $index - 13 ) % 3 ) {
		$slug = $slugs[ 1 + intdiv( $index - 13, 3 ) ];
	}

	if ( '' === $slug ) {
		$index++;

		continue;
	}

	$shape = atelier_fixture_shape( $index );

	$attachments[ $attachment_id ] = atelier_fixture_attachment(
		$attachment_id,
		$shape[0],
		$shape[1],
		array( 'tags' => array( array( 'slug' => $slug, 'name' => $terms[ $slug ] ) ) )
	);

	$index++;
}

$galleries[] = atelier_fixture_gallery(
	101,
	'Tagged gallery',
	$items,
	atelier_fixture_config(
		array(
			'tags'                       => 'True',
			'tags_filter'                => 'True',
			'tags_position'              => 'above',
			'tags_all_enabled'           => 'True',
			'tags_all'                   => 'Alle',
			'keyboard'                   => 'True',
			'lazy_loading'               => 'True',
			// Paginated, because 47 of the 52 real galleries are and because the interesting
			// half of server-side filtering is what it does to the page arithmetic. A filter
			// that only spans the rendered page is the design this one replaced.
			'pagination'                 => 'True',
			'pagination_images_per_page' => '10',
		)
	),
	array( 'name' => 'tagged-gallery' )
);

// 102 - a fixed column grid rather than the justified one, which is the other layout branch.
// Flags spelled as integer 1.
$items = atelier_fixture_block( $attachments, 1201, 45 );

// One image WordPress never generated a `medium_large` for, in the one gallery that asks for a
// non-default size. Both facts are needed together: at its own setting the image resolves, and
// only a change that forces the default onto it drops it back to the full-size original.
//
// This was found by measurement rather than by thinking of it. Mutation B6 reverses `fill()`'s
// merge so stored settings lose to defaults, and against the real corpus it turns
// `grid image is not the original` red — against the first version of this one it did not,
// because every synthetic attachment carried every size and so nothing could regress.
$attachments[1201] = atelier_fixture_attachment(
	1201,
	1600,
	1067,
	array( 'omit_sizes' => array( 'medium_large' ) )
);

$galleries[] = atelier_fixture_gallery(
	102,
	'Fixed columns',
	$items,
	atelier_fixture_config(
		array(
			'columns'      => '4',
			'isotope'      => 0,
			'gutter'       => '20',
			'lazy_loading' => 1,
			'keyboard'     => 1,
			'protection'   => 0,
			'image_size'   => 'medium',
		)
	),
	array( 'name' => 'fixed-columns' )
);

// 103 - captions, titles, alt text and hand-written custom CSS keyed on Envira's own element
// id, which the converter rewrites to Atelier's. Sixteen real galleries carry such a rule.
// Flags spelled as PHP booleans.
//
// Not one of the 2,264 real items has a caption or alt text and no attachment has an excerpt
// to fall back to, so the equivalence check run over the real fixture alone cannot notice a
// conversion that drops those fields — which a mutation demonstrated by surviving. Here they
// are present from the start.
$items = atelier_fixture_block(
	$attachments,
	1301,
	20,
	array(
		'excerpt' => 'Attachment excerpt',
		'alt'     => 'Attachment alt text',
	)
);

$captions = array(
	'A caption with <em>markup</em> in it',
	'A caption with an ampersand & a quote "here"',
	'Ein Bildtitel mit Umlauten: Käfer über Ästen',
	'A plain caption',
);

$index = 0;

foreach ( $items as $attachment_id => $item ) {
	$items[ $attachment_id ] = atelier_fixture_item(
		$attachment_id,
		array(
			'title'   => 'Item title ' . $attachment_id,
			'caption' => $captions[ $index % count( $captions ) ],
			'alt'     => 'Item alt ' . $attachment_id,
		)
	);

	$index++;
}

$galleries[] = atelier_fixture_gallery(
	103,
	'Captions and custom CSS',
	$items,
	atelier_fixture_config(
		array(
			'custom_css'      => '#envira-gallery-103 .envira-gallery-item { border: 1px solid #333; }',
			'title_display'   => 'below',
			'lazy_loading'    => true,
			'keyboard'        => true,
			'social_lightbox' => true,
			'social_facebook' => true,
			'social_email'    => true,
		)
	),
	array( 'name' => 'captions-and-custom-css' )
);

// 104 - a pending item and an orphaned one, beside active ones. Envira marks an item `pending`
// rather than removing it, and a pending item is not displayed; the live site has two.
//
// The orphan names an attachment with no row at all, which is what deleting a photograph from
// the media library leaves behind: the item survives in `_eg_gallery_data` and renders from
// Envira's frozen full-size URL, with no metadata, no sizes and no dimensions. The live corpus
// has none — all 2,264 items resolve — so the per-gallery equality asserting the renderer keeps
// such an item out of the lightbox is vacuously true on every real gallery, and this is the one
// row anywhere that makes it mean something.
//
// Adding it is what forced four per-item checks to say what they had always meant. They
// asserted a srcset, an intrinsic size, lightbox dimensions and a non-original grid src for
// every item, which is a property of THIS corpus rather than of the renderer, and an orphan
// turned all four red while the renderer behaved exactly as designed.
$items = atelier_fixture_block( $attachments, 1401, 12 );

$items[1412]   = atelier_fixture_item( 1412, array( 'status' => 'pending' ) );
$items[1499001] = atelier_fixture_item( 1499001, array( 'title' => 'Deleted from the media library' ) );

$galleries[] = atelier_fixture_gallery(
	104,
	'With a pending item',
	$items,
	atelier_fixture_config(),
	array( 'name' => 'with-a-pending-item' )
);

// 105 - password-protected. Carried as a boolean and never as the password: what the suite
// needs is the distinction, and until the exporter carried it the corpus could not tell a
// protected gallery from a public one — which is how an album cover grid published one for a
// while with the whole suite green.
$galleries[] = atelier_fixture_gallery(
	105,
	'Protected gallery',
	atelier_fixture_block( $attachments, 1501, 6 ),
	atelier_fixture_config(),
	array(
		'name'      => 'protected-gallery',
		'protected' => true,
	)
);

// 106 to 108 - the non-public statuses. Two drafts rather than one, because the live site has
// two and a single row is not a distribution.
$galleries[] = atelier_fixture_gallery(
	106,
	'Private gallery',
	atelier_fixture_block( $attachments, 1601, 4 ),
	atelier_fixture_config(),
	array(
		'name'   => 'private-gallery',
		'status' => 'private',
	)
);

$galleries[] = atelier_fixture_gallery(
	107,
	'Draft gallery one',
	atelier_fixture_block( $attachments, 1701, 3 ),
	atelier_fixture_config(),
	array(
		'name'   => 'draft-gallery-one',
		'status' => 'draft',
	)
);

$galleries[] = atelier_fixture_gallery(
	108,
	'Draft gallery two',
	atelier_fixture_block( $attachments, 1801, 3 ),
	atelier_fixture_config(),
	array(
		'name'   => 'draft-gallery-two',
		'status' => 'draft',
	)
);

// 109 - Envira keeps its site-wide defaults in a gallery of its own, marked `type =
// 'defaults'`, and the reader skips it or it renders as an empty grid.
//
// It is `publish` here where the live site's is a draft, and that is a deliberate departure. A
// mutation removing the same guard from the album reader SURVIVED, because the live defaults
// row is a draft and every loop skips albums with no members — so the guard was unreachable by
// accident of the data rather than by anything in the code. A published defaults row is what
// any other Envira site would have.
$galleries[] = atelier_fixture_gallery(
	109,
	'Envira Default Settings',
	atelier_fixture_block( $attachments, 1901, 1 ),
	atelier_fixture_config( array( 'type' => 'defaults' ) ),
	array( 'name' => 'envira-default-settings' )
);

$albums = array();

// 200 - three members with titles and counts shown, which is what both real albums do. The
// three hold disjoint attachments, which is what the cover checks need.
$albums[] = atelier_fixture_album(
	200,
	'Album with three members',
	array(
		100 => atelier_fixture_member( 100, 1001 ),
		101 => atelier_fixture_member( 101, 1112 ),
		102 => atelier_fixture_member( 102, 1217 ),
	),
	array( '100', '101', '102' ),
	array(
		'type'                => 'album',
		'columns'             => '3',
		'display_titles'      => 'below',
		'display_image_count' => '1',
	)
);

// 201 - the same gallery listed twice, and both settings switched off. The storage allows a
// duplicate and the editor can create one; the renderer used to look members up by ID and so
// rendered the first entry's cover and caption for both. Titles and counts are off because
// both were stored, both varied between the real albums, and the renderer showed them
// unconditionally anyway — a record carrying a choice nothing read.
$albums[] = atelier_fixture_album(
	201,
	'Album with a repeated member',
	array(
		103 => atelier_fixture_member( 103, 1302 ),
		104 => atelier_fixture_member( 104, 1403 ),
	),
	array( '103', '104', '103' ),
	array(
		'type'                => 'album',
		'columns'             => '2',
		'display_titles'      => '0',
		'display_image_count' => '0',
	)
);

// 202 - Envira's stored album defaults, in the older member-less shape the live one has.
$albums[] = atelier_fixture_album(
	202,
	'Envira Default Album Settings',
	array(),
	array(),
	array( 'type' => 'defaults' ),
	array(
		'legacy' => true,
		'status' => 'draft',
	)
);

$out = isset( $argv[1] ) ? $argv[1] : __DIR__ . '/fixture-synthetic.json';

// Pretty-printed with unescaped slashes and unicode, because this file is committed and a
// reviewer has to be able to read a diff of it. The exporter writes its own compactly for the
// opposite reason: nobody reads six megabytes.
file_put_contents(
	$out,
	json_encode(
		array(
			'siteurl'     => 'https://example.com',
			'galleries'   => $galleries,
			'albums'      => $albums,
			'attachments' => $attachments,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	) . "\n"
);

$items_total = 0;

foreach ( $galleries as $gallery ) {
	$data         = unserialize( base64_decode( $gallery['meta'] ) );
	$items_total += count( $data['gallery'] );
}

printf(
	"galleries=%d albums=%d attachments=%d items=%d\n",
	count( $galleries ),
	count( $albums ),
	count( $attachments ),
	$items_total
);
printf( "wrote %s (%d bytes)\n", $out, filesize( $out ) );
