<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Deployers;

use Usamamuneerchaudhary\Slipway\Model\Environment;
use Usamamuneerchaudhary\Slipway\Support\Shell;

/**
 * Laravel Forge: triggers the site's "Deployment Trigger URL". Forge pulls the code from
 * Git on the server, so the CI build artifact is not used by this deployer.
 *
 * Options: secret (default FORGE_DEPLOY_URL), the NAME of the secret holding the trigger URL.
 */
final class ForgeDeployer extends AbstractDeployer
{
    public function validate(Environment $environment): array
    {
        return array_merge(
            $this->unknownOptions($environment->options, ['secret']),
            $this->validSecretName($environment, 'secret', 'FORGE_DEPLOY_URL'),
        );
    }

    public function requiredSecrets(Environment $environment): array
    {
        return [$this->secretOption($environment, 'secret', 'FORGE_DEPLOY_URL')];
    }

    public function tools(Environment $environment): array
    {
        return ['curl'];
    }

    public function deployCommands(Environment $environment): string
    {
        $secret = $this->secretOption($environment, 'secret', 'FORGE_DEPLOY_URL');

        return 'echo ' . Shell::quote("Triggering Forge deployment for {$environment->name}") . "\n"
            . 'curl -fsS --max-time 60 -X POST "$' . $secret . '"';
    }
}
