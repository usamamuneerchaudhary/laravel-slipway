<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Usamamuneerchaudhary\Slipway\DriverManager;
use Usamamuneerchaudhary\Slipway\Presets;

final class InitCommand extends Command
{
    protected $signature = 'slipway:init
        {--preset=app : Starter configuration: app, package or deploy}
        {--driver=* : Provider to enable (github, bitbucket, gitlab, circleci). Repeat for several}
        {--branch= : Branch pushes CI should run on, such as main, master, or develop}
        {--force : Overwrite an existing configuration file}';

    protected $description = 'Create the Slipway configuration file from a preset';

    public function handle(DriverManager $drivers): int
    {
        $preset = (string) $this->option('preset');
        $enabled = array_values(array_unique((array) $this->option('driver')));
        $enabled = $enabled === [] ? ['github'] : $enabled;

        try {
            Presets::assertReady($preset, $enabled, $drivers->names());
            $relative = Presets::target($preset);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $root = $preset === Presets::PACKAGE ? (getcwd() ?: base_path()) : base_path();
        $target = rtrim($root, '/\\') . '/' . $relative;

        if (is_file($target) && ! $this->option('force')) {
            $this->error("{$relative} already exists. Use --force to overwrite it.");

            return self::FAILURE;
        }

        $branch = $this->resolveBranch();
        if ($branch === null) {
            return self::FAILURE;
        }

        try {
            $contents = Presets::render($preset, $enabled, $drivers->names(), $branch);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dir = dirname($target);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Could not create {$dir}.");

            return self::FAILURE;
        }

        if (file_put_contents($target, $contents) === false) {
            $this->error("Could not write {$relative}.");

            return self::FAILURE;
        }

        $this->info("Created {$relative} (preset: {$preset}; providers: " . implode(', ', $enabled) . "; branch: {$branch}).");
        $this->newLine();
        $this->line('Next steps:');
        $this->line("  1. Review {$relative}" . ($preset === Presets::DEPLOY ? ' and replace every TODO value' : ''));
        $this->line('  2. ' . ($preset === Presets::PACKAGE ? 'vendor/bin/testbench' : 'php artisan') . ' slipway:compile   (writes the pipeline files)');
        $this->line('  3. Commit the config AND the generated files. Never edit the generated files by hand.');

        return self::SUCCESS;
    }

    /**
     * The branch pushes should run on.
     *
     * Returns null when the name cannot be written into the preset. A non-interactive run with no --branch uses main.
     */
    private function resolveBranch(): ?string
    {
        $requested = $this->option('branch');
        if (is_string($requested) && trim($requested) !== '') {
            return $this->validatedBranch($requested);
        }

        if ($this->input->isInteractive()) {
            $answer = $this->ask('Which branch should CI run on?', 'main');

            return $this->validatedBranch(is_string($answer) ? $answer : 'main');
        }

        return $this->validatedBranch('main');
    }

    /**
     * Accept a branch name, or print why it was refused.
     *
     * Returns null when the name is empty or not a single branch CI can check out.
     */
    private function validatedBranch(string $branch): ?string
    {
        try {
            return Presets::branch($branch);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return null;
        }
    }
}
