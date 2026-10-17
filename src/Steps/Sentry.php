<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Steps;

use InvalidArgumentException;
use Usamamuneerchaudhary\Slipway\Model\Contribution;
use Usamamuneerchaudhary\Slipway\Model\Step;

/**
 * Injects and uploads source maps with the project's locked `@sentry/cli`.
 */
final class Sentry extends AbstractStep
{
    public function contribute(array $options): Contribution
    {
        $o = $this->options($options, [
            'org' => 'string',
            'project' => 'string',
            'path' => 'string',
            'token' => 'string',
        ]);

        if (! isset($o['org'], $o['project'])) {
            throw new InvalidArgumentException('options [org] and [project] are required');
        }

        $org = $this->shellSafe((string) $o['org'], 'org');
        $project = $this->shellSafe((string) $o['project'], 'project');
        $path = $this->shellSafe((string) ($o['path'] ?? 'public/build'), 'path');
        $token = (string) ($o['token'] ?? 'SENTRY_AUTH_TOKEN');
        if (preg_match('/^[A-Z][A-Z0-9_]*$/D', $token) !== 1) {
            throw new InvalidArgumentException('option [token] must be an UPPER_SNAKE_CASE secret name');
        }

        $run = <<<SH
if [ ! -x node_modules/.bin/sentry-cli ]; then
  echo "sentry-cli is not installed. Add @sentry/cli to the lockfile and run the frontend build first." >&2
  exit 1
fi
if [ -z "\${SENTRY_RELEASE:-}" ]; then
  SENTRY_RELEASE="\${GITHUB_SHA:-\${CI_COMMIT_SHA:-\${BITBUCKET_COMMIT:-\${CIRCLE_SHA1:-}}}}"
  if [ -z "\$SENTRY_RELEASE" ]; then
    SENTRY_RELEASE="\$(git rev-parse HEAD)"
  fi
  export SENTRY_RELEASE
fi
node_modules/.bin/sentry-cli sourcemaps inject --release "\$SENTRY_RELEASE" {$path}
node_modules/.bin/sentry-cli sourcemaps upload --release "\$SENTRY_RELEASE" {$path}
SH;

        return Contribution::steps(
            Step::run(
                'Upload Sentry source maps',
                $run,
                ['SENTRY_ORG' => $org, 'SENTRY_PROJECT' => $project],
                [$token],
            ),
        );
    }
}
