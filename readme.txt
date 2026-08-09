=== Atelier ===
Contributors: tstone1
Tags: gallery, photo gallery, image gallery, lightbox, photography
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 26.8.19
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast, responsive photo galleries with a pure-CSS justified grid, a lazy-loaded lightbox, and per-image tag filtering.

== Description ==

Atelier renders photo galleries as a justified grid whose geometry is settled in CSS before the
images arrive, so nothing reflows as the page loads. The lightbox is imported on first click,
which means a page full of galleries costs no JavaScript until somebody opens an image.

= What it does =

* **A justified grid that needs no JavaScript.** Every item is sized and grown in proportion to
  its own aspect ratio, so a row settles at one shared height with no measuring and no layout
  pass after loading. Fixed-column layouts are supported too.
* **Grid images are WordPress derivatives with a `srcset`**, not the full-size original.
* **PhotoSwipe 5, dynamically imported on first click.** Assets are enqueued only once a gallery
  has actually rendered, never site-wide.
* **Per-image tags, filtered server-side.** The filter spans the whole gallery rather than the
  page currently rendered, so a tag that matches nothing on page one still works.
* **Pagination** with AJAX, with a lightbox that can span every page.
* **EXIF display**, read from the metadata WordPress already parsed at upload rather than by
  re-reading the file on every request.
* **Deep links** that name the image rather than its position, so a shared link opens the same
  photograph regardless of the filter or page the recipient lands on.
* **Blocks** for galleries and albums, plus shortcodes.
* Intrinsic `width`/`height` on every image, so nothing shifts as the page loads.

= Migrating from Envira Gallery =

Atelier can read Envira Gallery's records where they lie, without copying or converting
anything, so you can compare the two by toggling a plugin. When you are ready, a migration on
the settings screen moves the galleries onto Atelier's own storage in place: post IDs never
change, so existing shortcodes keep working, and existing permalinks keep resolving. Envira's
original records are left untouched, and the migration can be rolled back from the same screen.

Atelier is **not affiliated with, endorsed by, or connected to Envira Gallery or Awesome
Motive**. "Envira Gallery" is their product and their trademark. Atelier contains no Envira
code; it names Envira only to describe what it can read and what it replaces.

On a site with no Envira history, Atelier serves its galleries from `/gallery/`, `/album/` and
`/gallery-tag/`. A site migrating from Envira keeps Envira's existing paths, so no indexed URL
moves. Both are overridable through the `atelier_url_slugs` filter.

== Installation ==

1. Upload the plugin to `wp-content/plugins/atelier` and activate it.
2. Galleries appear under **Atelier** in the admin menu. Create one, add images, and drag to
   order them.
3. Embed it with the **Atelier Gallery** block, or with `[atelier-gallery id="123"]`.

If Envira Gallery is installed, Atelier stays out of its way: the takeover setting under
**Settings → Atelier** defaults to handling `[envira-gallery]` only while Envira is inactive.

== Frequently Asked Questions ==

= Does it need a page builder or a build step? =

No. There is no bundler and no npm dependency at runtime. PhotoSwipe is bundled.

= Is the source of the bundled JavaScript included? =

Yes. Nothing here is generated: every file the plugin ships is the source. PhotoSwipe 5.4.4 is
vendored under `assets/vendor/photoswipe/` with its MIT licence, and the unminified `.esm.js`
sources sit beside the `.esm.min.js` files they were minified from.

= Will migrating from Envira change my URLs? =

No. The migration renames post types in place and pins the URL paths to the ones already
published, so permalinks and shortcodes keep resolving to the same galleries.

= Can I go back? =

Yes. The migration leaves Envira's own records untouched and can be rolled back from the
settings screen, which restores the original post types rather than reconstructing them.

= Where are per-image tags stored? =

On the attachment, as a taxonomy, so tagging an image affects it everywhere it appears rather
than only in one gallery.

== Screenshots ==

1. A justified gallery with the tag filter above it.
2. The gallery editor, with drag-to-order and per-image details.
3. The lightbox, showing EXIF for an image.

== Changelog ==

= 26.8.19 =
* Pagination, tag filtering and the lightbox no longer stop working on sites that use full-page
  caching, where the nonce a logged-out visitor holds is routinely older than the page.
* Deep links to a single image that were shared before the plugin was renamed resolve again.

= 26.8.18 =
* Generic URL paths by default for new installs; sites with an Envira history keep the paths
  they already publish, recorded once rather than re-derived.
* Bundled translations are loaded explicitly, so they apply on WordPress 6.x as well as 7.
* Fixed converted custom CSS targeting a gallery's wrapper, which produced a selector that
  matched no element.
* A failed pagination request now says so instead of silently keeping the previous page.

= 26.8.15 =
* Galleries and albums are centred in the content column again.

== Upgrade Notice ==

= 26.8.19 =
Recommended for any site using a page cache: gallery pagination and filtering kept working only
for as long as the cached page's nonce was valid.
