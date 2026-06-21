<?php

namespace App\Modules\Dns\Services;

use App\Modules\Settings\Repositories\SettingsRepository;
use App\Support\Database;

class EdgeDnsService
{
    private PowerDnsService $powerDns;
    private PowerDnsRecordBuilder $records;
    private EdgeDnsPoolRenderer $renderer;
    private SettingsRepository $settings;

    public function __construct()
    {
        $this->powerDns = new PowerDnsService();
        $this->records = new PowerDnsRecordBuilder();
        $this->renderer = new EdgeDnsPoolRenderer();
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

    public function desiredRrsets(bool $persistGeneration = false): array
    {
        $zone = $this->cdnZone();
        $ttl = $this->ttl();
        $pool = $this->renderer->pool();
        if ($persistGeneration) {
            $this->persistGeneration($pool);
        }
        $rrsets = [
            $this->desired('@', 'NS', $ttl, $this->records->nameservers(), 'platform_nameservers'),
        ];
        foreach ($this->renderer->edgeSelectionRrsets() as $edgeRrset) {
            $type = (string) $edgeRrset['dns_type'];
            $source = (string) $edgeRrset['mode'] === 'static_anycast'
                ? 'shared_proxy_static_anycast:' . $type
                : 'shared_proxy:' . $type;
            $rrsets[] = $this->desired(
                $this->proxyLabel(),
                (string) $edgeRrset['rrset_type'],
                $ttl,
                (array) $edgeRrset['records'],
                $source
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
        $pool = $this->renderer->pool();
        return [
            'cdn_zone' => $this->cdnZone(),
            'proxy_host' => $this->proxyHost(),
            'static_anycast' => $this->renderer->staticAnycastIps(),
            'active_edge_nodes' => $pool['nodes'],
            'generated_edge_hostnames' => [$this->proxyHost() . '.'],
            'customer_records' => [],
            'invalid' => $pool['warnings'],
        ];
    }

    public function status(): array
    {
        $pool = $this->renderer->pool();
        $stmt = Database::pdo()->prepare(
            'SELECT desired_hash, last_success_at FROM dns_sync_state WHERE zone_name = :zone LIMIT 1'
        );
        $stmt->execute(['zone' => $this->cdnZone() . '.']);
        $state = $stmt->fetch();

        return [
            'cdn_zone' => $this->cdnZone(),
            'proxy_host' => $this->proxyHost(),
            'static_anycast' => $this->renderer->staticAnycastIps(),
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
