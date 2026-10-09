<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Usamamuneerchaudhary\Slipway\Support\ConfigLocator;
use Usamamuneerchaudhary\Slipway\Tests\Support\Fixture;

final class ConfigLocatorTest extends TestCase
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

    public function test_laravel_config_wins_and_uses_the_application_root(): void
    {
        $located = (new ConfigLocator('/app', $this->dir, ['name' => 'X']))->locate();

        $this->assertSame(['name' => 'X'], $located['config']);
        $this->assertSame('/app', $located['root']);
        $this->assertSame('config/slipway.php', $located['source']);
    }

    public function test_a_package_root_file_is_used_when_laravel_has_no_config(): void
    {
        file_put_contents($this->dir . '/slipway.php', "<?php return ['name' => 'Pkg'];\n");

        $located = (new ConfigLocator('/skeleton', $this->dir, null))->locate();

        $this->assertSame(['name' => 'Pkg'], $located['config']);
        $this->assertSame($this->dir, $located['root']);
        $this->assertSame('slipway.php', $located['source']);
    }

    public function test_an_empty_laravel_config_falls_through_to_the_package_file(): void
    {
        file_put_contents($this->dir . '/slipway.php', "<?php return ['name' => 'Pkg'];\n");

        $this->assertSame('slipway.php', (new ConfigLocator('/skeleton', $this->dir, []))->locate()['source']);
    }

    public function test_an_explicit_file_takes_precedence(): void
    {
        file_put_contents($this->dir . '/custom.php', "<?php return ['name' => 'Custom'];\n");

        $located = (new ConfigLocator('/app', $this->dir, ['name' => 'X']))->locate('custom.php');

        $this->assertSame(['name' => 'Custom'], $located['config']);
        $this->assertSame('custom.php', $located['source']);
        $this->assertSame($this->dir, $located['root']);
    }

    public function test_a_helpful_error_when_nothing_is_found(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('slipway:init');

        (new ConfigLocator('/app', $this->dir, null))->locate();
    }

    public function test_a_missing_explicit_file_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        (new ConfigLocator('/app', $this->dir, null))->locate('nope.php');
    }

    public function test_a_config_file_must_return_an_array(): void
    {
        file_put_contents($this->dir . '/slipway.php', "<?php return 'oops';\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must return an array');

        (new ConfigLocator('/app', $this->dir, null))->locate();
    }
}
