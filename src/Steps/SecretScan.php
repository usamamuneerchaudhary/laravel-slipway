<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Steps;

use Usamamuneerchaudhary\Slipway\Model\Contribution;
use Usamamuneerchaudhary\Slipway\Model\Step;

/**
 * Scans the checked-out repository for committed credentials using Slipway's built-in
 * scanner (no external download, so nothing unpinned is fetched in CI).
 * It is a fast first line of defence, not a replacement for a dedicated scanner.
 */
final class SecretScan extends AbstractStep
{
    public function contribute(array $options): Contribution
    {
        $this->options($options, []);

        return Contribution::steps(Step::run('Scan repository for secrets', Step::ARTISAN . ' slipway:scan --no-interaction'));
    }
}
