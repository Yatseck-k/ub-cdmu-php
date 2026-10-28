# Repository Guidelines

## Project Structure & Module Organization

The Composer package, PHP CLI, metric logic, configuration, and read-only AST analyzer live in `src/`. `bin/ub-php` is the Composer binary. PHP tests are in `tests/`, small syntax projects are in `fixtures/`, and metric and command explanations are in `docs/`. The optional agent skill and integration examples live in the separate `ub-mode-php` repository. The local `work/` folder is task handoff material and is excluded from Git in this checkout.

## Build, Test, and Development Commands

Use PHP 8.2+ and Composer 2. From the repository root, run `composer install`, `composer test`, and `composer test-consumer`. Run `php bin/ub-php --root fixtures check --format json` to analyze bundled fixtures. In a consumer project, install this checkout as a Composer path repository and run `vendor/bin/ub-php doctor` or `vendor/bin/ub-php check --format json`.

## Coding Style & Naming Conventions

Use PSR-12 style and `PascalCase` PHP class names. Keep metric calculations pure and AST extraction read-only. Preserve stable ordering in reports and validate external input before calculation. C/D/U commands must not execute or edit analyzed PHP source. Only the explicit `mutate` command may run project tests through installed Infection; mutants belong to owned temporary directories. Never rewrite original source or user configuration. Report JSON keeps schema version `1.0`.

## Testing Guidelines

Add a behavior test for parsing, CLI output, metric rules, or report changes before implementation when practical. Keep framework fixtures minimal. No project test-coverage percentage is enforced; missing Clover coverage must stay `unknown`. The default known CRAP gate is `< 10`; Dryer candidates are warnings.

## Commit & Pull Request Guidelines

Use short, imperative commit subjects. Pull requests should explain the behavior changed, relevant issue or specification section, tests run, and any dependency or report changes. Include sample JSON for report changes. Keep generated `.metrics/`, Composer `vendor/`, and local IDE files out of commits.
