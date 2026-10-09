<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Model;

final class Environment
{
    /**
     * @param array{url?:string,path?:string,expect?:int,retries?:int,delay?:int}|null $verify
     * @param array<string,mixed> $options  Deployer-specific options.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $branch,
        public readonly ?string $tag,
        public readonly ?string $after,
        public readonly bool $approval,
        public readonly string $deployer,
        public readonly ?array $verify,
        public readonly bool $rollback,
        public readonly array $options = [],
    ) {
    }
}
