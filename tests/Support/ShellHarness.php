<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Support;

/**
 * Runs generated POSIX sh against mock `ssh`, `rsync`, `curl` and `php` binaries, so deploy and
 * rollback logic is exercised for real instead of only string-matched.
 *
 * The mock `ssh` strips its options and target and runs the remaining command LOCALLY with
 * stdin forwarded, so a "server" is just a temporary directory.
 */
final class ShellHarness
{
    public string $root;
    public string $bin;
    public string $home;
    public string $work;
    public string $log;

    public function __construct()
    {
        $this->root = Fixture::tempDir();
        $this->bin = $this->root . '/bin';
        $this->home = $this->root . '/home';
        $this->work = $this->root . '/work';
        $this->log = $this->root . '/calls.log';

        mkdir($this->bin);
        mkdir($this->home);
        mkdir($this->work);

        $this->tool('ssh', <<<'SH'
#!/bin/sh
while [ $# -gt 0 ]; do
  case "$1" in
    -i|-o|-p) shift 2 ;;
    *) break ;;
  esac
done
shift
exec sh -c "$1"
SH);
        $this->tool('rsync', <<<'SH'
#!/bin/sh
for a; do last="$a"; done
dest="${last#*:}"
mkdir -p "$dest" && cp -a ./. "$dest/"
SH);
        $this->tool('curl', <<<'SH'
#!/bin/sh
printf '%s' "${MOCK_HTTP:-200}"
SH);
        $this->tool('php', <<<'SH'
#!/bin/sh
echo "php $*" >> "$MOCK_LOG"
SH);
        // Real servers run GNU coreutils (`mv -T`). BSD/macOS mv has no -T; its `-h` flag is the equivalent.
        $this->tool('mv', <<<'SH'
#!/bin/sh
if [ "$1" = "-Tf" ] && [ "$(uname -s)" = "Darwin" ]; then
  shift
  exec /bin/mv -fh "$@"
fi
exec /bin/mv "$@"
SH);
    }

    public static function shell(): ?string
    {
        foreach (['/usr/bin/dash', '/bin/dash', '/bin/sh', '/usr/bin/sh'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function tool(string $name, string $script): void
    {
        file_put_contents($this->bin . '/' . $name, $script . "\n");
        chmod($this->bin . '/' . $name, 0755);
    }

    /**
     * @param array<string,string> $env
     * @return array{code:int,out:string}
     */
    public function run(string $script, array $env = []): array
    {
        $file = $this->root . '/script.sh';
        file_put_contents($file, $script);

        $process = proc_open(
            [self::shell() ?? 'sh', $file],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->work,
            array_merge([
                'PATH' => $this->bin . ':/usr/bin:/bin',
                'HOME' => $this->home,
                'MOCK_LOG' => $this->log,
                'SLIPWAY_SHA' => 'abc123',
                'SLIPWAY_REF' => 'main',
            ], $env),
        );

        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return ['code' => proc_close($process), 'out' => (string) $out];
    }

    public function calls(): string
    {
        return is_file($this->log) ? (string) file_get_contents($this->log) : '';
    }

    public function cleanup(): void
    {
        Fixture::remove($this->root);
    }
}
