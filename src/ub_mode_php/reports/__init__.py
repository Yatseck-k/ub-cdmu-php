"""Compatible EDN snapshots; JSON is the canonical report."""

import json


def _edn_string(value: str) -> str:
    return json.dumps(value, ensure_ascii=False)


def _edn_number(value: int | float | None) -> str:
    return "nil" if value is None else str(value)


def crap_edn(entries: list[dict]) -> str:
    records = []
    for item in entries:
        if item["kind"] not in {"function", "method"} or "anonymous@" in item["namespace"]:
            continue
        records.append("{:name " + _edn_string(item["name"]) + " :namespace " + _edn_string(item["namespace"]) + " :complexity " + str(item["complexity"]) + " :coverage " + _edn_number(item["coverage"]) + " :crap " + _edn_number(item["crap"]) + "}")
    return "{:entries [" + " ".join(records) + "]}\n"


def dry_edn(candidates: list[dict]) -> str:
    records = []
    for item in candidates:
        spans = []
        for side in ("left", "right"):
            span = item[side]
            spans.append(f':{side} {{:file {_edn_string(span["file"])} :start-line {span["start_line"]} :end-line {span["end_line"]}}}')
        records.append("{:score " + str(item["score"]) + ' :language "php" ' + " ".join(spans) + f' :left-nodes {item["left_nodes"]} :right-nodes {item["right_nodes"]}' + "}")
    return "{:candidates [" + " ".join(records) + "]}\n"
