<?php
/**
 * Plugin container: constructs the collaborators and wires them to WordPress.
 *
 * @package Atelier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns object construction and hook registration for the whole plugin.
 *
 * Nothing else in Atelier calls `add_action`/`add_filter` at construction time, so this
 * class is the single place to read to learn what the plugin does to a site.
 */
class Atelier {

	/**
	 * Reads galleries and albums out of the Envira post meta.
	 *
	 * @var Atelier_Repository
	 */
	private $repository;

	/**
	 * Turns a gallery into front-end markup.
	 *
	 * @var Atelier_Renderer
	 */
	private $renderer;

	/**
	 * Registers and enqueues styles and scripts.
	 *
	 * @var Atelier_Assets
	 */
	private $assets;

	/**
	 * Handles the shortcodes.
	 *
	 * @var Atelier_Shortcode
	 */
	private $shortcode;

	/**
	 * Registers the two block-editor blocks.
	 *
	 * Handed the shortcode rather than a repository and a renderer, because that is what it
	 * renders through — see `Atelier_Block` for why a fifth publishing path was given no way to
	 * publish anything on its own.
	 *
	 * @var Atelier_Block
	 */
	private $block;

	/**
	 * Serves paginated pages over AJAX.
	 *
	 * @var Atelier_Ajax
	 */
	private $ajax;

	/**
	 * Settings screen and option access.
	 *
	 * @var Atelier_Settings
	 */
	private $settings;

	/**
	 * Registers the post types and taxonomy galleries live in.
	 *
	 * @var Atelier_Post_Types
	 */
	private $post_types;

	/**
	 * Renders a gallery on its own permalink.
	 *
	 * @var Atelier_Standalone
	 */
	private $standalone;

	/**
	 * The migration onto Atelier's own storage.
	 *
	 * @var Atelier_Migration
	 */
	private $migration;

	/**
	 * The migration's admin screen.
	 *
	 * @var Atelier_Migration_Screen
	 */
	private $migration_screen;

	/**
	 * Edits a gallery's images and settings.
	 *
	 * Handed the repository as well as the settings, because the list column has to report
	 * whichever record is authoritative rather than the one key that only a migrated site has.
	 *
	 * @var Atelier_Editor
	 */
	private $editor;

	/**
	 * Edits an album's member galleries and settings.
	 *
	 * @var Atelier_Album_Editor
	 */
	private $album_editor;

	/**
	 * Builds the object graph.
	 */
	public function __construct() {
		$this->settings         = new Atelier_Settings();
		$this->post_types       = new Atelier_Post_Types( $this->settings );
		$this->repository       = new Atelier_Repository(
			Atelier_Post_Types::gallery_type( $this->settings ),
			Atelier_Post_Types::album_type( $this->settings ),
			Atelier_Post_Types::tag_taxonomy( $this->settings ),
			$this->settings->has_migrated()
		);
		$this->assets           = new Atelier_Assets( $this->settings );
		$this->renderer         = new Atelier_Renderer( $this->assets );
		$this->shortcode        = new Atelier_Shortcode( $this->repository, $this->renderer, $this->settings );
		$this->block            = new Atelier_Block( $this->shortcode, $this->repository );
		$this->ajax             = new Atelier_Ajax( $this->repository, $this->renderer );
		$this->standalone       = new Atelier_Standalone( $this->repository, $this->renderer, $this->settings );
		$this->migration        = new Atelier_Migration( $this->settings );
		$this->migration_screen = new Atelier_Migration_Screen( $this->migration, $this->settings );
		$this->editor           = new Atelier_Editor( $this->settings, $this->repository );
		$this->album_editor     = new Atelier_Album_Editor( $this->settings, $this->repository );

		$this->settings->set_migration_screen( $this->migration_screen );
	}

	/**
	 * Registers every hook the plugin uses.
	 *
	 * @return void
	 */
	public function boot() {
		$this->settings->register();
		$this->post_types->register();
		$this->assets->register();
		$this->shortcode->register();
		$this->block->register();
		$this->ajax->register();
		$this->standalone->register();
		$this->migration_screen->register();
		$this->editor->register();
		$this->album_editor->register();
	}

	/**
	 * Exposes the repository so themes and companion code can read gallery data.
	 *
	 * @return Atelier_Repository The gallery repository.
	 */
	public function repository() {
		return $this->repository;
	}

	/**
	 * Exposes the renderer for themes that want to output a gallery directly.
	 *
	 * @return Atelier_Renderer The renderer.
	 */
	public function renderer() {
		return $this->renderer;
	}
}
