<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Model;

use InvalidArgumentException;

/**
 * The provider-neutral pipeline. Everything a driver needs to render YAML.
 */
final class Pipeline
{
    /**
     * @param list<Job>               $jobs               In execution order (stage order, then declaration order).
     * @param array<string,Environment> $environments
     * @param list<string>            $declaredSecrets    Secret names the project declares (names only, never values).
     * @param array<string,mixed>     $notifications
     */
    public function __construct(
        public readonly string $name,
        public readonly Triggers $triggers,
        public readonly array $jobs,
        public readonly array $environments = [],
        public readonly array $declaredSecrets = [],
        public readonly bool $cancelSuperseded = true,
        public readonly array $notifications = [],
    ) {
    }

    /** @return array<string,list<Job>> stage name => jobs */
    public function stages(): array
    {
        $stages = [];
        foreach ($this->jobs as $job) {
            $stages[$job->stage][] = $job;
        }

        return $stages;
    }

    public function job(string $id): Job
    {
        foreach ($this->jobs as $job) {
            if ($job->id === $id) {
                return $job;
            }
        }

        throw new InvalidArgumentException("Unknown job [{$id}].");
    }

    /**
     * Jobs that run for a given trigger. For `push`, pass the branch name.
     *
     * @return list<Job>
     */
    public function jobsFor(string $event, ?string $branch = null): array
    {
        $out = [];
        foreach ($this->jobs as $job) {
            if (! in_array($event, $job->events, true)) {
                continue;
            }

            if ($event === 'push' && $branch !== null) {
                $patterns = $job->branches ?? $this->triggers->pushBranches;
                if (! self::matchesAny($branch, $patterns)) {
                    continue;
                }
            }

            $out[] = $job;
        }

        return $out;
    }

    /** @return list<Feature> */
    public function features(): array
    {
        $used = [];
        $flag = static function (Feature $f) use (&$used): void {
            $used[$f->value] = $f;
        };

        foreach ($this->jobs as $job) {
            $job->isMatrix() && $flag(Feature::Matrix);
            $job->services !== [] && $flag(Feature::Services);
            $job->manual && $flag(Feature::Approvals);
            $job->environment !== null && $flag(Feature::Environments);
            ($job->artifactPaths !== [] || $job->consumesArtifact) && $flag(Feature::Artifacts);
            $job->caches !== [] && $flag(Feature::Caching);
            $flag(Feature::Timeouts);
        }

        foreach ($this->stages() as $jobs) {
            count($jobs) > 1 && $flag(Feature::ParallelJobs);
        }

        $this->cancelSuperseded && $flag(Feature::Concurrency);
        $this->triggers->schedule !== [] && $flag(Feature::Schedule);
        $this->triggers->manual && $flag(Feature::ManualTrigger);
        $this->triggers->pathsIgnore !== [] && $flag(Feature::PathFilters);
        $this->triggers->hasTags() && $flag(Feature::TagTriggers);

        return array_values($used);
    }

    /** @return list<string> Every secret name referenced by any step. */
    public function referencedSecrets(): array
    {
        $names = [];
        foreach ($this->jobs as $job) {
            foreach ($job->secretNames() as $name) {
                $names[$name] = true;
            }
        }

        foreach ($this->notifications as $cfg) {
            if (is_array($cfg) && isset($cfg['secret']) && is_string($cfg['secret'])) {
                $names[$cfg['secret']] = true;
            }
        }

        return array_keys($names);
    }

    /** @param list<string> $patterns */
    public static function matchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $value || fnmatch($pattern, $value)) {
                return true;
            }
        }

        return false;
    }
}
