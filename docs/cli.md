# CLI and configuration

[Русская версия](ru/cli.md)

Run `vendor/bin/ub-php` from the analyzed project's root. Global `--root PATH` and `--config PATH` options appear before the command. `doctor` reports the PHP runtime, parser/YAML dependency availability, and project directory. `check` runs enabled Crapper and Dryer checks; `crap` and `dry` run one. Each accepts `--format text|json`; check commands accept `--changed`, while `crap` and `dry` also accept `--threshold NUMBER`. `--changed` includes added or modified files from staged, unstaged, and untracked Git paths within configured source directories.

The optional `.ub-mode.yaml` uses `version: 1`. See the repository example. `project.source` directories are used only if present. A full scan with no PHP files is an error; `--changed` with no changed PHP files succeeds with an empty report. `project.exclude` paths are excluded anywhere in the tree. `crapper.coverage.path` points to an existing Clover XML report. If absent, coverage stays unknown. Reports go to `.metrics/` by default. The CLI does not run PHPUnit or Pest and does not modify source files.

Exit codes: `0` means analysis passed (including Dryer warnings or unknown coverage); `2` means a known CRAP score reached the configured threshold; `3` means an environment, configuration, parser, or report error; `4` means invalid CLI usage. JSON output is deterministic for unchanged inputs. Git is needed only for `--changed`.
