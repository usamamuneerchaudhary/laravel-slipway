<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Steps;

use InvalidArgumentException;
use Usamamuneerchaudhary\Slipway\Model\Contribution;
use Usamamuneerchaudhary\Slipway\Model\Step;

/** Installs frontend dependencies and builds assets. Requires `node` or `bun` in config. */
final class BuildAssets extends AbstractStep
{
    public function contribute(array $options): Contribution
    {
        $o = $this->options($options, ['script' => 'string', 'preset' => 'string']);
        $preset = (string) ($o['preset'] ?? 'npm');
        if (! in_array($preset, ['npm', 'vite', 'bun'], true)) {
            throw new InvalidArgumentException('option [preset] must be npm, vite or bun');
        }

        $script = $this->shellSafe((string) ($o['script'] ?? 'build'), 'script');
        if (str_starts_with($script, '-')) {
            throw new InvalidArgumentException('option [script] must be a package.json script name, not a flag');
        }

        $npmCi = 'npm ci --no-audit --no-fund';

        return match ($preset) {
            'npm' => Contribution::steps(
                Step::run('Install npm dependencies', $npmCi),
                Step::run('Build assets', 'npm run ' . $script),
            )->withNode(),
            'vite' => Contribution::steps(
                Step::run('Install npm dependencies', $npmCi),
                Step::run(
                    isset($o['script']) ? 'Build assets (Vite)' : 'Build assets (npx vite build)',
                    isset($o['script']) ? 'npm run ' . $script : 'npx vite build',
                ),
            )->withNode(),
            'bun' => Contribution::steps(
                Step::run('Install bun dependencies', 'bun install --frozen-lockfile'),
                Step::run('Build assets', 'bun run ' . $script),
            )->withBun(),
        };
    }
}
