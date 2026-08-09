<?php
/**
 * Registers and conditionally enqueues front-end assets.
 *
 * @package Atelier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the plugin's styles and scripts.
 *
 * Assets are registered on every request but enqueued only once a gallery has actually
 * been rendered, so a page with no gallery on it loads none of this. Envira enqueued its
 * stylesheet site-wide, which is a meaningful cost on a site where most pages are text.
 */
class Atelier_Assets {

	/**
	 * Whether a gallery has been rendered on this request.
	 *
	 * @var bool
	 */
	private $needed = false;

	/**
	 * Plugin settings.
	 *
	 * @var Atelier_Settings
	 */
	private $settings;

	/**
	 * Builds the asset handler.
	 *
	 * @param Atelier_Settings $settings Plugin settings.
	 */
	public function __construct( Atelier_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hooks asset registration.
	 *
	 * **Registration is on `init`, not on `wp_enqueue_scripts`, and the difference is the
	 * admin.** `wp_enqueue_scripts` fires on the front end only, so a handle registered there
	 * does not exist in the block editor — and `Atelier_Block` registers an editor stylesheet
	 * that *depends* on `atelier`, so the preview would be laid out by nothing. A missing
	 * dependency is dropped silently by `WP_Styles`; there is no warning to notice.
	 *
	 * Registering is not enqueueing. Nothing below puts a byte on a page: `need_gallery()` is
	 * still the only thing that does, and it is still only called once a gallery has rendered.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_early' ), 20 );
	}

	/**
	 * Registers the styles and scripts without enqueueing them.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style(
			'photoswipe',
			ATELIER_URL . 'assets/vendor/photoswipe/photoswipe.css',
			array(),
			'5.4.4'
		);

		wp_register_style(
			'atelier',
			ATELIER_URL . 'assets/css/atelier.css',
			array( 'photoswipe' ),
			ATELIER_VERSION
		);

		wp_register_script(
			'atelier',
			ATELIER_URL . 'assets/js/atelier.js',
			array(),
			ATELIER_VERSION,
			true
		);

		wp_localize_script(
			'atelier',
			'AtelierSettings',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'atelier' ),
				'photoswipe' => ATELIER_URL . 'assets/vendor/photoswipe/photoswipe.esm.min.js',
				'i18n'       => array(
					'close'      => __( 'Close', 'atelier' ),
					'next'       => __( 'Next', 'atelier' ),
					'previous'   => __( 'Previous', 'atelier' ),
					'zoom'       => __( 'Zoom', 'atelier' ),
					'download'   => __( 'Download', 'atelier' ),
					'share'      => __( 'Share', 'atelier' ),
					/* translators: %s: name of a social network, e.g. Facebook. */
					'shareOn'    => __( 'Share on %s', 'atelier' ),
					'copyLink'   => __( 'Copy link', 'atelier' ),
					'copied'     => __( 'Link copied', 'atelier' ),
					'loadFailed' => __( 'This page could not be loaded.', 'atelier' ),
				),
			)
		);
	}

	/**
	 * Enqueues the assets early when the post being viewed contains a gallery.
	 *
	 * Shortcodes run during `the_content`, which is after `wp_head`, so an enqueue made at
	 * render time is printed by `print_late_styles()` in the footer — the styles do arrive,
	 * but the first paint can be unstyled and then reflow, which is exactly what the CSS
	 * layout exists to avoid. Looking for the shortcode in the post content beforehand puts
	 * the stylesheet in the head for the normal case; `need_gallery()` remains the backstop
	 * for galleries rendered from a widget, a template call or a block.
	 *
	 * **The list of shortcodes has to be the one `Atelier_Shortcode` actually claims, not every
	 * shortcode Atelier knows the name of.** Enqueueing on `[envira-gallery]` while the takeover
	 * setting has us standing aside loads a stylesheet and two scripts onto a page that Envira
	 * is rendering — measured on the live site at about 940 bytes of markup and three requests,
	 * with no visual change. Small, but it makes "install Atelier and change nothing" false in
	 * the one configuration that claim exists for: both plugins active, `takeover=auto`.
	 *
	 * @return void
	 */
	public function maybe_enqueue_early() {
		if ( $this->needed || ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$shortcodes = array( 'atelier-gallery', 'atelier-album' );

		if ( $this->settings->should_take_over() ) {
			$shortcodes[] = 'envira-gallery';
			$shortcodes[] = 'envira-album';
		}

		foreach ( $shortcodes as $shortcode ) {
			if ( has_shortcode( $post->post_content, $shortcode ) ) {
				$this->need_gallery();

				return;
			}
		}
	}

	/**
	 * Records that a gallery was rendered and enqueues the assets.
	 *
	 * Safe to call repeatedly; only the first call does anything.
	 *
	 * @return void
	 */
	public function need_gallery() {
		if ( $this->needed ) {
			return;
		}

		$this->needed = true;

		wp_enqueue_style( 'atelier' );
		wp_enqueue_script( 'atelier' );
	}
}
