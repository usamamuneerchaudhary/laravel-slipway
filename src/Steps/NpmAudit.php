<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Steps;

use Usamamuneerchaudhary\Slipway\Model\Contribution;
use Usamamuneerchaudhary\Slipway\Model\Step;

/** Checks the npm dependency tree for known vulnerabilities. Requires `node` in config. */
final class NpmAudit extends AbstractStep
{
    public function contribute(array $options): Contribution
    {
        $o = $this->options($options, ['level' => 'string']);

        $level = (string) ($o['level'] ?? 'high');
        if (! in_array($level, ['low', 'moderate', 'high', 'critical'], true)) {
            throw new \InvalidArgumentException('option [level] must be low, moderate, high or critical');
        }

        return Contribution::steps(
            Step::run('Install npm dependencies', 'npm ci --no-audit --no-fund'),
            Step::run('Audit npm dependencies', 'npm audit --audit-level=' . $level),
        )->withNode();
    }
}
