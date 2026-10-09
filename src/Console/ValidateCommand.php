<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Console;

use Usamamuneerchaudhary\Slipway\Compiler;

final class ValidateCommand extends SlipwayCommand
{
    protected $signature = 'slipway:validate
        {--config= : Path to a configuration file (default: config/slipway.php)}';

    protected $description = 'Validate the configuration and policies without writing any files';

    public function handle(Compiler $compiler): int
    {
        $located = $this->locate();
        if ($located === null) {
            return self::FAILURE;
        }

        if ($this->compile($compiler, $located) === null) {
            return self::FAILURE;
        }

        $this->info($located['source'] . ' is valid.');

        return self::SUCCESS;
    }
}
