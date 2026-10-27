"""The ub-php command line interface."""

import argparse
import json
import math
import os
from pathlib import Path
import subprocess
import sys
import tempfile

from ub_mode_php.contracts import validate_analyzer_result
from ub_mode_php.core import load_config, source_files
from ub_mode_php.crapper import analyze as analyze_crap, read_clover
from ub_mode_php.dryer import find_duplicates
from ub_mode_php.reports import crap_edn, dry_edn


SCHEMA_VERSION = "1.0"


class CliParser(argparse.ArgumentParser):
    def error(self, message: str) -> None:
        self.print_usage(sys.stderr)
        print(f"ub-php: {message}", file=sys.stderr)
        raise SystemExit(4)


def _analyzer_path() -> Path:
    override = os.environ.get("UB_MODE_PHP_ANALYZER")
    if override:
        return Path(override)
    return Path(__file__).resolve().parents[3] / "php/analyzer/bin/analyze.php"


def _php_binary() -> str:
    return os.environ.get("UB_MODE_PHP_BIN", "php")


def _invoke_analyzer(root: Path, files: list[str]) -> dict:
    script = _analyzer_path()
    if not script.is_file() or not (script.parents[1] / "vendor/autoload.php").is_file():
        raise RuntimeError("PHP analyzer dependencies are missing; run composer install in php/analyzer")
    process = subprocess.run(
        [_php_binary(), str(script)],
        input=json.dumps({"schema_version": SCHEMA_VERSION, "root": str(root), "files": files}),
        capture_output=True,
        text=True,
        check=False,
    )
    if process.returncode:
        raise RuntimeError(process.stderr.strip() or "PHP analyzer failed")
    try:
        return validate_analyzer_result(json.loads(process.stdout))
    except (json.JSONDecodeError, ValueError) as exc:
        raise RuntimeError(f"invalid PHP analyzer response: {exc}") from exc


def _write_atomic(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", dir=path.parent, prefix=".ub-php-", delete=False) as stream:
        temporary = Path(stream.name)
        stream.write(content)
    try:
        os.replace(temporary, path)
    finally:
        temporary.unlink(missing_ok=True)


def _doctor(root: Path, as_json: bool) -> int:
    script = _analyzer_path()
    php_version = None
    try:
        process = subprocess.run([_php_binary(), "-r", "echo PHP_VERSION_ID;"], capture_output=True, text=True, check=True)
        php_version = int(process.stdout.strip())
    except (OSError, ValueError, subprocess.SubprocessError):
        pass
    checks = {
        "python": sys.version.split()[0],
        "php_version_id": php_version,
        "php_supported": php_version is not None and php_version >= 80200,
        "analyzer": script.is_file(),
        "composer_dependencies": (script.parents[1] / "vendor/autoload.php").is_file(),
        "project": root.is_dir(),
    }
    if as_json:
        print(json.dumps({"schema_version": SCHEMA_VERSION, "checks": checks}, sort_keys=True))
    else:
        for name, value in checks.items():
            print(f"{name}: {value}")
    return 0 if all(checks[key] for key in ("php_supported", "analyzer", "composer_dependencies", "project")) else 3


def main(argv: list[str] | None = None) -> int:
    parser = CliParser(prog="ub-php")
    parser.add_argument("--root", type=Path, default=Path.cwd(), help="PHP project root")
    parser.add_argument("--config", type=Path, help="configuration YAML path")
    subparsers = parser.add_subparsers(dest="command", required=True)
    for command in ("doctor", "check", "crap", "dry"):
        sub = subparsers.add_parser(command)
        sub.add_argument("--format", choices=("text", "json"), default="text")
        if command != "doctor":
            sub.add_argument("--changed", action="store_true")
            sub.add_argument("--threshold", type=float, help="override this analyzer's threshold")
    args = parser.parse_args(argv)
    root = args.root.resolve()
    if args.command == "doctor":
        return _doctor(root, args.format == "json")
    try:
        config = load_config(root, args.config)
        files = source_files(root, config, args.changed)
        if not args.changed and not files:
            raise ValueError("no PHP files found in configured source directories")
        result = _invoke_analyzer(root, files)
        if result["diagnostics"]:
            raise RuntimeError("; ".join(f"{item['file']}: {item['message']}" for item in result["diagnostics"]))
        run_crap = args.command in {"check", "crap"} and config["crapper"]["enabled"]
        run_dry = args.command in {"check", "dry"} and config["dryer"]["enabled"]
        if args.threshold is not None and (args.command == "check" or not math.isfinite(args.threshold) or args.threshold <= 0):
            raise ValueError("--threshold applies to crap or dry and must be positive")
        coverage = {}
        if run_crap:
            coverage_path = root / config["crapper"]["coverage"]["path"]
            if coverage_path.exists():
                coverage = read_clover(coverage_path, root)
        crap_threshold = args.threshold if args.command == "crap" and args.threshold is not None else config["crapper"]["threshold"]
        dry_threshold = args.threshold if args.command == "dry" and args.threshold is not None else config["dryer"]["threshold"]
        if run_dry and dry_threshold > 1:
            raise ValueError("Dryer threshold must be between 0 and 1")
        entries = analyze_crap(result["units"], coverage, crap_threshold) if run_crap else []
        candidates = find_duplicates(result["units"], dry_threshold, config["dryer"]["min_lines"], config["dryer"]["min_nodes"]) if run_dry else []
        report = {"schema_version": SCHEMA_VERSION, "tool": args.command, "entries": entries, "candidates": candidates, "diagnostics": []}
        serialized = json.dumps(report, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n"
        directory = root / config["reports"]["directory"]
        if "json" in config["reports"]["formats"]:
            _write_atomic(directory / f"{args.command}.json", serialized)
        if "edn" in config["reports"]["formats"]:
            if run_crap:
                _write_atomic(directory / "crap.edn", crap_edn(entries))
            if run_dry:
                _write_atomic(directory / "dry.edn", dry_edn(candidates))
        if args.format == "json":
            print(serialized, end="")
        else:
            print(f"analyzed {len(files)} PHP files; {len(entries)} CRAP entries; {len(candidates)} Dryer candidates")
            for entry in entries:
                value = "unknown" if entry["crap"] is None else f"{entry['crap']:.3f}"
                print(f"CRAP {value} CC={entry['complexity']} {entry['file']}:{entry['start_line']} {entry['name']}")
            for candidate in candidates:
                left, right = candidate["left"], candidate["right"]
                print(f"DRY {candidate['score']:.3f} {left['file']}:{left['start_line']}-{left['end_line']} {right['file']}:{right['start_line']}-{right['end_line']}")
        return 2 if "exceeded_crap_threshold" in config["quality"]["fail_on"] and any(entry["exceeded_threshold"] for entry in entries) else 0
    except (OSError, RuntimeError, subprocess.SubprocessError, ValueError) as exc:
        print(f"ub-php: {exc}", file=sys.stderr)
        return 3


if __name__ == "__main__":
    raise SystemExit(main())
