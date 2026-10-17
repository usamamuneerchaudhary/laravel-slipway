<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Config\ConfigException;
use Usamamuneerchaudhary\Slipway\Model\Feature;
use Usamamuneerchaudhary\Slipway\Model\Job;
use Usamamuneerchaudhary\Slipway\Model\Pipeline;
use Usamamuneerchaudhary\Slipway\Tests\Support\Fixture;

final class PipelineBuilderTest extends TestCase
{
    private function pipeline(array $config): Pipeline
    {
        return Fixture::compile($config)->pipeline;
    }

    /** @return array<string,Job> */
    private function jobsById(Pipeline $p): array
    {
        $out = [];
        foreach ($p->jobs as $job) {
            $out[$job->id] = $job;
        }

        return $out;
    }

    private function assertCompileFails(string $needle, array $config): void
    {
        try {
            Fixture::compile($config);
        } catch (ConfigException $e) {
            $this->assertStringContainsString($needle, implode("\n", $e->errors));

            return;
        }

        $this->fail('Expected the configuration to be rejected.');
    }

    public function test_stages_run_in_order_and_each_waits_for_the_previous(): void
    {
        $jobs = $this->jobsById($this->pipeline(Fixture::full()));

        $this->assertSame([], $jobs['quality']->needs);
        $this->assertSame(['quality', 'slipway-guard'], $jobs['test']->needs);
        $this->assertSame(['test'], $jobs['build']->needs);
        $this->assertSame(['build'], $jobs['deploy-staging']->needs);
        $this->assertSame(['deploy-staging'], $jobs['deploy-production']->needs);
    }

    public function test_the_drift_guard_runs_alongside_the_first_stage(): void
    {
        $jobs = $this->jobsById($this->pipeline(Fixture::minimal()));

        $this->assertArrayHasKey('slipway-guard', $jobs);
        $this->assertSame('test', $jobs['slipway-guard']->stage);
        $this->assertSame([], $jobs['slipway-guard']->needs);
    }

    public function test_the_drift_guard_can_be_disabled(): void
    {
        $this->assertArrayNotHasKey('slipway-guard', $this->jobsById($this->pipeline(Fixture::minimal(['guard' => false]))));
    }

    public function test_the_drift_guard_uses_the_configured_artisan_command(): void
    {
        $jobs = $this->jobsById($this->pipeline(Fixture::minimal(['artisan' => 'vendor/bin/testbench'])));
        $commands = array_map(static fn ($s): ?string => $s->run, $jobs['slipway-guard']->steps);

        $this->assertContains('vendor/bin/testbench slipway:check --no-interaction', $commands);
    }

    public function test_artisan_token_is_replaced_in_built_in_steps(): void
    {
        $p = $this->pipeline(Fixture::minimal(['artisan' => 'vendor/bin/testbench', 'stages' => ['qa' => ['secret-scan'], 'test' => ['pest']]]));
        $all = implode("\n", array_map(static fn ($s): string => (string) $s->run, array_merge(...array_map(static fn (Job $j): array => $j->steps, $p->jobs))));

        $this->assertStringContainsString('vendor/bin/testbench slipway:scan', $all);
        $this->assertStringContainsString('vendor/bin/testbench key:generate', $all);
        $this->assertStringNotContainsString('__ARTISAN__', $all);
    }

    public function test_projects_with_a_lock_file_audit_it_and_key_their_cache_on_it(): void
    {
        $jobs = $this->jobsById($this->pipeline(Fixture::minimal(['stages' => ['qa' => ['composer-audit'], 'test' => ['pest']]])));
        $audit = (string) $jobs['qa']->steps[array_key_last($jobs['qa']->steps)]->run;

        $this->assertSame('composer audit --no-interaction --locked', $audit);
        $this->assertSame(['composer.lock'], $jobs['test']->caches[0]->keyFiles);
        $this->assertSame(['composer.lock'], $jobs['slipway-guard']->caches[0]->keyFiles);
    }

    public function test_libraries_without_a_lock_file_audit_what_was_installed_and_key_their_cache_on_composer_json(): void
    {
        $jobs = $this->jobsById($this->pipeline(Fixture::minimal(['lockfile' => false, 'stages' => ['qa' => ['composer-audit'], 'test' => ['pest']]])));
        $audit = (string) $jobs['qa']->steps[array_key_last($jobs['qa']->steps)]->run;

        $this->assertSame('composer audit --no-interaction', $audit);
        $this->assertSame(['composer.json'], $jobs['test']->caches[0]->keyFiles);
        $this->assertSame(['composer.json'], $jobs['slipway-guard']->caches[0]->keyFiles);
        $this->assertStringNotContainsString('__LOCKED__', implode("\n", array_map(static fn ($s): string => (string) $s->run, array_merge(...array_values(array_map(static fn (Job $j): array => $j->steps, $jobs))))));
    }

    public function test_lockfile_must_be_a_boolean(): void
    {
        $this->assertCompileFails('lockfile: must be true or false', Fixture::minimal(['lockfile' => 'no']));
    }

    public function test_matrix_stage_uses_all_php_versions_and_others_use_the_newest(): void
    {
        $jobs = $this->jobsById($this->pipeline(Fixture::full()));

        $this->assertSame(['8.2', '8.3'], $jobs['test']->phpVersions);
        $this->assertSame(['8.3'], $jobs['quality']->phpVersions);
        $this->assertTrue($jobs['test']->isMatrix());
        $this->assertFalse($jobs['quality']->isMatrix());
    }

    public function test_stage_php_option_overrides_the_default(): void
    {
        $jobs = $this->jobsById($this->pipeline(Fixture::minimal([
            'php' => ['8.2', '8.4'],
            'stages' => ['test' => ['php' => '8.2', 'steps' => ['pest']]],
        ])));

        $this->assertSame(['8.2'], $jobs['test']->phpVersions);
    }

    public function test_parallel_stage_creates_one_job_per_step(): void
    {
        $jobs = $this->jobsById($this->pipeline(Fixture::minimal(['stages' => [
            'quality' => ['parallel' => true, 'steps' => ['pint', 'larastan']],
            'test' => ['pest'],
        ]])));

        $this->assertArrayHasKey('quality-pint', $jobs);
        $this->assertArrayHasKey('quality-larastan', $jobs);
        $this->assertSame(['quality-pint', 'quality-larastan', 'slipway-guard'], $jobs['test']->needs);
    }

    public function test_services_add_connection_settings_to_the_job(): void
    {
        $jobs = $this->jobsById($this->pipeline(Fixture::full()));
        $names = array_map(static fn ($s): string => $s->name, $jobs['test']->services);

        $this->assertSame(['mysql', 'redis'], $names);
        $this->assertSame('testing', $jobs['test']->env['APP_ENV']);
    }

    public function test_unknown_service_is_rejected(): void
    {
        $this->assertCompileFails('unknown service [oracle]', Fixture::minimal(['stages' => ['test' => ['pest' => ['services' => ['oracle']]]]]));
    }

    public function test_caches_are_keyed_per_dependency_file(): void
    {
        $jobs = $this->jobsById($this->pipeline(Fixture::full()));
        $names = array_map(static fn ($c): string => $c->name, $jobs['build']->caches);

        $this->assertSame(['composer-nodev', 'node'], $names);
        $this->assertSame([], $this->jobsById($this->pipeline(Fixture::minimal(['cache' => false])))['test']->caches);
    }

    public function test_artifact_stage_publishes_one_tarball_and_uses_production_dependencies(): void
    {
        $build = $this->jobsById($this->pipeline(Fixture::full()))['build'];
        $commands = implode("\n", array_map(static fn ($s): string => (string) $s->run, $build->steps));

        $this->assertSame(['slipway-build.tar.gz'], $build->artifactPaths);
        $this->assertStringContainsString('--no-dev', $commands);
        $this->assertStringContainsString('tar -czf slipway-build.tar.gz', $commands);
    }

    public function test_artifact_job_does_not_run_on_pull_requests(): void
    {
        $build = $this->jobsById($this->pipeline(Fixture::full()))['build'];

        $this->assertNotContains('pull_request', $build->events);
        $this->assertContains('push', $build->events);
        $this->assertContains('tag', $build->events);
    }

    public function test_deploy_jobs_download_and_unpack_the_artifact(): void
    {
        $deploy = $this->jobsById($this->pipeline(Fixture::full()))['deploy-staging'];
        $names = array_map(static fn ($s): string => $s->name, $deploy->steps);

        $this->assertTrue($deploy->consumesArtifact);
        $this->assertSame(['Download build artifact', 'Unpack build artifact', 'Deploy to staging'], array_slice($names, 0, 3));
    }

    public function test_ssh_environment_without_an_artifact_stage_is_rejected(): void
    {
        $config = Fixture::full();
        unset($config['stages']['build']);

        $this->assertCompileFails('no stage publishes one', $config);
    }

    public function test_environment_chain_and_approval(): void
    {
        $jobs = $this->jobsById($this->pipeline(Fixture::full()));

        $this->assertFalse($jobs['deploy-staging']->manual);
        $this->assertTrue($jobs['deploy-production']->manual);
        $this->assertSame('production', $jobs['deploy-production']->environment);
        $this->assertSame(['push'], $jobs['deploy-staging']->events);
        $this->assertSame(['main'], $jobs['deploy-staging']->branches);
        $this->assertSame(['main'], $jobs['deploy-production']->branches, 'an "after" environment inherits its parent triggers');
    }

    public function test_rollback_requires_verification(): void
    {
        $config = Fixture::full();
        unset($config['environments']['staging']['verify']);
        $config['policies'] = [];

        $this->assertCompileFails('"verify" must be configured too', $config);
    }

    public function test_rollback_requires_a_deployer_that_supports_it(): void
    {
        $config = Fixture::full();
        $config['environments']['staging'] = [
            'branch' => 'main', 'via' => 'forge', 'rollback' => true,
            'verify' => ['url_secret' => 'STAGING_URL'],
        ];
        $config['secrets'][] = 'FORGE_DEPLOY_URL';

        $this->assertCompileFails('cannot roll back automatically', $config);
    }

    public function test_unknown_step_and_deployer_list_what_is_available(): void
    {
        $this->assertCompileFails('Unknown step [nope]. Known aliases:', Fixture::minimal(['stages' => ['qa' => ['nope']]]));
        $this->assertCompileFails('Unknown deployer [ftp]', Fixture::minimal(['environments' => ['s' => ['branch' => 'main', 'via' => 'ftp']]]));
    }

    public function test_step_option_errors_are_reported_with_their_config_path(): void
    {
        $this->assertCompileFails('stages.qa.larastan: unknown option [lvl]', Fixture::minimal(['stages' => ['qa' => ['larastan' => ['lvl' => 5]]]]));
    }

    public function test_node_steps_require_a_node_version(): void
    {
        $this->assertCompileFails('"node" is not set', Fixture::minimal(['node' => null, 'stages' => ['build' => ['build-assets']]]));
    }

    public function test_bun_steps_require_a_bun_version(): void
    {
        $this->assertCompileFails('"bun" is not set', Fixture::minimal(['stages' => ['build' => ['build-assets' => ['preset' => 'bun']]]]));
    }

    public function test_a_bun_build_records_the_runtime_on_the_job(): void
    {
        $jobs = $this->jobsById($this->pipeline(Fixture::minimal([
            'bun' => '1.2.21',
            'stages' => ['build' => ['build-assets' => ['preset' => 'bun']]],
        ])));

        $this->assertSame('1.2.21', $jobs['build']->bun);
        $this->assertNull($jobs['build']->node);
    }

    public function test_stage_events_must_be_enabled_triggers(): void
    {
        $this->assertCompileFails('event [schedule] is not enabled', Fixture::minimal(['stages' => ['test' => ['on' => ['schedule'], 'steps' => ['pest']]]]));
    }

    public function test_pipeline_needs_at_least_one_trigger(): void
    {
        $this->assertCompileFails('nothing can start this pipeline', Fixture::minimal(['triggers' => ['push' => false, 'pull_request' => false]]));
    }

    public function test_notifications_add_failure_steps_everywhere_and_success_only_at_the_end(): void
    {
        $jobs = $this->jobsById($this->pipeline(Fixture::full()));

        $failure = static fn (Job $j): int => count(array_filter($j->steps, static fn ($s): bool => $s->runOn === 'failure'));
        $success = static fn (Job $j): int => count(array_filter($j->steps, static fn ($s): bool => str_contains($s->name, '(success)')));

        $this->assertSame(1, $failure($jobs['quality']));
        $this->assertSame(1, $failure($jobs['deploy-production']));
        $this->assertSame(0, $success($jobs['quality']));
        $this->assertSame(1, $success($jobs['deploy-production']));
    }

    public function test_features_used_are_detected_for_capability_checks(): void
    {
        $features = array_map(static fn (Feature $f): string => $f->value, $this->pipeline(Fixture::full())->features());

        foreach (['matrix', 'services', 'approvals', 'environments', 'artifacts', 'schedule', 'tag-triggers', 'path-filters', 'concurrency', 'manual-trigger'] as $expected) {
            $this->assertContains($expected, $features);
        }

        $simple = array_map(static fn (Feature $f): string => $f->value, $this->pipeline(Fixture::minimal())->features());
        $this->assertNotContains('services', $simple);
        $this->assertNotContains('approvals', $simple);
    }

    public function test_jobs_for_filters_by_event_and_branch(): void
    {
        $p = $this->pipeline(Fixture::full());
        $ids = static fn (array $jobs): array => array_map(static fn (Job $j): string => $j->id, $jobs);

        $this->assertNotContains('deploy-staging', $ids($p->jobsFor('pull_request')));
        $this->assertContains('deploy-staging', $ids($p->jobsFor('push', 'main')));
        $this->assertNotContains('deploy-staging', $ids($p->jobsFor('push', 'feature/x')));
        $this->assertNotContains('deploy-staging', $ids($p->jobsFor('tag')));
    }

    public function test_referenced_secrets_include_deployers_verification_and_notifications(): void
    {
        $secrets = $this->pipeline(Fixture::full())->referencedSecrets();

        foreach (['SSH_PRIVATE_KEY', 'SSH_KNOWN_HOSTS', 'STAGING_URL', 'SLACK_WEBHOOK'] as $name) {
            $this->assertContains($name, $secrets);
        }
    }
}
