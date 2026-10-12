<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway;

use Usamamuneerchaudhary\Slipway\Contracts\Deployer;
use Usamamuneerchaudhary\Slipway\Contracts\PipelineStep;
use Usamamuneerchaudhary\Slipway\Contracts\Policy;
use Usamamuneerchaudhary\Slipway\Deployers\CommandDeployer;
use Usamamuneerchaudhary\Slipway\Deployers\EnvoyerDeployer;
use Usamamuneerchaudhary\Slipway\Deployers\ForgeDeployer;
use Usamamuneerchaudhary\Slipway\Deployers\LaravelCloudDeployer;
use Usamamuneerchaudhary\Slipway\Deployers\SshDeployer;
use Usamamuneerchaudhary\Slipway\Deployers\VaporDeployer;
use Usamamuneerchaudhary\Slipway\Deployers\WebhookDeployer;
use Usamamuneerchaudhary\Slipway\Policies\DeclaredSecrets;
use Usamamuneerchaudhary\Slipway\Policies\PolicyEngine;
use Usamamuneerchaudhary\Slipway\Policies\RequireApprovalForProduction;
use Usamamuneerchaudhary\Slipway\Policies\RequireAuditBeforeProduction;
use Usamamuneerchaudhary\Slipway\Policies\RequireRollback;
use Usamamuneerchaudhary\Slipway\Policies\RequireTestsBeforeDeploy;
use Usamamuneerchaudhary\Slipway\Policies\RequireVerification;
use Usamamuneerchaudhary\Slipway\Security\SecretScanner;
use Usamamuneerchaudhary\Slipway\Steps\BuildArtifact;
use Usamamuneerchaudhary\Slipway\Steps\BuildAssets;
use Usamamuneerchaudhary\Slipway\Steps\ComposerAudit;
use Usamamuneerchaudhary\Slipway\Steps\ComposerValidate;
use Usamamuneerchaudhary\Slipway\Steps\Infection;
use Usamamuneerchaudhary\Slipway\Steps\Larastan;
use Usamamuneerchaudhary\Slipway\Steps\NpmAudit;
use Usamamuneerchaudhary\Slipway\Steps\NpmTest;
use Usamamuneerchaudhary\Slipway\Steps\Pest;
use Usamamuneerchaudhary\Slipway\Steps\PhpUnit;
use Usamamuneerchaudhary\Slipway\Steps\Pint;
use Usamamuneerchaudhary\Slipway\Steps\Rector;
use Usamamuneerchaudhary\Slipway\Steps\SecretScan;
use Usamamuneerchaudhary\Slipway\Support\Registry;

/**
 * Composition root. Builds registries pre-loaded with the built-in aliases and wires a Compiler.
 *
 * Inside Laravel the service provider builds these once and shares them through the container,
 * so other packages can add their own steps, deployers, policies and drivers.
 */
final class Slipway
{
    public const string VERSION = '1.7';

    /** @param (callable(class-string):object)|null $resolver Container `make`, for DI into user classes. */
    public static function steps(?callable $resolver = null): Registry
    {
        $r = new Registry(PipelineStep::class, 'step', $resolver);

        foreach ([
            'pint' => Pint::class,
            'larastan' => Larastan::class,
            'phpstan' => Larastan::class,
            'composer-audit' => ComposerAudit::class,
            'composer-validate' => ComposerValidate::class,
            'npm-audit' => NpmAudit::class,
            'npm-test' => NpmTest::class,
            'rector' => Rector::class,
            'secret-scan' => SecretScan::class,
            'pest' => Pest::class,
            'phpunit' => PhpUnit::class,
            'infection' => Infection::class,
            'build-assets' => BuildAssets::class,
            'build-artifact' => BuildArtifact::class,
        ] as $alias => $class) {
            $r->register($alias, static fn (): object => new $class());
        }

        return $r;
    }

    /** @param (callable(class-string):object)|null $resolver */
    public static function deployers(?callable $resolver = null): Registry
    {
        $r = new Registry(Deployer::class, 'deployer', $resolver);

        foreach ([
            'ssh' => SshDeployer::class,
            'vapor' => VaporDeployer::class,
            'forge' => ForgeDeployer::class,
            'cloud' => LaravelCloudDeployer::class,
            'envoyer' => EnvoyerDeployer::class,
            'webhook' => WebhookDeployer::class,
            'command' => CommandDeployer::class,
        ] as $alias => $class) {
            $r->register($alias, static fn (): object => new $class());
        }

        return $r;
    }

    /** @param (callable(class-string):object)|null $resolver */
    public static function policies(?callable $resolver = null): Registry
    {
        $r = new Registry(Policy::class, 'policy', $resolver);

        foreach ([
            'declared-secrets' => DeclaredSecrets::class,
            'audit-before-production' => RequireAuditBeforeProduction::class,
            'tests-before-deploy' => RequireTestsBeforeDeploy::class,
            'approval-for-production' => RequireApprovalForProduction::class,
            'verify-production' => RequireVerification::class,
            'rollback-for-production' => RequireRollback::class,
        ] as $alias => $class) {
            $r->register($alias, static fn (): object => new $class());
        }

        return $r;
    }

    /**
     * Convenience for standalone use and tests: a Compiler with every built-in registered.
     */
    public static function compiler(
        ?Registry $steps = null,
        ?Registry $deployers = null,
        ?Registry $policies = null,
        ?DriverManager $drivers = null,
        ?SecretScanner $scanner = null,
        string $source = 'config/slipway.php',
    ): Compiler {
        return new Compiler(
            new PipelineBuilder($steps ?? self::steps(), $deployers ?? self::deployers()),
            new PolicyEngine($policies ?? self::policies()),
            $drivers ?? DriverManager::withDefaults(),
            $scanner ?? new SecretScanner(),
            $source,
        );
    }
}
