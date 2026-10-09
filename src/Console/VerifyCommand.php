<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Console;

use Usamamuneerchaudhary\Slipway\Compiler;
use Usamamuneerchaudhary\Slipway\Health\HealthChecker;

final class VerifyCommand extends SlipwayCommand
{
    protected $signature = 'slipway:verify
        {environment : Environment name from the configuration}
        {--url= : Base URL to check (default: the environment\'s configured url, or the env var named by url_secret)}
        {--config= : Path to a configuration file (default: config/slipway.php)}';

    protected $description = 'Run an environment\'s post-deploy health check from this machine';

    public function handle(Compiler $compiler): int
    {
        $located = $this->locate();
        if ($located === null) {
            return self::FAILURE;
        }

        $result = $this->compile($compiler, $located, false);
        if ($result === null) {
            return self::FAILURE;
        }

        $name = (string) $this->argument('environment');
        $environment = $result->pipeline->environments[$name] ?? null;

        if ($environment === null) {
            $this->error(sprintf('Unknown environment [%s]. Defined: %s.', $name, implode(', ', array_keys($result->pipeline->environments)) ?: '(none)'));

            return self::FAILURE;
        }

        $verify = $environment->verify;
        if ($verify === null) {
            $this->error("Environment [{$name}] has no \"verify\" health check configured.");

            return self::FAILURE;
        }

        $url = $this->option('url');
        $url = is_string($url) && $url !== '' ? $url : ($verify['url'] ?? null);

        if ($url === null && $verify['url_secret'] !== null) {
            $fromEnv = getenv($verify['url_secret']);
            $url = $fromEnv === false || $fromEnv === '' ? null : $fromEnv;
        }

        if ($url === null) {
            $this->error('No URL to check. Pass --url, or export ' . ($verify['url_secret'] ?? 'the URL') . ' in your shell.');

            return self::FAILURE;
        }

        $checker = new HealthChecker(null, null, fn (string $line) => $this->line('  ' . $line));

        try {
            $health = $checker->check($url, $verify['path'], $verify['expect'], $verify['retries'], $verify['delay']);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($health->ok) {
            $this->info("{$name} is healthy ({$health->url}).");

            return self::SUCCESS;
        }

        $this->error("{$name} failed verification after {$health->attempts} attempt(s); last status " . ($health->lastStatus === 0 ? 'unreachable' : $health->lastStatus) . '.');

        return self::FAILURE;
    }
}
