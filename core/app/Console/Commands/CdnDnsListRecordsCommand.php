<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\DnsService;
use App\Support\CommandIO;

class CdnDnsListRecordsCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $siteId = (int) ($opts['site_id'] ?? 0);
        if ($siteId <= 0) {
            fwrite(STDERR, "Missing --site_id\n");
            return 1;
        }

        CommandIO::printJson(['data' => (new DnsService())->listBySite($siteId)]);
        return 0;
    }
}
