# Cursor project rule

After changing PHP, run the project's configured tests and `ub-php check --changed --format json`. Review `ub-php dry --changed --threshold 0.8` results before refactoring. Report errors, CRAP failures, duplicate warnings, unknown coverage, and skipped mutation/UML gates distinctly. Keep thresholds unchanged.
