<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use Usamamuneerchaudhary\Slipway\DriverManager;
use Usamamuneerchaudhary\Slipway\SlipwayServiceProvider;
use Usamamuneerchaudhary\Slipway\Support\ActionPins;
use Usamamuneerchaudhary\Slipway\Tests\Support\Fixture;

/**
 * Laravel integration tests (require orchestra/testbench).
 * The framework-free core is covered by tests/Unit and tests/Conformance.
 */
final class CommandsTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function getPackageProviders($app): array
    {
        return [SlipwayServiceProvider::class];
    }

    /**
     * These tests create and delete files under the application's base path. That must be Testbench's
     * throw-away skeleton, never this package's own checkout (whose .github/ holds the real workflow).
     */
    private function basePathIsDisposable(): bool
    {
        $base = realpath($this->app->basePath());

        return $base !== false && $base !== realpath(dirname(__DIR__, 2));
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->basePathIsDisposable()) {
            $this->markTestSkipped('The application base path is the package root; refusing to touch it.');
        }

        foreach (['config/slipway.php', '.github', 'bitbucket-pipelines.yml', '.gitlab-ci.yml'] as $path) {
            Fixture::remove($this->app->basePath($path));
        }
    }

    protected function tearDown(): void
    {
        // tearDown still runs after a skip in setUp(), so it needs the same guard.
        if ($this->app !== null && $this->basePathIsDisposable()) {
            foreach (['config/slipway.php', '.github', 'bitbucket-pipelines.yml', '.gitlab-ci.yml'] as $path) {
                Fixture::remove($this->app->basePath($path));
            }
        }
        foreach ($this->cleanup as $dir) {
            Fixture::remove($dir);
        }

        parent::tearDown();
    }

    public function test_the_service_provider_registers_every_command_and_the_container_bindings(): void
    {
        $registered = array_keys(Artisan::all());

        foreach (['init', 'compile', 'check', 'validate', 'explain', 'drivers', 'scan', 'verify', 'pin'] as $name) {
            $this->assertContains("slipway:{$name}", $registered);
        }

        $this->assertInstanceOf(DriverManager::class, $this->app->make(DriverManager::class));
        $this->assertSame($this->app->make('slipway.steps'), $this->app->make('slipway.steps'), 'registries are shared singletons');
    }

    public function test_init_writes_the_config_and_refuses_to_overwrite_it(): void
    {
        $this->artisan('slipway:init', ['--preset' => 'app', '--driver' => ['github']])->assertExitCode(0);
        $this->assertFileExists($this->app->basePath('config/slipway.php'));

        $this->artisan('slipway:init')->assertExitCode(1);
        $this->artisan('slipway:init', ['--force' => true])->assertExitCode(0);
    }

    public function test_init_rejects_an_unknown_preset(): void
    {
        $this->artisan('slipway:init', ['--preset' => 'nope'])->assertExitCode(1);
    }

    public function test_compile_writes_the_pipeline_files(): void
    {
        config(['slipway' => Fixture::minimal(['drivers' => ['github' => true, 'gitlab' => true]])]);

        $this->artisan('slipway:compile')->assertExitCode(0);

        $this->assertFileExists($this->app->basePath('.github/workflows/slipway.yml'));
        $this->assertFileExists($this->app->basePath('.gitlab-ci.yml'));
    }

    public function test_compile_dry_run_writes_nothing(): void
    {
        config(['slipway' => Fixture::minimal()]);

        $this->artisan('slipway:compile', ['--dry-run' => true])->assertExitCode(0);

        $this->assertFileDoesNotExist($this->app->basePath('.github/workflows/slipway.yml'));
    }

    public function test_check_is_the_drift_guard(): void
    {
        config(['slipway' => Fixture::minimal()]);

        $this->artisan('slipway:check')->assertExitCode(1);          // nothing generated yet
        $this->artisan('slipway:compile')->assertExitCode(0);
        $this->artisan('slipway:check')->assertExitCode(0);          // up to date

        file_put_contents($this->app->basePath('.github/workflows/slipway.yml'), "# hand edit\n", FILE_APPEND);
        $this->artisan('slipway:check')->assertExitCode(1);          // tampered
    }

    public function test_invalid_configuration_is_reported_and_fails(): void
    {
        config(['slipway' => ['stages' => []]]);

        $this->artisan('slipway:validate')->expectsOutputToContain('stages: required')->assertExitCode(1);
        $this->artisan('slipway:compile')->assertExitCode(1);
    }

    public function test_validate_and_explain_succeed_for_a_valid_configuration(): void
    {
        config(['slipway' => Fixture::full()]);

        $this->artisan('slipway:validate')->assertExitCode(0);
        $this->artisan('slipway:explain')->expectsOutputToContain('Pipeline "Acme CI"')->assertExitCode(0);
    }

    public function test_drivers_lists_the_capability_matrix(): void
    {
        $this->artisan('slipway:drivers')->assertExitCode(0);
    }

    public function test_scan_fails_when_a_credential_is_committed(): void
    {
        $dir = Fixture::tempDir();
        $this->cleanup[] = $dir;

        $this->artisan('slipway:scan', ['--path' => $dir])->assertExitCode(0);

        file_put_contents($dir . '/notes.txt', 'token ' . 'ghp_' . str_repeat('aB3dE6', 7) . "\n");
        $this->artisan('slipway:scan', ['--path' => $dir])->assertExitCode(1);
    }

    public function test_scan_groups_findings_by_file_and_summarises_the_run(): void
    {
        $dir = Fixture::tempDir();
        $this->cleanup[] = $dir;
        $token = 'ghp_' . str_repeat('aB3dE6', 7);

        file_put_contents($dir . '/a.txt', "ok\ntoken {$token}\n");
        file_put_contents($dir . '/.env', "APP_KEY=base64:abc\n");

        $exitCode = Artisan::call('slipway:scan', ['--path' => $dir]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);

        foreach (['FAIL', 'a.txt', 'line 2', 'GitHub token', 'Committed credentials file', '2 potential credentials in 2 files', '2 files scanned', 'How to fix'] as $expected) {
            $this->assertStringContainsString($expected, $output);
        }

        $this->assertStringNotContainsString($token, $output);
    }

    public function test_scan_reports_a_clean_run_with_the_number_of_files_scanned(): void
    {
        $dir = Fixture::tempDir();
        $this->cleanup[] = $dir;
        file_put_contents($dir . '/readme.txt', "hello\n");

        $exitCode = Artisan::call('slipway:scan', ['--path' => $dir]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('PASS', $output);
        $this->assertStringContainsString('No credentials found', $output);
        $this->assertStringContainsString('1 file scanned', $output);
    }

    public function test_scan_changed_requires_git_and_base_requires_changed(): void
    {
        $dir = Fixture::tempDir();
        $this->cleanup[] = $dir;

        $this->artisan('slipway:scan', ['--path' => $dir, '--changed' => true])
            ->expectsOutputToContain('git repository')
            ->assertExitCode(1);

        $this->artisan('slipway:scan', ['--path' => $dir, '--base' => 'main'])
            ->expectsOutputToContain('--changed')
            ->assertExitCode(1);
    }

    public function test_scan_changed_only_looks_at_changed_files(): void
    {
        $dir = Fixture::tempDir();
        $this->cleanup[] = $dir;
        exec('git -C ' . escapeshellarg($dir) . ' init -q 2>&1', $output, $code);

        if ($code !== 0) {
            $this->markTestSkipped('git is not available.');
        }

        file_put_contents($dir . '/notes.txt', 'token ' . 'ghp_' . str_repeat('aB3dE6', 7) . "\n");

        $this->artisan('slipway:scan', ['--path' => $dir, '--changed' => true])->assertExitCode(1);
    }

    public function test_pin_prints_the_commit_shas_for_every_unpinned_action(): void
    {
        $sha = str_repeat('a1', 20);
        $this->app->instance(ActionPins::class, new ActionPins(static fn (string $action, string $ref): ?string => $sha));
        config(['slipway' => Fixture::minimal()]);

        $this->artisan('slipway:pin')
            ->expectsOutputToContain("'actions/checkout' => '{$sha}', // v4")
            ->assertExitCode(0);
    }

    public function test_pin_reports_actions_that_cannot_be_resolved(): void
    {
        $this->app->instance(ActionPins::class, new ActionPins(static fn (string $action, string $ref): ?string => null));
        config(['slipway' => Fixture::minimal()]);

        $this->artisan('slipway:pin')->expectsOutputToContain('Could not resolve')->assertExitCode(1);
    }

    public function test_pin_needs_the_github_driver(): void
    {
        config(['slipway' => Fixture::minimal(['drivers' => ['github' => false, 'gitlab' => true]])]);

        $this->artisan('slipway:pin')->expectsOutputToContain('GitHub driver is not enabled')->assertExitCode(1);
    }

    public function test_pin_has_nothing_to_do_when_every_action_is_already_pinned(): void
    {
        $sha = str_repeat('b2', 20);
        $pins = ['actions/checkout' => $sha, 'actions/cache' => $sha, 'shivammathur/setup-php' => $sha, 'actions/setup-node' => $sha, 'actions/upload-artifact' => $sha, 'actions/download-artifact' => $sha];
        config(['slipway' => Fixture::minimal(['drivers' => ['github' => ['options' => ['pins' => $pins]]]])]);

        $this->artisan('slipway:pin')->expectsOutputToContain('already pinned')->assertExitCode(0);
    }

    public function test_verify_reports_unknown_environments(): void
    {
        config(['slipway' => Fixture::full()]);

        $this->artisan('slipway:verify', ['environment' => 'nope'])->assertExitCode(1);
    }

    public function test_commands_explain_how_to_get_started_when_there_is_no_configuration(): void
    {
        $empty = Fixture::tempDir();
        $this->cleanup[] = $empty;
        $previous = getcwd();
        chdir($empty);

        try {
            config(['slipway' => null]);
            $this->artisan('slipway:compile')->expectsOutputToContain('slipway:init')->assertExitCode(1);
        } finally {
            chdir($previous);
        }
    }
}
