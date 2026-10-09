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
 * GitLab CI/CD.
 *
 * Stages map 1:1 to GitLab stages, so no `needs:` graph is emitted: stage order alone sequences
 * the pipeline, and every job automatically receives the artifacts of earlier stages.
 *
 * Driver options (config: drivers.gitlab.options):
 *   image        PHP job image template, "{php}" is replaced; default "php:{php}-cli"
 *   shell_image  image for shell-only jobs (deploys); default "alpine:3.22"
 *   bootstrap    list of shell lines that REPLACE the built-in PHP/Node bootstrap
 *   tags         list of runner tags applied to every job
 */
final class GitLabDriver extends AbstractDriver
{
    /** Top-level keywords that cannot be used as job names. */
    private const RESERVED = [
        'image', 'services', 'stages', 'types', 'before_script', 'after_script', 'variables',
        'cache', 'include', 'default', 'workflow', 'pages',
    ];

    public function name(): string
    {
        return 'gitlab';
    }

    public function label(): string
    {
        return 'GitLab CI/CD';
    }

    public function defaultPath(): string
    {
        return '.gitlab-ci.yml';
    }

    public function capabilities(): array
    {
        return [
            Feature::Matrix->value => Support::Full,
            Feature::Services->value => Support::Full,
            Feature::Approvals->value => Support::Full,
            Feature::Environments->value => Support::Full,
            Feature::Concurrency->value => Support::Partial,
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
            Feature::Concurrency->value => 'Jobs are marked "interruptible". The project setting "Auto-cancel redundant pipelines" (Settings > CI/CD > General pipelines) must be on. Deploy jobs are never interruptible.',
            Feature::Schedule->value => 'Rules match scheduled pipelines, but the schedule itself is created under Build > Pipeline schedules; GitLab does not store cron in the YAML.',
            Feature::Artifacts->value => 'Artifacts expire after one week, so a manual approval older than that cannot deploy; re-run the build.',
            Feature::PathFilters->value => 'GitLab "changes" rules can only include paths, so "paths_ignore" cannot be expressed.',
        ];
    }

    public function render(Pipeline $pipeline, array $options = []): string
    {
        $o = $options['options'] ?? [];
        $this->assertOptions($o, ['image', 'shell_image', 'bootstrap', 'tags']);

        $image = $this->stringOption($o, 'image', 'php:{php}-cli');
        $shellImage = $this->stringOption($o, 'shell_image', 'alpine:3.22');
        $bootstrap = $o['bootstrap'] ?? null;
        if ($bootstrap !== null && (! is_array($bootstrap) || ! array_is_list($bootstrap))) {
            throw new InvalidArgumentException('gitlab: option [bootstrap] must be a list of shell lines.');
        }
        $tags = $o['tags'] ?? [];
        if (! is_array($tags) || ! array_is_list($tags)) {
            throw new InvalidArgumentException('gitlab: option [tags] must be a list of runner tags.');
        }

        $doc = [
            'workflow' => ['name' => $pipeline->name, 'rules' => $this->workflowRules($pipeline)],
            'stages' => array_keys($pipeline->stages()),
            'variables' => [
                'SLIPWAY_SHA' => '$CI_COMMIT_SHA',
                'SLIPWAY_REF' => '$CI_COMMIT_REF_NAME',
                'SLIPWAY_REPO' => '$CI_PROJECT_PATH',
                'SLIPWAY_RUN_URL' => '$CI_PIPELINE_URL',
            ],
        ];

        foreach ($pipeline->jobs as $job) {
            if (in_array($job->id, self::RESERVED, true)) {
                throw new InvalidArgumentException(
                    "gitlab: [{$job->id}] is a reserved GitLab keyword and cannot be a job name. Rename the stage."
                );
            }

            $doc[$job->id] = $this->job($job, $pipeline, $image, $shellImage, $bootstrap, $tags);
        }

        return Yaml::dump($this->deepMerge($doc, $options['raw'] ?? []));
    }

    // ---------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function workflowRules(Pipeline $pipeline): array
    {
        $t = $pipeline->triggers;
        $rules = [];

        if ($t->pullRequest && ($t->hasPush() || $t->hasTags())) {
            // Avoid duplicate branch + merge-request pipelines for the same commit.
            $rules[] = ['if' => '$CI_COMMIT_BRANCH && $CI_OPEN_MERGE_REQUESTS && $CI_PIPELINE_SOURCE == "push"', 'when' => 'never'];
        }

        foreach ($this->eventConditions($pipeline, ['pull_request', 'push', 'tag', 'schedule', 'manual'], null, null) as $condition) {
            $rules[] = ['if' => $condition];
        }

        return $rules;
    }

    /** @return array<string,mixed> */
    private function job(Job $job, Pipeline $pipeline, string $image, string $shellImage, ?array $bootstrap, array $tags): array
    {
        $hasPhp = $job->needsPhp();
        $out = ['stage' => $job->stage];

        $out['image'] = $hasPhp ? str_replace('{php}', $job->isMatrix() ? '$PHP_VERSION' : $job->phpVersions[0], $image) : $shellImage;
        $tags !== [] && $out['tags'] = $tags;
        $out['timeout'] = $job->timeoutMinutes . 'm';

        if ($pipeline->cancelSuperseded && $job->environment === null) {
            $out['interruptible'] = true;
        }

        $rules = $this->jobRules($job, $pipeline);
        $rules !== [] && $out['rules'] = $rules;

        if ($job->manual) {
            $out['allow_failure'] = false; // block the pipeline at the gate instead of skipping past it
        }

        if ($job->isMatrix()) {
            $out['parallel'] = ['matrix' => [['PHP_VERSION' => $job->phpVersions]]];
        }

        if ($job->environment !== null) {
            $out['environment'] = ['name' => $job->environment];
            $out['resource_group'] = $job->environment; // one deploy per environment at a time
        }

        if ($job->consumesArtifact) {
            $out['variables']['GIT_STRATEGY'] = 'none'; // deploy the artifact, not a fresh checkout
        }

        $env = $this->jobEnv($job, static fn (Service $s): string => $s->name);
        foreach ($env as $name => $value) {
            $out['variables'][$name] = $value;
        }

        if ($job->services !== []) {
            $out['services'] = array_map(static function (Service $service): array {
                $def = ['name' => $service->image, 'alias' => $service->name];
                $service->env !== [] && $def['variables'] = $service->env;

                return $def;
            }, $job->services);
        }

        $caches = [];
        foreach ($job->caches as $cache) {
            $prefix = $cache->name === 'node' || ! $hasPhp
                ? $cache->name
                : $cache->name . '-php' . ($job->isMatrix() ? '$PHP_VERSION' : $job->phpVersions[0]);
            $caches[] = ['key' => ['files' => $cache->keyFiles, 'prefix' => $prefix], 'paths' => $cache->paths];
        }
        $caches !== [] && $out['cache'] = $caches;

        $before = [];
        $setup = $this->containerSetup($job, $hasPhp, $bootstrap);
        $setup !== '' && $before[] = $setup;
        foreach ($job->services as $service) {
            // GitLab starts services but does not guarantee they accept connections yet.
            $before[] = $this->waitForPorts($service->ports, $service->name);
        }
        $before !== [] && $out['before_script'] = $before;

        $script = [];
        $after = [];
        foreach ($job->steps as $step) {
            if ($step->type !== StepType::Run) {
                continue; // artifacts are declared on the job / handed over automatically
            }

            $exports = $this->shellExports($step->env);
            $body = $this->failFast(($exports !== '' ? $exports . "\n" : '') . rtrim((string) $step->run, "\n"));

            if ($step->runOn === 'success') {
                $script[] = $body;
            } elseif ($step->runOn === 'failure') {
                $after[] = 'if [ "$CI_JOB_STATUS" = "failed" ]; then' . "\n" . $body . "fi\n";
            } else {
                $after[] = $body;
            }
        }

        $out['script'] = $script === [] ? ['true'] : $script;
        $after !== [] && $out['after_script'] = $after;

        if ($job->artifactPaths !== []) {
            $out['artifacts'] = ['paths' => $job->artifactPaths, 'expire_in' => '1 week'];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function jobRules(Job $job, Pipeline $pipeline): array
    {
        $enabled = $this->enabledEvents($pipeline);
        $events = $job->events;
        sort($events);
        sort($enabled);

        if ($events === $enabled && $job->branches === null && $job->tags === null && ! $job->manual) {
            return [];
        }

        $rules = [];
        foreach ($this->eventConditions($pipeline, $job->events, $job->branches, $job->tags) as $condition) {
            $rule = ['if' => $condition];
            $job->manual && $rule['when'] = 'manual';
            $rules[] = $rule;
        }

        return $rules;
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
    private function eventConditions(Pipeline $pipeline, array $events, ?array $branches, ?array $tags): array
    {
        $t = $pipeline->triggers;
        $out = [];

        foreach ($events as $event) {
            switch ($event) {
                case 'pull_request':
                    if ($t->pullRequest) {
                        $cond = '$CI_PIPELINE_SOURCE == "merge_request_event"';
                        if ($t->pullRequestBranches !== []) {
                            $cond .= ' && (' . $this->anyMatch('$CI_MERGE_REQUEST_TARGET_BRANCH_NAME', $t->pullRequestBranches) . ')';
                        }
                        $out[] = $cond;
                    }
                    break;

                case 'push':
                    if ($t->hasPush()) {
                        $out[] = '$CI_COMMIT_BRANCH && $CI_PIPELINE_SOURCE == "push" && ('
                            . $this->anyMatch('$CI_COMMIT_BRANCH', $branches ?? $t->pushBranches) . ')';
                    }
                    break;

                case 'tag':
                    if ($t->hasTags()) {
                        $out[] = '$CI_COMMIT_TAG && (' . $this->anyMatch('$CI_COMMIT_TAG', $tags ?? $t->tags) . ')';
                    }
                    break;

                case 'schedule':
                    $t->schedule !== [] && $out[] = '$CI_PIPELINE_SOURCE == "schedule"';
                    break;

                case 'manual':
                    $t->manual && $out[] = '$CI_PIPELINE_SOURCE == "web"';
                    break;

                default:
                    throw new InvalidArgumentException("gitlab: unknown event [{$event}].");
            }
        }

        return $out;
    }

    /** @param list<string> $patterns */
    private function anyMatch(string $variable, array $patterns): string
    {
        return implode(' || ', array_map(function (string $pattern) use ($variable): string {
            if (str_contains($pattern, '"')) {
                throw new InvalidArgumentException("gitlab: pattern [{$pattern}] cannot contain a double quote.");
            }

            if (preg_match('/[*?\[]/', $pattern) !== 1) {
                return "{$variable} == \"{$pattern}\"";
            }

            return "{$variable} =~ " . $this->regexFromGlob($pattern);
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
}
