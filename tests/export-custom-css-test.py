#!/usr/bin/env python3
"""Checks the decision logic in tools/export-custom-css.py.

    python tests/export-custom-css-test.py

Not part of the CI suite, which runs PHP and Node only — this covers a one-shot recovery tool
rather than the plugin. It exists because that tool's first version read only Envira's record,
which returns a stale value for any gallery whose CSS was edited through Atelier after the
migration, and does so while reporting success. A wrong answer that looks right is the whole
reason to test this at all: the older CSS is perfectly valid CSS.

The database work is deliberately not exercised. This imports the module, which is possible only
because the connection is not made at import time for the pure helpers.
"""

import importlib.util
import pathlib
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location("exporter", ROOT / "tools" / "export-custom-css.py")
exporter = importlib.util.module_from_spec(spec)

try:
    spec.loader.exec_module(exporter)
except SystemExit:
    # The module exits when tests/.db.json is absent. Everything under test is defined above
    # that point, so the partially-executed module is still the real code.
    pass
except ImportError as exc:
    sys.exit(f"[SKIP] {exc}; install pymysql and phpserialize to run this")

passed = failed = 0


def check(name, got, want):
    global passed, failed
    if got == want:
        print(f"  [OK]   {name}")
        passed += 1
    else:
        print(f"  [FAIL] {name}\n         got:  {got!r}\n         want: {want!r}")
        failed += 1


A = "#atelier-1 { margin: 0 }"        # what Envira's record holds
B = "#atelier-1 { margin: 4px }"      # what Atelier's editor wrote later

print("choose(): which copy is the site actually rendering")
# The case the first version got wrong.
check("migrated site prefers Atelier's newer copy", exporter.choose(A, B, True)[0], B)
check("...and reports the disagreement", exporter.choose(A, B, True)[2], True)
check("un-migrated site prefers Envira's", exporter.choose(A, B, False)[0], A)
check("identical copies are not a conflict", exporter.choose(A, A, True)[2], False)
check("Envira alone, migrated", exporter.choose(A, "", True)[0], A)
check("Atelier alone, un-migrated", exporter.choose("", B, False)[0], B)
check("neither yields nothing", exporter.choose("", "", True)[0], "")
check("neither is not a conflict", exporter.choose("", "", True)[2], False)

print("rewrite(): Envira's ids onto Atelier's")
check(
    "the wrapper form moves the id PAST the suffix",
    exporter.rewrite("#envira-gallery-wrap-12 {}"),
    "#atelier-12-wrap {}",
)
check(
    "...and the general form is not applied to it first",
    "#atelier-wrap-12" in exporter.rewrite("#envira-gallery-wrap-12 {}"),
    False,
)
check("the plain form", exporter.rewrite("#envira-gallery-7 {}"), "#atelier-7 {}")
check("both in one stylesheet",
      exporter.rewrite("#envira-gallery-wrap-3 a, #envira-gallery-3 b {}"),
      "#atelier-3-wrap a, #atelier-3 b {}")
check("CONTROL: a string with no envira id is untouched",
      exporter.rewrite("#atelier-9 {}"), "#atelier-9 {}")

print("comment_safe(): a title cannot end the CSS comment it sits in")
check("*/ is neutralised", exporter.comment_safe("Trip */ x"), "Trip *\\/ x")
check("CONTROL: an ordinary title is untouched", exporter.comment_safe("Lisbon"), "Lisbon")

print(f"\nchecks: {passed + failed}, failing: {failed}")
sys.exit(1 if failed else 0)
