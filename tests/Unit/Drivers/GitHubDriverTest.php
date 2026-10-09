<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit\Drivers;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Config\ConfigException;
use Usamamuneerchaudhary\Slipway\Tests\Support\Fixture;

final class GitHubDriverTest extends TestCase
{
    private function yaml(array $config = null): string
    {
        $config ??= Fixture::full(['drivers' => ['github' => true]]);

        return Fixture::compile($config)->files['github']->contents;
    }

    public function test_permissions_default_to_read_only(): void
    {
        $this->assertStringContainsString("permissions:\n  contents: read\n", $this->yaml());
    }

    public function test_checkout_does_not_persist_credentials(): void
    {
        $this->assertStringContainsString('persist-credentials: false', $this->yaml());
    }

    public function test_actions_are_pinned_and_can_be_overridden_with_a_sha(): void
    {
        $sha = str_repeat('a', 40);
        $yaml = $this->yaml(Fixture::minimal(['drivers' => ['github' => ['options' => ['pins' => ['actions/checkout' => $sha]]]]]));

        $this->assertStringContainsString("actions/checkout@{$sha}", $yaml);
        $this->assertStringContainsString('shivammathur/setup-php@v2', $yaml);
    }

    public function test_pins_for_unknown_actions_are_rejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('pins');

        $this->yaml(Fixture::minimal(['drivers' => ['github' => ['options' => ['pins' => ['evil/action' => 'v1']]]]]));
    }

    public function test_no_workflow_expression_ever_appears_inside_a_run_script(): void
    {
        $yaml = $this->yaml();

        foreach (explode("\n", $yaml) as $i => $line) {
            if (preg_match('/^\s+run: /', $line) === 1) {
                $this->assertStringNotContainsString('${{', $line, "line {$i}");
            }
        }

        // Block scalars: collect each `run: |` body and make sure it has no expression.
        preg_match_all('/^(\s+)run: \|[+\-0-9]*\n((?:\1  .*\n|\n)+)/m', $yaml, $blocks);
        $this->assertNotEmpty($blocks[2]);
        foreach ($blocks[2] as $body) {
            $this->assertStringNotContainsString('${{', $body);
        }
    }

    public function test_commands_containing_expressions_are_rejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('expression');

        $this->yaml(Fixture::minimal(['stages' => ['qa' => [['run' => 'echo ${{ github.event.pull_request.title }}']]]]));
    }

    public function test_secrets_reach_steps_only_through_env(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString('SSH_PRIVATE_KEY: ${{ secrets.SSH_PRIVATE_KEY }}', $yaml);
        $this->assertStringContainsString('SLACK_WEBHOOK: ${{ secrets.SLACK_WEBHOOK }}', $yaml);
    }

    public function test_pull_requests_cancel_superseded_runs_but_deploys_do_not(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString("cancel-in-progress: \${{ github.event_name == 'pull_request' }}", $yaml);
        $this->assertStringContainsString("group: slipway-deploy-production\n      cancel-in-progress: false", $yaml);
    }

    public function test_cancellation_can_be_disabled(): void
    {
        $this->assertStringNotContainsString('concurrency:', $this->yaml(Fixture::minimal(['concurrency' => ['cancel_superseded' => false]])));
    }

    public function test_environments_map_to_github_environments(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString('environment: staging', $yaml);
        $this->assertStringContainsString('environment: production', $yaml);
    }

    public function test_matrix_job_lists_every_php_version_and_names_the_run(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString("matrix:\n        php:\n          - \"8.2\"\n          - \"8.3\"", $yaml);
        $this->assertStringContainsString('name: Test (PHP ${{ matrix.php }})', $yaml);
        $this->assertStringContainsString('php-version: ${{ matrix.php }}', $yaml);
    }

    public function test_services_use_health_checks_so_tests_wait_for_the_database(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString('--health-cmd=', $yaml);
        $this->assertStringContainsString('DB_HOST: 127.0.0.1', $yaml);
    }

    public function test_triggers_cover_push_tags_pull_requests_schedule_and_manual(): void
    {
        $yaml = $this->yaml();

        foreach (["push:\n    branches:\n      - main", "tags:\n      - v*", 'pull_request:', "cron: 0 3 * * 1", 'workflow_dispatch:'] as $needle) {
            $this->assertStringContainsString($needle, $yaml);
        }
    }

    public function test_deploy_jobs_only_run_for_their_branch(): void
    {
        $this->assertStringContainsString("if: (github.event_name == 'push' && github.ref_type == 'branch' && (github.ref_name == 'main'))", $this->yaml());
    }

    public function test_branch_globs_become_startswith_and_complex_globs_are_rejected(): void
    {
        $base = [
            'triggers' => ['push' => ['release/*']],
            'stages' => ['test' => ['pest'], 'build' => ['build-artifact']],
            'secrets' => ['SSH_PRIVATE_KEY', 'SSH_KNOWN_HOSTS'],
        ];
        $env = ['via' => 'ssh', 'options' => ['host' => 'a.test', 'user' => 'd', 'path' => '/srv/a']];

        $ok = $this->yaml(Fixture::minimal($base + ['environments' => ['s' => $env + ['branch' => 'release/*']]]));
        $this->assertStringContainsString("startsWith(github.ref_name, 'release/')", $ok);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('cannot be expressed as a job condition');
        $this->yaml(Fixture::minimal(['triggers' => ['push' => ['rel*ase/*']]] + $base + ['environments' => ['s' => $env + ['branch' => 'rel*ase/*']]]));
    }

    public function test_artifact_jobs_run_only_on_events_that_can_deploy(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString("if: (github.event_name == 'push' && github.ref_type == 'branch') || (github.ref_type == 'tag') || github.event_name == 'workflow_dispatch'", $yaml);
    }

    public function test_deploy_jobs_do_not_check_out_the_repository(): void
    {
        $yaml = $this->yaml();
        $deploy = substr($yaml, (int) strpos($yaml, "  deploy-staging:\n"));
        $deploy = substr($deploy, 0, (int) strpos($deploy, "  deploy-production:\n"));

        $this->assertStringNotContainsString('actions/checkout', $deploy);
        $this->assertStringContainsString('actions/download-artifact', $deploy);
    }

    public function test_runner_is_configurable(): void
    {
        $yaml = $this->yaml(Fixture::minimal(['drivers' => ['github' => ['options' => ['runner' => 'self-hosted']]]]));

        $this->assertStringContainsString('runs-on: self-hosted', $yaml);
    }

    public function test_unknown_driver_options_are_rejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('unknown option [runnr]');

        $this->yaml(Fixture::minimal(['drivers' => ['github' => ['options' => ['runnr' => 'x']]]]));
    }
}
