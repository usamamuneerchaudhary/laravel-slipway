<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Console;

use Usamamuneerchaudhary\Slipway\Compiler;

final class CompileCommand extends SlipwayCommand
{
    protected $signature = 'slipway:compile
        {--config= : Path to a configuration file (default: config/slipway.php)}
        {--dry-run : Show which files would change without writing them}';

    protected $description = 'Compile the Slipway configuration into pipeline files for every enabled provider';

    public function handle(Compiler $compiler): int
    {
        $located = $this->locate();
        if ($located === null) {
            return self::FAILURE;
        }

        $result = $this->compile($compiler, $located);
        if ($result === null) {
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $drift = $compiler->drift($result, $located['root']);
            if ($drift === []) {
                $this->info('Nothing to change: pipeline files are up to date.');
            }
            foreach ($drift as $path => $state) {
                $this->line(sprintf('  would %s %s', $state === 'missing' ? 'create' : 'update', $path));
            }

            return self::SUCCESS;
        }

        $changed = $compiler->write($result, $located['root']);

        foreach ($result->files as $file) {
            $this->line(sprintf('  %s %s', in_array($file->path, $changed, true) ? 'wrote    ' : 'unchanged', $file->path));
        }

        $this->info($changed === [] ? 'Pipeline files already up to date.' : 'Compiled ' . count($changed) . ' pipeline file(s). Commit them with your config.');

        return self::SUCCESS;
    }
}
