<?php

namespace App\Console\Commands;

use App\Modules\Proxy\Services\OriginHealthService;
use App\Support\CommandIO;

class CdnOriginsHealthCheckCommand
{
    public function __invoke(array $argv): int
    {
        CommandIO::printJson((new OriginHealthService())->checkDue());
        return 0;
    }
}
