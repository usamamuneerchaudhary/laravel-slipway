<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Usamamuneerchaudhary\Slipway\Security\SecretScanner;
use Usamamuneerchaudhary\Slipway\Support\ConfigLocator;

final class ScanCommand extends Command
{
    protected $signature = 'slipway:scan
        {--path= : Directory to scan (default: the project root)}';

    protected $description = 'Scan the repository for committed credentials';

    public function handle(): int
    {
        $ignore = [];
        $laravelConfig = config('slipway');

        try {
            $located = (new ConfigLocator(base_path(), getcwd() ?: base_path(), is_array($laravelConfig) ? $laravelConfig : null))->locate();
            $ignore = $located['config']['scan']['ignore'] ?? [];
            $root = $located['root'];
        } catch (RuntimeException) {
            $root = base_path();
        }

        $path = $this->option('path');
        $root = is_string($path) && $path !== '' ? $path : $root;

        $findings = (new SecretScanner(is_array($ignore) ? $ignore : []))->scanDirectory($root);

        if ($findings === []) {
            $this->info('No credentials found.');

            return self::SUCCESS;
        }

        foreach ($findings as $finding) {
            $this->error((string) $finding);
        }
        $this->line(count($findings) . ' potential credential(s). To ignore a deliberate fixture, add "slipway-scan-ignore" to its line or list the path under "scan.ignore" in the configuration.');

        return self::FAILURE;
    }
}
