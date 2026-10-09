<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Drivers;

use InvalidArgumentException;
use Usamamuneerchaudhary\Slipway\Model\Feature;
use Usamamuneerchaudhary\Slipway\Model\Job;
use Usamamuneerchaudhary\Slipway\Model\Pipeline;
use Usamamuneerchaudhary\Slipway\Model\Service;
use Usamamuneerchaudhary\Slipway\Model\StepType;
use Usamamuneerchaudhary\Slipway\Model\Support;
use Usamamuneerchaudhary\Slipway\Support\Yaml;

/**
 * Bitbucket Pipelines.
 *
 * Bitbucket has no job graph: each trigger (pull request, branch, tag, custom) owns a sequential
 * list of steps. The driver therefore computes, per trigger, which jobs apply and emits them
 * stage by stage (a stage with several jobs becomes a `parallel` group).
 *
 * Driver options (config: drivers.bitbucket.options):
 *   image        PHP job image template, "{php}" is replaced; default "php:{php}-cli"
 *   shell_image  image for shell-only jobs (deploys); default "alpine:3.22"
 *   bootstrap    list of shell lines that REPLACE the built-in PHP/Node bootstrap (e.g. [] when
 *                you supply a pre-built image)
 *   size         optional step size, "2x", "4x", ...
 */
final class BitbucketDriver extends AbstractDriver
{
    public function name(): string
    {
        return 'bitbucket';
    }

    public function label(): string
    {
        return 'Bitbucket Pipelines';
    }

    public function defaultPath(): string
    {
        return 'bitbucket-pipelines.yml';
    }

    public function capabilities(): array
    {
        return [
            Feature::Matrix->value => Support::Partial,
            Feature::Services->value => Support::Full,
            Feature::Approvals->value => Support::Full,
            Feature::Environments->value => Support::Partial,
            Feature::Concurrency->value => Support::None,
            Feature::Schedule->value => Support::Partial,
            Feature::ManualTrigger->value => Support::Full,
            Feature::Artifacts->value => Support::Partial,
            Feature::PathFilters->value => Support::None,
            Feature::Timeouts->value => Support::Full,
            Feature::Caching->value => Support::Full,
            Feature::ParallelJobs->value => Support::Full,
            Feature::TagTriggers->value => Support::Full,
        ];
    }

    public function notes(): array
    {
        return [
            Feature::Matrix->value => 'Bitbucket has no matrix syntax. Each PHP version is expanded into its own parallel step.',
            Feature::Environments->value => 'The deployment environments must already exist under Repository settings > Deployments, or Bitbucket rejects the file.',
            Feature::Concurrency->value => 'There is no way to cancel superseded runs in bitbucket-pipelines.yml.',
            Feature::Schedule->value => 'Rendered as the custom pipeline "scheduled". Create the actual schedule under Repository settings > Schedules; Bitbucket does not store cron in the YAML.',
            Feature::Artifacts->value => 'Artifacts expire after 14 days, so a manual approval older than that cannot deploy; re-run the build.',
            Feature::PathFilters->value => 'Only "includePaths" conditions exist, so "paths_ignore" cannot be expressed.',
        ];
    }

    public function render(Pipeline $pipeline, array $options = []): string
    {
        $o = $options['options'] ?? [];
        $this->assertOptions($o, ['image', 'shell_image', 'bootstrap', 'size']);

        $image = $this->stringOption($o, 'image', 'php:{php}-cli');
        $shellImage = $this->stringOption($o, 'shell_image', 'alpine:3.22');
        $bootstrap = $o['bootstrap'] ?? null;
        if ($bootstrap !== null && (! is_array($bootstrap) || ! array_is_list($bootstrap))) {
            throw new InvalidArgumentException('bitbucket: option [bootstrap] must be a list of shell lines.');
        }

        $ctx = [
            'image' => $image,
            'shell_image' => $shellImage,
            'bootstrap' => $bootstrap,
            'size' => $o['size'] ?? null,
            'caches' => [],
            'services' => [],
        ];

        $t = $pipeline->triggers;
        $pipelines = [];

        if ($t->pullRequest) {
            $items = $this->items($pipeline->jobsFor('pull_request'), $pipeline, $ctx);
            $items !== [] && $pipelines['pull-requests'] = ['**' => $items];
        }

        $branches = [];
        foreach ($t->pushBranches as $branch) {
            $items = $this->items($pipeline->jobsFor('push', $branch), $pipeline, $ctx);
            $items !== [] && $branches[$branch] = $items;
        }
        $branches !== [] && $pipelines['branches'] = $branches;

        $tags = [];
        foreach ($t->tags as $pattern) {
            $jobs = array_values(array_filter(
                $pipeline->jobsFor('tag'),
                static fn (Job $j): bool => $j->tags === null || Pipeline::matchesAny($pattern, $j->tags)
            ));
            $items = $this->items($jobs, $pipeline, $ctx);
            $items !== [] && $tags[$pattern] = $items;
        }
        $tags !== [] && $pipelines['tags'] = $tags;

        $custom = [];
        if ($t->schedule !== []) {
            $items = $this->items($pipeline->jobsFor('schedule'), $pipeline, $ctx);
            $items !== [] && $custom['scheduled'] = $items;
        }
        if ($t->manual) {
            $items = $this->items($pipeline->jobsFor('manual'), $pipeline, $ctx);
            $items !== [] && $custom['manual'] = $items;
        }
        $custom !== [] && $pipelines['custom'] = $custom;

        if ($pipelines === []) {
            throw new InvalidArgumentException('bitbucket: no pipeline would run for any trigger.');
        }

        $doc = [];
        $definitions = [];
        $ctx['caches'] !== [] && $definitions['caches'] = $ctx['caches'];
        $ctx['services'] !== [] && $definitions['services'] = $ctx['services'];
        $definitions !== [] && $doc['definitions'] = $definitions;
        $doc['pipelines'] = $pipelines;

        return Yaml::dump($this->deepMerge($doc, $options['raw'] ?? []));
    }

    // ---------------------------------------------------------------------

    /**
     * @param list<Job>           $jobs
     * @param array<string,mixed> $ctx
     * @return list<array<string,mixed>>
     */
    private function items(array $jobs, Pipeline $pipeline, array &$ctx): array
    {
        $byStage = [];
        foreach ($jobs as $job) {
            $byStage[$job->stage][] = $job;
        }

        $items = [];
        foreach ($byStage as $stageJobs) {
            $steps = [];
            foreach ($stageJobs as $job) {
                foreach ($this->expand($job, $ctx) as $step) {
                    $steps[] = ['step' => $step];
                }
            }

            if (count($steps) === 1) {
                $items[] = $steps[0];
            } elseif ($steps !== []) {
                $items[] = ['parallel' => ['steps' => $steps]];
            }
        }

        return $items;
    }

    /**
     * One Bitbucket step per job, or one per PHP version when the job is a matrix.
     *
     * @param array<string,mixed> $ctx
     * @return list<array<string,mixed>>
     */
    private function expand(Job $job, array &$ctx): array
    {
        if (! $job->needsPhp()) {
            return [$this->step($job, null, $ctx)];
        }

        $out = [];
        foreach ($job->phpVersions as $version) {
            $out[] = $this->step($job, $version, $ctx);
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private function step(Job $job, ?string $php, array &$ctx): array
    {
        $title = $job->name . ($job->isMatrix() ? " (PHP {$php})" : '');
        $step = ['name' => $title];

        $step['image'] = $php !== null ? str_replace('{php}', $php, $ctx['image']) : $ctx['shell_image'];
        $ctx['size'] !== null && $step['size'] = $ctx['size'];
        $step['max-time'] = $job->timeoutMinutes;

        if ($job->consumesArtifact) {
            $step['clone'] = ['enabled' => false];
        }

        if ($job->environment !== null) {
            $step['deployment'] = $job->environment;
        }

        if ($job->manual) {
            $step['trigger'] = 'manual';
        }

        // Caches: one definition per (cache, PHP version) so versions never share vendor/.
        $names = [];
        foreach ($job->caches as $cache) {
            $name = $cache->name === 'node' || $php === null ? $cache->name : $cache->name . '-php' . str_replace('.', '', $php);
            $ctx['caches'][$name] = ['key' => ['files' => $cache->keyFiles], 'path' => $this->bareDir($cache->paths[0])];
            $names[] = $name;
        }
        $names !== [] && $step['caches'] = $names;

        if ($job->services !== []) {
            if (! $job->needsPhp()) {
                throw new InvalidArgumentException("bitbucket: job [{$job->id}] uses services but has no PHP runtime to wait for them.");
            }
            $step['services'] = [];
            foreach ($job->services as $service) {
                $ctx['services'][$service->name] = $this->serviceDefinition($service);
                $step['services'][] = $service->name;
            }
        }

        $prelude = $this->prelude();
        $script = [$prelude];

        $setup = $this->containerSetup($job, $php !== null, $ctx['bootstrap']);
        $setup !== '' && $script[] = $setup;

        if ($job->services !== []) {
            $script[] = $this->waitForPorts($this->servicePorts($job), '127.0.0.1');
        }

        $env = $this->jobEnv($job, static fn (Service $s): string => '127.0.0.1');
        if ($env !== []) {
            $script[] = $this->shellExports($env);
        }

        $after = [];
        foreach ($job->steps as $s) {
            if ($s->type === StepType::ArtifactUpload || $s->type === StepType::ArtifactDownload) {
                continue; // handled by `artifacts:` / automatic artifact hand-over
            }

            $exports = $this->shellExports(array_merge($s->env, []));

            if ($s->runOn === 'success') {
                $script[] = $this->failFast(($exports !== '' ? $exports . "\n" : '') . rtrim((string) $s->run, "\n"));
                continue;
            }

            $guard = $s->runOn === 'failure' ? 'if [ "$BITBUCKET_EXIT_CODE" != "0" ]; then' . "\n" : '';
            $close = $s->runOn === 'failure' ? "fi\n" : '';
            $after[] = $guard . $this->failFast(($exports !== '' ? $exports . "\n" : '') . rtrim((string) $s->run, "\n")) . $close;
        }

        $step['script'] = $script;

        if ($after !== []) {
            $step['after-script'] = array_merge([$prelude], $after);
        }

        if ($job->artifactPaths !== []) {
            $step['artifacts'] = array_map(fn (string $p): string => $this->globPath($p), $job->artifactPaths);
        }

        return $step;
    }

    /** @return array<string,mixed> */
    private function serviceDefinition(Service $service): array
    {
        $def = ['image' => $service->image];
        $service->env !== [] && $def['variables'] = $service->env;

        return $def;
    }

    /** @return list<int> */
    private function servicePorts(Job $job): array
    {
        $ports = [];
        foreach ($job->services as $service) {
            foreach ($service->ports as $port) {
                $ports[] = $port;
            }
        }

        return $ports;
    }

    private function prelude(): string
    {
        return <<<'SH'
export SLIPWAY_SHA="$BITBUCKET_COMMIT"
export SLIPWAY_REF="${BITBUCKET_BRANCH:-${BITBUCKET_TAG:-unknown}}"
export SLIPWAY_REPO="$BITBUCKET_REPO_FULL_NAME"
export SLIPWAY_RUN_URL="https://bitbucket.org/$BITBUCKET_REPO_FULL_NAME/pipelines/results/$BITBUCKET_BUILD_NUMBER"
SH;
    }
}
