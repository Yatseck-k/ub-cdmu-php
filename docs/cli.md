# CLI and configuration

[Русская версия](ru/cli.md)

All commands run from the project root by default. `--root PATH` and `--config PATH` are global options and appear before the command. `doctor` reports Python, PHP, analyzer, and Composer dependency availability. `check` runs enabled Crapper and Dryer checks; `crap` and `dry` run one. Each accepts `--format text|json` and `--changed`. `crap` and `dry` accept `--threshold NUMBER`. `--changed` includes added/modified files from staged, unstaged, and untracked Git paths, within configured source directories.

The optional `.ub-mode.yaml` uses `version: 1`. See the repository example. `project.source` directories are used only if present; a full scan with no PHP files is an error, while `--changed` with no changed PHP files succeeds with an empty report. `project.exclude` paths are excluded anywhere in the tree. `crapper.coverage.path` points to an existing Clover XML report. If it is absent, coverage remains unknown. Reports go to `.metrics/` by default. The CLI does not run PHPUnit or Pest and does not modify source files.

Exit codes: `0` means analysis passed (including Dryer warnings or unknown coverage); `2` means a known CRAP score reached the configured threshold; `3` means an environment, configuration, protocol, parser, or report error; `4` means invalid CLI usage. JSON output is deterministic for unchanged inputs.
