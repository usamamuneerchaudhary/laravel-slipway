<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway;

use InvalidArgumentException;
use Throwable;
use Usamamuneerchaudhary\Slipway\Config\ConfigException;
use Usamamuneerchaudhary\Slipway\Contracts\Deployer;
use Usamamuneerchaudhary\Slipway\Contracts\PipelineStep;
use Usamamuneerchaudhary\Slipway\Deployers\DeployScript;
use Usamamuneerchaudhary\Slipway\Model\CacheDef;
use Usamamuneerchaudhary\Slipway\Model\Contribution;
use Usamamuneerchaudhary\Slipway\Model\Environment;
use Usamamuneerchaudhary\Slipway\Model\Job;
use Usamamuneerchaudhary\Slipway\Model\Pipeline;
use Usamamuneerchaudhary\Slipway\Model\Step;
use Usamamuneerchaudhary\Slipway\Model\TiaBaseline;
use Usamamuneerchaudhary\Slipway\Model\Triggers;
use Usamamuneerchaudhary\Slipway\Steps\BuildArtifact;
use Usamamuneerchaudhary\Slipway\Support\NotificationScript;
use Usamamuneerchaudhary\Slipway\Support\Registry;
use Usamamuneerchaudhary\Slipway\Support\ServiceCatalog;

/**
 * Turns a validated (normalized) config array into the provider-neutral Pipeline model.
 *
 * Stages run in order; jobs inside a stage run in parallel; every environment becomes
 * its own "deploy-<name>" stage after all CI stages.
 */
final class PipelineBuilder
{
    /** @var list<string> */
    private array $errors = [];

    /** @var list<array{php?:string,schedule?:string,coverage?:string,parallel?:bool}> */
    private array $tiaSpecs = [];

    /** @var list<string> */
    private array $warnings = [];

    private ?string $root = null;

    public function __construct(
        private readonly Registry $steps,
        private readonly Registry $deployers,
    ) {
    }

    /**
     * @param array<string,mixed> $config Output of Config\Schema::normalize().
     * @param string|null         $root   Project root, used to see which Bun lockfile exists.
     * @throws ConfigException
     */
    public function build(array $config, ?string $root = null): Pipeline
    {
        $this->errors = [];
        $this->warnings = [];
        $this->tiaSpecs = [];
        $this->root = $root;

        $triggers = new Triggers(
            pushBranches: $config['triggers']['push'],
            tags: $config['triggers']['tags'],
            pullRequest: $config['triggers']['pull_request'],
            pullRequestBranches: $config['triggers']['pull_request_branches'],
            schedule: $config['triggers']['schedule'],
            manual: $config['triggers']['manual'],
            pathsIgnore: $config['triggers']['paths_ignore'],
        );

        $enabled = $this->enabledEvents($triggers);
        if ($enabled === []) {
            $this->errors[] = 'triggers: nothing can start this pipeline. Enable push, pull_request, tags, schedule or manual.';
        }

        $jobs = [];
        $previous = [];

        $firstStage = true;
        foreach ($config['stages'] as $stageName => $stage) {
            $stageJobs = $this->buildStage((string) $stageName, $stage, $config, $enabled, $previous);

            if ($firstStage && $config['guard']) {
                // The drift guard runs alongside the first stage so it never adds wall-clock time.
                $stageJobs[] = $this->guardJob((string) $stageName, $config, $enabled);
            }
            $firstStage = false;

            foreach ($stageJobs as $job) {
                $jobs[] = $job;
            }
            $previous = array_map(static fn (Job $j): string => $j->id, $stageJobs);
        }

        $environments = [];
        $deployJobs = [];

        foreach ($config['environments'] as $name => $def) {
            $environment = new Environment(
                name: (string) $name,
                branch: $def['branch'],
                tag: $def['tag'],
                after: $def['after'],
                approval: $def['approval'],
                deployer: $def['via'],
                verify: $def['verify'],
                rollback: $def['rollback'],
                options: $def['options'],
            );
            $environments[$environment->name] = $environment;

            $job = $this->buildDeployJob($environment, $config, $previous, $deployJobs, $jobs);
            if ($job !== null) {
                $deployJobs[$environment->name] = $job;
                $jobs[] = $job;
            }
        }

        $jobs = $this->addNotifications($jobs, $config['notifications'], $config['name']);
        $tia = $this->tiaBaseline($config, $jobs);

        if ($this->errors !== []) {
            throw new ConfigException($this->errors);
        }

        return new Pipeline(
            name: $config['name'],
            triggers: $triggers,
            jobs: $jobs,
            environments: $environments,
            declaredSecrets: $config['secrets'],
            cancelSuperseded: $config['concurrency']['cancel_superseded'],
            notifications: $config['notifications'],
            tia: $tia,
        );
    }

    // ---------------------------------------------------------------------
    // CI stages
    // ---------------------------------------------------------------------

    /**
     * @param array<string,mixed> $stage
     * @param array<string,mixed> $config
     * @param list<string>        $enabled
     * @param list<string>        $previous Job ids of the preceding stage.
     * @return list<Job>
     */
    private function buildStage(string $name, array $stage, array $config, array $enabled, array $previous): array
    {
        $units = [];
        foreach ($stage['steps'] as $entry) {
            $unit = $this->resolveEntry($name, $entry, $config);
            if ($unit !== null) {
                $units[] = $unit;
            }
        }

        if ($units === []) {
            return [];
        }

        $groups = $stage['options']['parallel']
            ? array_map(static fn (array $u): array => [$u], $units)
            : [$units];

        $jobs = [];
        $ids = [];
        foreach ($groups as $group) {
            $label = count($groups) > 1 ? $group[0]['label'] : null;
            $id = $label === null ? $name : $name . '-' . $this->slug($label);

            if (isset($ids[$id])) {
                $this->errors[] = "stages.{$name}: two parallel steps resolve to the same job id [{$id}]. Give inline steps distinct names.";
                continue;
            }
            $ids[$id] = true;

            $jobs[] = $this->buildCiJob($id, $name, $label, array_map(static fn (array $u): Contribution => $u['contribution'], $group), $stage['options'], $config, $enabled, $previous);
        }

        return $jobs;
    }

    /**
     * The drift guard: fails the build when a generated pipeline file was edited by hand
     * or config/slipway.php changed without re-running `php artisan slipway:compile`.
     *
     * @param array<string,mixed> $config
     * @param list<string>        $enabled
     */
    private function guardJob(string $stage, array $config, array $enabled): Job
    {
        return new Job(
            id: 'slipway-guard',
            name: 'Slipway: pipeline drift check',
            stage: $stage,
            needs: [],
            phpVersions: [end($config['php'])],
            extensions: $config['extensions'],
            caches: $config['cache'] ? [new CacheDef('composer', ['vendor/'], [$this->composerKeyFile($config)])] : [],
            steps: [
                Step::run('Install Composer dependencies', 'composer install --no-interaction --no-progress --prefer-dist'),
                Step::run('Check generated pipeline files are up to date', $config['artisan'] . ' slipway:check --no-interaction'),
            ],
            timeoutMinutes: $config['timeouts']['default'],
            events: $enabled,
            env: $config['env'],
        );
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $config
     * @return array{label:string,contribution:Contribution}|null
     */
    private function resolveEntry(string $stage, array $entry, array $config): ?array
    {
        if ($entry['kind'] === 'inline') {
            return [
                'label' => $entry['name'],
                'contribution' => Contribution::steps(Step::run($entry['name'], $entry['run'], $entry['env'], $entry['secrets'])),
            ];
        }

        $ref = $entry['ref'];
        $options = $entry['options'];
        $path = "stages.{$stage}.{$ref}";

        try {
            $step = $this->steps->resolve($ref);
        } catch (InvalidArgumentException $e) {
            $this->errors[] = "{$path}: " . $e->getMessage();

            return null;
        }

        if (! $step instanceof PipelineStep) {
            $this->errors[] = "{$path}: [{$ref}] does not implement " . PipelineStep::class . '.';

            return null;
        }

        if ($step instanceof BuildArtifact && ! array_key_exists('paths', $options)) {
            $options['paths'] = $config['artifact']['paths'];
        }

        try {
            $contribution = $step->contribute($options);
        } catch (InvalidArgumentException $e) {
            $this->errors[] = "{$path}: " . $e->getMessage();

            return null;
        } catch (Throwable $e) {
            $this->errors[] = "{$path}: step threw " . $e::class . ': ' . $e->getMessage();

            return null;
        }

        return ['label' => $this->labelFor($ref), 'contribution' => $contribution];
    }

    /**
     * @param list<Contribution>  $contributions
     * @param array<string,mixed> $options
     * @param array<string,mixed> $config
     * @param list<string>        $enabled
     * @param list<string>        $previous
     */
    private function buildCiJob(
        string $id,
        string $stage,
        ?string $label,
        array $contributions,
        array $options,
        array $config,
        array $enabled,
        array $previous,
    ): Job {
        $steps = [];
        $services = $options['services'];
        $artifactPaths = [];
        $env = array_merge($config['env'], $options['env']);
        $needsNode = false;
        $needsBun = false;
        $coverage = null;
        $noDev = false;

        foreach ($contributions as $c) {
            array_push($steps, ...$c->steps);
            $services = array_merge($services, $c->services);
            $artifactPaths = array_merge($artifactPaths, $c->artifactPaths);
            $env = array_merge($env, $c->env);
            $needsNode = $needsNode || $c->requiresNode;
            $needsBun = $needsBun || $c->requiresBun;
            $coverage ??= $c->coverage;
            $noDev = $noDev || $c->noDev;
            if ($c->tia !== null) {
                $this->tiaSpecs[] = $c->tia;
            }
        }

        $steps = array_map(fn (Step $s): Step => $this->applyTokens($s, $config), $steps);

        foreach ($steps as $step) {
            if ($step->run === 'npx vite build') {
                $this->warn('build-assets preset "vite" runs `npx vite build` when script is omitted. Set script to a package.json script name to run that script with npm.');
            }
        }

        $services = array_values(array_unique($services));
        $artifactPaths = array_values(array_unique($artifactPaths));

        $node = null;
        if ($needsNode) {
            $node = $config['node'];
            if ($node === null) {
                $this->errors[] = "stages.{$stage}: a step needs Node.js but \"node\" is not set in config (e.g. 'node' => '22').";
            }
        }

        $bun = null;
        if ($needsBun) {
            $bun = $config['bun'];
            if ($bun === null) {
                $this->errors[] = "stages.{$stage}: a step needs Bun but \"bun\" is not set in config (e.g. 'bun' => '1.2.21').";
            }
        }

        $phpVersions = match (true) {
            $options['php'] === false => [],
            is_string($options['php']) => [$options['php']],
            $options['matrix'] => $config['php'],
            default => [end($config['php'])],
        };

        $serviceModels = [];
        foreach ($services as $service) {
            if (ServiceCatalog::has($service)) {
                $serviceModels[] = ServiceCatalog::get($service);
            } else {
                $this->errors[] = "stages.{$stage}.services: unknown service [{$service}]; available: " . implode(', ', ServiceCatalog::names());
            }
        }

        if ($phpVersions !== []) {
            array_unshift($steps, Step::run(
                'Install Composer dependencies',
                'composer install --no-interaction --no-progress --prefer-dist' . ($noDev ? ' --no-dev --optimize-autoloader' : '')
            ));
        }

        $caches = [];
        if ($config['cache']) {
            if ($phpVersions !== []) {
                $caches[] = new CacheDef($noDev ? 'composer-nodev' : 'composer', ['vendor/'], [$this->composerKeyFile($config)]);
            }
            if ($node !== null) {
                $caches[] = new CacheDef('node', ['node_modules/'], ['package-lock.json']);
            }
            if ($bun !== null) {
                $caches[] = new CacheDef('bun', ['node_modules/'], [$this->bunKeyFile()]);
            }
        }

        $events = $options['on'] ?? ($artifactPaths !== [] ? array_values(array_intersect(['push', 'tag', 'manual'], $enabled)) : $enabled);
        foreach ($events as $event) {
            if (! in_array($event, $enabled, true)) {
                $this->errors[] = "stages.{$stage}.on: event [{$event}] is not enabled under \"triggers\".";
            }
        }

        $title = ucfirst(str_replace(['-', '_'], ' ', $stage));

        return new Job(
            id: $id,
            name: $label === null ? $title : "{$title}: {$label}",
            stage: $stage,
            needs: $previous,
            phpVersions: $phpVersions,
            extensions: $options['extensions'] ?? $config['extensions'],
            coverage: $coverage,
            node: $node,
            bun: $bun,
            tools: [],
            services: $serviceModels,
            caches: $caches,
            steps: $steps,
            timeoutMinutes: $options['timeout'] ?? $config['timeouts']['default'],
            environment: null,
            manual: false,
            events: array_values($events),
            branches: null,
            tags: null,
            artifactPaths: $artifactPaths,
            consumesArtifact: false,
            env: $env,
        );
    }

    // ---------------------------------------------------------------------
    // Deployment environments
    // ---------------------------------------------------------------------

    /**
     * @param array<string,mixed>  $config
     * @param list<string>         $previous     Last CI stage's job ids.
     * @param array<string,Job>    $deployJobs   Deploy jobs built so far, by environment name.
     * @param list<Job>            $jobs         All jobs so far (to check an artifact is published).
     */
    private function buildDeployJob(Environment $environment, array $config, array $previous, array $deployJobs, array $jobs): ?Job
    {
        $path = "environments.{$environment->name}";

        try {
            $deployer = $this->deployers->resolve($environment->deployer);
        } catch (InvalidArgumentException $e) {
            $this->errors[] = "{$path}.via: " . $e->getMessage();

            return null;
        }

        if (! $deployer instanceof Deployer) {
            $this->errors[] = "{$path}.via: [{$environment->deployer}] does not implement " . Deployer::class . '.';

            return null;
        }

        $problems = $deployer->validate($environment);
        foreach ($problems as $message) {
            $this->errors[] = "{$path}.options: {$message}";
        }

        if ($problems !== []) {
            // Never generate a script from options that failed validation.
            return null;
        }

        if ($environment->rollback && $environment->verify === null) {
            $this->errors[] = "{$path}.rollback: rollback is triggered by a failed verification, so \"verify\" must be configured too.";
        }

        if ($environment->rollback && ! $deployer->supportsRollback($environment)) {
            $this->errors[] = "{$path}.rollback: deployer [{$environment->deployer}] cannot roll back automatically with these options.";
        }

        $usesArtifact = $deployer->usesArtifact($environment);
        if ($usesArtifact) {
            $publishes = false;
            foreach ($jobs as $existing) {
                $publishes = $publishes || $existing->artifactPaths !== [];
            }
            if (! $publishes) {
                $this->errors[] = "{$path}: deployer [{$environment->deployer}] ships the build artifact, but no stage publishes one. Add 'build-artifact' to a stage.";
            }
        }

        // Which triggers start this environment, and which branch/tag filters apply.
        $parent = $environment->after !== null ? ($deployJobs[$environment->after] ?? null) : null;
        if ($environment->branch === null && $environment->tag === null && $parent !== null) {
            $events = $parent->events;
            $branches = $parent->branches;
            $tags = $parent->tags;
        } else {
            $events = [];
            $branches = null;
            $tags = null;
            if ($environment->branch !== null) {
                $events[] = 'push';
                $branches = [$environment->branch];
            }
            if ($environment->tag !== null) {
                $events[] = 'tag';
                $tags = [$environment->tag];
            }
        }

        $needs = $parent !== null ? [$parent->id] : $previous;

        $tools = $deployer->tools($environment);
        if ($environment->verify !== null) {
            $tools[] = 'curl';
        }
        $tools = array_values(array_unique($tools));

        $needsPhp = $deployer->needsPhp($environment);
        $steps = [];
        if ($usesArtifact) {
            $steps[] = Step::artifactDownload();
            $steps[] = Step::run('Unpack build artifact', BuildArtifact::unpackCommand());
        } elseif ($needsPhp) {
            $steps[] = Step::run('Install Composer dependencies', 'composer install --no-interaction --no-progress --prefer-dist');
        }

        $secrets = $deployer->requiredSecrets($environment);
        if ($environment->verify !== null && $environment->verify['url_secret'] !== null) {
            $secrets[] = $environment->verify['url_secret'];
        }

        $steps[] = Step::run(
            "Deploy to {$environment->name}",
            DeployScript::compose($environment, $deployer),
            [],
            $secrets,
        );

        $verifyMinutes = $environment->verify === null ? 0 : (int) ceil($environment->verify['retries'] * ($environment->verify['delay'] + 15) / 60);
        $timeout = min(360, max($config['timeouts']['default'], $verifyMinutes + 15));

        return new Job(
            id: 'deploy-' . $environment->name,
            name: 'Deploy: ' . $environment->name,
            stage: 'deploy-' . $environment->name,
            needs: $needs,
            phpVersions: $needsPhp ? [end($config['php'])] : [],
            extensions: $config['extensions'],
            tools: $tools,
            caches: [],
            steps: $steps,
            timeoutMinutes: $timeout,
            environment: $environment->name,
            manual: $environment->approval,
            events: $events,
            branches: $branches,
            tags: $tags,
            consumesArtifact: $usesArtifact,
            env: $config['env'],
        );
    }

    // ---------------------------------------------------------------------
    // Notifications
    // ---------------------------------------------------------------------

    /**
     * @param list<Job>           $jobs
     * @param array<string,mixed> $notifications
     * @return list<Job>
     */
    private function addNotifications(array $jobs, array $notifications, string $pipelineName): array
    {
        if ($notifications === [] || $jobs === []) {
            return $jobs;
        }

        $lastStage = $jobs[count($jobs) - 1]->stage;
        $out = [];

        foreach ($jobs as $job) {
            $extra = [];
            $tools = $job->tools;

            foreach ($notifications as $channel => $cfg) {
                foreach ($cfg['on'] as $when) {
                    if ($when === 'success' && $job->stage !== $lastStage) {
                        continue;
                    }

                    $extra[] = Step::run(
                        ucfirst($channel) . ' notification (' . $when . ')',
                        NotificationScript::render($channel, $cfg['secret']),
                        ['SLIPWAY_JOB' => $job->name, 'SLIPWAY_PIPELINE' => $pipelineName, 'SLIPWAY_STATUS' => $when === 'success' ? 'succeeded' : 'FAILED'],
                        [$cfg['secret']],
                        $when === 'success' ? 'success' : 'failure',
                    );
                    $tools[] = 'curl';
                }
            }

            $out[] = $extra === [] ? $job : $job->with([
                'steps' => array_merge($job->steps, $extra),
                'tools' => array_values(array_unique($tools)),
            ]);
        }

        return $out;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @return list<string> */
    private function enabledEvents(Triggers $t): array
    {
        $events = [];
        $t->pullRequest && $events[] = 'pull_request';
        $t->hasPush() && $events[] = 'push';
        $t->hasTags() && $events[] = 'tag';
        $t->schedule !== [] && $events[] = 'schedule';
        $t->manual && $events[] = 'manual';

        return $events;
    }

    /** @param array<string,mixed> $config */
    private function applyTokens(Step $step, array $config): Step
    {
        if ($step->run === null || (! str_contains($step->run, Step::ARTISAN) && ! str_contains($step->run, Step::LOCKED))) {
            return $step;
        }

        return new Step(
            $step->type,
            $step->name,
            str_replace([Step::ARTISAN, Step::LOCKED], [$config['artisan'], $config['lockfile'] ? ' --locked' : ''], $step->run),
            $step->env,
            $step->secrets,
            $step->runOn,
        );
    }

    /**
     * The file whose hash keys the Composer cache. Libraries do not commit composer.lock,
     * so their cache is keyed by composer.json instead (a missing file would break the cache key).
     *
     * @param array<string,mixed> $config
     */
    private function composerKeyFile(array $config): string
    {
        return $config['lockfile'] ? 'composer.lock' : 'composer.json';
    }

    /**
     * Bun projects commit bun.lock or bun.lockb, not both. CircleCI checksum fails when a
     * key file is missing, so the cache is keyed on the one file that exists.
     */
    private function bunKeyFile(): string
    {
        $root = $this->root;
        if ($root === null || $root === '') {
            return 'bun.lock';
        }

        $base = rtrim($root, '/\\');
        $text = is_file($base . '/bun.lock');
        $binary = is_file($base . '/bun.lockb');

        if ($text && $binary) {
            $this->warn('Both bun.lock and bun.lockb are present. The Bun cache is keyed on bun.lock so CircleCI does not checksum a second lockfile.');

            return 'bun.lock';
        }

        if ($binary) {
            return 'bun.lockb';
        }

        if (! $text) {
            $this->warn('No bun.lock or bun.lockb was found. The Bun cache is keyed on bun.lock. CircleCI fails the cache step when that file is missing.');
        }

        return 'bun.lock';
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    private function warn(string $message): void
    {
        if (! in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }

    /**
     * @param array<string,mixed> $config
     * @param list<Job>           $jobs
     */
    private function tiaBaseline(array $config, array $jobs): ?TiaBaseline
    {
        if ($this->tiaSpecs === []) {
            return null;
        }

        $baselines = [];
        foreach ($this->tiaSpecs as $spec) {
            $baselines[] = $this->makeTia($spec, $config);
        }

        $fingerprints = array_unique(array_map(static fn (TiaBaseline $tia): string => $tia->fingerprint(), $baselines));
        if (count($fingerprints) > 1) {
            $this->errors[] = 'pest: option [tia] is set to different values on more than one step.';

            return null;
        }

        $baseline = $baselines[0];
        if ($baseline->parallel && ! $this->pestRunsInParallel($jobs)) {
            $this->warn('The Pest TIA baseline runs `vendor/bin/pest --parallel`. No Pest step in this pipeline does. Set parallel on the pest step, or set tia.parallel to false.');
        }

        return $baseline;
    }

    /** @param list<Job> $jobs */
    private function pestRunsInParallel(array $jobs): bool
    {
        foreach ($jobs as $job) {
            foreach ($job->steps as $step) {
                if (! is_string($step->run) || ! str_contains($step->run, 'vendor/bin/pest')) {
                    continue;
                }

                if (preg_match('/(^|\s)--parallel(\s|$)/', $step->run) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array{php?:string,schedule?:string,coverage?:string,parallel?:bool}  $spec
     * @param  array<string,mixed>  $config
     */
    private function makeTia(array $spec, array $config): TiaBaseline
    {
        /** @var list<string> $phpVersions */
        $phpVersions = $config['php'];
        $php = $spec['php'] ?? $phpVersions[array_key_last($phpVersions)];
        /** @var list<string> $push */
        $push = $config['triggers']['push'];

        return new TiaBaseline(
            php: $php,
            schedule: $spec['schedule'] ?? '0 3 * * *',
            coverage: $spec['coverage'] ?? 'xdebug',
            parallel: $spec['parallel'] ?? false,
            branch: $push[0] ?? 'main',
            lockfile: $config['lockfile'],
        );
    }

    private function slug(string $text): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');

        return $slug === '' ? 'step' : $slug;
    }

    private function labelFor(string $ref): string
    {
        $short = str_contains($ref, '\\') ? substr($ref, (int) strrpos($ref, '\\') + 1) : $ref;

        return ucfirst(str_replace(['-', '_'], ' ', strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $short))));
    }
}
