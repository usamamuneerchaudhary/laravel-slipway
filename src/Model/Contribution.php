<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Model;

/**
 * What a pipeline Step class adds to the job it runs in: steps plus any
 * environment requirements (Node, Bun, services, build artifact, extra env).
 */
final class Contribution
{
    /**
     * @param list<Step>           $steps
     * @param list<string>         $services      Service aliases, e.g. "mysql", "redis".
     * @param list<string>         $artifactPaths Paths to publish as the build artifact.
     * @param array<string,string> $env
     * @param string|null          $coverage      Coverage driver for setup-php ("pcov" | "xdebug").
     * @param bool                 $noDev         Install Composer dependencies with --no-dev.
     * @param array{php?:string,schedule?:string,coverage?:string,parallel?:bool}|null $tia
     */
    public function __construct(
        public readonly array $steps,
        public readonly bool $requiresNode = false,
        public readonly array $services = [],
        public readonly array $artifactPaths = [],
        public readonly array $env = [],
        public readonly ?string $coverage = null,
        public readonly bool $noDev = false,
        public readonly bool $requiresBun = false,
        public readonly ?array $tia = null,
    ) {
    }

    public static function steps(Step ...$steps): self
    {
        return new self(array_values($steps));
    }

    public function withNode(): self
    {
        return $this->copy(['requiresNode' => true]);
    }

    public function withBun(): self
    {
        return $this->copy(['requiresBun' => true]);
    }

    /** @param list<string> $services */
    public function withServices(array $services): self
    {
        return $this->copy(['services' => $services]);
    }

    /** @param list<string> $paths */
    public function withArtifact(array $paths): self
    {
        return $this->copy(['artifactPaths' => $paths]);
    }

    /** @param array<string,string> $env */
    public function withEnv(array $env): self
    {
        return $this->copy(['env' => $env]);
    }

    public function withCoverage(?string $driver): self
    {
        return $this->copy(['coverage' => $driver]);
    }

    public function withoutDevDependencies(): self
    {
        return $this->copy(['noDev' => true]);
    }

    /** @param array{php?:string,schedule?:string,coverage?:string,parallel?:bool} $tia */
    public function withTia(array $tia): self
    {
        return $this->copy(['tia' => $tia]);
    }

    /** @param array<string,mixed> $changes */
    private function copy(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
