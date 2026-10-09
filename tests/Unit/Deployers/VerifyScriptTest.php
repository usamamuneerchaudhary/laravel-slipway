<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit\Deployers;

use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Health\VerifyScript;
use Usamamuneerchaudhary\Slipway\Support\NotificationScript;
use Usamamuneerchaudhary\Slipway\Tests\Support\ShellHarness;

final class VerifyScriptTest extends TestCase
{
    private ShellHarness $h;

    protected function setUp(): void
    {
        if (ShellHarness::shell() === null) {
            $this->markTestSkipped('No POSIX shell available.');
        }
        $this->h = new ShellHarness();
    }

    protected function tearDown(): void
    {
        $this->h->cleanup();
    }

    private function verify(array $overrides = []): array
    {
        return array_merge(['url' => 'https://app.example.test/', 'url_secret' => null, 'path' => '/up', 'expect' => 200, 'retries' => 3, 'delay' => 1], $overrides);
    }

    public function test_it_succeeds_on_the_expected_status(): void
    {
        $r = $this->h->run(VerifyScript::render($this->verify()) . "\nslipway_verify\n", ['MOCK_HTTP' => '200']);

        $this->assertSame(0, $r['code']);
        $this->assertStringContainsString('Verified: https://app.example.test/up returned HTTP 200', $r['out']);
    }

    public function test_it_fails_after_the_configured_number_of_attempts(): void
    {
        $r = $this->h->run(VerifyScript::render($this->verify(['retries' => 2])) . "\nslipway_verify\n", ['MOCK_HTTP' => '500']);

        $this->assertSame(1, $r['code']);
        $this->assertStringContainsString('attempt 1/2', $r['out']);
        $this->assertStringContainsString('attempt 2/2', $r['out']);
        $this->assertStringNotContainsString('attempt 3', $r['out']);
    }

    public function test_it_honours_a_custom_expected_status(): void
    {
        $r = $this->h->run(VerifyScript::render($this->verify(['expect' => 204, 'retries' => 1])) . "\nslipway_verify\n", ['MOCK_HTTP' => '204']);

        $this->assertSame(0, $r['code']);
    }

    public function test_the_base_url_can_come_from_a_secret_and_a_trailing_slash_is_tolerated(): void
    {
        $script = VerifyScript::render($this->verify(['url' => null, 'url_secret' => 'APP_URL'])) . "\nslipway_verify\n";
        $r = $this->h->run($script, ['MOCK_HTTP' => '200', 'APP_URL' => 'https://secret.example.test/']);

        $this->assertSame(0, $r['code'], $r['out']);
        $this->assertStringContainsString('https://secret.example.test/up', $r['out']);
    }

    public function test_the_generated_function_is_valid_sh_even_with_awkward_urls(): void
    {
        $script = VerifyScript::render($this->verify(['url' => "https://a.test/it's"])) . "\nslipway_verify\n";
        $r = $this->h->run($script, ['MOCK_HTTP' => '200']);

        $this->assertSame(0, $r['code'], $r['out']);
    }

    public function test_notifications_produce_valid_json_for_slack_and_teams(): void
    {
        $this->h->tool('curl', "#!/bin/sh\nfor a; do case \"\$a\" in @*) cat \"\${a#@}\" > \"\$MOCK_LOG.payload\";; esac; done\n");

        foreach (['slack', 'teams'] as $channel) {
            $script = NotificationScript::render($channel, 'HOOK');
            $r = $this->h->run($script, [
                'HOOK' => 'https://hooks.test/x',
                'SLIPWAY_JOB' => 'Deploy: "prod"',
                'SLIPWAY_PIPELINE' => 'CI',
                'SLIPWAY_STATUS' => 'FAILED',
                'SLIPWAY_REF' => 'feature/"x"\\y',
                'SLIPWAY_REPO' => 'acme/app',
                'SLIPWAY_RUN_URL' => 'https://ci.test/run/1',
            ]);

            $this->assertSame(0, $r['code'], "{$channel}: " . $r['out']);
            $payload = json_decode((string) file_get_contents($this->h->log . '.payload'), true);
            $this->assertIsArray($payload, "{$channel} payload must be valid JSON");
            $strings = [];
            array_walk_recursive($payload, static function ($value) use (&$strings): void {
                if (is_string($value)) {
                    $strings[] = $value;
                }
            });
            $this->assertStringContainsString('feature/"x"\\y', implode("\n", $strings), "{$channel}: ref must survive JSON escaping");
        }
    }

    public function test_a_failing_notification_never_fails_the_job(): void
    {
        $this->h->tool('curl', "#!/bin/sh\nexit 22\n");

        $r = $this->h->run(NotificationScript::render('slack', 'HOOK'), [
            'HOOK' => 'https://hooks.test/x', 'SLIPWAY_JOB' => 'J', 'SLIPWAY_PIPELINE' => 'P', 'SLIPWAY_STATUS' => 'FAILED',
            'SLIPWAY_REF' => 'main', 'SLIPWAY_REPO' => 'a/b', 'SLIPWAY_RUN_URL' => 'https://x.test',
        ]);

        $this->assertSame(0, $r['code']);
        $this->assertStringContainsString('Notification failed (ignored)', $r['out']);
    }
}
