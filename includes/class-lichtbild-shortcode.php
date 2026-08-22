<?php
/**
 * Shortcode handlers.
 *
 * @package Lichtbild
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the gallery and album shortcodes.
 *
 * `[lichtbild-gallery]` is always ours. `[envira-gallery]` is taken over according to the
 * takeover setting, and the takeover happens late on `init` so that Envira — which
 * registers its own shortcode on `init` at the default priority — has already run and can
 * be displaced deterministically rather than by load order.
 *
 * **Since 26.8.6 this consults `Lichtbild_Repository::is_viewable()` like every other publishing
 * path, and the question it was left open for has an answer.** It was the fourth and last path
 * that did not. The argument for leaving it was that a shortcode is an author naming a specific
 * gallery in a specific post, which reads as intent to publish it *there* — the way WordPress
 * does not cascade a post's password onto the attachments it embeds. What made that thin is
 * that the same sentence very nearly describes an album, and an album always did check.
 *
 * What kept it open was cost, not principle: WordPress holds **one** cookie carrying **one**
 * password, so a protected gallery embedded in a protected post with a *different* password
 * would stop rendering for a visitor who had legitimately unlocked the page. That is a product
 * question about what a password means, and it was settled by measuring rather than arguing —
 * on the site this plugin exists for, the one protected gallery is embedded in a post carrying
 * the *same* password, and the one private gallery is embedded in a private post only readable
 * by users who can read the gallery too. Closing it costs nothing there, and it makes the
 * remaining case behave the way anyone would expect: a protected gallery embedded in a public
 * post refuses, which is precisely what the check exists for.
 *
 * The album handler checks the **album's** own visibility; the renderer separately checks each
 * member gallery. Both are needed and neither implies the other.
 */
class Lichtbild_Shortcode {

	/**
	 * Gallery repository.
	 *
	 * @var Lichtbild_Repository
	 */
	private $repository;

	/**
	 * Renderer.
	 *
	 * @var Lichtbild_Renderer
	 */
	private $renderer;

	/**
	 * Plugin settings.
	 *
	 * @var Lichtbild_Settings
	 */
	private $settings;

	/**
	 * Builds the handler.
	 *
	 * @param Lichtbild_Repository $repository Gallery repository.
	 * @param Lichtbild_Renderer   $renderer   Renderer.
	 * @param Lichtbild_Settings   $settings   Plugin settings.
	 */
	public function __construct( Lichtbild_Repository $repository, Lichtbild_Renderer $renderer, Lichtbild_Settings $settings ) {
		$this->repository = $repository;
		$this->renderer   = $renderer;
		$this->settings   = $settings;
	}

	/**
	 * Hooks shortcode registration.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_shortcodes' ), 100 );
	}

	/**
	 * Registers the shortcodes.
	 *
	 * @return void
	 */
	public function register_shortcodes() {
		add_shortcode( 'lichtbild-gallery', array( $this, 'gallery' ) );
		add_shortcode( 'lichtbild-album', array( $this, 'album' ) );

		if ( $this->settings->claims_envira_shortcodes() ) {
			remove_shortcode( 'envira-gallery' );
			remove_shortcode( 'envira-album' );

			add_shortcode( 'envira-gallery', array( $this, 'gallery' ) );
			add_shortcode( 'envira-album', array( $this, 'album' ) );
		}
	}

	/**
	 * Renders a gallery shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 *
	 * @return string HTML markup, empty when the gallery cannot be found.
	 */
	public function gallery( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'   => '',
				'slug' => '',
				'page' => 1,
			),
			is_array( $atts ) ? $atts : array(),
			'lichtbild-gallery'
		);

		$reference = '' !== $atts['id'] ? $atts['id'] : $atts['slug'];

		if ( '' === $reference ) {
			return '';
		}

		$gallery = $this->repository->gallery( $reference );

		if ( null === $gallery || ! $this->repository->is_viewable( $gallery->id() ) ) {
			return '';
		}

		return $this->renderer->gallery( $gallery, max( 1, (int) $atts['page'] ) );
	}

	/**
	 * Renders an album shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 *
	 * @return string HTML markup, empty when the album cannot be found.
	 */
	public function album( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'   => '',
				'slug' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'lichtbild-album'
		);

		$reference = '' !== $atts['id'] ? $atts['id'] : $atts['slug'];

		if ( '' === $reference ) {
			return '';
		}

		$album = $this->repository->album( $reference );

		if ( null === $album || ! $this->repository->is_viewable( $album->id() ) ) {
			return '';
		}

		return $this->renderer->album( $album, $this->repository );
	}
}
