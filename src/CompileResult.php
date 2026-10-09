<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway;

use Usamamuneerchaudhary\Slipway\Model\Pipeline;

final class CompileResult
{
    /**
     * @param array<string,GeneratedFile> $files    keyed by driver name
     * @param list<string>                $warnings
     */
    public function __construct(
        public readonly Pipeline $pipeline,
        public readonly array $files,
        public readonly array $warnings,
        public readonly string $configHash,
    ) {
    }
}
