# Claude Code

Follow the repository's `ub-mode` skill. Run the target project's configured tests, then `ub-php check --changed --format json`. For a stricter duplicate review run `ub-php dry --changed --threshold 0.8`. Treat Dryer candidates as warnings and missing coverage as unknown. Mutation and UML gates are unavailable in v0.1; report them as skipped.
