<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit\Drivers;

use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Drivers\PhpBootstrap;
use Usamamuneerchaudhary\Slipway\Tests\Support\ShellHarness;

final class BunInstallTest extends TestCase
{
    private ?ShellHarness $h = null;

    protected function tearDown(): void
    {
        $this->h?->cleanup();
    }

    /**
     * @param array<string,string> $env
     * @return array{code:int,out:string}
     */
    private function runInstall(string $version, bool $tamperedDownload = false, bool $githubRunner = false, array $env = []): array
    {
        $this->h = new ShellHarness();

        $zip = 'bun-linux-x64.zip';
        $payload = $tamperedDownload ? 'tampered' : 'genuine';
        $sum = hash('sha256', 'genuine');

        $this->h->tool('uname', "#!/bin/sh\necho x86_64\n");
        $this->h->tool('unzip', "#!/bin/sh\necho \"unzip \$*\" >> \"\$MOCK_LOG\"\nmkdir -p /tmp/bun-extract/bun-linux-x64\necho bun > /tmp/bun-extract/bun-linux-x64/bun\n");
        $install = $githubRunner
            ? <<<'SH'
#!/bin/sh
echo "install $*" >> "$MOCK_LOG"
dest=""
for arg; do dest="$arg"; done
mkdir -p "$(dirname "$dest")"
printf '%s\n' '#!/bin/sh' 'echo bun-ok' > "$dest"
chmod +x "$dest"
SH
            : "#!/bin/sh\necho \"install \$*\" >> \"\$MOCK_LOG\"\n";
        $this->h->tool('install', $install);
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
  */SHASUMS256.txt) printf '%s  %s\\n' '{$sum}' '{$zip}' > "\$out" ;;
  *) printf '%s' '{$payload}' > "\$out" ;;
esac
SH);

        $script = $githubRunner ? PhpBootstrap::githubBunInstall($version) : PhpBootstrap::bunInstall($version);

        return $this->h->run("set -eu\n" . $script . "\n", $env);
    }

    public function test_it_installs_the_official_binary_after_verifying_its_checksum(): void
    {
        if (ShellHarness::shell() === null) {
            $this->markTestSkipped('No POSIX shell available.');
        }

        $result = $this->runInstall('1.2.21');

        $this->assertSame(0, $result['code'], $result['out']);
        $this->assertStringContainsString('https://github.com/oven-sh/bun/releases/download/bun-v1.2.21/SHASUMS256.txt', $this->h->calls());
        $this->assertStringContainsString('https://github.com/oven-sh/bun/releases/download/bun-v1.2.21/bun-linux-x64.zip', $this->h->calls());
        $this->assertStringContainsString('unzip -q /tmp/bun-linux-x64.zip', $this->h->calls());
        $this->assertStringContainsString('install /tmp/bun-extract/', $this->h->calls());
        $this->assertStringContainsString('/usr/local/bin/bun', $this->h->calls());
    }

    public function test_github_runners_install_bun_onto_the_user_path_without_root(): void
    {
        if (ShellHarness::shell() === null) {
            $this->markTestSkipped('No POSIX shell available.');
        }

        $githubPath = tempnam(sys_get_temp_dir(), 'slipway-github-path');
        $this->assertIsString($githubPath);

        try {
            $result = $this->runInstall('1.2.21', githubRunner: true, env: ['GITHUB_PATH' => $githubPath]);

            $this->assertSame(0, $result['code'], $result['out']);
            $this->assertStringNotContainsString('/usr/local/bin/bun', $this->h->calls());
            $this->assertStringContainsString($this->h->home . '/.bun/bin/bun', $this->h->calls());

            $directory = trim((string) file_get_contents($githubPath));
            $this->assertSame($this->h->home . '/.bun/bin', $directory);

            $resolved = $this->h->run("command -v bun\nbun\n", [
                'PATH' => $directory . ':' . $this->h->bin . ':/usr/bin:/bin',
            ]);

            $this->assertSame(0, $resolved['code'], $resolved['out']);
            $this->assertStringContainsString($directory . '/bun', $resolved['out']);
            $this->assertStringContainsString('bun-ok', $resolved['out']);
        } finally {
            if (is_file($githubPath)) {
                unlink($githubPath);
            }
        }
    }

    public function test_a_tampered_download_is_never_unpacked(): void
    {
        if (ShellHarness::shell() === null) {
            $this->markTestSkipped('No POSIX shell available.');
        }

        $result = $this->runInstall('1.2.21', tamperedDownload: true);

        $this->assertNotSame(0, $result['code'], 'a checksum mismatch must fail the job');
        $this->assertStringNotContainsString('unzip -q', $this->h->calls());
    }

    public function test_nothing_is_piped_into_a_shell(): void
    {
        $script = PhpBootstrap::bunInstall('1.2.21');

        $this->assertDoesNotMatchRegularExpression('/\|\s*(ba|z)?sh\b/', $script);
        $this->assertStringNotContainsString('bun.sh/install', $script);
    }

    public function test_the_script_is_valid_posix_sh(): void
    {
        $shell = ShellHarness::shell();
        if ($shell === null) {
            $this->markTestSkipped('No POSIX shell available.');
        }

        foreach ([PhpBootstrap::bunInstall('1.2.21'), PhpBootstrap::githubBunInstall('1.2.21')] as $script) {
            $file = tempnam(sys_get_temp_dir(), 'slipway-bun');
            file_put_contents($file, $script);
            exec(escapeshellarg($shell) . ' -n ' . escapeshellarg($file) . ' 2>&1', $output, $code);
            unlink($file);

            $this->assertSame(0, $code, implode("\n", $output));
        }
    }
}
