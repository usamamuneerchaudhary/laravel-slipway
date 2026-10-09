<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Drivers;

use InvalidArgumentException;
use Usamamuneerchaudhary\Slipway\Model\Feature;
use Usamamuneerchaudhary\Slipway\Model\Job;
use Usamamuneerchaudhary\Slipway\Model\Pipeline;
use Usamamuneerchaudhary\Slipway\Model\Service;
use Usamamuneerchaudhary\Slipway\Model\Step;
use Usamamuneerchaudhary\Slipway\Model\StepType;
use Usamamuneerchaudhary\Slipway\Model\Support;
use Usamamuneerchaudhary\Slipway\Support\Yaml;

/**
 * GitHub Actions.
 *
 * Driver options (config: drivers.github.options):
 *   runner       default "ubuntu-latest"
 *   permissions  workflow GITHUB_TOKEN permissions; default ['contents' => 'read'] (least privilege)
 *   pins         action name => ref (tag or full commit SHA) overriding the built-in defaults, e.g.
 *                ['actions/checkout' => '<40-char sha>']. Pinning to SHAs is recommended.
 */
final class GitHubDriver extends AbstractDriver
{
    /** Conservative defaults. Pin to commit SHAs through the `pins` option for supply-chain safety. */
    private const ACTIONS = [
        'actions/checkout' => 'v4',
        'actions/cache' => 'v4',
        'actions/setup-node' => 'v4',
        'actions/upload-artifact' => 'v4',
        'actions/download-artifact' => 'v4',
        'shivammathur/setup-php' => 'v2',
    ];

    /** @var array<string,string> */
    private array $pins = [];

    public function name(): string
    {
        return 'github';
    }

    public function label(): string
    {
        return 'GitHub Actions';
    }

    public function defaultPath(): string
    {
        return '.github/workflows/slipway.yml';
    }

    public function capabilities(): array
    {
        return [
            Feature::Matrix->value => Support::Full,
            Feature::Services->value => Support::Full,
            Feature::Approvals->value => Support::Partial,
            Feature::Environments->value => Support::Full,
            Feature::Concurrency->value => Support::Full,
            Feature::Schedule->value => Support::Full,
            Feature::ManualTrigger->value => Support::Full,
            Feature::Artifacts->value => Support::Full,
            Feature::PathFilters->value => Support::Full,
            Feature::Timeouts->value => Support::Full,
            Feature::Caching->value => Support::Full,
            Feature::ParallelJobs->value => Support::Full,
            Feature::TagTriggers->value => Support::Full,
        ];
    }

    public function notes(): array
    {
        return [
            Feature::Approvals->value => 'Rendered as a GitHub Environment. Required reviewers are repository settings that YAML cannot create or enforce: configure them under Settings > Environments.',
        ];
    }

    public function render(Pipeline $pipeline, array $options = []): string
    {
        $o = $options['options'] ?? [];
        $this->assertOptions($o, ['runner', 'permissions', 'pins']);

        $runner = $this->stringOption($o, 'runner', 'ubuntu-latest');
        $permissions = $o['permissions'] ?? ['contents' => 'read'];
        if (! is_array($permissions)) {
            throw new InvalidArgumentException('github: option [permissions] must be an array such as [\'contents\' => \'read\'].');
        }

        $this->pins = [];
        foreach ($o['pins'] ?? [] as $action => $ref) {
            if (! isset(self::ACTIONS[$action]) || ! is_string($ref) || preg_match('/^[A-Za-z0-9._\/-]+$/', $ref) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'github: option [pins] must map one of (%s) to a tag or commit SHA.',
                    implode(', ', array_keys(self::ACTIONS))
                ));
            }
            $this->pins[$action] = $ref;
        }

        $workflow = [
            'name' => $pipeline->name,
            'on' => $this->triggers($pipeline),
            'permissions' => $permissions,
        ];

        if ($pipeline->cancelSuperseded) {
            // Only pull-request runs are cancelled when superseded. A push that is mid-deploy
            // must be allowed to finish.
            $workflow['concurrency'] = [
                'group' => '${{ github.workflow }}-${{ github.ref }}',
                'cancel-in-progress' => "\${{ github.event_name == 'pull_request' }}",
            ];
        }

        $jobs = [];
        foreach ($pipeline->jobs as $job) {
            $jobs[$job->id] = $this->job($job, $pipeline, $runner);
        }
        $workflow['jobs'] = $jobs;

        $workflow = $this->deepMerge($workflow, $options['raw'] ?? []);

        return Yaml::dump($workflow);
    }

    // ---------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function triggers(Pipeline $pipeline): array
    {
        $t = $pipeline->triggers;
        $on = [];

        if ($t->hasPush() || $t->hasTags()) {
            $push = [];
            $t->hasPush() && $push['branches'] = $t->pushBranches;
            $t->hasTags() && $push['tags'] = $t->tags;
            $t->pathsIgnore !== [] && $push['paths-ignore'] = $t->pathsIgnore;
            $on['push'] = $push;
        }

        if ($t->pullRequest) {
            $pr = [];
            $t->pullRequestBranches !== [] && $pr['branches'] = $t->pullRequestBranches;
            $t->pathsIgnore !== [] && $pr['paths-ignore'] = $t->pathsIgnore;
            $on['pull_request'] = $pr === [] ? null : $pr;
        }

        if ($t->schedule !== []) {
            $on['schedule'] = array_map(static fn (string $cron): array => ['cron' => $cron], $t->schedule);
        }

        if ($t->manual) {
            $on['workflow_dispatch'] = null;
        }

        return $on;
    }

    /** @return array<string,mixed> */
    private function job(Job $job, Pipeline $pipeline, string $runner): array
    {
        $out = ['name' => $this->jobName($job)];

        if ($job->needs !== []) {
            $out['needs'] = $job->needs;
        }

        $condition = $this->condition($job, $pipeline);
        if ($condition !== null) {
            $out['if'] = $condition;
        }

        $out['runs-on'] = $runner;
        $out['timeout-minutes'] = $job->timeoutMinutes;

        if ($job->environment !== null) {
            $out['environment'] = $job->environment;
            // Deploys for one environment queue instead of cancelling each other.
            $out['concurrency'] = ['group' => 'slipway-deploy-' . $job->environment, 'cancel-in-progress' => false];
        }

        if ($job->isMatrix()) {
            $out['strategy'] = ['fail-fast' => false, 'matrix' => ['php' => $job->phpVersions]];
        }

        if ($job->services !== []) {
            $out['services'] = [];
            foreach ($job->services as $service) {
                $out['services'][$service->name] = $this->service($service);
            }
        }

        $env = array_merge(
            $this->slipwayEnv(),
            $this->jobEnv($job, static fn (Service $s): string => '127.0.0.1')
        );
        $out['env'] = $env;

        $out['steps'] = $this->steps($job);

        return $out;
    }

    private function jobName(Job $job): string
    {
        return $job->isMatrix() ? $job->name . ' (PHP ${{ matrix.php }})' : $job->name;
    }

    /** @return array<string,string> */
    private function slipwayEnv(): array
    {
        return [
            'SLIPWAY_SHA' => '${{ github.sha }}',
            'SLIPWAY_REF' => '${{ github.ref_name }}',
            'SLIPWAY_REPO' => '${{ github.repository }}',
            'SLIPWAY_RUN_URL' => '${{ github.server_url }}/${{ github.repository }}/actions/runs/${{ github.run_id }}',
        ];
    }

    /** @return array<string,mixed> */
    private function service(Service $service): array
    {
        $def = ['image' => $service->image];

        if ($service->env !== []) {
            $def['env'] = $service->env;
        }

        if ($service->ports !== []) {
            $def['ports'] = array_map(static fn (int $p): string => "{$p}:{$p}", $service->ports);
        }

        if ($service->healthCmd !== null) {
            $def['options'] = '--health-cmd="' . $service->healthCmd . '" --health-interval=10s --health-timeout=5s --health-retries=10';
        }

        return $def;
    }

    /** @return list<array<string,mixed>> */
    private function steps(Job $job): array
    {
        $steps = [];
        $phpRef = $job->isMatrix() ? '${{ matrix.php }}' : ($job->phpVersions[0] ?? '');
        $needsCheckout = ! $job->consumesArtifact;

        if ($needsCheckout) {
            $steps[] = [
                'name' => 'Check out code',
                'uses' => $this->action('actions/checkout'),
                'with' => ['persist-credentials' => false],
            ];
        }

        if ($job->needsPhp()) {
            $with = [
                'php-version' => $phpRef,
                'extensions' => implode(', ', $job->extensions),
                'coverage' => $job->coverage ?? 'none',
                'tools' => 'composer:v2',
            ];
            $steps[] = ['name' => 'Set up PHP', 'uses' => $this->action('shivammathur/setup-php'), 'with' => $with];
        }

        if ($job->node !== null) {
            $steps[] = [
                'name' => 'Set up Node.js',
                'uses' => $this->action('actions/setup-node'),
                'with' => ['node-version' => $job->node],
            ];
        }

        foreach ($job->caches as $cache) {
            $files = implode("', '", $cache->keyFiles);
            $phpPart = $cache->name !== 'node' && $job->needsPhp() ? '-php-' . $phpRef : '';
            $prefix = "{$cache->name}-\${{ runner.os }}{$phpPart}-";
            $steps[] = [
                'name' => 'Cache ' . $cache->name . ' dependencies',
                'uses' => $this->action('actions/cache'),
                'with' => [
                    'path' => implode("\n", $cache->paths),
                    'key' => $prefix . "\${{ hashFiles('{$files}') }}",
                    'restore-keys' => $prefix,
                ],
            ];
        }

        foreach ($job->steps as $step) {
            $steps[] = $this->step($step, $job);
        }

        return $steps;
    }

    /** @return array<string,mixed> */
    private function step(Step $step, Job $job): array
    {
        if ($step->type === StepType::ArtifactUpload) {
            return [
                'name' => $step->name,
                'uses' => $this->action('actions/upload-artifact'),
                'with' => [
                    'name' => self::ARTIFACT_NAME,
                    'path' => implode("\n", array_map(fn (string $p): string => $this->globPath($p), $job->artifactPaths)),
                    'retention-days' => 7,
                    'if-no-files-found' => 'error',
                ],
            ];
        }

        if ($step->type === StepType::ArtifactDownload) {
            return [
                'name' => $step->name,
                'uses' => $this->action('actions/download-artifact'),
                'with' => ['name' => self::ARTIFACT_NAME, 'path' => '.'],
            ];
        }

        $this->assertNoExpressions($step);

        $out = ['name' => $step->name];

        if ($step->runOn === 'failure') {
            $out['if'] = 'failure()';
        } elseif ($step->runOn === 'always') {
            $out['if'] = 'always()';
        }

        $env = $step->env;
        foreach ($step->secrets as $secret) {
            $env[$secret] = '${{ secrets.' . $secret . ' }}';
        }
        if ($env !== []) {
            $out['env'] = $env;
        }

        $out['run'] = rtrim((string) $step->run, "\n") . "\n";

        return $out;
    }

    private function action(string $name): string
    {
        return $name . '@' . ($this->pins[$name] ?? self::ACTIONS[$name]);
    }

    /**
     * Job-level `if:` so a job only runs for the triggers it is meant for. Omitted when the
     * job runs for every enabled trigger.
     */
    private function condition(Job $job, Pipeline $pipeline): ?string
    {
        $enabled = [];
        $t = $pipeline->triggers;
        $t->pullRequest && $enabled[] = 'pull_request';
        $t->hasPush() && $enabled[] = 'push';
        $t->hasTags() && $enabled[] = 'tag';
        $t->schedule !== [] && $enabled[] = 'schedule';
        $t->manual && $enabled[] = 'manual';

        $jobEvents = $job->events;
        sort($jobEvents);
        sort($enabled);

        if ($jobEvents === $enabled && $job->branches === null && $job->tags === null) {
            return null;
        }

        $parts = [];
        foreach ($job->events as $event) {
            $parts[] = match ($event) {
                'pull_request' => "github.event_name == 'pull_request'",
                'push' => $this->pushCondition($job->branches),
                'tag' => $this->tagCondition($job->tags),
                'schedule' => "github.event_name == 'schedule'",
                'manual' => "github.event_name == 'workflow_dispatch'",
                default => throw new InvalidArgumentException("github: unknown event [{$event}]."),
            };
        }

        return implode(' || ', $parts);
    }

    /** @param list<string>|null $branches */
    private function pushCondition(?array $branches): string
    {
        $base = "github.event_name == 'push' && github.ref_type == 'branch'";
        if ($branches === null) {
            return "({$base})";
        }

        return "({$base} && (" . implode(' || ', array_map(fn (string $b): string => $this->nameMatch('github.ref_name', $b), $branches)) . '))';
    }

    /** @param list<string>|null $tags */
    private function tagCondition(?array $tags): string
    {
        $base = "github.ref_type == 'tag'";
        if ($tags === null) {
            return "({$base})";
        }

        return "({$base} && (" . implode(' || ', array_map(fn (string $b): string => $this->nameMatch('github.ref_name', $b), $tags)) . '))';
    }

    private function nameMatch(string $subject, string $pattern): string
    {
        if (str_contains($pattern, "'")) {
            throw new InvalidArgumentException("github: pattern [{$pattern}] cannot contain a single quote.");
        }

        if (preg_match('/^[^*?\[]*\*{1,2}$/', $pattern) === 1) {
            return "startsWith({$subject}, '" . rtrim($pattern, '*') . "')";
        }

        if (preg_match('/[*?\[]/', $pattern) === 1) {
            throw new InvalidArgumentException(
                "github: pattern [{$pattern}] cannot be expressed as a job condition. Use an exact name or a trailing \"*\"."
            );
        }

        return "{$subject} == '{$pattern}'";
    }
}
