<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\DnsService;
use App\Modules\Proxy\Services\ConfigService;
use App\Modules\Domains\Services\DomainService;
use App\Support\CommandIO;

class CdnEdgeSyncConfigCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $ifVersion = isset($opts['if_version']) ? (int) $opts['if_version'] : null;
        $snapshot = (new ConfigService(new DomainService(), new DnsService()))->edgeConfig($ifVersion);
        CommandIO::printJson($snapshot);
        return 0;
    }
}
