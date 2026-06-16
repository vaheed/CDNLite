<?php

namespace App\Modules\Dns\Services;

use App\Modules\Settings\Repositories\SettingsRepository;
use App\Support\Database;

class EdgeDnsService
{
    private PowerDnsService $powerDns;
    private PowerDnsRecordBuilder $records;
    private EdgeHealthRecordBuilder $health;
    private SettingsRepository $settings;

    public function __construct()
    {
        $this->powerDns = new PowerDnsService();
        $this->records = new PowerDnsRecordBuilder();
        $this->health = new EdgeHealthRecordBuilder();
        $this->settings = new SettingsRepository();
    }

    public function bootstrap(): array
    {
        return (new DnsReconciler())->reconcile(true);
    }

    public function sync(bool $force = false): array
    {
        return (new DnsReconciler())->reconcile($force);
    }

    public function desiredRrsets(): array
    {
        $zone = $this->cdnZone();
        $ttl = $this->ttl();
        $pool = $this->activeEdgePool();
        $this->persistGeneration($pool);
        $rrsets = [
            $this->desired('@', 'NS', $ttl, $this->records->nameservers(), 'platform_nameservers'),
        ];

        foreach (['A' => 'ipv4', 'AAAA' => 'ipv6'] as $type => $family) {
            $targets = array_merge($pool['anycast'][$family], $pool['unicast'][$family]);
            $content = $this->health->luaRecord($type, $targets);
            if ($content === null) {
                continue;
            }
            $rrsets[] = $this->desired(
                $this->proxyLabel(),
                'LUA',
                $ttl,
                [$content],
                'shared_proxy:' . $type
            );
        }
        $targets = Database::pdo()->query(
            "SELECT DISTINCT d.id
             FROM domains d
             JOIN dns_records r ON r.domain_id = d.id
             WHERE r.proxied = true AND r.status = 'active'
             ORDER BY d.id"
        )->fetchAll();
        foreach ($targets as $target) {
            $rrsets[] = $this->desired(
                'site-' . $this->label((string) $target['id']),
                'CNAME',
                $ttl,
                [$this->proxyHost() . '.'],
                'site_proxy:' . (string) $target['id']
            );
        }
        return $rrsets;
    }

    public function validate(): array
    {
        $pool = $this->activeEdgePool();
        return [
            'cdn_zone' => $this->cdnZone(),
            'proxy_host' => $this->proxyHost(),
            'active_edge_nodes' => $pool['nodes'],
            'generated_edge_hostnames' => [$this->proxyHost() . '.'],
            'customer_records' => [],
            'invalid' => $pool['warnings'],
        ];
    }

    public function status(): array
    {
        $pool = $this->activeEdgePool();
        $stmt = Database::pdo()->prepare(
            'SELECT desired_hash, last_success_at FROM dns_sync_state WHERE zone_name = :zone LIMIT 1'
        );
        $stmt->execute(['zone' => $this->cdnZone() . '.']);
        $state = $stmt->fetch();

        return [
            'cdn_zone' => $this->cdnZone(),
            'proxy_host' => $this->proxyHost(),
            'powerdns_enabled' => $this->powerDns->isEnabled(),
            'records' => $this->desiredRrsets(),
            'edge_state' => $pool['nodes'],
            'warnings' => $pool['warnings'],
            'effective_hash' => $state === false ? null : (string) $state['desired_hash'],
            'synced_at' => $state === false ? null : (int) $state['last_success_at'],
        ];
    }

    public function ensureBaseZone(): array
    {
        if (!$this->powerDns->isEnabled()) {
            return ['ok' => true, 'powerdns_enabled' => false];
        }
        return $this->powerDns->ensureZone($this->cdnZone());
    }

    private function desired(string $name, string $type, int $ttl, array $contents, string $source): array
    {
        $zone = $this->cdnZone() . '.';
        $rrset = [
            'zone_name' => $zone,
            'rrset_name' => $name === '@' ? $zone : strtolower($name) . '.' . $zone,
            'rrset_type' => strtoupper($type),
            'ttl' => $ttl,
            'records' => array_values($contents),
            'source' => $source,
        ];
        $rrset['desired_hash'] = hash('sha256', json_encode($rrset, JSON_UNESCAPED_SLASHES) ?: '[]');
        return $rrset;
    }

    private function activeEdgePool(): array
    {
        $rows = Database::pdo()->query(
            'SELECT * FROM edge_state ORDER BY anycast DESC, region ASC, edge_id ASC, ip_family ASC, ip ASC'
        )->fetchAll();
        $nodes = [];
        $warnings = [];
        $anycast = ['ipv4' => [], 'ipv6' => []];
        $unicast = ['ipv4' => [], 'ipv6' => []];
        $seen = ['anycast' => ['ipv4' => [], 'ipv6' => []], 'unicast' => ['ipv4' => [], 'ipv6' => []]];

        foreach ($rows as $row) {
            if (!(bool) $row['healthy']) {
                $warnings[] = ['edge_id' => (string) $row['edge_id'], 'error' => 'edge_not_healthy'];
                continue;
            }
            $ip = trim((string) $row['ip']);
            $family = (string) $row['ip_family'] === 'AAAA' ? 'ipv6' : 'ipv4';
            $bucket = (bool) $row['anycast'] ? 'anycast' : 'unicast';
            if (!isset($seen[$bucket][$family][$ip])) {
                $seen[$bucket][$family][$ip] = true;
                $target = [
                    'ip' => $ip,
                    'country' => (string) $row['country'],
                    'continent' => (string) $row['continent'],
                ];
                if ($bucket === 'anycast') {
                    $anycast[$family][] = $target;
                } else {
                    $unicast[$family][] = $target;
                }
            }
            $nodes[] = [
                'edge_id' => (string) $row['edge_id'],
                'ip' => $ip,
                'ip_family' => (string) $row['ip_family'],
                'region' => (string) $row['region'],
                'country' => (string) $row['country'],
                'continent' => (string) $row['continent'],
                'anycast' => (bool) $row['anycast'],
                'healthy' => true,
                'last_check_at' => (int) $row['last_check_at'],
            ];
        }

        return [
            'nodes' => $nodes,
            'warnings' => $warnings,
            'anycast' => $anycast,
            'unicast' => $unicast,
        ];
    }

    private function persistGeneration(array $pool): void
    {
        $hash = hash('sha256', json_encode($pool['nodes'], JSON_UNESCAPED_SLASHES) ?: '[]');
        Database::pdo()->prepare(
            'INSERT INTO edge_state_generations (state_hash, created_at)
             VALUES (:hash, :created_at) ON CONFLICT (state_hash) DO NOTHING'
        )->execute(['hash' => $hash, 'created_at' => time()]);
    }

    private function cdnZone(): string
    {
        return rtrim(strtolower((string) $this->settings->value('platform.edge_dns', 'cdn_zone')), '.');
    }

    private function proxyHost(): string
    {
        $host = rtrim(strtolower((string) $this->settings->value('platform.edge_dns', 'proxy_host')), '.');
        $suffix = '.' . $this->cdnZone();
        if ($host === $this->cdnZone() || !str_ends_with($host, $suffix)) {
            throw new \RuntimeException('cdn_proxy_host_must_belong_to_cdn_zone');
        }
        return $host;
    }

    private function proxyLabel(): string
    {
        return substr($this->proxyHost(), 0, -strlen('.' . $this->cdnZone()));
    }

    private function ttl(): int
    {
        $interval = (int) (getenv('CDNLITE_SYNC_INTERVAL_SECONDS') ?: 30);
        return max(30, $interval * 2);
    }

    private function label(string $value): string
    {
        $value = strtolower(preg_replace('/[^a-zA-Z0-9-]/', '', $value) ?? '');
        return trim($value, '-') ?: 'site';
    }
}
