<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Steps;

use InvalidArgumentException;
use Usamamuneerchaudhary\Slipway\Contracts\PipelineStep;

/**
 * Base class for built-in steps. Gives them strict option validation so a typo in
 * config (e.g. "paralel") fails loudly instead of being silently ignored.
 */
abstract class AbstractStep implements PipelineStep
{
    /**
     * @param array<string,mixed> $given
     * @param array<string,string> $allowed option name => expected type: bool|int|string|list|bool|int
     * @return array<string,mixed>
     */
    protected function options(array $given, array $allowed): array
    {
        foreach ($given as $key => $value) {
            if (! array_key_exists($key, $allowed)) {
                throw new InvalidArgumentException(
                    sprintf('unknown option [%s]; allowed: %s', $key, $allowed === [] ? '(none)' : implode(', ', array_keys($allowed)))
                );
            }

            $type = $allowed[$key];
            $ok = match ($type) {
                'bool' => is_bool($value),
                'int' => is_int($value),
                'string' => is_string($value) && $value !== '',
                'list' => is_array($value) && array_is_list($value) && $this->allStrings($value),
                'bool|int' => is_bool($value) || is_int($value),
                'int|string' => is_int($value) || (is_string($value) && $value !== ''),
                default => true,
            };

            if (! $ok) {
                throw new InvalidArgumentException(sprintf('option [%s] must be of type %s', $key, $type));
            }
        }

        return $given;
    }

    /** @param array<mixed> $list */
    private function allStrings(array $list): bool
    {
        foreach ($list as $item) {
            if (! is_string($item) || $item === '') {
                return false;
            }
        }

        return true;
    }

    /** Reject values that could break out of a single shell word. */
    protected function shellSafe(string $value, string $option): string
    {
        if (preg_match('/^[A-Za-z0-9._\/=:@%+,\-]+$/', $value) !== 1) {
            throw new InvalidArgumentException(
                "option [{$option}] contains characters that are not allowed in a command argument: [{$value}]"
            );
        }

        return $value;
    }
}
