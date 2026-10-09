<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Health;

final class HealthResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly int $attempts,
        public readonly int $lastStatus,
        public readonly string $url,
    ) {
    }
}
