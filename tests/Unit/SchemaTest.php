<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Config\ConfigException;
use Usamamuneerchaudhary\Slipway\Config\Schema;
use Usamamuneerchaudhary\Slipway\Tests\Support\Fixture;

final class SchemaTest extends TestCase
{
    /** @return list<string> */
    private function errorsFor(array $config): array
    {
        try {
            (new Schema())->normalize($config);
        } catch (ConfigException $e) {
            return $e->errors;
        }

        return [];
    }

    private function assertHasError(string $needle, array $config): void
    {
        $errors = $this->errorsFor($config);
        $this->assertNotEmpty($errors, 'Expected validation errors but the configuration was accepted.');
        $this->assertStringContainsString($needle, implode("\n", $errors));
    }

    public function test_minimal_configuration_is_valid_and_gets_defaults(): void
    {
        $n = (new Schema())->normalize(Fixture::minimal());

        $this->assertSame('CI', $n['name']);
        $this->assertSame(false, $n['strict']);
        $this->assertSame(true, $n['guard']);
        $this->assertSame('php artisan', $n['artisan']);
        $this->assertSame(true, $n['concurrency']['cancel_superseded']);
        $this->assertSame(20, $n['timeouts']['default']);
        $this->assertSame(['8.3'], $n['php']);
    }

    public function test_all_errors_are_reported_together(): void
    {
        $errors = $this->errorsFor(['php' => ['eight'], 'stages' => [], 'triggers' => ['push' => 5]]);

        $this->assertGreaterThanOrEqual(3, count($errors));
    }

    public function test_unknown_keys_are_rejected_with_a_suggestion(): void
    {
        $this->assertHasError('unknown key [stagess] (did you mean [stages]?)', Fixture::minimal(['stagess' => []]));
        $this->assertHasError('unknown key [paralel] (did you mean [parallel]?)', Fixture::minimal([
            'stages' => ['test' => ['steps' => ['pest'], 'paralel' => true]],
        ]));
    }

    public function test_php_versions_are_validated_and_sorted(): void
    {
        $n = (new Schema())->normalize(Fixture::minimal(['php' => ['8.4', '8.2', '8.10', '8.3']]));
        $this->assertSame(['8.2', '8.3', '8.4', '8.10'], $n['php']);

        $this->assertHasError('not a valid PHP version', Fixture::minimal(['php' => ['latest']]));
        $this->assertHasError('non-empty list', Fixture::minimal(['php' => []]));
    }

    public function test_stages_are_required(): void
    {
        $this->assertHasError('stages: required', Fixture::minimal(['stages' => []]));
    }

    public function test_the_deploy_prefix_is_reserved_for_generated_stages(): void
    {
        $this->assertHasError('reserved', Fixture::minimal(['stages' => ['deploy-eu' => ['pest']]]));
    }

    public function test_names_with_a_trailing_newline_are_rejected(): void
    {
        $this->assertHasError('must be lowercase', Fixture::minimal(['stages' => ["qa\n" => ['pest']]]));
        $this->assertHasError('secrets', Fixture::minimal(['secrets' => ["STAGING_URL\n"]]));
    }

    public function test_stage_names_must_be_safe_identifiers(): void
    {
        $this->assertHasError('must be lowercase', Fixture::minimal(['stages' => ['My Stage' => ['pest']]]));
    }

    public function test_steps_accept_aliases_options_and_inline_commands(): void
    {
        $n = (new Schema())->normalize(Fixture::minimal(['stages' => ['qa' => [
            'pint',
            'larastan' => ['level' => 6],
            ['run' => 'php artisan lint:routes', 'name' => 'Lint routes'],
        ]]]));

        $kinds = array_map(static fn (array $s): string => $s['kind'], $n['stages']['qa']['steps']);
        $this->assertSame(['ref', 'ref', 'inline'], $kinds);
        $this->assertSame(['level' => 6], $n['stages']['qa']['steps'][1]['options']);
    }

    public function test_inline_steps_require_a_command(): void
    {
        $this->assertHasError('run: must be a non-empty command', Fixture::minimal(['stages' => ['qa' => [['run' => '  ']]]]));
    }

    public function test_driver_output_paths_must_stay_inside_the_project(): void
    {
        foreach (['/etc/cron.d/x', '../outside.yml', 'a/../../b.yml'] as $path) {
            $this->assertHasError('relative path inside the project', Fixture::minimal(['drivers' => ['github' => ['path' => $path]]]));
        }
    }

    public function test_driver_can_be_given_as_a_boolean(): void
    {
        $n = (new Schema())->normalize(Fixture::minimal(['drivers' => ['github' => true, 'gitlab' => false]]));

        $this->assertTrue($n['drivers']['github']['enabled']);
        $this->assertFalse($n['drivers']['gitlab']['enabled']);
    }

    public function test_cron_expressions_need_five_fields(): void
    {
        $this->assertHasError('not a 5-field cron', Fixture::minimal(['triggers' => ['schedule' => ['every monday']]]));
    }

    public function test_secret_names_must_be_upper_snake_case(): void
    {
        $this->assertHasError('UPPER_SNAKE_CASE', Fixture::minimal(['secrets' => ['my-token']]));
        $this->assertHasError('declared twice', Fixture::minimal(['secrets' => ['A_B', 'A_B']]));
    }

    public function test_environment_needs_a_deployer_and_a_trigger(): void
    {
        $this->assertHasError('via: required', Fixture::minimal(['environments' => ['staging' => ['branch' => 'main']]]));
        $this->assertHasError('needs a "branch", a "tag" or an "after"', Fixture::minimal(['environments' => ['staging' => ['via' => 'ssh']]]));
    }

    public function test_after_must_reference_an_earlier_environment(): void
    {
        $env = static fn (array $extra): array => $extra + ['via' => 'ssh'];

        $this->assertHasError('not a defined environment', Fixture::minimal(['environments' => ['prod' => $env(['after' => 'ghost'])]]));
        $this->assertHasError('must be declared before', Fixture::minimal(['environments' => [
            'prod' => $env(['after' => 'staging']),
            'staging' => $env(['branch' => 'main']),
        ]]));
    }

    public function test_branch_must_be_covered_by_push_triggers(): void
    {
        $this->assertHasError('could never deploy', Fixture::minimal([
            'triggers' => ['push' => ['develop']],
            'environments' => ['prod' => ['branch' => 'main', 'via' => 'ssh']],
        ]));
    }

    public function test_tag_environments_need_tag_triggers(): void
    {
        $this->assertHasError('add the tag pattern to triggers.tags', Fixture::minimal([
            'environments' => ['prod' => ['tag' => 'v*', 'via' => 'ssh']],
        ]));
    }

    public function test_verify_needs_exactly_one_url_source(): void
    {
        $base = ['branch' => 'main', 'via' => 'ssh'];

        $this->assertHasError('set either "url"', Fixture::minimal(['environments' => ['s' => $base + ['verify' => ['path' => '/up']]]]));
        $this->assertHasError('not both', Fixture::minimal(['environments' => ['s' => $base + ['verify' => ['url' => 'https://a.test', 'url_secret' => 'A']]]]));
        $this->assertHasError('must be an http(s) URL', Fixture::minimal(['environments' => ['s' => $base + ['verify' => ['url' => 'ftp://a.test']]]]));
    }

    public function test_verify_path_cannot_carry_shell_metacharacters(): void
    {
        foreach (['/up; rm -rf /', "/up'", '/up$(x)', '/a b', 'up'] as $path) {
            $this->assertHasError('verify.path', Fixture::minimal(['environments' => ['s' => [
                'branch' => 'main', 'via' => 'ssh', 'verify' => ['url' => 'https://a.test', 'path' => $path],
            ]]]));
        }
    }

    public function test_verify_numbers_are_bounded(): void
    {
        $verify = static fn (array $v): array => Fixture::minimal(['environments' => ['s' => [
            'branch' => 'main', 'via' => 'ssh', 'verify' => ['url' => 'https://a.test'] + $v,
        ]]]);

        $this->assertHasError('verify.expect', $verify(['expect' => 99]));
        $this->assertHasError('verify.retries', $verify(['retries' => 0]));
        $this->assertHasError('verify.delay', $verify(['delay' => 999]));
    }

    public function test_notifications_take_a_secret_name_never_a_url(): void
    {
        $this->assertHasError('never the URL itself', Fixture::minimal(['notifications' => ['slack' => ['secret' => 'https://hooks.slack.com/x']]]));
        $this->assertHasError('unknown channel [discord]', Fixture::minimal(['notifications' => ['discord' => ['secret' => 'X']]]));
    }

    public function test_artisan_command_must_be_plain(): void
    {
        $this->assertHasError('artisan:', Fixture::minimal(['artisan' => 'php artisan; curl evil']));
        $n = (new Schema())->normalize(Fixture::minimal(['artisan' => 'vendor/bin/testbench']));
        $this->assertSame('vendor/bin/testbench', $n['artisan']);
    }

    public function test_env_variable_names_are_validated(): void
    {
        $this->assertHasError('not a valid environment variable name', Fixture::minimal(['env' => ['bad name' => '1']]));
    }

    public function test_env_booleans_become_strings(): void
    {
        $n = (new Schema())->normalize(Fixture::minimal(['env' => ['FEATURE' => true, 'RETRY' => 3]]));

        $this->assertSame(['FEATURE' => 'true', 'RETRY' => '3'], $n['env']);
    }

    public function test_timeouts_are_bounded(): void
    {
        $this->assertHasError('timeouts.default', Fixture::minimal(['timeouts' => ['default' => 0]]));
        $this->assertHasError('timeouts.default', Fixture::minimal(['timeouts' => ['default' => 9999]]));
    }
}
