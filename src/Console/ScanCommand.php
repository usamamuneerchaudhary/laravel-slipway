<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Usamamuneerchaudhary\Slipway\Security\Finding;
use Usamamuneerchaudhary\Slipway\Security\SecretScanner;
use Usamamuneerchaudhary\Slipway\Support\ConfigLocator;

final class ScanCommand extends Command
{
    protected $signature = 'slipway:scan
        {--path= : Directory to scan (default: the project root)}
        {--changed : Scan only modified, staged and untracked files (requires git)}
        {--base= : With --changed, scan everything that differs from this git ref, e.g. origin/main}';

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

        $scanner = new SecretScanner(is_array($ignore) ? $ignore : []);
        $base = $this->option('base');

        if (is_string($base) && $base !== '' && ! $this->option('changed')) {
            $this->components->error('--base only works together with --changed.');

            return self::FAILURE;
        }

        $isChanged = (bool) $this->option('changed');
        $base = is_string($base) && $base !== '' ? $base : null;
        $startedAt = microtime(true);

        try {
            $findings = $isChanged ? $scanner->scanChanged($root, $base) : $scanner->scanDirectory($root);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $summary = sprintf(
            '%s %s scanned in %s',
            number_format($scanner->filesScanned()),
            $scanner->filesScanned() === 1 ? 'file' : 'files',
            $this->formatDuration(microtime(true) - $startedAt),
        );

        $this->newLine();
        $this->line('  <options=bold>Slipway secret scan</>');
        $this->line('  <fg=gray>' . $this->describeScope($root, $isChanged, $base) . '</>');
        $this->newLine();

        if ($findings === []) {
            $this->line("  <bg=green;fg=black;options=bold> PASS </> No credentials found. <fg=gray>{$summary}.</>");
            $this->newLine();

            return self::SUCCESS;
        }

        $this->renderFindings($findings);

        $files = count(array_unique(array_map(fn (Finding $finding): string => $this->splitLocation($finding->location)[0], $findings)));
        $this->line(sprintf(
            '  <bg=red;fg=white;options=bold> FAIL </> %d potential %s in %d %s. <fg=gray>%s.</>',
            count($findings),
            count($findings) === 1 ? 'credential' : 'credentials',
            $files,
            $files === 1 ? 'file' : 'files',
            $summary,
        ));
        $this->newLine();
        $this->line('  <fg=gray>Deliberate fixture? Add "slipway-scan-ignore" to its line, or list the path under "scan.ignore" in the configuration.</>');
        $this->newLine();

        return self::FAILURE;
    }

    /** @param list<Finding> $findings */
    private function renderFindings(array $findings): void
    {
        $byFile = [];
        $advice = [];

        foreach ($findings as $finding) {
            [$file, $line] = $this->splitLocation($finding->location);
            $byFile[$file][] = [$line, $finding->type];
            $advice[$finding->advice] = true;
        }

        ksort($byFile);

        foreach ($byFile as $file => $entries) {
            $this->line("  <fg=red;options=bold>✗</> <options=bold>{$file}</>");

            foreach ($entries as [$line, $type]) {
                $where = $line === null ? str_repeat(' ', 9) : str_pad("line {$line}", 9);
                $this->line("      <fg=gray>{$where}</> {$type}");
            }

            $this->newLine();
        }

        $this->line('  <options=bold>How to fix</>');

        foreach (array_keys($advice) as $text) {
            $this->line("  <fg=yellow>→</> {$text}");
        }

        $this->newLine();
    }

    /** @return array{0:string,1:int|null} */
    private function splitLocation(string $location): array
    {
        if (preg_match('/^(.*):(\d+)$/', $location, $matches) === 1) {
            return [$matches[1], (int) $matches[2]];
        }

        return [$location, null];
    }

    private function describeScope(string $root, bool $isChanged, ?string $base): string
    {
        if (! $isChanged) {
            return "Full scan of {$root}";
        }

        return $base === null
            ? "Changed files only (modified, staged, untracked) in {$root}"
            : "Changed files only (differing from {$base}) in {$root}";
    }

    private function formatDuration(float $seconds): string
    {
        return $seconds < 1 ? number_format($seconds * 1000) . 'ms' : number_format($seconds, 1) . 's';
    }
}
