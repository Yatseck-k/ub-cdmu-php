"""Project configuration and input selection."""

from copy import deepcopy
import math
from pathlib import Path
import subprocess

import yaml


DEFAULT_CONFIG = {
    "version": 1,
    "project": {"language": "php", "source": ["src", "app"], "exclude": ["vendor", "tests", "var", "storage", "bootstrap/cache", ".metrics"]},
    "crapper": {"enabled": True, "threshold": 10, "coverage": {"format": "clover", "path": "coverage.xml", "missing": "unknown"}},
    "dryer": {"enabled": True, "threshold": 0.82, "min_lines": 4, "min_nodes": 20},
    "reports": {"formats": ["json", "edn"], "directory": ".metrics"},
    "quality": {"fail_on": ["exceeded_crap_threshold"]},
}


def _merge(base: dict, override: dict) -> dict:
    for key, value in override.items():
        if key not in base:
            raise ValueError(f"unknown configuration key: {key}")
        if isinstance(base[key], dict):
            if not isinstance(value, dict):
                raise ValueError(f"configuration {key} must be a mapping")
            _merge(base[key], value)
        else:
            base[key] = value
    return base


def _relative_path(value: str) -> str:
    if not isinstance(value, str) or not value or Path(value).is_absolute() or "\\" in value or any(part in {"", ".", ".."} for part in value.split("/")):
        raise ValueError(f"path must be relative to the project: {value!r}")
    return value


def load_config(root: Path, path: Path | None = None) -> dict:
    config = deepcopy(DEFAULT_CONFIG)
    source = path or root / ".ub-mode.yaml"
    if source.exists():
        try:
            override = yaml.safe_load(source.read_text(encoding="utf-8"))
        except yaml.YAMLError as exc:
            raise ValueError(f"invalid YAML configuration: {exc}") from exc
        if not isinstance(override, dict):
            raise ValueError("configuration must be a mapping")
        _merge(config, override)
    if config["version"] != 1 or config["project"]["language"] != "php":
        raise ValueError("only version 1 PHP configuration is supported")
    for group in ("project", "crapper", "dryer", "reports", "quality"):
        if not isinstance(config[group], dict):
            raise ValueError(f"{group} must be a mapping")
    for key in ("source", "exclude"):
        values = config["project"][key]
        if not isinstance(values, list):
            raise ValueError(f"project.{key} must be a list")
        config["project"][key] = [_relative_path(item) for item in values]
    for group in ("crapper", "dryer"):
        if not isinstance(config[group]["enabled"], bool):
            raise ValueError(f"{group}.enabled must be a boolean")
    if isinstance(config["crapper"]["threshold"], bool) or not isinstance(config["crapper"]["threshold"], (int, float)) or not math.isfinite(config["crapper"]["threshold"]) or config["crapper"]["threshold"] <= 0:
        raise ValueError("crapper.threshold must be positive")
    coverage = config["crapper"]["coverage"]
    if coverage["format"] != "clover" or coverage["missing"] != "unknown":
        raise ValueError("only Clover coverage with unknown missing values is supported")
    coverage["path"] = _relative_path(coverage["path"])
    dryer = config["dryer"]
    if isinstance(dryer["threshold"], bool) or not isinstance(dryer["threshold"], (int, float)) or not math.isfinite(dryer["threshold"]) or not 0 <= dryer["threshold"] <= 1:
        raise ValueError("dryer.threshold must be between 0 and 1")
    if any(isinstance(dryer[key], bool) or not isinstance(dryer[key], int) or dryer[key] < 1 for key in ("min_lines", "min_nodes")):
        raise ValueError("dryer minimums must be positive integers")
    reports = config["reports"]
    reports["directory"] = _relative_path(reports["directory"])
    if not isinstance(reports["formats"], list) or any(fmt not in {"json", "edn"} for fmt in reports["formats"]):
        raise ValueError("reports.formats must contain json and/or edn")
    if not isinstance(config["quality"]["fail_on"], list) or any(item != "exceeded_crap_threshold" for item in config["quality"]["fail_on"]):
        raise ValueError("unsupported quality.fail_on entry")
    return config


def _excluded(path: str, exclusions: list[str]) -> bool:
    return any(path == prefix or path.startswith(prefix + "/") or "/" + prefix + "/" in "/" + path for prefix in exclusions)


def source_files(root: Path, config: dict, changed: bool = False) -> list[str]:
    sources = config["project"]["source"]
    exclusions = config["project"]["exclude"]
    files: set[str] = set()
    if changed:
        commands = [
            ["git", "diff", "--name-only", "--diff-filter=ACMR", "-z"],
            ["git", "diff", "--cached", "--name-only", "--diff-filter=ACMR", "-z"],
            ["git", "ls-files", "--others", "--exclude-standard", "-z"],
        ]
        for command in commands:
            process = subprocess.run(command, cwd=root, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            if process.returncode:
                raise ValueError(process.stderr.decode("utf-8", errors="replace").strip() or "Git changed-file selection failed")
            output = process.stdout
            files.update(part.decode("utf-8") for part in output.split(b"\0") if part)
    else:
        for source in sources:
            directory = root / source
            if directory.is_dir():
                files.update(path.relative_to(root).as_posix() for path in directory.rglob("*.php") if path.is_file())
    selected = []
    root_real = root.resolve()
    for file in files:
        path = root / file
        try:
            relative = path.resolve().relative_to(root_real).as_posix()
        except ValueError:
            continue
        if path.suffix != ".php" or not path.is_file():
            continue
        if not any(relative == source or relative.startswith(source + "/") for source in sources):
            continue
        if _excluded(relative, exclusions):
            continue
        selected.append(relative)
    return sorted(set(selected))
