# Codex

The repository skill is `.agents/skills/ub-mode/SKILL.md`; select `$ub-mode` for code changes. Run the target project's tests first. Then run `ub-php check --changed --format json` and, when reviewing duplication at the skill threshold, `ub-php dry --changed --threshold 0.8`. Report CRAP failures, Dryer warnings, unknown coverage, and unavailable v0.2 mutation/UML gates separately. Do not alter thresholds to pass a check.
