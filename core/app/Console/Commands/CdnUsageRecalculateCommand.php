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
        $service = new CollectorService();
        $result = $service->rebuildAggregates($domainId);
        $run = $service->runNextRollupJob('cli');
        $result['worker'] = $run;
        $result['job'] = isset($result['job_id']) ? $service->rollupJob((string) $result['job_id']) : null;
        $result['summary'] = $service->summary($domainId);
        $result['aggregates'] = [
            'minute' => $service->summary($domainId, 'minute'),
            'hour' => $service->summary($domainId, 'hour'),
            'day' => $service->summary($domainId, 'day'),
        ];
        CommandIO::printJson($result);
        return 0;
    }
}
