"""CRAP score and Clover line coverage."""

from pathlib import Path
import xml.etree.ElementTree as ET


def score(complexity: int, coverage_percent: float | None) -> float | None:
    if coverage_percent is None:
        return None
    if not 0 <= coverage_percent <= 100:
        raise ValueError("coverage must be between 0 and 100")
    fraction = coverage_percent / 100
    return complexity ** 2 * (1 - fraction) ** 3 + complexity


def read_clover(path: Path, project_root: Path) -> dict[str, dict[int, int]]:
    """Return file -> executable line -> hit count, rejecting ambiguous paths."""
    result: dict[str, dict[int, int]] = {}
    try:
        tree = ET.parse(path)
    except ET.ParseError as exc:
        raise ValueError(f"invalid Clover XML: {exc}") from exc
    for file_element in tree.findall(".//file"):
        name = file_element.get("name")
        if not name:
            continue
        candidate = Path(name)
        if not candidate.is_absolute():
            candidate = project_root / candidate
        try:
            relative = candidate.resolve().relative_to(project_root.resolve()).as_posix()
        except ValueError:
            continue
        lines = result.setdefault(relative, {})
        for line in file_element.findall("./line"):
            if line.get("type") != "stmt":
                continue
            try:
                number = int(line.get("num", ""))
                count = int(line.get("count", ""))
            except ValueError as exc:
                raise ValueError(f"invalid Clover line in {relative}") from exc
            if number < 1 or count < 0:
                raise ValueError(f"invalid Clover line in {relative}")
            lines[number] = max(lines.get(number, 0), count)
    return result


def coverage_for_unit(unit: dict, coverage: dict[str, dict[int, int]]) -> float | None:
    lines = coverage.get(unit["file"], {})
    relevant = [hits for number, hits in lines.items() if unit["start_line"] <= number <= unit["end_line"]]
    if not relevant:
        return None
    return 100 * sum(hits > 0 for hits in relevant) / len(relevant)


def analyze(units: list[dict], coverage: dict[str, dict[int, int]], threshold: float) -> list[dict]:
    entries = []
    for unit in units:
        amount = coverage_for_unit(unit, coverage)
        value = score(unit["complexity"], amount)
        entries.append({**unit, "coverage": amount, "coverage_type": "line", "crap": value, "exceeded_threshold": value is not None and value >= threshold})
    return sorted(entries, key=lambda entry: (entry["file"], entry["start_line"], entry["id"]))
