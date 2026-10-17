<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Console;

use Illuminate\Console\Command;

/**
 * Appends `pest()->tia()` to an existing Pest.php so local runs use TIA without putting `--tia` on CI.
 */
final class TiaCommand extends Command
{
    protected $signature = 'slipway:tia
        {--filtered : Narrow PHPUnit to affected test files}
        {--directory= : TIA storage directory, relative to the project root}
        {--path=tests/Pest.php : Pest configuration file, relative to the project root}';

    protected $description = 'Add pest()->tia()->locally()->baselined() to an existing Pest configuration file';

    public function handle(): int
    {
        $path = $this->option('path');
        $path = is_string($path) && $path !== '' ? $path : 'tests/Pest.php';
        $target = $this->resolvePath($path);
        if ($target === null) {
            return self::FAILURE;
        }

        $directory = $this->option('directory');
        $directory = is_string($directory) && $directory !== '' ? $directory : null;
        if ($directory !== null && (preg_match('/^[A-Za-z0-9._\/=:@%+,\-]+$/D', $directory) !== 1 || preg_match('#(^|/)\.\.(/|$)#', $directory) === 1)) {
            $this->error('The --directory value contains characters that are not allowed.');

            return self::FAILURE;
        }

        if (! is_file($target)) {
            $this->error($path . ' does not exist, so slipway:tia did not create a TIA-only stub. Install Pest so this file extends your test case, then run this command again.');

            return self::FAILURE;
        }

        $existing = (string) file_get_contents($target);
        if ($this->alreadyCallsPestTia($existing)) {
            $this->info($path . ' already calls pest()->tia(). Left unchanged.');

            return self::SUCCESS;
        }

        $contents = rtrim($existing) . "\n\n" . $this->statement((bool) $this->option('filtered'), $directory) . "\n";

        if (file_put_contents($target, $contents, LOCK_EX) === false) {
            $this->error("Could not write [{$path}].");

            return self::FAILURE;
        }

        $this->info("Updated {$path}.");
        $this->line('The pull-request suite still runs in full. CI records a shared baseline when the pest step sets tia.');

        return self::SUCCESS;
    }

    private function statement(bool $filtered, ?string $directory): string
    {
        $call = 'pest()->tia()->locally()->baselined()';
        if ($filtered) {
            $call .= '->filtered()';
        }
        if ($directory !== null) {
            $call .= '->directory(' . var_export($directory, true) . ')';
        }

        return $call . ';';
    }

    /**
     * A real `pest()->tia(` call. Comments, strings, and other `->tia(` calls do not count.
     */
    private function alreadyCallsPestTia(string $contents): bool
    {
        if (! str_contains($contents, 'tia')) {
            return false;
        }

        $code = '';
        foreach (token_get_all($contents) as $token) {
            if (! is_array($token)) {
                $code .= $token;

                continue;
            }

            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING], true)) {
                $code .= ' ';

                continue;
            }

            $code .= $token[1];
        }

        return preg_match('/\bpest\s*\(\s*\)\s*->\s*tia\s*\(/', $code) === 1;
    }

    private function resolvePath(string $path): ?string
    {
        if (str_starts_with($path, '/') || preg_match('#(^|/)\.\.(/|$)#', $path) === 1 || str_contains($path, "\0")) {
            $this->error('The --path must be inside the project (no leading "/" or "..").');

            return null;
        }

        return $this->projectRoot() . '/' . $path;
    }

    /**
     * Laravel apps write under base_path(). Testbench's base_path() is a throwaway skeleton,
     * so a package command writes under the working directory instead.
     */
    private function projectRoot(): string
    {
        $base = rtrim(base_path(), '/\\');
        $cwd = getcwd();
        $cwd = rtrim(is_string($cwd) && $cwd !== '' ? $cwd : $base, '/\\');

        if ($cwd === $base) {
            return $base;
        }

        if (is_file($base . '/bootstrap/.testbench-default-skeleton')) {
            return $cwd;
        }

        if (is_file($cwd . '/testbench.yaml') || is_file($cwd . '/testbench.yml')) {
            return $cwd;
        }

        return $base;
    }
}
