<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Usamamuneerchaudhary\Slipway\Config\ConfigException;
use Usamamuneerchaudhary\Slipway\Config\Schema;
use Usamamuneerchaudhary\Slipway\Contracts\Driver;
use Usamamuneerchaudhary\Slipway\Model\Pipeline;
use Usamamuneerchaudhary\Slipway\Model\Support;
use Usamamuneerchaudhary\Slipway\Policies\PolicyEngine;
use Usamamuneerchaudhary\Slipway\Security\SecretScanner;
use Usamamuneerchaudhary\Slipway\Support\ActionPins;

/**
 * config array -> validate -> neutral pipeline -> policies -> one YAML file per enabled driver.
 *
 * Output is fully deterministic (no timestamps, stable ordering), so `check()` can detect drift
 * by plain byte comparison.
 */
final class Compiler
{
    public function __construct(
        private readonly PipelineBuilder $builder,
        private readonly PolicyEngine $policies,
        private readonly DriverManager $drivers,
        private readonly SecretScanner $scanner,
        private readonly string $source = 'config/slipway.php',
    ) {
    }

    /**
     * @param array<string,mixed> $config The raw contents of config/slipway.php.
     * @throws ConfigException  Invalid config, policy errors, unsupported features in strict mode,
     *                          or credentials found in the config or generated output.
     */
    public function compile(array $config, ?string $source = null): CompileResult
    {
        $source ??= $this->source;
        $errors = [];
        $warnings = [];

        foreach ($this->scanner->scanConfig($config) as $finding) {
            $errors[] = 'Credential in configuration: ' . $finding;
        }

        try {
            $normalized = (new Schema())->normalize($config); // throws ConfigException with every schema error
            $pipeline = $this->builder->build($normalized);   // throws ConfigException with every build error
        } catch (ConfigException $e) {
            throw new ConfigException(array_merge($errors, $e->errors));
        }

        foreach ($this->policies->evaluate($pipeline, $normalized['policies']) as $violation) {
            if ($violation->isError()) {
                $errors[] = (string) $violation;
            } else {
                $warnings[] = (string) $violation;
            }
        }

        $hash = $this->hash($normalized);
        $files = [];

        foreach ($normalized['drivers'] as $name => $driverConfig) {
            if (! $driverConfig['enabled']) {
                continue;
            }

            if (! $this->drivers->has($name)) {
                $errors[] = "drivers.{$name}: no such driver. Available: " . implode(', ', $this->drivers->names()) . '.';
                continue;
            }

            $driver = $this->drivers->get($name);

            [$capWarnings, $capErrors] = $this->checkCapabilities($driver, $pipeline, $normalized['strict']);
            $warnings = array_merge($warnings, $capWarnings);
            $errors = array_merge($errors, $capErrors);

            try {
                $yaml = $driver->render($pipeline, $driverConfig);
            } catch (InvalidArgumentException $e) {
                $errors[] = "drivers.{$name}: " . $e->getMessage();
                continue;
            } catch (Throwable $e) {
                $errors[] = "drivers.{$name}: failed to render (" . $e::class . '): ' . $e->getMessage();
                continue;
            }

            $path = $driverConfig['path'] ?? $driver->defaultPath();

            if ($normalized['strict'] && $name === 'github') {
                $unpinned = ActionPins::unpinned($yaml);
                if ($unpinned !== []) {
                    $warnings[] = 'GitHub Actions: ' . implode(', ', array_keys($unpinned))
                        . ' use mutable version tags instead of commit SHAs. Run "' . $normalized['artisan'] . ' slipway:pin" and add the result to drivers.github.options.pins.';
                }
            }

            // Last line of defence: a credential must never reach a committed pipeline file.
            foreach ($this->scanner->scanString($yaml, $path) as $finding) {
                $errors[] = 'Credential in generated output: ' . $finding;
            }

            $files[$name] = new GeneratedFile($name, $path, $this->header($driver, $hash, $source, $normalized['artisan']) . $yaml);
        }

        if ($files === [] && $errors === []) {
            $errors[] = 'drivers: no driver is enabled. Enable at least one (github, bitbucket, gitlab).';
        }

        $paths = [];
        foreach ($files as $file) {
            if (isset($paths[$file->path])) {
                $errors[] = "drivers: [{$file->driver}] and [{$paths[$file->path]}] would both write {$file->path}. Give each driver its own path.";
            }
            $paths[$file->path] = $file->driver;
        }

        if ($errors !== []) {
            throw new ConfigException($errors);
        }

        return new CompileResult($pipeline, $files, $warnings, $hash);
    }

    /**
     * Write the generated files under $basePath. Unchanged files are left untouched.
     *
     * @return list<string> Relative paths that were created or updated.
     */
    public function write(CompileResult $result, string $basePath): array
    {
        $changed = [];

        foreach ($result->files as $file) {
            $target = $this->resolve($basePath, $file->path);
            $existing = is_file($target) ? file_get_contents($target) : null;

            if ($existing === $file->contents) {
                continue;
            }

            $dir = dirname($target);
            if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
                throw new RuntimeException("Could not create directory [{$dir}].");
            }

            if (file_put_contents($target, $file->contents, LOCK_EX) === false) {
                throw new RuntimeException("Could not write [{$target}].");
            }

            $changed[] = $file->path;
        }

        return $changed;
    }

    /**
     * Compare generated files with what is on disk.
     *
     * @return array<string,string> relative path => "missing" | "modified"
     */
    public function drift(CompileResult $result, string $basePath): array
    {
        $drift = [];

        foreach ($result->files as $file) {
            $target = $this->resolve($basePath, $file->path);

            if (! is_file($target)) {
                $drift[$file->path] = 'missing';
            } elseif ($this->normalizeLineEndings((string) file_get_contents($target)) !== $this->normalizeLineEndings($file->contents)) {
                $drift[$file->path] = 'modified';
            }
        }

        return $drift;
    }

    // ---------------------------------------------------------------------

    /** @return array{0:list<string>,1:list<string>} [warnings, errors] */
    private function checkCapabilities(Driver $driver, Pipeline $pipeline, bool $strict): array
    {
        $warnings = [];
        $errors = [];
        $capabilities = $driver->capabilities();
        $notes = $driver->notes();

        foreach ($pipeline->features() as $feature) {
            $support = $capabilities[$feature->value] ?? Support::None;
            $note = isset($notes[$feature->value]) ? ' ' . $notes[$feature->value] : '';

            if ($support === Support::Partial) {
                $warnings[] = "{$driver->label()}: {$feature->label()} is only partially supported.{$note}";
            } elseif ($support === Support::None) {
                $message = "{$driver->label()}: {$feature->label()} is not supported and was left out.{$note}";
                if ($strict) {
                    $errors[] = $message . ' (strict mode)';
                } else {
                    $warnings[] = $message;
                }
            }
        }

        return [$warnings, $errors];
    }

    private function header(Driver $driver, string $hash, string $source, string $artisan): string
    {
        return implode("\n", [
            '# Generated by Slipway for ' . $driver->label() . '. DO NOT EDIT BY HAND.',
            "# Source: {$source} (config hash {$hash})",
            "# Regenerate with: {$artisan} slipway:compile",
            '',
            '',
        ]);
    }

    /**
     * Hash of the normalized configuration only. The package version is deliberately excluded so a
     * Slipway release never makes every consumer's committed pipeline "stale".
     *
     * @param array<string,mixed> $normalized
     */
    private function hash(array $normalized): string
    {
        $encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return substr(hash('sha256', $encoded === false ? serialize($normalized) : $encoded), 0, 12);
    }

    /** A checkout with `core.autocrlf` must not look like a hand edit. */
    private function normalizeLineEndings(string $contents): string
    {
        return str_replace("\r\n", "\n", $contents);
    }

    private function resolve(string $basePath, string $relative): string
    {
        // Schema already rejects absolute paths and "..", but never trust a single layer when writing files.
        if (str_starts_with($relative, '/') || preg_match('#(^|/)\.\.(/|$)#', $relative) === 1) {
            throw new RuntimeException("Refusing to write outside the project: [{$relative}].");
        }

        return rtrim($basePath, '/\\') . '/' . $relative;
    }
}
