<?php

namespace App\Console\Commands;

use App\Modules\Collector\Services\CollectorService;
use App\Support\CommandIO;

class CdnUsageRecalculateCommand
{
    public function __invoke(array $argv): int
    {
        // Current storage model is append-only usage rows.
        // Recalculate is equivalent to deterministic summary recomputation.
        $summary = (new CollectorService())->summary(null);
        CommandIO::printJson(['ok' => true, 'summary' => $summary]);
        return 0;
    }
}
