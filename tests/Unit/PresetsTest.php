<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Presets;
use Usamamuneerchaudhary\Slipway\Tests\Support\Fixture;

final class PresetsTest extends TestCase
{
    private function load(string $php): array
    {
        $file = tempnam(sys_get_temp_dir(), 'slipway-preset');
        file_put_contents($file, $php);

        try {
            return require $file;
        } finally {
            unlink($file);
        }
    }

    public function test_every_preset_compiles_for_every_provider(): void
    {
        foreach (Presets::names() as $preset) {
            $config = $this->load(Presets::render($preset, ['github', 'bitbucket', 'gitlab', 'circleci']));

            $result = Fixture::compile($config);

            $this->assertSame(['github', 'bitbucket', 'gitlab', 'circleci'], array_keys($result->files), $preset);
        }
    }

    public function test_the_published_config_file_compiles(): void
    {
        $config = require __DIR__ . '/../../config/slipway.php';

        $this->assertSame(['github'], array_keys(Fixture::compile($config)->files));
    }

    public function test_the_published_config_matches_the_app_preset(): void
    {
        $this->assertSame(Presets::render('app', ['github']), file_get_contents(__DIR__ . '/../../config/slipway.php'));
    }

    public function test_the_deploy_preset_satisfies_its_own_policies_and_includes_a_full_chain(): void
    {
        $config = $this->load(Presets::render('deploy', ['github']));
        $result = Fixture::compile($config);
        $ids = array_map(static fn ($j): string => $j->id, $result->pipeline->jobs);

        $this->assertContains('deploy-staging', $ids);
        $this->assertContains('deploy-production', $ids);
        $this->assertNotEmpty($config['policies']);
    }

    public function test_the_package_preset_runs_through_testbench_without_a_laravel_app(): void
    {
        $config = $this->load(Presets::render('package', ['github']));
        $yaml = Fixture::compile($config)->files['github']->contents;

        $this->assertStringContainsString('vendor/bin/testbench slipway:check', $yaml);
        $this->assertStringNotContainsString('key:generate', $yaml);
        $this->assertStringNotContainsString('php artisan', $yaml);
        $this->assertStringNotContainsString('8.2', $yaml);
        $this->assertStringContainsString('8.3', $yaml);
        $this->assertStringContainsString('8.4', $yaml);
    }

    public function test_a_custom_branch_is_the_push_trigger_and_the_deploy_branch(): void
    {
        $app = $this->load(Presets::render('app', ['github'], branch: 'develop'));
        $this->assertSame(['develop'], $app['triggers']['push']);

        $deploy = $this->load(Presets::render('deploy', ['github'], branch: 'master'));
        $this->assertSame(['master'], $deploy['triggers']['push']);
        $this->assertSame('master', $deploy['environments']['staging']['branch']);

        $yaml = Fixture::compile($deploy)->files['github']->contents;
        $this->assertStringContainsString('- master', $yaml);
        $this->assertStringNotContainsString("- main\n", $yaml);
    }

    public function test_a_branch_that_would_break_out_of_the_generated_php_is_rejected(): void
    {
        foreach (["main'; phpinfo();//", 'feature/../main', 'feature//wip', '-develop', 'develop.lock', ''] as $branch) {
            try {
                Presets::render('app', ['github'], branch: $branch);
                $this->fail("expected [{$branch}] to be rejected");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('is not a branch name', $e->getMessage());
            }
        }

        $this->assertSame('release/1.9', Presets::branch('  release/1.9  '));
    }

    public function test_drivers_are_listed_with_the_requested_ones_enabled(): void
    {
        $php = Presets::render('app', ['gitlab']);

        $this->assertStringContainsString("'gitlab' => ['enabled' => true]", $php);
        $this->assertStringContainsString("'github' => ['enabled' => false]", $php);
    }

    public function test_targets(): void
    {
        $this->assertSame('config/slipway.php', Presets::target('app'));
        $this->assertSame('config/slipway.php', Presets::target('deploy'));
        $this->assertSame('slipway.php', Presets::target('package'));
    }

    public function test_unknown_presets_and_drivers_are_rejected(): void
    {
        foreach ([fn () => Presets::render('nope', ['github']), fn () => Presets::render('app', ['jenkins']), fn () => Presets::target('nope')] as $call) {
            try {
                $call();
                $this->fail('expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_presets_never_contain_credential_values(): void
    {
        $scanner = new \Usamamuneerchaudhary\Slipway\Security\SecretScanner();

        foreach (Presets::names() as $preset) {
            $this->assertSame([], $scanner->scanString(Presets::render($preset, ['github']), $preset), $preset);
        }
    }
}
