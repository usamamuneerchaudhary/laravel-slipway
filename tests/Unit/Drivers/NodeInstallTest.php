<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit\Drivers;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Usamamuneerchaudhary\Slipway\Drivers\GitLabDriver;
use Usamamuneerchaudhary\Slipway\Tests\Support\ShellHarness;

final class NodeInstallTest extends TestCase
{
    private ?ShellHarness $h = null;

    protected function tearDown(): void
    {
        $this->h?->cleanup();
    }

    private function script(string $version): string
    {
        $method = new ReflectionMethod(GitLabDriver::class, 'nodeInstall');

        return (string) $method->invoke(new GitLabDriver(), $version);
    }

    /**
     * Runs the generated script against mock `curl`, `tar`, `uname` and `sha256sum`.
     *
     * @return array{code:int,out:string}
     */
    private function runInstall(string $version, bool $tamperedDownload = false): array
    {
        $this->h = new ShellHarness();

        $tarball = 'node-v22.11.0-linux-x64.tar.gz';
        $payload = $tamperedDownload ? 'tampered' : 'genuine';
        $sum = hash('sha256', 'genuine');

        $this->h->tool('uname', "#!/bin/sh\necho x86_64\n");
        $this->h->tool('tar', "#!/bin/sh\necho \"tar \$*\" >> \"\$MOCK_LOG\"\n");
        $this->h->tool('sha256sum', "#!/bin/sh\nfor p in /usr/bin/sha256sum /bin/sha256sum; do [ -x \"\$p\" ] && exec \"\$p\" \"\$@\"; done\nexec /usr/bin/shasum -a 256 \"\$@\"\n");
        $this->h->tool('curl', <<<SH
#!/bin/sh
echo "curl \$*" >> "\$MOCK_LOG"
out=""
while [ \$# -gt 0 ]; do
  case "\$1" in
    -o) out="\$2"; shift 2 ;;
    *) url="\$1"; shift ;;
  esac
done
case "\$url" in
  */SHASUMS256.txt) printf '%s  %s\\n%s  %s\\n' '{$sum}' '{$tarball}' 'ffff' 'node-v22.11.0-linux-arm64.tar.gz' > "\$out" ;;
  *) printf '%s' '{$payload}' > "\$out" ;;
esac
SH);

        return $this->h->run("set -eu\n" . $this->script($version) . "\n");
    }

    public function test_it_installs_the_official_binary_after_verifying_its_checksum(): void
    {
        if (ShellHarness::shell() === null) {
            $this->markTestSkipped('No POSIX shell available.');
        }

        $result = $this->runInstall('22');

        $this->assertSame(0, $result['code'], $result['out']);
        $this->assertStringContainsString('https://nodejs.org/dist/latest-v22.x/SHASUMS256.txt', $this->h->calls());
        $this->assertStringContainsString('https://nodejs.org/dist/latest-v22.x/node-v22.11.0-linux-x64.tar.gz', $this->h->calls());
        $this->assertStringContainsString('tar -xzf /tmp/node-v22.11.0-linux-x64.tar.gz -C /usr/local', $this->h->calls());
    }

    public function test_a_tampered_download_is_never_unpacked(): void
    {
        if (ShellHarness::shell() === null) {
            $this->markTestSkipped('No POSIX shell available.');
        }

        $result = $this->runInstall('22', tamperedDownload: true);

        $this->assertNotSame(0, $result['code'], 'a checksum mismatch must fail the job');
        $this->assertStringNotContainsString('tar -xzf', $this->h->calls());
    }

    public function test_an_exact_version_uses_its_own_release_directory(): void
    {
        $this->assertStringContainsString('https://nodejs.org/dist/v22.11.0', $this->script('22.11.0'));
        $this->assertStringContainsString('https://nodejs.org/dist/latest-v22.x', $this->script('22.11'));
    }

    public function test_nothing_is_piped_into_a_shell(): void
    {
        foreach (['22', '22.11', '22.11.0'] as $version) {
            $this->assertDoesNotMatchRegularExpression('/\|\s*(ba|z)?sh\b/', $this->script($version));
            $this->assertStringNotContainsString('nodesource', $this->script($version));
        }
    }

    public function test_the_script_is_valid_posix_sh(): void
    {
        $shell = ShellHarness::shell();
        if ($shell === null) {
            $this->markTestSkipped('No POSIX shell available.');
        }

        foreach (['22', '22.11', '22.11.0'] as $version) {
            $file = tempnam(sys_get_temp_dir(), 'slipway-node');
            file_put_contents($file, $this->script($version));
            exec(escapeshellarg($shell) . ' -n ' . escapeshellarg($file) . ' 2>&1', $output, $code);
            unlink($file);

            $this->assertSame(0, $code, implode("\n", $output));
        }
    }
}
