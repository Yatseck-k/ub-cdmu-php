# Architecture and contract

[Русская версия](ru/architecture.md)

`vendor/bin/ub-php` selects PHP files under configured source directories. The PHP analyzer parses them with `nikic/php-parser` without loading or executing target source. The same PHP process validates configuration, reads an optional Clover report, calculates CRAP and Jaccard similarity, applies thresholds, and writes JSON/EDN reports. There is no cross-language process or analyzer JSON transport.

An analysis unit has a stable source-based `id`, POSIX relative `file`, `namespace` (including class for methods), `name`, `kind`, inclusive line span, `complexity`, `node_count`, and normalized AST `features`. IDs include path and start line; renaming or moving a unit creates a new ID. The canonical report keeps `schema_version: "1.0"`, `tool`, `entries`, `candidates`, and `diagnostics` for integrations.

Reports are sorted and atomically replaced after successful analysis. A failed parse produces exit code 3 and leaves existing reports intact. JSON is canonical; EDN exports the original tool shapes. The report directory is excluded from source scanning.
