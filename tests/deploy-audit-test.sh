#!/bin/bash
#
# Proves that deploy.sh's server audit is capable of reaching each of its verdicts, and of
# refusing when it cannot tell.
#
#   bash tests/deploy-audit-test.sh
#
# The audit answers one question -- what does the server actually have? -- and it exists because
# UPLOAD_ORDER, the list of files a release uploads, was the PREVIOUS release's list five times
# in a row. Every deploy record since 26.8.21 says the fix is to ask the server rather than to
# diff a tag, and every one of those audits ran as a throwaway script that was then thrown away.
#
# What makes an audit worth having is that its verdicts are distinguishable, and three of them
# fail silently if they are not:
#
#   - ABSENT read as SAME is a file that never deploys and nobody notices.
#   - UNREADABLE read as ABSENT is "I could not tell" delivered as a finding.
#   - an empty file set read as "nothing differs" is the whole tool reporting a clean bill of
#     health over a universe it never walked. This repository has shipped that defect before:
#     a missing `git` once produced a confident report of 40 differing files and 201 phantom
#     leftovers on a healthy site.
#
# So there are seven cases, each with its outcome declared in advance, and two of them exist
# only to make the other five mean something:
#
#   A  control      the tree matches the local one       -> PASS, changed 0, unreadable 0
#   B  changed      one byte differs                     -> the file is named CHANGED
#   C  absent       a deployed file is missing           -> ABSENT, and it needs uploading
#   D  both ways    UPLOAD_ORDER vs the files that differ -> both directions reported
#   E  unreadable   present but cannot be read           -> UNREADABLE, and it REFUSES
#   F  emptiness    no tracked files at all              -> REFUSES rather than reporting clean
#   G  mutation     case B with the digest compare neutered -> B stops seeing a change, which is
#                   the proof that the digest is what makes B fire
#
# Everything runs against a directory on disk -- no network, no credentials, no deployment
# target -- which is why `audit --against` exists and why this can run in CI.
#
# @package Atelier\Tests

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'chmod -R u+rwX "$TMP" 2>/dev/null; rm -rf "$TMP"' EXIT

# Two ordinary shipped files, neither of them likely to be in any particular release's
# UPLOAD_ORDER. Nothing below asserts against the real list -- case D hands in its own.
VICTIM="includes/class-atelier-item.php"
OTHER="includes/class-atelier-exif.php"

pass=0
fail=0

# A copy of the WORKING tree, not of HEAD: the code under test is uncommitted while this runs,
# and a test that silently exercised the committed version would report on code nobody is about
# to ship.
snapshot() {
	local dest="$1"

	mkdir -p "$dest"
	( cd "$ROOT" && git ls-files -z ) | ( cd "$ROOT" && tar -cf - --null -T - ) | tar -xf - -C "$dest"

	# The copy needs to be a repository, because the audit derives the shipped set from
	# `git ls-files` -- deliberately, so that it and tools/build-zip.sh cannot disagree about what
	# ships. Staging is enough; nothing here needs a commit or an identity.
	git init -q "$dest" 2>/dev/null
	git -C "$dest" add -A 2>/dev/null
}

# make_trees <name> -- a local checkout and a "deployed" tree, the latter holding exactly what
# the server is supposed to hold: everything shipped, minus the files deliberately never
# deployed. $1 receives the pair's directory.
make_trees() {
	local dir="$TMP/$1"

	rm -rf "$dir"
	mkdir -p "$dir"
	snapshot "$dir/local"
	cp -R "$dir/local" "$dir/ref"

	# The server has never had these, and the audit is expected to say so rather than to treat
	# them as missing. Removing them here is what makes case A's "2 of them expected" a fact
	# about the classifier instead of a coincidence.
	rm -f "$dir/ref/LICENSE" "$dir/ref/languages/atelier.pot"

	# ...and it has never had the development apparatus either. The deployed tree is a plugin
	# directory, so anything .distignore excludes has no business in it; leaving it would still
	# pass, since the audit walks the shipped set rather than the directory, but a fixture that
	# does not look like the thing it stands for invites the next reader to conclude the wrong
	# thing from a passing run.
	rm -rf "$dir/ref/tests" "$dir/ref/tools" "$dir/ref/docs" "$dir/ref/.github"

	printf '%s' "$dir"
}

# run <dir> [args...] -- returns the exit status, output in $OUT.
run() {
	local dir="$1"
	shift

	OUT="$(bash "$dir/local/tools/deploy.sh" audit --against "$dir/ref" "$@" 2>&1)"

	return $?
}

# expect <name> <pass|fail> <status> <substring...>
expect() {
	local name="$1" want="$2" status="$3"
	shift 3

	local ok=1

	if [ "$want" = "pass" ] && [ "$status" -ne 0 ]; then
		ok=0
	fi

	if [ "$want" = "fail" ] && [ "$status" -eq 0 ]; then
		ok=0
	fi

	local needle

	for needle in "$@"; do
		case "$OUT" in
			*"$needle"*) ;;
			*) ok=0 ;;
		esac
	done

	if [ "$ok" -eq 1 ]; then
		printf '  [OK]     %s\n' "$name"
		pass=$((pass + 1))
	else
		printf '  [FAILED] %s (wanted %s, status %s)\n' "$name" "$want" "$status"
		printf '%s\n' "$OUT" | sed 's/^/           | /'
		fail=$((fail + 1))
	fi
}

# refute <name> <substring> -- the output must NOT contain it.
refute() {
	local name="$1" needle="$2"

	case "$OUT" in
		*"$needle"*)
			printf '  [FAILED] %s (found "%s")\n' "$name" "$needle"
			fail=$((fail + 1))
			;;
		*)
			printf '  [OK]     %s\n' "$name"
			pass=$((pass + 1))
			;;
	esac
}

echo "deploy.sh server audit"
echo

# --- A: the control. Everything matches, and the two never-deployed files are named as such. ---
D="$(make_trees a)"
run "$D"
expect "A control: a matching tree is 0 changed, 0 unreadable, and it walked the set" pass $? \
	"changed: 0" "unreadable: 0" "absent: 2 (2 of them expected)"
refute "A control: a matching tree names no file CHANGED" "CHANGED "
# ...and none ABSENT either, which is what stops case C from being vacuous: if the audit called
# every file absent regardless, C would pass for the wrong reason.
refute "A control: a matching tree names no file ABSENT" "ABSENT "

# --- B: one byte differs. Size is deliberately unchanged, because a byte count cannot see it. --
D="$(make_trees b)"
before="$(wc -c < "$D/ref/$VICTIM" | tr -d ' ')"
LC_ALL=C tr 'A' 'B' < "$D/local/$VICTIM" > "$D/ref/$VICTIM"
after="$(wc -c < "$D/ref/$VICTIM" | tr -d ' ')"

if [ "$before" != "$after" ]; then
	echo "  [BROKEN] B: the mutation changed the file's LENGTH, so this case would also pass"
	echo "           a size check and says nothing about the digest"
	fail=$((fail + 1))
else
	run "$D"
	expect "B changed: an equal-length difference is found, and only in that file" pass $? \
		"CHANGED    $VICTIM" "changed: 1"
fi

# --- C: a deployed file is gone. It must be ABSENT, and it must count as needing upload. -------
D="$(make_trees c)"
rm -f "$D/ref/$VICTIM"
run "$D"
expect "C absent: a missing file is ABSENT and lands in the set that needs uploading" pass $? \
	"ABSENT     $VICTIM" "need uploading" "\"$VICTIM\""

# --- D: the UPLOAD_ORDER cross-check, in BOTH directions. -------------------------------------
#
# One direction alone is the dangerous half missing. A file that differs and is not listed will
# silently not be uploaded; a file listed though the server already matches it costs one needless
# transfer. The list is handed in here rather than read from deploy.sh, so this asserts about the
# check rather than about whichever release happens to be in flight.
D="$(make_trees d)"
LC_ALL=C tr 'A' 'B' < "$D/local/$VICTIM" > "$D/ref/$VICTIM"
run "$D" "$OTHER"
expect "D both ways: names the unlisted difference AND the listed non-difference" pass $? \
	"DIFFERS from the server but is not listed" "$VICTIM" \
	"listed though the server already matches it: $OTHER"

run "$D" "$VICTIM"
expect "D both ways: a list that names exactly the differing file is in sync" pass $? \
	"in sync with the server"

# The third state, which is neither of those and is the one you see most often: nothing differs
# at all, so UPLOAD_ORDER is simply the list the last push used. Reporting that as a discrepancy
# would train the reader to ignore the line that matters.
D="$(make_trees d2)"
run "$D" "$OTHER"
expect "D both ways: a just-deployed site is named as such, not flagged" pass $? \
	"nothing differs" "just-deployed site looks like"

# --- E: present but unreadable. "I could not tell" must never be delivered as a verdict. -------
D="$(make_trees e)"
chmod 000 "$D/ref/$VICTIM"

if [ -r "$D/ref/$VICTIM" ]; then
	# Running as root, or a filesystem that ignores the mode. Either way the case cannot be
	# built, and a case that cannot be built must say so rather than pass.
	echo "  [BROKEN] E: chmod 000 left the file readable, so unreadability cannot be exercised"
	fail=$((fail + 1))
else
	run "$D"
	expect "E unreadable: a file that cannot be read refuses the whole audit" fail $? \
		"UNREADABLE $VICTIM" "covers less than it appears to"
	refute "E unreadable: it is not silently filed as absent" "ABSENT     $VICTIM"
fi

# --- F: emptiness. The one answer that must never be delivered quietly. ------------------------
#
# Without a git repository `git ls-files` prints nothing, which is byte-for-byte what a healthy
# tree with nothing shippable looks like. A tool that walked no files and reported no differences
# is indistinguishable from a clean deploy, and that is the failure this repository has actually
# shipped -- so the audit must refuse instead.
D="$(make_trees f)"
rm -rf "$D/local/.git"
run "$D"
expect "F emptiness: no tracked files refuses rather than reporting a clean audit" fail $? \
	"the shipped set is unknown"
refute "F emptiness: it does not print a summary that reads as a pass" "unreadable: 0"

# --- G: mutate the harness. Neuter the digest comparison and case B must stop finding B. -------
#
# If B still reported a change with every file forced to compare equal, B would be firing for
# some other reason and would be saying nothing about the digest. The edit asserts it landed:
# a mutation that patched nothing reports the tests as fine, which is the most misleading
# verdict available.
D="$(make_trees g)"
LC_ALL=C tr 'A' 'B' < "$D/local/$VICTIM" > "$D/ref/$VICTIM"

before="$(shasum "$D/local/tools/deploy.sh" | cut -d' ' -f1)"
perl -0pi -e 's/\t\tremote_sum="\$\(shasum "\$src" \| cut -d. . -f1\)"/\t\tremote_sum="\$local_sum"/' \
	"$D/local/tools/deploy.sh"
after="$(shasum "$D/local/tools/deploy.sh" | cut -d' ' -f1)"

if [ "$before" = "$after" ]; then
	echo "  [BROKEN] G: the mutation did not land -- the digest comparison has moved,"
	echo "           so this case proves nothing about case B"
	fail=$((fail + 1))
else
	run "$D"
	expect "G mutation: forcing the digests equal makes case B stop seeing a change" pass $? \
		"changed: 0"
fi

echo
echo "------------------------------------------------------------------------------"
echo "cases: $((pass + fail)), failing: $fail"

[ "$fail" -eq 0 ]
