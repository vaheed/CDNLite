<?php

namespace App\Console\Commands;

use App\Modules\Collector\Services\CollectorService;
use App\Support\CommandIO;

class CdnUsageSummaryCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $siteId = isset($opts['site_id']) ? (string) $opts['site_id'] : null;
        $bucket = isset($opts['bucket']) ? (string) $opts['bucket'] : null;
        CommandIO::printJson(['data' => (new CollectorService())->summary($siteId, $bucket)]);
        return 0;
    }
}
