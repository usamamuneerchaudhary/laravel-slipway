<?php

/*
|--------------------------------------------------------------------------
| Slipway: CI/CD defined once, compiled for every provider
|--------------------------------------------------------------------------
|
| This file is the single source of truth for your pipeline. The YAML files
| (.github/workflows/slipway.yml, bitbucket-pipelines.yml, .gitlab-ci.yml, .circleci/config.yml) are
| GENERATED from it and committed. Never edit them by hand.
|
|   php artisan slipway:compile    write the YAML files
|   php artisan slipway:check      fail if the YAML is out of date (runs in CI too)
|   php artisan slipway:explain    describe the pipeline in plain English
|   php artisan slipway:drivers    which features each provider supports
|
| Keep this file free of closures so `php artisan config:cache` keeps working.
| Secrets are referenced by NAME only. Never put a secret value in this file.
|
*/

return [

    // Shown as the pipeline name on the provider.
    'name' => 'CI',

    // Which providers to generate. Each can override the output `path`, add `options`
    // specific to that provider, or merge extra YAML through `raw`.
    'drivers' => [
        'github' => ['enabled' => true],
        'bitbucket' => ['enabled' => false],
        'gitlab' => ['enabled' => false],
        'circleci' => ['enabled' => false],
    ],

    // true: a feature the provider cannot express makes compilation fail.
    // false: it is left out and reported as a warning.
    'strict' => false,

    // Adds a job that fails the build when the committed YAML no longer matches this file.
    'guard' => true,

    // true: the project commits composer.lock (applications). The Composer cache is keyed on it and
    // `composer audit` checks it. Set false for libraries, which do not commit a lock file.
    'lockfile' => true,

    // PHP versions. Stages marked `matrix => true` run on all of them; other stages use the newest.
    'php' => ['8.3', '8.4'],

    // Node version for steps that build front-end assets. null = the project has no Node build.
    'node' => null,

    // PHP extensions (setup-php on GitHub; compiled into the image on Bitbucket and GitLab).
    // 'extensions' => ['mbstring', 'dom', 'curl', 'libxml', 'zip', 'pcntl', 'pdo', 'bcmath', 'intl', 'exif', 'pdo_sqlite'],

    'triggers' => [
        'push' => ['main'],            // branches (globs allowed); false disables
        'tags' => [],                  // e.g. ['v*'] to build and release on tags
        'pull_request' => true,        // true, false, or a list of target branches
        'schedule' => [],              // cron expressions (UTC), e.g. ['0 3 * * 1']
        'manual' => false,             // allow starting a run by hand
        'paths_ignore' => [],          // e.g. ['docs/**', '*.md'] (GitHub only)
    ],

    // Cancel runs that a newer push has made obsolete (saves minutes and money).
    'concurrency' => ['cancel_superseded' => true],

    // Default job timeout in minutes. A stuck job should not burn a runner for hours.
    'timeouts' => ['default' => 20],

    // Cache Composer and npm dependencies between runs.
    'cache' => true,

    // Non-secret environment variables for every job.
    'env' => [],

    /*
    |--------------------------------------------------------------------------
    | Stages
    |--------------------------------------------------------------------------
    |
    | Stages run in order; the jobs inside one stage run in parallel.
    | A step is a built-in alias, a class implementing PipelineStep, or an inline command.
    |
    |   'pint'                                  an alias
    |   'larastan' => ['level' => 6]            an alias with options
    |   ['run' => 'php artisan lint:routes']    an inline command (POSIX sh)
    |
    | Built-in aliases: pint, larastan, composer-validate, composer-audit, npm-audit,
    |                   npm-test, rector, secret-scan, pest, phpunit, infection,
    |                   build-assets, build-artifact
    |
    | Stage options: matrix, php, parallel (one job per step), services, timeout, on, env.
    |
    */
    'stages' => [
        'quality' => ['pint', 'larastan', 'composer-audit', 'secret-scan'],

        'test' => [
            'matrix' => true,
            'steps' => [
                'pest',
                // 'pest' => ['parallel' => true, 'services' => ['mysql', 'redis']],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Environments (continuous delivery)
    |--------------------------------------------------------------------------
    |
    | Add a 'build' stage with 'build-artifact', then define where it deploys.
    | `php artisan slipway:init --preset=deploy` writes a complete working example.
    |
    */
    'environments' => [],

    /*
    |--------------------------------------------------------------------------
    | Policies (policy as code)
    |--------------------------------------------------------------------------
    |
    | Rules checked at compile time. A violation stops compilation, so a risky
    | pipeline cannot be merged unnoticed. Add your own by implementing Policy.
    |
    | audit-before-production, tests-before-deploy, approval-for-production,
    | verify-production, rollback-for-production
    |
    */
    'policies' => [
        // 'tests-before-deploy',
    ],

    // Every secret the pipeline reads, by NAME. Anything used but not declared fails compilation.
    'secrets' => [],

    // 'notifications' => ['slack' => ['secret' => 'SLACK_WEBHOOK', 'on' => ['failure']]],

];
