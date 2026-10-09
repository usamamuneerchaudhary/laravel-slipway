<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Support;

use Usamamuneerchaudhary\Slipway\CompileResult;
use Usamamuneerchaudhary\Slipway\Model\Job;

/**
 * Describes a compiled pipeline in plain English, so reviewers (and non-DevOps teammates) can
 * read what a config change actually does without reading generated YAML.
 */
final class Explainer
{
    /** @return list<string> */
    public static function explain(CompileResult $result): array
    {
        $p = $result->pipeline;
        $t = $p->triggers;
        $out = [];

        $out[] = "Pipeline \"{$p->name}\"";
        $out[] = 'Providers: ' . implode(', ', array_keys($result->files));

        $when = [];
        $t->hasPush() && $when[] = 'pushes to ' . implode(', ', $t->pushBranches);
        $t->hasTags() && $when[] = 'tags matching ' . implode(', ', $t->tags);
        $t->pullRequest && $when[] = 'pull requests' . ($t->pullRequestBranches !== [] ? ' targeting ' . implode(', ', $t->pullRequestBranches) : '');
        $t->schedule !== [] && $when[] = 'schedule (' . implode('; ', $t->schedule) . ' UTC)';
        $t->manual && $when[] = 'manual runs';
        $out[] = 'Runs on: ' . implode('; ', $when);
        $t->pathsIgnore !== [] && $out[] = 'Ignores changes only touching: ' . implode(', ', $t->pathsIgnore);
        $out[] = 'Superseded runs are cancelled: ' . ($p->cancelSuperseded ? 'yes (pull requests only on GitHub)' : 'no');
        $out[] = '';

        $number = 0;
        foreach ($p->stages() as $stage => $jobs) {
            $number++;
            $parallel = count($jobs) > 1 ? ' - jobs run in parallel' : '';
            $out[] = "Stage {$number}: {$stage}{$parallel}";

            foreach ($jobs as $job) {
                $out[] = '  * ' . $job->name . self::runtime($job) . self::gate($job);
                foreach ($job->steps as $step) {
                    $out[] = '      - ' . $step->name;
                }
            }
        }

        if ($p->environments !== []) {
            $out[] = '';
            $out[] = 'Environments';
            foreach ($p->environments as $env) {
                $line = "  * {$env->name}: ";
                $line .= $env->after !== null ? "after {$env->after} succeeds" : ($env->branch !== null ? "on pushes to {$env->branch}" : "on tags matching {$env->tag}");
                $line .= ", deploys via {$env->deployer}";
                $line .= $env->approval ? ', needs manual approval' : ', no approval needed';
                $out[] = $line;

                if ($env->verify !== null) {
                    $target = $env->verify['url'] ?? ('secret ' . $env->verify['url_secret']);
                    $out[] = "      verifies {$env->verify['path']} returns HTTP {$env->verify['expect']} ({$env->verify['retries']} tries, {$env->verify['delay']}s apart) at {$target}";
                } else {
                    $out[] = '      no post-deploy verification';
                }
                $out[] = '      ' . ($env->rollback ? 'rolls back automatically if verification fails' : 'no automatic rollback');
            }
        }

        $secrets = $p->referencedSecrets();
        if ($secrets !== []) {
            $out[] = '';
            $out[] = 'Secrets the pipeline reads (names only): ' . implode(', ', $secrets);
        }

        if ($result->warnings !== []) {
            $out[] = '';
            $out[] = 'Warnings';
            foreach ($result->warnings as $warning) {
                $out[] = '  ! ' . $warning;
            }
        }

        return $out;
    }

    private static function runtime(Job $job): string
    {
        $bits = [];
        if ($job->needsPhp()) {
            $bits[] = 'PHP ' . implode(', ', $job->phpVersions);
        }
        $job->node !== null && $bits[] = 'Node ' . $job->node;
        if ($job->services !== []) {
            $bits[] = 'with ' . implode(', ', array_map(static fn ($s): string => $s->name, $job->services));
        }

        return $bits === [] ? '' : ' [' . implode('; ', $bits) . ']';
    }

    private static function gate(Job $job): string
    {
        return $job->manual ? ' (waits for manual approval)' : '';
    }
}
