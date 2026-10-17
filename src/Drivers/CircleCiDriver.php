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
 * CircleCI
 *
 * Driver options (config: drivers.circleci.options):
 *   image           PHP job image template, "{php}" is replaced; default "php:{php}-cli"
 *   shell_image     image for shell-only jobs (deploys); default "alpine:3.22"
 *   bootstrap       list of shell lines that REPLACE the built-in PHP/Node bootstrap
 *   resource_class  CircleCI resource class applied to every job, e.g. "medium"
 *   context         list of CircleCI contexts applied to every job that uses a secret
 *   serial_deploys  serialise deploys per environment with "serial-group"; default true
 */
final class CircleCiDriver extends AbstractDriver
{
    private const string WORKING_DIRECTORY = '~/project';

    /** `checkout` needs git and ssh, which the plain php and alpine images do not ship. */
    private const string GIT_SETUP = <<<'SH'
if command -v apt-get >/dev/null 2>&1; then
  apt-get update -qq
  apt-get install -y -qq --no-install-recommends ca-certificates git openssh-client
elif command -v apk >/dev/null 2>&1; then
  apk add --no-cache ca-certificates git openssh-client
fi
SH;

    private const string PRELUDE = <<<'SH'
export SLIPWAY_SHA="$CIRCLE_SHA1"
export SLIPWAY_REF="${CIRCLE_BRANCH:-${CIRCLE_TAG:-unknown}}"
export SLIPWAY_REPO="$CIRCLE_PROJECT_USERNAME/$CIRCLE_PROJECT_REPONAME"
export SLIPWAY_RUN_URL="$CIRCLE_BUILD_URL"
SH;

    public function name(): string
    {
        return 'circleci';
    }

    public function label(): string
    {
        return 'CircleCI';
    }

    public function defaultPath(): string
    {
        return '.circleci/config.yml';
    }

    public function capabilities(): array
    {
        return [
            Feature::Matrix->value => Support::Full,
            Feature::Services->value => Support::Full,
            Feature::Approvals->value => Support::Full,
            Feature::Environments->value => Support::Partial,
            Feature::Concurrency->value => Support::Partial,
            Feature::Schedule->value => Support::Partial,
            Feature::ManualTrigger->value => Support::Partial,
            Feature::Artifacts->value => Support::Partial,
            Feature::PathFilters->value => Support::None,
            Feature::Timeouts->value => Support::Partial,
            Feature::Caching->value => Support::Full,
            Feature::ParallelJobs->value => Support::Full,
            Feature::TagTriggers->value => Support::Full,
        ];
    }

    public function notes(): array
    {
        return [
            Feature::Environments->value => 'CircleCI has no deployment environments in YAML. Deploy jobs are serialised per environment with "serial-group"; secrets come from project environment variables or the contexts listed in the "context" option, which you must create under Organization Settings > Contexts.',
            Feature::Concurrency->value => 'Superseded runs are not cancelled by YAML. Turn on "Auto-cancel redundant workflows" under Project Settings > Advanced. Deploys use "serial-group", which queues them and skips an older pipeline behind a newer one.',
            Feature::Schedule->value => 'Rendered as a match on pipeline.event.name == "schedule". CircleCI does not store cron in this YAML for GitHub App or GitLab projects; create the schedule trigger under Project Settings > Triggers or through the API.',
            Feature::ManualTrigger->value => 'Rendered as a match on pipeline.event.name == "api". Start the pipeline with "Trigger Pipeline" in the CircleCI web app or through the API.',
            Feature::Artifacts->value => 'The build is handed to later jobs through a workspace, which CircleCI keeps for 15 days by default (Plan > Usage Controls), so a manual approval older than that cannot deploy; re-run the build.',
            Feature::PathFilters->value => 'CircleCI can only filter by path through dynamic configuration (the path-filtering orb), which Slipway does not generate, so "paths_ignore" cannot be expressed.',
            Feature::Timeouts->value => 'CircleCI has no per-job timeout in YAML. The value is applied as "no_output_timeout" on each command, which limits silence, not total runtime; the overall job limit comes from your plan.',
        ];
    }

    public function render(Pipeline $pipeline, array $options = []): string
    {
        $o = $this->resolveOptions($options['options'] ?? []);

        $jobs = [];
        foreach ($pipeline->jobs as $job) {
            $jobs[$job->id] = $this->job($job, $o);
        }

        $workflow = [];
        $when = $this->conditions($pipeline, $this->enabledEvents($pipeline), null, null);
        $when !== [] && $workflow['when'] = $this->anyOf($when);
        $workflow['jobs'] = $this->workflowJobs($pipeline, $o);

        $doc = [
            'version' => '2.1',
            'jobs' => $jobs,
            'workflows' => [$this->workflowName($pipeline) => $workflow],
        ];

        return Yaml::dump($this->deepMerge($doc, $options['raw'] ?? []));
    }

    // ---------------------------------------------------------------------

    /**
     * @param array<string,mixed> $given
     * @return array{image:string,shell_image:string,bootstrap:?array,resource_class:?string,context:list<string>,serial_deploys:bool}
     */
    private function resolveOptions(array $given): array
    {
        $this->assertOptions($given, ['image', 'shell_image', 'bootstrap', 'resource_class', 'context', 'serial_deploys']);

        $bootstrap = $given['bootstrap'] ?? null;
        if ($bootstrap !== null && (! is_array($bootstrap) || ! array_is_list($bootstrap))) {
            throw new InvalidArgumentException('circleci: option [bootstrap] must be a list of shell lines.');
        }

        $context = $given['context'] ?? [];
        if (is_string($context) && $context !== '') {
            $context = [$context];
        }
        if (! is_array($context) || ! array_is_list($context) || array_filter($context, static fn (mixed $c): bool => ! is_string($c) || $c === '') !== []) {
            throw new InvalidArgumentException('circleci: option [context] must be a context name or a list of context names.');
        }

        $resourceClass = $given['resource_class'] ?? null;
        if ($resourceClass !== null && (! is_string($resourceClass) || $resourceClass === '')) {
            throw new InvalidArgumentException('circleci: option [resource_class] must be a non-empty string.');
        }

        $serial = $given['serial_deploys'] ?? true;
        if (! is_bool($serial)) {
            throw new InvalidArgumentException('circleci: option [serial_deploys] must be true or false.');
        }

        return [
            'image' => $this->stringOption($given, 'image', 'php:{php}-cli'),
            'shell_image' => $this->stringOption($given, 'shell_image', 'alpine:3.22'),
            'bootstrap' => $bootstrap,
            'resource_class' => $resourceClass,
            'context' => array_values($context),
            'serial_deploys' => $serial,
        ];
    }

    /**
     * @param array<string,mixed> $o Resolved options.
     * @return array<string,mixed>
     */
    private function job(Job $job, array $o): array
    {
        $hasPhp = $job->needsPhp();
        $php = $job->isMatrix() ? '<< parameters.php_version >>' : ($job->phpVersions[0] ?? null);
        $timeout = $job->timeoutMinutes . 'm';

        if ($job->services !== [] && ! $hasPhp) {
            throw new InvalidArgumentException("circleci: job [{$job->id}] uses services but has no PHP runtime to wait for them.");
        }

        $out = [];
        $job->isMatrix() && $out['parameters'] = ['php_version' => ['type' => 'string']];

        $docker = [['image' => $hasPhp ? str_replace('{php}', (string) $php, $o['image']) : $o['shell_image']]];
        foreach ($job->services as $service) {
            $definition = ['image' => $service->image];
            $service->env !== [] && $definition['environment'] = $service->env;
            $docker[] = $definition;
        }
        $out['docker'] = $docker;

        $o['resource_class'] !== null && $out['resource_class'] = $o['resource_class'];
        $out['working_directory'] = self::WORKING_DIRECTORY;

        $env = $this->jobEnv($job, static fn (Service $s): string => '127.0.0.1');
        $env !== [] && $out['environment'] = $env;

        $steps = [];

        if ($job->consumesArtifact) {
            $steps[] = ['attach_workspace' => ['at' => '.']]; // deploy the build, not a fresh checkout
        } else {
            $steps[] = $this->run('Install Git and SSH', $this->failFast(self::GIT_SETUP), $timeout);
            $steps[] = 'checkout';
        }

        foreach ($job->caches as $cache) {
            $steps[] = ['restore_cache' => ['keys' => [$this->cacheKey($cache->name, $cache->keyFiles, $php, $hasPhp), $this->cacheKey($cache->name, [], $php, $hasPhp)]]];
        }

        $setup = $this->containerSetup($job, $hasPhp, $o['bootstrap']);
        $setup !== '' && $steps[] = $this->run('Prepare environment', $setup, $timeout);

        if ($job->services !== []) {
            $ports = [];
            foreach ($job->services as $service) {
                array_push($ports, ...$service->ports);
            }
            $steps[] = $this->run('Wait for services', $this->waitForPorts($ports, '127.0.0.1'), $timeout);
        }

        $trailing = [];
        foreach ($job->steps as $step) {
            if ($step->type !== StepType::Run) {
                continue; // the artifact is handed over by persist_to_workspace / attach_workspace
            }

            $command = $this->failFast($this->withPrelude(rtrim((string) $step->run, "\n")));

            if ($step->runOn === 'success') {
                $steps[] = $this->run($step->name, $command, $timeout, $step->env);
            } else {
                $trailing[] = $this->run($step->name, $command, $timeout, $step->env, $step->runOn === 'failure' ? 'on_fail' : 'always');
            }
        }

        foreach ($job->caches as $cache) {
            $steps[] = ['save_cache' => [
                'key' => $this->cacheKey($cache->name, $cache->keyFiles, $php, $hasPhp),
                'paths' => array_map($this->bareDir(...), $cache->paths),
            ]];
        }

        if ($job->artifactPaths !== []) {
            // Go's filepath.Match has no "**"; a plain directory path persists the whole directory.
            $steps[] = ['persist_to_workspace' => ['root' => '.', 'paths' => array_map($this->bareDir(...), $job->artifactPaths)]];
        }

        $out['steps'] = [...$steps, ...$trailing];

        return $out;
    }

    /**
     * @param array<string,string> $env
     * @return array<string,mixed>
     */
    private function run(string $name, string $command, string $timeout, array $env = [], ?string $when = null): array
    {
        $run = ['name' => $name, 'command' => $command, 'no_output_timeout' => $timeout];
        $env !== [] && $run['environment'] = $env;
        $when !== null && $run['when'] = $when;

        return ['run' => $run];
    }

    /** Each CircleCI step is its own shell, so the SLIPWAY_* variables are defined where they are used. */
    private function withPrelude(string $command): string
    {
        return str_contains($command, 'SLIPWAY_') ? self::PRELUDE . "\n" . $command : $command;
    }

    /**
     * @param list<string> $keyFiles Empty = the prefix used as a restore fallback.
     */
    private function cacheKey(string $name, array $keyFiles, ?string $php, bool $hasPhp): string
    {
        $key = 'v1-' . $name;

        if ($this->cacheKeyedByPhp($name) && $hasPhp && $php !== null) {
            $key .= '-php' . $php;
        }

        $key .= '-';

        foreach ($keyFiles as $file) {
            $key .= '{{ checksum "' . $file . '" }}-';
        }

        return $keyFiles === [] ? $key : rtrim($key, '-');
    }

    // ---------------------------------------------------------------------

    /**
     * @param array<string,mixed> $o
     * @return list<string|array<string,mixed>>
     */
    private function workflowJobs(Pipeline $pipeline, array $o): array
    {
        $enabled = $this->enabledEvents($pipeline);
        $ids = array_map(static fn (Job $job): string => $job->id, $pipeline->jobs);
        $entries = [];

        foreach ($pipeline->jobs as $job) {
            $approvalId = 'approve-' . $job->id;
            if ($job->manual && in_array($approvalId, $ids, true)) {
                throw new InvalidArgumentException("circleci: the approval job for [{$job->id}] would be named [{$approvalId}], which is already a job. Rename one of them.");
            }

            $filter = $this->jobFilter($job, $pipeline, $enabled);
            $requires = $job->needs;

            if ($job->manual) {
                $approval = ['type' => 'approval'];
                $job->needs !== [] && $approval['requires'] = $job->needs;
                $filter !== null && $approval['filters'] = $filter;
                $entries[] = [$approvalId => $approval];
                $requires = [$approvalId, ...$job->needs];
            }

            $entry = [];
            $requires !== [] && $entry['requires'] = $requires;
            $filter !== null && $entry['filters'] = $filter;

            if ($job->isMatrix()) {
                $entry['matrix'] = ['parameters' => ['php_version' => $job->phpVersions]];
            }

            if ($job->secretNames() !== [] && $o['context'] !== []) {
                $entry['context'] = $o['context'];
            }

            if ($job->environment !== null && $o['serial_deploys']) {
                $entry['serial-group'] = '<< pipeline.project.slug >>/' . $job->environment;
            }

            $entries[] = $entry === [] ? $job->id : [$job->id => $entry];
        }

        return $entries;
    }

    /** The workflow runs for any enabled event; a job only needs its own filter when it narrows that. */
    private function jobFilter(Job $job, Pipeline $pipeline, array $enabled): ?string
    {
        $events = $job->events;
        sort($events);
        sort($enabled);

        if ($events === $enabled && $job->branches === null && $job->tags === null) {
            return null;
        }

        $conditions = $this->conditions($pipeline, $job->events, $job->branches, $job->tags);

        return $conditions === [] ? 'false' : $this->anyOf($conditions);
    }

    /** @return list<string> */
    private function enabledEvents(Pipeline $pipeline): array
    {
        $t = $pipeline->triggers;
        $events = [];
        $t->pullRequest && $events[] = 'pull_request';
        $t->hasPush() && $events[] = 'push';
        $t->hasTags() && $events[] = 'tag';
        $t->schedule !== [] && $events[] = 'schedule';
        $t->manual && $events[] = 'manual';

        return $events;
    }

    /**
     * @param list<string>      $events
     * @param list<string>|null $branches Null = the pipeline's push branches.
     * @param list<string>|null $tags     Null = the pipeline's tag patterns.
     * @return list<string>
     */
    private function conditions(Pipeline $pipeline, array $events, ?array $branches, ?array $tags): array
    {
        $t = $pipeline->triggers;
        $out = [];

        foreach ($events as $event) {
            switch ($event) {
                case 'pull_request':
                    if ($t->pullRequest) {
                        $condition = 'pipeline.event.name == "pull_request"';
                        if ($t->pullRequestBranches !== []) {
                            $condition .= ' and (' . $this->anyMatch('pipeline.event.github.pull_request.base.ref', $t->pullRequestBranches) . ')';
                        }
                        $out[] = $condition;
                    }
                    break;

                case 'push':
                    if ($t->hasPush()) {
                        $out[] = 'pipeline.event.name == "push" and (' . $this->anyMatch('pipeline.git.branch', $branches ?? $t->pushBranches) . ')';
                    }
                    break;

                case 'tag':
                    if ($t->hasTags()) {
                        $out[] = $this->anyMatch('pipeline.git.tag', $tags ?? $t->tags);
                    }
                    break;

                case 'schedule':
                    $t->schedule !== [] && $out[] = 'pipeline.event.name == "schedule"';
                    break;

                case 'manual':
                    $t->manual && $out[] = 'pipeline.event.name == "api"';
                    break;

                default:
                    throw new InvalidArgumentException("circleci: unknown event [{$event}].");
            }
        }

        return $out;
    }

    /** @param list<string> $conditions */
    private function anyOf(array $conditions): string
    {
        return count($conditions) === 1
            ? $conditions[0]
            : implode(' or ', array_map(static fn (string $condition): string => '(' . $condition . ')', $conditions));
    }

    /** @param list<string> $patterns */
    private function anyMatch(string $variable, array $patterns): string
    {
        return implode(' or ', array_map(function (string $pattern) use ($variable): string {
            if (str_contains($pattern, '"')) {
                throw new InvalidArgumentException("circleci: pattern [{$pattern}] cannot contain a double quote.");
            }

            if (preg_match('/[*?\[]/', $pattern) !== 1) {
                return "{$variable} == \"{$pattern}\"";
            }

            return "{$variable} matches " . $this->regexFromGlob($pattern);
        }, $patterns));
    }

    private function regexFromGlob(string $glob): string
    {
        $regex = '';
        foreach (str_split($glob) as $char) {
            $regex .= match ($char) {
                '*' => '.*',
                '?' => '.',
                default => preg_quote($char, '/'),
            };
        }

        return '/^' . $regex . '$/';
    }

    private function workflowName(Pipeline $pipeline): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($pipeline->name)), '-');

        return $slug === '' ? 'pipeline' : $slug;
    }
}
