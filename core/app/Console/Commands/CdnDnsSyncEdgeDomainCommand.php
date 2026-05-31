<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\EdgeDnsService;
use App\Support\CommandIO;

class CdnDnsSyncEdgeDomainCommand
{
    public function __invoke(array $argv): int
    {
        try {
            CommandIO::printJson((new EdgeDnsService())->sync(true));
            return 0;
        } catch (\RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        }
    }
}
