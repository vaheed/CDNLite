<?php

namespace App\Console\Commands;

use App\Modules\Edge\Services\EdgeService;
use App\Support\CommandIO;

class CdnEdgeListCommand
{
    public function __invoke(array $argv): int
    {
        CommandIO::printJson(['data' => (new EdgeService())->list()]);
        return 0;
    }
}
