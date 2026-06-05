<?php

namespace App\Console\Commands;

use App\Modules\Collector\Services\CollectorService;
use App\Support\CommandIO;

class CdnUsageRecalculateCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $domainId = isset($opts['domain_id']) ? (string) $opts['domain_id'] : null;
        $result = (new CollectorService())->rebuildAggregates($domainId);
        $result['summary'] = (new CollectorService())->summary($domainId);
        $result['aggregates'] = [
            'minute' => (new CollectorService())->summary($domainId, 'minute'),
            'hour' => (new CollectorService())->summary($domainId, 'hour'),
            'day' => (new CollectorService())->summary($domainId, 'day'),
        ];
        CommandIO::printJson($result);
        return 0;
    }
}
