<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Policies;

use InvalidArgumentException;
use Throwable;
use Usamamuneerchaudhary\Slipway\Contracts\Policy;
use Usamamuneerchaudhary\Slipway\Model\Pipeline;
use Usamamuneerchaudhary\Slipway\Support\Registry;

/** Evaluates policy-as-code against the compiled pipeline. `declared-secrets` always runs. */
final class PolicyEngine
{
    public function __construct(private readonly Registry $policies)
    {
    }

    /**
     * @param list<array{ref:string,options:array<string,mixed>}> $configured
     * @return list<Violation>
     */
    public function evaluate(Pipeline $pipeline, array $configured): array
    {
        $entries = [['ref' => 'declared-secrets', 'options' => []]];
        foreach ($configured as $entry) {
            if ($entry['ref'] !== 'declared-secrets') {
                $entries[] = $entry;
            }
        }

        $violations = [];
        foreach ($entries as $entry) {
            try {
                $policy = $this->policies->resolve($entry['ref']);
                if (! $policy instanceof Policy) {
                    throw new InvalidArgumentException("[{$entry['ref']}] does not implement " . Policy::class . '.');
                }

                foreach ($policy->check($pipeline, $entry['options']) as $violation) {
                    $violations[] = $violation;
                }
            } catch (Throwable $e) {
                $violations[] = new Violation($entry['ref'], 'Could not be evaluated: ' . $e->getMessage());
            }
        }

        return $violations;
    }
}
