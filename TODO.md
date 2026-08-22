# TODO

What is open, and why it is worth doing. `CHANGELOG.md` says what shipped, `AGENTS.md` says why
the code is the way it is; this file is the third question — what is known to be missing.

An entry earns its place by being a decision someone would otherwise have to rediscover. Things
that are merely imaginable do not belong here, and an entry that turns out to be wrong should be
deleted rather than left to rot.

## Test harness

Five entries here were closed in 26.8.9 — the undeclared conditional checks, the unguarded nulls,
`image has src`, the unnamed collateral and the three unasserted screen branches. The one that
had been promoted above them, the uncommittable fixture, was closed in 26.8.10 by
`tests/make-fixture.php` and the CI it made possible. What they cost and what generalises is in
`AGENTS.md`; what remains is below. 26.8.10 opened two findings of its own and closed both in
the same release — the four per-item checks that assumed every item has a live attachment, and
the diagnostic cascade that inflated a dozen red sets.

- **`markup parses` is pinned by no mutation, and it may not be pinnable.** It resisted every
  single-edit mutation under DOMDocument's tolerant HTML parser. Reported rather than contrived,
  which is the right answer — but it is worth one more attempt with a mutation that emits an
  unclosed element in a position the parser cannot recover from, before concluding the check is
  decorative.

- **The `VANISHED` verdict compares name sets, and that half is not mutation-proved — because it
  cannot be, yet.** It was a count first, which would have called a mutation that removed one
  check and added another "unchanged"; it compares `array_diff_key` both ways now, which is
  strictly stronger and cost four lines. But **no mutation can currently produce that net-zero
  case**: check names are string literals in `render-test.php`, and mutations only edit
  `includes/`, so nothing under mutation can rename a check. The one-directional case *is* proved
  — deleting one `expect()` entry turns `B25` red with the name printed. Recorded rather than
  quietly claimed: the guard is sound by inspection and unproven by execution, and those are
  different things. It becomes reachable the day any check name is derived from production code.

- **A check can keep its row and lose most of its population, and nothing watches for that.**
  Raised by an independent review of 26.8.9, verified, and left open deliberately.
  `every renderable item became a figure` examines 53 — 51 real galleries plus 2 synthetic — so a
  mutation that stops the real galleries reaching it leaves the name in the report backed by 2
  assertions. `[EMPTY]` catches a population that falls to *zero*; a partial collapse has nothing
  watching it, and the mutation can still report `KILLED` off the synthetic remainder.

  **The obvious fix is wrong and was measured before being rejected.** Making a population change
  a verdict would fail correct mutations: `B52` shifts three populations by legitimately making
  one more gallery render. So `--names` prints the deltas as information instead. What is still
  missing is a rule that separates a legitimate shift from a collapse — probably "a check whose
  population falls by more than half while still passing", which is a heuristic and wants a real
  example before being encoded. Do not encode one without a case that would have caught something.

- **The unearned-pass class is closed for the checks that had it, but nothing stops the next
  one.** Both instances found so far — the cover pair and the three round trips — were a check
  comparing against a value the failure path also produces (`0`, `''`). Each was closed
  individually and each needed a mutation to find, because a check that passes for the wrong
  reason is invisible in a green run.

  What would generalise is an audit rather than another fix: for each check, ask what the
  helper it reads from returns when it finds nothing, and whether the assertion can tell that
  from a pass. `lichtbild_render_found()` answers `''`, `lichtbild_album_found()` answers an empty
  album, and the stubs answer `false` for an unknown ID — three known sources of a
  plausible-looking wrong answer. Doing that sweep properly means reading all 238 checks against
  those three, which is a session's work and worth more than it sounds.

- **A stub that ignores an argument is the same defect one layer down, and one was found by
  accident.** `get_posts()` ignored `post_status` entirely until 26.8.14, so a picker asking for
  published galleries only — every draft silently missing — was unconstructible, and mutation
  `BK6` SURVIVED against a stub with no opinion about it. It was found because a *new* mutation
  went unexpectedly green, not by anyone auditing the stubs.

  The generalisation is cheap to state and real work to do: for each stub, list the arguments it
  accepts and does not read, because each one is a property of the production call that cannot
  be got wrong. `wp_localize_script()` is still a no-op taking three, and `wp_register_style()`
  was one until this release.

## The block editor

- **Closed 26.8.21: both blocks were used in a browser, on a fresh install.** Inserter offers
  both, the picker lists the gallery, the preview renders it server-side, and clicking the
  preview *does* select the block --- `pointer-events: none` makes the element transparent to the
  click, which lands on the `useBlockProps()` wrapper above it, exactly as this entry predicted
  from how the property is specified. Prediction and observation agreed, which is worth recording
  precisely because it means the reasoning was sound rather than lucky.

  What replaced it is a bigger gap: **that session ran against a WordPress built for it, and
  nothing re-runs it.** `tests/blocks-js-test.js` still only proves the script parses and
  registers. A browser check of the editor is not in CI and would need a WordPress in CI to be.

## Code

- **`Lichtbild_Config::from_envira()`'s `$id` now exists solely to be passed to the
  `lichtbild_config_from_envira` filter.** That is a legitimate seam, but it is a thinner
  justification than the parameter's presence suggests, and the docblock that claimed it was used
  for the CSS rewrite was wrong for months. Worth a look the next time that function is opened.

- **`Lichtbild_Settings::render_page()` calls into `Lichtbild_Post_Types` while `Lichtbild_Post_Types`
  holds a `Lichtbild_Settings`.** A static-level cycle, harmless today, and worth not deepening: it
  means the options store renders UI that knows about the type registrar.

- **`render_result()` assumes every element of `$errors` is a string.** A non-string would warn
  in `esc_html()`, exactly as it would have in the `implode()` this replaced. Pre-existing rather
  than introduced, and only reachable from code we control.

## Live site

**Closed 2026-08-14, and the answer was better than the task.** This section opened with "move the
sixteen galleries' custom CSS into the Customizer before 26.8.22 reaches the site". `tools/export-custom-css.py`
ran against the live database — its first real run, the SQL and unserialise paths having been
unexercised — and exited clean: Envira's copy and Lichtbild's agreed everywhere, so nothing was
lost when the feature went. Reading the 145 lines it produced is what mattered: **every
declaration in all twenty blocks is commented out**, and 16 of the 20 target `#lichtbild-2423`
whichever gallery holds them. The browser parsed an empty rule; the removal changed no pixel;
there is nothing to paste. Full account in [`docs/deploys.md`](docs/deploys.md).

**The inventory of what a particular site still has lying around moved to `AGENTS.local.md`,
which is gitignored** — it is a list of one deployment's leftover rows, terms and cron events,
and it says nothing about the plugin. Nothing here depends on it.

One item in it generalises and stays, because it is a fact about Lichtbild's own code rather than
about any site: **on a site with an Envira history, `Lichtbild_Settings::standalone()` falls back to
Envira's `envira_gallery_standalone_enabled` option when `lichtbild_standalone` is unset.** So a
tidy-up that deletes Envira's leftover options from such a site, without Lichtbild's own option
being set, turns every gallery permalink into an empty page with no error anywhere. The dependency
is invisible from the options table, which is exactly why it is written down rather than left to
be rediscovered.

Since 26.8.21 that fallback is consulted **only** where it means something: a site with no Envira
history returns `true` outright, because there is neither an option to inherit nor a migration to
have copied one, and inheriting Envira's "off" default gave a brand-new gallery a permalink with
no photographs on it.

## Documentation

- **`AGENTS.md` refers to 26.8.3, which never existed as a released version.** Albums gained a
  record of their own under that number, but the album editor landed before anything was
  deployed, so the version constant went 26.8.2 to 26.8.4 directly. The prose is describing a
  milestone rather than a release; it should say so.
