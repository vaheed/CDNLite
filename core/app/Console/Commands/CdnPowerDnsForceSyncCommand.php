<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\DnsService;
use App\Modules\Dns\Services\EdgeDnsService;
use App\Support\CommandIO;

class CdnPowerDnsForceSyncCommand
{
    public function __invoke(array $argv): int
    {
        try {
            $customer = (new DnsService())->rebuildCustomerZones();
            $edge = (new EdgeDnsService())->sync(true);
            CommandIO::printJson(['data' => ['customer' => $customer, 'edge' => $edge]]);
            return (($customer['ok'] ?? false) && ($edge['ok'] ?? false)) ? 0 : 1;
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        }
    }
}
