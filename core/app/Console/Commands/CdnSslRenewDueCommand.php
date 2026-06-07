<?php

namespace App\Console\Commands;

use App\Modules\Proxy\Services\CertRenewalService;
use App\Support\CommandIO;

class CdnSslRenewDueCommand
{
    public function __invoke(array $argv): int
    {
        $result = (new CertRenewalService())->renewDue();
        CommandIO::printJson($result);
        foreach ($result['results'] as $item) {
            if (($item['status'] ?? null) === 'error') {
                return 1;
            }
        }
        return 0;
    }
}
