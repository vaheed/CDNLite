<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\PowerDnsService;
use App\Support\CommandIO;

class CdnSettingsTestPowerDnsCommand
{
    public function __invoke(array $argv): int
    {
        CommandIO::printJson(['data' => (new PowerDnsService())->healthCheck()]);
        return 0;
    }
}
