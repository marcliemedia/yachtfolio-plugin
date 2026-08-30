#!/usr/bin/env python3
"""Refresh this repository from the working plugin in the Local install.

WHY THE REPO LIVES OUTSIDE THE WORDPRESS INSTALL
------------------------------------------------
If `.git` sat inside wp-content/plugins/otium-yachtfolio-sync, any file-copy
deployment would carry it onto the live server, and an exposed `.git` directory
hands the whole source history to anyone who requests
/wp-content/plugins/otium-yachtfolio-sync/.git/config. This plugin *is* deployed
by copying files, so the risk is real rather than theoretical.

The cost is this script.

Usage
    python sync-from-local.py             # mirror, then show what changed
    python sync-from-local.py --commit    # mirror and commit at the plugin version

Override the source with the OY_YF_SRC environment variable.
"""

from __future__ import annotations

import os
import re
import shutil
import subprocess
import sys
from pathlib import Path

DEFAULT_SRC = r"D:/local-wordpress/otium-yatch/app/public/wp-content/plugins/otium-yachtfolio-sync"

# Files that belong to the repository, not to the plugin. Never mirrored away.
REPO_OWNED = {".git", ".gitignore", ".gitattributes", "README.md", "sync-from-local.py"}

# A credential literal in source. Matches an assignment, not a variable name, so
# `$settings->passkey_source()` and `OY_YF_PASSKEY_LIVE` do not trip it.
CREDENTIAL = re.compile(
    r"""(passkey|password|secret|token|api_?key)["']?\s*(=>|=|:)\s*["'][^"']{8,}""",
    re.IGNORECASE,
)


def fail(message: str) -> None:
    print(message, file=sys.stderr)
    raise SystemExit(1)


def mirror(src: Path, repo: Path) -> None:
    """Make repo match src, so a file deleted upstream disappears here too."""
    for entry in repo.iterdir():
        if entry.name in REPO_OWNED:
            continue
        shutil.rmtree(entry) if entry.is_dir() else entry.unlink()

    for entry in src.iterdir():
        if entry.name in REPO_OWNED:
            continue
        target = repo / entry.name
        shutil.copytree(entry, target) if entry.is_dir() else shutil.copy2(entry, target)


def scan_for_secrets(repo: Path) -> list[str]:
    """Check the working tree, not the diff — a file added *and* ignored still
    deserves an alarm."""
    hits = []
    roots = [repo / "src", *repo.glob("*.php")]
    for root in roots:
        files = root.rglob("*.php") if root.is_dir() else [root]
        for path in files:
            try:
                text = path.read_text(encoding="utf-8", errors="replace")
            except OSError:
                continue
            for n, line in enumerate(text.splitlines(), 1):
                if CREDENTIAL.search(line):
                    hits.append(f"{path.relative_to(repo).as_posix()}:{n}: {line.strip()[:100]}")
    return hits


def plugin_version(repo: Path) -> str:
    header = (repo / "otium-yachtfolio-sync.php").read_text(encoding="utf-8", errors="replace")
    match = re.search(r"Version:\s*([0-9.]+)", header)
    return match.group(1) if match else "unknown"


def git(repo: Path, *args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["git", "-C", str(repo), *args],
        capture_output=True, text=True, check=check,
    )


def main() -> int:
    repo = Path(__file__).resolve().parent
    src = Path(os.environ.get("OY_YF_SRC", DEFAULT_SRC))

    if not (src / "otium-yachtfolio-sync.php").is_file():
        fail(f"not a plugin directory: {src}\nset OY_YF_SRC to the plugin path")

    mirror(src, repo)

    hits = scan_for_secrets(repo)
    if hits:
        print("REFUSING: a credential literal appeared in the source", file=sys.stderr)
        for hit in hits[:5]:
            print("  " + hit, file=sys.stderr)
        return 1

    git(repo, "add", "-A")
    staged = git(repo, "diff", "--cached", "--quiet", check=False).returncode != 0

    if "--commit" not in sys.argv:
        status = git(repo, "status", "--short").stdout.strip()
        print(status if status else "nothing changed")
        if staged:
            print("\nrun with --commit to commit the above")
        return 0

    if not staged:
        print("nothing changed")
        return 0

    version = plugin_version(repo)
    git(repo, "-c", "core.safecrlf=false", "commit", "-q", "-m", f"Otium Yachtfolio Sync {version}")
    print(f"committed {version}")
    print(git(repo, "log", "--oneline", "-1").stdout.strip())
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
