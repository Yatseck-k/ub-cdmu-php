import json
from pathlib import Path
import shutil
import subprocess
import pytest

from ub_mode_php.cli import main
from ub_mode_php.core import load_config, source_files


FIXTURE = Path(__file__).resolve().parents[1] / "fixtures/plain-php/Example.php"


def project(tmp_path):
    source = tmp_path / "src"
    source.mkdir()
    shutil.copyfile(FIXTURE, source / "Example.php")
    (tmp_path / ".ub-mode.yaml").write_text("project:\n  source: [src]\n", encoding="utf-8")
    return tmp_path


def test_source_filter_and_changed_files(tmp_path):
    root = project(tmp_path)
    (root / "src/vendor").mkdir()
    (root / "src/vendor/Hidden.php").write_text("<?php", encoding="utf-8")
    assert source_files(root, load_config(root)) == ["src/Example.php"]
    subprocess.run(["git", "init", "-q"], cwd=root, check=True)
    assert source_files(root, load_config(root), changed=True) == ["src/Example.php"]


def test_cli_unknown_coverage_and_deterministic_report(tmp_path, capsys):
    root = project(tmp_path)
    assert main(["--root", str(root), "check", "--format", "json"]) == 0
    report = json.loads(capsys.readouterr().out)
    assert report["schema_version"] == "1.0"
    assert {entry["name"] for entry in report["entries"]} >= {"run", "helper", "shared"}
    assert all(entry["coverage"] is None and entry["crap"] is None for entry in report["entries"])
    first = (root / ".metrics/check.json").read_bytes()
    assert main(["--root", str(root), "check", "--format", "json"]) == 0
    capsys.readouterr()
    assert (root / ".metrics/check.json").read_bytes() == first
    assert (root / "src/Example.php").read_bytes() == FIXTURE.read_bytes()


def test_clover_threshold_and_parse_error(tmp_path, capsys):
    root = project(tmp_path)
    coverage = """<?xml version="1.0"?><coverage><project><file name="src/Example.php">
      <line num="18" type="stmt" count="0"/>
      <line num="19" type="stmt" count="0"/>
      <line num="20" type="stmt" count="0"/>
    </file></project></coverage>"""
    (root / "coverage.xml").write_text(coverage, encoding="utf-8")
    assert main(["--root", str(root), "crap", "--format", "json"]) == 2
    report = json.loads(capsys.readouterr().out)
    assert next(entry for entry in report["entries"] if entry["name"] == "run")["exceeded_threshold"]
    (root / "src/Example.php").write_text("<?php function bad( {", encoding="utf-8")
    assert main(["--root", str(root), "check"]) == 3
    assert "Example.php" in capsys.readouterr().err


def test_real_php_duplicate_candidates(tmp_path, capsys):
    source = tmp_path / "src"
    source.mkdir()
    fixtures = Path(__file__).resolve().parents[1] / "fixtures"
    shutil.copyfile(fixtures / "laravel/OrderService.php", source / "Laravel.php")
    shutil.copyfile(fixtures / "symfony/OrderService.php", source / "Symfony.php")
    assert main(["--root", str(tmp_path), "dry", "--format", "json"]) == 0
    report = json.loads(capsys.readouterr().out)
    assert len(report["candidates"]) == 1
    assert report["candidates"][0]["score"] == 1.0
    assert report["candidates"][0]["left_nodes"] >= 20


def test_usage_error_is_distinct_from_quality_failure():
    with pytest.raises(SystemExit) as error:
        main(["unknown-command"])
    assert error.value.code == 4


def test_invalid_configuration_and_coverage_are_analysis_errors(tmp_path, capsys):
    root = project(tmp_path)
    (root / ".ub-mode.yaml").write_text("project: [not, a, mapping", encoding="utf-8")
    assert main(["--root", str(root), "check"]) == 3
    assert "YAML" in capsys.readouterr().err
    (root / ".ub-mode.yaml").write_text("project:\n  source: [src]\n", encoding="utf-8")
    (root / "coverage.xml").write_text("<coverage>", encoding="utf-8")
    assert main(["--root", str(root), "crap"]) == 3
    assert "Clover" in capsys.readouterr().err


def test_full_scan_cannot_pass_without_php_source(tmp_path, capsys):
    (tmp_path / "src").mkdir()
    assert main(["--root", str(tmp_path), "check"]) == 3
    assert "no PHP files" in capsys.readouterr().err
