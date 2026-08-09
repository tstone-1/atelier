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
  from a pass. `atelier_render_found()` answers `''`, `atelier_album_found()` answers an empty
  album, and the stubs answer `false` for an unknown ID — three known sources of a
  plausible-looking wrong answer. Doing that sweep properly means reading all 220 checks against
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

- **Nobody has looked at the blocks in a browser.** The script is proved to run and register
  (`tests/blocks-js-test.js`), the editor page is proved to carry it, and the render callbacks
  are proved to delegate — but every one of those is a fact about *code*, and 26.8.11 is the
  entry recording that two live defects were invisible to 207 checks because the markup was
  right and the rendering was not. Insert both blocks once after the next deploy: the picker,
  the preview, and specifically that `pointer-events: none` on the preview does not stop the
  editor selecting the block itself. It should not: the property makes the element transparent
  to pointer events, so the click lands on the `useBlockProps()` wrapper above it, which is what
  Gutenberg selects on. That is a prediction from how the property is specified, not an
  observation, and it is the one interaction the mock cannot model.

## Code

- **`Atelier_Config::from_envira()`'s `$id` now exists solely to be passed to the
  `atelier_config_from_envira` filter.** That is a legitimate seam, but it is a thinner
  justification than the parameter's presence suggests, and the docblock that claimed it was used
  for the CSS rewrite was wrong for months. Worth a look the next time that function is opened.

- **`Atelier_Settings::render_page()` calls into `Atelier_Post_Types` while `Atelier_Post_Types`
  holds a `Atelier_Settings`.** A static-level cycle, harmless today, and worth not deepening: it
  means the options store renders UI that knows about the type registrar.

- **`render_result()` assumes every element of `$errors` is a string.** A non-string would warn
  in `esc_html()`, exactly as it would have in the `implode()` this replaced. Pre-existing rather
  than introduced, and only reachable from code we control.

## Live site

**The inventory of what a particular site still has lying around moved to `AGENTS.local.md`,
which is gitignored** — it is a list of one deployment's leftover rows, terms and cron events,
and it says nothing about the plugin. Nothing here depends on it.

One item in it generalises and stays, because it is a fact about Atelier's own code rather than
about any site: **`Atelier_Settings::standalone()` falls back to Envira's
`envira_gallery_standalone_enabled` option when `atelier_standalone` is unset.** So a tidy-up that
deletes Envira's leftover options from a site that never set Atelier's own turns every gallery
permalink into an empty page, with no error anywhere. The dependency is invisible from the options
table, which is exactly why it is written down rather than left to be rediscovered.

## Documentation

- **`AGENTS.md` refers to 26.8.3, which never existed as a released version.** Albums gained a
  record of their own under that number, but the album editor landed before anything was
  deployed, so the version constant went 26.8.2 to 26.8.4 directly. The prose is describing a
  milestone rather than a release; it should say so.
