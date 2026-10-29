# ub-cdmu-php

[Русская версия](README.ru.md)

Static quality gates for PHP projects. This is a Composer package for PHP 8.2+. It parses target source without loading or executing it. `check` reports CRAP from cyclomatic complexity and existing PHPUnit Clover line coverage, plus structural duplication candidates. Optional `mutate` (Infection) and `uml` (offline viewer) are separate commands. Python and Docker are not required.

**New here? Start with the [quick guide](docs/GUIDE.md).** The [reference guide](docs/README.md) has every option and contract.

Text checks show stage progress in stderr and a compact PASS/WARN/FAIL result with report paths and next steps. Use `vendor/bin/ub-php check --verbose` for every entry and match, or `--progress never` for quiet progress. Redirected text logs receive plain progress lines; JSON stays quiet unless `--progress always` is requested. Unknown coverage and duplicate candidates produce WARN with exit 0, not a verified CRAP pass. See the [CLI guide](docs/README.md#cli) for limits and exit codes.

## Mutation and UML

```sh
vendor/bin/ub-php doctor --tools --format json
vendor/bin/ub-php mutate --changed --min-msi 80
vendor/bin/ub-php uml
# Open .metrics/uml.html in your browser.
```

Mutation is explicit and runs project tests through an optional installed Infection **0.29.14**. The verified fixture uses PHP 8.2, PHPUnit 11.5 and Xdebug coverage; POSIX/PCNTL process control is required for mutation. No automatic installation occurs. UML is static, works offline, and requires no Infection. `check` still runs C+D only. See [CLI](docs/README.md#cli) for configuration, status semantics, source scope and output artifacts.

## Install in a PHP project

Until the package is published, add this checkout as a local Composer path repository from the project you want to analyze:

```sh
composer config repositories.ub-cdmu-php path /path/to/ub-cdmu-php
composer require --dev yatseck-k/ub-cdmu-php:@dev
vendor/bin/ub-php doctor
vendor/bin/ub-php check --format json
```

After publication, the path repository step can be replaced with a normal `composer require --dev yatseck-k/ub-cdmu-php`. The project needs an `.ub-mode.yaml` or an existing `src/` or `app/` directory. Reports are written to `.metrics/` by default. Git is needed only for `--changed`; an existing Clover report is optional.

Commands: `check`, `crap`, `dry`, `doctor`, `mutate` and `uml`. Use `--changed` (needs a Git repository) to analyze staged, unstaged, and untracked PHP files in configured source directories; `uml` does not accept it. `dry --threshold 0.8` applies the stricter `ub-mode` review threshold. `--format json` prints the same canonical result written to `.metrics/`.

For development in this checkout:

```sh
composer install
composer test
composer test-consumer
php bin/ub-php --root fixtures check --format json
```

See [CLI](docs/README.md#cli), [metric rules](docs/README.md#metrics), [architecture](docs/README.md#architecture), and [compatibility](docs/README.md#requirements). Optional Infection mutation testing and an offline PHP UML viewer are available as separate commands.

The optional [`ub-mode-php`](https://github.com/Yatseck-k/ub-mode-php) agent skill is maintained as a separate project and works even when this CLI is unavailable. See [Contributing](CONTRIBUTING.md) for development guidelines.

## Related projects

- [`ub-mode-php`](https://github.com/Yatseck-k/ub-mode-php) — optional agent workflow that can use these analyzers.
- [`commit_rules`](https://github.com/Yatseck-k/commit_rules) — an independent Conventional Commits skill.
