<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Console;

use Usamamuneerchaudhary\Slipway\Compiler;

/** The drift guard. Runs in CI so a hand-edited or stale pipeline file fails the build. */
final class CheckCommand extends SlipwayCommand
{
    protected $signature = 'slipway:check
        {--config= : Path to a configuration file (default: config/slipway.php)}';

    protected $description = 'Fail if the committed pipeline files differ from what the configuration compiles to';

    public function handle(Compiler $compiler): int
    {
        $located = $this->locate();
        if ($located === null) {
            return self::FAILURE;
        }

        $result = $this->compile($compiler, $located, false);
        if ($result === null) {
            return self::FAILURE;
        }

        $drift = $compiler->drift($result, $located['root']);

        if ($drift === []) {
            $this->info('Pipeline files are up to date with ' . $located['source'] . '.');

            return self::SUCCESS;
        }

        foreach ($drift as $path => $state) {
            $this->error(sprintf('%s is %s.', $path, $state === 'missing' ? 'missing' : 'out of date (edited by hand, or the config changed)'));
        }
        $this->line('Run "' . ($located['config']['artisan'] ?? 'php artisan') . ' slipway:compile" and commit the result.');

        return self::FAILURE;
    }
}
