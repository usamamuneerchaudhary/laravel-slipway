<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Deployers;

use Usamamuneerchaudhary\Slipway\Model\Environment;
use Usamamuneerchaudhary\Slipway\Support\Shell;

/**
 * Laravel Vapor: runs `vendor/bin/vapor deploy` (requires laravel/vapor-cli in your
 * composer dependencies). Vapor builds and ships its own artifact from the checkout.
 *
 * Options:
 *   secret      NAME of the secret holding the Vapor API token (default VAPOR_API_TOKEN)
 *   environment Vapor environment name (default: this environment's key)
 */
final class VaporDeployer extends AbstractDeployer
{
    public function validate(Environment $environment): array
    {
        $errors = array_merge(
            $this->unknownOptions($environment->options, ['secret', 'environment']),
            $this->validSecretName($environment, 'secret', 'VAPOR_API_TOKEN'),
        );

        if (! preg_match('/^[a-z][a-z0-9_-]*$/i', $this->vaporEnvironment($environment))) {
            $errors[] = 'option [environment] must be a plain Vapor environment name';
        }

        return $errors;
    }

    public function requiredSecrets(Environment $environment): array
    {
        return [$this->secretOption($environment, 'secret', 'VAPOR_API_TOKEN')];
    }

    public function tools(Environment $environment): array
    {
        return [];
    }

    public function needsPhp(Environment $environment): bool
    {
        return true;
    }

    public function deployCommands(Environment $environment): string
    {
        $env = Shell::quote($this->vaporEnvironment($environment));

        return 'VAPOR_API_TOKEN="$' . $this->secretOption($environment, 'secret', 'VAPOR_API_TOKEN') . '" '
            . 'vendor/bin/vapor deploy ' . $env . ' --commit="$SLIPWAY_SHA" --no-interaction';
    }

    public function supportsRollback(Environment $environment): bool
    {
        return true;
    }

    public function rollbackCommands(Environment $environment): ?string
    {
        $env = Shell::quote($this->vaporEnvironment($environment));

        return 'VAPOR_API_TOKEN="$' . $this->secretOption($environment, 'secret', 'VAPOR_API_TOKEN') . '" '
            . 'vendor/bin/vapor rollback ' . $env . ' --no-interaction';
    }

    private function vaporEnvironment(Environment $environment): string
    {
        $value = $environment->options['environment'] ?? $environment->name;

        return is_string($value) ? $value : $environment->name;
    }
}
