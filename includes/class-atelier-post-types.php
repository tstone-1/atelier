<?php
/**
 * Registers the post types and taxonomy galleries live in.
 *
 * @package Atelier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the object types Envira used to register.
 *
 * This is what independence actually rests on. Galleries, albums and image tags are all
 * custom types, and a custom type exists only while some plugin registers it — so deleting
 * Envira without replacing these registrations does not merely remove an editor, it takes
 * `/envira/<slug>/`, `/envira_album/<slug>/` and `/envira-tag/<slug>/` off the site. Those
 * are live, canonical, indexed URLs.
 *
 * Two subtleties decide how this class behaves:
 *
 * - **The names change at migration, the URLs never do.** Before migration the rows say
 *   `post_type = 'envira'`, so that is what gets registered. Afterwards they say
 *   `atelier_gallery`, and that is registered instead — but with `rewrite['slug']` pinned to
 *   the original path either way, so no URL moves in either direction.
 * - **Never register on top of Envira.** While Envira is active it registers these itself,
 *   and registering the same name twice means the last call silently wins. Atelier stays out
 *   of the way until Envira is gone.
 */
class Atelier_Post_Types {

	/**
	 * Post type holding galleries once Atelier owns the data.
	 */
	const GALLERY = 'atelier_gallery';

	/**
	 * Post type holding albums once Atelier owns the data.
	 */
	const ALBUM = 'atelier_album';

	/**
	 * Taxonomy holding per-image tags once Atelier owns the data.
	 */
	const TAG = 'atelier_tag';

	/**
	 * URL paths for a site that is continuing Envira's, keyed as the filter is.
	 *
	 * These are the right paths for exactly one kind of site: one where Envira already
	 * published these URLs and search engines already hold them. On any other site they are
	 * both wrong and a little rude — a fresh install has no reason to serve its galleries from
	 * a path named after somebody else's product.
	 */
	const SLUGS_ENVIRA = array(
		'gallery' => 'envira',
		'album'   => 'envira_album',
		'tag'     => 'envira-tag',
	);

	/**
	 * URL paths for a site with no Envira history — the default for a new install.
	 */
	const SLUGS_GENERIC = array(
		'gallery' => 'gallery',
		'album'   => 'album',
		'tag'     => 'gallery-tag',
	);

	/**
	 * Back-compatible aliases. Kept because they are a public surface.
	 */
	const GALLERY_SLUG = 'envira';
	const ALBUM_SLUG   = 'envira_album';
	const TAG_SLUG     = 'envira-tag';

	/**
	 * Plugin settings, consulted for whether Envira is active and whether data has moved.
	 *
	 * @var Atelier_Settings
	 */
	private $settings;

	/**
	 * Builds the registrar.
	 *
	 * @param Atelier_Settings $settings Plugin settings.
	 */
	public function __construct( Atelier_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hooks registration.
	 *
	 * Priority 5 on `init` puts this before the shortcode registration at 100 and before
	 * anything that queries galleries, and it matches the priority Envira used.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_types' ), 5 );
	}

	/**
	 * Registers the post types and taxonomy, unless Envira is doing it.
	 *
	 * @return void
	 */
	public function register_types() {
		$migrated = $this->settings->has_migrated();

		// Standing aside for Envira is only correct while the rows are still Envira's. Once
		// they say `atelier_gallery`, nobody else registers that name — so deferring here
		// because Envira happens to be active again would leave the type unregistered and
		// take every gallery, album and tag URL off the site. Envira registering `envira`
		// alongside is harmless at that point: no row is stored under it any more.
		if ( ! $migrated && $this->settings->envira_is_active() ) {
			return;
		}

		register_post_type(
			$migrated ? self::GALLERY : Atelier_Repository::GALLERY_POST_TYPE,
			$this->gallery_args()
		);

		register_post_type(
			$migrated ? self::ALBUM : Atelier_Repository::ALBUM_POST_TYPE,
			$this->album_args()
		);

		register_taxonomy(
			$migrated ? self::TAG : Atelier_Repository::TAG_TAXONOMY,
			'attachment',
			$this->tag_args()
		);

		if ( $migrated && $this->settings->envira_is_active() ) {
			add_action( 'admin_notices', array( $this, 'render_conflict_notice' ) );
		}
	}

	/**
	 * Warns that Envira is registering the same URL paths Atelier now owns.
	 *
	 * Once migrated, both plugins register types whose rewrite slug is `envira`, and one rule
	 * key cannot route to two query variables — so registration order decides which wins, and
	 * if Envira's does, its rule queries a post type that no longer holds a single row and the
	 * URL 404s. Atelier cannot fix that from its own side; the only reliable answer is that
	 * Envira should be gone by this point, which is what this says.
	 *
	 * @return void
	 */
	public function render_conflict_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' .
			esc_html__( 'Envira Gallery is active on a migrated site.', 'atelier' ) . '</strong> ' .
			esc_html__( 'Both plugins are claiming the same gallery, album and tag URLs, and whichever registers last wins — which can make those URLs return 404. Deactivate Envira Gallery, or roll the migration back from Settings > Atelier.', 'atelier' ) .
			'</p></div>';
	}

	/**
	 * Returns the URL path for one object type.
	 *
	 * The `$default` argument is deliberately ignored for anything but a key this class does
	 * not know, and that is the whole point of the change: the fallback used to be Envira's
	 * path unconditionally, so a filter returning an empty string on a generic site silently
	 * put `/envira/` back. The fallback is now the SCHEME's own path, so every route out of
	 * this method agrees with the site's recorded answer.
	 *
	 * @param string $key     One of `gallery`, `album` or `tag`.
	 * @param string $default Path to use when the scheme does not know the key either.
	 *
	 * @return string URL path, without slashes.
	 */
	private function slug( $key, $default ) {
		$scheme = $this->settings->slug_scheme_paths();

		/**
		 * Filters the URL paths galleries, albums and tag archives are served from.
		 *
		 * Changing these on a site that already has galleries moves live URLs, so a site
		 * doing that is responsible for redirecting the old ones.
		 *
		 * @param array $slugs Paths keyed by `gallery`, `album` and `tag`.
		 */
		$slugs = (array) apply_filters( 'atelier_url_slugs', $scheme );

		$slug = isset( $slugs[ $key ] ) ? trim( (string) $slugs[ $key ], '/' ) : '';

		if ( '' !== $slug ) {
			return $slug;
		}

		return isset( $scheme[ $key ] ) ? $scheme[ $key ] : $default;
	}

	/**
	 * Returns the registration arguments for the gallery post type.
	 *
	 * @return array Arguments for register_post_type().
	 */
	private function gallery_args() {
		return array(
			'labels'             => array(
				'name'          => __( 'Galleries', 'atelier' ),
				'singular_name' => __( 'Gallery', 'atelier' ),
				'add_new_item'  => __( 'Add New Gallery', 'atelier' ),
				'edit_item'     => __( 'Edit Gallery', 'atelier' ),
				'new_item'      => __( 'New Gallery', 'atelier' ),
				'view_item'     => __( 'View Gallery', 'atelier' ),
				'search_items'  => __( 'Search Galleries', 'atelier' ),
				'not_found'     => __( 'No galleries found.', 'atelier' ),
				'menu_name'     => __( 'Atelier', 'atelier' ),
			),
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => false,
			'menu_icon'          => 'dashicons-format-gallery',
			'menu_position'      => 25,
			'hierarchical'       => false,
			'has_archive'        => false,
			'query_var'          => true,
			'capability_type'    => 'post',
			'supports'           => array( 'title', 'author', 'revisions' ),
			// Pinned to Envira's path: these URLs are canonical and indexed, and renaming
			// the post type must not move them.
			'rewrite'            => array(
				'slug'       => $this->slug( 'gallery', self::GALLERY_SLUG ),
				'with_front' => false,
			),
		);
	}

	/**
	 * Returns the registration arguments for the album post type.
	 *
	 * @return array Arguments for register_post_type().
	 */
	private function album_args() {
		// The parent has to be the gallery type registered on *this* request. Naming the
		// post-migration type unconditionally puts the submenu under a type that does not
		// exist yet, and WordPress then drops it.
		$parent = $this->settings->has_migrated() ? self::GALLERY : Atelier_Repository::GALLERY_POST_TYPE;

		return array(
			'labels'             => array(
				'name'          => __( 'Albums', 'atelier' ),
				'singular_name' => __( 'Album', 'atelier' ),
				'add_new_item'  => __( 'Add New Album', 'atelier' ),
				'edit_item'     => __( 'Edit Album', 'atelier' ),
				'menu_name'     => __( 'Albums', 'atelier' ),
			),
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => 'edit.php?post_type=' . $parent,
			'show_in_rest'       => false,
			'hierarchical'       => false,
			'has_archive'        => false,
			'query_var'          => true,
			'capability_type'    => 'post',
			'supports'           => array( 'title', 'author', 'revisions' ),
			'rewrite'            => array(
				'slug'       => $this->slug( 'album', self::ALBUM_SLUG ),
				'with_front' => false,
			),
		);
	}

	/**
	 * Returns the registration arguments for the image tag taxonomy.
	 *
	 * @return array Arguments for register_taxonomy().
	 */
	private function tag_args() {
		return array(
			'labels'            => array(
				'name'          => __( 'Image Tags', 'atelier' ),
				'singular_name' => __( 'Image Tag', 'atelier' ),
				'search_items'  => __( 'Search Image Tags', 'atelier' ),
				'all_items'     => __( 'All Image Tags', 'atelier' ),
				'edit_item'     => __( 'Edit Image Tag', 'atelier' ),
				'menu_name'     => __( 'Image Tags', 'atelier' ),
			),
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => false,
			'hierarchical'      => false,
			'query_var'         => true,
			'rewrite'           => array(
				'slug'       => $this->slug( 'tag', self::TAG_SLUG ),
				'with_front' => false,
			),
		);
	}

	/**
	 * Returns the post type galleries are stored under right now.
	 *
	 * @param Atelier_Settings $settings Plugin settings.
	 *
	 * @return string Post type name.
	 */
	public static function gallery_type( Atelier_Settings $settings ) {
		return $settings->has_migrated() ? self::GALLERY : Atelier_Repository::GALLERY_POST_TYPE;
	}

	/**
	 * Returns the post type albums are stored under right now.
	 *
	 * @param Atelier_Settings $settings Plugin settings.
	 *
	 * @return string Post type name.
	 */
	public static function album_type( Atelier_Settings $settings ) {
		return $settings->has_migrated() ? self::ALBUM : Atelier_Repository::ALBUM_POST_TYPE;
	}

	/**
	 * Returns the taxonomy image tags are stored under right now.
	 *
	 * @param Atelier_Settings $settings Plugin settings.
	 *
	 * @return string Taxonomy name.
	 */
	public static function tag_taxonomy( Atelier_Settings $settings ) {
		return $settings->has_migrated() ? self::TAG : Atelier_Repository::TAG_TAXONOMY;
	}
}
