<?php

namespace App\Console\Commands;

use App\Modules\Proxy\Services\CertRenewalService;
use App\Support\CommandIO;

class CdnSslRenewDueCommand
{
    public function __invoke(array $argv): int
    {
        $service = new CertRenewalService();
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
