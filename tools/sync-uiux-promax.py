#!/usr/bin/env python3
"""Build a Design Core runtime catalog from a local UI UX Pro Max checkout.

This is a build/sync utility only. WordPress never runs Python and never fetches
upstream data at runtime.

Usage:
  python3 tools/sync-uiux-promax.py \
    --source-dir ../ui-ux-pro-max-skill \
    --output resources/design-intelligence/catalog.json
"""

from __future__ import annotations

import argparse
import csv
import json
import re
from pathlib import Path
from typing import Dict, Iterable, List

DATA_CANDIDATES = (
    Path(".claude/skills/ui-ux-pro-max/data"),
    Path("src/ui-ux-pro-max/data"),
    Path("cli/assets/data"),
)

# Product aliases are Design Core-owned matching hints layered on top of upstream
# terminology. They survive full catalog refreshes without modifying upstream data.
DESIGN_CORE_PRODUCT_ALIASES = {
    "B2B Service": [
        "engineering",
        "construction",
        "manpower",
        "recruitment",
        "workforce",
        "industrial",
        "engineering service",
        "construction service",
        "technical staffing",
    ],
}


def rows(path: Path) -> List[dict]:
    if not path.exists():
        return []
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        return list(csv.DictReader(handle))


def slug(value: str) -> str:
    value = re.sub(r"[^a-z0-9]+", "-", (value or "").lower()).strip("-")
    return value or "profile"


def split_plus(value: str) -> List[str]:
    return [part.strip() for part in re.split(r"\s*\+\s*|\s*,\s*", value or "") if part.strip()]


def words(value: str) -> set[str]:
    return {w for w in re.findall(r"[a-z0-9]+", (value or "").lower()) if len(w) > 2}


def find_data_dir(root: Path) -> Path:
    for relative in DATA_CANDIDATES:
        candidate = root / relative
        if (candidate / "products.csv").exists() and (candidate / "colors.csv").exists():
            return candidate
    raise SystemExit("Could not find UI UX Pro Max data directory under the supplied source checkout.")


def index_by(data: Iterable[dict], key: str) -> Dict[str, dict]:
    return {(row.get(key) or "").strip().casefold(): row for row in data if (row.get(key) or "").strip()}


def select_typography(product: dict, reasoning: dict, typography_rows: List[dict]) -> dict:
    query = " ".join(
        [
            product.get("Product Type", ""),
            product.get("Keywords", ""),
            product.get("Key Considerations", ""),
            reasoning.get("Typography_Mood", ""),
        ]
    )
    q = words(query)
    best, score = {}, -1
    for row in typography_rows:
        haystack = " ".join(
            [row.get("Font Pairing Name", ""), row.get("Mood/Style Keywords", ""), row.get("Best For", "")]
        )
        current = len(q & words(haystack))
        if current > score:
            best, score = row, current
    return best


def select_landing(pattern_name: str, landing_rows: List[dict]) -> dict:
    target = (pattern_name or "").strip().casefold()
    if not target:
        return {}
    for row in landing_rows:
        identities = [row.get("Pattern Name", ""), row.get("Pattern ID", "")]
        identities.extend((row.get("Aliases", "") or "").split("|"))
        if target in {item.strip().casefold() for item in identities if item.strip()}:
            return row
    target_words = words(pattern_name)
    best, score = {}, -1
    for row in landing_rows:
        current = len(target_words & words(" ".join([row.get("Pattern Name", ""), row.get("Keywords", ""), row.get("Aliases", "")])) )
        if current > score:
            best, score = row, current
    return best if score > 0 else {}


def recommended_shell(category: str, product: dict) -> tuple[str, str]:
    text = " ".join([category, product.get("Keywords", ""), product.get("Landing Page Pattern", "")]).lower()
    if any(key in text for key in ("hyperlocal", "location", "local service", "nearby")):
        return "location-landing", "strong"
    if any(key in text for key in ("documentation", "knowledge base", "article", "resource", "blog", "news")):
        return "resource-article", "approximate"
    if "import" in text:
        return "import-guide", "strong"
    if any(key in text for key in ("contact", "about")):
        return "contact-about", "approximate"
    if any(key in text for key in ("service", "saas", "consult", "agency", "professional", "legal", "insurance", "spa", "restaurant", "fitness", "education", "health")):
        return "service-landing", "approximate"
    return "", "unavailable"


def normalize_sections(landing: dict, product: dict) -> List[str]:
    raw = landing.get("Section Order", "") if landing else ""
    if raw:
        out = []
        for part in raw.split(">"):
            clean = re.sub(r"\([^)]*\)", "", part).strip()
            if clean:
                out.append(slug(clean)[:48])
        if out:
            return out
    pattern = product.get("Landing Page Pattern", "")
    return [slug(part) for part in re.split(r"\s*\+\s*", pattern) if part.strip()]


def color_tokens(row: dict) -> dict:
    mapping = {
        "Primary": "primary", "On Primary": "on_primary", "Secondary": "secondary",
        "On Secondary": "on_secondary", "Accent": "accent", "On Accent": "on_accent",
        "Background": "background", "Foreground": "foreground", "Card": "card",
        "Card Foreground": "card_foreground", "Muted": "muted", "Muted Foreground": "muted_foreground",
        "Border": "border", "Destructive": "destructive", "On Destructive": "on_destructive", "Ring": "ring",
    }
    return {target: row.get(source) for source, target in mapping.items() if row.get(source)}


def normalize_ux_rules(data: List[dict]) -> List[dict]:
    rules = []
    for row in data:
        number = row.get("No", "")
        category = row.get("Category", "")
        issue = row.get("Issue", "")
        key = (category + " " + issue).lower()
        if "motion" in key:
            check = "browser-reduced-motion"
        elif "focus" in key:
            check = "browser-focus"
        elif "target size" in key or "touch" in key:
            check = "browser-touch-target"
        elif "contrast" in key:
            check = "browser-contrast"
        elif "heading" in key:
            check = "elementor-heading-order"
        elif "alt" in key or "image" in key:
            check = "elementor-image-alt"
        elif any(term in key for term in ("keyboard", "drag", "carousel", "hover", "form", "authentication")):
            check = "interaction"
        elif "wrap" in key or "overflow" in key:
            check = "browser-overflow"
        else:
            check = "guidance"
        rules.append(
            {
                "id": f"uuxpm-{number or slug(issue)}",
                "category": category,
                "severity": (row.get("Severity") or "medium").lower(),
                "check": check,
                "guidance": row.get("Do") or row.get("Description") or issue,
                "issue": issue,
                "platform": row.get("Platform", ""),
                "avoid": row.get("Don't", ""),
            }
        )
    return rules


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--source-dir", required=True, help="Path to a local clone/check-out of nextlevelbuilder/ui-ux-pro-max-skill")
    parser.add_argument("--output", default="resources/design-intelligence/catalog.json")
    args = parser.parse_args()

    source_root = Path(args.source_dir).expanduser().resolve()
    data_dir = find_data_dir(source_root)
    products = rows(data_dir / "products.csv")
    colors = rows(data_dir / "colors.csv")
    reasoning = rows(data_dir / "ui-reasoning.csv")
    typography = rows(data_dir / "typography.csv")
    landing = rows(data_dir / "landing.csv")
    ux = rows(data_dir / "ux-guidelines.csv")

    color_index = index_by(colors, "Product Type")
    reasoning_index = index_by(reasoning, "UI_Category")
    profiles = []

    for product in products:
        category = (product.get("Product Type") or "").strip()
        if not category:
            continue
        reason = reasoning_index.get(category.casefold(), {})
        palette = color_index.get(category.casefold(), {})
        landing_row = select_landing(product.get("Landing Page Pattern", "") or reason.get("Recommended_Pattern", ""), landing)
        font = select_typography(product, reason, typography)
        shell, shell_fit = recommended_shell(category, product)
        keywords = [item.strip() for item in (product.get("Keywords") or "").split(",") if item.strip()]
        keywords.extend(DESIGN_CORE_PRODUCT_ALIASES.get(category, []))
        styles = split_plus(product.get("Primary Style Recommendation", "")) + split_plus(product.get("Secondary Styles", ""))
        profiles.append(
            {
                "id": slug(category),
                "category": category,
                "keywords": list(dict.fromkeys(keywords)),
                "pattern": product.get("Landing Page Pattern") or reason.get("Recommended_Pattern", ""),
                "pattern_id": landing_row.get("Pattern ID", ""),
                "styles": list(dict.fromkeys(styles)),
                "color_mood": reason.get("Color_Mood") or product.get("Color Palette Focus", ""),
                "typography_mood": reason.get("Typography_Mood", ""),
                "heading_font": font.get("Heading Font", "Inter"),
                "body_font": font.get("Body Font", "Inter"),
                "effects": split_plus(reason.get("Key_Effects", "")) or split_plus(landing_row.get("Recommended Effects", "")),
                "anti_patterns": split_plus(reason.get("Anti_Patterns", "")),
                "recommended_shell": shell,
                "shell_fit": shell_fit,
                "sections": normalize_sections(landing_row, product),
                "colors": color_tokens(palette),
                "defaults": {"variance": 5, "motion": 4, "density": 5},
                "considerations": [product.get("Key Considerations", "")] if product.get("Key Considerations") else [],
            }
        )

    bundle = {
        "schema_version": 1,
        "source": {
            "name": "UI UX Pro Max",
            "repository": "https://github.com/nextlevelbuilder/ui-ux-pro-max-skill",
            "license": "MIT",
            "snapshot_kind": "full-local-sync",
            "data_dir": str(data_dir.relative_to(source_root)),
            "note": "Generated locally by Design Core tools/sync-uiux-promax.py. Review before committing.",
        },
        "product_profiles": profiles,
        "ux_rules": normalize_ux_rules(ux),
    }

    output = Path(args.output).expanduser()
    if not output.is_absolute():
        output = Path.cwd() / output
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(bundle, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"Wrote {len(profiles)} product profiles and {len(bundle['ux_rules'])} UX rules to {output}")


if __name__ == "__main__":
    main()
