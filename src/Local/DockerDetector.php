<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Local;

/**
 * Whether the Docker CLI is on PATH. A probe can be injected in tests.
 */
final class DockerDetector
{
    /** @var (callable():bool)|null */
    private $probe;

    /** @param (callable():bool)|null $probe */
    public function __construct(?callable $probe = null)
    {
        $this->probe = $probe;
    }

    public function installed(): bool
    {
        if ($this->probe !== null) {
            return (bool) ($this->probe)();
        }

        return $this->onPath('docker') || $this->onPath('docker.exe');
    }

    private function onPath(string $binary): bool
    {
        $path = getenv('PATH');
        if (! is_string($path) || $path === '') {
            return false;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $file = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $binary;
            if (is_file($file) && is_executable($file)) {
                return true;
            }
        }

        return false;
    }
}
