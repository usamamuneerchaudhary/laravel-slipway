<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit\Deployers;

use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Deployers\DeployScript;
use Usamamuneerchaudhary\Slipway\Deployers\SshDeployer;
use Usamamuneerchaudhary\Slipway\Model\Environment;
use Usamamuneerchaudhary\Slipway\Tests\Support\ShellHarness;

/**
 * Executes the generated SSH deploy script against a simulated server and checks the
 * behaviours that matter in production: atomic switch, correct rollback target, safe pruning.
 */
final class SshDeployScriptTest extends TestCase
{
    private ShellHarness $h;
    private string $script;
    private string $base;

    protected function setUp(): void
    {
        if (ShellHarness::shell() === null) {
            $this->markTestSkipped('No POSIX shell available.');
        }

        $this->h = new ShellHarness();
        $this->base = $this->h->root . '/srv/app';
        mkdir($this->base . '/shared', 0775, true);

        $env = new Environment(
            name: 'staging',
            branch: 'main',
            tag: null,
            after: null,
            approval: false,
            deployer: 'ssh',
            verify: ['url' => null, 'url_secret' => 'STAGING_URL', 'path' => '/up', 'expect' => 200, 'retries' => 2, 'delay' => 1],
            rollback: true,
            options: ['host' => 'srv.example.com', 'user' => 'deploy', 'path' => $this->base, 'keep_releases' => 2, 'post_deploy' => ['echo POST-DEPLOY-RAN']],
        );

        $this->script = DeployScript::compose($env, new SshDeployer());
    }

    protected function tearDown(): void
    {
        $this->h->cleanup();
    }

    /** @return array{code:int,out:string} */
    private function deploy(int $http = 200): array
    {
        file_put_contents($this->h->work . '/artisan', "<?php\n");
        $result = $this->h->run($this->script, [
            'MOCK_HTTP' => (string) $http,
            'SSH_PRIVATE_KEY' => 'fake-key',
            'SSH_KNOWN_HOSTS' => 'fake-hosts',
            'STAGING_URL' => 'https://staging.example.test',
        ]);
        sleep(1); // releases are named by UTC second; keep them distinct

        return $result;
    }

    private function current(): ?string
    {
        if (! is_link($this->base . '/current')) {
            return null;
        }

        $link = readlink($this->base . '/current');

        return $link === false ? null : basename($link);
    }

    public function test_the_script_is_valid_posix_sh(): void
    {
        $file = $this->h->root . '/syntax.sh';
        file_put_contents($file, $this->script);
        exec(escapeshellarg(ShellHarness::shell() ?? 'sh') . ' -n ' . escapeshellarg($file) . ' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
    }

    public function test_it_fails_early_and_clearly_without_a_shared_env_file(): void
    {
        $result = $this->deploy();

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('shared/.env', $result['out']);
        $this->assertNull($this->current(), 'nothing may go live without a .env');
    }

    public function test_a_healthy_first_deploy_goes_live_and_prepares_shared_storage(): void
    {
        touch($this->base . '/shared/.env');

        $result = $this->deploy();

        $this->assertSame(0, $result['code'], $result['out']);
        $this->assertNotNull($this->current());
        $this->assertTrue(is_dir($this->base . '/shared/storage/framework/views'));
        $this->assertStringContainsString('POST-DEPLOY-RAN', $result['out']);
        $this->assertStringContainsString('php artisan migrate --force', $this->h->calls());
        $this->assertStringContainsString('php artisan optimize', $this->h->calls());
    }

    public function test_the_new_release_links_shared_env_and_storage(): void
    {
        touch($this->base . '/shared/.env');
        $this->deploy();

        $release = $this->base . '/releases/' . $this->current();
        $this->assertTrue(is_link($release . '/.env'));
        $this->assertTrue(is_link($release . '/storage'));
    }

    public function test_a_failed_verification_rolls_back_to_the_release_that_was_live(): void
    {
        touch($this->base . '/shared/.env');
        $this->deploy();
        $this->deploy();
        $good = $this->current();

        $result = $this->deploy(500);

        $this->assertSame(1, $result['code'], 'a failed verification must fail the job');
        $this->assertSame($good, $this->current());
        $this->assertStringContainsString('Rolled back to', $result['out']);
    }

    public function test_repeated_failures_never_roll_back_to_a_broken_release(): void
    {
        touch($this->base . '/shared/.env');
        $this->deploy();
        $this->deploy();
        $good = $this->current();

        $this->deploy(500);
        $this->deploy(500);
        $this->deploy(500);

        $this->assertSame($good, $this->current());
    }

    public function test_pruning_never_removes_the_live_release_even_after_rollbacks(): void
    {
        touch($this->base . '/shared/.env');
        $this->deploy();
        $this->deploy();
        $good = $this->current();

        $this->deploy(500);
        $this->deploy(500);

        $this->assertTrue(is_dir($this->base . '/releases/' . $good), 'the live release was deleted');
    }

    public function test_a_failing_first_deploy_reports_that_there_is_nothing_to_roll_back_to(): void
    {
        touch($this->base . '/shared/.env');

        $result = $this->deploy(500);

        $this->assertSame(1, $result['code']);
        $this->assertStringContainsString('No previous release to roll back to', $result['out']);
    }

    public function test_verification_polls_until_the_endpoint_recovers(): void
    {
        touch($this->base . '/shared/.env');
        $this->h->tool('curl', "#!/bin/sh\nn=\$(cat \"\$MOCK_LOG.n\" 2>/dev/null || echo 0)\nn=\$((n + 1))\necho \$n > \"\$MOCK_LOG.n\"\n[ \"\$n\" -ge 2 ] && printf 200 || printf 503\n");

        $result = $this->deploy();

        $this->assertSame(0, $result['code'], $result['out']);
        $this->assertStringContainsString('got HTTP 503', $result['out']);
        $this->assertStringContainsString('Verified:', $result['out']);
    }

    public function test_host_keys_are_verified_and_secrets_are_read_from_the_environment(): void
    {
        $this->assertStringContainsString('StrictHostKeyChecking=yes', $this->script);
        $this->assertStringContainsString('"$SSH_PRIVATE_KEY"', $this->script);
        $this->assertStringNotContainsString('StrictHostKeyChecking=no', $this->script);
    }
}
