"""Jaccard similarity over PHP AST feature sets."""

from itertools import combinations


def find_duplicates(units: list[dict], threshold: float = 0.82, min_lines: int = 4, min_nodes: int = 20) -> list[dict]:
    eligible = [unit for unit in units if unit["kind"] in {"function", "method"} and unit["end_line"] - unit["start_line"] + 1 >= min_lines and unit["node_count"] >= min_nodes]
    result = []
    for left, right in combinations(eligible, 2):
        if left["kind"] != right["kind"] or left["id"] == right["id"]:
            continue
        a, b = set(left["features"]), set(right["features"])
        if not a or not b:
            continue
        similarity = len(a & b) / len(a | b)
        if similarity >= threshold:
            result.append({
                "score": similarity,
                "left": {key: left[key] for key in ("id", "file", "start_line", "end_line", "name")},
                "right": {key: right[key] for key in ("id", "file", "start_line", "end_line", "name")},
                "left_nodes": left["node_count"],
                "right_nodes": right["node_count"],
            })
    return sorted(result, key=lambda candidate: (-candidate["score"], candidate["left"]["file"], candidate["left"]["start_line"], candidate["right"]["file"], candidate["right"]["start_line"]))
