# Atelier

A WordPress gallery plugin that replaces Envira Gallery Pro on `timo-stein.com`, so the
site stops depending on a ~100 EUR/year licence it no longer pays for and is running an old
version of.

**Prepared for public release, GPL-2.0-or-later**, with `LICENSE`, `README.md` and a
wordpress.org-format `readme.txt`. **Still private as of 26.8.19** — the repository has not been
made public and nothing has been submitted to wordpress.org; it passes that directory's official
Plugin Check with zero errors, which is readiness rather than submission. The two rules below are
what being publishable makes non-negotiable rather than tidy:

- **Nothing that identifies the deployment target goes in a tracked file** — see *Site access*.
  The hostname, the FTP account and the table prefix live in gitignored files, and the reason is
  that *together* they are reconnaissance even though no single one is a password.
- **Envira is named only nominatively**, to say what is read and what is replaced, and the plugin
  header, `README.md` and `LICENSE` all state that this is not affiliated with or endorsed by
  Envira Gallery or Awesome Motive. It contains no Envira code, which was established by
  comparison rather than by assertion: every source file here against all 481 Envira PHP, JS and
  CSS files, and not one pair of lines in common.

> **Called Tivira through 26.8.15, renamed to Atelier in 26.8.16.** The old name was one letter
> from Envira's in the same product category, which a disclaimer cannot cure. This file, the
> changelog and the deploy records name everything by its *current* identifiers throughout,
> including in entries describing releases that shipped under the former name — the alternative
> is documentation that cannot be grepped against the code it documents. Read a class or file
> name in an old entry as "the thing now called that". Dates, counts and measured numbers are
> untouched. The rename itself is recorded in `CHANGELOG.md` and `docs/deploys.md`.

> **This file is the index; `docs/lessons.md` is the corpus.** It is read in full before
> every task and it only grows, so an entry that is worth keeping and is only worth reading
> once you are already in its area lives in [`docs/lessons.md`](docs/lessons.md) — verbatim,
> in its original order — and leaves one line here. The per-release deploy records are in
> [`docs/deploys.md`](docs/deploys.md). Knowing that a trap *exists* is most of its value;
> a one-line hook is never enough to avoid it, so read the entry before working in its area.
>
> `tests/docs-index-test.php` is the guard, and it runs in CI: this file has a ceiling, every
> corpus file it links to must be present and non-empty, and every entry it names must still
> exist as a heading in exactly one of them. The second of those is the one that catches a
> mistake made in a hurry — the index is tracked and the corpus was not, so committing this
> file alone would leave a fresh clone with an index pointing at nothing.

## Two generations, and both are live

**v1 is a drop-in reader.** It renders Envira's own rows in place, changing nothing. That is
still how an un-migrated site behaves, and it is what makes the switch reversible.

**v2 owns the data.** `Atelier_Config` defines twenty-six normalised settings and converts
Envira's ~281 keys into them; `Atelier_Migration` renames the post types in place and writes
the converted record alongside the original. After that the reader contains no Envira
knowledge at all — which is the difference between a migration and a rename.

`Atelier_Repository` reads either shape: on a migrated site a `_atelier_gallery` record wins,
otherwise `_eg_gallery_data` is converted on the fly. So the two generations coexist, and the
migration is not a cliff. **The "on a migrated site" is load-bearing** — see *What the second
review changed, on the write path* in [`docs/lessons.md`](docs/lessons.md) for what it cost
to leave it out.

### What independence actually required

Not the editor — the **registrations**. Galleries, albums and image tags are custom types,
and a custom type exists only while a plugin registers it. Envira owns three live URL spaces:

| URL | what |
|---|---|
| `/envira/<slug>/` | gallery permalinks — canonical, Yoast-indexed, HTTP 200 |
| `/envira_album/<slug>/` | album permalinks |
| `/envira-tag/<slug>/` | tag archives |

Delete Envira without replacing those registrations and all of it 404s. `Atelier_Post_Types`
takes them over, and **the type names change at migration while the URLs never do** —
`rewrite['slug']` stays pinned either way.

**Since 26.8.17 those paths are Envira's only on a site that has an Envira history.** A site with
none serves `/gallery/`, `/album/` and `/gallery-tag/`, and since 26.8.18 it also starts already
on Atelier's own post types rather than registering names it has no reason to carry. Both answers
are decided from one observation and recorded once, never re-derived — a site's Envira history
changes when its old records are deleted, and its published URLs must not change with it.
`atelier_url_slugs` filters the paths on top of that.

### Migration invariants

- **Post IDs never change.** It is `UPDATE ... SET post_type`, not a copy, so every
  `[envira-gallery id="N"]` still names the same row and no permalink moves. A
  create-and-import design would need an ID map forever.
- **Nothing is destroyed.** `_eg_gallery_data` is untouched; the converted record is written
  under a new key. Rollback restores the post types and forgets the new key — no data is
  reconstructed because none was lost.
- **`plan()` is separable from `migrate()`**, so the confirmation screen counts come from the
  code that does the work.
- **The rewrite flush is not optional.** Rules are generated from the types registered at
  flush time, so skipping it 404s every gallery until someone re-saves permalinks — which
  looks exactly like the migration having broken the site.

**Deeper entries — full text in [`docs/lessons.md`](docs/lessons.md):**

- *The screen that runs it* — why it is not part of the settings form, why its
  post/redirect/get is load-bearing — the request that renames the types registered the old
  ones — and why every guard is in the handler rather than the markup.
- *Recovering from a migration that dies half-way* — there is no transaction. Rollback is gated
  on the rows and never on the flag, because the flag is exactly what a half-finished run gets
  wrong; and `(int)` on `$wpdb->update()` turns a failed statement into "nothing needed doing".
- *Registration must not defer to Envira once the data has moved* — `register_types()` standing
  aside while Envira is active is right up to the migration and wrong after it — nothing else
  registers `atelier_gallery`. The guard is `! $migrated && ...`.

## The editor, and why it requires the migration

`Atelier_Editor` is the last thing that kept Envira installed for **galleries**. Everything else
about a gallery had moved across; the only way to *change* one was still Envira's screen. Albums
followed two releases later — see *And the album editor, since 26.8.4* in
[`docs/lessons.md`](docs/lessons.md), which is this class's twin and had to wait for albums
to have a record of their own.

**It writes v2 records, so it refuses to run before the migration.** That is the same rule
the repository enforces, seen from the writing side: a v2 record is authoritative only on a
migrated site, so an editor that wrote one earlier would save happily and change nothing a
visitor sees. Rather than have two writable representations of one gallery — the state the
write-path review already had to eliminate once — the edit screen on an un-migrated site
says what to do instead of pretending to work.

That also settles the order of operations, which was not obvious before the editor existed:
**deactivate Envira, migrate, and only then is the site editable.** There is no window
without an editor, because the migration is what turns Atelier's on.

**That whole paragraph describes a site migrating from Envira, and until 26.8.18 the code applied
it to every site.** A fresh install has no Envira storage to still be on, so it now starts
migrated and edits immediately; the rule above is a fact about continuing an Envira installation,
not about the plugin. Full text: *The 26.8.18 deploy, which fixed the install nobody here has
ever done* in [`docs/deploys.md`](docs/deploys.md).

Three properties of the save path are load-bearing:

- **An absent nonce field means the request is not ours, and the response is to do nothing —
  silently.** `save_post` fires for quick edit, bulk edit, status changes and autosaves, none
  of which carry the images; reading a missing `atelier_items` as "the user removed every
  image" would empty a gallery on a quick-edit of its title. Note that removing this guard
  does *not* actually let such a request through — the nonce verification two statements
  later refuses an absent nonce as readily as a wrong one, and a mutation of the first
  survived until the check also asserted **no PHP warning**. That is the property only the
  first guard has, and `save_post` fires often enough that a warning per quick edit is a log
  nobody can read.
- **Order is submitted explicitly, in `atelier_order`, not inferred from the field order.**
  The browser serialises in DOM order and PHP preserves it, so it would usually be right —
  and "usually" is the wrong standard for the one property a drag-and-drop editor exists to
  set. Rows the order does not name are dropped, which is also how removal works.
- **Image tags are attachment terms, not gallery data**, so editing them changes that image
  everywhere it appears. That is what keeps the filter meaning the same thing on every page,
  and it is stated on the screen rather than left to be discovered.

**The media picker has to be told an image's existing tags, or adding it clears them.** A
row created from the media library would otherwise submit an empty tag field, and an empty
field that means "unchanged" is indistinguishable at the server from one that means "remove
them all" — so the fix is that the field is never blank by accident.
`Atelier_Editor::attachment_tags()` puts them in `wp_prepare_attachment_for_js`, gated on the
frame having been opened from one of our galleries because it costs a term lookup per
attachment and the media library is used all over the admin. The server also treats a row
with **no** `tags` key as "leave them alone", which is the belt to that brace and is checked
directly.

**`Atelier_Config::sanitize()` is not `fill()` with sanitising bolted on**, and confusing the
two is a silent bug in the interesting direction. They answer opposite questions about an
absent key: a *stored* record is missing one because the version that wrote it predated it,
so the default is right; a *submitted form* is missing one because an unchecked checkbox
sends nothing, so a default of `true` would make that box impossible to switch off.

**Deeper entries — full text in [`docs/lessons.md`](docs/lessons.md):**

- *Albums own their data too, since 26.8.3* — albums spent a release looking migrated while
  still being read in Envira's format. Two conversions are deliberately unfaithful because
  Envira's own record is wrong — and a stored value nothing reads is not neutral when the code
  that would read it prefers it to the truth.
- *And the album editor, since 26.8.4* — the picker is a list of galleries rather than the
  media library, and a cover outside its member gallery is refused in `save()` rather than
  merely not offered: a chooser is markup, and markup is a suggestion.

## What an independent review found, before the migration (26.8.5)

Seven findings, all fixed, four of which would have bitten only *after* the migration — which
is the whole argument for reviewing before running it. Full text in
[`docs/lessons.md`](docs/lessons.md).

- *Three of those hid behind the test harness, and that is the more useful half* — each was
  reachable only once the stubs stopped modelling WordPress wrongly. A payload builder is part
  of the code under test.

## The original v1 decision: a drop-in, not a migration

Atelier does not own its data. Galleries stay exactly where Envira put them — post type
`envira`, post meta `_eg_gallery_data` — and Atelier reads them in place. Nothing is
migrated, nothing is written back, and no gallery is edited to make the switch.

That buys the property that matters on a live site: **switching is a plugin toggle in both
directions.** Deactivate Envira and Atelier takes over `[envira-gallery]`; reactivate Envira
and it takes it back. Comparing the two is a page refresh, and backing out costs nothing.

The takeover is a setting (Settings -> Atelier), defaulting to `auto`:

| mode | behaviour |
|---|---|
| `auto` | handle `[envira-gallery]` only while Envira is inactive (default) |
| `always` | handle it even when Envira is active — for A/B on one page |
| `never` | only `[atelier-gallery]` renders through Atelier |

`[atelier-gallery id="N"]` always renders through Atelier regardless of the setting.

Consequence worth stating: **on an un-migrated site the admin UI is still Envira's.** In v1
Atelier had no editor at all, so editing a gallery meant keeping Envira installed — an
accepted trade, since the site's galleries are years old and rarely edited. That is still
the situation before the migration; afterwards `Atelier_Editor` takes over, and the section
above explains why it cannot take over any earlier.

## What the site actually uses

Measured against the live database, not assumed. This is what scoped v1.

- **52 galleries** carrying data (50 publish, 1 private, 2 draft), **2,264 items**,
  2,243 distinct attachments, **all plain images** — no video items exist despite
  `envira-videos` being active.
- **Embedded only as `[envira-gallery id="N"]`**, in 49 published posts. No blocks, no
  widgets, no `[envira-album]` anywhere.
- 3 albums (`_eg_album_data`), 2 published, reachable only by permalink.
- Layout: `columns = 0` + `isotope = 1` + `justified_row_height = 150` — justified rows —
  on 49 of 52. `base` gallery theme, `base_dark` lightbox.
- Pagination on 47/52, 10 per page, ajax + scroll, lightbox spanning all pages.
- Right-click protection on 47/52. Sharing in the lightbox on 50/52. EXIF on 5.
- `envira-tag`: 58 terms, 104 assignments — **on attachments**, i.e. genuinely per-image.
- Effectively unused, one gallery each: zoom, slideshow, printing, watermarking, downloads.

Of the 18 active Envira plugins, about six carry real weight.

## Envira's storage, as it actually is

- Gallery record: `_eg_gallery_data` = `{id, gallery, config}`. Not `_eris_…`, which is what
  the Envira docs and older blog posts suggest and cost a wrong first guess here.
- `gallery` is keyed by **attachment ID**; each item is
  `{status, src, title, link, alt, caption, thumb, mobile_thumb, tags}`. `src`/`link` are a
  frozen copy of the **full-size** URL from when the image was added.
- `status` is `active` or `pending`; pending items are not displayed.
- `config` carries ~281 keys per gallery, nearly all of them defaults its settings screen
  wrote out. Only about 60 vary at all across the site, and most of those vary because one
  single gallery is an outlier.
- **Envira serialises booleans inconsistently** — `1`, `'1'`, `'True'`, `true` all appear,
  and `keyboard` is literally split between `'1'` (32 galleries) and `'True'` (20).
  `Atelier_Config::flag()` exists for this; never compare a config value directly.
- Envira keeps its **site-wide defaults in a gallery of its own**, marked `config.type =
  'defaults'`. `Atelier_Repository` skips it, or it renders as an empty grid.
- **Per-image tags are not in the item record.** The Tags addon migrated them into the
  `envira-tag` taxonomy on the attachment — the `_processed_tag_upgrade` meta on 51
  galleries records that having happened — and the item's own `tags` key is now empty
  everywhere. Read the taxonomy, not the item.
- 16 galleries carry hand-written `custom_css` keyed on `#envira-gallery-<id>`. **Atelier no
  longer reads it** — see *The wordpress.org review, and the feature it cost* below. It survives
  in **two** records that can disagree — Envira's permanently, Atelier's own only until that
  gallery is next saved — and `tools/export-custom-css.py` reads both.

## Security posture

Independent read-only review, 2026-08-07: no critical findings, and the anonymous AJAX
authorization, attribute escaping and settings handling were confirmed sound. Three things
that review changed, all of which had looked fine:

- **Escaping a value into an HTML attribute does not sanitize it.** The caption travels to
  the browser through an `esc_attr`-escaped data attribute and is then inserted with
  `innerHTML` — and `getAttribute()` hands back the original string, so the escaping that
  made the attribute safe does nothing for the parse that follows. `Atelier_Item::caption()`
  applies `wp_kses_post()` at the source instead.
- **Envira's frozen `src`/`link` are database strings, not URLs.** They are used whenever an
  attachment has been deleted, and the JSON endpoint emits them without `esc_url()`. Both
  now go through `Atelier_Item::safe_url()`, which allows only `http`/`https`.
- **Items with unknown dimensions are kept out of the lightbox**, rather than handed to
  PhotoSwipe as `0x0` slides for its zoom arithmetic to divide by. They stay in the grid as
  ordinary links that open the file.

**The two front-end AJAX endpoints verify their nonce and never refuse on it (since 26.8.19), and
that is a decision rather than an omission.** A nonce says when a page was generated and expires
twelve hours later, while a full-page cache serves pages generated days ago — so refusing would
break pagination and filtering on cached sites at an unpredictable hour, with no error the owner
could act on. It costs nothing because both endpoints are reads that change no state and return
JSON no cross-origin script can read: the authorization is `is_viewable()`, which had to hold on
its own anyway. `Atelier_Album_Editor::handle_covers()` is an admin path, is never cached, and
does still refuse.

**Deeper entries — full text in [`docs/lessons.md`](docs/lessons.md):**

- *What the second review changed, on the write path* — four write-path defects found before
  anything touched production, the first of them a comment asserting a safety property that
  nothing enforced.
- *There are three places that publish a gallery, and the rule was copied into two of them* —
  an album published a protected gallery's cover while its own permalink and both AJAX
  endpoints correctly refused. Count the publish paths, not the fixes.
- *The rule is `Atelier_Repository::is_viewable()`, and extracting it found two more gaps* — one
  predicate would otherwise mean one mutation, so they split into three that delete a **call
  site** and two that break a **leg** — and a stub answering for one of two post types will
  invent a status for the other.

## Traps already paid for

- **Envira's per-field EXIF toggles are load-bearing, and they are all off for identity
  fields.** Across all 52 galleries `exif_lightbox_make`, `_model` and `_capture_time` are
  `0` while aperture, shutter speed, focal length and ISO are `1`. A renderer that prints
  everything WordPress can parse therefore prints the camera body on every gallery whose
  settings say not to. `Atelier_Exif::fields()` takes the enabled set; it does not decide.
- **`tags_all` is a per-gallery, site-owner-translated string** — it is `Alle` on every
  gallery here, not `All`. The stored label wins over the plugin's own translation.
- **Deep links name the image, not its position.** The fragment is
  `#atelier-<galleryId>-i<attachmentId>`. An index is only meaningful together with the filter
  and page it was taken under, neither of which is in the URL, so an index-based fragment
  opens a different photograph for anyone whose filter differs — including the same visitor
  after a reload. Resolving one fetches the unfiltered item list, because the linked image
  may be on a page the grid has not rendered.
- **A tag filter has to span the whole gallery, not the rendered page.** The filter bar
  lists every tag in the gallery while the grid shows one page, so 17 of 40 buttons on the
  test gallery filtered a paginated grid down to nothing. Filtering is therefore server-side
  (`Atelier_Gallery::filtered_items()`, and `page_count()`/`page_items()` both take the tag),
  and the AJAX response re-renders the pagination nav because the server is the side that
  knows how many pages the filter leaves. A DOM-hiding filter cannot be made correct here.
- **WordPress already has the EXIF.** It parses it at upload and stores it in
  `image_meta`. Envira's addon re-reads the original file per request to get the same
  values. `lens` is the one field that genuinely is not in `image_meta`; it is off
  everywhere here and is deliberately unsupported rather than bought with a file read.

## Deliberate improvements over Envira

- **The justified grid is pure CSS.** Each item is both grown and sized in proportion to its
  own aspect ratio, so within a flex row every item lands at width `aspect * k` and
  therefore at height `k`. Envira needed isotope, which cannot lay out until the images have
  loaded; here the geometry is settled before the images arrive, so there is no reflow. The
  last row is kept at its natural size by a `::after` spacer with an enormous `flex-grow`.

  Note the precise claim: **the geometry is in the CSS, not necessarily in the first paint.**
  Shortcodes run during `the_content`, after `wp_head`, so a stylesheet enqueued at render
  time is printed in the footer by `print_late_styles()` and the first paint can be unstyled.
  `Atelier_Assets::maybe_enqueue_early()` looks for the shortcode in the post content during
  `wp_enqueue_scripts` to get the stylesheet into the head for the ordinary case; a gallery
  rendered from a widget or a template call still falls back to the late enqueue.
- **Grid images are WordPress derivatives with a srcset**, not the original. Envira's
  `src` is the full-size file: 239 KB versus 72 KB for the `medium_large` derivative on a
  sample image, per grid thumbnail.
- **PhotoSwipe 5 is dynamically imported on first click**, so a page of galleries costs no
  JavaScript until someone opens an image. Assets are enqueued only once a gallery has
  actually rendered; Envira enqueued site-wide.
- Intrinsic `width`/`height` on every image, so nothing shifts as the page loads.
- **The caches are primed per gallery, not per image.** Reading an item touches three things
  WordPress caches per post — the attachment row (the title and excerpt an item falls back to),
  its meta (dimensions and registered sizes) and its terms (the tag filter). Unprimed that is
  three queries per image, and `Atelier_Ajax::handle_items()` walks *every* item because the
  lightbox spans pages on 47 of the 51 galleries: roughly fifteen hundred queries on the
  504-image gallery, in the request a visitor waits on to open the lightbox.
  `Atelier_Gallery::prime()` reduces it to three.

  Two details are load-bearing. It is called with **the set about to be walked**, not always
  with everything — a paginated gallery renders ten items, and priming five hundred to read ten
  is the same mistake pointing the other way — which is why `handle_page()` primes all items
  only when a tag is applied, and the renderer only when the tag bar is on. And in
  `handle_items()` it runs **before** the filter, because deciding which items carry the tag is
  itself what reads every item's terms.

  Testing this needs a word of warning: **priming changes no output at all**, so deleting it
  leaves every other check green. `_prime_post_caches()` is recorded by the stub and the check
  asserts the *shape* of the call — one call covering every attachment, rather than none and
  rather than one per image. Mutation `A1` is the proof.

## Testing

There is no WordPress in the loop. `tests/wp-stubs.php` implements the ~25 functions Atelier
calls, backed by a fixture exported from the live database, and `tests/render-test.php`
renders **every gallery on the site** and asserts the markup.

```sh
# 1. one-off: tests/.db.json with the wp-config.php values (gitignored). Its "host" is NOT
#    wp-config.php's DB_HOST — that says localhost. Use the hosting account's own name.
uv run --with pymysql --with phpserialize python tests/export-fixture.py
php tests/render-test.php

# or, with no database and no credentials — this is what CI and a fresh clone run:
php tests/make-fixture.php
php tests/render-test.php tests/fixture-synthetic.json
```

220 checks over 51 galleries and 529 rendered items. Each is reported with the **population
it examined**.

That last property needs `Checks::expect()` to be true, and it is worth understanding why.
A check only exists once an assertion runs, so a conditional area — EXIF, say — does not
report `0 examined` when it stops matching; it **disappears from the report entirely**,
which reads as "not applicable" and is indistinguishable from coverage silently lapsing.
Conditional checks are therefore declared up front, and a declared check that examined
nothing is reported `[EMPTY]` and counts as failing. Mutation `M20` is the proof: it switches
the EXIF area off and four checks go red instead of vanishing.

**Deeper entries — full text in [`docs/lessons.md`](docs/lessons.md):**

- *Declaring conditional checks by hand does not scale, and the count is the instrument* — a
  check that stops running disappears from the report rather than failing, and the generic
  instrument is the **set of check names** compared against baseline in both directions. Also
  holds the mutation harness's own honesty rules, the four checks pinned by nothing and why,
  and the fatal that pre-empted the check that should have failed.
- *And since 26.8.10 there is a second corpus, built by `tests/make-fixture.php`* — so a fresh
  clone and CI can run every check with no database — and why the generator is committed while
  its output is not.
  - *The measurement that says it is worth anything* — the row that means something is the
    pinned-check **set**, not the kill count; and a claim about what a corpus fails to cover
    needs the full red set of every mutation.
  - *Five shapes the corpus was missing, and how each was found* — each found by a check going
    red or a mutation losing a red — never by reading the suite.
  - *Three defects in the suite that only a second corpus could expose* — a gallery named by
    ID, an unguarded division that fatals rather than fails, four per-item checks that encoded
    "every item has a live attachment" without saying so — and one PHP warning that silently
    re-scored every check after it, in the direction nobody audits.
  - *CI, which is the whole point* — five PHP versions, the generator run twice and `cmp`d, and
    `git diff --exit-code` after the mutation pass.
- *PHP versions: test the one the site runs, not the one the Mac has* — the site is 8.2.30 and
  this Mac is 8.5. A version that is not installed is `[SKIP]`, never a pass, and a run with no
  summary line is `[BROKEN]`.
- *A public read endpoint cannot be gated on a nonce, and the harness said it could (26.8.19)* —
  a stub returning `true` unconditionally models code that cannot get the answer wrong, and
  correcting it turned four passing checks red at once. Holds the deep-link shim too, and what a
  test of a closed IIFE can and cannot see.

## The local WordPress

```sh
bash tools/devenv.sh setup     # build it from a dump of the live database
bash tools/devenv.sh start     # database on 3307, site at http://localhost:8080
bash tools/devenv.sh reset     # restore to the snapshot, in seconds
bash tools/devenv.sh status    # what is running, and which post types the rows are under
```

Lives at `~/Developer/wp-atelier`, outside this repo. WordPress **7.0.3** and PHP **8.2** to
match the live site, MariaDB **10.11** to match its engine, and the plugin is symlinked in so
an edit here is live there with nothing to sync.

It exists for the five things the stub suite structurally cannot reach: real `$wpdb` against
the production engine, real rewrite-rule generation, real object and term caches, the real
Envira plugin to coexist with, and the admin screen actually rendering. It does not replace
the stub suite — that runs in seconds with no infrastructure and covers 220 properties.

**Deeper entries — full text in [`docs/lessons.md`](docs/lessons.md):**

- *The editors, against real infrastructure* — 26 checks across two scripts, and the
  precondition is checked before anything is changed — `reset` restores production's
  `active_plugins`, with Envira running.
- *The one thing even that does not prove: the browser round trip* — an identical render is
  also what a save that never ran produces, so the control is the whole check — and WordPress
  prints some of its own hidden inputs with single quotes.
- *The full round trip, on real infrastructure* — 53/3/58 moved and rolled back
  byte-identically, with the control that says "identical" is not vacuous. One count
  discrepancy that was Envira's doing: count in one process or not at all.
- *Five traps, four of them the harness lying rather than the code* — `localhost:3307` discards
  the port, PHP CLI writes errors to stdout, the built-in server has no rewrite engine, and
  opcache serves the build you just replaced — pointing at doing nothing.
- *Two failures the setup script itself had, and both are this project's recurring shape* — it
  printed `ready` over a wall of failures, and read an HTTP 200 as a working page over a
  zero-byte body.
- *What has to come from the server, and why* — the theme and all twenty `envira-*` directories
  over read-only FTPS; uploads deliberately not fetched, which is why Envira's own stylesheet
  is a primary source on disk.

## Local preview

Renders three real galleries — justified with pagination and EXIF, one with the tag filter
forced on, one fixed-column — with pagination, filtering and the lightbox all live, because
the preview server answers the two AJAX endpoints out of the fixture.

```sh
ATELIER_FIXTURE=tests/fixture.json php -S 127.0.0.1:8765 -t . tests/preview-server.php
open http://127.0.0.1:8765/
```

Images load from `timo-stein.com` itself (uploads are not hotlink-protected), so the preview
needs a network connection and touches nothing on the server.

## The live switchover, and the three bugs it found

Done 2026-08-07: Atelier uploaded, activated, and Envira's 18 addons deactivated.
`timo-stein.com` now renders every gallery through Atelier. **At the time this was written the
migration had not been run** — the site was still on v1, reading Envira's own rows in place. It
was migrated later the same day, and Envira was uninstalled after that; see the two sections
below.

The result is worth the trouble: gallery permalinks went from **395 KB to 60 KB**, and every
one of the 57 gallery, album and tag URLs answers 200 with the same photographs as before.

**Every one of the three bugs below was found by a control, not by a test.** Each had passed
the whole suite, the mutation pass and the real-WordPress checks, because each needed a state
the local environment had never been in — Atelier and Envira *both active*, and a page nobody
had thought to capture.

- **Both plugins active rendered every gallery permalink twice.** `Atelier_Standalone` appended
  its gallery without consulting `should_take_over()`, so it ran alongside Envira's own
  standalone filter. This is the state a fresh install is in, and the one the takeover setting
  exists for. It was live for about a minute; the fix is in `applies()`.
- **Album permalinks rendered an empty page.** `Atelier_Standalone` only ever handled galleries,
  and an album keeps its galleries in post meta for the same reason a gallery keeps its images
  there — so `/envira_album/<slug>/` answered **200** with nothing on it. Two published URLs,
  and a regression rather than a missing extra, because Envira's albums addon does render them.
- **A password-protected gallery would have been published in full.** WordPress enforces a post
  password by replacing `post_content`, which reaches no part of a gallery, so
  `Atelier_Standalone` would have appended every image directly beneath the password form — and
  `Atelier_Ajax` would have served them to anyone with the gallery ID, because a protected post
  is `publish` status and `read_post` does not consider the password either. Found by reading
  the live database during the pre-flight: exactly one gallery on this site is protected, and
  the post embedding it is protected too.

Four things generalise past this deployment:

- **Deploy in a state where the change should be nothing, and check that it is nothing.**
  Activating Atelier with Envira still running and `takeover=auto` is meant to be a complete
  no-op. It was not, and a byte comparison against the pre-deploy capture said so in seconds.
  A rollout with no such step would have shipped the duplication.
- **Capture the before-state of every URL *space*, not a sample of one.** Ten probe URLs were
  captured and none of them was an album, which is exactly why the album regression reached
  production. The list should come from the database — every post type and taxonomy the plugin
  registers — rather than from what came to mind.
- **A comparison needs the un-switched site to compare against, and after the switch it is
  gone.** The album regression could only be measured by putting Envira back *locally* and
  fetching the same URL. Keep the local copy able to run both plugins.
- **The fingerprint has to be semantic, not byte-for-byte.** Atelier's markup is deliberately
  different, so the comparison is the set of upload filenames with size suffixes stripped: the
  same photographs, however they are wrapped.

**Deeper entries — full text in [`docs/lessons.md`](docs/lessons.md):**

- *Fixed since: the early enqueue now matches only the shortcodes we claim* — "it did not
  enqueue" is also what a scan matching nothing produces, so both directions are asserted.
- *Closed in 26.8.6: the shortcode was the fourth publishing path* — a product decision blocked
  on an unknown cost is usually blocked on an unrun query.
- *And the fifth publishing path, added 26.8.14, which arrived already asking* — `Atelier_Block`
  renders nothing itself and hands to the shortcode, so the visibility rule has no second
  implementation to drift from — and the coverage does **not** follow from the design, which
  measurement said rather than reasoning.
  - *The one real defect in this release, and no check would ever have found it* — the picker's
    choices were built on `init` — 111 queries on every front-end request, changing no rendered
    byte, so every instrument here was blind. The first measurement was a warm-cache artifact.
  - *Three things the harness could not see until it was told to* — a stub that ignores an
    argument models code that cannot get that argument wrong; and a `block.json` is a source
    file for translation that no tokenizer can see.
  - *`tests/blocks-js-test.js`, the only JavaScript this repo tests* — if `blocks.js` throws,
    both blocks are absent from the inserter and every live check still passes. Restore by
    copy, not `git checkout`.
  - *What real WordPress said, and the artifact it produced first* — a migration performed
    later in the same request leaves every object built earlier naming the rows they used to
    name — the post-types trap, one layer further in.

## The migration, run on the live site 2026-08-07

`timo-stein.com` now runs on Atelier's own storage. 53 galleries, 3 albums and 58 image tags
moved; 51 gallery records and 2 album records were converted. Envira's records are untouched —
52 `_eg_gallery_data` and 3 `_eg_album_data` still there — which is what keeps rollback real.

**159 of 159 URLs are semantically identical to the pre-migration capture, and all 159 answer
200.** Both albums render every cover from their own v2 record; the protected gallery still
serves its form with zero markup and zero upload filenames; the AJAX endpoint still refuses it
with a valid nonce while a public gallery returns its items; all 104 tag-to-attachment
relationships survived the taxonomy rename.

**Verify a migration semantically, never byte for byte.** The post type is in WordPress's own
body and post classes, so `single-envira` becomes `single-atelier_gallery` on every page and a
byte hash reports 100% changed while telling you nothing. `tools/deploy.sh` now carries both:
`capture` (whole-page hash, right for a deploy, where nothing should move) and `fingerprint`
(the upload filenames with size suffixes stripped, the tile count, and the document title —
right for a migration). Using the wrong one fails in whichever direction is least convenient.

**The document title is in that fingerprint because the image set alone is blind on 60 of the
159 URLs** — 58 tag archives render no thumbnails, plus the protected gallery. Without a title
the strongest thing "unchanged" could mean for those is "still empty", which a page that *broke*
into an empty one satisfies just as well. Adding it took the fingerprint from 52 distinct values
to 114, largest group 2. It also turned out to be the only reason the one real regression was
visible at all.

**Deeper entries — full text in [`docs/lessons.md`](docs/lessons.md):**

- *The regression was Yoast's, and the pre-flight should have found it* — Yoast keys its
  settings on the registered name, so renaming the taxonomy dropped the canonical from 58
  indexed URLs. Ask what **else** keys off the names you are renaming; print the plan, do not
  apply it.

## Envira is gone (2026-08-07)

All twenty `envira-*` plugin directories were deleted from the server. The plugins directory now
holds eleven plugins and Atelier; `active_plugins` names no Envira anything.

**This is the moment the project's central claim was actually tested.** Nothing but Atelier
registers `/envira/`, `/envira_album/` and `/envira-tag/` now, and all **159 URLs still answer
200 with the same photographs as before the migration** — compared against a capture taken while
Envira was still installed and rendering. The registrations were the whole argument; here is the
evidence.

**Rollback still works, and that was checked rather than assumed** — it is now the only recovery
path, and the obvious worry is that it restores rows to post types nothing registers.
`register_types()` stands aside only when `! $migrated && envira_is_active()`, so with Envira
absent it registers whichever names the schema says: roll back and it registers `envira`,
`envira_album` and `envira-tag` itself, which are exactly the names the restored rows are under.
Confirmed against a real WordPress rather than read off the source.

Uninstalling it leaves harmless debris behind — orphaned `wp_options` rows, a scheduled event
with no handler, a taxonomy nothing registers. None of it is read by anything and none of it is
worth a production write on its own. **The inventory of what one particular site still carries is
in the gitignored `AGENTS.local.md`**, along with the one entry in it that is load-bearing.

Envira's own gallery and album records are deliberately still there. They are what rollback
restores authority to, and they cost nothing.

## Deploying to the live site

The plugin is not in the site's plugin list by default and has to be uploaded. There is no
shell on the host, so it goes over FTPS, and **that transport drops large transfers often
enough that a deploy has to be verified rather than trusted.**

Since 26.8.2 this is `tools/deploy.sh` rather than a recipe followed by hand. The first two
deploys were ad hoc, and both of the mistakes below — an unchunked upload that took the site
down, a size check that cannot see an equal-length change — were mistakes the *procedure* was
supposed to prevent and the person running it did not. A rule written down as a caution gets
nodded at; the same rule written as a line of code gets followed.

```sh
uv run --with pymysql python tools/live-urls.py > urls.txt   # from the database, not memory
bash tools/deploy.sh plan                     # order, and the greps that justify it
bash tools/deploy.sh capture before.tsv urls.txt
bash tools/deploy.sh push                     # chunked, every file digest-verified
bash tools/deploy.sh capture after.tsv urls.txt
bash tools/deploy.sh compare before.tsv after.tsv
```

Three properties are worth knowing before changing it. **The upload order is asserted, not
trusted** — `plan` re-derives which file defines a method and which call it, and refuses to run
if the list contradicts that; swapping two entries turns it red, which has been checked.
**`compare` asserts the join's own sanity**, because a previous deploy's ad-hoc version compared
the hash column against the status column and reported all 116 URLs as changed. And the
password is read from the keychain straight into a `.netrc` in a 0700 temp dir that is removed
on exit, so it never reaches an argument list, a log line, or a transcript.

- `curl` reports `426` (server closed the data connection) on maybe a quarter of files above
  ~15 KB. It is **not deterministic and not a size limit**: a 189 KB file went first time,
  while an 18 KB one failed seven attempts in a row and a 31 KB one succeeded on the fifth.
  Nothing in the file's content predicts it.
- **A failed transfer leaves a file of the wrong length on the server, not no file.** Three
  PHP files landed at **0 bytes** and one JavaScript file at exactly **16,384** — a block
  boundary, which is the tell that a stream was cut rather than refused. A 0-byte
  `class-atelier-config.php` is invisible until someone activates the plugin, and then it is a
  fatal error on a public site.
- So: **never trust curl's exit code.** The exit code was right that first time, but only
  because the abort happened to be reported.
- Verify **every** file, not the ones that reported an error. The first pass reported seven
  failures and a size audit found the same seven — but that audit was written after a first
  version that labelled every *local* file "ok" without checking the server at all, which is
  the same empty-filter-reads-as-a-pass trap the test notes are full of.
- **Comparing byte counts is not enough, and this section used to say it was.** A file whose
  new version happens to be the same length as the old one passes a size check while still
  holding the old content — and that is not a freak case: a version string of equal width
  (`26.8.0` → `26.8.1`) and a block moved within a file both produce it, and both occurred in
  the same deploy. **Verify by digest: download the file back and compare `shasum` against the
  local copy.** Size is a cheap first gate; the digest is the only thing that establishes what
  is on the server.

**The deploy records — one per release that reached the site, newest first. Full text in
[`docs/deploys.md`](docs/deploys.md):**

- *The 26.8.21 deploy, whose release was found on an install that had never been done* — a
  deliberate no-op on the only site it reached, which is the recommended shape; `UPLOAD_ORDER`
  held the previous release's list **again**, after that was written down, because the note
  says re-derive and the script says nothing; and a control that grepped a `.php` URL measured
  the host's willingness to execute PHP, not the file.
- *The 26.8.20 deploy, where "0 changed" was RIGHT and my reason for expecting otherwise was
  wrong* — `capture` strips `?ver=` before hashing, so it is structurally blind to the only
  page-level evidence a CSS-plus-version release produces; a prediction derived from an
  assumption about your own instrument is the instrument agreeing with itself. What
  established the release was a live contrast measurement, and the control that says the
  probe can still see the defect.
- *The 26.8.19 deploy, where "0 changed" was again the prediction and again proves nothing* —
  neither change touches a rendered byte, so `capture` was going to say 160/160 whether the
  upload landed or never happened. Four before-and-after behavioural controls instead, and the
  `-1` in their "before" column is the bug reproduced from the command line.
  - *The ordering constraint that is not about PHP, now a line of code rather than a note* — an
    asset must precede `atelier.php`, which carries the `?ver=` it is cached under; no grep can
    see that edge, and it bound twice in five releases. `plan` now refuses it.
- *The 26.8.18 deploy, which fixed the install nobody here has ever done* — schema 1 means "still
  on Envira's storage", not "new", so a fresh install registered post types NAMED `envira` and
  refused to edit; 26.8.17 had fixed the paths and left the names.
  - *Two answers derived separately were wrong; one observation fixed it* — the fix's own first
    draft gave a brand-new install Envira's paths, because "migrated" had come to mean two things.
  - *A fix that hands the user a new footgun* — a fresh site is now migrated, so it must be
    refused the rollback it would otherwise be offered.
- *The 26.8.17 deploy, whose riskiest line ran on the server and decided something* — the slug
  scheme writes an option on first use and every gallery URL depends on the answer; it derived
  `envira` unaided, and the control is that `/gallery/<slug>/` does **not** serve.
  - *One ordering constraint, invisible to `plan` for the third release running* — a method
    arriving on an existing class, called on every front-end request. Three releases in a row now,
    which makes it the normal case rather than the exception.
- *The 26.8.16 deploy, which was a first install, a data rename, and a plugin swap* — ordering is
  moot when the directory is inactive, and `plan` says so instead of claiming satisfaction;
  `uninstall.php` had shipped three releases earlier and never reached the server.
  - *The sequence, and why it needed no new migration code* — a SQL rename would have corrupted
    16 galleries, because the stored CSS selectors sit inside PHP-serialised data where a string
    replace changes the length without the length prefix.
  - *Every number that came back "changed" was an instrument, and one of them was mid-sequence* —
    a tile counter keyed to a hardcoded class name, Yoast losing 58 canonicals to the rollback,
    and a protected-gallery check that had picked the wrong gallery entirely.
  - *The setting that does not travel with a rename* — a rename orphans every option keyed on the
    old name, and a fallback chain hides it until the thing it falls back to is deleted.
- *The 26.8.15 deploy, where "0 changed" was the prediction and proves nothing on its own* — a
  deploy that never landed produces the same number, because `capture` throws away the one thing
  this release changes. Four controls that answer what the comparison cannot.
  - *The bug was found by a person looking at the site, for the third time* — the Envira control
    that made it a regression rather than a preference, and the first time the local copy's
    ability to run both plugins has been cashed in.
  - *The control I nearly used instead would have said the opposite* — a Wayback snapshot whose
    theme stylesheet had not truly applied; ask whether a stylesheet's **effects** are present,
    not whether the stylesheet is.
  - *One ordering constraint, and it is not about PHP* — the stylesheet before `atelier.php`, or
    browsers cache the old file under the new `?ver=` and nothing corrects it until the next bump.
- *The 26.8.14 deploy, and the URL that had never once been checked* — the front page renders
  ten galleries and was in no deploy's verification surface; three checks failed and all three
  were my own instrument.
  - *The front page renders ten galleries and was in no deploy's verification surface* —
    enumerate the URL *spaces* a plugin can appear in, not a sample of the ones that came to
    mind.
  - *Three checks that failed and were all my own instrument* — a glob counted as an image, a
    "no-gallery" page with ten galleries, and a refusal that refused in German.
- *The 26.8.11 deploy, the first with an ordering constraint `plan` cannot express* — the
  constraint is on a returned array key, which no grep can see. Identical was the wrong
  expectation, and the semantic instrument was not taken — decide which one the release needs
  at `plan` time, not after the upload.
- *The 26.8.10 deploy, where the stale thing was the deploy script itself* — `UPLOAD_ORDER`
  still held the previous release's file list and `plan` cheerfully validated it. The version
  of record is what the server serves, not what the last release commit says.
- *The 26.8.8 deploy, which is what a boring one looks like when it is predicted* — no ordering
  constraint, derived and not hoped; identical required in advance, `ver=` the one thing
  required to differ.
- *The 26.8.7 deploy, where `plan` passed an order that would have taken the site down* —
  `abstract class` broke the name extraction and removed both spellings the consumer search
  relied on; `continue` on "I could not determine that" is a silent pass.
- *The 26.8.6 deploy, where the VERIFICATION TOOL was the thing that broke* — `live-urls.py`
  returned 49 URLs instead of 159 after the migration, and every downstream step would still
  have reported success.
- *The 26.8.5 deploy, which had no ordering constraint at all — and how that was established* —
  "no constraint" is a derived fact, and whether a require is new is a fact about the server
  rather than about this checkout.
- *The 26.8.4 deploy, and the constraint that inverted* — `atelier.php` went third rather than
  last. Re-derive `UPLOAD_ORDER` every release; do not edit the last one.
- *The 26.8.2 deploy, which was the first one that was boring* — identical was predicted from
  two queries against production, which is what makes 159/159 a pass rather than an ambiguity.
- *The 26.8.1 deploy, which took the site down for a few minutes* — whole-file upload failed 6
  of 6 and left the file at 0 bytes, which is a fatal on every page. Chunk by default; it is
  not the fallback.

## German, and why the catalogue needs a test of its own (26.8.13)

The site is `lang="de"` and 28 visitor-facing strings had rendered in English since the
switchover. A catalogue rots on the next string added and the symptom is invisible, so it has a
test in CI. Two of its four lessons were superseded at 26.8.14 by `wp i18n make-pot`. Full text
in [`docs/lessons.md`](docs/lessons.md).

## Two defects a person found by looking at the site (26.8.11)

The lightbox never filled the viewport and album covers left a hole — both live since the
switchover, both invisible to 207 checks and 187 mutations, because the markup was right and
the failure was in what it means to a browser. Full text in
[`docs/lessons.md`](docs/lessons.md).

- *Changing `display` orphans every rule that targets the old layout model (26.8.12)* —
  `grid-template-columns` is inert on a flex container: no error, no warning, no fallback. CSS
  has no output to assert on, so the instrument is a rendering engine — `--headless --dump-dom`
  plus a `getBoundingClientRect()` probe, measured at three stylesheets, not two.

## Site access

**The hostname, account and table prefix are deliberately not in this repository**, which is
public. An FTP hostname plus its username is two thirds of a login, and stating that the database
port answers from outside tells a reader exactly where to aim the third. None of it is secret in
the sense of being a password — and that is the point worth keeping: *the reason to withhold it is
that together it is reconnaissance, not that any one line is a credential.* A repository that
publishes its own deployment target has done an attacker's first hour of work.

They live in two gitignored places instead, both machine-local:

- `tools/deploy.env` — `ATELIER_DEPLOY_HOST` and `ATELIER_DEPLOY_USER`, read by `tools/deploy.sh`.
  Everything else in that script is parameterised off those two, the keychain lookup included, so
  there was exactly one place to change.
- `tests/.db.json` — the database connection for `tools/live-urls.py` and `tests/export-fixture.py`.
  Its `host` is **not** `wp-config.php`'s `DB_HOST`, which says `localhost`; use the hosting
  account's own name.

The password is never in either. It is in the macOS login keychain, looked up by host and account
at the moment of use, piped straight into a `.netrc` in a 0700 temp dir that is removed on exit —
never rendered, never an argument, never a log line.

**The hosting shape and the stack versions moved to `AGENTS.local.md`, which is gitignored.**
They are facts about the target rather than about the plugin, and the rule stated above applies to
them for the same reason it applies to the hostname: no single line is a credential, and together
they describe one named site's hosting. What still has to be said here, because the code only
makes sense with it: **there is no shell on the host**, which is why deployment is FTPS and why
every uploaded file has to be verified by digest rather than by exit code or size.

**If `deploy.sh` refuses with `set ATELIER_DEPLOY_HOST and ATELIER_DEPLOY_USER`, that is this
change working, not a broken script.** Recreate `tools/deploy.env` with those two lines.

## Submitting to wordpress.org — submitted 2026-08-09, **pended 2026-08-14**

**26.8.21 was uploaded and the directory assigned the slug `atelier`**, confirming
`https://wordpress.org/plugins/atelier/` as the eventual permalink. That URL is not live yet and
will not be until a human approves the plugin: it currently 301s to
`wordpress.org/plugins/search/atelier/`, which is worth knowing as the *expected* pre-approval
shape — it is not a 404, so a 404 check would report the wrong thing.

**The submission was pended by an automated pre-review on 2026-08-14** (`AUTOPREREVIEW
atelier/tstone1/14Aug26/T1`) — before any human read it, and it is a *reply* to that thread, not
a fresh upload, that puts the plugin into a named volunteer's queue. Four findings, all
addressed in 26.8.22; the one with teeth cost a feature.

- *The wordpress.org review, and the feature it cost* — a plugin may not store and print CSS
  entered through its own UI, so the setting, its conversion, its sanitiser, the textarea and the
  `<style>` all went together; nothing was destroyed, because Envira's record still holds it.
  - *The CRLF checkout, which had been quietly disabling the mutation harness* — found while
    running it, not part of the review: no `.gitattributes`, so every **multi-line** mutation
    matched nothing on Windows. The harness said `BROKEN`, which is the only reason it was
    visible.

That earlier note saying no reply was needed described the **submission confirmation**, and it
stopped being true the moment this arrived: silence on a pended submission is a rejection after
three months, not a queue position.

**The live risk is now email, not code.** The team states that the account address must stay
operational, must not autorespond, and must not mark their mail as spam — and that a forwarder,
alias or group address must allow-list `plugins@wordpress.org` too. `mail@timo-stein.com` is on
All-Inkl (MX `v100427.kasserver.com`), so the allow-list that matters is the mailbox's own
SpamAssassin configuration in KAS, upstream of any rule in Apple Mail. A review thread that dies
in a spam folder looks exactly like a queue that is simply slow.

What follows was verified against wordpress.org rather than recalled, because most of it is the
kind of thing that gets assumed.

- **The WordPress.org account is `tstone1`, and it will never match the GitHub handle.**
  wordpress.org usernames cannot contain a hyphen, so `tstone-1` is not registrable there and the
  two handles are permanently different. `readme.txt`'s `Contributors: tstone1` is therefore
  **correct and must not be "corrected"** to match the repository — an edit that looks like fixing
  an inconsistency would name a user who cannot exist. Whitelist `plugins@wordpress.org`: the
  review thread arrives by email and dies silently if filtered.

  Registered 2026-08-09, and this entry said the opposite the day before — `profiles.wordpress.org/tstone1/`
  answered 404 on 2026-08-08 and answers 200 now, with `.../zzq-no-such-user-9182/` still 404 as the
  control that the endpoint discriminates rather than answering 200 to everything. The probe was
  right both times; the world moved. Worth stating because a fact recorded as "checked" carries a
  date for exactly this reason, and *account does not exist* is the one fact in this section that a
  single action by the user can invert overnight.
- **The slug `atelier` is assigned, and it is permanent once approved.** It was derived from the
  plugin's display name at submission. It can still be changed from the Plugin Submission page
  **until a reviewer begins**, and never afterwards; the display name stays changeable either way.
  `api.wordpress.org/plugins/info/1.2/` still 404s for it, which is correct for an unapproved
  plugin rather than evidence of a problem — the control that the endpoint works is the same call
  for `akismet`, which returns full data. Two unrelated "Atelier …" plugins by another author
  already exist, which is not a conflict but does mean the name is not distinctive there.
- **The four screenshots exist, in `.wordpress-org/`, and are not in the zip.** They go into an
  `assets/` directory at the root of the SVN checkout once there is one, as `screenshot-1` and so
  on, matching the order `readme.txt` describes. That directory is the SVN root's, **not the
  plugin's own `assets/`**, and confusing the two ships two megabytes of listing images to every
  installation.
- **`Plugin URI` resolves.** `github.com/tstone-1/atelier` was made public on 2026-08-09, from a
  squashed root commit; the full pre-squash history is private in `tstone-1/atelier-history`. The
  two decisions were separate — wordpress.org requires neither of the other — and this one was
  taken for its own reasons.
- **Plugin Check reports 0 errors and 0 warnings on the submitted archive**, run through the
  official plugin against the actual zip on a clean WordPress. The team's own email says automated
  tools have false positives and miss things, so this is a floor rather than a prediction: the
  41 warnings it used to report were all either analysis limits or deliberate decisions, and each
  now carries the justification in the code where a reviewer meets it.

Two guidelines are worth knowing before a reviewer raises them, and both are now satisfied.
**Guideline 17** forbids a trademark as the "sole or initial term of a plugin slug" — `atelier`
is clear — and asks for "Dancing Sloths for Superbox" phrasing rather than the reverse. Neither
name leads with Envira: the plugin header's description opens "Responsive galleries for
WordPress." and `readme.txt`'s short description does not mention Envira at all. The Envira
sentence that follows is nominative use and permitted. **Guideline 4** wants documented access to
source: the unminified PhotoSwipe sources ship beside the minified ones, and a `readme.txt` FAQ
entry now says so rather than leaving it to be noticed.

Process, once the account exists: zip exactly what `deploy.sh` ships, upload at
`wordpress.org/plugins/developers/add/`, review within **14 business days**, then an SVN repo
where the code goes in `trunk/`, is copied to `tags/<version>/`, and `Stable tag` names the tag.

**The gap no checklist covers: nobody has ever installed this plugin from scratch and made a
gallery.** The fresh-install path was written in 26.8.18 and is covered by the suite and by a
stripped local WordPress, but that is the first thing every stranger does and it has only ever
been exercised by a machine.

## Conventions

- CalVer `YY.M.MICRO`, matching `screenpick`/`tpdf`. Version lives in the `atelier.php`
  header and the `ATELIER_VERSION` constant — **both must agree**.
- **`CHANGELOG.md` says what changed; this file says why.** The split is stated because two
  places describing one release is how prose drifts, and drift is this project's most expensive
  habit. A release note is a fact about behaviour and belongs in the changelog even when it is
  dull; a trap, a rejected alternative, or what a verification actually cost belongs here even
  when it is long. Neither is a summary of the other, so neither goes stale by being unread.
  The deploy records carry reasoning rather than behaviour, so they are entries like any
  other: one line here, full text in [`docs/deploys.md`](docs/deploys.md).
- WordPress coding standards: tabs, `snake_case`, Yoda conditions, full docblocks on every
  class, method and property.
- Escape at output, never at assignment. `Atelier_Renderer::attributes()` is the one place
  that decides between `esc_url` and `esc_attr`.
- No build step. The JavaScript is a classic script that dynamically imports PhotoSwipe;
  there is deliberately no bundler, no npm dependency at runtime, and PhotoSwipe 5.4.4 is
  vendored under `assets/vendor/photoswipe/` with its MIT licence.
