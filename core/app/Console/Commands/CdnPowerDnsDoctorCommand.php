<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\DnsSyncStateService;
use App\Modules\Dns\Services\PowerDnsService;
use App\Support\CommandIO;

class CdnPowerDnsDoctorCommand
{
    public function __invoke(array $argv): int
    {
        $powerDns = new PowerDnsService();
        $health = $powerDns->healthCheck();
        $payload = [
            'enabled' => $powerDns->isEnabled(),
            'configured' => $powerDns->isConfigured(),
            'strict' => $powerDns->isStrict(),
            'api' => $health,
            'sync' => (new DnsSyncStateService())->summary(),
        ];
        CommandIO::printJson(['data' => $payload]);
        return ($powerDns->isEnabled() && ($health['ok'] ?? false) !== true) ? 1 : 0;
    }
}
