<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Health;

use InvalidArgumentException;

/**
 * Post-deploy HTTP health check ("continuous verification"): poll a URL until it returns the
 * expected status or the retries run out. Mirrors the shell function generated into pipelines
 * (see VerifyScript), so `php artisan slipway:verify` behaves like the pipeline's own check.
 */
final class HealthChecker
{
    /** @var callable(string):int */
    private $fetch;

    /** @var callable(int):void */
    private $sleep;

    /** @var callable(string):void */
    private $log;

    /**
     * @param (callable(string):int)|null $fetch Returns the HTTP status, or 0 when unreachable.
     * @param (callable(int):void)|null   $sleep
     * @param (callable(string):void)|null $log
     */
    public function __construct(?callable $fetch = null, ?callable $sleep = null, ?callable $log = null)
    {
        $this->fetch = $fetch ?? [$this, 'httpStatus'];
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->log = $log ?? static function (string $line): void {
        };
    }

    public function check(string $baseUrl, string $path = '/up', int $expect = 200, int $retries = 5, int $delay = 10): HealthResult
    {
        if (preg_match('#^https?://[^\s]+$#D', $baseUrl) !== 1) {
            throw new InvalidArgumentException('The health check URL must be an http(s) URL.');
        }

        if (! str_starts_with($path, '/')) {
            throw new InvalidArgumentException('The health check path must start with "/".');
        }

        if ($retries < 1) {
            throw new InvalidArgumentException('retries must be at least 1.');
        }

        $url = rtrim($baseUrl, '/') . $path;
        $status = 0;

        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            $status = ($this->fetch)($url);

            if ($status === $expect) {
                ($this->log)("Verified: {$url} returned HTTP {$status}");

                return new HealthResult(true, $attempt, $status, $url);
            }

            ($this->log)(sprintf('Attempt %d/%d: got HTTP %s, expected %d', $attempt, $retries, $status === 0 ? '000' : (string) $status, $expect));

            if ($attempt < $retries) {
                ($this->sleep)($delay);
            }
        }

        return new HealthResult(false, $retries, $status, $url);
    }

    private function httpStatus(string $url): int
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            curl_exec($ch);

            // The handle is freed when it goes out of scope; curl_close() is deprecated since PHP 8.5.
            return (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        }

        $context = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true, 'follow_location' => 0]]);
        @file_get_contents($url, false, $context);

        // http_get_last_response_headers() replaces the $http_response_header magic variable (PHP 8.4+).
        $headers = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : ($http_response_header ?? null);

        foreach ($headers ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                return (int) $m[1];
            }
        }

        return 0;
    }
}
