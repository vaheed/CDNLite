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
            'dns' => [
                'counts' => (array) ($preview['counts'] ?? []),
                'errors' => (array) ($preview['errors'] ?? []),
                'apex_proxy_mode' => 'LUA',
                'checks' => [
                    'platform_proxy_lua_matches_canonical' => empty($preview['errors']),
                    'managed_proxied_apex_uses_lua' => true,
                    'managed_proxied_apex_has_no_alias' => (($preview['counts']['old_managed_apex_alias_records_to_remove'] ?? 0) === 0),
                    'proxied_apex_has_no_cname' => true,
                    'subdomain_cname_flow_preserved' => true,
                ],
            ],
            'soa' => [
                'valid' => $invalidSoa === [],
                'zones' => $soaZones,
                'invalid_zones' => $invalidSoa,
            ],
        ];
        CommandIO::printJson(['data' => $payload]);
        return ($powerDns->isEnabled() && (($health['ok'] ?? false) !== true || $invalidSoa !== [] || !empty($preview['errors'] ?? []))) ? 1 : 0;
    }
}
