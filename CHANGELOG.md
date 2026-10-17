# Changelog

All notable changes are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/).

## [1.8] - 2026-10-17

### Added

- Local runs warn when `git status --porcelain` is not empty. `slipway:test-pipeline --clean` and
  `./ci-local.sh --clean` clone `HEAD` into a detached worktree so uncommitted files cannot produce
  a false pass. Secrets still come from the project's `.env`.
- Pest TIA: `'pest' => ['tia' => true]` writes `.github/workflows/tia-baseline.yml` (GitHub only)
  following [Pest's TIA workflow](https://pestphp.com/docs/tia). The pull-request test command stays
  a full suite. `php artisan slipway:tia` adds `pest()->tia()->locally()->baselined()` to
  `tests/Pest.php`. Removing `tia` does not delete an already committed baseline workflow; delete
  `.github/workflows/tia-baseline.yml` yourself.
- `build-assets` presets: `npm` (default), `vite`, and `bun`. Bun needs `'bun' => '1.2.21'` (exact
  version) and is installed from GitHub Releases after checksum verification.
- Step `sentry`: injects and uploads source maps with the locked `@sentry/cli` (`org`, `project`,
  optional `path` and `token`). Put it after `build-assets` in the same stage.

### Fixed

- CircleCI Bun caches are keyed on the one lockfile that exists (`bun.lock` or `bun.lockb`). Checksum of a missing file was failing the job before `bun install`.
- GitHub Bun setup installs into `$HOME/.bun/bin` and appends that directory to `$GITHUB_PATH`. The root install into `/usr/local/bin` stays in container jobs.
- `slipway:tia` writes into the package working directory under Testbench, appends only to an existing Pest file, and ignores comments and unrelated `->tia(` calls.
- `slipway:pin` also reads `.github/workflows/tia-baseline.yml`.
- A Pest TIA baseline uses `--parallel` when the Pest step does. An explicit `tia.parallel` that the Pest step does not use is a compile warning.
- `build-assets` preset `vite` without `script` warns that the step runs `npx vite build`.
- Sentry source-map upload sets `--release` from `SENTRY_RELEASE` or the CI commit SHA.
- `./ci-local.sh --clean` prunes a worktree registration when `git worktree remove` fails. A dirty-tree warning is printed before the Docker check.

## [1.7] - 2026-10-12

### Added

- `slipway:test-pipeline`: writes `ci-local.sh` from `config/slipway.php` and runs a pipeline stage locally in Docker.
  Interactive terminals prompt for driver, stage and PHP version, then run after writing. `--no-run` writes the script
  only; `--no-interaction` needs `--driver` when more than one provider is configured. Warns if Docker is not on PATH;
  `--run` fails instead of starting containers when it is missing.

## [1.6] - 2026-10-11

### Added

- CircleCI pipeline file for the package's own CI.
- Step `infection`: opt-in mutation testing with Infection (`min_msi`, `min_covered_msi`, `threads`, `config`, `git_diff`, `only_covering_tests`). Not added to the default presets.

## [1.5] - 2026-10-11

### Added

- CircleCI driver (`circleci`).

## [1.4] - 2026-10-10

### Changed

- Secret scanner improvements.

## [1.3] - 2026-10-10

### Added

- Secret scanner skip paths for generated and vendor folders.

## [1.2] - 2026-10-09

### Added

- `cloud` deployer: triggers a Laravel Cloud deploy hook for the commit that passed CI (`secret`, `commit`).
- Steps `composer-validate`, `npm-test` and `rector` (dry run only).
- `slipway:pin`: resolves the GitHub Actions a workflow uses to commit SHAs, ready to paste into the `pins` option.
- In `strict` mode, compiling warns about GitHub Actions that are not pinned to a commit.
- README recipes for Ploi, Coolify, Dokploy (`webhook`), Kamal and Deployer (`command`).
- Laravel Pint is now part of the package's own quality checks.

### Changed

- Node.js on Bitbucket and GitLab is installed from the official nodejs.org tarball and verified against the
  published `SHASUMS256.txt`, replacing the NodeSource setup script.

## [1.1] - 2026-10-09

Hardening release. Run `slipway:compile` once after upgrading: the generated header and config hash no longer
contain the Slipway version, so upgrading Slipway will not make committed pipelines stale again.

### Changed

- SSH deploys run in batch mode with connection timeouts and keep-alives, remove the deploy key from the runner
  when the script ends, and treat pruning old releases as best effort.
- The Composer installer is verified against its published checksum before it runs on container providers.
  Downloaded setup scripts are executed only after a successful download.
- Drift detection ignores Windows line endings.
- The secret scanner reads the files git tracks, so a local gitignored `.env` is no longer reported.
- Credential findings in generated output are reported together with configuration errors.

### Fixed

- Anchored validation patterns accepted a trailing newline; hosts, users and similar values with one are rejected.
- PHP 8.5 deprecation of `curl_close()` in the health checker.

## [1.0] - 2026-10-09

First stable release. Requires PHP 8.3+ and Laravel 11, 12 or 13.

### Added

- Compile `config/slipway.php` (or `slipway.php` in a library) to GitHub Actions, Bitbucket Pipelines and GitLab CI.
- Deterministic output with a config hash in the header, and a `slipway-guard` job that fails CI when the committed
  YAML is stale or hand-edited.
- Commands: `slipway:init`, `compile`, `check`, `validate`, `explain`, `drivers`, `scan`, `verify`.
- Presets: `app`, `deploy` and `package` (library, run through Orchestra Testbench).
- Built-in steps: `pint`, `larastan`/`phpstan`, `composer-audit`, `npm-audit`, `secret-scan`, `pest`, `phpunit`,
  `build-assets`, `build-artifact`; inline commands; custom steps by class.
- Build once, deploy everywhere: one `slipway-build.tar.gz` artifact promoted through environments.
- Deployers: `ssh` (releases, shared `.env`/`storage`, atomic `current` symlink, verified host keys), `vapor`, `forge`,
  `envoyer`, `webhook`, `command`.
- Post-deploy HTTP verification with retries and automatic rollback; the job still fails after a rollback.
- Policy as code: `declared-secrets`, `tests-before-deploy`, `audit-before-production`, `approval-for-production`,
  `verify-production`, `rollback-for-production`; custom policies.
- Secrets referenced by name only, with a credential scanner over the config, the generated output and the repository.
- Slack and Microsoft Teams notifications.
- Provider capability matrix, `strict` mode, and per-driver `path`, `options` and `raw` overrides.
- `lockfile` option for libraries that do not commit `composer.lock`.
- Public extension points: `Driver`, `PipelineStep`, `Policy`, `Deployer`, plus a reusable driver conformance suite.
