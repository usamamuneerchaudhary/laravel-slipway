<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Drivers;

use InvalidArgumentException;
use Usamamuneerchaudhary\Slipway\Contracts\Driver;
use Usamamuneerchaudhary\Slipway\Model\Job;
use Usamamuneerchaudhary\Slipway\Model\Service;
use Usamamuneerchaudhary\Slipway\Model\Step;
use Usamamuneerchaudhary\Slipway\Support\Shell;

abstract class AbstractDriver implements Driver
{
    /** Name of the single build artifact shared between jobs (where the provider names it). */
    protected const ARTIFACT_NAME = 'slipway-build';

    /**
     * Env vars for a job: user env, then service connection settings.
     *
     * @param callable(Service):string $hostFor Host name that reaches a service from the job.
     * @return array<string,string>
     */
    protected function jobEnv(Job $job, callable $hostFor): array
    {
        $env = $job->env;

        foreach ($job->services as $service) {
            $host = $hostFor($service);
            foreach ($service->appEnv as $name => $value) {
                $env[$name] = str_replace('{host}', $host, $value);
            }
        }

        return $env;
    }

    /**
     * Translate a neutral artifact path ("vendor/" = directory) to glob syntax ("vendor/**").
     */
    protected function globPath(string $path): string
    {
        return str_ends_with($path, '/') ? $path . '**' : $path;
    }

    /** Neutral directory path without its trailing slash ("vendor/" => "vendor"). */
    protected function bareDir(string $path): string
    {
        return rtrim($path, '/');
    }

    /**
     * Recursive merge for the driver's `raw` overrides: maps merge, lists and scalars replace.
     *
     * @param array<mixed> $base
     * @param array<mixed> $override
     * @return array<mixed>
     */
    protected function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])
                && ! array_is_list($value) && ! array_is_list($base[$key])) {
                $base[$key] = $this->deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * @param array<string,mixed> $given
     * @param list<string>        $allowed
     */
    protected function assertOptions(array $given, array $allowed): void
    {
        foreach (array_keys($given) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException(sprintf(
                    '%s: unknown option [%s]; allowed: %s',
                    $this->name(),
                    $key,
                    implode(', ', $allowed)
                ));
            }
        }
    }

    /** @param array<string,mixed> $options */
    protected function stringOption(array $options, string $key, string $default): string
    {
        $value = $options[$key] ?? $default;
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("{$this->name()}: option [{$key}] must be a non-empty string.");
        }

        return $value;
    }

    /**
     * Reject commands that GitHub would pre-process as expressions, which is the classic
     * script-injection vector. Secrets must be declared on the step, not interpolated.
     */
    protected function assertNoExpressions(Step $step): void
    {
        if ($step->run !== null && str_contains($step->run, '${{')) {
            throw new InvalidArgumentException(
                "Step [{$step->name}] contains a \"\${{ }}\" expression. Pass secrets via the step's \"secrets\" and read them as \$NAME instead."
            );
        }
    }

    /**
     * The shell commands of a job's `success` steps, one script item per step.
     *
     * @return list<string>
     */
    protected function successScripts(Job $job): array
    {
        $out = [];
        foreach ($job->steps as $step) {
            if ($step->type->value === 'run' && $step->runOn === 'success') {
                $out[] = rtrim((string) $step->run, "\n") . "\n";
            }
        }

        return $out;
    }

    /**
     * Setup commands for providers that run jobs in plain containers (Bitbucket, GitLab):
     * PHP extensions and Composer for PHP jobs, apk packages for shell-only jobs, plus Node.
     *
     * @param list<string>|null $override Lines replacing the built-in bootstrap (driver option).
     */
    protected function containerSetup(Job $job, bool $hasPhp, ?array $override): string
    {
        if ($override !== null) {
            $lines = $override;
        } elseif ($hasPhp) {
            $lines = [PhpBootstrap::script($job->extensions, $job->coverage, PhpBootstrap::aptPackagesFor($job->tools))];
            $job->node !== null && $lines[] = $this->nodeInstall($job->node);
        } elseif ($job->tools !== []) {
            $lines = [PhpBootstrap::alpineScript($job->tools)];
        } else {
            $lines = [];
        }

        return $lines === [] ? '' : $this->failFast(implode("\n", $lines));
    }

    /**
     * Multi-line script items must abort on the first failing command. GitLab only checks the
     * LAST command's exit code of a multi-line item, and Bitbucket's behaviour is not guaranteed,
     * so make it explicit. Single commands are already checked, and scripts that set their own
     * errexit (the deploy script) are left alone.
     */
    protected function failFast(string $script): string
    {
        $script = rtrim($script, "\n");

        if (! str_contains($script, "\n") || preg_match('/^\s*set\s+-[a-z]*e/', $script) === 1) {
            return $script . "\n";
        }

        return "set -e\n" . $script . "\n";
    }

    protected function nodeInstall(string $version): string
    {
        $major = explode('.', $version)[0];

        return 'curl -fsSL https://deb.nodesource.com/setup_' . $major . ".x | bash -\napt-get install -y -qq nodejs";
    }

    /** @param array<string,string> $env */
    protected function shellExports(array $env): string
    {
        $lines = [];
        foreach ($env as $name => $value) {
            $lines[] = 'export ' . $name . '=' . Shell::quote($value);
        }

        return implode("\n", $lines);
    }

    /**
     * Shell that waits (up to 60s) until each service port accepts connections.
     *
     * @param list<int> $ports
     */
    protected function waitForPorts(array $ports, string $host): string
    {
        $template = <<<'SH'
for port in __PORTS__; do
  tries=0
  until php -r 'exit(@fsockopen($argv[1], (int) $argv[2]) ? 0 : 1);' __HOST__ "$port"; do
    tries=$((tries + 1))
    if [ "$tries" -ge 60 ]; then
      echo "Service on port $port did not start in time" >&2
      exit 1
    fi
    sleep 1
  done
done
SH;

        return strtr($template, ['__PORTS__' => implode(' ', $ports), '__HOST__' => Shell::quote($host)]) . "\n";
    }
}
