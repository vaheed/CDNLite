<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\EdgeDnsService;
use App\Support\CommandIO;

class CdnPowerDnsDryRunCommand
{
    public function __invoke(array $argv): int
    {
        $preview = (new EdgeDnsService())->validate();
        CommandIO::printJson(['data' => $preview]);
        return ($preview['invalid'] ?? []) === [] ? 0 : 1;
    }
}
