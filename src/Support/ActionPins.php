<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Support;

use RuntimeException;

/**
 * Finds the GitHub Actions a generated workflow uses and resolves their version tags to full
 * commit SHAs, so they can be pinned through `drivers.github.options.pins`.
 *
 * A tag such as `v4` can be moved by whoever controls the action's repository; a commit SHA cannot.
 */
final class ActionPins
{
    /** @var callable(string,string):?string */
    private $lookup;

    /**
     * @param (callable(string $action, string $ref):?string)|null $lookup Returns the commit SHA for a tag, or null if the tag does not exist.
     */
    public function __construct(?callable $lookup = null)
    {
        $this->lookup = $lookup ?? [$this, 'lookupWithGit'];
    }

    /**
     * Every `uses: owner/name@ref` in a workflow, by action name.
     *
     * @return array<string,string> action => ref
     */
    public static function used(string $workflow): array
    {
        preg_match_all('/^\s*(?:-\s+)?uses:\s*([A-Za-z0-9_.-]+\/[A-Za-z0-9_.\/-]+)@(\S+)\s*$/m', $workflow, $matches, PREG_SET_ORDER);

        $used = [];
        foreach ($matches as $match) {
            $used[$match[1]] = $match[2];
        }
        ksort($used);

        return $used;
    }

    /**
     * Actions that are referenced by a mutable tag or branch instead of a commit SHA.
     *
     * @return array<string,string> action => ref
     */
    public static function unpinned(string $workflow): array
    {
        return array_filter(self::used($workflow), static fn (string $ref): bool => ! self::isCommitSha($ref));
    }

    public static function isCommitSha(string $ref): bool
    {
        return preg_match('/^[0-9a-f]{40}$/D', $ref) === 1;
    }

    /**
     * @throws RuntimeException When the tag cannot be resolved.
     */
    public function resolve(string $action, string $ref): string
    {
        if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $action) !== 1) {
            throw new RuntimeException("[{$action}] is not a plain owner/name action reference.");
        }

        if (preg_match('/^[A-Za-z0-9._-]+$/D', $ref) !== 1) {
            throw new RuntimeException("[{$ref}] is not a plain tag name.");
        }

        $sha = ($this->lookup)($action, $ref);

        if ($sha === null || ! self::isCommitSha($sha)) {
            throw new RuntimeException("Could not resolve {$action}@{$ref} to a commit SHA.");
        }

        return $sha;
    }

    /** @internal */
    public function lookupWithGit(string $action, string $ref): ?string
    {
        $process = @proc_open(
            ['git', 'ls-remote', '--tags', "https://github.com/{$action}.git", "refs/tags/{$ref}", "refs/tags/{$ref}^{}"],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['GIT_TERMINAL_PROMPT' => '0', 'PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')],
        );

        if (! is_resource($process)) {
            throw new RuntimeException('git is required to resolve action versions.');
        }

        $output = (string) stream_get_contents($pipes[1]);
        $error = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            throw new RuntimeException("git ls-remote failed for {$action}: " . ($error !== '' ? $error : 'unknown error'));
        }

        return self::parseLsRemote($output, $ref);
    }

    /** Prefers the peeled (`^{}`) commit of an annotated tag over the tag object itself. */
    public static function parseLsRemote(string $output, string $ref): ?string
    {
        $plain = null;

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if (! is_array($fields) || count($fields) !== 2) {
                continue;
            }

            [$sha, $name] = $fields;

            if ($name === "refs/tags/{$ref}^{}") {
                return $sha;
            }

            if ($name === "refs/tags/{$ref}") {
                $plain = $sha;
            }
        }

        return $plain;
    }
}
