<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Steps;

use Usamamuneerchaudhary\Slipway\Model\Contribution;
use Usamamuneerchaudhary\Slipway\Model\Step;

/** Installs npm dependencies and runs a package.json script (default `test`). Requires `node` in config. */
final class NpmTest extends AbstractStep
{
    public function contribute(array $options): Contribution
    {
        $o = $this->options($options, ['script' => 'string']);
        $script = $this->shellSafe((string) ($o['script'] ?? 'test'), 'script');

        if (str_starts_with($script, '-')) {
            throw new \InvalidArgumentException('option [script] must be a package.json script name, not a flag');
        }

        return Contribution::steps(
            Step::run('Install npm dependencies', 'npm ci --no-audit --no-fund'),
            Step::run('Run npm script: ' . $script, $script === 'test' ? 'npm test' : 'npm run ' . $script),
        )->withNode();
    }
}
