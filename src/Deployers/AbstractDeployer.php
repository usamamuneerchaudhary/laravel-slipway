<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Deployers;

use Usamamuneerchaudhary\Slipway\Contracts\Deployer;
use Usamamuneerchaudhary\Slipway\Model\Environment;

abstract class AbstractDeployer implements Deployer
{
    /**
     * Check an options array against an allow-list.
     *
     * @param array<string,mixed> $options
     * @param list<string>        $allowed
     * @return list<string>
     */
    protected function unknownOptions(array $options, array $allowed): array
    {
        $errors = [];
        foreach (array_keys($options) as $key) {
            if (! in_array($key, $allowed, true)) {
                $errors[] = sprintf('unknown option [%s]; allowed: %s', $key, implode(', ', $allowed));
            }
        }

        return $errors;
    }

    protected function secretOption(Environment $environment, string $key, string $default): string
    {
        $value = $environment->options[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /** @return list<string> */
    protected function validSecretName(Environment $environment, string $key, string $default): array
    {
        $name = $environment->options[$key] ?? $default;

        return is_string($name) && preg_match('/^[A-Z][A-Z0-9_]*$/', $name) === 1
            ? []
            : ["option [{$key}] must be an UPPER_SNAKE_CASE secret name"];
    }

    public function needsPhp(Environment $environment): bool
    {
        return false;
    }

    public function usesArtifact(Environment $environment): bool
    {
        return false;
    }

    public function supportsRollback(Environment $environment): bool
    {
        return false;
    }

    public function rollbackCommands(Environment $environment): ?string
    {
        return null;
    }
}
