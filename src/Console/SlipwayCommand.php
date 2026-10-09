<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Usamamuneerchaudhary\Slipway\CompileResult;
use Usamamuneerchaudhary\Slipway\Compiler;
use Usamamuneerchaudhary\Slipway\Config\ConfigException;
use Usamamuneerchaudhary\Slipway\Support\ConfigLocator;

/** Shared plumbing: find the config, compile it, and report problems consistently. */
abstract class SlipwayCommand extends Command
{
    /** @return array{config:array<string,mixed>,root:string,source:string}|null */
    protected function locate(): ?array
    {
        $laravelConfig = config('slipway');
        $explicit = $this->input->hasOption('config') ? $this->option('config') : null;

        try {
            return (new ConfigLocator(
                base_path(),
                getcwd() ?: base_path(),
                is_array($laravelConfig) ? $laravelConfig : null,
            ))->locate(is_string($explicit) ? $explicit : null);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return null;
        }
    }

    /**
     * @param array{config:array<string,mixed>,root:string,source:string} $located
     */
    protected function compile(Compiler $compiler, array $located, bool $showWarnings = true): ?CompileResult
    {
        try {
            $result = $compiler->compile($located['config'], $located['source']);
        } catch (ConfigException $e) {
            $this->error('Slipway configuration is invalid (' . $located['source'] . '):');
            foreach ($e->errors as $message) {
                $this->line('  - ' . $message);
            }

            return null;
        }

        if ($showWarnings) {
            foreach ($result->warnings as $warning) {
                $this->warn($warning);
            }
        }

        return $result;
    }
}
