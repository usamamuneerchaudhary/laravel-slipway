<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Policies;

use Usamamuneerchaudhary\Slipway\Contracts\Policy;
use Usamamuneerchaudhary\Slipway\Model\Job;
use Usamamuneerchaudhary\Slipway\Model\Pipeline;

abstract class AbstractPolicy implements Policy
{
    /**
     * Environments a policy applies to. Defaults to ["production"].
     *
     * @param array<string,mixed> $options
     * @return list<string>
     */
    protected function targets(array $options): array
    {
        $names = $options['environments'] ?? ['production'];

        return is_array($names) ? array_values(array_filter($names, 'is_string')) : ['production'];
    }

    /**
     * Every job that must finish before $job can start (transitive `needs`).
     *
     * @return list<Job>
     */
    protected function ancestors(Pipeline $pipeline, Job $job): array
    {
        $seen = [];
        $queue = $job->needs;

        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = $pipeline->job($id);
            array_push($queue, ...$seen[$id]->needs);
        }

        return array_values($seen);
    }

    /**
     * @param list<Job> $jobs
     */
    protected function anyStepMatches(array $jobs, string $regex): bool
    {
        foreach ($jobs as $job) {
            foreach ($job->steps as $step) {
                if ($step->run !== null && preg_match($regex, $step->run) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<Job> Deploy jobs for the targeted environments. */
    protected function deployJobs(Pipeline $pipeline, array $options): array
    {
        $out = [];
        foreach ($this->targets($options) as $name) {
            foreach ($pipeline->jobs as $job) {
                if ($job->environment === $name) {
                    $out[] = $job;
                }
            }
        }

        return $out;
    }
}
