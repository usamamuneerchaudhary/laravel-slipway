<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Policies;

use Usamamuneerchaudhary\Slipway\Model\Pipeline;

/**
 * "Shift security left": nothing reaches the targeted environments unless a dependency
 * vulnerability audit ran earlier in the same pipeline.
 *
 * Options: environments (default ['production'])
 */
final class RequireAuditBeforeProduction extends AbstractPolicy
{
    public function name(): string
    {
        return 'audit-before-production';
    }

    public function check(Pipeline $pipeline, array $options = []): array
    {
        $violations = [];

        foreach ($this->deployJobs($pipeline, $options) as $job) {
            if (! $this->anyStepMatches($this->ancestors($pipeline, $job), '/\bcomposer audit\b/')) {
                $violations[] = new Violation(
                    $this->name(),
                    "Environment [{$job->environment}] can be deployed without a Composer audit. Add 'composer-audit' to a stage before it."
                );
            }
        }

        return $violations;
    }
}
