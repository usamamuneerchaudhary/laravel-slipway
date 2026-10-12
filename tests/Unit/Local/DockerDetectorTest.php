<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit\Local;

use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Local\DockerDetector;
use Usamamuneerchaudhary\Slipway\Tests\Support\Fixture;

final class DockerDetectorTest extends TestCase
{
    public function test_it_finds_docker_on_the_path(): void
    {
        $dir = Fixture::tempDir();
        $bin = $dir . '/docker';
        file_put_contents($bin, "#!/bin/sh\n");
        chmod($bin, 0755);

        $previous = getenv('PATH');
        putenv('PATH=' . $dir);

        try {
            $this->assertTrue((new DockerDetector())->installed());
        } finally {
            $this->restorePath($previous);
            Fixture::remove($dir);
        }
    }

    public function test_it_is_false_when_docker_is_not_on_the_path(): void
    {
        $previous = getenv('PATH');
        putenv('PATH=/nonexistent-slipway-docker-path');

        try {
            $this->assertFalse((new DockerDetector())->installed());
        } finally {
            $this->restorePath($previous);
        }
    }

    public function test_a_probe_overrides_the_path_lookup(): void
    {
        $this->assertFalse((new DockerDetector(static fn (): bool => false))->installed());
        $this->assertTrue((new DockerDetector(static fn (): bool => true))->installed());
    }

    private function restorePath(string|false $previous): void
    {
        if (is_string($previous)) {
            putenv('PATH=' . $previous);

            return;
        }

        putenv('PATH');
    }
}
