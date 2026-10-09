<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Steps;

use Usamamuneerchaudhary\Slipway\Model\Contribution;
use Usamamuneerchaudhary\Slipway\Model\Step;

/** Checks the locked (or, without a lock file, the installed) dependencies against known vulnerability advisories. */
final class ComposerAudit extends AbstractStep
{
    public function contribute(array $options): Contribution
    {
        $this->options($options, []);

        return Contribution::steps(Step::run('Audit Composer dependencies', 'composer audit --no-interaction' . Step::LOCKED));
    }
}
