<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Steps;

use Usamamuneerchaudhary\Slipway\Model\Contribution;
use Usamamuneerchaudhary\Slipway\Model\Step;

/** Laravel Pint in test (check-only) mode. Fails the build if code style is off. */
final class Pint extends AbstractStep
{
    public function contribute(array $options): Contribution
    {
        $this->options($options, []);

        return Contribution::steps(Step::run('Check code style (Pint)', 'vendor/bin/pint --test'));
    }
}
