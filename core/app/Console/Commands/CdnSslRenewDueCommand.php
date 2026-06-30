<?php

namespace App\Console\Commands;

use App\Services\ControlPlane\SslRenewalService;
use App\Support\CommandIO;

class CdnSslRenewDueCommand
{
    public function __invoke(array $argv): int
    {
        $service = new SslRenewalService();
        $queued = $service->processQueuedJobs();
        $renewals = $service->renewDue();
        $result = ['queued' => $queued, 'renewals' => $renewals];
        CommandIO::printJson($result);
        foreach (array_merge($queued['results'], $renewals['results']) as $item) {
            if (($item['status'] ?? null) === 'error') {
                return 1;
            }
        }
        return 0;
    }
}
