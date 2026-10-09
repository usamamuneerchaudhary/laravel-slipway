<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Conformance;

use Usamamuneerchaudhary\Slipway\Contracts\Driver;
use Usamamuneerchaudhary\Slipway\Drivers\GitHubDriver;

final class GitHubConformanceTest extends DriverConformanceTestCase
{
    protected function driver(): Driver
    {
        return new GitHubDriver();
    }
}
