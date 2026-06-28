<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\DnsService;
use App\Modules\Domains\Services\DomainService;
use App\Modules\Proxy\Services\ConfigService;
use App\Support\CommandIO;

class CdnConfigSnapshotsPruneCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $keep = isset($opts['keep']) ? (int) $opts['keep'] : 2;
        $batch = isset($opts['batch']) ? (int) $opts['batch'] : 5000;
        $dryRun = isset($opts['dry-run']) || isset($opts['dry_run']);

        $result = (new ConfigService(new DomainService(), new DnsService()))
            ->pruneSnapshots($keep, $batch > 0 ? $batch : null, $dryRun);
        CommandIO::printJson($result);
        return 0;
    }
}
