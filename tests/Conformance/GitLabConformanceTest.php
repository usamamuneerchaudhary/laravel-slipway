<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Conformance;

use Usamamuneerchaudhary\Slipway\Contracts\Driver;
use Usamamuneerchaudhary\Slipway\Drivers\GitLabDriver;

final class GitLabConformanceTest extends DriverConformanceTestCase
{
    protected function driver(): Driver
    {
        return new GitLabDriver();
    }
}
