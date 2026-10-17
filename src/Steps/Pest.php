<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Steps;

use InvalidArgumentException;
use Usamamuneerchaudhary\Slipway\Model\Contribution;

/** Pest test runner. `parallel` needs Pest's built-in parallel runner (paratest). */
final class Pest extends AbstractTestStep
{
    public function contribute(array $options): Contribution
    {
        $tia = $this->tiaSpec($options);
        $runsInParallel = ($options['parallel'] ?? false) === true;
        unset($options['tia']);
        $contribution = parent::contribute($options);

        if ($tia === null) {
            return $contribution;
        }

        if (! array_key_exists('parallel', $tia)) {
            $tia['parallel'] = $runsInParallel;
        }

        return $contribution->withTia($tia);
    }

    protected function binary(): string
    {
        return 'vendor/bin/pest';
    }

    protected function label(): string
    {
        return 'Pest';
    }

    protected function parallelFlag(): ?string
    {
        return '--parallel';
    }

    protected function coverageArgs(bool|int $coverage): string
    {
        return is_int($coverage) ? '--coverage --min=' . $coverage : '--coverage';
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array{php?:string,schedule?:string,coverage?:string,parallel?:bool}|null
     */
    private function tiaSpec(array $options): ?array
    {
        if (! array_key_exists('tia', $options)) {
            return null;
        }

        $tia = $options['tia'];
        if ($tia === false) {
            return null;
        }

        if ($tia === true) {
            return [];
        }

        if (! is_array($tia) || array_is_list($tia)) {
            throw new InvalidArgumentException('option [tia] must be true or a map of php, schedule, coverage and parallel');
        }

        foreach (array_keys($tia) as $key) {
            if (! in_array($key, ['php', 'schedule', 'coverage', 'parallel'], true)) {
                throw new InvalidArgumentException('unknown option [tia.' . $key . ']; allowed: php, schedule, coverage, parallel');
            }
        }

        if (isset($tia['php']) && (! is_string($tia['php']) || preg_match('/^\d+(\.\d+){0,2}$/D', $tia['php']) !== 1)) {
            throw new InvalidArgumentException('option [tia.php] must be a PHP version such as "8.4"');
        }

        if (isset($tia['schedule']) && (! is_string($tia['schedule']) || preg_match('/^\S+( \S+){4}$/D', $tia['schedule']) !== 1)) {
            throw new InvalidArgumentException('option [tia.schedule] must be a 5-field cron expression');
        }

        if (isset($tia['coverage']) && ! in_array($tia['coverage'], ['xdebug', 'pcov'], true)) {
            throw new InvalidArgumentException('option [tia.coverage] must be xdebug or pcov');
        }

        if (isset($tia['parallel']) && ! is_bool($tia['parallel'])) {
            throw new InvalidArgumentException('option [tia.parallel] must be of type bool');
        }

        /** @var array{php?:string,schedule?:string,coverage?:string,parallel?:bool} $tia */
        return $tia;
    }
}
