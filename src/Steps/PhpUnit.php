<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Steps;

use InvalidArgumentException;

/** PHPUnit test runner. */
final class PhpUnit extends AbstractTestStep
{
    protected function binary(): string
    {
        return 'vendor/bin/phpunit';
    }

    protected function label(): string
    {
        return 'PHPUnit';
    }

    protected function parallelFlag(): ?string
    {
        return null; // PHPUnit has no built-in parallel runner; use `php artisan test --parallel` via an inline step.
    }

    protected function coverageArgs(bool|int $coverage): string
    {
        if (is_int($coverage)) {
            throw new InvalidArgumentException('PHPUnit cannot enforce a minimum coverage percentage; use coverage => true, or Pest.');
        }

        return '--coverage-text';
    }
}
