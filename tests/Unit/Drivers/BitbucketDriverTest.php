<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit\Drivers;

use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Config\ConfigException;
use Usamamuneerchaudhary\Slipway\Tests\Support\Fixture;

final class BitbucketDriverTest extends TestCase
{
    private function yaml(array $config = null): string
    {
        $config ??= Fixture::full(['drivers' => ['bitbucket' => true]]);

        return Fixture::compile($config)->files['bitbucket']->contents;
    }

    public function test_every_trigger_gets_its_own_pipeline(): void
    {
        $yaml = $this->yaml();

        foreach (["pipelines:\n  pull-requests:", "  branches:\n    main:", "  tags:\n    \"v*\":", "  custom:\n    scheduled:", "    manual:"] as $needle) {
            $this->assertStringContainsString($needle, $yaml);
        }
    }

    public function test_php_matrix_is_expanded_into_one_step_per_version(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString('name: Test (PHP 8.2)', $yaml);
        $this->assertStringContainsString('name: Test (PHP 8.3)', $yaml);
        $this->assertStringContainsString('image: php:8.2-cli', $yaml);
    }

    public function test_stage_jobs_run_in_parallel_groups(): void
    {
        $this->assertStringContainsString('parallel:', $this->yaml());
    }

    public function test_pull_requests_do_not_build_or_deploy(): void
    {
        $yaml = $this->yaml();
        $pr = substr($yaml, (int) strpos($yaml, '  pull-requests:'), (int) strpos($yaml, "  branches:") - (int) strpos($yaml, '  pull-requests:'));

        $this->assertStringNotContainsString('deployment:', $pr);
        $this->assertStringNotContainsString('name: Build', $pr);
    }

    public function test_deployments_use_environments_and_manual_triggers(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString('deployment: staging', $yaml);
        $this->assertStringContainsString('deployment: production', $yaml);
        $this->assertStringContainsString('trigger: manual', $yaml);
    }

    public function test_deploy_steps_skip_the_clone_and_use_a_small_shell_image(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString("clone:\n            enabled: false", $yaml);
        $this->assertStringContainsString('image: alpine:3.22', $yaml);
        $this->assertStringContainsString('apk add --no-cache', $yaml);
    }

    public function test_artifact_is_declared_on_the_build_step(): void
    {
        $this->assertStringContainsString("artifacts:\n            - slipway-build.tar.gz", $this->yaml());
    }

    public function test_caches_and_services_are_defined_once_at_the_top(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString("definitions:\n  caches:", $yaml);
        $this->assertStringContainsString('composer-php82:', $yaml);
        $this->assertStringContainsString('composer-php83:', $yaml);
        $this->assertStringContainsString("  services:\n    mysql:\n      image: mysql:8.4", $yaml);
        $this->assertSame(1, substr_count($yaml, "  services:\n    mysql:"));
    }

    public function test_step_time_limits_are_set(): void
    {
        $this->assertStringContainsString('max-time: 20', $this->yaml());
    }

    public function test_multiline_blocks_fail_fast(): void
    {
        $this->assertStringContainsString("- |\n                    set -e\n", $this->yaml());
    }

    public function test_provider_environment_is_translated_to_neutral_variables(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString('export SLIPWAY_SHA="$BITBUCKET_COMMIT"', $yaml);
        $this->assertStringContainsString('SLIPWAY_REPO="$BITBUCKET_REPO_FULL_NAME"', $yaml);
    }

    public function test_services_are_awaited_before_tests_start(): void
    {
        $this->assertStringContainsString('did not start in time', $this->yaml());
    }

    public function test_bootstrap_can_be_replaced_for_prebuilt_images(): void
    {
        $yaml = $this->yaml(Fixture::minimal([
            'drivers' => ['bitbucket' => ['options' => ['image' => 'registry.example.com/php:{php}', 'bootstrap' => []]]],
        ]));

        $this->assertStringContainsString('image: registry.example.com/php:8.3', $yaml);
        $this->assertStringNotContainsString('docker-php-ext-install', $yaml);
        $this->assertStringNotContainsString('apt-get update', $yaml);
    }

    public function test_extensions_the_image_cannot_install_are_reported(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('not known to the container bootstrap');

        $this->yaml(Fixture::minimal(['drivers' => ['bitbucket' => true], 'extensions' => ['mbstring', 'imagick']]));
    }

    public function test_unsupported_features_are_reported_not_silently_dropped(): void
    {
        $warnings = implode("\n", Fixture::compile(Fixture::full(['drivers' => ['bitbucket' => true]]))->warnings);

        $this->assertStringContainsString('Cancel superseded runs is not supported', $warnings);
        $this->assertStringContainsString('Path filters is not supported', $warnings);
    }
}
