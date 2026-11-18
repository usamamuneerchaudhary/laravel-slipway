<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Slipway;

final class VersionTest extends TestCase
{
    public function test_the_version_constant_matches_the_newest_changelog_release(): void
    {
        $changelog = file_get_contents(__DIR__ . '/../../CHANGELOG.md');
        $this->assertIsString($changelog);

        $this->assertSame(1, preg_match('/^## \[(\d+\.\d+(?:\.\d+)?)\]/m', $changelog, $m), 'CHANGELOG.md has no released version heading.');
        $this->assertSame($m[1], Slipway::VERSION, 'Update Slipway::VERSION when you add a release to CHANGELOG.md.');
    }
}
