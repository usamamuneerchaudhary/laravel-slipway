<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Contracts;

use Usamamuneerchaudhary\Slipway\Model\Contribution;

/**
 * PUBLIC API. A reusable building block for a stage, e.g. "run Pint".
 *
 * Reference it in config/slipway.php by class name or by a registered alias:
 *
 *     'quality' => [Pint::class, Larastan::class => ['level' => 6]],
 *
 * Implementations must be pure: the same options must always produce the same
 * Contribution, because the compiler output is compared byte-for-byte by the drift guard.
 */
interface PipelineStep
{
    /**
     * @param array<string,mixed> $options Options given in the config entry (may be empty).
     */
    public function contribute(array $options): Contribution;
}
