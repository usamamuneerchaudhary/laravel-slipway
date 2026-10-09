<?php

/*
|--------------------------------------------------------------------------
| Slipway's own pipeline (dogfooding)
|--------------------------------------------------------------------------
|
| This package builds its CI with itself. .github/workflows/slipway.yml is GENERATED from this
| file; never edit it by hand. After changing this file run:
|
|   vendor/bin/testbench slipway:compile
|
| and commit both files. The `slipway-guard` job in CI fails if they disagree.
|
*/

return [

    'name' => 'CI',

    'drivers' => [
        'github' => ['enabled' => true],
    ],

    'strict' => false,
    'guard' => true,

    // Libraries do not commit composer.lock.
    'lockfile' => false,

    // Slipway's commands run through Orchestra Testbench in a package.
    'artisan' => 'vendor/bin/testbench',

    // Every PHP version the package supports.
    'php' => ['8.3', '8.4'],
    'node' => null,

    'triggers' => [
        'push' => ['main'],
        'tags' => [],
        'pull_request' => true,
        'schedule' => ['0 3 * * 1'],   // weekly: catches breakage from new dependency releases
        'manual' => true,
        'paths_ignore' => [],
    ],

    'concurrency' => ['cancel_superseded' => true],
    'timeouts' => ['default' => 15],
    'cache' => true,
    'env' => [],

    'stages' => [
        'quality' => ['composer-audit', 'secret-scan'],

        'test' => [
            'matrix' => true,
            'steps' => ['pest' => ['prepare_env' => false]],
        ],
    ],

    'environments' => [],
    'policies' => [],
    'secrets' => [],

];
