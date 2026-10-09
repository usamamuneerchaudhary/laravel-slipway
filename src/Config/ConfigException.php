<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Config;

use RuntimeException;

/**
 * Thrown when config/slipway.php is invalid. Carries every problem found, each
 * prefixed with its config path, so users can fix them in one pass.
 */
final class ConfigException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(
            "Invalid slipway configuration:\n - " . implode("\n - ", $errors)
        );
    }
}
