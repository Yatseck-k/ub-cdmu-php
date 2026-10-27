import pytest

from ub_mode_php.contracts import validate_analyzer_result
from ub_mode_php.crapper import score, coverage_for_unit, analyze
from ub_mode_php.dryer import find_duplicates
from ub_mode_php.reports import crap_edn, dry_edn


def unit(name, features, line=1):
    return {
        "id": f"src/Example.php:App\\Example:{name}:{line}",
        "file": "src/Example.php",
        "namespace": "App\\Example",
        "name": name,
        "kind": "method",
        "start_line": line,
        "end_line": line + 9,
        "complexity": 6,
        "node_count": 25,
        "features": features,
    }


def test_contract_rejects_malformed_result():
    valid = {"schema_version": "1.0", "units": [unit("first", ["call:save"])], "diagnostics": []}
    assert validate_analyzer_result(valid)["units"][0]["name"] == "first"
    try:
        validate_analyzer_result({**valid, "schema_version": "2.0"})
    except ValueError as exc:
        assert "schema_version" in str(exc)
    else:
        assert False, "unsupported contract was accepted"


def test_crap_and_unknown_coverage():
    assert score(6, 75.0) == 6.5625
    assert score(3, None) is None
    covered = {"src/Example.php": {1: 2, 2: 0, 3: 1}}
    assert coverage_for_unit(unit("first", [], 1), covered) == pytest.approx(200 / 3)
    assert coverage_for_unit(unit("first", [], 20), covered) is None
    boundary = analyze([{**unit("first", []), "complexity": 10}], {"src/Example.php": {1: 1}}, 10)
    assert boundary[0]["crap"] == 10
    assert boundary[0]["exceeded_threshold"]


def test_duplicate_detection_preserves_call_distinction():
    units = [
        unit("a", ["stmt:return", "call:save", "op:+"]),
        unit("b", ["stmt:return", "call:save", "op:+"], 20),
        unit("c", ["stmt:return", "call:delete", "op:-"], 40),
    ]
    result = find_duplicates(units, threshold=0.82, min_lines=4, min_nodes=20)
    assert [(item["left"]["name"], item["right"]["name"]) for item in result] == [("a", "b")]
    assert result[0]["score"] == 1.0


def test_edn_unknown_and_candidate_shape():
    entry = {**unit("a", []), "coverage": None, "coverage_type": "line", "crap": None}
    assert ':coverage nil :crap nil' in crap_edn([entry])
    assert ":entries []" in crap_edn([{**entry, "namespace": "App\\anonymous@4#1"}])
    candidate = {
        "score": 0.9,
        "left": {"file": "src/A.php", "start_line": 1, "end_line": 5, "name": "a"},
        "right": {"file": "src/B.php", "start_line": 2, "end_line": 7, "name": "b"},
        "left_nodes": 25,
        "right_nodes": 26,
    }
    output = dry_edn([candidate])
    assert ':candidates' in output and ':language "php"' in output
    assert ':left-nodes 25' in output and ':right-nodes 26' in output
