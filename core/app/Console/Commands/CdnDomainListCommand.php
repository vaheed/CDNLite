<?php

namespace App\Console\Commands;

use App\Modules\Domains\Services\DomainService;
use App\Support\CommandIO;

class CdnDomainListCommand
{
    public function __invoke(array $argv): int
    {
        CommandIO::printJson(['data' => (new DomainService())->all()]);
        return 0;
    }
}
