<?php
/**
 * Block editor registration.
 *
 * @package Atelier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `atelier/gallery` and `atelier/album` blocks.
 *
 * **This is the fifth path that can put gallery content in front of a visitor, and it renders
 * none of it itself.** Both callbacks hand straight to `Atelier_Shortcode`, which already
 * consults `Atelier_Repository::is_viewable()`. That is deliberate and it is the whole design:
 * the four earlier paths each grew their own copy of the visibility rule, one of them forgot
 * it, and a protected gallery's cover was published on a public album page for a week. A block
 * that assembled its own repository and renderer would be the fifth copy of a rule that has
 * already been forgotten once.
 *
 * So there is exactly one thing to verify about this class, and the checks say it in those
 * terms: what it renders is byte-identical to what the shortcode renders, including when the
 * shortcode renders nothing.
 *
 * WHY THE PICKER IS PRINTED INTO THE PAGE RATHER THAN FETCHED
 * ==========================================================
 *
 * `Atelier_Post_Types` registers all three types with `show_in_rest => false`, because the
 * editors are metaboxes on the classic post screen and nothing else needs them over REST.
 * A block editor therefore cannot query them: `useEntityRecords( 'postType', 'atelier_gallery' )`
 * answers a 404, not an empty list. The choices are printed as an inline script instead, which
 * is the same shape `Atelier_Assets` already uses for the lightbox's strings.
 *
 * That is a constraint rather than a preference, and it is worth knowing before someone
 * "modernises" this into a fetch: turning `show_in_rest` on would expose every gallery record
 * on a new public surface to answer a question the editor screen already knows the answer to.
 *
 * WHAT THE EDITOR PREVIEW DELIBERATELY DOES NOT LOAD
 * ==================================================
 *
 * The block's `editorStyle` pulls in the front-end stylesheet, so the preview is laid out
 * exactly as a visitor sees it. `atelier.js` is **not** loaded, so the preview has no lightbox
 * and no AJAX pagination. Both would be actively wrong inside an editor — a click that opens a
 * full-screen viewer over the post you are writing, or a pagination request that replaces the
 * preview's markup behind the editor's back. `blocks.css` also makes the preview inert, so a
 * click on a photograph cannot navigate the editor away to an image file.
 */
class Atelier_Block {

	/**
	 * Handle shared by the editor script and the editor stylesheet.
	 */
	const HANDLE = 'atelier-blocks';

	/**
	 * The shortcode handler both render callbacks delegate to.
	 *
	 * @var Atelier_Shortcode
	 */
	private $shortcode;

	/**
	 * Reads the galleries and albums the picker offers.
	 *
	 * @var Atelier_Repository
	 */
	private $repository;

	/**
	 * Builds the block registrar.
	 *
	 * @param Atelier_Shortcode  $shortcode  Handler both blocks render through.
	 * @param Atelier_Repository $repository Reader behind the picker.
	 */
	public function __construct( Atelier_Shortcode $shortcode, Atelier_Repository $repository ) {
		$this->shortcode  = $shortcode;
		$this->repository = $repository;
	}

	/**
	 * Hooks block registration.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_data' ) );
	}

	/**
	 * Registers the editor assets and both block types.
	 *
	 * @return void
	 */
	public function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			self::HANDLE,
			ATELIER_URL . 'assets/js/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ),
			ATELIER_VERSION,
			true
		);

		// Depends on `atelier`, so the preview is laid out by the same stylesheet the visitor
		// gets. That handle is registered on `init` for exactly this reason — `wp_enqueue_scripts`
		// never fires in the admin, so a dependency registered there is silently dropped here and
		// the preview renders unstyled.
		wp_register_style(
			self::HANDLE,
			ATELIER_URL . 'assets/css/blocks.css',
			array( 'atelier' ),
			ATELIER_VERSION
		);

		register_block_type(
			ATELIER_DIR . 'blocks/gallery',
			array( 'render_callback' => array( $this, 'render_gallery' ) )
		);

		register_block_type(
			ATELIER_DIR . 'blocks/album',
			array( 'render_callback' => array( $this, 'render_album' ) )
		);
	}

	/**
	 * Prints the picker's choices and the editor's strings.
	 *
	 * **Separate from `register_blocks()` on purpose, and the reason is a measurement.** Building
	 * the choices reads every gallery row through the reader, which on the live site's cold cache
	 * is **111 queries and 11ms** — for data only the block editor ever looks at. Called from
	 * `register_blocks()`, which runs on `init`, that was 111 queries on every front-end page
	 * view, and it does not show up in any rendered byte. `enqueue_block_editor_assets` fires
	 * only when the block editor loads, so it is paid once by the one screen that reads it, and
	 * not on the plugins screen either.
	 *
	 * Attaching the data after the script has already been enqueued is fine: `wp_add_inline_script`
	 * appends to the registered handle, and the handle is printed later, in the footer.
	 *
	 * `before`, so `window.AtelierBlocks` exists by the time the script body runs. The strings
	 * travel with it rather than through `wp_set_script_translations()`: that route needs a
	 * compiled JSON catalogue per script handle, generated by a build step this plugin
	 * deliberately does not have, and `tests/i18n-test.php` could not see into it.
	 *
	 * @return void
	 */
	public function enqueue_editor_data() {
		wp_add_inline_script(
			self::HANDLE,
			'window.AtelierBlocks = ' . wp_json_encode( $this->editor_data() ) . ';',
			'before'
		);
	}

	/**
	 * Renders the gallery block.
	 *
	 * @param array $attributes Block attributes.
	 *
	 * @return string HTML markup, empty when the gallery cannot be shown.
	 */
	public function render_gallery( $attributes ) {
		return $this->shortcode->gallery( array( 'id' => $this->reference( $attributes ) ) );
	}

	/**
	 * Renders the album block.
	 *
	 * @param array $attributes Block attributes.
	 *
	 * @return string HTML markup, empty when the album cannot be shown.
	 */
	public function render_album( $attributes ) {
		return $this->shortcode->album( array( 'id' => $this->reference( $attributes ) ) );
	}

	/**
	 * Reads the chosen post ID out of the block attributes.
	 *
	 * Returned as the empty string when nothing is chosen, which is the value the shortcode
	 * already treats as "no reference". A literal `'0'` is not that value — it would be looked
	 * up, miss, and reach the same answer by a longer route.
	 *
	 * @param mixed $attributes Block attributes, which are whatever the editor stored.
	 *
	 * @return string Post ID as a string, or an empty string.
	 */
	private function reference( $attributes ) {
		$id = is_array( $attributes ) && isset( $attributes['id'] ) ? (int) $attributes['id'] : 0;

		return $id > 0 ? (string) $id : '';
	}

	/**
	 * Builds the data the editor script needs.
	 *
	 * @return array{galleries:array,albums:array,i18n:array} Picker choices and UI strings.
	 */
	private function editor_data() {
		return array(
			'galleries' => $this->options( $this->repository->gallery_choices() ),
			'albums'    => $this->options( $this->repository->album_choices() ),
			'i18n'      => array(
				'galleryTitle'        => __( 'Atelier Gallery', 'atelier' ),
				'albumTitle'          => __( 'Atelier Album', 'atelier' ),
				'chooseGallery'       => __( 'Choose a gallery', 'atelier' ),
				'chooseAlbum'         => __( 'Choose an album', 'atelier' ),
				'galleryInstructions' => __( 'Pick one of the galleries on this site. Edit its images and settings on the gallery itself, not here.', 'atelier' ),
				'albumInstructions'   => __( 'Pick one of the albums on this site. Edit its galleries and settings on the album itself, not here.', 'atelier' ),
				'settings'            => __( 'Gallery', 'atelier' ),
				'albumSettings'       => __( 'Album', 'atelier' ),
				'none'                => __( '— Select —', 'atelier' ),
				'noGalleries'         => __( 'This site has no galleries yet.', 'atelier' ),
				'noAlbums'            => __( 'This site has no albums yet.', 'atelier' ),
				'emptyGallery'        => __( 'This gallery has nothing to show. It may be empty, a draft, or password-protected.', 'atelier' ),
				'emptyAlbum'          => __( 'This album has nothing to show. It may be empty, a draft, or password-protected.', 'atelier' ),
			),
		);
	}

	/**
	 * Turns titles-keyed-by-id into the shape `SelectControl` wants.
	 *
	 * @param array<int,string> $choices Titles keyed by post ID.
	 *
	 * @return array<int,array{value:int,label:string}> Select options.
	 */
	private function options( array $choices ) {
		$out = array();

		foreach ( $choices as $id => $title ) {
			$out[] = array(
				'value' => (int) $id,
				'label' => (string) $title,
			);
		}

		return $out;
	}
}
