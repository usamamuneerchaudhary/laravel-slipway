<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Contracts\Driver;
use Usamamuneerchaudhary\Slipway\DriverManager;
use Usamamuneerchaudhary\Slipway\Model\Feature;
use Usamamuneerchaudhary\Slipway\Model\Pipeline;
use Usamamuneerchaudhary\Slipway\Model\Support;
use Usamamuneerchaudhary\Slipway\Slipway;
use Usamamuneerchaudhary\Slipway\Tests\Support\Fixture;

/**
 * The shared contract every Driver must satisfy, built-in or third-party.
 *
 * To test your own driver, extend this class and implement driver():
 *
 *     final class CircleCiConformanceTest extends DriverConformanceTestCase
 *     {
 *         protected function driver(): Driver { return new CircleCiDriver(); }
 *     }
 */
abstract class DriverConformanceTestCase extends TestCase
{
    abstract protected function driver(): Driver;

    /** A deliberately awkward secret-looking value that must never reach the output. */
    private const SENTINEL = 'conformance-sentinel-8f3a91';

    /** @return array<string,Pipeline> */
    private function pipelines(): array
    {
        $compiler = Slipway::compiler();
        $out = [];

        $out['minimal'] = $compiler->compile(Fixture::minimal(['drivers' => [$this->driver()->name() => true]]))->pipeline;
        $out['full'] = $compiler->compile(Fixture::full(['drivers' => [$this->driver()->name() => true]]))->pipeline;

        return $out;
    }

    private function render(Pipeline $pipeline): string
    {
        return $this->driver()->render($pipeline, ['enabled' => true, 'path' => null, 'raw' => [], 'options' => []]);
    }

    public function test_identity_is_well_formed(): void
    {
        $d = $this->driver();

        $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_-]*$/', $d->name());
        $this->assertNotEmpty($d->label());
        $this->assertDoesNotMatchRegularExpression('#^/|\.\.#', $d->defaultPath(), 'default path must be relative and inside the project');
    }

    public function test_it_declares_a_support_level_for_every_feature(): void
    {
        $caps = $this->driver()->capabilities();

        foreach (Feature::cases() as $feature) {
            $this->assertArrayHasKey($feature->value, $caps, "driver does not declare support for [{$feature->value}]");
            $this->assertInstanceOf(Support::class, $caps[$feature->value]);
        }
    }

    public function test_every_partial_or_missing_feature_is_explained(): void
    {
        $d = $this->driver();
        $notes = $d->notes();

        foreach ($d->capabilities() as $feature => $support) {
            if ($support !== Support::Full) {
                $this->assertArrayHasKey($feature, $notes, "[{$feature}] is {$support->value} but has no explanatory note");
                $this->assertNotEmpty($notes[$feature]);
            }
        }
    }

    public function test_it_renders_non_empty_output_for_simple_and_complete_pipelines(): void
    {
        foreach ($this->pipelines() as $name => $pipeline) {
            $this->assertNotEmpty(trim($this->render($pipeline)), "{$name} pipeline rendered nothing");
        }
    }

    public function test_rendering_is_deterministic(): void
    {
        foreach ($this->pipelines() as $name => $pipeline) {
            $this->assertSame($this->render($pipeline), $this->render($pipeline), "{$name} pipeline is not deterministic");
        }
    }

    public function test_output_is_text_without_tabs_or_trailing_whitespace(): void
    {
        foreach ($this->pipelines() as $name => $pipeline) {
            $yaml = $this->render($pipeline);

            $this->assertStringNotContainsString("\t", $yaml, "{$name}: tabs are not allowed in YAML indentation");
            $this->assertStringEndsWith("\n", $yaml);
            $this->assertDoesNotMatchRegularExpression('/[ ]+\n(?!\s*\n)/', preg_replace('/\|[+\-0-9]*\n(?:(?:[ ]{2,}.*)?\n)*/', '', $yaml) ?? $yaml, "{$name}: trailing whitespace outside block scalars");
        }
    }

    public function test_secrets_are_referenced_by_name_never_inlined(): void
    {
        $config = Fixture::full(['drivers' => [$this->driver()->name() => true]]);
        $config['env'] = ['APP_NOTE' => self::SENTINEL];
        $yaml = $this->render(Slipway::compiler()->compile($config)->pipeline);

        // The sentinel is plain config, so it may appear; the secret NAMES must not carry values.
        foreach (['SSH_PRIVATE_KEY', 'SSH_KNOWN_HOSTS', 'STAGING_URL', 'SLACK_WEBHOOK'] as $secret) {
            $this->assertDoesNotMatchRegularExpression('/\b' . $secret . ': ["\']?[A-Za-z0-9+\/=]{30,}/', $yaml, "{$secret} looks like it was given a literal value");
        }
    }

    public function test_every_job_in_the_pipeline_appears_in_the_output(): void
    {
        $pipeline = $this->pipelines()['full'];
        $yaml = $this->render($pipeline);

        foreach ($pipeline->jobs as $job) {
            $this->assertStringContainsString($job->name, $yaml, "job [{$job->id}] is missing from the output");
        }
    }

    public function test_it_rejects_unknown_driver_options_instead_of_ignoring_them(): void
    {
        $pipeline = $this->pipelines()['minimal'];

        try {
            $this->driver()->render($pipeline, ['enabled' => true, 'path' => null, 'raw' => [], 'options' => ['definitely_not_an_option' => true]]);
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);

            return;
        }

        $this->fail('Unknown options must raise InvalidArgumentException so typos do not pass silently.');
    }

    public function test_raw_overrides_are_applied(): void
    {
        $pipeline = $this->pipelines()['minimal'];
        $yaml = $this->driver()->render($pipeline, ['enabled' => true, 'path' => null, 'raw' => ['x-slipway-conformance' => ['marker' => 'present']], 'options' => []]);

        $this->assertStringContainsString('x-slipway-conformance:', $yaml);
        $this->assertStringContainsString('marker: present', $yaml);
    }

    public function test_it_registers_with_the_driver_manager(): void
    {
        $manager = new DriverManager();
        $manager->register($this->driver());

        $this->assertTrue($manager->has($this->driver()->name()));
        $this->assertSame($this->driver()->label(), $manager->get($this->driver()->name())->label());
    }
}
