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

    public function test_an_unknown_default_stage_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stage [deploy]');

        $this->script($this->bitbucketConfig(), 'bitbucket', ['defaultStage' => 'deploy']);
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
