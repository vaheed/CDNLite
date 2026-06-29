<?php

namespace App\Console\Commands;

use App\Services\ControlPlane\TelemetryRetentionService;
use App\Support\CommandIO;

class CdnUsagePruneCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $days = isset($opts['days']) && is_numeric($opts['days']) ? (int) $opts['days'] : null;
        $dryRun = isset($opts['dry-run']) || isset($opts['dry_run']);
        $batchSize = isset($opts['batch-size']) && is_numeric($opts['batch-size']) ? (int) $opts['batch-size'] : null;
        $retention = new TelemetryRetentionService();

        if (isset($opts['all'])) {
            CommandIO::printJson([
                'data' => $retention->pruneOperationalRetention([
                    'dry_run' => $dryRun,
                    'batch_size' => $batchSize,
                    'usage_days' => $days,
                    'security_days' => $this->intOption($opts, 'security-days'),
                    'dns_days' => $this->intOption($opts, 'dns-days'),
                    'ssl_job_days' => $this->intOption($opts, 'ssl-job-days'),
                    'idempotency_days' => $this->intOption($opts, 'idempotency-days'),
                ]),
            ]);
            return 0;
        }

        CommandIO::printJson(['data' => $retention->pruneDetailedEvents($days, $dryRun, $batchSize)]);
        return 0;
    }

    private function intOption(array $opts, string $name): ?int
    {
        return isset($opts[$name]) && is_numeric($opts[$name]) ? (int) $opts[$name] : null;
    }
}
