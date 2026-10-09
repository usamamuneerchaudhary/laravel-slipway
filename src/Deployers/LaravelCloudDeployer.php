<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Deployers;

use Usamamuneerchaudhary\Slipway\Model\Environment;
use Usamamuneerchaudhary\Slipway\Support\Shell;

/**
 * Laravel Cloud: calls the environment's deploy hook (Settings > Deployments > Deploy hook).
 * Laravel Cloud pulls the code from Git and runs its own build and deploy commands, so the CI
 * build artifact is not used by this deployer.
 *
 * Options:
 *   secret   NAME of the secret holding the deploy hook URL (default LARAVEL_CLOUD_DEPLOY_HOOK)
 *   commit   default true: pass `commit_hash` so Cloud deploys exactly the commit CI just tested.
 *            false deploys the latest commit of the environment's branch.
 *
 * Turn off Cloud's own push-to-deploy for the environment, or Cloud deploys before CI has passed.
 * The hook has no rollback; `verify` still works.
 */
final class LaravelCloudDeployer extends AbstractDeployer
{
    private const string DEFAULT_SECRET = 'LARAVEL_CLOUD_DEPLOY_HOOK';

    public function validate(Environment $environment): array
    {
        $errors = array_merge(
            $this->unknownOptions($environment->options, ['secret', 'commit']),
            $this->validSecretName($environment, 'secret', self::DEFAULT_SECRET),
        );

        if (isset($environment->options['commit']) && ! is_bool($environment->options['commit'])) {
            $errors[] = 'option [commit] must be true or false';
        }

        return $errors;
    }

    public function requiredSecrets(Environment $environment): array
    {
        return [$this->secretOption($environment, 'secret', self::DEFAULT_SECRET)];
    }

    public function tools(Environment $environment): array
    {
        return ['curl'];
    }

    public function deployCommands(Environment $environment): string
    {
        $secret = $this->secretOption($environment, 'secret', self::DEFAULT_SECRET);
        $echo = 'echo ' . Shell::quote("Triggering Laravel Cloud deployment for {$environment->name}");

        if (($environment->options['commit'] ?? true) === false) {
            return $echo . "\n" . 'curl -fsS --max-time 60 -X POST "$' . $secret . '"';
        }

        $template = <<<'SH'
__ECHO__
case "$__SECRET__" in
  *\?*) SLIPWAY_HOOK_JOIN='&' ;;
  *) SLIPWAY_HOOK_JOIN='?' ;;
esac
curl -fsS --max-time 60 -X POST "${__SECRET__}${SLIPWAY_HOOK_JOIN}commit_hash=${SLIPWAY_SHA}"
SH;

        return strtr($template, ['__ECHO__' => $echo, '__SECRET__' => $secret]);
    }
}
