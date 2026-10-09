<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit\Drivers;

use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Config\ConfigException;
use Usamamuneerchaudhary\Slipway\Tests\Support\Fixture;

final class GitLabDriverTest extends TestCase
{
    private function yaml(array $config = null): string
    {
        $config ??= Fixture::full(['drivers' => ['gitlab' => true]]);

        return Fixture::compile($config)->files['gitlab']->contents;
    }

    public function test_stages_are_declared_in_order(): void
    {
        $this->assertStringContainsString("stages:\n  - quality\n  - test\n  - build\n  - deploy-staging\n  - deploy-production\n", $this->yaml());
    }

    public function test_workflow_rules_avoid_duplicate_branch_and_merge_request_pipelines(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString('$CI_COMMIT_BRANCH && $CI_OPEN_MERGE_REQUESTS && $CI_PIPELINE_SOURCE == "push"', $yaml);
        $this->assertStringContainsString('when: never', $yaml);
        $this->assertStringContainsString('$CI_PIPELINE_SOURCE == "merge_request_event"', $yaml);
    }

    public function test_matrix_uses_a_php_version_variable_in_image_and_cache(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString("parallel:\n    matrix:\n      - PHP_VERSION:\n          - \"8.2\"\n          - \"8.3\"", $yaml);
        $this->assertStringContainsString('image: php:$PHP_VERSION-cli', $yaml);
        $this->assertStringContainsString('prefix: composer-php$PHP_VERSION', $yaml);
    }

    public function test_services_use_aliases_as_hostnames(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString("alias: mysql", $yaml);
        $this->assertStringContainsString('DB_HOST: mysql', $yaml);
        $this->assertStringContainsString('REDIS_HOST: redis', $yaml);
    }

    public function test_services_are_awaited_before_scripts_run(): void
    {
        $this->assertStringContainsString('did not start in time', $this->yaml());
    }

    public function test_manual_gate_blocks_the_pipeline_instead_of_being_skipped(): void
    {
        $yaml = $this->yaml();
        $production = substr($yaml, (int) strpos($yaml, "deploy-production:\n  stage"));

        $this->assertStringContainsString('when: manual', $production);
        $this->assertStringContainsString('allow_failure: false', $production);
    }

    public function test_deploys_use_environments_and_resource_groups(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString("environment:\n    name: staging", $yaml);
        $this->assertStringContainsString('resource_group: production', $yaml);
    }

    public function test_deploy_jobs_do_not_fetch_the_repository(): void
    {
        $this->assertStringContainsString('GIT_STRATEGY: none', $this->yaml());
    }

    public function test_only_non_deploy_jobs_are_interruptible(): void
    {
        $yaml = $this->yaml();
        $deploy = substr($yaml, (int) strpos($yaml, "deploy-staging:\n  stage"));

        $this->assertStringContainsString('interruptible: true', $yaml);
        $this->assertStringNotContainsString('interruptible', $deploy);
    }

    public function test_artifact_is_published_with_an_expiry(): void
    {
        $this->assertStringContainsString("artifacts:\n    paths:\n      - slipway-build.tar.gz\n    expire_in: 1 week", $this->yaml());
    }

    public function test_failure_notifications_run_in_after_script(): void
    {
        $this->assertStringContainsString('if [ "$CI_JOB_STATUS" = "failed" ]; then', $this->yaml());
    }

    public function test_tag_rules_use_regular_expressions(): void
    {
        $this->assertStringContainsString('$CI_COMMIT_TAG =~ /^v.*$/', $this->yaml());
    }

    public function test_reserved_gitlab_keywords_cannot_be_stage_names(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('reserved GitLab keyword');

        $this->yaml(Fixture::minimal(['drivers' => ['gitlab' => true], 'stages' => ['cache' => ['pest']]]));
    }

    public function test_runner_tags_are_applied_to_every_job(): void
    {
        $yaml = $this->yaml(Fixture::minimal(['drivers' => ['gitlab' => ['options' => ['tags' => ['docker']]]]]));

        $this->assertStringContainsString("tags:\n    - docker", $yaml);
    }

    public function test_job_timeouts_are_expressed_in_minutes(): void
    {
        $this->assertStringContainsString('timeout: 20m', $this->yaml());
    }

    public function test_multiline_blocks_fail_fast(): void
    {
        $this->assertStringContainsString("- |\n      set -e\n", $this->yaml());
    }
}
