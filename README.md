<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/usamamuneerchaudhary/laravel-slipway/main/public/images/slipway-logo-dark.png">
    <source media="(prefers-color-scheme: light)" srcset="https://raw.githubusercontent.com/usamamuneerchaudhary/laravel-slipway/main/public/images/slipway-logo-light.png">
    <img alt="Slipway" src="https://raw.githubusercontent.com/usamamuneerchaudhary/laravel-slipway/main/public/images/slipway-logo-light.png" width="420">
  </picture>
</p>

<p align="center">
  <a href="https://laravel-slipway.mintlify.site/"><img src="https://img.shields.io/badge/docs-laravel--slipway.mintlify.site-FF6A2B" alt="Documentation"></a>
  <a href="https://packagist.org/packages/usamamuneerchaudhary/laravel-slipway"><img src="https://img.shields.io/packagist/v/usamamuneerchaudhary/laravel-slipway" alt="Packagist"></a>
  <a href="https://packagist.org/packages/usamamuneerchaudhary/laravel-slipway"><img src="https://img.shields.io/packagist/l/usamamuneerchaudhary/laravel-slipway" alt="License"></a>
</p>

**Define your CI/CD once, in PHP. Compile it to GitHub Actions, Bitbucket Pipelines and GitLab CI.**

Slipway is a Laravel package that treats `config/slipway.php` as the single source of truth for your
pipeline. The provider YAML files are *generated* from it, committed, and guarded: if somebody edits
a workflow by hand (or forgets to regenerate it), CI fails.

**Documentation:** [https://laravel-slipway.mintlify.site/](https://laravel-slipway.mintlify.site/)

```php
// config/slipway.php
return [
    'drivers' => ['github' => true, 'gitlab' => true],
    'php'     => ['8.3', '8.4'],
    'node'    => '22',

    'stages' => [
        'quality' => ['pint', 'larastan', 'composer-audit', 'secret-scan'],
        'test'    => ['matrix' => true, 'steps' => ['pest' => ['services' => ['mysql']]]],
        'build'   => ['build-assets' => ['preset' => 'vite'], 'sentry' => ['org' => 'acme', 'project' => 'web'], 'build-artifact'],
    ],

    'environments' => [
        'staging'    => ['branch' => 'main', 'via' => 'ssh', 'rollback' => true,
                         'verify' => ['url_secret' => 'STAGING_URL'], 'options' => ['host' => 'staging.example.com', 'user' => 'deploy', 'path' => '/var/www/staging']],
        'production' => ['after' => 'staging', 'approval' => true, 'via' => 'ssh', 'rollback' => true,
                         'verify' => ['url_secret' => 'PRODUCTION_URL'], 'options' => ['host' => 'app.example.com', 'user' => 'deploy', 'path' => '/var/www/app']],
    ],

    'policies' => ['tests-before-deploy', 'approval-for-production', 'rollback-for-production'],
    'secrets'  => ['SSH_PRIVATE_KEY', 'SSH_KNOWN_HOSTS', 'STAGING_URL', 'PRODUCTION_URL', 'SENTRY_AUTH_TOKEN'],
];
```

```bash
php artisan slipway:compile          # writes .github/workflows/slipway.yml and .gitlab-ci.yml
php artisan slipway:check            # fails if the committed YAML is stale (CI runs this for you)
php artisan slipway:test-pipeline    # writes ./ci-local.sh and runs a stage locally in Docker
php artisan slipway:tia              # add pest()->tia()->locally()->baselined() to an existing tests/Pest.php
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

Presets, library setup, the config reference, deployers, policies and extension points are in the
[documentation](https://laravel-slipway.mintlify.site/):

* [Quickstart](https://laravel-slipway.mintlify.site/quickstart)
* [Configuration](https://laravel-slipway.mintlify.site/configuration)
* [Stages and steps](https://laravel-slipway.mintlify.site/stages-and-steps)
* [Environments and deployers](https://laravel-slipway.mintlify.site/environments)
* [Security model](https://laravel-slipway.mintlify.site/security-model)
* [Extending](https://laravel-slipway.mintlify.site/extending)

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
[writing a driver](https://laravel-slipway.mintlify.site/writing-a-driver).

## License

MIT. See [LICENSE](LICENSE).
