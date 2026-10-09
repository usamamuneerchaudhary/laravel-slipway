<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Contracts;

use Usamamuneerchaudhary\Slipway\Model\Pipeline;
use Usamamuneerchaudhary\Slipway\Policies\Violation;

/**
 * PUBLIC API. Policy-as-code: a rule evaluated against the compiled pipeline
 * before any YAML is written. An `error` violation aborts compilation.
 */
interface Policy
{
    /** Short stable identifier shown in reports, e.g. "audit-before-production". */
    public function name(): string;

    /**
     * @param array<string,mixed> $options
     * @return list<Violation>
     */
    public function check(Pipeline $pipeline, array $options = []): array;
}
