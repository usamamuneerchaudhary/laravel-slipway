<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Policies;

use Usamamuneerchaudhary\Slipway\Model\Pipeline;

/**
 * Targeted environments must require a manual approval before deploying.
 *
 * Options: environments (default ['production'])
 */
final class RequireApprovalForProduction extends AbstractPolicy
{
    public function name(): string
    {
        return 'approval-for-production';
    }

    public function check(Pipeline $pipeline, array $options = []): array
    {
        $violations = [];

        foreach ($this->targets($options) as $name) {
            $env = $pipeline->environments[$name] ?? null;
            if ($env !== null && ! $env->approval) {
                $violations[] = new Violation($this->name(), "Environment [{$name}] deploys without a manual approval. Set 'approval' => true.");
            }
        }

        return $violations;
    }
}
