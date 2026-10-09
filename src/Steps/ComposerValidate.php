<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Steps;

use Usamamuneerchaudhary\Slipway\Model\Contribution;
use Usamamuneerchaudhary\Slipway\Model\Step;

/**
 * Validates composer.json (and that composer.lock is in sync with it). `strict` (default true)
 * also fails on warnings, e.g. a missing license or an unbound version constraint.
 */
final class ComposerValidate extends AbstractStep
{
    public function contribute(array $options): Contribution
    {
        $o = $this->options($options, ['strict' => 'bool']);

        $cmd = 'composer validate --no-interaction' . (($o['strict'] ?? true) ? ' --strict' : '');

        return Contribution::steps(Step::run('Validate composer.json', $cmd));
    }
}
