<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Security;

/**
 * Lightweight credential detection.
 *
 * It catches the obvious, high-confidence leaks (cloud keys, tokens, private keys, webhook URLs,
 * committed .env files) without any external download. It is a first line of defence, not a
 * replacement for dedicated tooling such as gitleaks or GitHub secret scanning.
 *
 * Findings never include the matched value. A line containing "slipway-scan-ignore" is skipped.
 */
final class SecretScanner
{
    private const array PATTERNS = [
        'AWS access key ID' => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        'GitHub token' => '/\bgh[pousr]_[A-Za-z0-9]{36,}\b/',
        'GitHub fine-grained token' => '/\bgithub_pat_[A-Za-z0-9_]{50,}\b/',
        'GitLab token' => '/\bglpat-[A-Za-z0-9_\-]{20,}\b/',
        'Slack token' => '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/',
        'Slack webhook URL' => '#https://hooks\.slack\.com/(?:services|workflows)/[A-Za-z0-9/_-]{20,}#',
        'Teams webhook URL' => '#https://[A-Za-z0-9.-]+\.(?:webhook\.office\.com|logic\.azure\.com)/[A-Za-z0-9/@._?=&%-]{20,}#',
        'Private key' => '/-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP |ENCRYPTED )?PRIVATE KEY(?: BLOCK)?-----/',
        'Stripe live key' => '/\b[sr]k_live_[0-9a-zA-Z]{24,}\b/',
        'Google API key' => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
        'npm token' => '/\bnpm_[A-Za-z0-9]{36}\b/',
    ];

    private const string SENSITIVE_NAME = '/(?:PASSWORD|PASSWD|SECRET|TOKEN|PRIVATE_KEY|API_KEY|ACCESS_KEY|CREDENTIALS?)$/i';

    private const array COMMITTED_FILES = ['.env', '.env.production', '.env.staging', '.env.local', 'id_rsa', 'id_ed25519', 'id_ecdsa'];

    private const array SKIP_DIRS = ['.git', 'vendor', 'node_modules', 'storage', 'bootstrap/cache', 'public/build', 'public/hot'];

    private const int MAX_FILE_BYTES = 1_048_576;

    /** @param list<string> $ignorePrefixes Relative path prefixes excluded from file scans. */
    public function __construct(private readonly array $ignorePrefixes = [])
    {
    }

    /** @return list<Finding> */
    public function scanString(string $text, string $location): array
    {
        $findings = [];
        $lineNo = 0;

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $lineNo++;

            if (str_contains($line, 'slipway-scan-ignore')) {
                continue;
            }

            foreach (self::PATTERNS as $type => $regex) {
                if (preg_match($regex, $line) === 1) {
                    $findings[] = new Finding($type, "{$location}:{$lineNo}");
                }
            }
        }

        return $findings;
    }

    /**
     * Scan a (raw or normalized) config array: every string value for credential patterns, and
     * every `env` map for sensitive-looking names carrying literal values.
     *
     * @param array<mixed> $config
     * @return list<Finding>
     */
    public function scanConfig(array $config, string $prefix = 'config'): array
    {
        $findings = [];

        foreach ($config as $key => $value) {
            $path = $prefix . '.' . $key;

            if (is_array($value)) {
                if ($key === 'env' || $key === 'variables') {
                    $findings = array_merge($findings, $this->scanEnvMap($value, $path));
                }
                $findings = array_merge($findings, $this->scanConfig($value, $path));
                continue;
            }

            if (is_string($value)) {
                $findings = array_merge($findings, $this->scanString($value, $path));
            }
        }

        return $findings;
    }

    /**
     * @param array<mixed> $env
     * @return list<Finding>
     */
    private function scanEnvMap(array $env, string $path): array
    {
        $findings = [];

        foreach ($env as $name => $value) {
            if (! is_string($name) || ! is_scalar($value) || (string) $value === '') {
                continue;
            }

            $text = (string) $value;
            $isReference = str_starts_with($text, '$') || str_contains($text, '${');

            if (preg_match(self::SENSITIVE_NAME, $name) === 1 && ! $isReference) {
                $findings[] = new Finding(
                    'Literal value for sensitive variable',
                    "{$path}.{$name}",
                    'Declare it under "secrets" and read it as $' . $name . ' in the step.'
                );
            }
        }

        return $findings;
    }

    /**
     * Walk a project directory looking for committed credentials.
     *
     * @return list<Finding>
     */
    public function scanDirectory(string $root): array
    {
        $root = rtrim($root, '/\\');

        $files = $this->repositoryFiles($root);
        if ($files !== null) {
            return $this->scanFiles($root, $files);
        }

        $findings = [];

        foreach (self::COMMITTED_FILES as $name) {
            if (is_file($root . '/' . $name) && ! $this->ignored($name)) {
                $findings[] = new Finding(
                    'Committed credentials file',
                    $name,
                    'Remove it from the repository, add it to .gitignore, and rotate anything it contained.'
                );
            }
        }

        $this->walk($root, '', $findings);

        return $findings;
    }

    /**
     * Files git considers part of the project: tracked, plus untracked files that are not
     * gitignored. A developer's local, gitignored `.env` is therefore never reported as "committed".
     *
     * @return list<string>|null Relative paths, or null when $root is not a git work tree (or git is unavailable).
     */
    private function repositoryFiles(string $root): ?array
    {
        if (! file_exists($root . '/.git') || ! function_exists('proc_open')) {
            return null;
        }

        $process = @proc_open(
            ['git', '-C', $root, 'ls-files', '-z', '--cached', '--others', '--exclude-standard'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            return null;
        }

        $output = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            return null;
        }

        return array_values(array_filter(explode("\0", $output), static fn (string $path): bool => $path !== ''));
    }

    /**
     * @param list<string> $files Relative paths from repositoryFiles().
     * @return list<Finding>
     */
    private function scanFiles(string $root, array $files): array
    {
        $findings = [];

        foreach ($files as $rel) {
            $full = $root . '/' . $rel;

            if (! is_file($full) || is_link($full) || $this->skippedDir($rel) || $this->ignored($rel)) {
                continue;
            }

            if (in_array(basename($rel), self::COMMITTED_FILES, true)) {
                $findings[] = new Finding(
                    'Committed credentials file',
                    $rel,
                    'Remove it from the repository, add it to .gitignore, and rotate anything it contained.'
                );
            }

            foreach ($this->scanFile($full, $rel) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function scanFile(string $full, string $rel): array
    {
        $size = @filesize($full);
        if ($size === false || $size === 0 || $size > self::MAX_FILE_BYTES) {
            return [];
        }

        $contents = @file_get_contents($full);
        if ($contents === false || str_contains(substr($contents, 0, 8000), "\0")) {
            return []; // unreadable or binary
        }

        return $this->scanString($contents, $rel);
    }

    /** @param list<Finding> $findings */
    private function walk(string $root, string $relative, array &$findings): void
    {
        $dir = $relative === '' ? $root : $root . '/' . $relative;
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $rel = $relative === '' ? $entry : $relative . '/' . $entry;
            $full = $root . '/' . $rel;

            if (is_link($full) || $this->skippedDir($rel) || $this->ignored($rel)) {
                continue;
            }

            if (is_dir($full)) {
                $this->walk($root, $rel, $findings);
                continue;
            }

            foreach ($this->scanFile($full, $rel) as $finding) {
                $findings[] = $finding;
            }
        }
    }

    private function skippedDir(string $relative): bool
    {
        foreach (self::SKIP_DIRS as $dir) {
            if ($relative === $dir || str_starts_with($relative, $dir . '/')) {
                return true;
            }
        }

        return false;
    }

    private function ignored(string $relative): bool
    {
        foreach ($this->ignorePrefixes as $prefix) {
            $prefix = trim($prefix);
            if ($prefix !== '' && ($relative === $prefix || str_starts_with($relative, rtrim($prefix, '/') . '/'))) {
                return true;
            }
        }

        return false;
    }
}
