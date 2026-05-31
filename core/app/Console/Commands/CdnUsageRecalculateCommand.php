<?php

namespace App\Console\Commands;

use App\Modules\Collector\Services\CollectorService;
use App\Support\CommandIO;

class CdnUsageRecalculateCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $siteId = isset($opts['site_id']) ? (string) $opts['site_id'] : null;
        $result = (new CollectorService())->rebuildAggregates($siteId);
        $result['summary'] = (new CollectorService())->summary($siteId);
        $result['aggregates'] = [
            'minute' => (new CollectorService())->summary($siteId, 'minute'),
            'hour' => (new CollectorService())->summary($siteId, 'hour'),
            'day' => (new CollectorService())->summary($siteId, 'day'),
        ];
        CommandIO::printJson($result);
        return 0;
    }
}
