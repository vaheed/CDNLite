<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\DnsSyncStateService;
use App\Modules\Dns\Services\DnsReconciler;
use App\Modules\Dns\Services\PowerDnsService;
use App\Support\CommandIO;

class CdnPowerDnsDoctorCommand
{
    public function __invoke(array $argv): int
    {
        $powerDns = new PowerDnsService();
        $health = $powerDns->healthCheck();
        $preview = ($powerDns->isEnabled() && ($health['ok'] ?? false) === true)
            ? (new DnsReconciler())->preview()
            : ['soa' => []];
        $soaZones = (array) ($preview['soa'] ?? []);
        $invalidSoa = array_values(array_filter($soaZones, static fn (array $zone): bool => ($zone['valid'] ?? false) !== true));
        $payload = [
            'enabled' => $powerDns->isEnabled(),
            'configured' => $powerDns->isConfigured(),
            'strict' => $powerDns->isStrict(),
            'api' => $health,
            'sync' => (new DnsSyncStateService())->summary(),
            'soa' => [
                'valid' => $invalidSoa === [],
                'zones' => $soaZones,
                'invalid_zones' => $invalidSoa,
            ],
        ];
        CommandIO::printJson(['data' => $payload]);
        return ($powerDns->isEnabled() && (($health['ok'] ?? false) !== true || $invalidSoa !== [])) ? 1 : 0;
    }
}
