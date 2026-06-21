<?php

namespace App\Modules\Dns\Services;

use App\Modules\Settings\Repositories\SettingsRepository;
use App\Support\Database;

class DnsOperationsService
{
    public function __construct(
        private SettingsRepository $settings = new SettingsRepository(),
        private PowerDnsService $powerDns = new PowerDnsService(),
        private DnsDesiredStateBuilder $builder = new DnsDesiredStateBuilder(),
        private DnsReconciler $reconciler = new DnsReconciler()
    ) {
    }

    public function status(): array
    {
        $powerDns = $this->settings->group('platform.powerdns')['values'];
        $edgeDns = $this->settings->group('platform.edge_dns')['values'];
        $api = $this->powerDns->isEnabled()
            ? $this->powerDns->healthCheck()
            : ['ok' => false, 'disabled' => true];

        return [
            'setup' => [
                'enabled' => $this->powerDns->isEnabled(),
                'configured' => $this->powerDns->isConfigured(),
                'api_url' => (string) $powerDns['api_url'],
                'server_id' => (string) $powerDns['server_id'],
                'api_key_configured' => (bool) ($powerDns['api_key']['configured'] ?? false),
                'cdn_zone' => (string) $edgeDns['cdn_zone'],
                'cdn_proxy_host' => (string) $edgeDns['proxy_host'],
                'static_anycast' => [
                    'ipv4' => array_values((array) ($edgeDns['anycast_ipv4'] ?? [])),
                    'ipv6' => array_values((array) ($edgeDns['anycast_ipv6'] ?? [])),
                ],
                'apex_proxy_mode' => 'DIRECT',
                'bundled_dnsgeo' => true,
                'poweradmin_url' => (string) (getenv('CDNLITE_POWERADMIN_URL') ?: 'http://localhost:8084'),
                'api' => $api,
            ],
            'dnsgeo' => [
                'powerdns_auth' => $api['ok'] ?? false,
                'postgresql' => $api['ok'] ?? false,
                'mmdb' => true,
                'edns_subnet_processing' => true,
                'lua_records' => true,
                'alias_expansion' => false,
                'resolver_configured' => true,
                'resolver' => 'pdns-recursor:5300',
                'api_publicly_exposed' => false,
            ],
            'sync' => (new DnsSyncStateService())->summary(),
        ];
    }

    public function zones(): array
    {
        $states = (new DnsSyncStateService())->summary()['zones'];
        $desired = $this->builder->build();
        $counts = [];
        foreach ($desired as $rrset) {
            $counts[$rrset['zone_name']] = ($counts[$rrset['zone_name']] ?? 0) + 1;
        }
        foreach ($states as &$state) {
            $state['desired_rrsets'] = $counts[$state['zone_name']] ?? 0;
            $state['converged'] = $state['status'] === 'ok'
                && $state['desired_hash'] === $state['applied_hash']
                && (int) $state['pending_changes'] === 0;
        }
        return $states;
    }

    public function desired(?string $zone = null): array
    {
        return array_values(array_filter(
            $this->builder->build(),
            static fn (array $rrset): bool => $zone === null
                || rtrim(strtolower($rrset['zone_name']), '.') === rtrim(strtolower($zone), '.')
        ));
    }

    public function actual(string $zone): array
    {
        return $this->powerDns->getZone($zone);
    }

    public function dryRun(): array
    {
        return $this->reconciler->preview();
    }

    public function forceSync(): array
    {
        return $this->reconciler->reconcile(true);
    }

    public function domainStatus(string $domainId): array
    {
        $stmt = Database::pdo()->prepare('SELECT domain FROM domains WHERE id = :id');
        $stmt->execute(['id' => $domainId]);
        $domain = $stmt->fetchColumn();
        if ($domain === false) {
            return ['error' => 'domain_not_found', 'status' => 404];
        }
        $zone = rtrim(strtolower((string) $domain), '.') . '.';
        $stateStmt = Database::pdo()->prepare(
            'SELECT status, last_attempt_at, last_success_at, last_error, pending_changes,
                    desired_hash, applied_hash
             FROM dns_sync_state WHERE zone_name = :zone'
        );
        $stateStmt->execute(['zone' => $zone]);
        $state = $stateStmt->fetch();
        return [
            'data' => [
                'zone' => $zone,
                'status' => $state === false ? 'pending' : (string) $state['status'],
                'last_attempt_at' => $state === false ? null : $state['last_attempt_at'],
                'last_success_at' => $state === false ? null : $state['last_success_at'],
                'last_error' => $state === false ? null : $state['last_error'],
                'pending_changes' => $state === false ? 0 : (int) $state['pending_changes'],
                'converged' => $state !== false
                    && $state['status'] === 'ok'
                    && $state['desired_hash'] === $state['applied_hash'],
            ],
        ];
    }
}
