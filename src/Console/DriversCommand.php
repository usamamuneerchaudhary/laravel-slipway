<?php

declare(strict_types=1);

namespace Usamamuneerchaudhary\Slipway\Console;

use Illuminate\Console\Command;
use Usamamuneerchaudhary\Slipway\DriverManager;
use Usamamuneerchaudhary\Slipway\Support\CapabilityMatrix;

final class DriversCommand extends Command
{
    protected $signature = 'slipway:drivers';

    protected $description = 'Show which features each CI provider supports';

    public function handle(DriverManager $drivers): int
    {
        $matrix = CapabilityMatrix::build($drivers);

        $this->table($matrix['headers'], $matrix['rows']);

        foreach ($matrix['notes'] as $note) {
            $this->line('  * ' . $note);
        }

        return self::SUCCESS;
    }
}
