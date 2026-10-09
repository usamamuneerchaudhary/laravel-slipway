# Writing a driver

A driver renders Slipway's provider-neutral `Pipeline` model as one provider's YAML. The three built-in drivers
(`GitHubDriver`, `BitbucketDriver`, `GitLabDriver`) are the reference implementations; read whichever is closest to
the provider you target.

## The contract

```php
namespace Usamamuneerchaudhary\Slipway\Contracts;

interface Driver
{
    public function name(): string;          // key in config('slipway.drivers'), e.g. "circleci"
    public function label(): string;         // "CircleCI"
    public function defaultPath(): string;   // ".circleci/config.yml" (relative, inside the project)
    public function capabilities(): array;   // Feature::value => Support (Full | Partial | None)
    public function notes(): array;          // Feature::value => why it is Partial / None
    public function render(Pipeline $pipeline, array $options = []): string; // the YAML body
}
```

`render()` returns the body only. The compiler adds the "generated, do not edit" header, scans the result for
credentials, and decides the file path.

## The model you render

* `Pipeline`: `name`, `triggers`, `jobs` (in execution order), `environments`, `declaredSecrets`,
  `cancelSuperseded`, `notifications`. Helpers: `stages()`, `job($id)`, `jobsFor($event, $branch)`.
* `Job`: `id`, `name`, `stage`, `needs`, `phpVersions` (more than one = matrix), `extensions`, `coverage`, `node`,
  `tools`, `services`, `caches`, `steps`, `timeoutMinutes`, `environment`, `manual` (needs approval), `events`,
  `branches`, `tags`, `artifactPaths`, `consumesArtifact`, `env`.
* `Step`: `type` (`Run`, `ArtifactUpload`, `ArtifactDownload`), `name`, `run` (POSIX `sh`), `env`, `secrets`
  (**names**), `runOn` (`success` | `failure` | `always`).
* `Triggers`: push branches, tag patterns, pull-request on/off and target branches, cron schedule, manual, ignored paths.

Everything is already validated and normalised. Jobs only reference jobs that exist, secrets are all declared, and
`needs` is acyclic.

## Capabilities

Return a `Support` level for **every** `Feature` case (`Matrix`, `Services`, `Approvals`, `Environments`,
`Concurrency`, `Schedule`, `ManualTrigger`, `Artifacts`, `PathFilters`, `Timeouts`, `Caching`, `ParallelJobs`,
`TagTriggers`). A missing entry is treated as `None`.

* `Full`: expressed natively.
* `Partial`: expressed with a caveat. Explain the caveat in `notes()`, including any one-time setting the user must
  make in the provider's UI. It becomes a compile warning.
* `None`: cannot be expressed. It is left out with a warning, or is a compile **error** in `strict` mode.

The compiler only reports a limitation for features the pipeline actually uses, so be honest rather than optimistic:
a `Full` you cannot back up is worse than a `Partial` with a clear note.

## Rules every driver must follow

1. **Deterministic.** The same pipeline must render the same bytes. No clocks, randomness or unordered maps.
2. **Secrets by name.** Never write a secret value. Expose a step's `secrets` to its script as environment variables of
   the same name, using the provider's secret reference syntax. Do not interpolate secrets into command text.
3. **Reject unknown options.** `AbstractDriver::assertOptions($options, ['allowed', 'keys'])` throws
   `InvalidArgumentException`; the compiler reports it as `drivers.<name>: …`.
4. **Honour `raw`.** The driver's `raw` option is deep-merged over the generated structure
   (`AbstractDriver::deepMerge`: maps merge; lists and scalars replace).
5. **Fail fast in multi-line scripts.** Providers that run a list of script lines can ignore a failure in the middle
   of a block. `AbstractDriver::failFast()` prefixes `set -e`.
6. **No tabs, no trailing whitespace** in the output.
7. **Use the YAML emitter.** `Support\Yaml::dump($array)` quotes values that YAML 1.1 would misread (`y`, `no`,
   `on`, `1e3`, `08`, and so on) and uses literal block scalars for multi-line strings. Build arrays, not strings.

`AbstractDriver` also provides `jobEnv()`, `globPath()`, `bareDir()`, `stringOption()`, `assertNoExpressions()`,
`successScripts()`, `containerSetup()`, `nodeInstall()`, `shellExports()` and `waitForPorts()` for providers that run
jobs in plain containers.

## Skeleton

```php
namespace Acme\Slipway;

use Usamamuneerchaudhary\Slipway\Drivers\AbstractDriver;
use Usamamuneerchaudhary\Slipway\Model\{Feature, Pipeline, Support};
use Usamamuneerchaudhary\Slipway\Support\Yaml;

final class CircleCiDriver extends AbstractDriver
{
    public function name(): string { return 'circleci'; }
    public function label(): string { return 'CircleCI'; }
    public function defaultPath(): string { return '.circleci/config.yml'; }

    public function capabilities(): array
    {
        $caps = [];
        foreach (Feature::cases() as $feature) {
            $caps[$feature->value] = Support::None;   // start honest, upgrade as you implement
        }

        return array_merge($caps, [
            Feature::Services->value => Support::Full,
            Feature::Timeouts->value => Support::Full,
        ]);
    }

    public function notes(): array
    {
        return [Feature::Matrix->value => 'Not implemented yet.'];  // explain every Partial / None
    }

    public function render(Pipeline $pipeline, array $options = []): string
    {
        $this->assertOptions($options['options'] ?? [], ['resource_class']);

        $config = ['version' => 2.1, 'jobs' => [/* one entry per $pipeline->jobs */], 'workflows' => [/* ... */]];

        return Yaml::dump($this->deepMerge($config, $options['raw'] ?? []));
    }
}
```

Register it from a service provider:

```php
$this->app->make(\Usamamuneerchaudhary\Slipway\DriverManager::class)->register(new CircleCiDriver());
```

and enable it with `'drivers' => ['circleci' => true]`.

## Prove it with the conformance suite

`tests/Conformance/DriverConformanceTestCase.php` encodes the rules above as tests: identity, a support level
for every feature, every `Partial`/`None` explained, deterministic output, no tabs or trailing whitespace, secrets
never inlined, every job present in the output, unknown options rejected, `raw` applied, and registration with the
`DriverManager`.

The case lives in this package's `tests/` directory, so Composer does not autoload it into dependent packages.
**Copy** `DriverConformanceTestCase.php` and `tests/Support/Fixture.php` into your driver's own tests (adjust the
namespaces), then:

```php
final class CircleCiConformanceTest extends DriverConformanceTestCase
{
    protected function driver(): Driver
    {
        return new CircleCiDriver();
    }
}
```

Passing conformance does not mean the YAML is valid for the provider. Also validate the output with the provider's
own linter or JSON schema, and run it once on the real service.
