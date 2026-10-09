<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Policies;

final class Violation
{
    public const ERROR = 'error';
    public const WARNING = 'warning';

    /** @param 'error'|'warning' $severity */
    public function __construct(
        public readonly string $policy,
        public readonly string $message,
        public readonly string $severity = self::ERROR,
    ) {
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }

    public function __toString(): string
    {
        return sprintf('[%s] %s: %s', $this->severity, $this->policy, $this->message);
    }
}
