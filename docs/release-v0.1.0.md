# v0.1.0 release notes (draft)

[Русская версия](ru/release-v0.1.0.md)

- Composer package and PHP-only CLI with versioned JSON reports.
- CRAP scores from static complexity and existing Clover line coverage; missing coverage stays unknown.
- Structural duplicate warnings with configurable Jaccard threshold and JSON/EDN reports.
- Changed-file selection and PHP/Composer CI tests. Agent integration examples are maintained in the separate `ub-mode-php` repository.

Known limits: no automatic coverage collection, mutation testing, UML visualization, or Windows support. Framework validation uses small syntax fixtures. This draft becomes a release note only after all v0.1 validation gates pass.
