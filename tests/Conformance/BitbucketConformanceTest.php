<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Conformance;

use Usamamuneerchaudhary\Slipway\Contracts\Driver;
use Usamamuneerchaudhary\Slipway\Drivers\BitbucketDriver;

final class BitbucketConformanceTest extends DriverConformanceTestCase
{
    protected function driver(): Driver
    {
        return new BitbucketDriver();
    }
}
