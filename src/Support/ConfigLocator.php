<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Support;

use RuntimeException;

/**
 * Finds the Slipway configuration and the project root it belongs to.
 *
 * Order: explicit --config file, then Laravel's `config('slipway')`, then `slipway.php` in the
 * working directory (library packages, where Testbench's skeleton app cannot see the package's
 * own config directory).
 */
final class ConfigLocator
{
    /**
     * @param string                    $basePath      Laravel base_path().
     * @param string                    $cwd           Current working directory.
     * @param array<string,mixed>|null  $laravelConfig config('slipway').
     */
    public function __construct(
        private readonly string $basePath,
        private readonly string $cwd,
        private readonly ?array $laravelConfig,
    ) {
    }

    /**
     * @return array{config:array<string,mixed>,root:string,source:string}
     */
    public function locate(?string $explicit = null): array
    {
        if ($explicit !== null && $explicit !== '') {
            $file = $this->absolute($explicit);

            return ['config' => $this->load($file), 'root' => $this->cwd, 'source' => $this->relative($file)];
        }

        if ($this->laravelConfig !== null && $this->laravelConfig !== []) {
            return ['config' => $this->laravelConfig, 'root' => $this->basePath, 'source' => 'config/slipway.php'];
        }

        $packageFile = rtrim($this->cwd, '/\\') . '/slipway.php';
        if (is_file($packageFile)) {
            return ['config' => $this->load($packageFile), 'root' => $this->cwd, 'source' => 'slipway.php'];
        }

        throw new RuntimeException(
            'No Slipway configuration found. Run "php artisan slipway:init" (or "vendor/bin/testbench slipway:init --preset=package" in a package).'
        );
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($this->cwd, '/\\') . '/' . $path;
    }

    private function relative(string $file): string
    {
        $prefix = rtrim($this->cwd, '/\\') . '/';

        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : basename($file);
    }

    /** @return array<string,mixed> */
    private function load(string $file): array
    {
        if (! is_file($file)) {
            throw new RuntimeException("Configuration file [{$file}] does not exist.");
        }

        $config = (static fn () => require $file)();

        if (! is_array($config)) {
            throw new RuntimeException("Configuration file [{$file}] must return an array.");
        }

        return $config;
    }
}
