<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway;

use InvalidArgumentException;
use Usamamuneerchaudhary\Slipway\Contracts\Driver;
use Usamamuneerchaudhary\Slipway\Drivers\BitbucketDriver;
use Usamamuneerchaudhary\Slipway\Drivers\GitHubDriver;
use Usamamuneerchaudhary\Slipway\Drivers\GitLabDriver;

/**
 * Holds the available CI providers. Third parties add their own with register().
 */
final class DriverManager
{
    /** @var array<string,Driver> */
    private array $drivers = [];

    public static function withDefaults(): self
    {
        $manager = new self();
        $manager->register(new GitHubDriver());
        $manager->register(new BitbucketDriver());
        $manager->register(new GitLabDriver());

        return $manager;
    }

    public function register(Driver $driver): void
    {
        $this->drivers[$driver->name()] = $driver;
    }

    public function has(string $name): bool
    {
        return isset($this->drivers[$name]);
    }

    public function get(string $name): Driver
    {
        return $this->drivers[$name] ?? throw new InvalidArgumentException(sprintf(
            'Unknown driver [%s]. Available: %s.',
            $name,
            implode(', ', $this->names())
        ));
    }

    /** @return list<string> */
    public function names(): array
    {
        $names = array_keys($this->drivers);
        sort($names);

        return $names;
    }

    /** @return array<string,Driver> */
    public function all(): array
    {
        return $this->drivers;
    }
}
