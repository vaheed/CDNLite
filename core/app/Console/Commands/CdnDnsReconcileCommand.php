<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\DnsReconciler;
use App\Support\CommandIO;

class CdnDnsReconcileCommand
{
    public function __invoke(array $argv): int
    {
        $result = (new DnsReconciler())->reconcile(in_array('--force', $argv, true));
        CommandIO::printJson(['data' => $result]);
        return ($result['ok'] ?? false) ? 0 : 1;
    }
}
