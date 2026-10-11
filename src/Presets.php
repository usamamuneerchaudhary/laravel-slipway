<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway;

use InvalidArgumentException;

/** Starter configurations written by `slipway:init`. */
final class Presets
{
    public const APP = 'app';
    public const PACKAGE = 'package';
    public const DEPLOY = 'deploy';

    /** @return list<string> */
    public static function names(): array
    {
        return [self::APP, self::PACKAGE, self::DEPLOY];
    }

    /** Where the file is written, relative to the project root. */
    public static function target(string $preset): string
    {
        self::assertKnown($preset);

        return $preset === self::PACKAGE ? 'slipway.php' : 'config/slipway.php';
    }

    /**
     * @param list<string> $enabledDrivers Drivers set to enabled; every known driver is listed.
     * @param list<string> $allDrivers
     */
    public static function render(string $preset, array $enabledDrivers, array $allDrivers = ['github', 'bitbucket', 'gitlab', 'circleci']): string
    {
        self::assertKnown($preset);

        $stub = file_get_contents(__DIR__ . '/../stubs/' . $preset . '.stub');
        if ($stub === false) {
            throw new InvalidArgumentException("Preset stub [{$preset}] is missing.");
        }

        foreach ($enabledDrivers as $driver) {
            if (! in_array($driver, $allDrivers, true)) {
                throw new InvalidArgumentException(sprintf('Unknown driver [%s]. Available: %s.', $driver, implode(', ', $allDrivers)));
            }
        }

        $lines = [];
        foreach ($allDrivers as $driver) {
            $lines[] = sprintf("        '%s' => ['enabled' => %s],", $driver, in_array($driver, $enabledDrivers, true) ? 'true' : 'false');
        }

        return str_replace('__DRIVERS__', implode("\n", $lines), $stub);
    }

    private static function assertKnown(string $preset): void
    {
        if (! in_array($preset, self::names(), true)) {
            throw new InvalidArgumentException(sprintf('Unknown preset [%s]. Available: %s.', $preset, implode(', ', self::names())));
        }
    }
}
