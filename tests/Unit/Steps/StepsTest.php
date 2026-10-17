<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit\Steps;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Model\Contribution;
use Usamamuneerchaudhary\Slipway\Model\Step;
use Usamamuneerchaudhary\Slipway\Model\StepType;
use Usamamuneerchaudhary\Slipway\Steps\BuildArtifact;
use Usamamuneerchaudhary\Slipway\Steps\BuildAssets;
use Usamamuneerchaudhary\Slipway\Steps\ComposerAudit;
use Usamamuneerchaudhary\Slipway\Steps\ComposerValidate;
use Usamamuneerchaudhary\Slipway\Steps\Infection;
use Usamamuneerchaudhary\Slipway\Steps\Larastan;
use Usamamuneerchaudhary\Slipway\Steps\NpmAudit;
use Usamamuneerchaudhary\Slipway\Steps\NpmTest;
use Usamamuneerchaudhary\Slipway\Steps\Pest;
use Usamamuneerchaudhary\Slipway\Steps\PhpUnit;
use Usamamuneerchaudhary\Slipway\Steps\Pint;
use Usamamuneerchaudhary\Slipway\Steps\Rector;
use Usamamuneerchaudhary\Slipway\Steps\SecretScan;
use Usamamuneerchaudhary\Slipway\Steps\Sentry;
use Usamamuneerchaudhary\Slipway\Tests\Support\Fixture;
use Usamamuneerchaudhary\Slipway\Tests\Support\ShellHarness;

final class StepsTest extends TestCase
{
    private function commands(Contribution $c): string
    {
        return implode("\n", array_map(static fn ($s): string => (string) $s->run, $c->steps));
    }

    private function assertRejects(string $needle, callable $call): void
    {
        try {
            $call();
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return;
        }

        $this->fail('expected InvalidArgumentException');
    }

    public function test_simple_steps_take_no_options(): void
    {
        foreach ([new Pint(), new ComposerAudit(), new SecretScan()] as $step) {
            $this->assertCount(1, $step->contribute([])->steps);
            $this->assertRejects('unknown option [x]', static fn () => $step->contribute(['x' => 1]));
        }
    }

    public function test_pint_checks_without_modifying_files(): void
    {
        $this->assertSame('vendor/bin/pint --test', $this->commands((new Pint())->contribute([])));
    }

    public function test_composer_audit_defers_the_locked_flag_to_the_lockfile_setting(): void
    {
        $this->assertSame('composer audit --no-interaction' . Step::LOCKED, $this->commands((new ComposerAudit())->contribute([])));
    }

    public function test_larastan_level_and_memory(): void
    {
        $cmd = $this->commands((new Larastan())->contribute(['level' => 6, 'memory_limit' => '2G']));

        $this->assertStringContainsString('--level=6', $cmd);
        $this->assertStringContainsString('--memory-limit=2G', $cmd);
        $this->assertStringContainsString('--level=max', $this->commands((new Larastan())->contribute(['level' => 'max'])));
        $this->assertStringNotContainsString('--level', $this->commands((new Larastan())->contribute([])));
    }

    public function test_larastan_rejects_unsafe_values(): void
    {
        $this->assertRejects('level', static fn () => (new Larastan())->contribute(['level' => '9; id']));
        $this->assertRejects('memory_limit', static fn () => (new Larastan())->contribute(['memory_limit' => '1G; id']));
    }

    public function test_pest_options(): void
    {
        $cmd = $this->commands((new Pest())->contribute(['parallel' => true, 'coverage' => 80, 'testsuite' => 'Unit']));

        $this->assertStringContainsString('vendor/bin/pest --parallel --coverage --min=80 --testsuite=Unit', $cmd);
    }

    public function test_pest_tia_does_not_change_the_test_command(): void
    {
        $c = (new Pest())->contribute(['tia' => true, 'parallel' => true]);

        $this->assertSame(['parallel' => true], $c->tia);
        $this->assertStringContainsString('vendor/bin/pest --parallel', $this->commands($c));
        $this->assertStringNotContainsString('--tia', $this->commands($c));
        $this->assertNull((new Pest())->contribute([])->tia);
        $this->assertNull((new Pest())->contribute(['tia' => false])->tia);
        $this->assertSame(['parallel' => false], (new Pest())->contribute(['tia' => true])->tia);
        $this->assertSame(['php' => '8.4', 'parallel' => false], (new Pest())->contribute(['tia' => ['php' => '8.4', 'parallel' => false]])->tia);
    }

    public function test_pest_tia_options_are_validated(): void
    {
        $this->assertRejects('tia.php', static fn () => (new Pest())->contribute(['tia' => ['php' => 'latest']]));
        $this->assertRejects('tia.schedule', static fn () => (new Pest())->contribute(['tia' => ['schedule' => 'nightly']]));
        $this->assertRejects('tia.coverage', static fn () => (new Pest())->contribute(['tia' => ['coverage' => 'none']]));
        $this->assertRejects('tia.filtered', static fn () => (new Pest())->contribute(['tia' => ['filtered' => true]]));
    }

    public function test_pest_prepares_the_laravel_environment_unless_disabled(): void
    {
        $this->assertStringContainsString('cp .env.example .env', $this->commands((new Pest())->contribute([])));
        $this->assertStringNotContainsString('.env.example', $this->commands((new Pest())->contribute(['prepare_env' => false])));
    }

    public function test_test_steps_request_services_and_a_testing_environment(): void
    {
        $c = (new Pest())->contribute(['services' => ['mysql', 'redis']]);

        $this->assertSame(['mysql', 'redis'], $c->services);
        $this->assertSame('testing', $c->env['APP_ENV']);
    }

    public function test_coverage_requests_a_coverage_driver(): void
    {
        $this->assertSame('pcov', (new Pest())->contribute(['coverage' => true])->coverage);
        $this->assertNull((new Pest())->contribute([])->coverage);
    }

    public function test_unknown_services_and_bad_coverage_are_rejected(): void
    {
        $this->assertRejects('unknown service [oracle]', static fn () => (new Pest())->contribute(['services' => ['oracle']]));
        $this->assertRejects('coverage', static fn () => (new Pest())->contribute(['coverage' => 150]));
        $this->assertRejects('testsuite', static fn () => (new Pest())->contribute(['testsuite' => 'a;b']));
    }

    public function test_phpunit_cannot_run_in_parallel_or_enforce_minimum_coverage(): void
    {
        $this->assertStringContainsString('vendor/bin/phpunit', $this->commands((new PhpUnit())->contribute([])));
        $this->assertStringContainsString('--coverage-text', $this->commands((new PhpUnit())->contribute(['coverage' => true])));
        $this->assertRejects('parallel', static fn () => (new PhpUnit())->contribute(['parallel' => true]));
        $this->assertRejects('minimum coverage', static fn () => (new PhpUnit())->contribute(['coverage' => 80]));
    }

    public function test_option_types_are_enforced(): void
    {
        $this->assertRejects('must be of type bool', static fn () => (new Pest())->contribute(['parallel' => 'yes']));
        $this->assertRejects('must be of type list', static fn () => (new Pest())->contribute(['services' => 'mysql']));
    }

    public function test_npm_steps_require_node(): void
    {
        $this->assertTrue((new NpmAudit())->contribute([])->requiresNode);
        $this->assertTrue((new BuildAssets())->contribute([])->requiresNode);
        $this->assertStringContainsString('npm ci --no-audit --no-fund', $this->commands((new BuildAssets())->contribute([])));
        $this->assertStringContainsString('npm run build', $this->commands((new BuildAssets())->contribute([])));
    }

    public function test_build_assets_vite_and_bun_presets(): void
    {
        $vite = (new BuildAssets())->contribute(['preset' => 'vite']);
        $this->assertTrue($vite->requiresNode);
        $this->assertFalse($vite->requiresBun);
        $this->assertStringContainsString('npx vite build', $this->commands($vite));
        $this->assertStringContainsString('npm run assets', $this->commands((new BuildAssets())->contribute(['preset' => 'vite', 'script' => 'assets'])));

        $bun = (new BuildAssets())->contribute(['preset' => 'bun']);
        $this->assertTrue($bun->requiresBun);
        $this->assertFalse($bun->requiresNode);
        $this->assertStringContainsString('bun install --frozen-lockfile', $this->commands($bun));
        $this->assertStringContainsString('bun run build', $this->commands($bun));
        $this->assertRejects('preset', static fn () => (new BuildAssets())->contribute(['preset' => 'yarn']));
    }

    public function test_sentry_uploads_source_maps_with_a_named_secret(): void
    {
        $c = (new Sentry())->contribute(['org' => 'acme', 'project' => 'web']);

        $this->assertSame(['SENTRY_AUTH_TOKEN'], $c->steps[0]->secrets);
        $this->assertSame('acme', $c->steps[0]->env['SENTRY_ORG']);
        $this->assertSame('web', $c->steps[0]->env['SENTRY_PROJECT']);
        $this->assertStringContainsString('sourcemaps inject --release "$SENTRY_RELEASE" public/build', $this->commands($c));
        $this->assertStringContainsString('sourcemaps upload --release "$SENTRY_RELEASE" public/build', $this->commands($c));
        $this->assertStringContainsString('GITHUB_SHA', $this->commands($c));
        $this->assertStringContainsString('CI_COMMIT_SHA', $this->commands($c));
        $this->assertStringContainsString('BITBUCKET_COMMIT', $this->commands($c));
        $this->assertStringContainsString('CIRCLE_SHA1', $this->commands($c));
        $this->assertStringContainsString('git rev-parse HEAD', $this->commands($c));
        $this->assertStringContainsString('Add @sentry/cli to the lockfile', $this->commands($c));
        $this->assertSame(['SENTRY_TOKEN'], (new Sentry())->contribute(['org' => 'acme', 'project' => 'web', 'token' => 'SENTRY_TOKEN'])->steps[0]->secrets);
        $this->assertRejects('org', static fn () => (new Sentry())->contribute(['project' => 'web']));
        $this->assertRejects('token', static fn () => (new Sentry())->contribute(['org' => 'acme', 'project' => 'web', 'token' => 'not-a-secret']));
        $this->assertRejects('path', static fn () => (new Sentry())->contribute(['org' => 'acme', 'project' => 'web', 'path' => 'dist; rm -rf /']));
    }

    public function test_npm_audit_level_is_validated(): void
    {
        $this->assertStringContainsString('--audit-level=critical', $this->commands((new NpmAudit())->contribute(['level' => 'critical'])));
        $this->assertRejects('level', static fn () => (new NpmAudit())->contribute(['level' => 'severe']));
    }

    public function test_composer_validate_is_strict_unless_told_otherwise(): void
    {
        $this->assertSame('composer validate --no-interaction --strict', $this->commands((new ComposerValidate())->contribute([])));
        $this->assertSame('composer validate --no-interaction', $this->commands((new ComposerValidate())->contribute(['strict' => false])));
        $this->assertRejects('unknown option [x]', static fn () => (new ComposerValidate())->contribute(['x' => 1]));
        $this->assertRejects('must be of type bool', static fn () => (new ComposerValidate())->contribute(['strict' => 'yes']));
    }

    public function test_npm_test_runs_the_test_script_by_default_and_requires_node(): void
    {
        $c = (new NpmTest())->contribute([]);

        $this->assertTrue($c->requiresNode);
        $this->assertStringContainsString('npm ci --no-audit --no-fund', $this->commands($c));
        $this->assertStringContainsString("\nnpm test", "\n" . $this->commands($c));
        $this->assertStringContainsString('npm run test:unit', $this->commands((new NpmTest())->contribute(['script' => 'test:unit'])));
    }

    public function test_npm_test_script_must_be_a_plain_script_name(): void
    {
        $this->assertRejects('script', static fn () => (new NpmTest())->contribute(['script' => 'test && curl evil']));
        $this->assertRejects('script', static fn () => (new NpmTest())->contribute(['script' => '--help']));
    }

    public function test_rector_only_ever_dry_runs(): void
    {
        $cmd = $this->commands((new Rector())->contribute([]));

        $this->assertStringContainsString('vendor/bin/rector process --dry-run', $cmd);
        $this->assertStringContainsString('--config=ci/rector.php', $this->commands((new Rector())->contribute(['config' => 'ci/rector.php'])));
    }

    public function test_rector_config_must_be_a_relative_path_inside_the_project(): void
    {
        $this->assertRejects('config', static fn () => (new Rector())->contribute(['config' => '/etc/rector.php']));
        $this->assertRejects('config', static fn () => (new Rector())->contribute(['config' => '../rector.php']));
        $this->assertRejects('config', static fn () => (new Rector())->contribute(['config' => 'rector.php; id']));
    }

    public function test_infection_always_requests_pcov_and_uses_ci_flags(): void
    {
        $c = (new Infection())->contribute(['prepare_env' => false]);

        $this->assertSame('pcov', $c->coverage);
        $this->assertSame('testing', $c->env['APP_ENV']);
        $this->assertSame('vendor/bin/infection --no-progress --no-interaction', $this->commands($c));
    }

    public function test_infection_options_append_expected_flags(): void
    {
        $cmd = $this->commands((new Infection())->contribute([
            'min_msi' => 70,
            'min_covered_msi' => 80,
            'threads' => 'max',
            'config' => 'ci/infection.json',
            'only_covering_tests' => true,
            'prepare_env' => false,
        ]));

        $this->assertStringContainsString('--min-msi=70', $cmd);
        $this->assertStringContainsString('--min-covered-msi=80', $cmd);
        $this->assertStringContainsString('--threads=max', $cmd);
        $this->assertStringContainsString('--configuration=ci/infection.json', $cmd);
        $this->assertStringContainsString('--only-covering-test-cases', $cmd);
        $this->assertStringContainsString('--threads=4', $this->commands((new Infection())->contribute(['threads' => 4, 'prepare_env' => false])));
    }

    public function test_infection_git_diff_fetches_the_base_and_limits_mutants(): void
    {
        $origin = $this->commands((new Infection())->contribute(['git_diff' => 'origin/main', 'prepare_env' => false]));

        $this->assertStringContainsString('git fetch --depth=1 origin main', $origin);
        $this->assertStringContainsString('--git-diff-base=origin/main', $origin);
        $this->assertStringContainsString('--git-diff-filter=AM', $origin);
        $this->assertStringContainsString('--ignore-msi-with-no-mutations', $origin);
        $this->assertStringContainsString('git fetch --depth=1 origin main', $this->commands((new Infection())->contribute(['git_diff' => 'main', 'prepare_env' => false])));
    }

    public function test_infection_prepares_the_laravel_environment_unless_disabled(): void
    {
        $this->assertStringContainsString('cp .env.example .env', $this->commands((new Infection())->contribute([])));
        $this->assertStringNotContainsString('.env.example', $this->commands((new Infection())->contribute(['prepare_env' => false])));
    }

    public function test_infection_rejects_unknown_and_unsafe_options(): void
    {
        $this->assertRejects('unknown option [x]', static fn () => (new Infection())->contribute(['x' => 1]));
        $this->assertRejects('config', static fn () => (new Infection())->contribute(['config' => '/etc/infection.json']));
        $this->assertRejects('config', static fn () => (new Infection())->contribute(['config' => '../infection.json']));
        $this->assertRejects('git_diff', static fn () => (new Infection())->contribute(['git_diff' => 'origin/main; id']));
        $this->assertRejects('git_diff', static fn () => (new Infection())->contribute(['git_diff' => '../main']));
        $this->assertRejects('min_msi', static fn () => (new Infection())->contribute(['min_msi' => 150]));
        $this->assertRejects('min_covered_msi', static fn () => (new Infection())->contribute(['min_covered_msi' => -1]));
        $this->assertRejects('threads', static fn () => (new Infection())->contribute(['threads' => 0]));
        $this->assertRejects('threads', static fn () => (new Infection())->contribute(['threads' => 'auto']));
    }

    public function test_infection_requests_pcov_in_compiled_github_yaml(): void
    {
        $yaml = Fixture::compile(Fixture::minimal(['stages' => ['mutate' => ['infection']]]))->files['github']->contents;

        $this->assertStringContainsString('coverage: pcov', $yaml);
        $this->assertStringContainsString('vendor/bin/infection --no-progress --no-interaction', $yaml);
    }

    public function test_build_assets_script_name_must_be_a_single_word(): void
    {
        $this->assertStringContainsString('npm run production', $this->commands((new BuildAssets())->contribute(['script' => 'production'])));
        $this->assertRejects('script', static fn () => (new BuildAssets())->contribute(['script' => 'build && curl evil']));
    }

    public function test_build_artifact_contributes_a_single_tarball_and_production_dependencies(): void
    {
        $c = (new BuildArtifact())->contribute([]);

        $this->assertSame(['slipway-build.tar.gz'], $c->artifactPaths);
        $this->assertTrue($c->noDev);
        $this->assertSame(StepType::ArtifactUpload, $c->steps[count($c->steps) - 1]->type);
    }

    public function test_build_artifact_validates_paths(): void
    {
        foreach (['/etc', '../x', 'a b', 'a;b', 'src/*', "a'b"] as $path) {
            $this->assertRejects('plain relative path', static fn () => (new BuildArtifact())->contribute(['paths' => [$path]]));
        }
        $this->assertRejects('at least one', static fn () => (new BuildArtifact())->contribute(['paths' => []]));
    }

    public function test_the_pack_and_unpack_scripts_work_and_pack_only_existing_paths(): void
    {
        if (ShellHarness::shell() === null || ! is_executable('/usr/bin/tar') && ! is_executable('/bin/tar')) {
            $this->markTestSkipped('POSIX shell or tar unavailable.');
        }

        $h = new ShellHarness();

        try {
            mkdir($h->work . '/app', 0775, true);
            file_put_contents($h->work . '/app/a.php', "<?php\n");
            file_put_contents($h->work . '/artisan', "<?php\n");
            chmod($h->work . '/artisan', 0755);
            symlink('app/a.php', $h->work . '/link');

            $pack = (new BuildArtifact())->contribute(['paths' => ['app/', 'artisan', 'link', 'lang/', 'missing.json'], 'optimize' => false])->steps[0];
            $r = $h->run("set -e\n" . $pack->run . "\n");
            $this->assertSame(0, $r['code'], $r['out']);

            exec('tar -tzf ' . escapeshellarg($h->work . '/slipway-build.tar.gz'), $listing);
            sort($listing);
            $this->assertSame(['app/', 'app/a.php', 'artisan', 'link'], $listing, 'only existing paths are packed');

            // Unpack elsewhere and check that the executable bit and the symlink survive.
            $out = $h->root . '/deploy';
            mkdir($out);
            copy($h->work . '/slipway-build.tar.gz', $out . '/slipway-build.tar.gz');
            $process = proc_open(['sh', '-c', BuildArtifact::unpackCommand()], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $out);
            stream_get_contents($pipes[1]);
            $this->assertSame(0, proc_close($process));
            $this->assertTrue(is_executable($out . '/artisan'), 'permissions must survive');
            $this->assertTrue(is_link($out . '/link'), 'symlinks must survive');
            $this->assertFileDoesNotExist($out . '/slipway-build.tar.gz');
        } finally {
            $h->cleanup();
        }
    }

    public function test_packing_fails_loudly_when_nothing_exists(): void
    {
        if (ShellHarness::shell() === null) {
            $this->markTestSkipped('No POSIX shell available.');
        }

        $h = new ShellHarness();

        try {
            $pack = (new BuildArtifact())->contribute(['paths' => ['nothing/'], 'optimize' => false])->steps[0];
            $r = $h->run("set -e\n" . $pack->run . "\n");

            $this->assertSame(1, $r['code']);
            $this->assertStringContainsString('None of the configured artifact paths exist', $r['out']);
        } finally {
            $h->cleanup();
        }
    }

    public function test_steps_can_be_referenced_by_class_name_and_custom_steps_register_by_alias(): void
    {
        $custom = new class implements \Usamamuneerchaudhary\Slipway\Contracts\PipelineStep {
            public function contribute(array $options): Contribution
            {
                return Contribution::steps(\Usamamuneerchaudhary\Slipway\Model\Step::run('Custom check', 'echo custom ' . ($options['word'] ?? 'default')));
            }
        };

        $steps = \Usamamuneerchaudhary\Slipway\Slipway::steps();
        $steps->register('custom', static fn (): object => $custom);
        $compiler = \Usamamuneerchaudhary\Slipway\Slipway::compiler($steps);

        $viaAlias = $compiler->compile(Fixture::minimal(['stages' => ['qa' => ['custom' => ['word' => 'hello']]]]));
        $viaClass = Fixture::compile(Fixture::minimal(['stages' => ['qa' => [Pint::class]]]));

        $this->assertStringContainsString('echo custom hello', $viaAlias->files['github']->contents);
        $this->assertStringContainsString('vendor/bin/pint --test', $viaClass->files['github']->contents);
    }
}
