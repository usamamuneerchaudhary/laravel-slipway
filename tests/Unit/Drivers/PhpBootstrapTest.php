<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit\Drivers;

use PHPUnit\Framework\TestCase;
use Usamamuneerchaudhary\Slipway\Drivers\PhpBootstrap;

final class PhpBootstrapTest extends TestCase
{
    public function test_composer_is_installed_only_after_its_checksum_is_verified(): void
    {
        $script = PhpBootstrap::script([], null);

        $this->assertStringContainsString('https://composer.github.io/installer.sig', $script);
        $this->assertStringContainsString('hash_file(\'sha384\'', $script);
        $this->assertStringNotContainsString('installer | php', $script, 'the installer must never be piped straight into php');

        $verify = strpos($script, 'checksum mismatch');
        $install = strpos($script, 'php /tmp/composer-setup.php');
        $this->assertNotFalse($verify);
        $this->assertNotFalse($install);
        $this->assertLessThan($install, $verify, 'verification must come before installation');
    }

    public function test_the_bootstrap_script_is_valid_posix_sh(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'slipway-bootstrap');
        file_put_contents($file, PhpBootstrap::script(['intl', 'redis'], 'pcov'));
        exec('sh -n ' . escapeshellarg($file) . ' 2>&1', $output, $code);
        unlink($file);

        $this->assertSame(0, $code, implode("\n", $output));
    }
}
