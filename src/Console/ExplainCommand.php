<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Console;

use Usamamuneerchaudhary\Slipway\Compiler;
use Usamamuneerchaudhary\Slipway\Support\Explainer;

final class ExplainCommand extends SlipwayCommand
{
    protected $signature = 'slipway:explain
        {--config= : Path to a configuration file (default: config/slipway.php)}';

    protected $description = 'Describe what the pipeline does in plain English';

    public function handle(Compiler $compiler): int
    {
        $located = $this->locate();
        if ($located === null) {
            return self::FAILURE;
        }

        // Warnings are part of the explanation, so print them there rather than twice.
        try {
            $result = $compiler->compile($located['config'], $located['source']);
        } catch (\Usamamuneerchaudhary\Slipway\Config\ConfigException $e) {
            $this->error('Slipway configuration is invalid (' . $located['source'] . '):');
            foreach ($e->errors as $message) {
                $this->line('  - ' . $message);
            }

            return self::FAILURE;
        }

        foreach (Explainer::explain($result) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
