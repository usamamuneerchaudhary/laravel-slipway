<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Policies;

use Usamamuneerchaudhary\Slipway\Model\Pipeline;

/**
 * Targeted environments must verify the release after deploying (continuous verification).
 *
 * Options: environments (default ['production'])
 */
final class RequireVerification extends AbstractPolicy
{
    public function name(): string
    {
        return 'verify-production';
    }

    public function check(Pipeline $pipeline, array $options = []): array
    {
        $violations = [];

        foreach ($this->targets($options) as $name) {
            $env = $pipeline->environments[$name] ?? null;
            if ($env !== null && $env->verify === null) {
                $violations[] = new Violation($this->name(), "Environment [{$name}] has no post-deploy verification. Add a 'verify' health check.");
            }
        }

        return $violations;
    }
}
