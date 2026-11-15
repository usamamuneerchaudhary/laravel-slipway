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
     * Confirm the preset and drivers can be rendered.
     *
     * Throws before a file is written when the preset or a driver name is unknown.
     *
     * @param  list<string>  $enabledDrivers
     * @param  list<string>  $allDrivers
     */
    public static function assertReady(string $preset, array $enabledDrivers, array $allDrivers): void
    {
        self::assertKnown($preset);

        foreach ($enabledDrivers as $driver) {
            if (! in_array($driver, $allDrivers, true)) {
                throw new InvalidArgumentException(sprintf('Unknown driver [%s]. Available: %s.', $driver, implode(', ', $allDrivers)));
            }
        }
    }

    /**
     * Validate a branch name before it is written into a preset.
     *
     * Returns the trimmed name. Throws when the name is empty, too long, or not a single branch CI can check out.
     */
    public static function branch(string $branch): string
    {
        $branch = trim($branch);

        if (self::isBranchName($branch)) {
            return $branch;
        }

        throw new InvalidArgumentException('[' . self::showBranch($branch) . '] is not a branch name CI can run on. Use a name such as main, master, or develop.');
    }

    /**
     * @param  list<string>  $enabledDrivers  Drivers set to enabled; every known driver is listed.
     * @param  list<string>  $allDrivers
     * @param  string  $branch  Branch pushes CI should run on. The deploy preset uses it for staging too.
     */
    public static function render(string $preset, array $enabledDrivers, array $allDrivers = ['github', 'bitbucket', 'gitlab', 'circleci'], string $branch = 'main'): string
    {
        self::assertReady($preset, $enabledDrivers, $allDrivers);
        $branch = self::branch($branch);

        $stub = file_get_contents(__DIR__ . '/../stubs/' . $preset . '.stub');
        if ($stub === false) {
            throw new InvalidArgumentException("Preset stub [{$preset}] is missing.");
        }

        if (! str_contains($stub, '__BRANCH__')) {
            throw new InvalidArgumentException("Preset stub [{$preset}] has no branch placeholder.");
        }

        $lines = [];
        foreach ($allDrivers as $driver) {
            $lines[] = sprintf("        '%s' => ['enabled' => %s],", $driver, in_array($driver, $enabledDrivers, true) ? 'true' : 'false');
        }

        return str_replace(
            ['__DRIVERS__', '__BRANCH__'],
            [implode("\n", $lines), $branch],
            $stub,
        );
    }

    private static function isBranchName(string $branch): bool
    {
        if ($branch === '' || strlen($branch) > 250) {
            return false;
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/D', $branch) !== 1) {
            return false;
        }

        if (str_contains($branch, '..') || str_contains($branch, '@{')) {
            return false;
        }

        foreach (explode('/', $branch) as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || str_starts_with($segment, '.')
                || str_starts_with($segment, '-')
                || str_ends_with($segment, '.')
                || str_ends_with($segment, '.lock')
            ) {
                return false;
            }
        }

        return true;
    }

    private static function showBranch(string $branch): string
    {
        $shown = preg_replace('/[\x00-\x1f\x7f]/', '', $branch) ?? '';
        $shown = trim($shown);

        if ($shown === '') {
            return '';
        }

        return mb_strlen($shown) > 80 ? mb_substr($shown, 0, 77) . '...' : $shown;
    }

    private static function assertKnown(string $preset): void
    {
        if (! in_array($preset, self::names(), true)) {
            throw new InvalidArgumentException(sprintf('Unknown preset [%s]. Available: %s.', $preset, implode(', ', self::names())));
        }
    }
}
