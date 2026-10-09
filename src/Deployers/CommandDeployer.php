<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Deployers;

use Usamamuneerchaudhary\Slipway\Model\Environment;

/**
 * Escape hatch: deploy by running your own POSIX sh commands.
 *
 * Options:
 *   commands           list of shell commands (required)
 *   rollback_commands  list of shell commands that undo the deploy (enables `rollback => true`)
 *   secrets            list of secret NAMES the commands read as $NAME
 *   tools              any of: curl, ssh, rsync, git
 *   php                true if the job needs PHP + Composer dependencies installed
 *   artifact           true to download the build artifact instead of checking out the repo
 */
final class CommandDeployer extends AbstractDeployer
{
    private const array KNOWN = ['commands', 'rollback_commands', 'secrets', 'tools', 'php', 'artifact'];
    private const array TOOLS = ['curl', 'ssh', 'rsync', 'git'];

    public function validate(Environment $environment): array
    {
        $o = $environment->options;
        $errors = $this->unknownOptions($o, self::KNOWN);

        foreach (['commands' => true, 'rollback_commands' => false] as $key => $required) {
            if (! isset($o[$key])) {
                if ($required) {
                    $errors[] = "option [{$key}] is required";
                }
                continue;
            }

            if (! is_array($o[$key]) || $o[$key] === [] || ! array_is_list($o[$key])) {
                $errors[] = "option [{$key}] must be a non-empty list of commands";
                continue;
            }

            foreach ($o[$key] as $i => $cmd) {
                if (! is_string($cmd) || trim($cmd) === '' || str_contains($cmd, "\0")) {
                    $errors[] = "option [{$key}.{$i}] must be a non-empty string";
                }
            }
        }

        foreach ($o['secrets'] ?? [] as $i => $name) {
            if (! is_string($name) || preg_match('/^[A-Z][A-Z0-9_]*$/D', $name) !== 1) {
                $errors[] = "option [secrets.{$i}] must be an UPPER_SNAKE_CASE secret name";
            }
        }

        foreach ($o['tools'] ?? [] as $i => $tool) {
            if (! in_array($tool, self::TOOLS, true)) {
                $errors[] = "option [tools.{$i}] must be one of: " . implode(', ', self::TOOLS);
            }
        }

        foreach (['php', 'artifact'] as $flag) {
            if (isset($o[$flag]) && ! is_bool($o[$flag])) {
                $errors[] = "option [{$flag}] must be true or false";
            }
        }

        return $errors;
    }

    public function requiredSecrets(Environment $environment): array
    {
        return array_values($environment->options['secrets'] ?? []);
    }

    public function tools(Environment $environment): array
    {
        return array_values($environment->options['tools'] ?? []);
    }

    public function needsPhp(Environment $environment): bool
    {
        return ($environment->options['php'] ?? false) === true;
    }

    public function usesArtifact(Environment $environment): bool
    {
        return ($environment->options['artifact'] ?? false) === true;
    }

    public function deployCommands(Environment $environment): string
    {
        return implode("\n", $environment->options['commands'] ?? []);
    }

    public function supportsRollback(Environment $environment): bool
    {
        return isset($environment->options['rollback_commands']);
    }

    public function rollbackCommands(Environment $environment): ?string
    {
        return $this->supportsRollback($environment)
            ? implode("\n", $environment->options['rollback_commands'])
            : null;
    }
}
