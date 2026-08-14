#!/usr/bin/env bash
#
# Uploads the plugin to the live site over FTPS and proves what landed.
#
# Usage:
#   bash tools/deploy.sh plan          # what would be uploaded, and in what order
#   bash tools/deploy.sh urls          # enumerate every URL the plugin owns, from the database
#   bash tools/deploy.sh capture <out> # fetch those URLs and record a normalised hash each
#   bash tools/deploy.sh fingerprint <out>  # the same URLs, recorded SEMANTICALLY
#   bash tools/deploy.sh push          # upload, chunked, verifying every file by digest
#   bash tools/deploy.sh compare <a> <b>
#
# The host has no shell, so everything here goes through curl over FTPS, and the transport is
# the reason this file exists rather than a one-liner. Three properties are not negotiable,
# each of them paid for on a previous deploy:
#
#   - CHUNK BY DEFAULT. A whole-file PUT of a 19 KB file failed six times out of six and left
#     it at 0 bytes; because that file is require_once'd, the live site returned HTTP 500 on
#     every page including the homepage. The same file went up first time in 8 KB pieces.
#     Trying whole-file first buys nothing when it works and costs an outage when it does not.
#   - VERIFY BY DIGEST, NEVER BY SIZE. A file whose new version is the same length as the old
#     passes a byte-count check while still holding the old content, and that is not a freak
#     case: a version string of equal width (26.8.0 -> 26.8.1) and a block moved within a file
#     both produce it, and both occurred in one deploy.
#   - ORDER MATTERS WHEN ONE FILE GAINS A METHOD ANOTHER CALLS. A caller landing first gives
#     visitors "Call to undefined method" until the definition arrives. UPLOAD_ORDER below is
#     derived from grep, not from memory, and `plan` prints the reasoning.
#
# The password is read from the login keychain into a .netrc that lives in a 0700 temp dir and
# is removed on exit. It is never rendered, never passed as an argument, and never logged.

set -uo pipefail

# The host and account are NOT literals here, and that is a publication requirement rather than a
# style preference: an FTP hostname plus its username is two thirds of a credential, and this
# repository is public. The password was never here — it comes from the login keychain below — but
# publishing the other two says exactly where to point a credential-stuffing tool.
#
# They come from the environment, or from `tools/deploy.env`, which is gitignored. Sourcing it is
# guarded on the file existing so a fresh clone gets the error message below rather than a
# `No such file` from the shell.
#
# Note this file is the ONLY thing that had to change: `netrc()` already looked the password up by
# ("$HOST", "$USER_NAME") rather than by a hardcoded service name, so parameterising the two
# carried the keychain lookup with it for free.
[ -f "$(dirname "${BASH_SOURCE[0]}")/deploy.env" ] && . "$(dirname "${BASH_SOURCE[0]}")/deploy.env"

HOST="${ATELIER_DEPLOY_HOST:-}"
USER_NAME="${ATELIER_DEPLOY_USER:-}"
REMOTE_DIR="${ATELIER_DEPLOY_DIR:-/wp-content/plugins/atelier}"

if [ -z "$HOST" ] || [ -z "$USER_NAME" ]; then
	case "${1:-}" in
		# `order-check` compares two trees that are already on disk and never opens a connection,
		# so it must not require a deployment target. That is not a convenience: it is what lets
		# tests/deploy-order-test.sh run in CI, on a runner that has no credentials and no route
		# to the host, and a check nothing exercises is a check nobody knows the state of.
		order-check) : ;;
		*)
			echo "[ERROR] set ATELIER_DEPLOY_HOST and ATELIER_DEPLOY_USER, or create tools/deploy.env:" >&2
			echo "          ATELIER_DEPLOY_HOST=ftp.example.com" >&2
			echo "          ATELIER_DEPLOY_USER=account" >&2
			echo "        The password is read from the macOS login keychain for that host and account," >&2
			echo "        never from a file: security add-internet-password -s <host> -a <account> -w" >&2
			exit 2
			;;
	esac
fi
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CHUNK=8192

# Callers before definitions is a fatal error for a visitor, so this list is ordered, not
# alphabetical, and every constraint below is asserted by `plan` from a grep rather than taken
# on trust. It is the set that changed since the deployed release; re-uploading a file that did
# not change buys nothing and adds a transfer that can fail.
#
# 26.8.22 has ONE cross-file constraint and it points the OTHER WAY from every previous release,
# because this release REMOVES a method rather than adding one. The five releases before it all
# had the shape "a definition arrives, land it before its caller"; invert the direction and the
# familiar rule gives exactly the wrong answer.
#
#   1. `Atelier_Gallery::custom_css()` is DELETED, and `class-atelier-renderer.php` is the file
#      that stops calling it. Land the definition's file first and the DEPLOYED renderer -- still
#      on the server, still calling it -- hits "Call to undefined method" on every page that
#      renders a gallery: the front page and 50-odd permalinks. So the CALLER goes first here.
#      Renderer, then gallery.
#
#   2. Softer, same direction: the deployed `class-atelier-gallery.php` reads
#      `$this->settings['custom_css']`, and `class-atelier-config.php` drops that key from
#      `defaults()`. Config landing first is an "Undefined array key" warning rather than a
#      fatal, but the ordering that avoids it is free. Gallery, then config.
#
# `plan` CANNOT SEE EITHER OF THESE. Its walk asks which requires are new and whether an asset
# precedes the bootstrap; a method that has been deleted appears in neither question, and the
# grep it runs looks for callers of a class name rather than of a method. It will print
# "constraints: satisfied" over an order that takes the site down. The other four removed
# functions this release are safe by construction and were checked rather than assumed:
# `atelier_load_textdomain()` is defined and hooked in the same file, and `Atelier_Config::css()`,
# `Atelier_Config::rewrite_css()` and `Atelier_Editor::row_css()` are all private.
#
# `atelier.php` is last for the usual reason: ATELIER_VERSION is the `?ver=` on the assets. No
# asset changed this release, so the window is harmless either way; the position costs nothing
# and stating a rule once per release is cheaper than deciding whether it applies.
#
# The set is derived from the SERVER, not from the release commit: every shipped file was
# downloaded back and digest-compared, and the server proved to be exactly the 26.8.21 deploy
# (03d9edc). That matters because a later commit, 9e3ab3c, edited five more shipped files after
# that deploy and never shipped -- ajax, album-editor, migration, settings and uninstall are in
# this list for that reason, not because 26.8.22 touched them.
#
# LICENSE, languages/atelier-de_DE.po and languages/atelier.pot are ABSENT from the server and
# deliberately stay that way: none is read at runtime, none has ever been deployed, and each is
# one more transfer that can fail for no behavioural gain. LICENSE ships in the wordpress.org
# ZIP, which is a different artifact.
UPLOAD_ORDER=(
	"includes/class-atelier-renderer.php"
	"includes/class-atelier-gallery.php"
	"includes/class-atelier-config.php"
	"includes/class-atelier-editor.php"
	"includes/class-atelier-ajax.php"
	"includes/class-atelier-album-editor.php"
	"includes/class-atelier-migration.php"
	"includes/class-atelier-settings.php"
	"languages/atelier-de_DE.mo"
	"readme.txt"
	"uninstall.php"
	"atelier.php"
)

# The order the checks below actually run against. It is UPLOAD_ORDER for every real invocation;
# `order-check` replaces it so a test can hand in a deliberately wrong order and require the
# check to go red. Without that seam the only way to exercise this is to break a live deploy.
ORDER=( "${UPLOAD_ORDER[@]}" )

WORK="$(mktemp -d)"
chmod 700 "$WORK"
trap 'rm -rf "$WORK"' EXIT

# ---------------------------------------------------------------------------------------------
# Credentials. Read once, into a file only this user can read, and never into a variable that
# could reach a log line or an argument list.
# ---------------------------------------------------------------------------------------------
netrc() {
	if [ ! -s "$WORK/netrc" ]; then
		umask 077
		{
			printf 'machine %s login %s password ' "$HOST" "$USER_NAME"
			security find-internet-password -s "$HOST" -a "$USER_NAME" -w
		} > "$WORK/netrc" || {
			echo "[ERROR] no keychain entry for $USER_NAME@$HOST" >&2
			exit 2
		}
	fi

	printf '%s' "$WORK/netrc"
}

ftp() {
	# --ftp-create-dirs so a new subdirectory in UPLOAD_ORDER works. `languages/` did not exist
	# on the server before 26.8.13, and without this the first chunk fails with a bare "550" that
	# reads like a permissions problem rather than a missing directory.
	curl --netrc-file "$(netrc)" --ssl-reqd --ftp-create-dirs --max-time 120 -sS "$@"
}

remote_size() {
	# -I on an FTP URL asks for SIZE. A missing file gives a non-zero exit, reported as -1 so
	# the caller can tell "absent" from "zero bytes" -- the two mean very different things here.
	local out
	out="$(ftp -I "ftp://$HOST$REMOTE_DIR/$1" 2>/dev/null | tr -d '\r' | awk '/Content-Length/{print $2}')"

	if [ -z "$out" ]; then
		printf '%s' "-1"
	else
		printf '%s' "$out"
	fi
}

remote_digest() {
	# The only statement that establishes what is on the server. Downloads it back rather than
	# trusting anything the upload reported.
	ftp -o "$WORK/verify.bin" "ftp://$HOST$REMOTE_DIR/$1" 2>/dev/null || {
		printf '%s' "unreadable"
		return
	}

	shasum "$WORK/verify.bin" | cut -d' ' -f1
}

# ---------------------------------------------------------------------------------------------
# Upload one file in CHUNK-sized pieces, re-reading the remote length after each so a cut stream
# is caught at the piece that failed rather than at the end.
# ---------------------------------------------------------------------------------------------
put_chunked() {
	local rel="$1" local_path="$ROOT/$1" want got expected piece n=0 attempt
	want="$(shasum "$local_path" | cut -d' ' -f1)"

	rm -rf "$WORK/pieces"
	mkdir -p "$WORK/pieces"
	split -b "$CHUNK" "$local_path" "$WORK/pieces/p"

	expected=0

	for piece in "$WORK/pieces"/p*; do
		n=$((n + 1))
		attempt=0

		while : ; do
			attempt=$((attempt + 1))

			if [ "$n" -eq 1 ]; then
				ftp -T "$piece" "ftp://$HOST$REMOTE_DIR/$rel" >/dev/null 2>&1
			else
				ftp --append -T "$piece" "ftp://$HOST$REMOTE_DIR/$rel" >/dev/null 2>&1
			fi

			expected=$((expected + $(wc -c < "$piece")))
			got="$(remote_size "$rel")"

			if [ "$got" = "$expected" ]; then
				break
			fi

			# A partial append corrupts every later offset, so the file is cut back to the last
			# known-good length before retrying rather than appended to again.
			echo "    piece $n attempt $attempt: remote $got, expected $expected -- retrying" >&2
			expected=$((expected - $(wc -c < "$piece")))

			if [ "$attempt" -ge 6 ]; then
				echo "[ERROR] $rel: piece $n did not land after $attempt attempts" >&2
				return 1
			fi

			# Truncate back by re-uploading everything already verified.
			if [ "$expected" -eq 0 ]; then
				: > "$WORK/empty"
				ftp -T "$WORK/empty" "ftp://$HOST$REMOTE_DIR/$rel" >/dev/null 2>&1
			else
				head -c "$expected" "$local_path" > "$WORK/head.bin"
				ftp -T "$WORK/head.bin" "ftp://$HOST$REMOTE_DIR/$rel" >/dev/null 2>&1
			fi
		done
	done

	got="$(remote_digest "$rel")"

	if [ "$got" != "$want" ]; then
		echo "[ERROR] $rel: digest mismatch after upload (remote $got, local $want)" >&2
		return 1
	fi

	printf '  [OK]   %-42s %6s bytes, %d chunks, digest verified\n' "$rel" "$expected" "$n"
}

# ---------------------------------------------------------------------------------------------
# The constraint that points BACKWARDS, and the reason this function exists at all.
#
# Every ordering rule above is about a definition ARRIVING: land it before its caller, or the
# caller is a fatal in the window between the two files. 26.8.22 was the first release to DELETE
# one -- `Atelier_Gallery::custom_css()`, with `Atelier_Renderer` as the file that stops calling
# it -- and the familiar rule gives exactly the wrong answer there. The caller has to go FIRST,
# because it is the DEPLOYED caller, still on the server, that would be left naming a method the
# new file no longer defines.
#
# `cmd_plan` printed "constraints: satisfied" over that order and was right to by its own lights:
# it asks which `require`s are new and whether an asset precedes the bootstrap, and a deleted
# method is in neither question. Its class-level grep cannot see it either -- the class is still
# required, still constructed, still there; it is one method that went away.
#
# The comparison is against the DEPLOYED tree, not against the previous release commit, for the
# reason the whole script exists: the version of record is what the server serves. A commit that
# edited a shipped file after the last deploy and never shipped is invisible to `git diff` against
# a tag, and 26.8.22 met exactly that (9e3ab3c, five files).
#
# Three outcomes rather than two, because "I could not tell" must never be delivered as a pass:
#
#   ERROR      a call SURVIVES the release -- some local file still calls a method nothing
#              defines any more. That is a broken release rather than an ordering problem, and
#              it also covers every caller OUTSIDE this upload for free: a file that is not in
#              the upload is byte-identical to the deployed one, so if its deployed copy calls
#              the method, so does its local copy.
#   ERROR      the deployed caller IS in this upload and is ordered at or after the file that
#              drops the definition.
#   AMBIGUOUS  another class still defines a method of that name, so `->name(` cannot say which
#              object it belongs to. Reported for a human rather than guessed either way -- a
#              grep that resolves this would be claiming a type checker it does not have.
# ---------------------------------------------------------------------------------------------

# Method and function DEFINITIONS in one PHP file, one name per line, sorted.
#
# Anchored at the start of the line so a CALL never matches: `public function custom_css(` is a
# definition, `$gallery->custom_css(` is not, and the difference is entirely the anchor plus the
# modifier prefix. A bare `function atelier_load_textdomain(` at column 0 is a definition too.
php_defs() {
	grep -oE '^[[:space:]]*((public|protected|private|static|abstract|final)[[:space:]]+)*function[[:space:]]+&?[A-Za-z_][A-Za-z0-9_]*' "$1" 2>/dev/null |
		sed -E 's/.*function[[:space:]]+&?//' |
		sort -u
}

# Does file $1 CALL method $2? Both spellings, and a space before the paren is legal PHP.
calls_method() {
	grep -qE "(->|::)[[:space:]]*$2[[:space:]]*\(" "$1" 2>/dev/null
}

order_position() {
	local n=0 rel

	for rel in "${ORDER[@]}"; do
		n=$((n + 1))

		if [ "$rel" = "$1" ]; then
			printf '%s' "$n"

			return
		fi
	done

	printf '%s' "0"
}

# Every shipped PHP file in the local tree, which is where a surviving call would be.
local_php() {
	printf '%s\n' "$ROOT/atelier.php" "$ROOT/uninstall.php" "$ROOT"/includes/*.php
}

check_removed_methods() {
	local ref="$1"
	local ok=1 removed=0 pairs=0 ambiguous=0
	local rel m f defining_pos caller caller_pos survivors elsewhere

	for rel in "${ORDER[@]}"; do
		case "$rel" in
			*.php) ;;
			*) continue ;;
		esac

		# Absent from the deployed tree means the file is new, and a new file cannot have
		# removed anything. Absent locally would be a deletion, which this script does not do.
		[ -f "$ref/$rel" ] || continue
		[ -f "$ROOT/$rel" ] || continue

		while IFS= read -r m; do
			[ -z "$m" ] && continue

			removed=$((removed + 1))
			defining_pos="$(order_position "$rel")"

			survivors=""
			elsewhere=0

			for f in $(local_php); do
				[ -f "$f" ] || continue

				if calls_method "$f" "$m"; then
					survivors="$survivors ${f#"$ROOT"/}"
				fi

				if [ "$f" != "$ROOT/$rel" ] && php_defs "$f" | grep -qx "$m"; then
					elsewhere=1
				fi
			done

			if [ "$elsewhere" -eq 1 ]; then
				echo "  AMBIGUOUS $rel drops $m(), but another class still defines that name;"
				echo "            a grep cannot say which object a call names -- check by hand"
				ambiguous=$((ambiguous + 1))

				continue
			fi

			if [ -n "$survivors" ]; then
				echo "[ERROR] $rel drops $m(), but$survivors still calls it -- no order fixes that" >&2
				ok=0

				continue
			fi

			for caller in "${ORDER[@]}"; do
				[ "$caller" = "$rel" ] && continue
				[ -f "$ref/$caller" ] || continue
				calls_method "$ref/$caller" "$m" || continue

				pairs=$((pairs + 1))
				caller_pos="$(order_position "$caller")"

				if [ "$caller_pos" -ge "$defining_pos" ]; then
					echo "[ERROR] $rel drops $m() and the DEPLOYED $caller still calls it," >&2
					echo "        but $caller is ordered after it -- every request that reaches" >&2
					echo "        that call between the two uploads is a fatal" >&2
					ok=0
				else
					echo "  REMOVED   $m(): $caller (deployed caller) precedes $rel (definition)"
				fi
			done
		done < <(comm -23 <(php_defs "$ref/$rel") <(php_defs "$ROOT/$rel"))
	done

	if [ "$removed" -eq 0 ]; then
		echo "  methods dropped this release:       none, so nothing to sequence backwards"
	else
		echo "  methods dropped this release:       $removed, ordering pairs checked: $pairs, ambiguous: $ambiguous"
	fi

	[ "$ok" -eq 1 ]
}

# Populate a local mirror of the DEPLOYED tree for the check above.
#
# A file that cannot be read is refused rather than skipped. Skipping is the failure this script
# has already made twice in other places: an unreadable file and a file with nothing removed
# produce the same silence, and the silent one reads as a pass.
fetch_deployed() {
	local dest="$1" rel size

	mkdir -p "$dest/includes"

	for rel in "${ORDER[@]}"; do
		case "$rel" in
			*.php) ;;
			*) continue ;;
		esac

		if ftp -o "$dest/$rel" "ftp://$HOST$REMOTE_DIR/$rel" 2>/dev/null && [ -s "$dest/$rel" ]; then
			continue
		fi

		rm -f "$dest/$rel"
		size="$(remote_size "$rel")"

		if [ "$size" != "-1" ]; then
			echo "[ERROR] the deployed $rel is $size bytes but could not be read; the" >&2
			echo "        removed-method check would silently cover nothing" >&2

			return 1
		fi
	done
}

cmd_plan() {
	ORDER=( "${UPLOAD_ORDER[@]}" )

	echo "upload order, and why it is this order:"
	echo

	local i=0

	for rel in "${UPLOAD_ORDER[@]}"; do
		i=$((i + 1))
		printf '  %d. %-44s %8s bytes\n' "$i" "$rel" "$(wc -c < "$ROOT/$rel" | tr -d ' ')"
	done

	echo
	echo "ordering constraints, derived rather than remembered:"

	# Labelled "required by atelier.php", not "new" -- this is every require in the LOCAL
	# bootstrap, and the new ones are worked out below by asking the server. The label used to
	# say "new classes required by atelier.php" over a list of all nineteen, which reads exactly
	# like the server fetch having failed and every require having defaulted to new. It cost a
	# detour through the fetch code on the 26.8.10 deploy to establish that nothing was wrong.
	# A report that describes itself inaccurately is a defect in the report.
	echo "  required by the local atelier.php:   $(cd "$ROOT" && grep -c "class-atelier-[a-z-]*\.php" atelier.php | tr -d ' ') classes"
	echo "  in this upload, absent on server:   $(for rel in "${UPLOAD_ORDER[@]}"; do [ "$(remote_size "$rel")" = "-1" ] && printf '%s ' "$rel"; done)"

	local ok=1
	local new_requires=0

	position() {
		local n=0

		for rel in "${UPLOAD_ORDER[@]}"; do
			n=$((n + 1))

			if [ "$rel" = "$1" ]; then
				printf '%s' "$n"

				return
			fi
		done

		printf '%s' "0"
	}

	# The one rule, stated generally rather than as this release's instance of it: a file that
	# NAMES a class must not land before the file that REQUIRES that class does -- otherwise the
	# consumer is a fatal "class not found" on every page it touches.
	#
	# Naming a class means calling it, constructing it, OR EXTENDING it, and the third was missing
	# until 26.8.7 needed it. A grep for `Foo::` and `new Foo(` cannot see `extends Foo`, so a
	# subclass ordered before its parent's require passed this check and would have taken every
	# admin page down -- the check was not lenient here, it was blind. `abstract` classes make the
	# gap worse, because the two spellings it did look for are exactly the ones you cannot use on
	# one: an abstract class is never `new`ed, so the only edge that exists is the invisible one.
	#
	# It only binds when the require is NEW, and whether it is new is a fact about the server,
	# not about this checkout. So the server's own atelier.php is fetched and read. Two releases
	# running, the interesting answer came from asking the server rather than from remembering:
	# 26.8.4's new class files legitimately preceded the gate because they did not yet exist
	# there, and 26.8.5 has no constraint at all because the requires are already in place.
	local gate server_bootstrap bootstrap_size
	gate="$(position atelier.php)"
	server_bootstrap="$(ftp "ftp://$HOST$REMOTE_DIR/atelier.php" 2>/dev/null)"

	# An empty answer has TWO causes that mean opposite things, and this script's whole history is
	# of empty answers being read as facts. So it is resolved by SIZE, which distinguishes them:
	# `remote_size` reports -1 for a file that is not there and a byte count for one that is.
	#
	#   -1  the bootstrap is genuinely absent -> first install of a new plugin directory. Nothing
	#       on the server executes any of it, so there is no order to get wrong. Say so and stop
	#       checking, rather than inventing a constraint or refusing to run.
	#   >=0 the file exists and could not be read -> a transport failure. Refuse, exactly as
	#       before: "cannot tell which requires are new" is the one answer that must never be
	#       delivered quietly, because every ordering guarantee below rests on having read it.
	if [ -z "$server_bootstrap" ]; then
		bootstrap_size="$(remote_size atelier.php)"

		if [ "$bootstrap_size" != "-1" ]; then
			echo "[ERROR] the deployed atelier.php is $bootstrap_size bytes but could not be read;" >&2
			echo "        cannot tell which requires are new, so the ordering check would cover nothing" >&2

			return 1
		fi

		echo "  FIRST INSTALL: no atelier.php on the server, so this is a new plugin directory."
		echo "  Ordering is moot: the directory is not in active_plugins, so PHP opens none of it"
		echo "  while it uploads. Activate only after every file is digest-verified."
		echo
		echo "constraints: none applicable (first install, plugin inactive during upload)"

		return 0
	fi

	while IFS= read -r class_file; do
		[ -z "$class_file" ] && continue

		# Already required on the server: landing a consumer first is safe.
		case "$server_bootstrap" in
			*"$class_file"*) continue ;;
		esac

		new_requires=$((new_requires + 1))

		echo "  NEW require this release: $class_file"

		# `^class` alone does not match `abstract class Foo` or `final class Foo`, and the empty
		# answer did not read as "cannot tell" -- it fell through the `continue` below and skipped
		# the constraint check entirely, reporting "constraints: satisfied" over an order that was
		# a fatal on every admin page. Caught by mutating the order and finding the check still
		# green, which is the only reason it was caught at all.
		local class_name
		class_name="$(cd "$ROOT" && grep -oE '^(abstract |final )?class [A-Za-z_]+' "includes/$class_file" | head -1 | awk '{print $NF}')"

		# A new require whose class name cannot be read is a check that covered NOTHING. That is
		# the one answer this must never give quietly, so it stops the deploy rather than skipping.
		if [ -z "$class_name" ]; then
			echo "[ERROR] cannot read a class name from includes/$class_file; the ordering check would cover nothing" >&2
			ok=0

			continue
		fi

		while IFS= read -r consumer; do
			[ -z "$consumer" ] && continue
			[ "$(position "$consumer")" -eq 0 ] && continue
			[ "$(remote_size "$consumer")" = "-1" ] && continue

			if [ "$gate" -ge "$(position "$consumer")" ]; then
				echo "[ERROR] $consumer names $class_name but is ordered before the file that requires it" >&2
				ok=0
			fi
		done < <(cd "$ROOT" && grep -l "${class_name}::\|new ${class_name}(\|extends ${class_name}" includes/*.php)

		if [ "$(position "includes/$class_file")" -eq 0 ] || [ "$(position "includes/$class_file")" -ge "$gate" ]; then
			echo "[ERROR] includes/$class_file is missing from the order, or ordered after atelier.php requires it" >&2
			ok=0
		fi
	done < <(cd "$ROOT" && grep -o "class-atelier-[a-z-]*\.php" atelier.php)

	[ "$new_requires" -eq 0 ] && echo "  new requires this release:          none, so nothing to sequence"

	# The second constraint, which is not about PHP and which the walk above is structurally
	# blind to: a stylesheet or script is cache-busted by `ATELIER_VERSION`, and that constant
	# lives in atelier.php. Land the bootstrap first and a browser asking for `?ver=<new>` is
	# served the OLD asset and caches it under the new name -- where nothing corrects it until
	# the next version bump, because the URL never changes again. No grep can see this edge:
	# nothing in the code names the file, the dependency runs through a query argument.
	#
	# It bound on 26.8.15 (the stylesheet) and again on 26.8.19 (the script), and both times it
	# was carried by a note in the deploy record. Twice is the rule rather than the exception, and
	# a rule written as a caution gets nodded at while the same rule written as a line of code
	# gets followed -- which is the argument this whole script was built on.
	# Counted separately from the ones that pass, because a single tally cannot say which of two
	# opposite things happened: with `atelier.php` ordered first, every asset FAILS the rule, and
	# a pass-counter left at zero then prints "none in this upload" directly beneath the error
	# saying otherwise. Same defect this script already fixed once in the requires label -- a
	# report that describes itself inaccurately is a defect in the report.
	local assets_seen=0
	local assets_first=0

	if [ "$gate" -gt 0 ]; then
		for rel in "${UPLOAD_ORDER[@]}"; do
			case "$rel" in
				assets/*)
					assets_seen=$((assets_seen + 1))

					if [ "$(position "$rel")" -ge "$gate" ]; then
						echo "[ERROR] $rel is ordered at or after atelier.php, which carries the ?ver= it is cached under" >&2
						ok=0
					else
						assets_first=$((assets_first + 1))
					fi
					;;
			esac
		done
	fi

	if [ "$assets_seen" -eq 0 ]; then
		echo "  versioned assets in this upload:    none, so no cache-buster to sequence"
	else
		echo "  versioned assets before the bootstrap: $assets_first of $assets_seen"
	fi

	# The third constraint, and the only one that runs backwards. See check_removed_methods().
	if fetch_deployed "$WORK/deployed"; then
		check_removed_methods "$WORK/deployed" || ok=0
	else
		ok=0
	fi

	echo

	[ "$ok" -eq 1 ] && echo "constraints: satisfied"
	[ "$ok" -eq 1 ] || return 1
}

cmd_push() {
	cmd_plan || return 1
	echo
	echo "uploading in ${CHUNK}-byte chunks:"

	for rel in "${UPLOAD_ORDER[@]}"; do
		put_chunked "$rel" || {
			echo "[ERROR] stopping: $rel did not land" >&2
			return 1
		}
	done

	echo
	echo "re-verifying every file by digest, after the whole set has landed:"

	local bad=0

	for rel in "${UPLOAD_ORDER[@]}"; do
		local want got
		want="$(shasum "$ROOT/$rel" | cut -d' ' -f1)"
		got="$(remote_digest "$rel")"

		if [ "$want" = "$got" ]; then
			printf '  [OK]   %s\n' "$rel"
		else
			printf '  [FAIL] %s (remote %s, local %s)\n' "$rel" "$got" "$want"
			bad=$((bad + 1))
		fi
	done

	echo
	echo "files: ${#UPLOAD_ORDER[@]}, mismatching: $bad"

	return "$bad"
}

# Reduces a page to WHICH PHOTOGRAPHS IT SHOWS, and nothing else.
#
# `capture` and `fingerprint` are not two ways of doing one job, and using the wrong one is how a
# verification lies in whichever direction is least convenient:
#
#   - A DEPLOY must not change a rendered byte, so `capture` hashes the whole page. That is the
#     strongest possible statement and the right one when nothing about the markup should move.
#   - A MIGRATION changes the post type, and WordPress puts the post type in its own body and
#     post classes -- `single-envira` becomes `single-atelier_gallery` on every page. A byte hash
#     therefore reports 100% changed and tells you nothing. Measured once: all 110 local URLs
#     "changed", none of them meaningfully.
#
# So the migration's question is not "are the bytes identical" but "are these the same
# photographs, in the same number, however they are wrapped". Upload filenames with their size
# suffixes stripped answer exactly that, and the tile count stops a page that lost every image
# from matching an empty one.
atelier_semantic() {
	local page
	page="$(cat)"

	{
		printf '%s' "$page" |
			grep -o 'wp-content/uploads/[^"'"'"' )]*\.\(jpg\|jpeg\|png\|gif\|webp\)' |
			sed 's/-[0-9]\{2,\}x[0-9]\{2,\}\././' |
			sort -u
		# The tile pattern matches the LEGACY class name as well, and that is not tidiness -- it is
		# what makes this instrument usable across the 26.8.16 rename at all.
		#
		# A fingerprint exists to compare a page before a change against the same page after it,
		# so anything in it that is spelled differently on the two sides reports a difference that
		# is purely the instrument's. Counting only `atelier-item` against a pre-rename page finds
		# zero and records `tiles:0`, so EVERY page carrying a gallery compares as changed -- 52 of
		# 111 in the local rehearsal -- while the tag archives, having no tiles, compare as
		# identical. That shape is the tell: a difference that tracks whether the page has any
		# tiles at all, rather than which photographs are on it.
		#
		# Proved rather than assumed: recomputing a changed page's hash with the tile count forced
		# to 0 reproduced the before-hash exactly, so the image sets and titles were identical and
		# the count was the whole difference.
		#
		# Safe to drop the `tivira` alternative once no capture predating 26.8.16 is still being
		# compared against -- and harmless to leave, since a page cannot carry both.
		printf 'tiles:%s\n' "$(printf '%s' "$page" | grep -oE '(atelier|tivira)-(album-)?item' | wc -l | tr -d ' ')"

		# The document title, because the image set alone is blind on any page that has no
		# images -- and 60 of this site's 159 URLs are exactly that: 58 tag archives, which
		# list attachments the theme renders no thumbnails for, plus the protected gallery.
		# Without this the strongest thing "unchanged" could mean for those is "still empty",
		# which is satisfied by a page that broke into an empty one. The title comes from the
		# term or the post, so the migration must not move it.
		printf '%s' "$page" | grep -o '<title>[^<]*</title>' | head -1
	} | shasum | cut -d' ' -f1
}

cmd_capture() {
	local out="$1" urls="${2:-$WORK/urls.txt}"

	[ -s "$urls" ] || {
		echo "[ERROR] no URL list at $urls; run 'urls' first" >&2
		exit 2
	}

	: > "$out"

	# A while-read loop, never `for u in $LIST`: under zsh an unquoted variable does not
	# word-split, so the loop runs once with the whole list as a single token.
	while IFS= read -r u; do
		[ -z "$u" ] && continue

		local body code hash
		body="$(curl -sS --max-time 30 -w '\n@@STATUS:%{http_code}' "$u" 2>/dev/null)"
		code="$(printf '%s' "$body" | tail -1 | sed 's/^@@STATUS://')"
		# ATELIER_VERSION reaches the asset query strings, so a version bump changes every page.
		# Normalising ?ver= is what lets the rest be required to match exactly.
		hash="$(printf '%s' "$body" | sed '$d' | sed 's/?ver=[0-9.]*//g' | shasum | cut -d' ' -f1)"

		printf '%s\t%s\t%s\n' "$u" "$hash" "$code" >> "$out"
	done < "$urls"

	printf 'captured %s urls, non-200: %s\n' \
		"$(wc -l < "$out" | tr -d ' ')" \
		"$(awk -F'\t' '$3!=200' "$out" | wc -l | tr -d ' ')"
}

cmd_fingerprint() {
	local out="$1" urls="${2:-$WORK/urls.txt}"

	[ -s "$urls" ] || {
		echo "[ERROR] no URL list at $urls; run 'urls' first" >&2
		exit 2
	}

	: > "$out"

	while IFS= read -r u; do
		[ -z "$u" ] && continue

		local body code hash
		body="$(curl -sS --max-time 30 -w '\n@@STATUS:%{http_code}' "$u" 2>/dev/null)"
		code="$(printf '%s' "$body" | tail -1 | sed 's/^@@STATUS://')"
		hash="$(printf '%s' "$body" | sed '$d' | atelier_semantic)"

		printf '%s\t%s\t%s\n' "$u" "$hash" "$code" >> "$out"
	done < "$urls"

	printf 'fingerprinted %s urls, non-200: %s\n' \
		"$(wc -l < "$out" | tr -d ' ')" \
		"$(awk -F'\t' '$3!=200' "$out" | wc -l | tr -d ' ')"
}

cmd_compare() {
	local a="$1" b="$2"

	join -t "$(printf '\t')" -j1 <(sort "$a") <(sort "$b") > "$WORK/joined.tsv"

	local rows changed misjoined
	rows="$(wc -l < "$WORK/joined.tsv" | tr -d ' ')"
	changed="$(awk -F'\t' '$2!=$4' "$WORK/joined.tsv" | wc -l | tr -d ' ')"

	# The join's own sanity. A previous deploy compared the hash column against the status
	# column and reported all 116 URLs as changed; it failed safe, but only by luck.
	misjoined="$(awk -F'\t' 'NF!=5' "$WORK/joined.tsv" | wc -l | tr -d ' ')"

	printf 'joined %s of %s rows, malformed %s, changed %s, status differences %s\n' \
		"$rows" "$(wc -l < "$a" | tr -d ' ')" "$misjoined" "$changed" \
		"$(awk -F'\t' '$3!=$5' "$WORK/joined.tsv" | wc -l | tr -d ' ')"

	awk -F'\t' '$2!=$4 {print "  CHANGED  "$1}' "$WORK/joined.tsv"

	[ "$rows" = "$(wc -l < "$a" | tr -d ' ')" ] || {
		echo "[ERROR] the join dropped rows; the comparison covers less than it appears to" >&2
		return 1
	}

	return 0
}

case "${1:-}" in
	plan) cmd_plan ;;
	push) cmd_push ;;
	capture) cmd_capture "${2:?usage: capture <out> [urls]}" "${3:-}" ;;
	fingerprint) cmd_fingerprint "${2:?usage: fingerprint <out> [urls]}" "${3:-}" ;;
	compare) cmd_compare "${2:?usage: compare <before> <after>}" "${3:?}" ;;
	# order-check <deployed-tree> [file ...] -- the removed-method constraint on its own, against
	# a tree on disk instead of the server, with an order the caller chooses. Offline by design:
	# it is how tests/deploy-order-test.sh proves the check can go red without breaking a deploy.
	order-check)
		shift
		REF="${1:?usage: order-check <deployed-tree-dir> [file ...]}"
		shift

		[ -d "$REF" ] || {
			echo "[ERROR] no such deployed tree: $REF" >&2
			exit 2
		}

		[ "$#" -gt 0 ] && ORDER=( "$@" )
		check_removed_methods "$REF"
		;;
	*)
		sed -n '2,30p' "${BASH_SOURCE[0]}"
		exit 1
		;;
esac
