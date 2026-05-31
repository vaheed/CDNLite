<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\EdgeDnsService;
use App\Support\CommandIO;

class CdnDnsValidateRoutingCommand
{
    public function __invoke(array $argv): int
    {
        CommandIO::printJson((new EdgeDnsService())->validate());
        return 0;
    }
}
