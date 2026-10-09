<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Model;

/**
 * A unit of execution. Jobs belong to a stage; stages run sequentially and the
 * jobs inside one stage run in parallel.
 */
final class Job
{
    /**
     * @param list<string>          $needs         Job ids that must finish first.
     * @param list<string>          $phpVersions   Empty = this job needs no PHP runtime.
     * @param list<string>          $extensions
     * @param list<string>          $tools         CLI tools needed on shell-only jobs (curl, ssh, rsync, git).
     * @param list<Service>         $services
     * @param list<CacheDef>        $caches
     * @param list<Step>            $steps
     * @param list<string>          $events        pull_request|push|tag|schedule|manual
     * @param list<string>|null     $branches      Push branch filter; null = all configured push branches.
     * @param list<string>|null     $tags          Tag pattern filter; null = all configured tag patterns.
     * @param list<string>          $artifactPaths Paths this job publishes as the build artifact.
     * @param array<string,string>  $env
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $stage,
        public readonly array $needs = [],
        public readonly array $phpVersions = [],
        public readonly array $extensions = [],
        public readonly ?string $coverage = null,
        public readonly ?string $node = null,
        public readonly array $tools = [],
        public readonly array $services = [],
        public readonly array $caches = [],
        public readonly array $steps = [],
        public readonly int $timeoutMinutes = 20,
        public readonly ?string $environment = null,
        public readonly bool $manual = false,
        public readonly array $events = ['pull_request', 'push'],
        public readonly ?array $branches = null,
        public readonly ?array $tags = null,
        public readonly array $artifactPaths = [],
        public readonly bool $consumesArtifact = false,
        public readonly array $env = [],
    ) {
    }

    public function isMatrix(): bool
    {
        return count($this->phpVersions) > 1;
    }

    public function needsPhp(): bool
    {
        return $this->phpVersions !== [];
    }

    /**
     * Copy of this job with some constructor properties replaced.
     *
     * @param array<string,mixed> $changes property name => new value
     */
    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /** @return list<string> All secret names referenced by this job's steps. */
    public function secretNames(): array
    {
        $names = [];
        foreach ($this->steps as $step) {
            foreach ($step->secrets as $secret) {
                $names[$secret] = true;
            }
        }

        return array_keys($names);
    }
}
