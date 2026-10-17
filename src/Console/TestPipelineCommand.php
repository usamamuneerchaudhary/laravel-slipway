<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Console;

use InvalidArgumentException;
use Usamamuneerchaudhary\Slipway\Compiler;
use Usamamuneerchaudhary\Slipway\Config\Schema;
use Usamamuneerchaudhary\Slipway\DriverManager;
use Usamamuneerchaudhary\Slipway\Local\DockerDetector;
use Usamamuneerchaudhary\Slipway\Local\LocalPipelineScript;

final class TestPipelineCommand extends SlipwayCommand
{
    protected $signature = 'slipway:test-pipeline
        {--driver= : CI provider to emulate (github, bitbucket, gitlab, circleci)}
        {--stage= : Stage to run (the script still accepts a stage argument later)}
        {--php= : PHP version for the image tag}
        {--path=ci-local.sh : Where to write the script, relative to the project root}
        {--force : Overwrite an existing script}
        {--run : Write the script and run it}
        {--no-run : Write the script without running it}
        {--clean : Run against a detached worktree of HEAD}
        {--include-deploy : Include deploy jobs (they may SSH or call a host)}
        {--config= : Path to a configuration file (default: config/slipway.php)}';

    protected $description = 'Write a Docker script from the Slipway configuration and run a pipeline stage locally';

    public function handle(Compiler $compiler, DriverManager $drivers, DockerDetector $docker): int
    {
        $located = $this->locate();
        if ($located === null) {
            return self::FAILURE;
        }

        $result = $this->compile($compiler, $located);
        if ($result === null) {
            return self::FAILURE;
        }

        $normalized = (new Schema)->normalize($located['config']);
        $generator = new LocalPipelineScript;
        $includeDeploy = (bool) $this->option('include-deploy');

        $driver = $this->resolveDriver($normalized, $drivers);
        if ($driver === null) {
            return self::FAILURE;
        }

        $stages = $generator->runnableStageNames($result->pipeline, $includeDeploy);
        $stage = $this->resolveChoice(
            $this->option('stage'),
            $stages,
            in_array('test', $stages, true) ? 'test' : ($stages[0] ?? null),
            'Which stage should run?',
            'Pass --stage=... (available: ' . implode(', ', $stages) . ').',
        );
        if ($stage === null) {
            return self::FAILURE;
        }

        /** @var list<string> $phpVersions */
        $phpVersions = $normalized['php'];
        $php = $this->resolveChoice(
            $this->option('php'),
            $phpVersions,
            $phpVersions === [] ? null : $phpVersions[array_key_last($phpVersions)],
            'Which PHP version?',
            'Pass --php=... (available: ' . implode(', ', $phpVersions) . ').',
        );
        if ($php === null) {
            return self::FAILURE;
        }

        $path = $this->option('path');
        $path = is_string($path) && $path !== '' ? $path : 'ci-local.sh';
        $target = $this->resolvePath($located['root'], $path);
        if ($target === null) {
            return self::FAILURE;
        }

        if (is_file($target) && ! $this->option('force') && ! $this->input->isInteractive()) {
            $this->error($path . ' already exists. Use --force to overwrite it.');

            return self::FAILURE;
        }

        try {
            $script = $generator->generate(
                $result->pipeline,
                $driver,
                $normalized['drivers'][$driver],
                [
                    'defaultStage' => $stage,
                    'defaultPhp' => $php,
                    'includeDeploy' => $includeDeploy,
                ],
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dir = dirname($target);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Could not create directory [{$dir}].");

            return self::FAILURE;
        }

        if (file_put_contents($target, $script, LOCK_EX) === false) {
            $this->error("Could not write [{$path}].");

            return self::FAILURE;
        }

        chmod($target, 0755);

        $this->info("Wrote {$path}.");

        if ($driver === 'github') {
            $this->warn('This local run uses Docker (php-cli), not GitHub\'s setup-php or actions/checkout.');
        }

        if ($includeDeploy) {
            $this->warn('Deploy jobs can SSH or call a host. Review the generated script before running it.');
        }

        $wantRun = $this->wantsRun();
        $clean = (bool) $this->option('clean');
        $display = './' . ltrim($path, './') . ' ' . $stage . ' ' . $php . ($clean ? ' --clean' : '');

        if (! $wantRun) {
            $this->line('Run ' . $display);

            if (! $docker->installed()) {
                $this->warn('Docker is not installed. Install Docker Desktop (or the Docker CLI) to run it.');
            }

            return self::SUCCESS;
        }

        if (! $docker->installed()) {
            $this->error('Docker is not installed. Install Docker Desktop (or the Docker CLI) to run the pipeline locally.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Running ' . $display . ' ...');

        $command = 'bash ' . escapeshellarg($target) . ' ' . escapeshellarg($stage) . ' ' . escapeshellarg($php);
        if ($clean) {
            $command .= ' --clean';
        }

        passthru($command, $exitCode);

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param  array<string,mixed>  $normalized */
    private function resolveDriver(array $normalized, DriverManager $drivers): ?string
    {
        $configured = array_keys($normalized['drivers']);
        $listed = $configured;
        sort($listed);

        $requested = $this->option('driver');
        if (is_string($requested) && $requested !== '') {
            if (! isset($normalized['drivers'][$requested])) {
                $this->error("Driver [{$requested}] is not in the configuration. Configured: " . implode(', ', $listed) . '.');

                return null;
            }

            return $this->assertKnownDriver($requested, $drivers);
        }

        if (count($configured) === 1) {
            return $this->assertKnownDriver($configured[0], $drivers);
        }

        $default = $configured[0];
        foreach ($normalized['drivers'] as $name => $config) {
            if ($config['enabled']) {
                $default = $name;
                break;
            }
        }

        if ($this->input->isInteractive()) {
            $chosen = $this->choice('Which provider should this local run emulate?', $listed, $default);

            return $this->assertKnownDriver(is_string($chosen) ? $chosen : $default, $drivers);
        }

        $this->error('Pass --driver=... (configured: ' . implode(', ', $listed) . ').');

        return null;
    }

    private function assertKnownDriver(string $driver, DriverManager $drivers): ?string
    {
        if (! $drivers->has($driver)) {
            $this->error('Unknown driver [' . $driver . ']. Available: ' . implode(', ', $drivers->names()) . '.');

            return null;
        }

        return $driver;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function resolveChoice(mixed $requested, array $allowed, ?string $default, string $question, string $missingFlag): ?string
    {
        if ($allowed === []) {
            $this->error($missingFlag);

            return null;
        }

        if (is_string($requested) && $requested !== '') {
            if (! in_array($requested, $allowed, true)) {
                $this->error("[{$requested}] is not available. " . $missingFlag);

                return null;
            }

            return $requested;
        }

        if (count($allowed) === 1 || $default === null) {
            return $allowed[0];
        }

        if ($this->input->isInteractive()) {
            $chosen = $this->choice($question, $allowed, $default);

            return is_string($chosen) ? $chosen : $default;
        }

        return $default;
    }

    private function wantsRun(): bool
    {
        if ($this->option('no-run')) {
            return false;
        }

        return (bool) $this->option('run') || $this->input->isInteractive();
    }

    private function resolvePath(string $root, string $path): ?string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        if (preg_match('#(^|/)\.\.(/|$)#', $path) === 1 || str_contains($path, "\0")) {
            $this->error('The --path must be inside the project (no "..").');

            return null;
        }

        return rtrim($root, '/\\') . '/' . $path;
    }
}
