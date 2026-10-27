# ub-cdmu-php

[Русская версия](README.ru.md)

Static quality gates for PHP projects. This is a Composer package for PHP 8.2+. It parses target source without loading or executing it. Version 0.1 reports CRAP from cyclomatic complexity and existing PHPUnit Clover line coverage, plus structural duplication candidates. Python and Docker are not required.

## Install in a PHP project

Until the package is published, add this checkout as a local Composer path repository from the project you want to analyze:

```sh
composer config repositories.ub-cdmu-php path /path/to/ub-cdmu-php
composer require --dev yatseck-k/ub-cdmu-php:@dev
vendor/bin/ub-php doctor
vendor/bin/ub-php check --format json
```

After publication, the path repository step can be replaced with a normal `composer require --dev yatseck-k/ub-cdmu-php`. The project needs an `.ub-mode.yaml` or an existing `src/` or `app/` directory. Reports are written to `.metrics/` by default. Git is needed only for `--changed`; an existing Clover report is optional.

Commands: `check`, `crap`, `dry`, and `doctor`. Use `--changed` to analyze staged, unstaged, and untracked PHP files in configured source directories. `dry --threshold 0.8` applies the stricter `ub-mode` review threshold. `--format json` prints the same canonical result written to `.metrics/`.

For development in this checkout:

```sh
composer install
composer test
composer test-consumer
php bin/ub-php --root fixtures check --format json
```

See [CLI](docs/cli.md), [metric rules](docs/metrics.md), [architecture](docs/architecture.md), and [compatibility](docs/compatibility.md). Infection and UML Viewer are planned for later releases.

The optional `ub-mode` agent skill is maintained separately in `ub-mode-php` (at `../ub-mode-php` in this workspace). It works even when this CLI is unavailable.
