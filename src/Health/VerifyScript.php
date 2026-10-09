<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Health;

use Usamamuneerchaudhary\Slipway\Support\Shell;

/**
 * Generates the post-deploy health check as a POSIX sh function.
 *
 * This is "continuous verification" in its simplest honest form: poll an HTTP
 * endpoint until it returns the expected status, or give up and let the caller roll back.
 * The same retry semantics are implemented in PHP by HealthChecker (php artisan slipway:verify).
 */
final class VerifyScript
{
    /**
     * @param array{url:?string,url_secret:?string,path:string,expect:int,retries:int,delay:int} $verify
     */
    public static function render(array $verify): string
    {
        $base = $verify['url_secret'] !== null
            ? '"$' . $verify['url_secret'] . '"'
            : Shell::quote((string) $verify['url']);

        $path = Shell::quote($verify['path']);

        return <<<SH
slipway_verify() {
  base={$base}
  base="\${base%/}"
  url="\${base}"{$path}
  i=1
  while [ "\$i" -le {$verify['retries']} ]; do
    code=\$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "\$url" || true)
    if [ "\$code" = "{$verify['expect']}" ]; then
      echo "Verified: \$url returned HTTP \$code"
      return 0
    fi
    echo "Verification attempt \$i/{$verify['retries']}: got HTTP \${code:-000}, expected {$verify['expect']}"
    i=\$((i + 1))
    if [ "\$i" -le {$verify['retries']} ]; then
      sleep {$verify['delay']}
    fi
  done
  return 1
}
SH;
    }
}
