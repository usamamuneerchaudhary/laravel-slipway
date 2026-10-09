<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Deployers;

use Usamamuneerchaudhary\Slipway\Contracts\Deployer;
use Usamamuneerchaudhary\Slipway\Health\VerifyScript;
use Usamamuneerchaudhary\Slipway\Model\Environment;

/**
 * Composes ONE portable script per environment: deploy, then verify, then roll back on failure.
 *
 * It is a single script (not several steps) on purpose: "run X if the previous step failed"
 * is expressed differently on every CI provider, but `if ! verify; then rollback; fi` is
 * plain POSIX sh and behaves identically everywhere.
 */
final class DeployScript
{
    public static function compose(Environment $environment, Deployer $deployer): string
    {
        $parts = [
            'set -eu',
            '',
            'echo ' . "'=== Deploying to {$environment->name} ==='",
            $deployer->deployCommands($environment),
        ];

        if ($environment->verify !== null) {
            $parts[] = '';
            $parts[] = VerifyScript::render($environment->verify);
            $parts[] = '';

            $rollback = $environment->rollback ? $deployer->rollbackCommands($environment) : null;

            if ($rollback !== null) {
                $parts[] = 'if slipway_verify; then';
                $parts[] = '  echo "Deployment verified."';
                $parts[] = 'else';
                $parts[] = '  echo "Verification failed; rolling back." >&2';
                $parts[] = self::indent($rollback);
                $parts[] = '  exit 1';
                $parts[] = 'fi';
            } else {
                $parts[] = 'if ! slipway_verify; then';
                $parts[] = '  echo "Verification failed." >&2';
                $parts[] = '  exit 1';
                $parts[] = 'fi';
            }
        }

        return implode("\n", $parts) . "\n";
    }

    private static function indent(string $script): string
    {
        // Heredoc bodies must stay at column 0, so only indent lines outside heredocs.
        $out = [];
        $terminator = null;
        foreach (explode("\n", $script) as $line) {
            if ($terminator !== null) {
                $out[] = $line;
                if ($line === $terminator) {
                    $terminator = null;
                }
                continue;
            }

            $out[] = $line === '' ? '' : '  ' . $line;

            if (preg_match("/<<'?\"?([A-Za-z_][A-Za-z0-9_]*)'?\"?\\s*$/", $line, $m) === 1) {
                $terminator = $m[1];
            }
        }

        return implode("\n", $out);
    }
}
