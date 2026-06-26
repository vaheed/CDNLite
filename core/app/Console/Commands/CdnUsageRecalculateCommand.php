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
        $bucket = isset($opts['bucket']) ? (string) $opts['bucket'] : null;
        $service = new CollectorService();
        $result = $service->rebuildAggregates($domainId, $bucket);
        $run = isset($result['inserted'])
            ? ['ok' => true, 'ran' => true, 'job_id' => $result['job_id'] ?? null, 'inserted' => $result['inserted']]
            : $service->runNextRollupJob('cli', isset($result['job_id']) ? (string) $result['job_id'] : null);
        $result['worker'] = $run;
        $result['inserted'] = $run['inserted'] ?? [];
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
