<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit\Deployers;

use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Config\ConfigException;
use Usamamuneerchaudhary\Slipway\Deployers\CommandDeployer;
use Usamamuneerchaudhary\Slipway\Deployers\EnvoyerDeployer;
use Usamamuneerchaudhary\Slipway\Deployers\ForgeDeployer;
use Usamamuneerchaudhary\Slipway\Deployers\SshDeployer;
use Usamamuneerchaudhary\Slipway\Deployers\VaporDeployer;
use Usamamuneerchaudhary\Slipway\Deployers\WebhookDeployer;
use Usamamuneerchaudhary\Slipway\Model\Environment;
use Usamamuneerchaudhary\Slipway\Tests\Support\Fixture;
use Usamamuneerchaudhary\Slipway\Tests\Support\ShellHarness;

final class DeployersTest extends TestCase
{
    private function env(string $via, array $options = [], bool $rollback = false, string $name = 'production'): Environment
    {
        return new Environment($name, 'main', null, null, false, $via, null, $rollback, $options);
    }

    private function ssh(array $options = []): array
    {
        return $options + ['host' => 'app.example.com', 'user' => 'deploy', 'path' => '/var/www/app'];
    }

    private function assertInvalid(string $needle, \Usamamuneerchaudhary\Slipway\Contracts\Deployer $d, Environment $env): void
    {
        $this->assertStringContainsString($needle, implode("\n", $d->validate($env)));
    }

    public function test_ssh_accepts_a_valid_configuration(): void
    {
        $this->assertSame([], (new SshDeployer())->validate($this->env('ssh', $this->ssh())));
    }

    public function test_ssh_validates_every_option(): void
    {
        $d = new SshDeployer();

        $this->assertInvalid('[host]', $d, $this->env('ssh', $this->ssh(['host' => 'a b; rm -rf /'])));
        $this->assertInvalid('[user]', $d, $this->env('ssh', $this->ssh(['user' => 'root; id'])));
        $this->assertInvalid('[path]', $d, $this->env('ssh', $this->ssh(['path' => 'relative'])));
        $this->assertInvalid('[path]', $d, $this->env('ssh', $this->ssh(['path' => '/var/www/../etc'])));
        $this->assertInvalid('[path]', $d, $this->env('ssh', $this->ssh(['path' => "/var/www/a'b"])));
        $this->assertInvalid('[port]', $d, $this->env('ssh', $this->ssh(['port' => 70000])));
        $this->assertInvalid('[keep_releases]', $d, $this->env('ssh', $this->ssh(['keep_releases' => 1])));
        $this->assertInvalid('[migrate]', $d, $this->env('ssh', $this->ssh(['migrate' => 'yes'])));
        $this->assertInvalid('unknown option [hots]', $d, $this->env('ssh', $this->ssh(['hots' => 'x'])));
        $this->assertInvalid('[key_secret]', $d, $this->env('ssh', $this->ssh(['key_secret' => 'my-key'])));
    }

    public function test_ssh_post_deploy_must_be_single_line_commands(): void
    {
        $d = new SshDeployer();

        $this->assertInvalid('post_deploy.0', $d, $this->env('ssh', $this->ssh(['post_deploy' => ["a\nb"]])));
        $this->assertInvalid('post_deploy.0', $d, $this->env('ssh', $this->ssh(['post_deploy' => ['x SLIPWAY_REMOTE_EOF']])));
        $this->assertSame([], $d->validate($this->env('ssh', $this->ssh(['post_deploy' => ['php artisan queue:restart']]))));
    }

    public function test_ssh_secrets_are_configurable_and_declared(): void
    {
        $d = new SshDeployer();

        $this->assertSame(['SSH_PRIVATE_KEY', 'SSH_KNOWN_HOSTS'], $d->requiredSecrets($this->env('ssh', $this->ssh())));
        $this->assertSame(['PROD_KEY', 'PROD_HOSTS'], $d->requiredSecrets($this->env('ssh', $this->ssh(['key_secret' => 'PROD_KEY', 'known_hosts_secret' => 'PROD_HOSTS']))));
    }

    public function test_ssh_can_skip_migrations_and_optimisation(): void
    {
        $script = (new SshDeployer())->deployCommands($this->env('ssh', $this->ssh(['migrate' => false, 'optimize' => false, 'storage_link' => false])));

        $this->assertStringNotContainsString('migrate', $script);
        $this->assertStringNotContainsString('artisan optimize', $script);
        $this->assertStringNotContainsString('storage:link', $script);
    }

    public function test_ssh_script_uses_the_configured_port(): void
    {
        $this->assertStringContainsString('-p 2222', (new SshDeployer())->deployCommands($this->env('ssh', $this->ssh(['port' => 2222]))));
    }

    public function test_forge_and_envoyer_call_a_secret_url(): void
    {
        $forge = (new ForgeDeployer())->deployCommands($this->env('forge'));
        $envoyer = (new EnvoyerDeployer())->deployCommands($this->env('envoyer'));

        $this->assertStringContainsString('"$FORGE_DEPLOY_URL"', $forge);
        $this->assertStringContainsString('"$ENVOYER_HOOK_URL"', $envoyer);
        $this->assertSame(['FORGE_DEPLOY_URL'], (new ForgeDeployer())->requiredSecrets($this->env('forge')));
        $this->assertFalse((new ForgeDeployer())->supportsRollback($this->env('forge')));
        $this->assertFalse((new ForgeDeployer())->usesArtifact($this->env('forge')));
    }

    public function test_forge_secret_names_are_validated(): void
    {
        $this->assertInvalid('UPPER_SNAKE_CASE', new ForgeDeployer(), $this->env('forge', ['secret' => 'https://forge.laravel.com/x']));
    }

    public function test_vapor_deploys_with_the_cli_and_can_roll_back(): void
    {
        $d = new VaporDeployer();
        $env = $this->env('vapor', [], true);

        $this->assertTrue($d->needsPhp($env));
        $this->assertStringContainsString("vendor/bin/vapor deploy 'production' --commit=\"\$SLIPWAY_SHA\"", $d->deployCommands($env));
        $this->assertStringContainsString("vendor/bin/vapor rollback 'production'", (string) $d->rollbackCommands($env));
        $this->assertTrue($d->supportsRollback($env));
    }

    public function test_vapor_environment_name_is_validated(): void
    {
        $this->assertInvalid('[environment]', new VaporDeployer(), $this->env('vapor', ['environment' => 'prod; id']));
    }

    public function test_webhook_supports_rollback_only_with_a_rollback_secret(): void
    {
        $d = new WebhookDeployer();

        $this->assertFalse($d->supportsRollback($this->env('webhook')));
        $this->assertTrue($d->supportsRollback($this->env('webhook', ['rollback_secret' => 'ROLLBACK_URL'])));
        $this->assertSame(['DEPLOY_WEBHOOK_URL', 'ROLLBACK_URL'], $d->requiredSecrets($this->env('webhook', ['rollback_secret' => 'ROLLBACK_URL'])));
    }

    public function test_webhook_payload_is_json_escaped_when_executed(): void
    {
        if (ShellHarness::shell() === null) {
            $this->markTestSkipped('No POSIX shell available.');
        }

        $h = new ShellHarness();
        $h->tool('curl', "#!/bin/sh\nfor a; do case \"\$a\" in @*) cat \"\${a#@}\" > \"\$MOCK_LOG.payload\";; esac; done\n");

        try {
            $script = (new WebhookDeployer())->deployCommands($this->env('webhook'));
            $result = $h->run("set -eu\n" . $script . "\n", ['SLIPWAY_REF' => 'feature/"quoted"\\branch', 'DEPLOY_WEBHOOK_URL' => 'https://hook.test/x']);

            $this->assertSame(0, $result['code'], $result['out']);
            $payload = (string) file_get_contents($h->log . '.payload');
            $decoded = json_decode($payload, true);
            $this->assertIsArray($decoded, 'payload must be valid JSON: ' . $payload);
            $this->assertSame('feature/"quoted"\\branch', $decoded['ref']);
            $this->assertSame('production', $decoded['environment']);
        } finally {
            $h->cleanup();
        }
    }

    public function test_command_deployer_requires_commands_and_validates_everything(): void
    {
        $d = new CommandDeployer();

        $this->assertInvalid('[commands] is required', $d, $this->env('command', []));
        $this->assertInvalid('[commands]', $d, $this->env('command', ['commands' => 'echo hi']));
        $this->assertInvalid('secrets.0', $d, $this->env('command', ['commands' => ['x'], 'secrets' => ['lower']]));
        $this->assertInvalid('tools.0', $d, $this->env('command', ['commands' => ['x'], 'tools' => ['wget']]));
        $this->assertInvalid('[php]', $d, $this->env('command', ['commands' => ['x'], 'php' => 'yes']));
        $this->assertSame([], $d->validate($this->env('command', ['commands' => ['echo hi'], 'tools' => ['curl'], 'secrets' => ['TOKEN']])));
    }

    public function test_command_deployer_flags_drive_the_job_setup(): void
    {
        $d = new CommandDeployer();
        $plain = $this->env('command', ['commands' => ['echo hi']]);
        $rich = $this->env('command', ['commands' => ['echo hi'], 'php' => true, 'artifact' => true, 'rollback_commands' => ['echo undo']]);

        $this->assertFalse($d->needsPhp($plain));
        $this->assertFalse($d->usesArtifact($plain));
        $this->assertTrue($d->needsPhp($rich));
        $this->assertTrue($d->usesArtifact($rich));
        $this->assertTrue($d->supportsRollback($rich));
        $this->assertSame('echo undo', $d->rollbackCommands($rich));
    }

    public function test_deployer_option_errors_surface_with_their_config_path(): void
    {
        $config = Fixture::minimal([
            'stages' => ['test' => ['pest'], 'build' => ['build-artifact']],
            'environments' => ['s' => ['branch' => 'main', 'via' => 'ssh', 'options' => ['host' => 'bad host']]],
            'secrets' => ['SSH_PRIVATE_KEY', 'SSH_KNOWN_HOSTS'],
        ]);

        try {
            Fixture::compile($config);
            $this->fail('expected a ConfigException');
        } catch (ConfigException $e) {
            $this->assertStringContainsString('environments.s.options: option [host]', implode("\n", $e->errors));
        }
    }

    public function test_every_deploy_script_is_valid_posix_sh(): void
    {
        $shell = ShellHarness::shell();
        if ($shell === null) {
            $this->markTestSkipped('No POSIX shell available.');
        }

        $envs = [
            new SshDeployer(), new ForgeDeployer(), new EnvoyerDeployer(), new VaporDeployer(), new WebhookDeployer(), new CommandDeployer(),
        ];
        $options = [
            SshDeployer::class => $this->ssh(),
            CommandDeployer::class => ['commands' => ['echo deploy'], 'rollback_commands' => ['echo undo']],
            WebhookDeployer::class => ['rollback_secret' => 'ROLLBACK_URL'],
        ];

        foreach ($envs as $deployer) {
            $env = $this->env('x', $options[$deployer::class] ?? [], true);
            $verify = ['url' => 'https://example.com', 'url_secret' => null, 'path' => '/up', 'expect' => 200, 'retries' => 3, 'delay' => 1];
            $env = new Environment($env->name, 'main', null, null, false, 'x', $verify, true, $env->options);

            $script = \Usamamuneerchaudhary\Slipway\Deployers\DeployScript::compose($env, $deployer);
            $file = sys_get_temp_dir() . '/slipway-syntax-' . bin2hex(random_bytes(4)) . '.sh';
            file_put_contents($file, $script);
            exec(escapeshellarg($shell) . ' -n ' . escapeshellarg($file) . ' 2>&1', $output, $code);
            unlink($file);

            $this->assertSame(0, $code, $deployer::class . ': ' . implode("\n", $output));
        }
    }
}
