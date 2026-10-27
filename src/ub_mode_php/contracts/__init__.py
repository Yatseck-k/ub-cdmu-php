"""Validate the PHP analyzer's versioned JSON response."""

from typing import Any
from pathlib import PurePosixPath


def validate_analyzer_result(value: Any) -> dict:
    if not isinstance(value, dict) or value.get("schema_version") != "1.0":
        raise ValueError("unsupported analyzer schema_version")
    if not isinstance(value.get("units"), list) or not isinstance(value.get("diagnostics"), list):
        raise ValueError("analyzer result needs units and diagnostics lists")
    identifiers = set()
    for unit in value["units"]:
        if not isinstance(unit, dict):
            raise ValueError("unit must be an object")
        for key in ("id", "file", "namespace", "name", "kind", "start_line", "end_line", "complexity", "node_count", "features"):
            if key not in unit:
                raise ValueError(f"unit missing {key}")
        if unit["kind"] not in {"function", "method", "closure", "arrow"}:
            raise ValueError("invalid unit kind")
        if not all(isinstance(unit[key], str) for key in ("id", "file", "namespace", "name")):
            raise ValueError("invalid unit strings")
        file_path = PurePosixPath(unit["file"])
        if not unit["id"] or not unit["name"] or not unit["file"] or file_path.is_absolute() or any(part in {"", ".", ".."} for part in unit["file"].split("/")) or "\\" in unit["file"]:
            raise ValueError("invalid unit identity or file path")
        if unit["id"] in identifiers:
            raise ValueError("duplicate unit id")
        identifiers.add(unit["id"])
        if not all(isinstance(unit[key], int) and not isinstance(unit[key], bool) for key in ("start_line", "end_line", "complexity", "node_count")):
            raise ValueError("invalid unit numbers")
        if unit["start_line"] < 1 or unit["end_line"] < unit["start_line"] or unit["complexity"] < 1 or unit["node_count"] < 0:
            raise ValueError("invalid unit range or counts")
        if not isinstance(unit["features"], list) or not all(isinstance(feature, str) for feature in unit["features"]):
            raise ValueError("invalid unit features")
    for item in value["diagnostics"]:
        if not isinstance(item, dict) or not isinstance(item.get("file"), str) or not isinstance(item.get("message"), str):
            raise ValueError("invalid diagnostic")
    return value
