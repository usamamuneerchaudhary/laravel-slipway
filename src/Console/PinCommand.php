<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Console;

use RuntimeException;
use Usamamuneerchaudhary\Slipway\Compiler;
use Usamamuneerchaudhary\Slipway\Support\ActionPins;

final class PinCommand extends SlipwayCommand
{
    protected $signature = 'slipway:pin
        {--config= : Path to a configuration file (default: config/slipway.php)}';

    protected $description = 'Resolve the GitHub Actions the workflow uses to commit SHAs, ready for drivers.github.options.pins';

    public function handle(Compiler $compiler, ActionPins $pins): int
    {
        $located = $this->locate();
        if ($located === null) {
            return self::FAILURE;
        }

        $result = $this->compile($compiler, $located, false);
        if ($result === null) {
            return self::FAILURE;
        }

        $github = $result->files['github'] ?? null;
        if ($github === null) {
            $this->error('The GitHub driver is not enabled, so there are no actions to pin.');

            return self::FAILURE;
        }

        $unpinned = ActionPins::unpinned($github->contents);
        if ($unpinned === []) {
            $this->info('Every action in ' . $github->path . ' is already pinned to a commit SHA.');

            return self::SUCCESS;
        }

        $resolved = [];
        foreach ($unpinned as $action => $ref) {
            try {
                $resolved[$action] = ['ref' => $ref, 'sha' => $pins->resolve($action, $ref)];
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $this->line('Add this to drivers.github.options in ' . $located['source'] . ', then run slipway:compile:');
        $this->newLine();
        $this->line("'pins' => [");
        foreach ($resolved as $action => $pin) {
            $this->line(sprintf("    '%s' => '%s', // %s", $action, $pin['sha'], $pin['ref']));
        }
        $this->line('],');

        return self::SUCCESS;
    }
}
