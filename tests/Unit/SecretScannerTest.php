<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Security\SecretScanner;
use Usamamuneerchaudhary\Slipway\Tests\Support\Fixture;

final class SecretScannerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Fixture::tempDir();
    }

    protected function tearDown(): void
    {
        Fixture::remove($this->dir);
    }

    /** Credential-shaped values are assembled at runtime so this file never contains a real-looking secret. */
    private function samples(): array
    {
        return [
            'AWS access key ID' => 'AKIA' . 'ABCDEFGHIJKLMNOP',
            'GitHub token' => 'ghp_' . str_repeat('aB3dE6', 7),
            'GitHub fine-grained token' => 'github_pat_' . str_repeat('A1b2C3d4E5', 6),
            'GitLab token' => 'glpat-' . str_repeat('xY7z', 6),
            'Slack token' => 'xoxb-' . '1234567890-abcdefghij',
            'Slack webhook URL' => 'https://hooks.slack.com/services/' . 'T00000000/B00000000/' . 'XXXXXXXXXXXXXXXXXXXXXXXX',
            'Private key' => '-----BEGIN ' . 'RSA PRIVATE KEY-----',
            'Stripe live key' => 'sk_live_' . str_repeat('a1B2', 8),
            'Google API key' => 'AIza' . str_repeat('aB3-_', 7),
            'npm token' => 'npm_' . str_repeat('aB3dE6', 6),
        ];
    }

    public function test_it_detects_each_credential_type(): void
    {
        $scanner = new SecretScanner();

        foreach ($this->samples() as $type => $value) {
            $findings = $scanner->scanString("key = {$value}\n", 'file.txt');

            $this->assertCount(1, $findings, "{$type} was not detected");
            $this->assertSame($type, $findings[0]->type);
            $this->assertSame('file.txt:1', $findings[0]->location);
        }
    }

    public function test_findings_never_contain_the_secret(): void
    {
        $scanner = new SecretScanner();

        foreach ($this->samples() as $type => $value) {
            foreach ($scanner->scanString($value, 'f') as $finding) {
                $this->assertStringNotContainsString($value, (string) $finding, $type);
            }
        }
    }

    public function test_ordinary_text_is_not_flagged(): void
    {
        $text = "APP_NAME=Laravel\nDB_PASSWORD=\${DB_PASSWORD}\nSee https://example.com/docs and AKIA is a prefix.\nghp_short\n";

        $this->assertSame([], (new SecretScanner())->scanString($text, 'x'));
    }

    public function test_a_line_can_opt_out_with_the_ignore_marker(): void
    {
        $line = 'token ' . $this->samples()['GitHub token'] . ' // slipway-scan-ignore';

        $this->assertSame([], (new SecretScanner())->scanString($line, 'x'));
    }

    public function test_line_numbers_are_reported(): void
    {
        $findings = (new SecretScanner())->scanString("ok\nok\ntoken " . $this->samples()['GitHub token'] . "\n", 'notes.md');

        $this->assertSame('notes.md:3', $findings[0]->location);
    }

    public function test_config_values_are_scanned_recursively(): void
    {
        $findings = (new SecretScanner())->scanConfig(['stages' => ['qa' => [['run' => 'curl -H "Authorization: ' . $this->samples()['GitHub token'] . '"']]]]);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('config.stages.qa.0.run', $findings[0]->location);
    }

    public function test_literal_values_for_sensitive_env_names_are_flagged_but_references_are_not(): void
    {
        $scanner = new SecretScanner();

        $this->assertCount(1, $scanner->scanConfig(['env' => ['API_TOKEN' => 'abc123']]));
        $this->assertCount(1, $scanner->scanConfig(['env' => ['DB_PASSWORD' => 'password']]));
        $this->assertSame([], $scanner->scanConfig(['env' => ['API_TOKEN' => '$CI_TOKEN', 'APP_ENV' => 'testing', 'DB_PASSWORD' => '${DB_PASSWORD}']]));
    }

    public function test_directory_scan_finds_credentials_in_nested_files(): void
    {
        mkdir($this->dir . '/src/deep', 0775, true);
        file_put_contents($this->dir . '/src/deep/Client.php', "<?php\n\$key = '" . $this->samples()['AWS access key ID'] . "';\n");

        $findings = (new SecretScanner())->scanDirectory($this->dir);

        $this->assertCount(1, $findings);
        $this->assertSame('src/deep/Client.php:2', $findings[0]->location);
    }

    public function test_committed_env_files_and_ssh_keys_are_flagged_by_name(): void
    {
        file_put_contents($this->dir . '/.env', "APP_KEY=base64:abc\n");
        file_put_contents($this->dir . '/id_rsa', "not a real key\n");

        $types = array_map(static fn ($f): string => $f->type, (new SecretScanner())->scanDirectory($this->dir));

        $this->assertSame(['Committed credentials file', 'Committed credentials file'], $types);
    }

    public function test_vendor_node_modules_and_git_are_skipped(): void
    {
        foreach (['vendor/pkg', 'node_modules/pkg', '.git/objects', 'storage/logs'] as $d) {
            mkdir($this->dir . '/' . $d, 0775, true);
            file_put_contents($this->dir . '/' . $d . '/x.txt', $this->samples()['GitHub token']);
        }

        $this->assertSame([], (new SecretScanner())->scanDirectory($this->dir));
    }

    public function test_ignored_paths_are_skipped(): void
    {
        mkdir($this->dir . '/tests/fixtures', 0775, true);
        file_put_contents($this->dir . '/tests/fixtures/leak.txt', $this->samples()['GitHub token']);

        $this->assertCount(1, (new SecretScanner())->scanDirectory($this->dir));
        $this->assertSame([], (new SecretScanner(['tests/fixtures']))->scanDirectory($this->dir));
    }

    public function test_binary_and_oversized_files_are_skipped(): void
    {
        file_put_contents($this->dir . '/image.bin', "\0\0" . $this->samples()['GitHub token']);
        file_put_contents($this->dir . '/huge.txt', str_repeat('a', 1_100_000) . $this->samples()['GitHub token']);

        $this->assertSame([], (new SecretScanner())->scanDirectory($this->dir));
    }

    public function test_symlinks_are_not_followed(): void
    {
        $outside = Fixture::tempDir();
        file_put_contents($outside . '/leak.txt', $this->samples()['GitHub token']);
        symlink($outside, $this->dir . '/link');

        try {
            $this->assertSame([], (new SecretScanner())->scanDirectory($this->dir));
        } finally {
            Fixture::remove($outside);
        }
    }
}
