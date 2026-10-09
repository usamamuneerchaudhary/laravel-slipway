<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Model;

final class Triggers
{
    /**
     * @param list<string> $pushBranches
     * @param list<string> $tags         Tag glob patterns, e.g. "v*".
     * @param list<string> $pullRequestBranches Target branches; empty means all.
     * @param list<string> $schedule     Cron expressions (UTC).
     * @param list<string> $pathsIgnore  Globs; changes only touching these do not trigger a run.
     */
    public function __construct(
        public readonly array $pushBranches = [],
        public readonly array $tags = [],
        public readonly bool $pullRequest = false,
        public readonly array $pullRequestBranches = [],
        public readonly array $schedule = [],
        public readonly bool $manual = false,
        public readonly array $pathsIgnore = [],
    ) {
    }

    public function hasPush(): bool
    {
        return $this->pushBranches !== [];
    }

    public function hasTags(): bool
    {
        return $this->tags !== [];
    }
}
