<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\DnsService;
use App\Support\CommandIO;

class CdnDnsRebuildCustomerZonesCommand
{
    public function __invoke(array $argv): int
    {
        try {
            CommandIO::printJson((new DnsService())->rebuildCustomerZones());
            return 0;
        } catch (\RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        }
    }
}
