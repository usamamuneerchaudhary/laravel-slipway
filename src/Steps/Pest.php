<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Steps;

/** Pest test runner. `parallel` needs Pest's built-in parallel runner (paratest). */
final class Pest extends AbstractTestStep
{
    protected function binary(): string
    {
        return 'vendor/bin/pest';
    }

    protected function label(): string
    {
        return 'Pest';
    }

    protected function parallelFlag(): ?string
    {
        return '--parallel';
    }

    protected function coverageArgs(bool|int $coverage): string
    {
        return is_int($coverage) ? '--coverage --min=' . $coverage : '--coverage';
    }
}
