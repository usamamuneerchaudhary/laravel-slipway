<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Support;

use Usamamuneerchaudhary\Slipway\DriverManager;
use Usamamuneerchaudhary\Slipway\Model\Feature;
use Usamamuneerchaudhary\Slipway\Model\Support;

/** Feature x provider table, used by `slipway:drivers` and generated into the README. */
final class CapabilityMatrix
{
    /**
     * @return array{headers:list<string>,rows:list<list<string>>,notes:list<string>}
     */
    public static function build(DriverManager $drivers): array
    {
        $all = $drivers->all();
        ksort($all);

        $headers = ['Feature'];
        foreach ($all as $driver) {
            $headers[] = $driver->label();
        }

        $rows = [];
        foreach (Feature::cases() as $feature) {
            $row = [$feature->label()];
            foreach ($all as $driver) {
                $row[] = ($driver->capabilities()[$feature->value] ?? Support::None)->symbol();
            }
            $rows[] = $row;
        }

        $notes = [];
        foreach ($all as $driver) {
            foreach ($driver->notes() as $featureKey => $note) {
                $feature = Feature::tryFrom($featureKey);
                $notes[] = sprintf('%s / %s: %s', $driver->label(), $feature?->label() ?? $featureKey, $note);
            }
        }

        return ['headers' => $headers, 'rows' => $rows, 'notes' => $notes];
    }

    /** Markdown table, for documentation. */
    public static function markdown(DriverManager $drivers): string
    {
        $m = self::build($drivers);
        $lines = ['| ' . implode(' | ', $m['headers']) . ' |', '|' . str_repeat('---|', count($m['headers']))];
        foreach ($m['rows'] as $row) {
            $lines[] = '| ' . implode(' | ', $row) . ' |';
        }

        return implode("\n", $lines) . "\n";
    }
}
