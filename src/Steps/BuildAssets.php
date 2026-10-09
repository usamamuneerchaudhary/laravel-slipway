<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Steps;

use Usamamuneerchaudhary\Slipway\Model\Contribution;
use Usamamuneerchaudhary\Slipway\Model\Step;

/** Installs npm dependencies and builds front-end assets (Vite by default). Requires `node` in config. */
final class BuildAssets extends AbstractStep
{
    public function contribute(array $options): Contribution
    {
        $o = $this->options($options, ['script' => 'string']);
        $script = $this->shellSafe((string) ($o['script'] ?? 'build'), 'script');

        return Contribution::steps(
            Step::run('Install npm dependencies', 'npm ci --no-audit --no-fund'),
            Step::run('Build assets', 'npm run ' . $script),
        )->withNode();
    }
}
