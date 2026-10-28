# CDMU: User and Integration Guide

[Русская версия](README.ru.md)

CDMU provides CRAP checks, structural duplication analysis, optional mutation testing and offline UML for PHP. `check` runs C+D; `mutate` and `uml` are separate commands. The `ub-mode` agent skill is maintained in the companion repository. For a short walkthrough, see the [quick guide](GUIDE.md).

## Contents

- [Requirements, compatibility and provenance](#requirements)
- [Installation](#installation)
- [Commands and configuration](#cli)
- [Metric rules](#metrics)
- [Architecture and report contracts](#architecture)
- [Release history: v0.1.0 draft](#release-notes)

<a id="requirements"></a>

## Requirements, compatibility and provenance

The Composer package needs PHP 8.2+, Composer 2 for installation, and the DOM, JSON and MBString PHP extensions. Runtime dependencies include `nikic/php-parser` 5.x, Symfony YAML/Process 7.4 and `colinodell/json5` 3.x (MIT). Git is needed only for `--changed`. Python and Docker are not required. The repository is MIT; the parser is BSD-3-Clause and Symfony YAML is MIT. Crapper and Dryer algorithms and EDN formats were studied from their upstream 0BSD repositories; no upstream implementation code is included here. Preserve dependency license notices in distributions.

The parser is tested against plain PHP plus small Laravel, Symfony, and Yii-shaped fixtures. These are syntax samples, not full framework applications. A parser's ability to accept newer target PHP syntax is separate from the PHP runtime version. The project does not claim Windows support or exact branch coverage.

Crapper EDN `:entries` fields are `:name`, `:namespace`, `:complexity`, `:coverage`, and `:crap`. It exports named functions and methods; closures, arrows, and anonymous-class methods stay in JSON because their synthetic names cannot be joined by the original viewer. Missing coverage and CRAP use `nil`, unlike upstream's absent-report fallback of 0%; our specification requires this difference. Dryer EDN uses `:candidates` with score, `"php"` language, source spans, and node counts. JSON is the authoritative format for integrations. The original v0.1 did not provide M/U; this checkout adds a versioned Infection adapter and an independent offline PHP UML viewer.

### Optional M/U runtime

The mutation adapter explicitly supports Infection 0.29.14; its own project installation remains optional. The real fixture pins PHPUnit 11.5 in its lock file and uses Xdebug coverage on PHP 8.2. Mutation requires POSIX/PCNTL and process groups on Linux/macOS; its executed tests retain their normal side effects. PCOV is an alternative engine coverage driver but has not been exercised by this integration suite. No broad compatibility with other Infection releases or arbitrary test-framework configurations is claimed.

C/D/U do not execute target PHP and do not need Infection or a coverage extension. UML uses php-parser and bundled local HTML/JS/SVG with no Java/CDN/Graphviz runtime dependency. It implements a bounded offline viewer, not all upstream UML Viewer features. New M/U output schemas are independent; no upstream M/U EDN interoperability is claimed. Symfony Process and JSON5 are MIT dependencies; Infection is BSD-3-Clause and is not bundled as a required runtime dependency.

<a id="installation"></a>

## Installation

Until publication, add the checkout as a Composer path repository from the target project. The path must exist in the same environment where Composer runs:

```sh
composer config repositories.ub-cdmu-php path /path/to/ub-cdmu-php
composer require --dev yatseck-k/ub-cdmu-php:@dev
vendor/bin/ub-php doctor
vendor/bin/ub-php check
```

For package development: run `composer install`, `composer test`, and `composer test-consumer` from its root. Real mutation integration: install dependencies in `fixtures/mutation/`, enable Xdebug coverage, then run `php tests/mutation.php --integration`. Configuration example: [`.ub-mode.yaml`](../.ub-mode.yaml).

<a id="cli"></a>

## Commands and configuration

Run `vendor/bin/ub-php` from the analyzed project's root. Global `--root PATH` and `--config PATH` options appear before the command. `doctor` reports the PHP runtime, parser/YAML dependency availability, and project directory. `check` runs enabled Crapper and Dryer checks; `crap` and `dry` run one. Each accepts `--format text|json`; check commands accept `--changed`, while `crap` and `dry` also accept `--threshold NUMBER`. `--changed` includes added or modified files from staged, unstaged, and untracked Git paths within configured source directories.

### Progress and text reports

`check`, `crap`, `dry`, `mutate`, and `uml` accept `--progress auto|always|never` and `--verbose` after the command. `doctor` does not accept these flags. In `auto` (the default), text runs write progress to stderr: a bar in a terminal, plain lines in redirected logs. JSON runs are quiet by default. `always` enables stderr progress even with JSON; `never` disables it. JSON stdout remains machine-readable.

For C/D, progress names four stages: parsing PHP, calculating CRAP, comparing duplicates, and saving reports. Percentages are local to the named stage. Disabled or empty stages are marked skipped. `Completed` means analysis and configured report writes finished, even if quality findings follow; it is not a PASS. Errors stop the current stage without `Completed`. Terminal redraws are throttled; log updates occur at 10% intervals.

The default text report shows up to 10 CRAP violations (highest scores first), five unknown-coverage entries (highest complexity first), and five DRY groups with up to three matches each. Hidden counts point to `--verbose` and the full JSON report when enabled. `--verbose` prints all CRAP entries and duplicate matches. Limits affect only text; JSON/EDN retain all results. DRY similarities are warnings, not proof of interchangeable behavior.

The final result lists checked files/units, known and unknown coverage, violations, duplicate pairs/groups, actual saved report paths, and suggested actions:

- `PASS` / exit `0`: no findings in the checked scope.
- `WARN` / exit `0`: unknown coverage, DRY candidates, CRAP violations with the failure gate disabled, no changed PHP files, or no checks enabled.
- `FAIL` / exit `2`: a known CRAP score reached the threshold and `quality.fail_on` enables failure.
- Analysis errors return `3` with diagnostics in stderr; text mode also reports `FAIL` with incomplete analysis. Usage errors return `4`.

Unknown coverage is not a verified CRAP pass. A no-change run does not assess the whole project. Report files left over from earlier runs are not evidence of a successful current run. `--progress never` is useful for scripts that need quiet stderr in text mode.

```sh
vendor/bin/ub-php check
vendor/bin/ub-php check --verbose --progress never
vendor/bin/ub-php check --format json --progress always > result.json
```

Text reports use separated sections and list report paths on individual lines. Terminal output uses cyan headings, green PASS, yellow warnings/unknown coverage, and red FAIL/CRAP violations. Colors are disabled for redirected output, when `NO_COLOR` is set, or with `TERM=dumb`. Progress detects its stderr terminal separately from the report stdout terminal.

### Configuration

The optional `.ub-mode.yaml` uses `version: 1`. See the repository example. `project.source` directories are used only if present. A full C/D scan with no PHP files is an analysis/configuration error (exit 3); `--changed` with no changed PHP files succeeds with an empty report. `project.exclude` paths are excluded anywhere in the tree. `crapper.coverage.path` points to an existing Clover XML report. If absent, coverage stays unknown. Reports go to `.metrics/` by default. Static check/crap/dry/uml commands do not run tests or modify source. Explicit mutate delegates test execution to Infection.

C/D exit codes: `0` means analysis passed (including Dryer warnings or unknown coverage); `2` means a known CRAP score reached the configured threshold; `3` means an environment, configuration, parser, or report error; `4` means invalid CLI usage. JSON output is deterministic for unchanged inputs. Git is needed only for `--changed`; outside a Git repository it fails with exit `3` and the message `--changed requires a Git repository`, so omit the flag there.

### Optional mutation testing

`check` continues to run C+D only. Install Infection separately in a PHP project that already has PHPUnit tests:

```sh
composer require --dev infection/infection:0.29.14 phpunit/phpunit:^11.5
# Configure Infection and the project's own PHPUnit bootstrap/source directories first.
vendor/bin/ub-php doctor --tools --format json
vendor/bin/ub-php mutate --changed --min-msi 80 --format json
vendor/bin/ub-php mutate --verbose
```

The verified combination is PHP 8.2, Infection 0.29.14, PHPUnit 11.5 and Xdebug coverage. The engine can also use PCOV; this integration has been exercised with Xdebug. Mutation requires POSIX and PCNTL extensions on Linux/macOS for process-group cleanup. Other Infection versions are reported as unsupported. C/D/U require none of these optional components. `doctor --tools` reports engine, UML, coverage extension and process-control capabilities without running tests; standard `doctor` stays compatible. Extension presence does not prove that coverage mode is enabled.

`mutator` configuration keys in `.ub-mode.yaml`: `executable` (default `vendor/bin/infection`), `configuration` (`infection.json5`), `threads` (1), `process_timeout` (600 seconds), `min_msi` (null), `input_paths` / `input_exclude` (empty lists), and `keep_failed_runs` (5). Infection JSON or JSON5 configuration must be directly in the project root; relative paths retain their meaning. An example is `fixtures/mutation/`. Source directories and excludes in that Infection configuration still apply, and CDMU's exact file selection restricts the mutation scope further. Bootstrap and tests execute with the project's normal privileges and side effects. Source and user configuration are not overwritten; working mutants live in an owned temporary directory. Selected paths containing commas cannot be represented by this engine filter and are rejected.

`--changed` uses the same staged/unstaged/untracked selector as C/D. Without Git, omit it. Baseline tests are still executed by Infection; the adapter does not skip them. `--min-msi N` accepts 0..100 and overrides `mutator.min_msi`. A non-null threshold activates this command's gate independently of C/D `quality.fail_on`. Infection's own minimum-score flags are overridden for collection; CDMU's minimum determines its exit code.

Mutation exit codes: `0` for completed runs (PASS or WARN), `2` for a known MSI below the active minimum, `3` for missing/unsupported engine, failing baseline, timeout, changed inputs, invalid report or write failure, `4` for usage errors. Survivors and uncovered/timeout/error mutants remain findings even with exit 0. With no effective mutants MSI is null. Without a minimum this is WARN/0; with an explicitly configured or CLI minimum it is FAIL/3, including an empty changed scope. The required gate was not evaluated. For this completed but unevaluated result, JSON stdout contains the full mutate envelope with FAIL, exit_code=3 and `gate_evaluated: false`, identical to retained `failure.json`. The previous published measurement is preserved. Read the actual process exit code; an old mutate.json does not describe the failed invocation. Failures before a valid result (baseline/parser/I/O) retain stderr diagnostics and may have empty stdout. MSI and covered MSI are different engine metrics; timeout/error counters are never renamed killed.

Outputs: `.metrics/mutate.json` (canonical summary, mutants, scope and input fingerprints), `mutate.raw.json` and `mutate.log`. Text lists at most ten findings; `--verbose` reveals all mutants. Progress names preparation, running Infection, reading results and saving. While Infection runs, CDMU reports elapsed time instead of inventing a percentage. Process timeout/interruption stops its process group and removes its temporary configuration/mutant directory. After publication the successful run directory is removed because its raw report and log are already in the published generation. Failed-run diagnostics remain under `.mutation-runs/`, bounded by `mutator.keep_failed_runs`.

Input paths such as `./src`, `src/` and `.` are normalized for Infection while preserving project boundaries. Use `mutator.input_paths` for additional real test inputs and `mutator.input_exclude` for runtime data such as `tests/tmp`; exclusions never remove mandatory source/configuration from protection. PHPUnit XML logging/coverage targets and cache paths are excluded automatically, including outputs not yet created. A new or changed genuine test input still invalidates the run. A live supervisor reserves its session identity until group cleanup finishes. A guardian in the owned group watches a private channel and cleans up if the supervisor dies; engine workers do not inherit its owner endpoint. No signal uses a saved, reaped PID. Logs and error tails are captured after group cleanup and pipe draining, including output written during TERM. Failed run initialization rolls back its own partial directory; an unavailable rollback reports the remaining path. Machine failure or simultaneous killing of every guard cannot guarantee userspace cleanup.

```yaml
mutator:
  input_paths: []
  input_exclude: [tests/tmp]
  keep_failed_runs: 5
reports:
  keep_generations: 5
```

### Offline UML

```sh
vendor/bin/ub-php uml
vendor/bin/ub-php uml --namespace 'App\Domain' --format json
# Open .metrics/uml.html locally in a browser.
```

`uml` builds a complete static graph and writes `uml.json`, `uml.svg` and `uml.html`. No Infection, server, CDN, Java, Graphviz or runtime Node is required. Search, namespace filters, class/member details, neighbor highlighting, pan/zoom/reset and keyboard navigation work offline. `uml.view_limit` defaults to 200 visible nodes; the viewer shows hidden counts and search can reach nodes outside the initial view. JSON retains the full graph. `--namespace` selects an initial view; `--changed` is rejected because partial files would lose relationships.

Supported facts: classes/interfaces/traits/enums, visibility, promoted properties and declared types; extends/implements/trait-use/type/new/static-call edges with resolved aliases. Dynamic/DI/magic dependencies and docblock types are not inferred. Anonymous classes are omitted and counted explicitly. Duplicate FQCNs produce WARN and an ambiguous node listing every definition. Its metrics remain unknown; ambiguous outgoing edges are omitted with diagnostics rather than choosing a definition. Export is a snapshot; regenerate it after edits.

Fresh CRAP is calculated without tests using existing Clover, including an explicitly configured external symlink. Missing, unreadable or malformed optional coverage yields unknown metrics and a warning diagnostic while preserving the graph. Mutation overlays are accepted only from a managed snapshot with matching source, test/configuration fingerprints and complete source scope. Old, manually imported, incompatible or changed snapshots are marked stale/unavailable and are not current evidence. Unknown metrics have a neutral color. No viewer action runs tests or modifies source. `uml` exit 0 means generation succeeded (possibly WARN), not that a quality gate passed; use `check`/`crap` for the CRAP gate. PHP syntax and report-write errors return 3 and preserve prior artifacts. Corrupt/unreadable optional mutation snapshots are unavailable; input/version mismatches are stale. Unexpected internal exceptions remain errors (exit 3).

New M/U outputs use immutable generations under `.metrics/.cdmu/`, stable public symlinks and an atomically switched pointer. Copy the report directory preserving links, or copy an individual HTML/SVG with link dereferencing. By default, `reports.keep_generations: 5` retains the current generation plus the four newest historical generations per tool. Zero disables pruning. Publication and snapshot reads use exclusive/shared tool locks. Active runs are never pruned; an abandoned unlocked run older than 60 seconds joins failure retention. Unmanaged directories, symlinks and legacy runs without ownership metadata are preserved. Cleanup failures are warnings and do not roll back a published report. Existing C/D report publication is unchanged. M/U always publish their own JSON and supporting artifacts; C/D `reports.formats` controls only the legacy exports.

<a id="metrics"></a>

## Metric rules

### Crapper

Cyclomatic complexity starts at 1 per function, method, closure, or arrow function. Add 1 for each `if`, `elseif`, `for`, `foreach`, `while`, `do`, `catch`, non-default `case`, non-default `match` arm, ternary, `&&`, `||`, logical `and`/`or`, or `??`. Nested functions and closures receive their own score. Abstract/interface methods have no body and are omitted.

Clover `<line type="stmt">` records inside a unit's inclusive span define executable lines. Coverage is the percentage with hit count above zero; it is line coverage, not branch coverage. Missing reports, unmatched units, and units with no executable lines have unknown coverage. For known coverage percentage `P`, `CRAP = CC² × (1 − P/100)³ + CC`. Unknown coverage produces `crap: null` in JSON and `nil` in EDN. A known CRAP score of 10 or greater fails the default quality gate.

### Dryer

Only ordinary functions and concrete methods with at least 4 source lines and 20 normalized AST nodes are candidates. Names of local variables and literals are ignored; AST shapes, operators, and direct call names are retained. Similarity is Jaccard intersection divided by union of feature sets. The default report threshold is 0.82; candidates are warnings and do not fail CI. `ub-mode` agent reviews may request 0.8 explicitly. Closures and arrows are excluded from duplication comparison in v0.1.

The normalization is heuristic. Review candidate pairs before refactoring. JSON includes IDs and richer fields; `.metrics/crap.edn` follows the [Crapper snapshot](https://github.com/unclebob/crapper), and `.metrics/dry.edn` follows the [Dryer snapshot](https://github.com/unclebob/dryer). For viewer compatibility, EDN only exports fields and names those formats can join.

### Mutation evidence and UML overlays

Mutation counters come from the supported Infection JSON logger. Keep killed, survived, uncovered, timeout, error, syntax_error, ignored and skipped distinct. MSI, covered MSI and mutation coverage are the engine's different percentages; do not infer one from CRAP Clover coverage. No effective mutants means no MSI. Without a configured minimum an unknown MSI is WARN/0. A requested minimum without a measurable score fails with exit 3 and gate_evaluated=false in JSON stdout and its identical failure artifact. Mutate uses its own minimum and does not change the C/D quality.fail_on policy.

UML is a topology snapshot, not a gate. Class CRAP is FAIL if a measured method violates the active threshold, unknown if any method lacks coverage or no concrete method is measured, otherwise PASS. Abstract/interface methods remain without a score. Mutation status uses only a managed, matching snapshot: survived, incomplete (uncovered/timeout/error), killed for measured killed mutants, unknown for no matched mutants, or stale/unavailable. A green CRAP indicator does not imply a green mutation result. No overlay uses an old snapshot after source/test/mutation-input configuration changes. Presentation, output-format, retention and gate-threshold edits do not invalidate an existing measurement.

<a id="architecture"></a>

## Architecture and report contracts

Static C/D analysis through `vendor/bin/ub-php` selects PHP files under configured source directories. The PHP analyzer parses them with `nikic/php-parser` without loading or executing target source. The same PHP process validates configuration, reads an optional Clover report, calculates CRAP and Jaccard similarity, applies thresholds, and writes JSON/EDN reports. There is no cross-language process or analyzer JSON transport.

An analysis unit has a stable source-based `id`, POSIX relative `file`, `namespace` (including class for methods), `name`, `kind`, inclusive line span, `complexity`, `node_count`, and normalized AST `features`. IDs include path and start line; renaming or moving a unit creates a new ID. The canonical report keeps `schema_version: "1.0"`, `tool`, `entries`, `candidates`, and `diagnostics` for integrations.

Reports are sorted and atomically replaced after successful analysis. A failed parse produces exit code 3 and leaves existing reports intact. JSON is canonical; EDN exports the original tool shapes. The report directory is excluded from source scanning.

### New command contracts

The historical check/crap/dry JSON and standard doctor retain schema 1.0 byte compatibility. New commands use independent schema 1.0 envelopes:

- mutate: `tool`, `status`, `exit_code`, `engine` (name/version), `scope` (changed/files), `fingerprints` (source/input hashes and effective configuration hash), `summary`, `mutants`, `diagnostics`.
- summary: total, killed, survived, uncovered, timeout, error, syntax_error, ignored, skipped, msi, covered_msi, mutation_coverage, min_msi and gate_evaluated. Scores without an effective denominator are null.
- mutant: id, POSIX relative file, positive line, mutator, diff, status, unit_id. The unique innermost containing span owns a nested mutant. Equal/overlapping ambiguous spans retain null because Infection provides a line, not a column. UML counts nested mutants once in their class and containing method; a null unit is assigned only to an unambiguous containing class/method, with diagnostics. Normalization validates counts, ranges, group completeness and source scope.
- uml: `tool`, `status`, `scope` (files/namespace/view_limit), `nodes`, `edges`, `mutation_overlay`, `limits`, `diagnostics`. Nodes have stable FQCN ids, source spans, kind, flags, members and metrics; external types have no source file. Edges have from/to/kind. The full graph is independent of initial display limits.
- doctor --tools: `tool: doctor-tools`, `tools` with optional engine, UML, coverage-runtime and process-control capabilities. This probe never runs project tests.

`MutationCommand` coordinates file selection and input fingerprints; `InfectionRunner` owns subprocesses, temporary configuration and cleanup; `InfectionReport` validates the pinned engine's JSON. Input fingerprints cover selected source, Composer manifest/lock, Infection/PHPUnit configuration and bootstraps, declared PHPUnit testsuites, existing tests/test/spec/fixtures directories and explicit input_paths. They exclude declared test outputs/cache, the canonical report directory and explicit input_exclude. Each selected file is hashed once per snapshot; arbitrary repository JSON/XML/PHP files are not swept into the manifest. The configuration hash is a versioned projection of version, project source/excludes, and mutator executable/configuration/input_paths/input_exclude. Raw .ub-mode.yaml bytes, UML/CRAP/DRY settings, reports/retention, MSI minimum, threads and overall process timeout are excluded; semantic input settings are reloaded from the active config path after mutation. Comments/UI edits preserve freshness; genuine input edits invalidate it. Old fingerprint versions become stale. Changing reports.directory changes where snapshots are found; CDMU does not move them automatically. External test resources and database state are outside this static fingerprint boundary.

`Topology` independently extracts class structure with NameResolver without altering existing Analyzer units. `UmlCommand` joins freshly calculated CRAP and a verified matching mutation snapshot; `Viewer` builds SVG and embeds complete JSON plus local CSS/JS in HTML. Opening the report cannot run a mutation or edit source.

M/U publication writes an immutable generation with checksums, prepares stable artifact aliases, then atomically switches one symlink under `.cdmu/` while holding a tool-specific file lock. A failed preparation keeps the previous pointer and artifacts. History is pruned under the same lock, respecting configured limits; shared reader locks protect a snapshot while its manifest and JSON are read. This targets Linux/macOS filesystems, not Windows. C/D atomic-file publication remains unchanged, including explicitly configured report-directory symlinks to external storage. M/U report storage stays inside the project; reports.directory="." remains invalid.

<a id="release-notes"></a>

## Release history: v0.1.0 draft

Historical draft for the original C/D-only scope. Its missing M/U capabilities describe that draft; the current implementation includes mutation and UML as documented above.

- Composer package and PHP-only CLI with versioned JSON reports.
- CRAP scores from static complexity and existing Clover line coverage; missing coverage stays unknown.
- Structural duplicate warnings with configurable Jaccard threshold and JSON/EDN reports.
- Changed-file selection and PHP/Composer CI tests. Agent integration examples are maintained in the separate `ub-mode-php` repository.

Known limits: no automatic coverage collection, mutation testing, UML visualization, or Windows support. Framework validation uses small syntax fixtures. This draft becomes a release note only after all v0.1 validation gates pass.
