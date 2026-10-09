<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit\Policies;

use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Config\ConfigException;
use Usamamuneerchaudhary\Slipway\Contracts\Policy;
use Usamamuneerchaudhary\Slipway\Model\Pipeline;
use Usamamuneerchaudhary\Slipway\Policies\Violation;
use Usamamuneerchaudhary\Slipway\Slipway;
use Usamamuneerchaudhary\Slipway\Tests\Support\Fixture;

final class PolicyTest extends TestCase
{
    private function config(array $policies, callable $mutate = null): array
    {
        $config = Fixture::full(['policies' => $policies, 'drivers' => ['github' => true]]);
        if ($mutate !== null) {
            $config = $mutate($config);
        }

        return $config;
    }

    private function errors(array $config): string
    {
        try {
            Fixture::compile($config);
        } catch (ConfigException $e) {
            return implode("\n", $e->errors);
        }

        return '';
    }

    public function test_a_fully_compliant_pipeline_passes_every_built_in_policy(): void
    {
        $this->assertSame('', $this->errors($this->config(['audit-before-production', 'tests-before-deploy', 'approval-for-production', 'verify-production', 'rollback-for-production'])));
    }

    public function test_audit_before_production(): void
    {
        $config = $this->config(['audit-before-production'], static function (array $c): array {
            $c['stages']['quality'] = ['pint'];

            return $c;
        });

        $this->assertStringContainsString("Environment [production] can be deployed without a Composer audit", $this->errors($config));
    }

    public function test_audit_policy_can_target_other_environments(): void
    {
        $config = $this->config(['audit-before-production' => ['environments' => ['staging']]], static function (array $c): array {
            $c['stages']['quality'] = ['pint'];

            return $c;
        });

        $this->assertStringContainsString('Environment [staging]', $this->errors($config));
        $this->assertStringNotContainsString('Environment [production]', $this->errors($config));
    }

    public function test_tests_before_deploy(): void
    {
        $config = $this->config(['tests-before-deploy'], static function (array $c): array {
            $c['stages']['test'] = ['matrix' => true, 'steps' => [['run' => 'echo skipped']]];
            $c['policies'] = ['tests-before-deploy'];

            return $c;
        });

        $errors = $this->errors($config);
        $this->assertStringContainsString('Environment [staging] can be deployed without running tests', $errors);
        $this->assertStringContainsString('Environment [production] can be deployed without running tests', $errors);
    }

    public function test_approval_for_production(): void
    {
        $config = $this->config(['approval-for-production'], static function (array $c): array {
            $c['environments']['production']['approval'] = false;

            return $c;
        });

        $this->assertStringContainsString('Environment [production] deploys without a manual approval', $this->errors($config));
    }

    public function test_verification_for_production(): void
    {
        $config = $this->config(['verify-production'], static function (array $c): array {
            unset($c['environments']['production']['verify']);
            $c['environments']['production']['rollback'] = false;

            return $c;
        });

        $this->assertStringContainsString('no post-deploy verification', $this->errors($config));
    }

    public function test_rollback_for_production(): void
    {
        $config = $this->config(['rollback-for-production'], static function (array $c): array {
            $c['environments']['production']['rollback'] = false;

            return $c;
        });

        $this->assertStringContainsString('does not roll back automatically', $this->errors($config));
    }

    public function test_policies_for_environments_that_do_not_exist_are_silent(): void
    {
        $this->assertSame('', $this->errors(Fixture::minimal(['policies' => ['approval-for-production', 'verify-production', 'rollback-for-production']])));
    }

    public function test_declared_secrets_policy_always_runs(): void
    {
        $config = Fixture::full(['policies' => [], 'secrets' => []]);

        $this->assertStringContainsString('Secret [SSH_PRIVATE_KEY] is used by the pipeline but not declared', $this->errors($config));
    }

    public function test_custom_policies_by_class_can_block_compilation(): void
    {
        $policy = new class implements Policy {
            public function name(): string
            {
                return 'no-friday-deploys';
            }

            public function check(Pipeline $pipeline, array $options = []): array
            {
                return [new Violation($this->name(), 'Fridays are for rest.')];
            }
        };

        $policies = Slipway::policies();
        $policies->register('no-friday-deploys', static fn (): object => $policy);
        $compiler = Slipway::compiler(null, null, $policies);

        try {
            $compiler->compile(Fixture::minimal(['policies' => ['no-friday-deploys']]));
            $this->fail('expected the custom policy to block compilation');
        } catch (ConfigException $e) {
            $this->assertStringContainsString('[error] no-friday-deploys: Fridays are for rest.', implode("\n", $e->errors));
        }
    }

    public function test_warning_violations_do_not_block_compilation(): void
    {
        $policy = new class implements Policy {
            public function name(): string
            {
                return 'advice';
            }

            public function check(Pipeline $pipeline, array $options = []): array
            {
                return [new Violation('advice', 'Consider more tests.', Violation::WARNING)];
            }
        };

        $policies = Slipway::policies();
        $policies->register('advice', static fn (): object => $policy);
        $result = Slipway::compiler(null, null, $policies)->compile(Fixture::minimal(['policies' => ['advice']]));

        $this->assertStringContainsString('Consider more tests.', implode("\n", $result->warnings));
    }

    public function test_a_policy_that_throws_is_reported_instead_of_crashing(): void
    {
        $policy = new class implements Policy {
            public function name(): string
            {
                return 'broken';
            }

            public function check(Pipeline $pipeline, array $options = []): array
            {
                throw new \LogicException('boom');
            }
        };

        $policies = Slipway::policies();
        $policies->register('broken', static fn (): object => $policy);

        try {
            Slipway::compiler(null, null, $policies)->compile(Fixture::minimal(['policies' => ['broken']]));
            $this->fail('expected an error');
        } catch (ConfigException $e) {
            $this->assertStringContainsString('Could not be evaluated: boom', implode("\n", $e->errors));
        }
    }

    public function test_unknown_policy_names_are_reported(): void
    {
        $this->assertStringContainsString('Unknown policy [nope]', $this->errors(Fixture::minimal(['policies' => ['nope']])));
    }
}
