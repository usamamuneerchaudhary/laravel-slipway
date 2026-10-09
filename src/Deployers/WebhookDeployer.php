<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Deployers;

use Usamamuneerchaudhary\Slipway\Model\Environment;
use Usamamuneerchaudhary\Slipway\Support\Shell;

/**
 * Generic HTTP webhook deployer for any platform that deploys on a URL call.
 *
 * Options:
 *   secret           NAME of the secret holding the deploy URL (default DEPLOY_WEBHOOK_URL)
 *   rollback_secret  optional NAME of a secret holding a rollback URL; enables `rollback => true`
 *
 * The request is a POST with a small JSON body: {"environment", "sha", "ref"}.
 */
final class WebhookDeployer extends AbstractDeployer
{
    public function validate(Environment $environment): array
    {
        $errors = array_merge(
            $this->unknownOptions($environment->options, ['secret', 'rollback_secret']),
            $this->validSecretName($environment, 'secret', 'DEPLOY_WEBHOOK_URL'),
        );

        if (isset($environment->options['rollback_secret'])) {
            $errors = array_merge($errors, $this->validSecretName($environment, 'rollback_secret', ''));
        }

        return $errors;
    }

    public function requiredSecrets(Environment $environment): array
    {
        $secrets = [$this->secretOption($environment, 'secret', 'DEPLOY_WEBHOOK_URL')];

        if (isset($environment->options['rollback_secret']) && is_string($environment->options['rollback_secret'])) {
            $secrets[] = $environment->options['rollback_secret'];
        }

        return $secrets;
    }

    public function tools(Environment $environment): array
    {
        return ['curl'];
    }

    public function deployCommands(Environment $environment): string
    {
        return $this->call($environment, $this->secretOption($environment, 'secret', 'DEPLOY_WEBHOOK_URL'));
    }

    public function supportsRollback(Environment $environment): bool
    {
        return isset($environment->options['rollback_secret']);
    }

    public function rollbackCommands(Environment $environment): ?string
    {
        return $this->supportsRollback($environment)
            ? $this->call($environment, (string) $environment->options['rollback_secret'])
            : null;
    }

    private function call(Environment $environment, string $secret): string
    {
        // Branch names may legally contain quotes and backslashes, so JSON-escape them.
        $template = <<<'SH'
json_escape() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'; }
printf '{"environment":"%s","sha":"%s","ref":"%s"}' __NAME__ "$(json_escape "$SLIPWAY_SHA")" "$(json_escape "$SLIPWAY_REF")" > webhook-payload.json
curl -fsS --max-time 60 -X POST -H 'Content-Type: application/json' --data @webhook-payload.json "$__SECRET__"
SH;

        return strtr($template, [
            '__NAME__' => Shell::quote($environment->name),
            '__SECRET__' => $secret,
        ]);
    }
}
