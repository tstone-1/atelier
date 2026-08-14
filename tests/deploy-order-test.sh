#!/bin/bash
#
# Proves that deploy.sh's removed-method ordering check is capable of going red.
#
#   bash tests/deploy-order-test.sh
#
# The check it exercises is the one that did not exist when 26.8.22 shipped. That release
# deleted `Atelier_Gallery::custom_css()` while the deployed `Atelier_Renderer` was still
# calling it, so the caller had to be uploaded FIRST -- the opposite of every ordering rule
# before it -- and `deploy.sh plan` printed "constraints: satisfied" over either order. The
# right upload order was worked out by hand and the wrong one would have been a fatal on the
# front page and fifty permalinks.
#
# A check written for that is worth nothing until it has been shown to fail, and the two ways
# this particular check could be worthless are both silent: it could pass because it examined
# nothing, and it could pass because the grep that finds callers matches nothing any more. So
# there are six cases, each with its outcome declared in advance, and two of them exist only to
# make the other four mean something:
#
#   A  control      caller before definition            -> PASS, and it says which pair it saw
#   B  the bug      definition before caller            -> FAIL, naming the method
#   C  emptiness    nothing removed at all              -> PASS, and it says "none"
#   D  survivor     a call the release does not remove  -> FAIL, no order can fix it
#   E  ambiguity    the name is defined on another class -> PASS, but reported for a human
#   F  mutation     case B with the caller-grep neutered -> PASS, which is the proof that the
#                   grep is what makes B fail rather than something incidental to the fixture
#
# Case C is not decoration. A check that walks an empty list passes exactly like one that
# walked a full list and found nothing wrong, and this repository has shipped that defect
# before. Case F is the same argument one level up, applied to the harness itself.
#
# Everything runs against two trees on disk -- no network, no credentials, no deployment
# target -- which is why it can run in CI. `deploy.sh order-check` exists for that.
#
# @package Atelier\Tests

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

GALLERY="includes/class-atelier-gallery.php"
RENDERER="includes/class-atelier-renderer.php"
ALBUM="includes/class-atelier-album.php"

pass=0
fail=0

# ---------------------------------------------------------------------------------------------
# Fixture construction.
#
# Methods are added rather than an existing one removed, because the check compares a DEPLOYED
# tree against the local one: a method present in the reference and absent locally is exactly a
# method this release deletes. Adding to the reference is the same thing as deleting from the
# release, and it does not require picking a real method whose disappearance would break the
# fixture's own PHP.
# ---------------------------------------------------------------------------------------------
cat > "$TMP/inject.php" <<'PHP'
<?php
// inject.php <file> <method> [<callee>] -- add a method just inside the closing brace.
$file   = $argv[1];
$method = $argv[2];
$callee = $argv[3] ?? '';

$src = file_get_contents( $file );
$at  = strrpos( $src, '}' );

if ( false === $at ) {
	fwrite( STDERR, "inject: no closing brace in $file\n" );
	exit( 1 );
}

$body = '' === $callee ? "\t\treturn '';\n" : "\t\treturn \$g->$callee();\n";
$args = '' === $callee ? '' : ' $g ';
$add  = "\n\tpublic function $method($args) {\n" . $body . "\t}\n";

file_put_contents( $file, substr( $src, 0, $at ) . $add . substr( $src, $at ) );
PHP

# A copy of the WORKING tree, not of HEAD: the check under test is usually uncommitted when
# this runs, and a test that silently exercised the committed version would report on code
# nobody is about to ship.
snapshot() {
	local dest="$1"

	mkdir -p "$dest"
	( cd "$ROOT" && git ls-files -z ) | ( cd "$ROOT" && tar -cf - --null -T - ) | tar -xf - -C "$dest"
}

# local + ref, both fresh. $1 receives the pair's directory.
make_trees() {
	local dir="$TMP/$1"

	rm -rf "$dir"
	mkdir -p "$dir"
	snapshot "$dir/local"
	cp -R "$dir/local" "$dir/ref"

	printf '%s' "$dir"
}

lint() {
	php -l "$1" > /dev/null 2>&1 || {
		echo "  [BROKEN] the fixture is not valid PHP: $1"
		fail=$((fail + 1))

		return 1
	}
}

# run <dir> <order...> -- returns the exit status, output in $OUT.
run() {
	local dir="$1"
	shift

	OUT="$(bash "$dir/local/tools/deploy.sh" order-check "$dir/ref" "$@" 2>&1)"

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

echo "deploy.sh removed-method ordering check"
echo

# --- A: the control. The caller is uploaded before the file that drops the definition. -------
D="$(make_trees a)"
php "$TMP/inject.php" "$D/ref/$GALLERY" probe_removed
php "$TMP/inject.php" "$D/ref/$RENDERER" probe_caller probe_removed
lint "$D/ref/$GALLERY" && lint "$D/ref/$RENDERER"
run "$D" "$RENDERER" "$GALLERY"
expect "A control: caller first is accepted, and the pair is named" pass $? \
	"REMOVED   probe_removed(): $RENDERER" "$GALLERY"

# --- B: the bug 26.8.22 would have shipped without this check. -------------------------------
run "$D" "$GALLERY" "$RENDERER"
expect "B the bug: definition first is refused, naming the method" fail $? \
	"probe_removed()" "ordered after it"

# --- C: emptiness. Nothing was removed, and the check must SAY so rather than fall silent. ----
D="$(make_trees c)"
run "$D" "$RENDERER" "$GALLERY"
expect "C emptiness: nothing removed is reported as nothing removed" pass $? \
	"none, so nothing to sequence backwards"

# --- D: a call that survives the release. No upload order can fix this one. -------------------
D="$(make_trees d)"
php "$TMP/inject.php" "$D/ref/$GALLERY" probe_removed
php "$TMP/inject.php" "$D/ref/$RENDERER" probe_caller probe_removed
php "$TMP/inject.php" "$D/local/$RENDERER" probe_caller probe_removed
lint "$D/local/$RENDERER"
run "$D" "$RENDERER" "$GALLERY"
expect "D survivor: a call nothing defines any more is refused outright" fail $? \
	"still calls it" "no order fixes that"

# --- E: the name is defined on another class, so a grep cannot resolve the call. --------------
D="$(make_trees e)"
php "$TMP/inject.php" "$D/ref/$GALLERY" probe_removed
php "$TMP/inject.php" "$D/ref/$RENDERER" probe_caller probe_removed
php "$TMP/inject.php" "$D/local/$ALBUM" probe_removed
lint "$D/local/$ALBUM"
run "$D" "$RENDERER" "$GALLERY"
expect "E ambiguity: a name another class defines is handed to a human" pass $? \
	"AMBIGUOUS" "check by hand"

# --- F: mutate the harness. Neuter the caller-grep and case B must stop failing. --------------
#
# If B still failed with this in place, B would be failing for some other reason and would be
# telling us nothing about the check. The edit is asserted to have landed -- a mutation that
# patched nothing reports the tests as fine, which is the most misleading verdict available.
D="$(make_trees f)"
php "$TMP/inject.php" "$D/ref/$GALLERY" probe_removed
php "$TMP/inject.php" "$D/ref/$RENDERER" probe_caller probe_removed

before="$(shasum "$D/local/tools/deploy.sh" | cut -d' ' -f1)"
perl -0pi -e 's/\tgrep -qE "\(->\|::\)\[\[:space:\]\]\*\$2\[\[:space:\]\]\*\\\(" "\$1" 2>\/dev\/null/\treturn 1/' "$D/local/tools/deploy.sh"
after="$(shasum "$D/local/tools/deploy.sh" | cut -d' ' -f1)"

if [ "$before" = "$after" ]; then
	echo "  [BROKEN] F: the mutation did not land -- calls_method's body has moved,"
	echo "           so this case proves nothing about case B"
	fail=$((fail + 1))
else
	run "$D" "$GALLERY" "$RENDERER"
	expect "F mutation: neutering the caller-grep makes case B stop failing" pass $? \
		"methods dropped this release:"
fi

echo
echo "------------------------------------------------------------------------------"
echo "cases: $((pass + fail)), failing: $fail"

[ "$fail" -eq 0 ]
