<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Policies;

use Usamamuneerchaudhary\Slipway\Model\Pipeline;

/**
 * Nothing deploys to the targeted environments unless a test run precedes it.
 * Recognises Pest, PHPUnit and `php artisan test`.
 *
 * Options: environments (default: every environment)
 */
final class RequireTestsBeforeDeploy extends AbstractPolicy
{
    public function name(): string
    {
        return 'tests-before-deploy';
    }

    public function check(Pipeline $pipeline, array $options = []): array
    {
        $options['environments'] ??= array_keys($pipeline->environments);
        $violations = [];

        foreach ($this->deployJobs($pipeline, $options) as $job) {
            if (! $this->anyStepMatches($this->ancestors($pipeline, $job), '#vendor/bin/(?:pest|phpunit)|artisan test#')) {
                $violations[] = new Violation(
                    $this->name(),
                    "Environment [{$job->environment}] can be deployed without running tests. Add 'pest' or 'phpunit' to a stage before it."
                );
            }
        }

        return $violations;
    }
}
