<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Conformance;

use Usamamuneerchaudhary\Slipway\Contracts\Driver;
use Usamamuneerchaudhary\Slipway\Drivers\CircleCiDriver;

final class CircleCiConformanceTest extends DriverConformanceTestCase
{
    protected function driver(): Driver
    {
        return new CircleCiDriver();
    }
}
