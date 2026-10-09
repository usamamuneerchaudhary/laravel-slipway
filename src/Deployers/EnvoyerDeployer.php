<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Deployers;

use Usamamuneerchaudhary\Slipway\Model\Environment;
use Usamamuneerchaudhary\Slipway\Support\Shell;

/**
 * Envoyer: calls the project's deployment hook URL. Envoyer pulls from Git, so the CI
 * build artifact is not used by this deployer.
 *
 * Options: secret (default ENVOYER_HOOK_URL), the NAME of the secret holding the hook URL.
 */
final class EnvoyerDeployer extends AbstractDeployer
{
    public function validate(Environment $environment): array
    {
        return array_merge(
            $this->unknownOptions($environment->options, ['secret']),
            $this->validSecretName($environment, 'secret', 'ENVOYER_HOOK_URL'),
        );
    }

    public function requiredSecrets(Environment $environment): array
    {
        return [$this->secretOption($environment, 'secret', 'ENVOYER_HOOK_URL')];
    }

    public function tools(Environment $environment): array
    {
        return ['curl'];
    }

    public function deployCommands(Environment $environment): string
    {
        $secret = $this->secretOption($environment, 'secret', 'ENVOYER_HOOK_URL');

        return 'echo ' . Shell::quote("Triggering Envoyer deployment for {$environment->name}") . "\n"
            . 'curl -fsS --max-time 60 "$' . $secret . '"';
    }
}
