# Extending Slipway

Slipway has four extension points. Each is a small interface in `Usamamuneerchaudhary\Slipway\Contracts`, and each
has a registry that other packages (or your own application) can add to. Register in a service provider's `boot()`:

```php
use Usamamuneerchaudhary\Slipway\DriverManager;

public function boot(): void
{
    $this->app->make('slipway.steps')->register('lint-routes', LintRoutes::class);
    $this->app->make('slipway.deployers')->register('kubectl', KubectlDeployer::class);
    $this->app->make('slipway.policies')->register('no-friday-deploys', NoFridayDeploys::class);
    $this->app->make(DriverManager::class)->register(new CircleCiDriver());
}
```

You can also skip registration and put a **fully-qualified class name** straight in the config; Slipway resolves it
through the container, so constructor dependencies are injected.

> **Purity rule.** Steps, deployers and policies must be pure: the same options must always produce the same
> output. The compiler output is compared byte-for-byte by `slipway:check`, so reading the clock, the network or
> random values will make the drift guard fail forever.

## A custom step

A step turns options into a `Contribution`: some shell steps plus any environment needs.

```php
use Usamamuneerchaudhary\Slipway\Contracts\PipelineStep;
use Usamamuneerchaudhary\Slipway\Model\Contribution;
use Usamamuneerchaudhary\Slipway\Model\Step;

final class LintRoutes implements PipelineStep
{
    public function contribute(array $options): Contribution
    {
        return Contribution::steps(
            Step::run('Lint routes', Step::ARTISAN . ' route:list --json > /dev/null'),
        );
    }
}
```

```php
'stages' => ['quality' => ['pint', 'lint-routes']],     // by alias
'stages' => ['quality' => [\App\Ci\LintRoutes::class]], // or by class
```

Useful pieces:

* `Step::run($name, $command, $env = [], $secrets = [], $runOn = 'success')`. `runOn` is `success`, `failure` or
  `always`. Commands are **POSIX `sh`**, not bash.
* `Step::ARTISAN` is a token replaced with the configured `artisan` command (`php artisan` or `vendor/bin/testbench`).
  `Step::LOCKED` is replaced with ` --locked` when the project has a `lockfile`, and with nothing otherwise.
* `$secrets` lists secret **names** the step needs. The driver exposes each as an environment variable of the same
  name, so the command reads `$NAME`. Never interpolate a secret into the command text, and never write a `${{ }}`
  expression in a command: the GitHub driver rejects it.
* `Contribution` is immutable and fluent: `withNode()`, `withServices(['mysql'])`, `withArtifact([...paths])`,
  `withEnv([...])`, `withCoverage('pcov')`, `withoutDevDependencies()`.
* Extend `AbstractStep` to get option validation (`$this->options($options, ['level' => 'int|string'])`
  rejects unknown keys and wrong types) and `shellSafe()` for values that end up in a command line.

Throw `InvalidArgumentException` for bad options; the message is reported with its config path.

## A custom policy

```php
use Usamamuneerchaudhary\Slipway\Model\Pipeline;
use Usamamuneerchaudhary\Slipway\Policies\AbstractPolicy;
use Usamamuneerchaudhary\Slipway\Policies\Violation;

final class RequireStagingFirst extends AbstractPolicy
{
    public function name(): string
    {
        return 'require-staging-first';
    }

    public function check(Pipeline $pipeline, array $options = []): array
    {
        $production = $pipeline->environments['production'] ?? null;

        if ($production !== null && $production->after !== 'staging') {
            return [new Violation($this->name(), "Production must deploy after 'staging'.")];
        }

        return [];
    }
}
```

```php
'policies' => ['require-staging-first'],
'policies' => ['tests-before-deploy' => ['environments' => ['staging', 'production']]], // with options
```

`Violation` defaults to an error, which stops compilation. Pass `Violation::WARNING` as the third argument to report
without failing. `AbstractPolicy` offers `targets($options)` (the `environments` option, default `production`),
`deployJobs()`, `ancestors()` and `anyStepMatches()`.

If a policy throws, the failure is reported as a violation ("Could not be evaluated") rather than silently passing.

## A custom deployer

A deployer returns POSIX `sh` for one environment. Extend `AbstractDeployer`: it provides the helpers below and
safe defaults (no PHP, no artifact, no rollback), so a minimal deployer implements four methods.

```php
use Usamamuneerchaudhary\Slipway\Deployers\AbstractDeployer;
use Usamamuneerchaudhary\Slipway\Model\Environment;
use Usamamuneerchaudhary\Slipway\Support\Shell;

/** Triggers a release by POSTing to a hook URL that is kept in a secret. */
final class ReleaseHookDeployer extends AbstractDeployer
{
    public function validate(Environment $e): array
    {
        return array_merge(
            $this->unknownOptions($e->options, ['secret']),
            $this->validSecretName($e, 'secret', 'RELEASE_HOOK_URL'),
        );
    }

    public function requiredSecrets(Environment $e): array
    {
        return [$this->secretOption($e, 'secret', 'RELEASE_HOOK_URL')];
    }

    public function tools(Environment $e): array
    {
        return ['curl'];
    }

    public function deployCommands(Environment $e): string
    {
        $secret = $this->secretOption($e, 'secret', 'RELEASE_HOOK_URL');

        return 'echo ' . Shell::quote("Releasing {$e->name}") . "\n"
            . 'curl -fsS --max-time 60 -X POST "$' . $secret . '"';
    }
}
```

```php
'environments' => ['production' => ['via' => 'release-hook', 'options' => ['secret' => 'RELEASE_HOOK_URL'], ...]],
'secrets' => ['RELEASE_HOOK_URL'],
```

To support automatic rollback, override `supportsRollback()` (return `true`) and `rollbackCommands()`. To ship the
build tarball, override `usesArtifact()`; to install PHP and Composer dependencies in the deploy job (for example to
run a CLI from `vendor/bin`), override `needsPhp()`.

Rules:

* Declare every secret the script reads in `requiredSecrets()`. The `declared-secrets` policy then forces them to
  be listed in `secrets`, and drivers pass exactly those into the job.
* Validate all options in `validate()`. Compilation fails with your messages; no script is generated from
  invalid options.
* Quote every value that reaches the shell (`Support\Shell::quote()` or `escapeshellarg`).
* `tools()` may list `curl`, `ssh`, `rsync` or `git`; the driver makes sure those exist in the job's container.
* Scripts also receive `SLIPWAY_SHA`, `SLIPWAY_REF`, `SLIPWAY_REPO` and `SLIPWAY_RUN_URL` from the driver.
* Verification and rollback orchestration (deploy, verify, roll back on failure, then fail the job) is done for you
  by `DeployScript`; your deployer only supplies the deploy and rollback commands.

## A custom driver

See [writing-a-driver.md](writing-a-driver.md).
