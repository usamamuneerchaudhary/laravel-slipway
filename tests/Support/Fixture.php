<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Support;

use Usamamuneerchaudhary\Slipway\Compiler;
use Usamamuneerchaudhary\Slipway\CompileResult;
use Usamamuneerchaudhary\Slipway\Slipway;

/** Reusable configurations and helpers for the test suite. */
final class Fixture
{
    /** The smallest valid configuration: one stage, GitHub only. */
    public static function minimal(array $overrides = []): array
    {
        return array_replace([
            'drivers' => ['github' => ['enabled' => true]],
            'php' => ['8.3'],
            'triggers' => ['push' => ['main'], 'pull_request' => true],
            'stages' => ['test' => ['pest']],
        ], $overrides);
    }

    /** Everything at once: all providers, matrix, services, artifact, two environments, policies. */
    public static function full(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Acme CI',
            'drivers' => ['github' => true, 'bitbucket' => true, 'gitlab' => true],
            'php' => ['8.2', '8.3'],
            'node' => '22',
            'triggers' => [
                'push' => ['main'],
                'tags' => ['v*'],
                'pull_request' => true,
                'schedule' => ['0 3 * * 1'],
                'manual' => true,
                'paths_ignore' => ['docs/**'],
            ],
            'stages' => [
                'quality' => ['pint', 'larastan' => ['level' => 6], 'composer-audit', 'secret-scan'],
                'test' => ['matrix' => true, 'steps' => ['pest' => ['parallel' => true, 'services' => ['mysql', 'redis']]]],
                'build' => ['build-assets', 'build-artifact'],
            ],
            'environments' => [
                'staging' => [
                    'branch' => 'main',
                    'via' => 'ssh',
                    'verify' => ['url_secret' => 'STAGING_URL', 'path' => '/up'],
                    'rollback' => true,
                    'options' => ['host' => 'staging.example.com', 'user' => 'deploy', 'path' => '/var/www/app'],
                ],
                'production' => [
                    'after' => 'staging',
                    'approval' => true,
                    'via' => 'ssh',
                    'verify' => ['url' => 'https://example.com', 'path' => '/up'],
                    'rollback' => true,
                    'options' => ['host' => 'prod.example.com', 'user' => 'deploy', 'path' => '/var/www/app'],
                ],
            ],
            'policies' => ['audit-before-production', 'approval-for-production', 'tests-before-deploy', 'verify-production', 'rollback-for-production'],
            'secrets' => ['SSH_PRIVATE_KEY', 'SSH_KNOWN_HOSTS', 'STAGING_URL', 'SLACK_WEBHOOK'],
            'notifications' => ['slack' => ['secret' => 'SLACK_WEBHOOK', 'on' => ['failure', 'success']]],
        ], $overrides);
    }

    public static function compiler(): Compiler
    {
        return Slipway::compiler();
    }

    public static function compile(array $config, ?string $root = null): CompileResult
    {
        return self::compiler()->compile($config, root: $root);
    }

    /** A temporary directory removed by `remove()`. */
    public static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/slipway-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);

        return $dir;
    }

    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }
}
