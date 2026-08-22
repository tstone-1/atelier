#!/bin/bash
#
# Proves that deploy.sh's before/after comparison is capable of going red.
#
#   bash tests/deploy-compare-test.sh
#
# `compare` is the last step of the documented release ceremony -- the one that answers "is the
# site still what it was". Until 26.8.25 it printed `changed 1` beside `CHANGED <url>` and
# exited **0**. Measured, not inferred: a two-line fixture with one altered hash produced that
# exact output. Anything scripted around it, branching on `$?`, was green over a page whose
# bytes had moved, and the ceremony's own verification step could not fail.
#
# It also compared row counts against the FIRST capture only, so a URL present in the second and
# absent from the first joined to nothing and was invisible -- a comparison that cannot see what
# appeared is not a comparison of two states.
#
# Six cases, and the first exists to make the other five mean something: a gate that refuses
# everything is as useless as one that refuses nothing, so the control proves it still passes on
# two identical captures.
#
#   A  control     identical captures            -> exit 0
#   B  hash        one body changed              -> exit 1
#   C  status      200 became 404                -> exit 1
#   D  appeared    a url only the after has      -> exit 1
#   E  vanished    a url only the before has     -> exit 1
#   F  malformed   a row with a missing column   -> exit 1
#
# No credentials and no network: it compares two files on disk, which is why `compare` takes its
# inputs as arguments rather than fetching them itself.

set -u

cd "$(dirname "${BASH_SOURCE[0]}")/.."
DEPLOY=tools/deploy.sh
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

fails=0
cases=0

row() { printf '%s\t%s\t%s\n' "$1" "$2" "$3"; }

base() {
	row 'https://example.com/a/' aaaaaaaa 200
	row 'https://example.com/b/' bbbbbbbb 200
	row 'https://example.com/c/' cccccccc 200
}

check() {
	local label="$1" want="$2" before="$3" after="$4"
	local out got

	cases=$(( cases + 1 ))
	out="$( LICHTBILD_DEPLOY_HOST=unused LICHTBILD_DEPLOY_USER=unused bash "$DEPLOY" compare "$before" "$after" 2>&1 )"
	got=$?

	if [ "$got" = "$want" ]; then
		printf '  [OK]   %-46s exit %s\n' "$label" "$got"
	else
		printf '  [FAIL] %-46s exit %s, expected %s\n' "$label" "$got" "$want"
		printf '%s\n' "$out" | sed 's/^/           /'
		fails=$(( fails + 1 ))
	fi
}

base > "$WORK/before.tsv"

# A -- the control. Without it, every case below is satisfied by a compare that always refuses.
base > "$WORK/same.tsv"
check 'identical captures pass' 0 "$WORK/before.tsv" "$WORK/same.tsv"

# B -- a body changed. This is the case that exited 0 for the whole life of the command.
{ row 'https://example.com/a/' aaaaaaaa 200; row 'https://example.com/b/' ZZZZZZZZ 200; row 'https://example.com/c/' cccccccc 200; } > "$WORK/hash.tsv"
check 'a changed hash is refused' 1 "$WORK/before.tsv" "$WORK/hash.tsv"

# C -- a page that stopped answering. Same shape, different column.
{ row 'https://example.com/a/' aaaaaaaa 200; row 'https://example.com/b/' bbbbbbbb 404; row 'https://example.com/c/' cccccccc 200; } > "$WORK/status.tsv"
check 'a changed status is refused' 1 "$WORK/before.tsv" "$WORK/status.tsv"

# D -- present only in the after capture. The direction the old row check could not see.
{ base; row 'https://example.com/new/' dddddddd 200; } > "$WORK/extra.tsv"
check 'a url that appeared is refused' 1 "$WORK/before.tsv" "$WORK/extra.tsv"

# E -- present only in the before capture.
{ row 'https://example.com/a/' aaaaaaaa 200; row 'https://example.com/b/' bbbbbbbb 200; } > "$WORK/missing.tsv"
check 'a url that vanished is refused' 1 "$WORK/before.tsv" "$WORK/missing.tsv"

# F -- a row that is not three columns. Malformed input must not read as "nothing changed".
{ row 'https://example.com/a/' aaaaaaaa 200; printf 'https://example.com/b/\tbbbbbbbb\n'; row 'https://example.com/c/' cccccccc 200; } > "$WORK/malformed.tsv"
check 'a malformed row is refused' 1 "$WORK/before.tsv" "$WORK/malformed.tsv"

printf -- '------------------------------------------------------------------------------\n'
printf 'cases: %s, failing: %s\n' "$cases" "$fails"

[ "$fails" -eq 0 ]
