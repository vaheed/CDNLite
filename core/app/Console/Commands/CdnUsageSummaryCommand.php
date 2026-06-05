<?php

namespace App\Console\Commands;

use App\Modules\Collector\Services\CollectorService;
use App\Support\CommandIO;

class CdnUsageSummaryCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $domainId = isset($opts['domain_id']) ? (string) $opts['domain_id'] : null;
        $bucket = isset($opts['bucket']) ? (string) $opts['bucket'] : null;
        CommandIO::printJson(['data' => (new CollectorService())->summary($domainId, $bucket)]);
        return 0;
    }
}
