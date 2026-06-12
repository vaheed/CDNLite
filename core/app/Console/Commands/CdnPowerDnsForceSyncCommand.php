<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\DnsReconciler;
use App\Support\CommandIO;

class CdnPowerDnsForceSyncCommand
{
    public function __invoke(array $argv): int
    {
        try {
            $result = (new DnsReconciler())->reconcile(true);
            CommandIO::printJson(['data' => $result]);
            return ($result['ok'] ?? false) ? 0 : 1;
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        }
    }
}
