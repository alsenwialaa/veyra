#!/usr/bin/env python3
"""Build deterministic Veyra source and WordPress-installable ZIP archives."""

from __future__ import annotations

import argparse
import hashlib
import os
import sys
import zipfile
from pathlib import Path, PurePosixPath

from verify_release import VerificationError, verify


ROOT_NAME = "veyra-ai-commerce-agent"
FIXED_ZIP_TIME = (2026, 8, 24, 0, 0, 0)
RUNTIME_TOP_LEVEL = {
    "assets",
    "config",
    "languages",
    "src",
    "templates",
    "readme.txt",
    "uninstall.php",
    "veyra-ai-commerce-agent.php",
}
SOURCE_EXCLUDES = {".git", ".idea", ".vscode", "build", "dist", "node_modules", "vendor", "__pycache__"}


def files_for(root: Path, installable: bool) -> list[Path]:
    files: list[Path] = []
    for path in root.rglob("*"):
        if not path.is_file():
            continue
        relative = path.relative_to(root)
        if any(part in SOURCE_EXCLUDES for part in relative.parts):
            continue
        if installable and relative.parts[0] not in RUNTIME_TOP_LEVEL:
            continue
        files.append(path)
    return sorted(files, key=lambda item: item.relative_to(root).as_posix())


def write_zip(root: Path, destination: Path, installable: bool) -> tuple[int, str]:
    destination.parent.mkdir(parents=True, exist_ok=True)
    file_count = 0
    with zipfile.ZipFile(destination, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for source in files_for(root, installable):
            relative = PurePosixPath(ROOT_NAME) / PurePosixPath(source.relative_to(root).as_posix())
            info = zipfile.ZipInfo(str(relative), FIXED_ZIP_TIME)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.create_system = 3
            executable = source.stat().st_mode & 0o111
            info.external_attr = ((0o755 if executable else 0o644) & 0xFFFF) << 16
            archive.writestr(info, source.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
            file_count += 1

    digest = hashlib.sha256(destination.read_bytes()).hexdigest()
    return file_count, digest


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    root = args.root.resolve()
    output = args.output.resolve()

    try:
        state = verify(root)
    except VerificationError as exc:
        print(f"FAIL package verification: {exc}", file=sys.stderr)
        return 1

    version = state["plugin_version"]
    output.mkdir(parents=True, exist_ok=True)
    targets = [
        (output / f"Veyra-AI-Commerce-Agent-{version}-source.zip", False),
        (output / f"Veyra-AI-Commerce-Agent-{version}-installable.zip", True),
    ]
    for target, installable in targets:
        count, digest = write_zip(root, target, installable)
        print(f"{target.name}\tfiles={count}\tsha256={digest}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
