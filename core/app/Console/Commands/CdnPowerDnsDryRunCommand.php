<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\DnsReconciler;
use App\Support\CommandIO;

class CdnPowerDnsDryRunCommand
{
    public function __invoke(array $argv): int
    {
        $preview = (new DnsReconciler())->preview();
        CommandIO::printJson(['data' => $preview]);
        return 0;
    }
}
