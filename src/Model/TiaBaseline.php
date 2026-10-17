<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Model;

/**
 * Pest TIA baseline recorded on CI so developers can fetch it with `baselined()`.
 */
final readonly class TiaBaseline
{
    public function __construct(
        public string $php,
        public string $schedule,
        public string $coverage,
        public bool   $parallel,
        public string $branch,
        public bool   $lockfile,
    ) {
    }

    public function fingerprint(): string
    {
        return $this->php . "\0" . $this->schedule . "\0" . $this->coverage . "\0" . ($this->parallel ? '1' : '0');
    }
}
