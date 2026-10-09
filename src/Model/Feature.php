<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Model;

/**
 * Pipeline capabilities that providers may or may not support.
 * Drivers declare a Support level for each; the compiler compares them with what
 * the pipeline actually uses.
 */
enum Feature: string
{
    case Matrix = 'matrix';
    case Services = 'services';
    case Approvals = 'approvals';
    case Environments = 'environments';
    case Concurrency = 'concurrency';
    case Schedule = 'schedule';
    case ManualTrigger = 'manual-trigger';
    case Artifacts = 'artifacts';
    case PathFilters = 'path-filters';
    case Timeouts = 'timeouts';
    case Caching = 'caching';
    case ParallelJobs = 'parallel-jobs';
    case TagTriggers = 'tag-triggers';

    public function label(): string
    {
        return match ($this) {
            self::Matrix => 'PHP version matrix',
            self::Services => 'Service containers',
            self::Approvals => 'Manual approvals',
            self::Environments => 'Deployment environments',
            self::Concurrency => 'Cancel superseded runs',
            self::Schedule => 'Scheduled runs',
            self::ManualTrigger => 'Manual trigger',
            self::Artifacts => 'Artifact promotion',
            self::PathFilters => 'Path filters',
            self::Timeouts => 'Job timeouts',
            self::Caching => 'Dependency caching',
            self::ParallelJobs => 'Parallel jobs',
            self::TagTriggers => 'Tag triggers',
        };
    }
}
