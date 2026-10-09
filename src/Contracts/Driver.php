<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Contracts;

use Usamamuneerchaudhary\Slipway\Model\Pipeline;
use Usamamuneerchaudhary\Slipway\Model\Support;

/**
 * PUBLIC API. A driver renders the neutral Pipeline model as one provider's YAML.
 *
 * Third-party drivers register through the DriverManager
 * (see https://laravel-slipway.mintlify.site/writing-a-driver)
 * and should pass the shared conformance suite in tests/Conformance.
 */
interface Driver
{
    /** Unique key used in config('slipway.drivers'), e.g. "github". */
    public function name(): string;

    /** Human-readable provider name, e.g. "GitHub Actions". */
    public function label(): string;

    /** Where the file is written when the config does not override `path`. */
    public function defaultPath(): string;

    /**
     * Support level for every Feature. A Feature missing from the map is treated as Support::None.
     *
     * @return array<string,Support> keyed by Feature::value
     */
    public function capabilities(): array;

    /**
     * Explanations for Partial/None features, keyed by Feature::value.
     *
     * @return array<string,string>
     */
    public function notes(): array;

    /**
     * Render the YAML body (no header; the compiler adds that).
     *
     * @param array<string,mixed> $options The driver's config block (path, raw, ...).
     */
    public function render(Pipeline $pipeline, array $options = []): string;
}
