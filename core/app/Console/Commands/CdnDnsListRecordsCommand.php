<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\DnsService;
use App\Support\CommandIO;

class CdnDnsListRecordsCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $siteId = trim((string) ($opts['site_id'] ?? ''));
        if ($siteId === '') {
            fwrite(STDERR, "Missing --site_id\n");
            return 1;
        }

        CommandIO::printJson(['data' => (new DnsService())->listBySite($siteId)]);
        return 0;
    }
}
