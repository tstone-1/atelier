<?php
/**
 * Removes Lichtbild's settings when the plugin is deleted.
 *
 * WordPress runs this only on deletion — not on deactivation — and only for a plugin that
 * ships it. Without it, the three options below survive deletion forever, which is exactly the
 * leftover Envira left behind: 37 `envira*` rows still sitting in `wp_options` months after it
 * was uninstalled.
 *
 * WHAT THIS DELIBERATELY DOES NOT DELETE, AND WHY IT WOULD BE DESTRUCTIVE TO
 * =========================================================================
 *
 * **The gallery and album records.** On a migrated site the rows are `lichtbild_gallery` and
 * `lichtbild_album` posts carrying `_lichtbild_gallery` / `_lichtbild_album` meta, and those are the
 * photographs — content, not settings. Deleting the plugin unregisters the post types, so the
 * posts stop being visible; reinstalling makes every one of them reappear untouched. Deleting
 * the meta here would turn "I removed the plugin" into "I destroyed 53 galleries", with no
 * warning and no undo. WordPress's own convention is that uninstall removes a plugin's
 * settings, never the user's content, and this is the case that convention exists for.
 *
 * **Envira's `_eg_gallery_data` and `_eg_album_data`.** Those are what a rollback restores
 * authority to. They are not Lichtbild's to remove under any circumstances.
 *
 * **`envira_gallery_standalone_enabled`.** Lichtbild reads that option before the migration and
 * copies its value into its own; reading a setting never makes it yours to delete.
 *
 * So what is left after deleting Lichtbild is a site whose galleries are intact but unreachable,
 * which is recoverable by reinstalling, rather than a site whose galleries are gone.
 *
 * @package Lichtbild
 */

// Set by WordPress itself when it runs this file. Its absence means the file was requested
// directly, which is the one way this could be called by someone who did not delete anything.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Options Lichtbild writes. Every one of these is a setting, which is why it is safe to remove.
 *
 * Named literally rather than read from `Lichtbild_Settings::OPTION_*`, because none of the
 * plugin's classes are loaded during uninstall — WordPress includes this file alone, with no
 * `lichtbild-gallery.php` before it. A `Lichtbild_Settings::OPTION_SCHEMA` here is a fatal, not a constant.
 */
$lichtbild_options = array(
	'lichtbild_schema_version',
	'lichtbild_takeover',
	'lichtbild_standalone',
	'lichtbild_slug_scheme',
);

foreach ( $lichtbild_options as $lichtbild_option ) {
	delete_option( $lichtbild_option );
}

// The migration screen hands its result to the next request in a per-user transient. One is
// left behind for anyone who ran a migration within five minutes of deleting the plugin.
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall runs once, with no request after it to serve from a cache; transients are keyed by a name pattern that no core API enumerates.
$lichtbild_transients = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options}
	 WHERE option_name LIKE '\\_transient\\_lichtbild\\_migration\\_result\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_lichtbild\\_migration\\_result\\_%'"
);

foreach ( (array) $lichtbild_transients as $lichtbild_transient ) {
	delete_option( $lichtbild_transient );
}
