<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Steps;

use InvalidArgumentException;
use Usamamuneerchaudhary\Slipway\Config\Schema;
use Usamamuneerchaudhary\Slipway\Model\Contribution;
use Usamamuneerchaudhary\Slipway\Model\Step;
use Usamamuneerchaudhary\Slipway\Support\Shell;

/**
 * Build once, promote everywhere. Installs production dependencies, packs the result into ONE
 * tarball and publishes it as the artifact every environment deploys. What is tested is what ships.
 *
 * A tarball (rather than thousands of loose files) keeps uploads fast and preserves file
 * permissions and symlinks, which GitHub's upload-artifact otherwise drops.
 */
final class BuildArtifact extends AbstractStep
{
    public const FILE = 'slipway-build.tar.gz';

    /** @param list<string> $defaultPaths */
    public function __construct(private readonly array $defaultPaths = Schema::DEFAULT_ARTIFACT_PATHS)
    {
    }

    /** Shell that unpacks the artifact in a deploy job. */
    public static function unpackCommand(): string
    {
        return 'tar -xzf ' . self::FILE . ' && rm -f ' . self::FILE;
    }

    public function contribute(array $options): Contribution
    {
        $o = $this->options($options, ['paths' => 'list', 'optimize' => 'bool']);

        $paths = $o['paths'] ?? $this->defaultPaths;
        if ($paths === []) {
            throw new InvalidArgumentException('option [paths] must contain at least one path.');
        }

        foreach ($paths as $p) {
            if (str_starts_with($p, '/') || preg_match('#(^|/)\.\.(/|$)#', $p) === 1 || preg_match('/[\s\'"`$;&|<>\\\\*?]/', $p) === 1) {
                throw new InvalidArgumentException("artifact path [{$p}] must be a plain relative path (no globs, spaces or \"..\").");
            }
        }

        $steps = [];
        if (($o['optimize'] ?? true) === true) {
            $steps[] = Step::run(
                'Warm framework caches',
                Step::ARTISAN . " config:clear --no-interaction\n" . Step::ARTISAN . ' package:discover --ansi'
            );
        }

        $steps[] = Step::run('Pack build artifact', $this->packCommand($paths));
        $steps[] = Step::artifactUpload('Publish build artifact');

        return (new Contribution($steps))
            ->withArtifact([self::FILE])
            ->withoutDevDependencies();
    }

    /** @param list<string> $paths */
    private function packCommand(array $paths): string
    {
        $list = implode(' ', array_map(static fn (string $p): string => Shell::quote(rtrim($p, '/')), $paths));

        // Only existing paths are packed, so optional directories (e.g. lang/) never break the build.
        return <<<SH
set --
for path in {$list}; do
  if [ -e "\$path" ]; then
    set -- "\$@" "\$path"
  fi
done
if [ "\$#" -eq 0 ]; then
  echo "None of the configured artifact paths exist." >&2
  exit 1
fi
tar -czf {$this->file()} "\$@"
ls -lh {$this->file()}
SH;
    }

    private function file(): string
    {
        return self::FILE;
    }
}
