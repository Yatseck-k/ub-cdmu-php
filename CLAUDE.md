# ub-mode-php agent guidance

For PHP changes, run the target project's configured tests, then `ub-php check --changed --format json`. Review `ub-php dry --changed --threshold 0.8` candidates before refactoring. Report CRAP failures, Dryer warnings, unknown coverage, and unavailable mutation/UML gates separately. Never lower thresholds to pass checks. See `docs/cli.md` for exit codes and `integrations/claude/CLAUDE.md` for a short invocation example.
