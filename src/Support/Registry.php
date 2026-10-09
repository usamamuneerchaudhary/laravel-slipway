<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Support;

use Closure;
use InvalidArgumentException;

/**
 * Maps short aliases ("pint") and fully-qualified class names to instances of one contract.
 *
 * Inside Laravel, pass the container's `make` as $resolver so user classes get dependency
 * injection; standalone it falls back to plain `new`.
 */
final class Registry
{
    /** @var array<string,Closure():object> */
    private array $factories = [];

    private ?Closure $resolver;

    /**
     * @param class-string $contract
     */
    public function __construct(
        private readonly string $contract,
        private readonly string $kind,
        ?callable $resolver = null,
    ) {
        $this->resolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    /**
     * @param Closure():object|class-string $factory
     */
    public function register(string $alias, Closure|string $factory): void
    {
        $this->factories[$alias] = is_string($factory)
            ? fn (): object => $this->instantiate($factory)
            : $factory;
    }

    public function has(string $ref): bool
    {
        return isset($this->factories[$ref])
            || (class_exists($ref) && is_subclass_of($ref, $this->contract));
    }

    /** @return list<string> */
    public function aliases(): array
    {
        $aliases = array_keys($this->factories);
        sort($aliases);

        return $aliases;
    }

    public function resolve(string $ref): object
    {
        if (isset($this->factories[$ref])) {
            return ($this->factories[$ref])();
        }

        if (class_exists($ref)) {
            $instance = $this->instantiate($ref);
            if ($instance instanceof $this->contract) {
                return $instance;
            }

            throw new InvalidArgumentException("[{$ref}] does not implement {$this->contract}.");
        }

        throw new InvalidArgumentException(sprintf(
            'Unknown %s [%s]. Known aliases: %s. You can also pass a class that implements %s.',
            $this->kind,
            $ref,
            $this->aliases() === [] ? '(none)' : implode(', ', $this->aliases()),
            $this->contract
        ));
    }

    private function instantiate(string $class): object
    {
        if (! class_exists($class)) {
            throw new InvalidArgumentException("Class [{$class}] does not exist.");
        }

        return $this->resolver !== null ? ($this->resolver)($class) : new $class();
    }
}
