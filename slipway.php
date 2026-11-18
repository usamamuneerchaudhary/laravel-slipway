<?php

/*
|--------------------------------------------------------------------------
| Slipway's own pipeline (dogfooding)
|--------------------------------------------------------------------------
|
| This package builds its CI with itself. .github/workflows/slipway.yml and .circleci/config.yml
| are GENERATED from this file; never edit them by hand. After changing this file run:
|
|   vendor/bin/testbench slipway:compile
|
| and commit both files. The `slipway-guard` job in CI fails if they disagree.
|
*/

return [

    'name' => 'CI',

    'drivers' => [
        'github' => [
            'enabled' => true,
            // Resolved with `vendor/bin/testbench slipway:pin`.
            'options' => [
                'pins' => [
                    'actions/cache' => '55cc8345863c7cc4c66a329aec7e433d2d1c52a9', // v6.1.0
                    'actions/checkout' => '3d3c42e5aac5ba805825da76410c181273ba90b1', // v7.0.1
                    'shivammathur/setup-php' => 'eb7c497e18156a6bbabfef1d3a82760b9eda3962', // v2
                ],
            ],
        ],
        'circleci' => [
            'enabled' => true,
        ],
    ],

    'strict' => true,
    'guard' => true,

    // Libraries do not commit composer.lock.
    'lockfile' => false,

    // Slipway's commands run through Orchestra Testbench in a package.
    'artisan' => 'vendor/bin/testbench',

    // Every PHP version the package supports.
    'php' => ['8.3', '8.4', '8.5'],
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
        'quality' => ['pint', 'composer-validate', 'composer-audit', 'secret-scan'],

        'test' => [
            'matrix' => true,
            'steps' => ['pest' => ['prepare_env' => false]],
        ],
    ],

    'environments' => [],
    'policies' => [],
    'secrets' => [],

];
