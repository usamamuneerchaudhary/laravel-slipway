<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Policies;

use Usamamuneerchaudhary\Slipway\Model\Pipeline;

/**
 * Targeted environments must roll back automatically when verification fails.
 *
 * Options: environments (default ['production'])
 */
final class RequireRollback extends AbstractPolicy
{
    public function name(): string
    {
        return 'rollback-for-production';
    }

    public function check(Pipeline $pipeline, array $options = []): array
    {
        $violations = [];

        foreach ($this->targets($options) as $name) {
            $env = $pipeline->environments[$name] ?? null;
            if ($env !== null && ! $env->rollback) {
                $violations[] = new Violation($this->name(), "Environment [{$name}] does not roll back automatically. Set 'rollback' => true (and configure 'verify').");
            }
        }

        return $violations;
    }
}
