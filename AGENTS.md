# Repository Guidelines

## Project Structure & Module Organization

The Python CLI and metric logic live in `src/ub_mode_php/`; the read-only AST parser and Composer manifest live in `php/analyzer/`. `bin/ub-php` is the command wrapper. Python tests are in `tests/`, PHP tests in `php/analyzer/tests/`, and small syntax projects in `fixtures/`. Protocol and metric explanations are in `docs/`. The optional agent skill and integration examples live in the separate `ub-mode-php-skill` repository. The local `work/` folder is task handoff material and is excluded from Git in this checkout.

## Build, Test, and Development Commands

Use Python 3.12+ and PHP 8.2+. From the repository root, `python -m pip install -e '.[dev]'` installs the CLI and Python test tools; `composer install --working-dir=php/analyzer` installs the parser. Run `python -m pytest` and `composer test --working-dir=php/analyzer`. `ub-php doctor` checks the environment; `ub-php check --format json` analyzes configured PHP paths. Where local runtimes are absent, `docker build -t ub-mode-php:dev .` builds a self-contained image; see `README.md` for the mounted-project command.

## Coding Style & Naming Conventions

Use four spaces in Python and PSR-12 style in PHP. Name Python modules/functions in `snake_case` and PHP classes in `PascalCase`. Keep pure metric calculations in Python and AST extraction in PHP; pass only versioned JSON across that boundary. Preserve stable ordering in reports and validate external input before metric calculation. Do not execute or edit analyzed PHP source.

## Testing Guidelines

Python tests use pytest; PHP analyzer tests run through Composer's `test` script. Add a behavior test for parsing, CLI output, metric rules, or protocol changes before implementation when practical. Keep framework fixtures minimal. No project test-coverage percentage is enforced; missing Clover coverage must stay `unknown`. The default known CRAP gate is `< 10`; Dryer candidates are warnings.

## Commit & Pull Request Guidelines

Use short, imperative commit subjects. Pull requests should explain the behavior changed, relevant issue or specification section, tests run, and any dependency or JSON protocol changes. Include sample JSON for report changes. Keep generated `.metrics/`, Composer `vendor/`, and local IDE files out of commits.
