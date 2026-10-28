# CDMU: quick guide

[Русская версия](GUIDE.ru.md)

CDMU (`ub-php`) checks the quality of PHP code without running it:

- **CRAP** — how complex a function is and how little of it the tests cover. The higher the number, the riskier the code is to change. The default threshold is 10.
- **DRY** — pairs of similar functions that may be worth merging.
- **Mutation testing** (optional) — checks whether your tests notice deliberate breakage of the code.
- **UML** (optional) — a page with a class and relation diagram that opens in a browser without a server.

Full details are in the [reference guide](README.md). This page is a quick start.

## Requirements

- PHP 8.2+ and Composer 2 (extensions `dom`, `json`, `mbstring`, `pcntl`, `posix`; Linux/macOS).
- Git — only for the `--changed` flag.
- For mutation testing also: Infection 0.29.14, PHPUnit and a coverage driver (Xdebug or PCOV).

## Installation

From the root of your PHP project (until the package is published, through a local checkout):

```sh
composer config repositories.ub-cdmu-php path /path/to/ub-cdmu-php
composer require --dev yatseck-k/ub-cdmu-php:@dev
vendor/bin/ub-php doctor
```

`doctor` should print `php_supported: true` and exit with 0. Run every command below from the project root.

The analyzer looks for code in `src` and `app`. If neither exists, create `.ub-mode.yaml` (see [Configuration](#configuration)).

## First run

```sh
vendor/bin/ub-php check
```

The command parses the code, calculates CRAP and looks for duplicates. The result is one of three lines:

| Result | Exit code | Meaning |
| --- | --- | --- |
| `PASS` | 0 | No findings |
| `WARN` | 0 | Warnings: unknown coverage, similar functions found, or no changed files |
| `FAIL` | 2 | A known CRAP score reached the threshold |

Reports are saved in `.metrics/` (`check.json`, `crap.edn`, `dry.edn`); add `.metrics/` to `.gitignore`. The bottom of the output has a "Next steps" block.

Useful flags: `--verbose` shows every entry, `--progress never` turns off the progress bar.

## Why everything says "unknown"

CRAP uses test coverage. Without a ready report, coverage is **unknown** — that is neither 0% nor "all good". Produce a Clover report with your project's tests (Xdebug or PCOV is required) and run the check again:

```sh
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-clover coverage.xml
vendor/bin/ub-php check
```

A `coverage.xml` in the project root is picked up automatically; set another path with `crapper.coverage.path`. File paths inside the report must point to files of your project: a report created in a container with different absolute paths will not match.

## What to do with the result

- **CRAP above the threshold** — simplify the branching in the function, add tests, or both. Do not raise the threshold to hide the finding.
- **DRY candidates** — warnings only. Compare what the functions mean: structural similarity does not prove they should be merged. For a stricter check: `vendor/bin/ub-php dry --threshold 0.8`.
- **Only your own changes:**

  ```sh
  vendor/bin/ub-php check --changed
  ```

  This takes modified, staged and new PHP files under `src`/`app`. It needs a Git repository; outside Git, run without `--changed`. An empty result for the changes says nothing about the whole project.

## JSON and CI

```sh
vendor/bin/ub-php check --format json > result.json
```

Decide from the JSON (`entries`, `candidates`) and the exit code, not from the text. In CI the exit code is enough: `2` is a known CRAP violation, `3` an analysis error, `4` invalid arguments.

## Configuration

The `.ub-mode.yaml` file in the project root is optional. A minimal example:

```yaml
version: 1
project:
  source: [src]
  exclude: [vendor, tests]
crapper:
  threshold: 10
  coverage:
    path: coverage.xml
dryer:
  threshold: 0.82
reports:
  directory: .metrics
```

The remaining keys and their defaults are in [`.ub-mode.yaml`](../.ub-mode.yaml). Unknown keys are an error. Use another settings file with `vendor/bin/ub-php --config path.yaml check`.

## Mutation testing (optional)

This runs **your project's tests** many times, so it is explicit only and can be slow. The tests run with their normal side effects.

```sh
composer require --dev infection/infection:0.29.14 phpunit/phpunit:^11.5
# configure infection.json5 in the project root (example: fixtures/mutation/)
vendor/bin/ub-php doctor --tools
vendor/bin/ub-php mutate --changed --min-msi 80
```

- Other Infection versions are not supported. CDMU never installs anything itself.
- `--min-msi 80` is the minimum share of killed mutants. Below it the exit code is 2.
- If a minimum is set but no score can be measured (for example, there are no suitable mutants), the result is `FAIL` with exit 3, not a silent success.
- The result is `.metrics/mutate.json`. After a failure it may still hold an earlier result: trust the exit code of the current run.
- Test by-products (PHPUnit reports, `tests/tmp`) can interfere: see `mutator.input_exclude` in the [reference guide](README.md#cli).

## UML (optional)

```sh
vendor/bin/ub-php uml
# then open .metrics/uml.html in a browser
```

It needs no server or internet and does not run project code. Classes are laid out left to right (a class sits left of the classes it depends on), colored by namespace; PHP and vendor classes are hidden until you tick "External types", and the checkboxes above the diagram switch relation groups on and off. Click a class to highlight its relations. For one part of the diagram: `uml --namespace 'App\Domain'`. The diagram is a snapshot: regenerate it after edits. `--changed` is not supported for UML.

## Common problems

| Symptom | Cause and fix |
| --- | --- |
| `no PHP files found in configured source directories` (exit 3) | There is no `src`/`app` directory or it has no PHP. Set `project.source` |
| `--changed requires a Git repository` (exit 3) | The directory is not a Git repository. Drop `--changed` |
| Everything is `unknown` | There is no `coverage.xml` — see above |
| `invalid command or option` (exit 4) | A typo in an argument. The global `--root` and `--config` options go **before** the command |
| `mutate` reports a missing `vendor/bin/infection` | Infection is not installed; see the mutation section |
| Mutation overlay in UML is "stale" | Sources, tests, bootstrap or their configuration changed after `mutate`; run `mutate` again |
