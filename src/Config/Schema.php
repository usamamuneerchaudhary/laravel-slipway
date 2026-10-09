<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Config;

/**
 * Validates config/slipway.php and fills in defaults.
 *
 * Every problem is collected and reported together, prefixed with its config path
 * (e.g. "environments.production.via"). Unknown keys are errors with a
 * "did you mean" hint: typos are the most common configuration bug.
 *
 * The normalized output is a plain array consumed by PipelineBuilder.
 */
final class Schema
{
    public const array DEFAULT_EXTENSIONS = [
        'mbstring', 'dom', 'curl', 'libxml', 'zip', 'pcntl', 'pdo', 'bcmath', 'intl', 'exif', 'pdo_sqlite',
    ];

    public const array DEFAULT_ARTIFACT_PATHS = [
        'app/', 'bootstrap/', 'config/', 'database/', 'lang/', 'public/', 'resources/', 'routes/',
        'storage/', 'vendor/', 'artisan', 'composer.json', 'composer.lock',
    ];

    private const array TOP_KEYS = [
        'name', 'strict', 'guard', 'lockfile', 'artisan', 'drivers', 'php', 'extensions', 'node', 'triggers', 'concurrency', 'timeouts',
        'cache', 'env', 'stages', 'environments', 'artifact', 'policies', 'secrets', 'notifications', 'scan',
    ];

    private const array STAGE_OPTION_KEYS = [
        'steps', 'parallel', 'matrix', 'php', 'on', 'timeout', 'services', 'env', 'extensions',
    ];

    private const array ENV_KEYS = ['branch', 'tag', 'after', 'approval', 'via', 'verify', 'rollback', 'options'];

    private const array EVENTS = ['pull_request', 'push', 'tag', 'schedule', 'manual'];

    /** @var list<string> */
    private array $errors = [];

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     * @throws ConfigException
     */
    public function normalize(array $config): array
    {
        $this->errors = [];

        $this->checkKeys($config, self::TOP_KEYS, '');

        $out = [
            'name' => $this->string($config, 'name', 'name', 'CI'),
            'strict' => $this->bool($config, 'strict', 'strict', false),
            'guard' => $this->bool($config, 'guard', 'guard', true),
            'lockfile' => $this->bool($config, 'lockfile', 'lockfile', true),
            'artisan' => $this->artisan($config['artisan'] ?? 'php artisan'),
            'drivers' => $this->drivers($config['drivers'] ?? ['github' => ['enabled' => true]]),
            'php' => $this->phpVersions($config['php'] ?? ['8.3'], 'php'),
            'extensions' => $this->stringList($config['extensions'] ?? self::DEFAULT_EXTENSIONS, 'extensions'),
            'node' => $this->node($config['node'] ?? null),
            'triggers' => $this->triggers($config['triggers'] ?? []),
            'concurrency' => ['cancel_superseded' => $this->nestedBool($config, 'concurrency', 'cancel_superseded', true)],
            'timeouts' => ['default' => $this->timeout($config['timeouts']['default'] ?? 20, 'timeouts.default')],
            'cache' => $this->bool($config, 'cache', 'cache', true),
            'env' => $this->envMap($config['env'] ?? [], 'env'),
            'stages' => $this->stages($config['stages'] ?? null),
            'environments' => $this->environments($config['environments'] ?? []),
            'artifact' => ['paths' => $this->stringList($config['artifact']['paths'] ?? self::DEFAULT_ARTIFACT_PATHS, 'artifact.paths')],
            'policies' => $this->refList($config['policies'] ?? [], 'policies'),
            'secrets' => $this->secretNames($config['secrets'] ?? [], 'secrets'),
            'notifications' => $this->notifications($config['notifications'] ?? []),
            'scan' => ['ignore' => $this->stringList($config['scan']['ignore'] ?? [], 'scan.ignore')],
        ];

        $this->crossChecks($out);

        if ($this->errors !== []) {
            throw new ConfigException($this->errors);
        }

        return $out;
    }

    // ---------------------------------------------------------------------
    // Sections
    // ---------------------------------------------------------------------

    /** @return array<string,array{enabled:bool,path:?string,raw:array,options:array}> */
    private function drivers(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            $this->errors[] = 'drivers: must be a non-empty map of driver name => options.';

            return [];
        }

        $out = [];
        foreach ($value as $name => $opts) {
            $path = "drivers.{$name}";
            if (! is_string($name) || preg_match('/^[a-z][a-z0-9_-]*$/', $name) !== 1) {
                $this->errors[] = "drivers: driver key [{$name}] must be a lowercase identifier such as \"github\".";
                continue;
            }

            if ($opts === true || $opts === false) {
                $opts = ['enabled' => $opts];
            }

            if (! is_array($opts)) {
                $this->errors[] = "{$path}: must be an array of options or a boolean.";
                continue;
            }

            $this->checkKeys($opts, ['enabled', 'path', 'raw', 'options'], $path);

            $file = $opts['path'] ?? null;
            if ($file !== null) {
                if (! is_string($file) || $file === '') {
                    $this->errors[] = "{$path}.path: must be a non-empty string.";
                    $file = null;
                } elseif (str_starts_with($file, '/') || preg_match('#(^|/)\.\.(/|$)#', $file) === 1 || str_contains($file, "\0")) {
                    $this->errors[] = "{$path}.path: [{$file}] must be a relative path inside the project (no leading \"/\" or \"..\").";
                    $file = null;
                }
            }

            $raw = $opts['raw'] ?? [];
            if (! is_array($raw)) {
                $this->errors[] = "{$path}.raw: must be an array merged into the generated YAML.";
                $raw = [];
            }

            $driverOptions = $opts['options'] ?? [];
            if (! is_array($driverOptions)) {
                $this->errors[] = "{$path}.options: must be an array of driver-specific settings.";
                $driverOptions = [];
            }

            $out[$name] = [
                'enabled' => $this->bool($opts, 'enabled', "{$path}.enabled", true),
                'path' => $file,
                'raw' => $raw,
                'options' => $driverOptions,
            ];
        }

        return $out;
    }

    private function artisan(mixed $value): string
    {
        if (! is_string($value) || preg_match('#^[A-Za-z0-9._/-]+( [A-Za-z0-9._/-]+)*$#', $value) !== 1) {
            $this->errors[] = 'artisan: must be a plain command such as "php artisan" or "vendor/bin/testbench".';

            return 'php artisan';
        }

        return $value;
    }

    /** @return list<string> */
    private function phpVersions(mixed $value, string $path): array
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            $this->errors[] = "{$path}: must be a non-empty list of PHP versions, e.g. ['8.3', '8.4'].";

            return [];
        }

        $out = [];
        foreach ($value as $i => $v) {
            if (is_int($v) || is_float($v)) {
                $v = (string) $v;
            }

            if (! is_string($v) || preg_match('/^\d+\.\d+$/', $v) !== 1) {
                $this->errors[] = "{$path}.{$i}: [" . $this->show($v) . "] is not a valid PHP version (use strings like \"8.3\").";
                continue;
            }

            $out[$v] = $v;
        }

        $versions = array_values($out);
        usort($versions, 'version_compare');

        return $versions;
    }

    private function node(mixed $value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match('/^\d+(\.\d+){0,2}$/', $value) !== 1) {
            $this->errors[] = 'node: must be null or a version such as "22".';

            return null;
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function triggers(mixed $value): array
    {
        if (! is_array($value)) {
            $this->errors[] = 'triggers: must be an array.';
            $value = [];
        }

        $this->checkKeys($value, ['push', 'tags', 'pull_request', 'schedule', 'manual', 'paths_ignore'], 'triggers');

        $push = $value['push'] ?? ['main'];
        $push = $push === false ? [] : $this->stringList($push, 'triggers.push');

        $pr = $value['pull_request'] ?? true;
        $prBranches = [];
        if (is_array($pr)) {
            $prBranches = $this->stringList($pr, 'triggers.pull_request');
            $pr = true;
        } elseif (! is_bool($pr)) {
            $this->errors[] = 'triggers.pull_request: must be true, false or a list of target branches.';
            $pr = false;
        }

        $schedule = $this->stringList($value['schedule'] ?? [], 'triggers.schedule');
        foreach ($schedule as $i => $cron) {
            if (preg_match('/^\S+( \S+){4}$/', $cron) !== 1) {
                $this->errors[] = "triggers.schedule.{$i}: [{$cron}] is not a 5-field cron expression.";
            }
        }

        return [
            'push' => $push,
            'tags' => $this->stringList($value['tags'] ?? [], 'triggers.tags'),
            'pull_request' => $pr,
            'pull_request_branches' => $prBranches,
            'schedule' => $schedule,
            'manual' => $this->bool($value, 'manual', 'triggers.manual', false),
            'paths_ignore' => $this->stringList($value['paths_ignore'] ?? [], 'triggers.paths_ignore'),
        ];
    }

    /** @return array<string,array{steps:list<array<string,mixed>>,options:array<string,mixed>}> */
    private function stages(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            $this->errors[] = 'stages: required. Define at least one stage, e.g. [\'test\' => [\'pest\']].';

            return [];
        }

        $out = [];
        foreach ($value as $name => $def) {
            $path = "stages.{$name}";
            if (! is_string($name) || preg_match('/^[a-z][a-z0-9_-]*$/', $name) !== 1) {
                $this->errors[] = "stages: stage name [{$name}] must be lowercase letters, digits, \"-\" or \"_\".";
                continue;
            }

            if (str_starts_with($name, 'deploy-')) {
                $this->errors[] = "{$path}: the \"deploy-\" prefix is reserved for generated environment stages.";
                continue;
            }

            $options = [];
            if (is_array($def) && array_key_exists('steps', $def)) {
                $this->checkKeys($def, self::STAGE_OPTION_KEYS, $path);
                $options = $def;
                unset($options['steps']);
                $entries = $def['steps'];
            } else {
                $entries = $def;
            }

            $out[$name] = [
                'steps' => $this->stageEntries($entries, "{$path}"),
                'options' => $this->stageOptions($options, $path),
            ];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function stageEntries(mixed $entries, string $path): array
    {
        if (! is_array($entries) || $entries === []) {
            $this->errors[] = "{$path}: must be a non-empty list of steps.";

            return [];
        }

        $out = [];
        foreach ($entries as $key => $entry) {
            $at = "{$path}." . $key;

            if (is_int($key) && is_string($entry)) {
                $out[] = ['kind' => 'ref', 'ref' => $entry, 'options' => []];
            } elseif (is_string($key) && is_array($entry)) {
                $out[] = ['kind' => 'ref', 'ref' => $key, 'options' => $entry];
            } elseif (is_string($key) && $entry === true) {
                $out[] = ['kind' => 'ref', 'ref' => $key, 'options' => []];
            } elseif (is_int($key) && is_array($entry) && isset($entry['run'])) {
                $out[] = $this->inlineStep($entry, $at);
            } else {
                $this->errors[] = "{$at}: a step must be a class/alias string, \"alias => [options]\", or an inline [\"run\" => \"command\"] array.";
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function inlineStep(array $entry, string $path): array
    {
        $this->checkKeys($entry, ['run', 'name', 'env', 'secrets'], $path);

        $run = $entry['run'] ?? null;
        if (! is_string($run) || trim($run) === '') {
            $this->errors[] = "{$path}.run: must be a non-empty command string.";
            $run = '';
        }

        return [
            'kind' => 'inline',
            'run' => $run,
            'name' => $this->string($entry, 'name', "{$path}.name", $this->firstLine($run)),
            'env' => $this->envMap($entry['env'] ?? [], "{$path}.env"),
            'secrets' => $this->secretNames($entry['secrets'] ?? [], "{$path}.secrets"),
        ];
    }

    /** @return array<string,mixed> */
    private function stageOptions(array $options, string $path): array
    {
        $out = [
            'parallel' => $this->bool($options, 'parallel', "{$path}.parallel", false),
            'matrix' => $this->bool($options, 'matrix', "{$path}.matrix", false),
            'php' => null,
            'on' => null,
            'timeout' => null,
            'services' => $this->stringList($options['services'] ?? [], "{$path}.services"),
            'env' => $this->envMap($options['env'] ?? [], "{$path}.env"),
            'extensions' => isset($options['extensions']) ? $this->stringList($options['extensions'], "{$path}.extensions") : null,
        ];

        if (array_key_exists('php', $options)) {
            $php = $options['php'];
            if ($php === false) {
                $out['php'] = false;
            } else {
                $versions = $this->phpVersions([$php], "{$path}.php");
                $out['php'] = $versions[0] ?? null;
            }
        }

        if (isset($options['on'])) {
            $on = $this->stringList($options['on'], "{$path}.on");
            foreach ($on as $event) {
                if (! in_array($event, self::EVENTS, true)) {
                    $this->errors[] = "{$path}.on: unknown event [{$event}]. Use: " . implode(', ', self::EVENTS) . '.';
                }
            }
            $out['on'] = $on;
        }

        if (isset($options['timeout'])) {
            $out['timeout'] = $this->timeout($options['timeout'], "{$path}.timeout");
        }

        return $out;
    }

    /** @return array<string,array<string,mixed>> */
    private function environments(mixed $value): array
    {
        if (! is_array($value)) {
            $this->errors[] = 'environments: must be a map of environment name => definition.';

            return [];
        }

        $out = [];
        foreach ($value as $name => $def) {
            $path = "environments.{$name}";
            if (! is_string($name) || preg_match('/^[a-z][a-z0-9_-]*$/', $name) !== 1) {
                $this->errors[] = "environments: name [{$name}] must be lowercase letters, digits, \"-\" or \"_\".";
                continue;
            }

            if (! is_array($def)) {
                $this->errors[] = "{$path}: must be an array.";
                continue;
            }

            $this->checkKeys($def, self::ENV_KEYS, $path);

            $via = $def['via'] ?? null;
            if (! is_string($via) || $via === '') {
                $this->errors[] = "{$path}.via: required. Name a deployer alias (ssh, vapor, forge, envoyer, webhook, command) or a Deployer class.";
                $via = '';
            }

            $options = $def['options'] ?? [];
            if (! is_array($options)) {
                $this->errors[] = "{$path}.options: must be an array.";
                $options = [];
            }

            $out[$name] = [
                'branch' => $this->optString($def, 'branch', "{$path}.branch"),
                'tag' => $this->optString($def, 'tag', "{$path}.tag"),
                'after' => $this->optString($def, 'after', "{$path}.after"),
                'approval' => $this->bool($def, 'approval', "{$path}.approval", false),
                'via' => $via,
                'verify' => $this->verify($def['verify'] ?? null, "{$path}.verify"),
                'rollback' => $this->bool($def, 'rollback', "{$path}.rollback", false),
                'options' => $options,
            ];
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function verify(mixed $value, string $path): ?array
    {
        if ($value === null || $value === false) {
            return null;
        }

        if (! is_array($value)) {
            $this->errors[] = "{$path}: must be false or an array like ['path' => '/up'].";

            return null;
        }

        $this->checkKeys($value, ['url', 'url_secret', 'path', 'expect', 'retries', 'delay'], $path);

        $url = $this->optString($value, 'url', "{$path}.url");
        $urlSecret = $this->optString($value, 'url_secret', "{$path}.url_secret");

        if ($url !== null && preg_match('#^https?://[^\s]+$#', $url) !== 1) {
            $this->errors[] = "{$path}.url: [{$url}] must be an http(s) URL.";
        }

        if ($url === null && $urlSecret === null) {
            $this->errors[] = "{$path}: set either \"url\" (a public URL) or \"url_secret\" (the NAME of a secret holding the base URL).";
        }

        if ($url !== null && $urlSecret !== null) {
            $this->errors[] = "{$path}: set \"url\" or \"url_secret\", not both.";
        }

        if ($urlSecret !== null && preg_match('/^[A-Z][A-Z0-9_]*$/', $urlSecret) !== 1) {
            $this->errors[] = "{$path}.url_secret: [{$urlSecret}] must be an UPPER_SNAKE_CASE secret name.";
        }

        $p = $value['path'] ?? '/up';
        if (! is_string($p) || ! str_starts_with($p, '/') || preg_match('/[\s\'"`$;&|<>\\\\]/', $p) === 1) {
            $this->errors[] = "{$path}.path: [" . $this->show($p) . '] must be a path starting with "/" and without spaces or shell characters.';
            $p = '/up';
        }

        $expect = $value['expect'] ?? 200;
        if (! is_int($expect) || $expect < 100 || $expect > 599) {
            $this->errors[] = "{$path}.expect: must be an HTTP status code (100-599).";
            $expect = 200;
        }

        $retries = $value['retries'] ?? 5;
        if (! is_int($retries) || $retries < 1 || $retries > 60) {
            $this->errors[] = "{$path}.retries: must be an integer from 1 to 60.";
            $retries = 5;
        }

        $delay = $value['delay'] ?? 10;
        if (! is_int($delay) || $delay < 1 || $delay > 300) {
            $this->errors[] = "{$path}.delay: must be an integer number of seconds from 1 to 300.";
            $delay = 10;
        }

        return [
            'url' => $url,
            'url_secret' => $urlSecret,
            'path' => $p,
            'expect' => $expect,
            'retries' => $retries,
            'delay' => $delay,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function notifications(mixed $value): array
    {
        if (! is_array($value)) {
            $this->errors[] = 'notifications: must be a map such as [\'slack\' => [\'secret\' => \'SLACK_WEBHOOK\']].';

            return [];
        }

        $out = [];
        foreach ($value as $kind => $cfg) {
            $path = "notifications.{$kind}";
            if (! in_array($kind, ['slack', 'teams'], true)) {
                $this->errors[] = "notifications: unknown channel [{$kind}]. Supported: slack, teams.";
                continue;
            }

            if (! is_array($cfg)) {
                $this->errors[] = "{$path}: must be an array.";
                continue;
            }

            $this->checkKeys($cfg, ['secret', 'on'], $path);

            $secret = $cfg['secret'] ?? null;
            if (! is_string($secret) || preg_match('/^[A-Z][A-Z0-9_]*$/', $secret) !== 1) {
                $this->errors[] = "{$path}.secret: required. The NAME of the secret holding the webhook URL (UPPER_SNAKE_CASE), never the URL itself.";
                $secret = '';
            }

            $on = $this->stringList($cfg['on'] ?? ['failure'], "{$path}.on");
            foreach ($on as $when) {
                if (! in_array($when, ['success', 'failure'], true)) {
                    $this->errors[] = "{$path}.on: [{$when}] must be \"success\" or \"failure\".";
                }
            }

            $out[$kind] = ['secret' => $secret, 'on' => $on];
        }

        return $out;
    }

    /** @param array<string,mixed> $out */
    private function crossChecks(array $out): void
    {
        $envs = $out['environments'];
        $names = array_keys($envs);

        foreach ($envs as $name => $env) {
            $path = "environments.{$name}";

            if ($env['branch'] === null && $env['tag'] === null && $env['after'] === null) {
                $this->errors[] = "{$path}: needs a \"branch\", a \"tag\" or an \"after\" (the environment it follows).";
            }

            if ($env['after'] !== null) {
                $pos = array_search($name, $names, true);
                $afterPos = array_search($env['after'], $names, true);

                if ($afterPos === false) {
                    $this->errors[] = "{$path}.after: [{$env['after']}] is not a defined environment.";
                } elseif ($afterPos >= $pos) {
                    $this->errors[] = "{$path}.after: [{$env['after']}] must be declared before [{$name}] in the environments array.";
                }
            }

            if ($env['branch'] !== null && $out['triggers']['push'] !== []
                && ! $this->branchCovered($env['branch'], $out['triggers']['push'])) {
                $this->errors[] = "{$path}.branch: [{$env['branch']}] is not matched by triggers.push (" . implode(', ', $out['triggers']['push']) . '), so this environment could never deploy.';
            }

            if ($env['branch'] !== null && $out['triggers']['push'] === []) {
                $this->errors[] = "{$path}.branch: triggers.push is disabled, so this environment could never deploy.";
            }

            if ($env['tag'] !== null && $out['triggers']['tags'] === []) {
                $this->errors[] = "{$path}.tag: add the tag pattern to triggers.tags so tag pushes start the pipeline.";
            }
        }

        $seen = [];
        foreach ($out['secrets'] as $secret) {
            if (isset($seen[$secret])) {
                $this->errors[] = "secrets: [{$secret}] is declared twice.";
            }
            $seen[$secret] = true;
        }
    }

    // ---------------------------------------------------------------------
    // Primitives
    // ---------------------------------------------------------------------

    /** @param list<string> $patterns */
    private function branchCovered(string $branch, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $branch || fnmatch($pattern, $branch)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $allowed */
    private function checkKeys(array $array, array $allowed, string $path): void
    {
        foreach (array_keys($array) as $key) {
            if (in_array($key, $allowed, true)) {
                continue;
            }

            $where = $path === '' ? '' : "{$path}: ";
            $hint = $this->suggest((string) $key, $allowed);
            $this->errors[] = $where . "unknown key [{$key}]" . ($hint !== null ? " (did you mean [{$hint}]?)" : '.');
        }
    }

    /** @param list<string> $candidates */
    private function suggest(string $key, array $candidates): ?string
    {
        $best = null;
        $bestDistance = 4;
        foreach ($candidates as $candidate) {
            $d = levenshtein(strtolower($key), $candidate);
            if ($d < $bestDistance) {
                $best = $candidate;
                $bestDistance = $d;
            }
        }

        return $best;
    }

    private function string(array $source, string $key, string $path, string $default): string
    {
        $v = $source[$key] ?? $default;
        if (! is_string($v) || trim($v) === '') {
            $this->errors[] = "{$path}: must be a non-empty string.";

            return $default;
        }

        return $v;
    }

    private function optString(array $source, string $key, string $path): ?string
    {
        if (! array_key_exists($key, $source) || $source[$key] === null) {
            return null;
        }

        if (! is_string($source[$key]) || trim($source[$key]) === '') {
            $this->errors[] = "{$path}: must be a non-empty string when set.";

            return null;
        }

        return $source[$key];
    }

    private function bool(array $source, string $key, string $path, bool $default): bool
    {
        if (! array_key_exists($key, $source)) {
            return $default;
        }

        if (! is_bool($source[$key])) {
            $this->errors[] = "{$path}: must be true or false.";

            return $default;
        }

        return $source[$key];
    }

    private function nestedBool(array $config, string $section, string $key, bool $default): bool
    {
        $block = $config[$section] ?? [];
        if (! is_array($block)) {
            $this->errors[] = "{$section}: must be an array.";

            return $default;
        }

        $this->checkKeys($block, [$key], $section);

        return $this->bool($block, $key, "{$section}.{$key}", $default);
    }

    private function timeout(mixed $value, string $path): int
    {
        if (! is_int($value) || $value < 1 || $value > 360) {
            $this->errors[] = "{$path}: must be an integer number of minutes from 1 to 360.";

            return 20;
        }

        return $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->errors[] = "{$path}: must be a list of strings.";

            return [];
        }

        $out = [];
        foreach ($value as $i => $item) {
            if (! is_string($item) || trim($item) === '' || preg_match('/[\x00-\x1f]/', $item) === 1) {
                $this->errors[] = "{$path}.{$i}: must be a non-empty single-line string.";
                continue;
            }
            $out[] = $item;
        }

        return $out;
    }

    /** @return list<string> */
    private function secretNames(mixed $value, string $path): array
    {
        $names = $this->stringList($value, $path);
        foreach ($names as $name) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $name) !== 1) {
                $this->errors[] = "{$path}: [{$name}] must be an UPPER_SNAKE_CASE secret NAME (declare names only, never values).";
            }
        }

        return $names;
    }

    /** @return array<string,string> */
    private function envMap(mixed $value, string $path): array
    {
        if (! is_array($value)) {
            $this->errors[] = "{$path}: must be a map of NAME => value.";

            return [];
        }

        $out = [];
        foreach ($value as $name => $v) {
            if (! is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                $this->errors[] = "{$path}: [{$name}] is not a valid environment variable name.";
                continue;
            }

            if (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            }

            if (! is_scalar($v)) {
                $this->errors[] = "{$path}.{$name}: must be a string, number or boolean.";
                continue;
            }

            $out[$name] = (string) $v;
        }

        return $out;
    }

    /** @return list<array{ref:string,options:array<string,mixed>}> */
    private function refList(mixed $value, string $path): array
    {
        if (! is_array($value)) {
            $this->errors[] = "{$path}: must be a list.";

            return [];
        }

        $out = [];
        foreach ($value as $key => $entry) {
            if (is_int($key) && is_string($entry)) {
                $out[] = ['ref' => $entry, 'options' => []];
            } elseif (is_string($key) && is_array($entry)) {
                $out[] = ['ref' => $key, 'options' => $entry];
            } else {
                $this->errors[] = "{$path}.{$key}: must be a class/alias string or \"alias => [options]\".";
            }
        }

        return $out;
    }

    private function firstLine(string $text): string
    {
        $line = trim(strtok($text, "\n") ?: $text);

        return mb_strlen($line) > 60 ? mb_substr($line, 0, 57) . '...' : $line;
    }

    private function show(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : get_debug_type($v);
    }
}
