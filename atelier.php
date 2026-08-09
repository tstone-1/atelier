<?php
/**
 * Plugin Name: Atelier
 * Plugin URI:  https://github.com/tstone-1/atelier
 * Description: Responsive galleries for WordPress. Reads existing Envira Gallery data in place, so galleries keep working without migration or a licence.
 * Version:     26.8.20
 * Author:      tstone-1
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: atelier
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 *
 * Atelier is a drop-in replacement for Envira Gallery Pro. It does not own its data:
 * galleries live in the `envira` post type under the `_eg_gallery_data` post meta
 * exactly as Envira wrote them, and Atelier renders from that. Deactivating Atelier and
 * reactivating Envira is therefore lossless in both directions, which is what makes an
 * A/B comparison on a live site safe.
 *
 * NOT AFFILIATED WITH, ENDORSED BY, OR CONNECTED TO ENVIRA GALLERY OR AWESOME MOTIVE.
 * "Envira Gallery" is their product and their trademark. Atelier is independent, contains
 * no Envira code, and names Envira only to say what it reads and what it replaces. The
 * `envira` post type, the `_eg_gallery_data` meta key and the `/envira/` URL paths appear
 * here because they are the data and the addresses of a site that already exists, and
 * moving either would break it -- interoperability, not association.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads the bundled translations.
 *
 * `Domain Path` in the header plus WordPress's textdomain registry is enough on WP 7, which is
 * what this was verified against -- but the header promises `Requires at least: 6.0`, and every
 * WP 6.x just-in-time loader reads only from `WP_LANG_DIR/plugins/`. A plugin that ships its own
 * `.mo` and is not distributed through wordpress.org therefore renders in English on 6.x, for
 * every string, silently: nothing errors, nothing is logged, the catalogue is simply never asked
 * for. The call costs one line and is a no-op where it is not needed.
 *
 * Hooked on `init` rather than run at file scope: since WP 6.7 loading a textdomain earlier
 * triggers a `_doing_it_wrong` notice, so the fix for one version must not become a warning on
 * another.
 *
 * @return void
 */
function atelier_load_textdomain() {
	load_plugin_textdomain( 'atelier', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

add_action( 'init', 'atelier_load_textdomain' );

define( 'ATELIER_VERSION', '26.8.20' );
define( 'ATELIER_FILE', __FILE__ );
define( 'ATELIER_DIR', plugin_dir_path( __FILE__ ) );
define( 'ATELIER_URL', plugin_dir_url( __FILE__ ) );

require_once ATELIER_DIR . 'includes/class-atelier-config.php';
require_once ATELIER_DIR . 'includes/class-atelier-album-config.php';
require_once ATELIER_DIR . 'includes/class-atelier-item.php';
require_once ATELIER_DIR . 'includes/class-atelier-gallery.php';
require_once ATELIER_DIR . 'includes/class-atelier-album.php';
require_once ATELIER_DIR . 'includes/class-atelier-post-types.php';
require_once ATELIER_DIR . 'includes/class-atelier-repository.php';
require_once ATELIER_DIR . 'includes/class-atelier-exif.php';
require_once ATELIER_DIR . 'includes/class-atelier-renderer.php';
require_once ATELIER_DIR . 'includes/class-atelier-assets.php';
require_once ATELIER_DIR . 'includes/class-atelier-shortcode.php';
require_once ATELIER_DIR . 'includes/class-atelier-block.php';
require_once ATELIER_DIR . 'includes/class-atelier-standalone.php';
require_once ATELIER_DIR . 'includes/class-atelier-ajax.php';
require_once ATELIER_DIR . 'includes/class-atelier-settings.php';
require_once ATELIER_DIR . 'includes/class-atelier-migration.php';
require_once ATELIER_DIR . 'includes/class-atelier-migration-screen.php';
require_once ATELIER_DIR . 'includes/class-atelier-metabox-editor.php';
require_once ATELIER_DIR . 'includes/class-atelier-editor.php';
require_once ATELIER_DIR . 'includes/class-atelier-album-editor.php';
require_once ATELIER_DIR . 'includes/class-atelier.php';

/**
 * Returns the shared plugin instance, booting it on first call.
 *
 * @return Atelier The plugin container.
 */
function atelier() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new Atelier();
		$instance->boot();
	}

	return $instance;
}

add_action( 'plugins_loaded', 'atelier', 20 );
