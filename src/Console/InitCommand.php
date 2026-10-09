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
        {--driver=* : Provider to enable (github, bitbucket, gitlab). Repeat for several}
        {--force : Overwrite an existing configuration file}';

    protected $description = 'Create the Slipway configuration file from a preset';

    public function handle(DriverManager $drivers): int
    {
        $preset = (string) $this->option('preset');
        $enabled = array_values(array_unique((array) $this->option('driver')));
        $enabled = $enabled === [] ? ['github'] : $enabled;

        try {
            $contents = Presets::render($preset, $enabled, $drivers->names());
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $root = $preset === Presets::PACKAGE ? (getcwd() ?: base_path()) : base_path();
        $relative = Presets::target($preset);
        $target = rtrim($root, '/\\') . '/' . $relative;

        if (is_file($target) && ! $this->option('force')) {
            $this->error("{$relative} already exists. Use --force to overwrite it.");

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

        $this->info("Created {$relative} (preset: {$preset}; providers: " . implode(', ', $enabled) . ').');
        $this->newLine();
        $this->line('Next steps:');
        $this->line("  1. Review {$relative}" . ($preset === Presets::DEPLOY ? ' and replace every TODO value' : ''));
        $this->line('  2. ' . ($preset === Presets::PACKAGE ? 'vendor/bin/testbench' : 'php artisan') . ' slipway:compile   (writes the pipeline files)');
        $this->line('  3. Commit the config AND the generated files. Never edit the generated files by hand.');

        return self::SUCCESS;
    }
}
