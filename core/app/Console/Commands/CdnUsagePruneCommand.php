<?php

namespace App\Console\Commands;

use App\Modules\Collector\Services\CollectorService;
use App\Support\CommandIO;

class CdnUsagePruneCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $days = isset($opts['days']) && is_numeric($opts['days']) ? (int) $opts['days'] : null;
        $dryRun = isset($opts['dry-run']) || isset($opts['dry_run']);
        CommandIO::printJson(['data' => (new CollectorService())->pruneDetailedEvents($days, $dryRun)]);
        return 0;
    }
}
