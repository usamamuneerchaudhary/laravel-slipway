<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Steps;

use Usamamuneerchaudhary\Slipway\Model\Contribution;
use Usamamuneerchaudhary\Slipway\Model\Step;

/**
 * Static analysis with PHPStan/Larastan. Rules live in your phpstan.neon;
 * `level` overrides it from CI when you want a stricter gate than local runs.
 */
final class Larastan extends AbstractStep
{
    public function contribute(array $options): Contribution
    {
        $o = $this->options($options, ['level' => 'int|string', 'memory_limit' => 'string']);

        $cmd = 'vendor/bin/phpstan analyse --no-progress --no-interaction';

        if (isset($o['level'])) {
            $level = (string) $o['level'];
            if ($level !== 'max' && preg_match('/^\d{1,2}$/D', $level) !== 1) {
                throw new \InvalidArgumentException('option [level] must be 0-10 or "max"');
            }
            $cmd .= ' --level=' . $level;
        }

        $cmd .= ' --memory-limit=' . $this->shellSafe((string) ($o['memory_limit'] ?? '1G'), 'memory_limit');

        return Contribution::steps(Step::run('Static analysis (Larastan)', $cmd));
    }
}
