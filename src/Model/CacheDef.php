<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Model;

final class CacheDef
{
    /**
     * @param list<string> $paths
     * @param list<string> $keyFiles Files whose hash keys the cache (e.g. composer.lock).
     */
    public function __construct(
        public readonly string $name,
        public readonly array $paths,
        public readonly array $keyFiles,
    ) {
    }
}
