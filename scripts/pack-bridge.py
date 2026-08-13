#!/usr/bin/env python3
"""Pack plugin/delegat-bridge as a WordPress plugin zip (root folder delegat-bridge/)."""

from __future__ import annotations

import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "plugin" / "delegat-bridge"
OUT = ROOT / "dist" / "delegat-bridge.zip"


def main() -> None:
    if not SRC.is_dir():
        raise SystemExit(f"Missing {SRC}")
    OUT.parent.mkdir(parents=True, exist_ok=True)
    if OUT.exists():
        OUT.unlink()
    with zipfile.ZipFile(OUT, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        for path in sorted(SRC.rglob("*")):
            if not path.is_file():
                continue
            rel = path.relative_to(SRC).as_posix()
            if rel.endswith(".DS_Store"):
                continue
            zf.write(path, arcname=f"delegat-bridge/{rel}")
    print(f"Wrote {OUT} ({OUT.stat().st_size} bytes)")


if __name__ == "__main__":
    main()
