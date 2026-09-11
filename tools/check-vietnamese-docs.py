#!/usr/bin/env python3
"""Check local Markdown file links in the Vietnamese documentation, offline.

This checks file targets, not heading anchors or external websites.
"""
from pathlib import Path
import re
import sys
from urllib.parse import unquote, urlsplit

ROOT = Path(__file__).resolve().parents[1]


def main() -> int:
    sources = [ROOT / "README.vi.md", *sorted((ROOT / "docs" / "vi").glob("*.md"))]
    failures = []
    checked = 0
    for source in sources:
        if not source.is_file():
            failures.append(f"Missing documentation: {source.relative_to(ROOT)}")
            continue
        text = re.sub(r"```.*?```", "", source.read_text(encoding="utf-8"), flags=re.S)
        for raw in re.findall(r"\]\(([^)]+)\)", text):
            target = raw.strip().strip("<>")
            parsed = urlsplit(target)
            if parsed.scheme or parsed.netloc or not parsed.path:
                continue
            path = (source.parent / unquote(parsed.path)).resolve()
            checked += 1
            if not path.is_relative_to(ROOT) or not path.is_file():
                failures.append(f"{source.relative_to(ROOT)}: invalid file link {target}")
    for failure in failures:
        print(failure, file=sys.stderr)
    print(f"Vietnamese docs: {len(sources)} files, {checked} local links, {len(failures)} errors")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
