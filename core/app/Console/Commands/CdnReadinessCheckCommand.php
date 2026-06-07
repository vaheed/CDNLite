<?php

namespace App\Console\Commands;

use App\Modules\Health\Services\ReadinessService;
use App\Support\CommandIO;

class CdnReadinessCheckCommand
{
    public function __invoke(array $argv): int
    {
        CommandIO::printJson(['data' => (new ReadinessService())->check()]);
        return 0;
    }
}
