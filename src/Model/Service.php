<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Model;

/**
 * A backing service (database, cache) started alongside a job.
 *
 * `$env` is the container's own configuration. `$appEnv` is a function of the host
 * name (which differs per provider) producing the env vars the Laravel app needs.
 */
final class Service
{
    /**
     * @param array<string,string> $env       Container environment.
     * @param list<int>            $ports
     * @param array<string,string> $appEnv    App env; the literal "{host}" is replaced per driver.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $image,
        public readonly array $env = [],
        public readonly array $ports = [],
        public readonly array $appEnv = [],
        public readonly ?string $healthCmd = null,
    ) {
    }
}
