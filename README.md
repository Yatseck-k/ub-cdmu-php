# ub-mode-php

Static quality gates for PHP projects. A Python 3.12+ CLI coordinates a PHP 8.2+ AST analyzer; target PHP source is parsed, never loaded or executed. Version 0.1 provides CRAP scores from complexity and existing PHPUnit Clover line coverage, plus structural duplication candidates.

## Quick start

Build the image from this repository, then mount the PHP project to analyze:

```sh
docker build -t ub-mode-php:dev .
PHP_PROJECT=/path/to/php-project
docker run --rm --user "$(id -u):$(id -g)" -v "$PHP_PROJECT:/project" ub-mode-php:dev --root /project doctor
docker run --rm --user "$(id -u):$(id -g)" -v "$PHP_PROJECT:/project" ub-mode-php:dev --root /project check --format json
```

The project being analyzed needs `.ub-mode.yaml` or existing `src/` or `app/` directories. Docker writes reports to its mounted project under `.metrics/`. For local development with Python 3.12+, PHP 8.2+, and Composer:

To inspect the bundled syntax samples without mounting another project, run `docker run --rm ub-mode-php:dev --root /app/fixtures check --format json`.

```sh
python -m pip install -e '.[dev]'
composer install --working-dir=php/analyzer
ub-php doctor
python -m pytest
composer test --working-dir=php/analyzer
```

Commands: `ub-php check`, `ub-php crap`, `ub-php dry`, and `ub-php doctor`. Use `--changed` on a check command to analyze staged, unstaged, and untracked PHP files under configured source directories. Use `ub-php dry --threshold 0.8` for the stricter `ub-mode` review threshold. `--format json` prints the same canonical result written to `.metrics/`.

See [CLI](docs/cli.md), [metric rules](docs/metrics.md), [architecture](docs/architecture.md), and [compatibility](docs/compatibility.md). Infection and UML Viewer are planned for later releases.

Agent instructions are in `AGENTS.md`, `.agents/skills/ub-mode/`, `CLAUDE.md`, `.cursor/rules/`, and `.github/copilot-instructions.md`. Agents should run the CLI and report missing coverage or unavailable later-version gates explicitly.
