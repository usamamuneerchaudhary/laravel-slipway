<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Steps;

use Usamamuneerchaudhary\Slipway\Model\Contribution;
use Usamamuneerchaudhary\Slipway\Model\Step;

/**
 * Rector in dry-run mode: fails the build when Rector would change code, and never modifies files.
 * Rules live in your rector.php; `config` points at a different file.
 */
final class Rector extends AbstractStep
{
    public function contribute(array $options): Contribution
    {
        $o = $this->options($options, ['config' => 'string']);

        $cmd = 'vendor/bin/rector process --dry-run --no-progress-bar --no-interaction';

        if (isset($o['config'])) {
            $config = $this->shellSafe((string) $o['config'], 'config');
            if (str_starts_with($config, '/') || str_contains($config, '..') || str_starts_with($config, '-')) {
                throw new \InvalidArgumentException('option [config] must be a relative path inside the project');
            }
            $cmd .= ' --config=' . $config;
        }

        return Contribution::steps(Step::run('Check automated refactorings (Rector)', $cmd));
    }
}
