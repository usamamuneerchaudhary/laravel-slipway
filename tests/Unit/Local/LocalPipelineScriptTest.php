<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit\Local;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Config\Schema;
use Usamamuneerchaudhary\Slipway\Local\LocalPipelineScript;
use Usamamuneerchaudhary\Slipway\Tests\Support\Fixture;

final class LocalPipelineScriptTest extends TestCase
{
    public function test_a_custom_bootstrap_is_embedded_and_catalog_services_are_started(): void
    {
        $script = $this->script($this->bitbucketConfig());

        $this->assertStringContainsString('echo custom-bootstrap', $script);
        $this->assertStringContainsString('vendor/bin/pest', $script);
        $this->assertStringContainsString('php artisan migrate', $script);
        $this->assertStringContainsString('did not start in time', $script);
        $this->assertStringContainsString('busybox:1.36', $script);
        $this->assertStringContainsString('--network container:"$PAUSE_NAME"', $script);
        $this->assertStringContainsString('export DB_HOST=\'127.0.0.1\'', $script);
    }

    public function test_a_raw_mysql_image_override_is_what_docker_run_uses(): void
    {
        $script = $this->script($this->bitbucketConfig());

        $this->assertStringContainsString('docker run -d --name "${RUN_ID}-mysql"', $script);
        $this->assertStringContainsString("'mysql:8.0'", $script);
        $this->assertStringNotContainsString("'mysql:8.4'", $script);
    }

    public function test_secret_names_are_loaded_at_runtime_and_values_are_never_written(): void
    {
        $script = $this->script($this->bitbucketConfig());

        $this->assertStringContainsString('load_secrets \'DB_SEEDER_HOST\'', $script);
        $this->assertStringContainsString('--env-file "$SECRETS_ENV"', $script);
        $this->assertStringNotContainsString('export DB_SEEDER_HOST=', $script);
        $this->assertStringNotContainsString('super-secret', $script);
    }

    public function test_environment_values_are_shell_quoted(): void
    {
        $script = $this->script($this->bitbucketConfig());

        $this->assertStringContainsString("export NOTE='it'\\''s quoted'", $script);
    }

    public function test_deploy_jobs_are_omitted_unless_requested(): void
    {
        $config = Fixture::full(['drivers' => ['github' => true]]);

        $script = $this->script($config, 'github');
        $this->assertStringNotContainsString('staging.example.com', $script);

        $withDeploy = $this->script($config, 'github', ['includeDeploy' => true]);
        $this->assertStringContainsString('staging.example.com', $withDeploy);
    }

    public function test_an_empty_bootstrap_skips_extension_install_and_still_copies_the_repo(): void
    {
        $script = $this->script(Fixture::minimal([
            'drivers' => ['bitbucket' => [
                'enabled' => true,
                'options' => ['image' => 'registry.example.com/php:{php}', 'bootstrap' => []],
            ]],
        ]), 'bitbucket');

        $this->assertStringNotContainsString('docker-php-ext-install', $script);
        $this->assertStringContainsString('apt-get install -y -qq --no-install-recommends rsync', $script);
        $this->assertStringContainsString('rsync -a --exclude .git', $script);
        $this->assertStringContainsString('"registry.example.com/php:${PHP_VERSION}"', $script);
    }

    public function test_gitlab_joins_a_user_defined_network_and_uses_the_service_hostname(): void
    {
        $script = $this->script(Fixture::minimal([
            'drivers' => ['gitlab' => true],
            'stages' => ['test' => ['services' => ['mysql'], 'steps' => ['pest']]],
        ]), 'gitlab');

        $this->assertStringContainsString('docker network create "$NETWORK_NAME"', $script);
        $this->assertStringContainsString('--network-alias \'mysql\'', $script);
        $this->assertStringContainsString('export DB_HOST=\'mysql\'', $script);
        $this->assertStringNotContainsString('busybox:1.36', $script);
    }

    public function test_github_raw_service_image_overrides_are_honoured(): void
    {
        $script = $this->script(Fixture::minimal([
            'drivers' => ['github' => [
                'enabled' => true,
                'raw' => ['jobs' => ['test' => ['services' => ['mysql' => ['image' => 'mysql:8.0']]]]],
            ]],
            'stages' => ['test' => ['services' => ['mysql'], 'steps' => ['pest']]],
        ]), 'github');

        $this->assertStringContainsString("'mysql:8.0'", $script);
        $this->assertStringContainsString('setup-php', $script);
        $this->assertStringContainsString('php-cli container instead', $script);
    }

    public function test_the_generated_script_is_valid_bash(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'slipway-ci-local');
        file_put_contents($file, $this->script($this->bitbucketConfig()));
        exec('bash -n ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        unlink($file);

        $this->assertSame(0, $code, implode("\n", $output));
    }

    public function test_a_dirty_working_tree_is_warned_about_and_does_not_fail_the_script(): void
    {
        $script = $this->script($this->bitbucketConfig());

        $this->assertStringContainsString('status --porcelain', $script);
        $this->assertStringContainsString('uncommitted or untracked files', $script);
        $this->assertStringContainsString('Pass --clean to run against HEAD', $script);
        $this->assertLessThan(strpos($script, 'if ! docker info'), strpos($script, 'status --porcelain'));
    }

    public function test_clean_uses_a_detached_worktree_and_keeps_secrets_on_the_project_root(): void
    {
        $script = $this->script($this->bitbucketConfig());

        $this->assertStringContainsString('--clean', $script);
        $this->assertStringContainsString('worktree add --detach', $script);
        $this->assertStringContainsString('worktree remove --force', $script);
        $this->assertStringContainsString('worktree prune', $script);
        $this->assertStringContainsString('-v "$SOURCE":/src:ro', $script);
        $this->assertStringContainsString('grep -E "^${name}=" "$ROOT/.env"', $script);
        $this->assertStringContainsString('this directory has no git HEAD', $script);
        $this->assertStringContainsString('Running against detached HEAD', $script);
    }

    public function test_clean_prunes_a_worktree_when_remove_fails_and_the_directory_is_deleted(): void
    {
        $git = trim((string) shell_exec('command -v git'));
        if ($git === '' || ! is_executable($git)) {
            $this->markTestSkipped('git is not available.');
        }

        $root = Fixture::tempDir();
        $bin = Fixture::tempDir();
        $worktree = $root . '-wt';
        $job = tempnam(sys_get_temp_dir(), 'slipway-job');
        $secrets = tempnam(sys_get_temp_dir(), 'slipway-secrets');

        try {
            $this->git($git, $root, 'init -q');
            $this->git($git, $root, 'config user.email test@example.com');
            $this->git($git, $root, 'config user.name Slipway');
            file_put_contents($root . '/README.md', "x\n");
            $this->git($git, $root, 'add README.md');
            $this->git($git, $root, '-c commit.gpgsign=false commit -qm init');
            $this->git($git, $root, 'worktree add --detach ' . escapeshellarg($worktree) . ' HEAD');
            $this->assertDirectoryExists($worktree);

            file_put_contents($bin . '/git', "#!/bin/sh\n"
                . 'if [ "$1" = "-C" ] && [ "$3" = "worktree" ] && [ "$4" = "remove" ]; then exit 1; fi' . "\n"
                . 'exec ' . escapeshellarg($git) . ' "$@"' . "\n");
            chmod($bin . '/git', 0755);

            $body = $this->cleanupBody($this->script($this->bitbucketConfig()));
            $wrapper = 'set -u' . "\n"
                . 'ROOT=' . escapeshellarg($root) . "\n"
                . 'WORKTREE=' . escapeshellarg($worktree) . "\n"
                . 'JOB_SCRIPT=' . escapeshellarg((string) $job) . "\n"
                . 'SECRETS_ENV=' . escapeshellarg((string) $secrets) . "\n"
                . 'export PATH=' . escapeshellarg($bin) . ':"$PATH"' . "\n"
                . "reset_runtime() { :; }\n"
                . "cleanup() {\n" . $body . "}\n"
                . "cleanup\n";

            $file = tempnam(sys_get_temp_dir(), 'slipway-cleanup');
            file_put_contents((string) $file, $wrapper);
            exec('bash ' . escapeshellarg((string) $file) . ' 2>&1', $output, $code);
            unlink((string) $file);

            $this->assertSame(0, $code, implode("\n", $output));
            $this->assertDirectoryDoesNotExist($worktree);
            $this->assertStringNotContainsString($worktree, $this->git($git, $root, 'worktree list --porcelain'));
        } finally {
            exec(escapeshellarg($git) . ' -C ' . escapeshellarg($root) . ' worktree prune >/dev/null 2>&1');
            Fixture::remove($worktree);
            Fixture::remove($root);
            Fixture::remove($bin);
            if (is_string($job) && is_file($job)) {
                unlink($job);
            }
            if (is_string($secrets) && is_file($secrets)) {
                unlink($secrets);
            }
        }
    }

    public function test_an_unknown_default_stage_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stage [deploy]');

        $this->script($this->bitbucketConfig(), 'bitbucket', ['defaultStage' => 'deploy']);
    }

    private function cleanupBody(string $script): string
    {
        $this->assertSame(1, preg_match('/^cleanup\(\) \{\n(?<body>(?:  .*\n)*)\}/m', $script, $match));

        return $match['body'];
    }

    private function git(string $git, string $repo, string $args): string
    {
        exec(escapeshellarg($git) . ' -C ' . escapeshellarg($repo) . ' ' . $args . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));

        return implode("\n", $output);
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array{defaultStage?:string,defaultPhp?:string,includeDeploy?:bool}  $settings
     */
    private function script(array $config, string $driver = 'bitbucket', array $settings = []): string
    {
        $result = Fixture::compile($config);
        $normalized = (new Schema)->normalize($config);

        return (new LocalPipelineScript)->generate(
            $result->pipeline,
            $driver,
            $normalized['drivers'][$driver],
            $settings,
        );
    }

    /** @return array<string,mixed> */
    private function bitbucketConfig(): array
    {
        return Fixture::minimal([
            'drivers' => ['bitbucket' => [
                'enabled' => true,
                'options' => ['bootstrap' => ['echo custom-bootstrap']],
                'raw' => ['definitions' => ['services' => ['mysql' => ['image' => 'mysql:8.0']]]],
            ]],
            'env' => ['NOTE' => "it's quoted"],
            'stages' => [
                'quality' => ['pint'],
                'test' => [
                    'services' => ['mysql'],
                    'steps' => [
                        [
                            'name' => 'Migrate',
                            'run' => 'php artisan migrate',
                            'secrets' => ['DB_SEEDER_HOST'],
                        ],
                        'pest',
                    ],
                ],
            ],
            'secrets' => ['DB_SEEDER_HOST'],
        ]);
    }
}
