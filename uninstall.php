<?php
/**
 * Removes Atelier's settings when the plugin is deleted.
 *
 * WordPress runs this only on deletion — not on deactivation — and only for a plugin that
 * ships it. Without it, the three options below survive deletion forever, which is exactly the
 * leftover Envira left behind: 37 `envira*` rows still sitting in `wp_options` months after it
 * was uninstalled.
 *
 * WHAT THIS DELIBERATELY DOES NOT DELETE, AND WHY IT WOULD BE DESTRUCTIVE TO
 * =========================================================================
 *
 * **The gallery and album records.** On a migrated site the rows are `atelier_gallery` and
 * `atelier_album` posts carrying `_atelier_gallery` / `_atelier_album` meta, and those are the
 * photographs — content, not settings. Deleting the plugin unregisters the post types, so the
 * posts stop being visible; reinstalling makes every one of them reappear untouched. Deleting
 * the meta here would turn "I removed the plugin" into "I destroyed 53 galleries", with no
 * warning and no undo. WordPress's own convention is that uninstall removes a plugin's
 * settings, never the user's content, and this is the case that convention exists for.
 *
 * **Envira's `_eg_gallery_data` and `_eg_album_data`.** Those are what a rollback restores
 * authority to. They are not Atelier's to remove under any circumstances.
 *
 * **`envira_gallery_standalone_enabled`.** Atelier reads that option before the migration and
 * copies its value into its own; reading a setting never makes it yours to delete.
 *
 * So what is left after deleting Atelier is a site whose galleries are intact but unreachable,
 * which is recoverable by reinstalling, rather than a site whose galleries are gone.
 *
 * @package Atelier
 */

// Set by WordPress itself when it runs this file. Its absence means the file was requested
// directly, which is the one way this could be called by someone who did not delete anything.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Options Atelier writes. Every one of these is a setting, which is why it is safe to remove.
 *
 * Named literally rather than read from `Atelier_Settings::OPTION_*`, because none of the
 * plugin's classes are loaded during uninstall — WordPress includes this file alone, with no
 * `atelier.php` before it. A `Atelier_Settings::OPTION_SCHEMA` here is a fatal, not a constant.
 */
$atelier_options = array(
	'atelier_schema_version',
	'atelier_takeover',
	'atelier_standalone',
	'atelier_slug_scheme',
);

foreach ( $atelier_options as $atelier_option ) {
	delete_option( $atelier_option );
}

// The migration screen hands its result to the next request in a per-user transient. One is
// left behind for anyone who ran a migration within five minutes of deleting the plugin.
global $wpdb;

$atelier_transients = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options}
	 WHERE option_name LIKE '\\_transient\\_atelier\\_migration\\_result\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_atelier\\_migration\\_result\\_%'"
);

foreach ( (array) $atelier_transients as $atelier_transient ) {
	delete_option( $atelier_transient );
}
