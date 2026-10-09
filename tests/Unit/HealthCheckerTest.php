<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Health\HealthChecker;

final class HealthCheckerTest extends TestCase
{
    /** @param list<int> $statuses */
    private function checker(array $statuses, array &$sleeps = [], array &$lines = [], array &$urls = []): HealthChecker
    {
        return new HealthChecker(
            static function (string $url) use (&$statuses, &$urls): int {
                $urls[] = $url;

                return array_shift($statuses) ?? 0;
            },
            static function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );
    }

    public function test_it_passes_on_the_first_expected_status(): void
    {
        $sleeps = [];
        $result = $this->checker([200], $sleeps)->check('https://app.test', '/up', 200, 5, 10);

        $this->assertTrue($result->ok);
        $this->assertSame(1, $result->attempts);
        $this->assertSame([], $sleeps, 'must not wait after success');
    }

    public function test_it_retries_with_the_configured_delay_until_healthy(): void
    {
        $sleeps = [];
        $result = $this->checker([503, 0, 200], $sleeps)->check('https://app.test', '/up', 200, 5, 7);

        $this->assertTrue($result->ok);
        $this->assertSame(3, $result->attempts);
        $this->assertSame([7, 7], $sleeps);
    }

    public function test_it_gives_up_after_the_retries_and_reports_the_last_status(): void
    {
        $sleeps = [];
        $result = $this->checker([500, 500, 502], $sleeps)->check('https://app.test', '/up', 200, 3, 1);

        $this->assertFalse($result->ok);
        $this->assertSame(3, $result->attempts);
        $this->assertSame(502, $result->lastStatus);
        $this->assertSame([1, 1], $sleeps, 'no sleep after the final attempt');
    }

    public function test_it_builds_the_url_without_double_slashes(): void
    {
        $urls = [];
        $result = $this->checker([200], urls: $urls)->check('https://app.test/', '/up');

        $this->assertSame(['https://app.test/up'], $urls);
        $this->assertSame('https://app.test/up', $result->url);
    }

    public function test_a_custom_expected_status_is_honoured(): void
    {
        $this->assertTrue($this->checker([204])->check('https://app.test', '/up', 204, 1, 1)->ok);
        $this->assertFalse($this->checker([200])->check('https://app.test', '/up', 204, 1, 1)->ok);
    }

    public function test_progress_is_logged(): void
    {
        $lines = [];
        $this->checker([500, 200], lines: $lines)->check('https://app.test', '/up', 200, 3, 1);

        $this->assertStringContainsString('Attempt 1/3: got HTTP 500, expected 200', $lines[0]);
        $this->assertStringContainsString('Verified: https://app.test/up returned HTTP 200', $lines[1]);
    }

    public function test_unreachable_hosts_are_logged_as_000(): void
    {
        $lines = [];
        $this->checker([0], lines: $lines)->check('https://app.test', '/up', 200, 1, 1);

        $this->assertStringContainsString('got HTTP 000', $lines[0]);
    }

    public function test_it_rejects_non_http_urls_and_bad_paths(): void
    {
        foreach ([['file:///etc/passwd', '/up'], ['ftp://a.test', '/up'], ['https://a.test', 'up'], ['javascript:alert(1)', '/up']] as [$url, $path]) {
            try {
                $this->checker([200])->check($url, $path);
                $this->fail("accepted {$url} {$path}");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_at_least_one_attempt_is_required(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->checker([200])->check('https://app.test', '/up', 200, 0, 1);
    }
}
