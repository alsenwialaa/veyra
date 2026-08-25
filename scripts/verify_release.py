#!/usr/bin/env python3
"""Deterministic source/package contract checks for Veyra.

This script intentionally proves only static repository invariants. It never
upgrades those checks into WordPress, WooCommerce, provider, accessibility, or
release-certification evidence.
"""

from __future__ import annotations

import argparse
import gettext
import json
import re
import sys
from pathlib import Path
from typing import Any


class VerificationError(RuntimeError):
    pass


def load_json(path: Path) -> Any:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise VerificationError(f"invalid JSON: {path}: {exc}") from exc


def require(condition: bool, message: str) -> None:
    if not condition:
        raise VerificationError(message)


def captured(pattern: str, text: str, label: str) -> str:
    match = re.search(pattern, text, flags=re.MULTILINE)
    if match is None:
        raise VerificationError(f"missing {label}")
    return match.group(1)


def verify_versions(root: Path) -> dict[str, str]:
    main = (root / "veyra-ai-commerce-agent.php").read_text(encoding="utf-8")
    readme = (root / "readme.txt").read_text(encoding="utf-8")

    header = captured(r"^ \* Version:\s*([^\s]+)\s*$", main, "plugin Version header")
    constant = captured(r"define\(\s*'VEYRA_VERSION'\s*,\s*'([^']+)'\s*\);", main, "VEYRA_VERSION")
    stable = captured(r"^Stable tag:\s*([^\s]+)\s*$", readme, "readme Stable tag")
    require(header == constant == stable, f"version mismatch: header={header}, constant={constant}, stable={stable}")

    schema = captured(r"define\(\s*'VEYRA_SCHEMA_VERSION'\s*,\s*'([^']+)'\s*\);", main, "schema version")
    return {"plugin_version": header, "schema_version": schema}


def verify_contract_counts(root: Path) -> dict[str, int]:
    contracts = root / "config" / "contracts"
    manifest = load_json(contracts / "proposal-manifest.json")
    proposal = manifest.get("proposal", {})
    require(manifest.get("verification", {}).get("status") == "verified", "canonical proposal is not verified")

    logical = load_json(contracts / "logical-tool-catalog.json")
    tools = logical.get("tools", [])
    require(isinstance(tools, list), "logical tool catalog tools must be a list")
    tool_names = [row.get("name") for row in tools if isinstance(row, dict)]
    require(len(tool_names) == len(set(tool_names)), "logical tool catalog contains duplicate names")
    require(len(tool_names) == int(proposal.get("required_logical_tool_count", -1)), "logical tool count mismatch")

    capabilities = load_json(contracts / "capabilities.json").get("capabilities", [])
    require(isinstance(capabilities, list), "capabilities must be a list")
    capability_names = [row.get("capability") for row in capabilities if isinstance(row, dict)]
    require(len(capability_names) == len(set(capability_names)), "capability registry contains duplicates")
    require(len(capability_names) == int(proposal.get("capability_count", -1)), "capability count mismatch")

    features = load_json(contracts / "feature-registry.json")
    entries = features.get("entries", [])
    require(isinstance(entries, list), "feature entries must be a list")
    feature_keys = [row.get("key") for row in entries if isinstance(row, dict)]
    require(len(feature_keys) == len(set(feature_keys)), "feature registry contains duplicate keys")
    core = sum(1 for row in entries if isinstance(row, dict) and row.get("release_unit") == "production_core")
    optional = sum(1 for row in entries if isinstance(row, dict) and row.get("release_unit") == "optional_module")
    require(core == int(proposal.get("production_core_feature_count", -1)), "Production Core feature count mismatch")
    require(optional == int(proposal.get("optional_module_count", -1)), "optional module count mismatch")

    return {
        "logical_tools": len(tool_names),
        "capabilities": len(capability_names),
        "production_core_features": core,
        "optional_modules": optional,
    }


def verify_json_tree(root: Path) -> int:
    count = 0
    for path in sorted(root.rglob("*.json")):
        load_json(path)
        count += 1
    return count


def verify_source_boundaries(root: Path) -> dict[str, int]:
    php_files = sorted((root / "src").rglob("*.php"))
    joined = "\n".join(path.read_text(encoding="utf-8", errors="strict") for path in php_files)

    forbidden = {
        "WooCommerce internal namespace": r"Automattic\\\\WooCommerce\\\\Internal",
        "direct order posts table SQL": r"(?is)(SELECT|UPDATE|INSERT|DELETE).{0,240}\$?wpdb->posts",
        "direct order postmeta SQL": r"(?is)(SELECT|UPDATE|INSERT|DELETE).{0,240}\$?wpdb->postmeta",
        "public sensitive REST permission": r"permission_callback\s*['\"]?\s*=>\s*['\"]__return_true['\"]",
    }
    for label, pattern in forbidden.items():
        require(re.search(pattern, joined) is None, f"forbidden source signal: {label}")

    route_count = joined.count("register_rest_route(")
    permission_count = joined.count("'permission_callback'") + joined.count('"permission_callback"')
    require(permission_count >= route_count, "one or more REST route declarations lack an explicit permission callback signal")

    return {"php_source_files": len(php_files), "rest_routes": route_count}


def verify_localization(root: Path) -> dict[str, int]:
    po = root / "languages" / "veyra-ai-commerce-agent-ar.po"
    mo = root / "languages" / "veyra-ai-commerce-agent-ar.mo"
    require(po.is_file() and mo.is_file(), "Arabic PO/MO catalog is missing")

    try:
        with mo.open("rb") as stream:
            catalog = gettext.GNUTranslations(stream)
    except (OSError, EOFError) as exc:
        raise VerificationError(f"invalid Arabic MO catalog: {exc}") from exc

    customer = (root / "src" / "Experience" / "Presentation" / "CustomerExperience.php").read_text(encoding="utf-8")
    block = re.search(r"private function defaultStrings\(\): array\s*\{(?P<body>.*?)\n\s*if \(function_exists\('__'\)\)", customer, re.DOTALL)
    require(block is not None, "customer string catalog block is unavailable")
    messages = re.findall(r"^\s*'[^']+'\s*=>\s*'([^']*)',\s*$", block.group("body"), re.MULTILINE)
    require(messages, "customer string catalog is empty")
    untranslated = [message for message in messages if catalog.gettext(message) == message]
    require(not untranslated, "Arabic catalog lacks customer strings: " + ", ".join(untranslated[:5]))

    return {"arabic_customer_strings": len(messages)}


def verify(root: Path) -> dict[str, Any]:
    require((root / "veyra-ai-commerce-agent.php").is_file(), "plugin bootstrap not found")
    result: dict[str, Any] = {}
    result.update(verify_versions(root))
    result.update(verify_contract_counts(root))
    result["json_documents"] = verify_json_tree(root / "config")
    result.update(verify_source_boundaries(root))
    result.update(verify_localization(root))
    return result


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("root", nargs="?", default=Path(__file__).resolve().parents[1], type=Path)
    args = parser.parse_args()
    root = args.root.resolve()

    try:
        result = verify(root)
    except VerificationError as exc:
        print(f"FAIL {exc}", file=sys.stderr)
        return 1

    print("PASS Veyra static release contracts")
    for key, value in result.items():
        print(f"{key}={value}")
    print("scope=static-only; live release gates remain separate")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
