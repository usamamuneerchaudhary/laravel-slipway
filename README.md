<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/usamamuneerchaudhary/laravel-slipway/main/public/images/slipway-logo-dark.png">
    <source media="(prefers-color-scheme: light)" srcset="https://raw.githubusercontent.com/usamamuneerchaudhary/laravel-slipway/main/public/images/slipway-logo-light.png">
    <img alt="Slipway" src="https://raw.githubusercontent.com/usamamuneerchaudhary/laravel-slipway/main/public/images/slipway-logo-light.png" width="420">
  </picture>
</p>


**Define your CI/CD once, in PHP. Compile it to GitHub Actions, Bitbucket Pipelines and GitLab CI.**

Slipway is a Laravel package that treats `config/slipway.php` as the single source of truth for your
pipeline. The provider YAML files are *generated* from it, committed, and guarded: if somebody edits
a workflow by hand (or forgets to regenerate it), CI fails.

```php
// config/slipway.php
return [
    'drivers' => ['github' => true, 'gitlab' => true],
    'php'     => ['8.3', '8.4'],

    'stages' => [
        'quality' => ['pint', 'larastan', 'composer-audit', 'secret-scan'],
        'test'    => ['matrix' => true, 'steps' => ['pest' => ['services' => ['mysql']]]],
        'build'   => ['build-artifact'],
    ],

    'environments' => [
        'staging'    => ['branch' => 'main', 'via' => 'ssh', 'rollback' => true,
                         'verify' => ['url_secret' => 'STAGING_URL'], 'options' => ['host' => 'staging.example.com', 'user' => 'deploy', 'path' => '/var/www/staging']],
        'production' => ['after' => 'staging', 'approval' => true, 'via' => 'ssh', 'rollback' => true,
                         'verify' => ['url_secret' => 'PRODUCTION_URL'], 'options' => ['host' => 'app.example.com', 'user' => 'deploy', 'path' => '/var/www/app']],
    ],

    'policies' => ['tests-before-deploy', 'approval-for-production', 'rollback-for-production'],
    'secrets'  => ['SSH_PRIVATE_KEY', 'SSH_KNOWN_HOSTS', 'STAGING_URL', 'PRODUCTION_URL'],
];
```

```bash
php artisan slipway:compile   # writes .github/workflows/slipway.yml and .gitlab-ci.yml
php artisan slipway:check     # fails if the committed YAML is stale (CI runs this for you)
```

## Why

| Problem with hand-written pipelines | What Slipway does |
|---|---|
| YAML drifts between providers and between repositories | One PHP config, one deterministic compiler. Same input, same bytes. |
| Someone edits the workflow in a hurry and nobody reviews it properly | A `slipway-guard` job fails the build when the YAML differs from the config. |
| Secrets end up in YAML, logs or shell history | Secrets are referenced **by name only**. A scanner checks the config and the generated output. Values are never printed. |
| "We deploy on Fridays and hope" | Build once, deploy that same artifact to every environment, health-check it, roll back automatically. |
| Safety rules live in a wiki | **Policies** such as `tests-before-deploy` fail compilation if a change would weaken them. |
| Provider lock-in | Switch or add a provider with one line. Unsupported features are reported, or are errors in `strict` mode. |

## Requirements

* PHP 8.3+
* Laravel 11, 12 or 13 (`illuminate/console`, `illuminate/support`, `illuminate/contracts`)
* For libraries (packages that are not Laravel apps): [Orchestra Testbench](https://github.com/orchestral/testbench)

## Installation

```bash
composer require --dev usamamuneerchaudhary/laravel-slipway
php artisan slipway:init                       # or --preset=deploy / --preset=package
php artisan slipway:init --driver=github --driver=bitbucket
```

Review the generated `config/slipway.php`, then:

```bash
php artisan slipway:compile
git add config/slipway.php .github .gitlab-ci.yml bitbucket-pipelines.yml
```

Install it as a **dev** dependency. The build artifact is produced with `composer install --no-dev`, so
Slipway never ships to production.

### Presets

| Preset | For | Writes |
|---|---|---|
| `app` (default) | A Laravel application: quality + test matrix | `config/slipway.php` |
| `deploy` | An application with continuous delivery over SSH (staging then production, approval, verify, rollback) | `config/slipway.php` |
| `package` | A library, run through Testbench | `slipway.php` in the package root |

### Libraries (packages)

A library has no `artisan` file and does not commit `composer.lock`. The `package` preset sets:

```php
'artisan'  => 'vendor/bin/testbench',   // Slipway's own commands run through Testbench
'lockfile' => false,                    // cache keyed on composer.json; `composer audit` checks what was installed
```

Add this to the library's `composer.json` so Testbench discovers Slipway's service provider:

```json
"scripts": {
    "post-autoload-dump": ["@php vendor/bin/testbench package:discover --ansi"]
}
```

then use `vendor/bin/testbench slipway:compile` and `vendor/bin/testbench slipway:check`. This repository
builds its own pipeline that way: see [`slipway.php`](slipway.php) and
[`.github/workflows/slipway.yml`](.github/workflows/slipway.yml).

## How it works

```
config/slipway.php
        |  Schema          validate everything, report every error at once
        v
   neutral Pipeline        stages -> jobs -> steps, environments, artifact, secrets
        |  PolicyEngine    tests-before-deploy, approval-for-production, ...
        |  SecretScanner   no credentials in the config or the generated output
        v
   Driver (per provider)   GitHub Actions | Bitbucket Pipelines | GitLab CI
        v
 .github/workflows/slipway.yml, bitbucket-pipelines.yml, .gitlab-ci.yml
```

Output is deterministic: no timestamps, stable ordering, a short config hash in the header. That is what
lets `slipway:check` detect drift with a byte comparison (line endings are ignored, so a Windows checkout with
`core.autocrlf` is not reported as drift). Neither the header nor the hash contains the Slipway version, so
upgrading Slipway never makes your committed pipeline files stale; only a change to the output itself does.

## Configuration reference

Every key is validated. Unknown keys are rejected with a "did you mean" suggestion, and all problems are
reported together.

| Key | Default | Meaning |
|---|---|---|
| `name` | `CI` | Pipeline name shown by the provider. |
| `drivers` | `github` | Map of provider => `true`/`false` or `['enabled', 'path', 'options', 'raw']`. See [Drivers](#drivers). |
| `strict` | `false` | `true`: a feature the provider cannot express is a compile error. `false`: it is left out and reported as a warning. |
| `guard` | `true` | Adds the `slipway-guard` job that runs `slipway:check`. |
| `lockfile` | `true` | `true`: the project commits `composer.lock` (cache key, `composer audit --locked`). `false` for libraries. |
| `artisan` | `php artisan` | Command used for Slipway's own commands and `key:generate`. `vendor/bin/testbench` for packages. |
| `php` | `['8.3']` | PHP versions. Stages with `matrix => true` run on all of them; other stages use the newest. |
| `extensions` | common set | PHP extensions to install. |
| `node` | `null` | Node version, required by steps that build front-end assets. |
| `triggers` | push to `main`, PRs | `push` (branches or `false`), `tags`, `pull_request` (`true`, `false` or target branches), `schedule` (cron, UTC), `manual`, `paths_ignore`. |
| `concurrency.cancel_superseded` | `true` | Cancel runs a newer push has made obsolete. Deploys are never cancelled mid-flight. |
| `timeouts.default` | `20` | Job timeout in minutes. |
| `cache` | `true` | Cache Composer and npm dependencies. |
| `env` | `[]` | Non-secret environment variables for every job. |
| `stages` | required | Ordered stages. See below. |
| `environments` | `[]` | Where the artifact is deployed. See below. |
| `artifact.paths` | app defaults | What `build-artifact` packs into the tarball (only paths that exist). |
| `policies` | `[]` | Rules evaluated at compile time. |
| `secrets` | `[]` | Every secret the pipeline reads, **by name**. Using an undeclared secret fails compilation. |
| `notifications` | none | `slack` / `teams`: `['secret' => 'NAME', 'on' => ['failure', 'success']]`. |
| `scan.ignore` | `[]` | Path prefixes the secret scanner skips. |

### Stages and steps

Stages run in order; jobs inside one stage run in parallel. A step is a built-in alias, a class implementing
`PipelineStep`, or an inline command (POSIX `sh`):

```php
'stages' => [
    'quality' => [
        'pint',                                  // alias
        'larastan' => ['level' => 6],            // alias with options
        ['run' => 'php artisan lint:routes'],    // inline command
    ],
    'test' => ['matrix' => true, 'steps' => ['pest' => ['parallel' => true, 'services' => ['mysql', 'redis']]]],
],
```

Stage options: `steps`, `matrix`, `parallel` (one job per step), `php` (a version, or `false` for no PHP),
`services`, `timeout`, `on` (`pull_request`, `push`, `tag`, `schedule`, `manual`), `env`, `extensions`.

| Alias | Does | Options |
|---|---|---|
| `pint` | `vendor/bin/pint --test` | none |
| `larastan` / `phpstan` | PHPStan analysis | `level`, `memory_limit` |
| `composer-validate` | `composer validate --no-interaction --strict` | `strict` (default `true`) |
| `composer-audit` | `composer audit` (adds `--locked` when `lockfile` is true) | none |
| `npm-audit` | `npm audit` | `level` |
| `npm-test` | `npm ci && npm test` (or `npm run <script>`); needs `node` | `script` |
| `rector` | `vendor/bin/rector process --dry-run`: reports changes, never rewrites files | `config` |
| `secret-scan` | `slipway:scan`: scans the files git tracks (plus untracked, non-ignored files), so a local gitignored `.env` is never reported | none |
| `pest` / `phpunit` | Run the tests; prepares `.env` and the app key | `parallel`, `coverage` (`true` or a minimum %), `services`, `testsuite`, `prepare_env` |
| `build-assets` | `npm ci && npm run build` | `script` |
| `build-artifact` | Packs **one** tarball, `slipway-build.tar.gz` | `paths`, `optimize` |

Available `services`: `mysql`, `postgres` and `redis`. They start as throwaway containers and the connection
settings are exported as environment variables for the job.

### Environments and deployment

Slipway builds **once** and every environment deploys that exact tarball. Nothing is rebuilt per environment.

```php
'environments' => [
    'staging'    => ['branch' => 'main', 'via' => 'ssh', 'verify' => [...], 'rollback' => true, 'options' => [...]],
    'production' => ['after' => 'staging', 'approval' => true, 'via' => 'ssh', ...],
],
```

Environment keys: `branch` or `tag` (what triggers it), `after` (run after another environment),
`approval` (a human must approve), `via` (deployer), `verify`, `rollback`, `options`.

**Verify** runs an HTTP health check after the deploy, with retries:
`['url' => 'https://…']` or `['url_secret' => 'NAME']`, plus `path` (default `/up`), `expect` (default `200`),
`retries` (default 5), `delay` (seconds, default 10). If verification fails and `rollback` is on, the
previous release is restored and the job still fails, so a broken deploy is never reported as green.

**Deployers** (`via`):

| Deployer | What it does | Options |
|---|---|---|
| `ssh` | Zero-downtime release over SSH: rsync a new `releases/<timestamp>`, share `.env` and `storage`, atomically switch the `current` symlink, prune old releases. Host keys are verified (`StrictHostKeyChecking=yes`). | `host`, `user`, `path`, `port`, `keep_releases`, `migrate`, `optimize`, `storage_link`, `post_deploy`, `key_secret`, `known_hosts_secret` |
| `vapor` | `vendor/bin/vapor deploy` | `secret`, `environment` |
| `forge` | Calls the Forge deployment trigger URL | `secret` |
| `cloud` | Calls the [Laravel Cloud](#laravel-cloud) deploy hook for the commit that passed CI | `secret`, `commit` |
| `envoyer` | Calls the Envoyer deployment hook | `secret` |
| `webhook` | `POST` to your own endpoint | `secret`, `rollback_secret` |
| `command` | Your own shell commands | `commands`, `rollback_commands`, `secrets`, `tools`, `php`, `artifact` |

A server needs a one-time setup. See [docs/ssh-server-setup.md](docs/ssh-server-setup.md).

#### Laravel Cloud

```php
'environments' => [
    'production' => ['branch' => 'main', 'via' => 'cloud', 'verify' => ['url' => 'https://example.com']],
],
'secrets' => ['LARAVEL_CLOUD_DEPLOY_HOOK'],
```

Create a deploy hook in the Cloud environment's settings and store its URL as the `LARAVEL_CLOUD_DEPLOY_HOOK`
secret (or name another with `secret`). Slipway `POST`s to it with `commit_hash` set to the commit that passed CI;
set `'commit' => false` to deploy the branch head instead.

* Laravel Cloud builds from your repository itself, so there is no artifact to upload.
* **Turn off "deploy on push" for the environment**, otherwise Cloud deploys before your tests have finished.
* The hook cannot roll back, so `rollback => true` is rejected for this deployer. `verify` still works and
  fails the job if the site does not come up.

#### Recipes for other platforms

No dedicated deployer is needed for platforms that deploy from a URL or a command:

```php
// Ploi, Coolify, Dokploy: POST to the deploy webhook (stored as a secret)
'production' => ['via' => 'webhook', 'options' => ['secret' => 'PLOI_DEPLOY_WEBHOOK']],

// Kamal
'production' => ['via' => 'command', 'options' => [
    'commands' => ['gem install kamal', 'kamal deploy'],
    'secrets' => ['KAMAL_REGISTRY_PASSWORD'],
    'artifact' => false,
]],

// Deployer (deployer.org)
'production' => ['via' => 'command', 'options' => [
    'commands' => ['vendor/bin/dep deploy production'],
    'secrets' => ['DEPLOY_SSH_KEY'],
    'artifact' => false,
]],
```

Rollback re-points the release; it **does not undo database migrations**. Keep migrations backwards
compatible (expand, then contract).

### Policies

Policies are checked at compile time. An error stops compilation, so a risky pipeline cannot be merged unnoticed.

| Policy | Fails when |
|---|---|
| `declared-secrets` (always on) | The pipeline reads a secret that is not listed in `secrets`. (A declared secret that is never used is a warning.) |
| `tests-before-deploy` | No test run (Pest, PHPUnit, `artisan test`) precedes the deploy job. |
| `audit-before-production` | No `composer audit` step precedes the deploy job. |
| `approval-for-production` | The environment has no manual `approval`. |
| `verify-production` | The environment has no `verify` health check. |
| `rollback-for-production` | The environment does not roll back automatically. |

`tests-before-deploy` applies to every environment by default. The others apply to the `production`
environment only. Target specific environments with options:
`'policies' => ['tests-before-deploy' => ['environments' => ['staging', 'production']]]`.

Write your own by implementing `Policy`. See [docs/extending.md](docs/extending.md).

### Notifications

```php
'notifications' => ['slack' => ['secret' => 'SLACK_WEBHOOK', 'on' => ['failure']]],
```

Failure notices run on every job; success notices on the last stage only. The webhook URL is a secret and is
referenced by name.

### Drivers

```php
'drivers' => [
    'github'    => ['enabled' => true, 'options' => ['permissions' => ['contents' => 'read'], 'pins' => ['actions/checkout' => '<40-char sha>']]],
    'bitbucket' => ['enabled' => true, 'options' => ['image' => 'php:{php}-cli']],
    'gitlab'    => ['enabled' => true, 'path' => '.gitlab-ci.yml', 'raw' => ['default' => ['tags' => ['docker']]]],
],
```

| Driver | Output | Options |
|---|---|---|
| `github` | `.github/workflows/slipway.yml` | `runner`, `permissions`, `pins` |
| `bitbucket` | `bitbucket-pipelines.yml` | `image`, `shell_image`, `bootstrap`, `size` |
| `gitlab` | `.gitlab-ci.yml` | `image`, `shell_image`, `bootstrap`, `tags` |

`path` overrides the output file. `raw` is deep-merged into the generated YAML as an escape hatch (maps merge;
lists and scalars replace).

## Provider support

`php artisan slipway:drivers` prints this for your installed drivers. Features a provider cannot express are
left out with a warning, or fail compilation in `strict` mode.

| Feature | Bitbucket Pipelines | GitHub Actions | GitLab CI/CD |
|---|---|---|---|
| PHP version matrix | partial | yes | yes |
| Service containers | yes | yes | yes |
| Manual approvals | yes | partial | yes |
| Deployment environments | partial | yes | yes |
| Cancel superseded runs | no | yes | partial |
| Scheduled runs | partial | yes | partial |
| Manual trigger | yes | yes | yes |
| Artifact promotion | partial | yes | partial |
| Path filters | no | yes | no |
| Job timeouts | yes | yes | yes |
| Dependency caching | yes | yes | yes |
| Parallel jobs | yes | yes | yes |
| Tag triggers | yes | yes | yes |

Some of the "partial" entries need a one-time setting in the provider's UI (for example GitHub required
reviewers, Bitbucket deployment environments, GitLab "Auto-cancel redundant pipelines", and the actual cron
schedule on Bitbucket and GitLab). Slipway prints exactly which one when it compiles.

## Commands

| Command | Purpose |
|---|---|
| `slipway:init [--preset=app\|package\|deploy] [--driver=*] [--force]` | Create the config from a preset. |
| `slipway:compile [--config=] [--dry-run]` | Write the pipeline files. |
| `slipway:check [--config=]` | Exit non-zero if the committed files differ from the config. |
| `slipway:validate [--config=]` | Validate the config and policies without writing. |
| `slipway:explain [--config=]` | Describe the pipeline in plain English. |
| `slipway:drivers` | The provider capability matrix. |
| `slipway:scan [--path=]` | Scan the repository for committed credentials. |
| `slipway:verify {environment} [--url=] [--config=]` | Run an environment's health check from your machine. |
| `slipway:pin [--config=]` | Resolve the GitHub Actions the workflow uses to commit SHAs, ready to paste into `pins`. |

## Security model

* **Secrets by name only.** The config holds names; the provider holds values. The scanner checks the
  config and the generated YAML, and a finding never contains the matched value.
* **Least privilege on GitHub.** `permissions: contents: read` by default, `persist-credentials: false` on
  checkout. Actions default to major-version tags (`actions/checkout@v4`), which are mutable. Run
  `slipway:pin` to resolve them to commit SHAs, paste the result into the `pins` driver option, and `strict` mode
  warns while any action is still unpinned.
* **No expression injection.** `${{ … }}` is rejected inside `run` scripts. Secrets reach scripts only as
  step `env`, never interpolated into the command line.
* **Host keys are verified** for SSH deploys; there is no "accept any host" mode. SSH runs in batch mode with
  connection timeouts, and the deploy key is removed from the runner when the script ends.
* **Verified tooling on container providers.** On Bitbucket and GitLab the Composer installer is checked against
  its published checksum before it runs. Node.js is installed from the official tarball on nodejs.org after it is
  checked against the published `SHASUMS256.txt`. That protects against corrupt or tampered downloads; it is an
  integrity check, not a signature check. Use your own image through the `image` driver option if you need a
  fully pinned toolchain.
* **Validated inputs.** Hosts, users, paths and URL paths are validated before they reach a shell script.
* **Deterministic output** makes review and drift detection reliable.

Report vulnerabilities as described in [SECURITY.md](SECURITY.md).

## Extending

Everything is a registry, so other packages can add behaviour without forking:

```php
public function boot(): void
{
    $this->app->make('slipway.steps')->register('my-step', MyStep::class);          // PipelineStep
    $this->app->make('slipway.deployers')->register('my-deployer', MyDeployer::class); // Deployer
    $this->app->make('slipway.policies')->register('my-rule', MyPolicy::class);     // Policy
    $this->app->make(\Usamamuneerchaudhary\Slipway\DriverManager::class)->register(new MyDriver()); // Driver
}
```

See [docs/extending.md](docs/extending.md) and [docs/writing-a-driver.md](docs/writing-a-driver.md). The conformance
suite in `tests/Conformance` (copy it into your driver's tests) proves a driver behaves like the built-in ones.

## Known limitations

* **Bitbucket files are large.** Bitbucket Pipelines has no matrix syntax, and Slipway's YAML emitter does not use
  anchors or aliases (to keep output simple to review and byte-stable), so steps are repeated for each trigger
  and each PHP version. A full pipeline can run to tens of kilobytes.
* **No database rollback.** See above.
* **Required reviewers, deployment environments and cron schedules** are provider settings that YAML cannot create.
* **Feature flags and progressive delivery** (canary, blue/green, flag-driven releases) are not built yet.
* **Provider output is generated, not hosted.** This repository's own CI runs on the generated GitHub Actions
  workflow. Bitbucket and GitLab output is covered by the test suite; run `slipway:compile` and review it on
  your first pipeline run.

## Roadmap

Ideas, not promises, roughly in order:

* A feature-flag step for Laravel Pennant, so a release can ship dark and be enabled separately.
* A `slipway:rollback` command for rolling back by hand (rollback today is automatic, inside the pipeline).
* Progressive delivery (canary / blue-green) for the `command` and `ssh` deployers.
* More services (MariaDB, Meilisearch, ...) and more providers through the same `Driver` contract.

## Testing

```bash
composer install
vendor/bin/pest                       # everything
vendor/bin/pest --testsuite=Unit
vendor/bin/pest --testsuite=Conformance
vendor/bin/pest --testsuite=Feature   # Testbench: real artisan commands
vendor/bin/pint --test                # code style
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Pull requests that add a provider driver are very welcome; start with
[docs/writing-a-driver.md](docs/writing-a-driver.md).

## License

MIT. See [LICENSE](LICENSE).
