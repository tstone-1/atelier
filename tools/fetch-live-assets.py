#!/usr/bin/env python3
"""Fetch the live site's theme and Envira plugin files into the dev environment.

    python3 tools/fetch-live-assets.py [--dest DIR] [--dry-run]

The dev environment starts from a database dump, which carries every gallery but none of the
code that reads them. Two things therefore have to come from the server:

- **The active theme.** Without one WordPress renders a zero-byte page, and a zero-byte page
  behind an HTTP 200 looks exactly like a working one.
- **Envira and its addons.** Coexistence, the takeover setting and the duplicate-rewrite-slug
  conflict are all claims about how Lichtbild behaves *next to* Envira. Modelling Envira is what
  the stub suite already does; the point of this environment is not to model it.

Read-only in the strictest sense: it opens an FTPS connection, lists, and retrieves. There is
no code path here that writes, renames or deletes anything on the server.

Credentials come from the same .netrc the rest of this work uses and are never printed.
"""
import argparse
import ftplib
import os
import netrc
import pathlib
import ssl
import sys

# Read from the environment or the gitignored `tools/deploy.env`, never a literal: this
# repository is public and an FTP hostname paired with its username is two thirds of a login.
HOST = os.environ.get("LICHTBILD_DEPLOY_HOST", "")

if not HOST:
    _env = pathlib.Path(__file__).with_name("deploy.env")
    if _env.exists():
        for _line in _env.read_text().splitlines():
            if _line.startswith("LICHTBILD_DEPLOY_HOST="):
                HOST = _line.split("=", 1)[1].strip()

if not HOST:
    sys.exit(
        "[ERROR] set LICHTBILD_DEPLOY_HOST, or create tools/deploy.env with\n"
        "        LICHTBILD_DEPLOY_HOST=ftp.example.com"
    )

# Everything needed to render a gallery the way the live site does, and nothing else. The
# other nine bundled themes and thirteen unrelated plugins would only slow the fetch down.
WANTED = [
    ("wp-content/themes", ["twentytwenty"]),
    ("wp-content/plugins", None),  # None means "every directory matching PLUGIN_PREFIX"
]

PLUGIN_PREFIX = "envira"


def connect(netrc_path):
    """Open an authenticated FTPS session."""
    login, _, password = netrc.netrc(netrc_path).authenticators(HOST)
    ftp = ftplib.FTP_TLS(context=ssl.create_default_context())
    ftp.connect(HOST, 21, timeout=60)
    ftp.login(login, password)
    ftp.prot_p()
    return ftp


def entries(ftp, path):
    """Yield (name, is_directory, size) for one remote directory.

    MLSD is used where the server offers it because parsing `LIST` output is guesswork, and a
    directory misread as a file is a silently truncated download rather than an error.
    """
    try:
        for name, facts in ftp.mlsd(path):
            if name in (".", ".."):
                continue
            yield name, facts.get("type") == "dir", int(facts.get("size", 0))
        return
    except (ftplib.error_perm, ftplib.error_proto):
        pass

    lines = []
    ftp.retrlines(f"LIST {path}", lines.append)

    for line in lines:
        parts = line.split(maxsplit=8)
        if len(parts) < 9:
            continue
        name = parts[8]
        if name in (".", ".."):
            continue
        yield name, line.startswith("d"), int(parts[4])


def fetch_tree(ftp, remote, local, stats, dry_run):
    """Recursively download one remote directory."""
    local.mkdir(parents=True, exist_ok=True)

    for name, is_dir, size in entries(ftp, remote):
        remote_path = f"{remote}/{name}"
        local_path = local / name

        if is_dir:
            fetch_tree(ftp, remote_path, local_path, stats, dry_run)
            continue

        stats["files"] += 1
        stats["bytes"] += size

        if dry_run:
            continue

        # Skip what is already here at the same size, so an interrupted run resumes cheaply
        # instead of starting over.
        if local_path.exists() and local_path.stat().st_size == size:
            stats["skipped"] += 1
            continue

        with open(local_path, "wb") as handle:
            ftp.retrbinary(f"RETR {remote_path}", handle.write)

        stats["written"] += 1

        if stats["written"] % 200 == 0:
            print(f"  {stats['written']} files, {stats['bytes'] / 1e6:.0f} MB", flush=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--dest", default=str(pathlib.Path.home() / "Developer/wp-lichtbild/wordpress"))
    parser.add_argument("--netrc", required=True, help="path to a .netrc holding the FTPS login")
    parser.add_argument("--dry-run", action="store_true", help="count what would be fetched")
    args = parser.parse_args()

    dest = pathlib.Path(args.dest)

    if not dest.is_dir():
        sys.exit(f"[ERROR] destination is not a directory: {dest}")

    ftp = connect(args.netrc)
    stats = {"files": 0, "bytes": 0, "written": 0, "skipped": 0}

    try:
        for parent, explicit in WANTED:
            if explicit is None:
                names = sorted(
                    name
                    for name, is_dir, _ in entries(ftp, parent)
                    if is_dir and name.startswith(PLUGIN_PREFIX)
                )
            else:
                names = explicit

            print(f"[fetch] {parent}: {len(names)} director{'y' if len(names) == 1 else 'ies'}")

            for name in names:
                print(f"  {name}", flush=True)
                fetch_tree(ftp, f"{parent}/{name}", dest / parent / name, stats, args.dry_run)
    finally:
        try:
            ftp.quit()
        except Exception:  # noqa: BLE001 - a failed QUIT says nothing about the transfer
            ftp.close()

    verb = "would fetch" if args.dry_run else "fetched"
    print(
        f"[fetch] {verb} {stats['files']} files, {stats['bytes'] / 1e6:.1f} MB "
        f"(written {stats['written']}, already present {stats['skipped']})"
    )

    # A run that transferred nothing is reported as such rather than as a success, because an
    # empty fetch and a complete one otherwise print the same closing line.
    if not args.dry_run and stats["written"] == 0 and stats["skipped"] == 0:
        sys.exit("[ERROR] nothing was fetched - check the remote paths")


if __name__ == "__main__":
    main()
