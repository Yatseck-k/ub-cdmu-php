# Architecture and contract

`ub-php` discovers PHP files under configured source directories and sends one JSON request to `php/analyzer/bin/analyze.php` on standard input: `{"schema_version":"1.0","root":"/absolute/project","files":["src/A.php"]}`. The analyzer parses each file with `nikic/php-parser`, then returns `schema_version`, `units`, and `diagnostics` on standard output. Parse errors are diagnostics; malformed requests fail on standard error. The Python boundary validates the response before calculating metrics. See `src/ub_mode_php/contracts/analyzer-v1.schema.json`.

A unit has a stable source-based `id`, POSIX relative `file`, `namespace` (including class for methods), `name`, `kind`, inclusive line span, `complexity`, `node_count`, and normalized AST `features`. IDs include the path and start line; renaming or moving a unit creates a new ID. The PHP analyzer does not execute target source or modify it. Python handles configuration, Clover import, Jaccard comparisons, thresholds, and reports.

Reports are sorted and atomically replaced after successful analysis. A failed parse produces exit code 3 and no new reports. JSON is canonical; EDN is an export for the original tool shapes. The report directory is excluded from source scanning.
