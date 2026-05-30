<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\DnsService;
use App\Modules\Proxy\Services\ConfigService;
use App\Modules\Sites\Services\SiteService;
use App\Support\CommandIO;

class CdnEdgeSyncConfigCommand
{
    public function __invoke(array $argv): int
    {
        $snapshot = (new ConfigService(new SiteService(), new DnsService()))->buildSnapshot();
        CommandIO::printJson($snapshot);
        return 0;
    }
}
