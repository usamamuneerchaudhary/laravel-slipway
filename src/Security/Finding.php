<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Security;

/**
 * A suspected credential. Deliberately carries no part of the secret value, so reports,
 * logs and CI output can never leak what they are warning about.
 */
final class Finding
{
    public function __construct(
        public readonly string $type,
        public readonly string $location,
        public readonly string $advice = 'Remove it, rotate the credential, and reference a declared secret by name instead.',
    ) {
    }

    public function __toString(): string
    {
        return "{$this->type} at {$this->location}. {$this->advice}";
    }
}
