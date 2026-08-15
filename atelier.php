<?php
/**
 * Plugin Name: Atelier
 * Plugin URI:  https://github.com/tstone-1/atelier
 * Description: Responsive galleries for WordPress. Reads existing Envira Gallery data in place, so galleries keep working without migration or a licence.
 * Version:     26.8.23
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

/*
 * There is no `load_plugin_textdomain()` call, and its absence is the finished state of a
 * condition the call itself carried: "remove it once the bundled catalogue is dropped in favour
 * of the directory's, not before." The distributed plugin no longer ships a `.po`/`.mo`, so that
 * is now the case.
 *
 * Translations come from translate.wordpress.org, which delivers them into
 * `WP_LANG_DIR/plugins/` -- the one place every WordPress 6.x and 7.x just-in-time loader reads
 * without being told to. That is why the call was unnecessary from 4.6 onward for anything the
 * directory serves, and it is what the directory's own guidelines ask for.
 *
 * `Domain Path` stays in the header. It is not vestigial: a copy of this plugin installed from
 * somewhere other than wordpress.org can still carry a catalogue in `languages/`, and on WP 7
 * the textdomain registry finds it from that header alone -- verified on WP 7.0.3, which is what
 * the site this was built for runs, and the reason its German kept working once this call went.
 * Confirmed on that site in production after the 26.8.22 deploy, not only locally: the rendered
 * page ships `Schließen`, `Weiter`, `Vergrößern` with no `load_plugin_textdomain()` anywhere.
 * Worth having checked, because the failure mode is silent -- 28 strings revert to English and
 * nothing is logged.
 *
 * **That is verified for WP 7 and for nothing older.** The header still says
 * `Requires at least: 6.0`, and which 6.x release began discovering a plugin's own `languages/`
 * directory unaided has not been established here -- an independent review put it at 6.8 and
 * cited a source that does not exist, so treat it as unknown rather than as 6.8. It costs
 * nothing either way for a plugin the directory serves, because those translations arrive in
 * `WP_LANG_DIR/plugins/`, which every version reads. It matters only for a copy installed from
 * source onto an older WordPress, where the bundled catalogue may silently not load. Settle it
 * on the local WordPress before relying on it.
 */

define( 'ATELIER_VERSION', '26.8.23' );
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
