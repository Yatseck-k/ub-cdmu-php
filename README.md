# ub-cdmu-php

**PHP code quality toolkit — CRAP metrics, duplication detection, mutation testing and offline UML.**

[Русский](README.ru.md) · [Quick start](docs/GUIDE.md) · [Full reference](docs/README.md) · [License](LICENSE)

![PHP](https://img.shields.io/badge/PHP-8.4.27%2B%20%7C%208.5.12%2B-777BB4?logo=php&logoColor=white) ![Composer](https://img.shields.io/badge/Composer-package-885630?logo=composer&logoColor=white) ![License](https://img.shields.io/badge/License-MIT-blue)

## Overview

`ub-cdmu-php` is a standalone Composer toolkit for inspecting PHP code quality. The core checks **parse source code without loading or executing it**, combining cyclomatic complexity and existing PHPUnit Clover line coverage with structural duplication detection. Optional tools run mutation tests using Infection or generate a self-contained offline UML viewer. **Python and Docker are not required.**

## What it does

| Command | Purpose |
| --- | --- |
| `check` | Run CRAP and DRY analysis together |
| `crap` | Calculate complexity-based CRAP metrics using available coverage |
| `dry` | Find candidates for structural code duplication |
| `doctor` | Validate configuration and inspect optional tools |
| `mutate` | Explicitly run optional Infection mutation analysis |
| `uml` | Generate an offline HTML code structure viewer |

**Results are evidence, not guesses:** missing coverage is reported as unknown, never as a verified CRAP pass. DRY matches require developer review. By default, `check` only runs CRAP and DRY.

## Requirements

- PHP **8.4.27+ (<8.5)** or **8.5.12+ (<9.0)**.
- Composer and PHP extensions DOM, JSON, mbstring, PCNTL and POSIX.
- Optional: an existing PHPUnit Clover coverage report, Git for `--changed`, and a compatible locally installed Infection for `mutate`.

## Quick start

Until the package is published, register a local checkout as a Composer path repository **from the target PHP project**:

```bash
composer config repositories.ub-cdmu-php path /path/to/ub-cdmu-php
composer require --dev yatseck-k/ub-cdmu-php:@dev
vendor/bin/ub-php doctor
vendor/bin/ub-php check
```

The project should contain an `.ub-mode.yaml` file or a `src/` or `app/` directory. Reports are saved to `.metrics/` by default.

### Common commands

```bash
vendor/bin/ub-php check --format json
vendor/bin/ub-php check --changed
vendor/bin/ub-php dry --threshold 0.8
vendor/bin/ub-php doctor --tools --format json

# Optional: runs the target project's tests using installed Infection
vendor/bin/ub-php mutate --changed --min-msi 80

# Creates .metrics/uml.html — open it in a browser
vendor/bin/ub-php uml
```

`--changed` requires Git and is not supported for `uml`. Use `--verbose` for details or `--progress never` for quiet output. Optional tools are never installed automatically.

## Configuration and documentation

- [Getting started](docs/GUIDE.md) · [По-русски](docs/GUIDE.ru.md)
- [CLI, metrics, statuses, exit codes and architecture](docs/README.md) · [По-русски](docs/README.ru.md)
- [Example configuration](.ub-mode.yaml)

## Development

```bash
composer install
composer test
composer test-consumer
php bin/ub-php --root fixtures check --format json
```

## Related projects

- [ub-mode-php](https://github.com/Yatseck-k/ub-mode-php) — independent agent skill that can optionally use this toolkit.
- [commit-rules](https://github.com/Yatseck-k/commit-rules) — independent Conventional Commits skill.

## License

[MIT](LICENSE).
