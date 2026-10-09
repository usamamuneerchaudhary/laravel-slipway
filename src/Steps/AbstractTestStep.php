<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Steps;

use InvalidArgumentException;
use Usamamuneerchaudhary\Slipway\Support\ServiceCatalog;
use Usamamuneerchaudhary\Slipway\Model\Contribution;
use Usamamuneerchaudhary\Slipway\Model\Step;

/**
 * Shared behaviour for test runners (Pest, PHPUnit): prepare a Laravel .env, run the
 * suite, and request any service containers (mysql, postgres, redis) the tests need.
 */
abstract class AbstractTestStep extends AbstractStep
{
    /** Binary relative to the project root, e.g. "vendor/bin/pest". */
    abstract protected function binary(): string;

    abstract protected function label(): string;

    /** Flags enabling parallel execution, or null if the runner has none built in. */
    abstract protected function parallelFlag(): ?string;

    /**
     * Arguments that collect coverage. `true` = report only; an int is a minimum percentage.
     * Throw InvalidArgumentException if the runner cannot enforce a minimum.
     */
    abstract protected function coverageArgs(bool|int $coverage): string;

    public function contribute(array $options): Contribution
    {
        $o = $this->options($options, [
            'parallel' => 'bool',
            'coverage' => 'bool|int',
            'services' => 'list',
            'testsuite' => 'string',
            'prepare_env' => 'bool',
        ]);

        $services = $o['services'] ?? [];
        foreach ($services as $service) {
            if (! ServiceCatalog::has($service)) {
                throw new InvalidArgumentException(
                    sprintf('unknown service [%s]; available: %s', $service, implode(', ', ServiceCatalog::names()))
                );
            }
        }

        $cmd = $this->binary();

        if (($o['parallel'] ?? false) === true) {
            $flag = $this->parallelFlag();
            if ($flag === null) {
                throw new InvalidArgumentException('option [parallel] is not supported by ' . $this->label());
            }
            $cmd .= ' ' . $flag;
        }

        $coverage = $o['coverage'] ?? false;
        if ($coverage !== false) {
            if (is_int($coverage) && ($coverage < 1 || $coverage > 100)) {
                throw new InvalidArgumentException('option [coverage] must be true or a minimum percentage from 1 to 100');
            }
            $cmd .= ' ' . $this->coverageArgs($coverage);
        }

        if (isset($o['testsuite'])) {
            $cmd .= ' --testsuite=' . $this->shellSafe($o['testsuite'], 'testsuite');
        }

        $steps = [];
        if (($o['prepare_env'] ?? true) === true) {
            $steps[] = Step::run(
                'Prepare Laravel environment',
                "[ -f .env ] || cp .env.example .env\n" . Step::ARTISAN . ' key:generate --no-interaction'
            );
        }
        $steps[] = Step::run('Run tests (' . $this->label() . ')', $cmd);

        $contribution = (new Contribution($steps))
            ->withServices(array_values($services))
            ->withEnv(['APP_ENV' => 'testing']);

        return $coverage !== false ? $contribution->withCoverage('pcov') : $contribution;
    }
}
