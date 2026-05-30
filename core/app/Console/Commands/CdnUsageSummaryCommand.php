<?php

namespace App\Console\Commands;

use App\Modules\Collector\Services\CollectorService;
use App\Support\CommandIO;

class CdnUsageSummaryCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $siteId = isset($opts['site_id']) ? (int) $opts['site_id'] : null;
        CommandIO::printJson(['data' => (new CollectorService())->summary($siteId)]);
        return 0;
    }
}
