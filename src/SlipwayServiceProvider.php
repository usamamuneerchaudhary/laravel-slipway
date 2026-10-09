<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Usamamuneerchaudhary\Slipway\Console\CheckCommand;
use Usamamuneerchaudhary\Slipway\Console\CompileCommand;
use Usamamuneerchaudhary\Slipway\Console\DriversCommand;
use Usamamuneerchaudhary\Slipway\Console\ExplainCommand;
use Usamamuneerchaudhary\Slipway\Console\InitCommand;
use Usamamuneerchaudhary\Slipway\Console\PinCommand;
use Usamamuneerchaudhary\Slipway\Console\ScanCommand;
use Usamamuneerchaudhary\Slipway\Console\ValidateCommand;
use Usamamuneerchaudhary\Slipway\Console\VerifyCommand;
use Usamamuneerchaudhary\Slipway\Security\SecretScanner;
use Usamamuneerchaudhary\Slipway\Support\Registry;

/**
 * Extension points, all resolved from the container so other packages can add to them:
 *
 *   $this->app->make('slipway.steps')->register('my-step', MyStep::class);        // PipelineStep
 *   $this->app->make('slipway.deployers')->register('my-host', MyDeployer::class); // Deployer
 *   $this->app->make('slipway.policies')->register('my-rule', MyPolicy::class);    // Policy
 *   $this->app->make(DriverManager::class)->register(new MyDriver());             // Driver
 *
 * Do this in `boot()`; registries are singletons shared with the commands.
 */
final class SlipwayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $resolver = fn (string $class): object => $this->app->make($class);

        $this->app->singleton('slipway.steps', static fn (): Registry => Slipway::steps($resolver));
        $this->app->singleton('slipway.deployers', static fn (): Registry => Slipway::deployers($resolver));
        $this->app->singleton('slipway.policies', static fn (): Registry => Slipway::policies($resolver));
        $this->app->singleton(DriverManager::class, static fn (): DriverManager => DriverManager::withDefaults());
        $this->app->singleton(SecretScanner::class, static fn (): SecretScanner => new SecretScanner());

        $this->app->singleton(Compiler::class, static fn (Application $app): Compiler => Slipway::compiler(
            $app->make('slipway.steps'),
            $app->make('slipway.deployers'),
            $app->make('slipway.policies'),
            $app->make(DriverManager::class),
            $app->make(SecretScanner::class),
        ));
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/slipway.php' => $this->app->configPath('slipway.php'),
        ], 'slipway-config');

        $this->commands([
            InitCommand::class,
            CompileCommand::class,
            CheckCommand::class,
            ValidateCommand::class,
            ExplainCommand::class,
            DriversCommand::class,
            ScanCommand::class,
            VerifyCommand::class,
            PinCommand::class,
        ]);
    }
}
