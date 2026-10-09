<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Model;

/**
 * What a pipeline Step class adds to the job it runs in: steps plus any
 * environment requirements (Node, services, build artifact, extra env).
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
     */
    public function __construct(
        public readonly array $steps,
        public readonly bool $requiresNode = false,
        public readonly array $services = [],
        public readonly array $artifactPaths = [],
        public readonly array $env = [],
        public readonly ?string $coverage = null,
        public readonly bool $noDev = false,
    ) {
    }

    public static function steps(Step ...$steps): self
    {
        return new self(array_values($steps));
    }

    public function withNode(): self
    {
        return new self($this->steps, true, $this->services, $this->artifactPaths, $this->env, $this->coverage, $this->noDev);
    }

    /** @param list<string> $services */
    public function withServices(array $services): self
    {
        return new self($this->steps, $this->requiresNode, $services, $this->artifactPaths, $this->env, $this->coverage, $this->noDev);
    }

    /** @param list<string> $paths */
    public function withArtifact(array $paths): self
    {
        return new self($this->steps, $this->requiresNode, $this->services, $paths, $this->env, $this->coverage, $this->noDev);
    }

    /** @param array<string,string> $env */
    public function withEnv(array $env): self
    {
        return new self($this->steps, $this->requiresNode, $this->services, $this->artifactPaths, $env, $this->coverage, $this->noDev);
    }

    public function withCoverage(?string $driver): self
    {
        return new self($this->steps, $this->requiresNode, $this->services, $this->artifactPaths, $this->env, $driver, $this->noDev);
    }

    public function withoutDevDependencies(): self
    {
        return new self($this->steps, $this->requiresNode, $this->services, $this->artifactPaths, $this->env, $this->coverage, true);
    }
}
