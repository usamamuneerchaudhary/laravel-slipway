<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Steps;

use InvalidArgumentException;
use Usamamuneerchaudhary\Slipway\Model\Contribution;
use Usamamuneerchaudhary\Slipway\Model\Step;

/**
 * Mutation testing with Infection. Opt-in: not part of the default presets.
 * Coverage is always collected with PCOV. Mutators and the test runner live
 * in your infection.json; `config` points at a different file.
 *
 * Typical use is a dedicated stage after tests, on one PHP version, often
 * scheduled rather than on every pull request:
 *
 *     'mutate' => [
 *         'timeout' => 45,
 *         'on' => ['schedule'],
 *         'steps' => [
 *             'infection' => ['min_msi' => 70, 'min_covered_msi' => 80, 'threads' => 4],
 *         ],
 *     ],
 *
 * `git_diff` limits mutants to added and modified files against that ref
 * (e.g. 'origin/main') and fetches it first so a shallow checkout still works.
 */
final class Infection extends AbstractStep
{
    public function contribute(array $options): Contribution
    {
        $o = $this->options($options, [
            'min_msi' => 'int',
            'min_covered_msi' => 'int',
            'threads' => 'int|string',
            'config' => 'string',
            'git_diff' => 'string',
            'only_covering_tests' => 'bool',
            'prepare_env' => 'bool',
        ]);

        $cmd = 'vendor/bin/infection --no-progress --no-interaction';

        if (isset($o['min_msi'])) {
            $cmd .= ' --min-msi=' . $this->msi($o['min_msi'], 'min_msi');
        }

        if (isset($o['min_covered_msi'])) {
            $cmd .= ' --min-covered-msi=' . $this->msi($o['min_covered_msi'], 'min_covered_msi');
        }

        if (isset($o['threads'])) {
            $cmd .= ' --threads=' . $this->threads($o['threads']);
        }

        if (isset($o['config'])) {
            $cmd .= ' --configuration=' . $this->relativePath((string) $o['config'], 'config');
        }

        $gitDiff = isset($o['git_diff']) ? $this->gitDiffRef((string) $o['git_diff']) : null;

        if ($gitDiff !== null) {
            $cmd .= ' --git-diff-base=' . $gitDiff;
            $cmd .= ' --git-diff-filter=AM --ignore-msi-with-no-mutations';
        }

        if (($o['only_covering_tests'] ?? false) === true) {
            $cmd .= ' --only-covering-test-cases';
        }

        $steps = [];

        if (($o['prepare_env'] ?? true) === true) {
            $steps[] = Step::run(
                'Prepare Laravel environment',
                "[ -f .env ] || cp .env.example .env\n" . Step::ARTISAN . ' key:generate --no-interaction'
            );
        }

        if ($gitDiff !== null) {
            $branch = str_starts_with($gitDiff, 'origin/') ? substr($gitDiff, 7) : $gitDiff;
            $steps[] = Step::run('Fetch git-diff base for Infection', 'git fetch --depth=1 origin ' . $branch);
        }

        $steps[] = Step::run('Mutation testing (Infection)', $cmd);

        return (new Contribution($steps))
            ->withEnv(['APP_ENV' => 'testing'])
            ->withCoverage('pcov');
    }

    private function msi(mixed $value, string $option): int
    {
        $msi = (int) $value;
        if ($msi < 0 || $msi > 100) {
            throw new InvalidArgumentException("option [{$option}] must be a percentage from 0 to 100");
        }

        return $msi;
    }

    private function threads(mixed $value): int|string
    {
        if (is_int($value)) {
            if ($value < 1) {
                throw new InvalidArgumentException('option [threads] must be at least 1, or "max"');
            }

            return $value;
        }

        if ($value !== 'max') {
            throw new InvalidArgumentException('option [threads] must be at least 1, or "max"');
        }

        return 'max';
    }

    private function relativePath(string $path, string $option): string
    {
        $safe = $this->shellSafe($path, $option);
        if (str_starts_with($safe, '/') || str_contains($safe, '..') || str_starts_with($safe, '-')) {
            throw new InvalidArgumentException("option [{$option}] must be a relative path inside the project");
        }

        return $safe;
    }

    private function gitDiffRef(string $ref): string
    {
        $safe = $this->shellSafe($ref, 'git_diff');
        if (str_starts_with($safe, '/') || str_contains($safe, '..') || str_starts_with($safe, '-')) {
            throw new InvalidArgumentException('option [git_diff] must be a git ref such as origin/main');
        }

        $branch = str_starts_with($safe, 'origin/') ? substr($safe, 7) : $safe;
        if ($branch === '') {
            throw new InvalidArgumentException('option [git_diff] must be a git ref such as origin/main');
        }

        return $safe;
    }
}
