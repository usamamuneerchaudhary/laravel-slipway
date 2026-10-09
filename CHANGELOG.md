# Changelog

All notable changes are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-10-09

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
