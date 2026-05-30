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
        $opts = CommandIO::parseOptions($argv);
        $ifVersion = isset($opts['if_version']) ? (int) $opts['if_version'] : null;
        $snapshot = (new ConfigService(new SiteService(), new DnsService()))->buildSnapshotForVersion($ifVersion);
        CommandIO::printJson($snapshot);
        return 0;
    }
}
