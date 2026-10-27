# GitHub Copilot repository instruction

Use the project's tests and run `ub-php check --changed --format json` after PHP edits. Use `ub-php dry --changed --threshold 0.8` for `ub-mode` duplicate review. Explain any CRAP gate failure, Dryer warning, unknown coverage, or unavailable mutation/UML gate. Do not adjust thresholds to make checks pass.
