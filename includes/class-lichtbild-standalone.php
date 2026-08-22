<?php
/**
 * Renders a gallery on its own permalink.
 *
 * @package Lichtbild
 */

defined( 'ABSPATH' ) || exit;

/**
 * Puts the gallery on the gallery's own page.
 *
 * `/envira/<slug>/` is a real, indexed, canonical URL, and a gallery post carries its images
 * in post meta rather than in `post_content` — so without this the permalink resolves, the
 * theme renders, and the page is empty. Registering the post type keeps the URL from 404ing;
 * only this makes the URL *show* anything, and the two are easy to confuse because the first
 * is what an HTTP status code reports.
 *
 * Envira does this by filtering `the_content` when a site option is set, and this mirrors it
 * closely enough that the same page keeps working after the switch. Two deliberate
 * differences:
 *
 * - **The loop is checked, not just the query.** Envira tests `is_singular()` and the queried
 *   post type, which is also true inside a secondary loop — a related-posts block or a
 *   sidebar widget running `the_content` on this post would get a second copy of the gallery.
 *   `in_the_loop()` and `is_main_query()` narrow it to the one place it belongs.
 * - **The setting is owned rather than borrowed.** Reading Envira's option forever would mean
 *   uninstalling Envira could silently blank every gallery permalink on the site, so the
 *   migration copies the value across and this prefers Lichtbild's own.
 */
class Lichtbild_Standalone {

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
	 * Hooks the content filter.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'the_content', array( $this, 'insert' ), 20 );
	}

	/**
	 * Appends the gallery to the content of its own page.
	 *
	 * @param string $content Post content.
	 *
	 * @return string Content, with the gallery appended when this is a gallery's own page.
	 */
	public function insert( $content ) {
		$kind = $this->applies();

		if ( 'album' === $kind ) {
			$album = $this->repository->album( get_the_ID() );

			return null === $album
				? $content
				: $content . $this->renderer->album( $album, $this->repository );
		}

		if ( 'gallery' === $kind ) {
			$gallery = $this->repository->gallery( get_the_ID() );

			return null === $gallery ? $content : $content . $this->renderer->gallery( $gallery, 1 );
		}

		return $content;
	}

	/**
	 * Reports what this request is the own page of, in the main loop.
	 *
	 * **Albums count, and forgetting them cost two live URLs.** An album keeps its galleries
	 * in post meta for exactly the same reason a gallery keeps its images there, so
	 * `/envira_album/<slug>/` has the same problem and needed the same answer — but it was
	 * invisible for a while because registering the post type keeps the URL answering 200,
	 * and an empty page behind a healthy status code is what this whole class exists to stop.
	 * Envira's albums addon renders those pages, so switching to Lichtbild without this is a
	 * regression rather than a missing extra.
	 *
	 * Envira has no separate option for album pages — checked against the live database — so
	 * both follow the one standalone setting.
	 *
	 * @return string `gallery`, `album`, or an empty string when nothing should be appended.
	 */
	private function applies() {
		if ( ! $this->enabled() ) {
			return '';
		}

		// The same deference the shortcode shows, and for a sharper reason. Envira has a
		// standalone filter of its own, so with both plugins active and this unguarded the
		// gallery's permalink renders the gallery *twice* — once from each plugin. It is the
		// one page where the two cannot simply take turns, because neither is invoked by
		// anything in `post_content` that the other could claim.
		//
		// Caught on the live site, in the state this setting exists for: Lichtbild freshly
		// activated alongside Envira, which is meant to be a no-op and was not. The local
		// WordPress had only ever rendered standalone pages with Envira switched off.
		//
		// Measured with both plugins active, one mode at a time: `auto` and `never` leave
		// Envira's copy alone, and `always` yields Lichtbild's copy *only* — because Envira's
		// standalone filter appends its shortcode rather than rendering directly, so whoever
		// owns the shortcode renders it and this guard decides the rest. Every mode gives
		// exactly one gallery.
		if ( ! $this->settings->should_take_over() ) {
			return '';
		}

		// All three matter. Without `in_the_loop()` a secondary loop rendering this post gets
		// its own copy of the gallery; without `is_main_query()` so does a widget.
		if ( ! in_the_loop() || ! is_main_query() ) {
			return '';
		}

		if ( is_singular( Lichtbild_Post_Types::gallery_type( $this->settings ) ) ) {
			$kind = 'gallery';
		} elseif ( is_singular( Lichtbild_Post_Types::album_type( $this->settings ) ) ) {
			$kind = 'album';
		} else {
			return '';
		}

		// A password-protected post is one WordPress has already replaced the content of with
		// the password form — and this filter runs *after* that, so appending here would
		// publish every image directly underneath the form asking for the password. The
		// protection is on `post_content`, and a gallery keeps its images in post meta, so
		// nothing upstream defends this: it has to check for itself.
		//
		// The predicate's status leg is belt to WordPress's braces here, unlike in the other
		// two paths where it is the only thing checking. A page WordPress declined to serve
		// never reaches `the_content` at all — but that impossibility lives in core's query
		// rather than in this file, so asking is cheaper than depending on it silently.
		return $this->repository->is_viewable( get_the_ID() ) ? $kind : '';
	}

	/**
	 * Reports whether standalone gallery pages are enabled.
	 *
	 * @return bool True when a gallery renders on its own permalink.
	 */
	public function enabled() {
		$enabled = $this->settings->standalone();

		/**
		 * Filters whether a gallery renders on its own permalink.
		 *
		 * @param bool $enabled Whether standalone gallery pages are on.
		 */
		return (bool) apply_filters( 'lichtbild_standalone_enabled', $enabled );
	}
}
