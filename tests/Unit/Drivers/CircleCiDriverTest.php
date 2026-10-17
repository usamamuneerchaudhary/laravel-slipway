<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit\Drivers;

use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Config\ConfigException;
use Usamamuneerchaudhary\Slipway\Tests\Support\Fixture;

final class CircleCiDriverTest extends TestCase
{
    /** @param array<string,mixed>|null $config */
    private function yaml(?array $config = null): string
    {
        $config ??= Fixture::full(['drivers' => ['circleci' => true]]);

        return Fixture::compile($config)->files['circleci']->contents;
    }

    /** @param array<string,mixed> $options */
    private function yamlWithOptions(array $options): string
    {
        return $this->yaml(Fixture::full(['drivers' => ['circleci' => ['options' => $options]]]));
    }

    private function section(string $yaml, string $from, string $to): string
    {
        $start = (int) strpos($yaml, $from);

        return substr($yaml, $start, (int) strpos($yaml, $to, $start) - $start);
    }

    public function test_it_writes_the_circleci_config_with_a_version_and_one_workflow(): void
    {
        $result = Fixture::compile(Fixture::full(['drivers' => ['circleci' => true]]));

        $this->assertSame('.circleci/config.yml', $result->files['circleci']->path);
        $this->assertStringContainsString("version: \"2.1\"\njobs:\n", $result->files['circleci']->contents);
        $this->assertStringContainsString("workflows:\n  acme-ci:\n", $result->files['circleci']->contents);
    }

    public function test_stages_become_requires_between_jobs(): void
    {
        $workflow = substr($this->yaml(), (int) strpos($this->yaml(), "workflows:\n"));

        $this->assertStringContainsString("      - quality\n      - slipway-guard\n      - test:\n          requires:\n            - quality\n            - slipway-guard\n", $workflow);
        $this->assertStringContainsString("      - build:\n          requires:\n            - test\n", $workflow);
    }

    public function test_the_workflow_runs_for_every_enabled_event_using_expression_filters(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString('pipeline.event.name == "pull_request"', $yaml);
        $this->assertStringContainsString('pipeline.event.name == "push" and (pipeline.git.branch == "main")', $yaml);
        $this->assertStringContainsString('pipeline.git.tag matches /^v.*$/', $yaml);
        $this->assertStringContainsString('pipeline.event.name == "schedule"', $yaml);
        $this->assertStringContainsString('pipeline.event.name == "api"', $yaml);
        $this->assertStringNotContainsString("branches:\n", $yaml, 'the deprecated branch/tag filter maps are not used');
    }

    public function test_a_job_narrower_than_the_workflow_gets_its_own_filter(): void
    {
        $yaml = $this->yaml();
        $build = $this->section($yaml, "      - build:\n", "      - deploy-staging:\n");
        $quality = $this->section($yaml, "      - quality\n", "      - test:\n");

        $this->assertStringContainsString('filters: ', $build);
        $this->assertStringNotContainsString('pipeline.event.name == "pull_request"', $build);
        $this->assertStringNotContainsString('filters:', $quality);
    }

    public function test_matrix_jobs_are_parameterised_and_expanded_by_the_workflow(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString("  test:\n    parameters:\n      php_version:\n        type: string\n", $yaml);
        $this->assertStringContainsString('image: php:<< parameters.php_version >>-cli', $yaml);
        $this->assertStringContainsString("matrix:\n            parameters:\n              php_version:\n                - \"8.2\"\n                - \"8.3\"", $yaml);
        $this->assertStringContainsString('v1-composer-php<< parameters.php_version >>-{{ checksum "composer.lock" }}', $yaml);
    }

    public function test_services_run_as_secondary_containers_reached_on_loopback(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString("      - image: mysql:8.4\n        environment:\n          MYSQL_ROOT_PASSWORD: password", $yaml);
        $this->assertStringContainsString("      - image: redis:7\n", $yaml);
        $this->assertStringContainsString('DB_HOST: 127.0.0.1', $yaml);
        $this->assertStringContainsString('REDIS_HOST: 127.0.0.1', $yaml);
        $this->assertStringNotContainsString('DB_HOST: localhost', $yaml, '"localhost" makes the MySQL client use a socket');
        $this->assertStringContainsString('did not start in time', $yaml);
    }

    public function test_git_and_ssh_are_installed_before_checkout(): void
    {
        $quality = $this->section($this->yaml(), "  quality:\n", "  slipway-guard:\n");

        $this->assertLessThan(strpos($quality, '- checkout'), strpos($quality, 'Install Git and SSH'));
    }

    public function test_the_manual_gate_is_a_separate_approval_job(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString("      - approve-deploy-production:\n          type: approval\n          requires:\n            - deploy-staging\n", $yaml);
        $this->assertStringContainsString("      - deploy-production:\n          requires:\n            - approve-deploy-production\n            - deploy-staging\n", $yaml);
        $this->assertStringNotContainsString("  approve-deploy-production:\n    docker:", $yaml, 'approval jobs only exist in the workflow');
    }

    public function test_deploys_are_serialised_per_environment(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString('serial-group: << pipeline.project.slug >>/staging', $yaml);
        $this->assertStringContainsString('serial-group: << pipeline.project.slug >>/production', $yaml);
        $this->assertStringNotContainsString('serial-group', $this->yamlWithOptions(['serial_deploys' => false]));
    }

    public function test_the_build_is_handed_to_deploys_through_a_workspace_instead_of_a_checkout(): void
    {
        $yaml = $this->yaml();
        $deploy = $this->section($yaml, "  deploy-production:\n", "workflows:\n");
        $build = $this->section($yaml, "  build:\n", "  deploy-staging:\n");

        $this->assertStringContainsString("- attach_workspace:\n          at: .", $deploy);
        $this->assertStringNotContainsString('- checkout', $deploy);
        $this->assertStringContainsString('- persist_to_workspace:', $build);
        $this->assertStringContainsString('root: .', $build);
        $this->assertStringNotContainsString('**', $build, 'workspace paths use Go filepath.Match, which has no "**"');
    }

    public function test_failure_and_always_steps_run_last_with_a_when_condition(): void
    {
        $quality = $this->section($this->yaml(), "  quality:\n", "  slipway-guard:\n");

        $this->assertStringContainsString('when: on_fail', $quality);
        $this->assertGreaterThan(strpos($quality, '- save_cache:'), strpos($quality, 'when: on_fail'));
    }

    public function test_commands_that_use_slipway_variables_define_them_from_circleci_variables(): void
    {
        $quality = $this->section($this->yaml(), "  quality:\n", "  slipway-guard:\n");

        $this->assertStringContainsString('export SLIPWAY_SHA="$CIRCLE_SHA1"', $quality);
        $this->assertStringContainsString('${CIRCLE_BRANCH:-${CIRCLE_TAG:-unknown}}', $quality);
        $this->assertStringContainsString('export SLIPWAY_RUN_URL="$CIRCLE_BUILD_URL"', $quality);
        $this->assertSame(1, substr_count($quality, 'export SLIPWAY_SHA='), 'only the notification step needs them');
    }

    public function test_timeouts_are_applied_as_no_output_timeouts(): void
    {
        $this->assertStringContainsString('no_output_timeout: 20m', $this->yaml());
    }

    public function test_secrets_are_never_written_into_the_file(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString('"$SSH_PRIVATE_KEY"', $yaml);
        $this->assertDoesNotMatchRegularExpression('/SSH_PRIVATE_KEY: \S/', $yaml);
    }

    public function test_contexts_apply_only_to_jobs_that_use_secrets(): void
    {
        $yaml = $this->yamlWithOptions(['context' => ['deploy-secrets']]);
        $workflow = substr($yaml, (int) strpos($yaml, "workflows:\n"));

        $this->assertStringContainsString("context:\n            - deploy-secrets", $this->section($workflow, "      - deploy-staging:\n", "      - approve-deploy-production:\n"));

        $withoutSecrets = $this->yaml(Fixture::minimal(['drivers' => ['circleci' => ['options' => ['context' => ['deploy-secrets']]]]]));
        $this->assertStringNotContainsString('context:', $withoutSecrets);
    }

    public function test_a_resource_class_and_image_can_be_configured(): void
    {
        $yaml = $this->yamlWithOptions(['resource_class' => 'large', 'image' => 'ghcr.io/acme/php:{php}']);

        $this->assertStringContainsString('resource_class: large', $yaml);
        $this->assertStringContainsString('image: ghcr.io/acme/php:8.3', $yaml);
    }

    public function test_the_bootstrap_can_be_replaced(): void
    {
        $yaml = $this->yamlWithOptions(['bootstrap' => ['echo custom-bootstrap']]);

        $this->assertStringContainsString('echo custom-bootstrap', $yaml);
        $this->assertStringNotContainsString('docker-php-ext-install', $yaml);
    }

    public function test_invalid_options_are_reported_as_configuration_errors(): void
    {
        foreach ([['context' => 5], ['serial_deploys' => 'yes'], ['resource_class' => ''], ['bootstrap' => 'x']] as $options) {
            try {
                $this->yamlWithOptions($options);
                $this->fail('Expected a ConfigException for ' . json_encode($options));
            } catch (ConfigException $exception) {
                $this->assertStringContainsString('circleci', implode("\n", $exception->errors));
            }
        }
    }

    public function test_tag_and_branch_globs_become_expression_regexes(): void
    {
        $config = Fixture::minimal(['drivers' => ['circleci' => true], 'triggers' => ['push' => ['main', 'release/*'], 'tags' => ['v1.*']]]);
        $yaml = $this->yaml($config);

        $this->assertStringContainsString('pipeline.git.branch == "main" or pipeline.git.branch matches /^release\/.*$/', $yaml);
        $this->assertStringContainsString('pipeline.git.tag matches /^v1\..*$/', $yaml);
    }

    public function test_it_lists_what_circleci_cannot_do_as_warnings(): void
    {
        $warnings = implode("\n", Fixture::compile(Fixture::full(['drivers' => ['circleci' => true]]))->warnings);

        $this->assertStringContainsString('CircleCI: Path filters is not supported', $warnings);
        $this->assertStringContainsString('CircleCI: Scheduled runs is only partially supported', $warnings);
    }

    public function test_bun_cache_key_checksums_only_bun_lock_when_bun_lockb_is_absent(): void
    {
        $root = Fixture::tempDir();
        file_put_contents($root . '/bun.lock', "{}\n");

        try {
            $result = Fixture::compile(Fixture::minimal([
                'drivers' => ['circleci' => true],
                'bun' => '1.2.21',
                'stages' => ['build' => ['build-assets' => ['preset' => 'bun']]],
            ]), $root);
        } finally {
            Fixture::remove($root);
        }

        $yaml = $result->files['circleci']->contents;
        $bun = null;
        foreach ($result->pipeline->job('build')->caches as $cache) {
            if ($cache->name === 'bun') {
                $bun = $cache;
            }
        }

        $this->assertNotNull($bun);
        $this->assertSame(['bun.lock'], $bun->keyFiles);
        $this->assertStringContainsString('{{ checksum "bun.lock" }}', $yaml);
        $this->assertStringNotContainsString('bun.lockb', $yaml);
    }

    public function test_bun_cache_key_checksums_only_bun_lockb_when_that_is_the_lockfile(): void
    {
        $root = Fixture::tempDir();
        file_put_contents($root . '/bun.lockb', 'binary');

        try {
            $yaml = Fixture::compile(Fixture::minimal([
                'drivers' => ['circleci' => true],
                'bun' => '1.2.21',
                'stages' => ['build' => ['build-assets' => ['preset' => 'bun']]],
            ]), $root)->files['circleci']->contents;
        } finally {
            Fixture::remove($root);
        }

        $this->assertStringContainsString('{{ checksum "bun.lockb" }}', $yaml);
        $this->assertDoesNotMatchRegularExpression('/checksum "bun\.lock"/', $yaml);
    }
}
