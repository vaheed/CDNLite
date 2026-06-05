<?php

namespace App\Modules\Dns\Services;

use App\Support\Database;
use App\Support\Logger;

class EdgeDnsService
{
    private PowerDnsService $powerDns;
    private PowerDnsRecordBuilder $records;
    private EdgeHealthRecordBuilder $health;

    public function __construct()
    {
        $this->powerDns = new PowerDnsService();
        $this->records = new PowerDnsRecordBuilder();
        $this->health = new EdgeHealthRecordBuilder();
    }

    public function bootstrap(): array
    {
        $result = $this->ensureBaseZone();
        if (($result['ok'] ?? false) !== true) {
            return $result;
        }

        $this->syncBootstrapRecords();
        return $this->sync(true);
    }

    public function sync(bool $force = false): array
    {
        $pool = $this->activeEdgePool();
        $rrsets = $this->buildEdgeRecords($pool);
        $hash = hash('sha256', json_encode($rrsets, JSON_UNESCAPED_SLASHES) ?: '[]');
        if (!$force && $this->lastHash() === $hash) {
            return ['ok' => true, 'skipped' => true, 'edge_records' => count($rrsets), 'effective_hash' => $hash];
        }

        if (!$this->powerDns->isEnabled()) {
            return ['ok' => true, 'powerdns_enabled' => false, 'edge_records' => count($rrsets), 'effective_hash' => $hash];
        }

        $zoneResult = $this->ensureBaseZone();
        if (($zoneResult['ok'] ?? false) !== true) {
            return $zoneResult;
        }

        foreach ($rrsets as $rrset) {
            $result = $this->powerDns->syncReplace(
                $this->baseDomain(),
                (string) $rrset['name'],
                (string) $rrset['type'],
                (int) $rrset['ttl'],
                (string) $rrset['content']
            );
            if (($result['ok'] ?? false) !== true) {
                Logger::error('edge_dns_sync_failed', ['rrset' => $rrset, 'result' => $result]);
                if ($this->powerDns->isStrict()) {
                    throw new \RuntimeException((string) ($result['error'] ?? 'edge_dns_sync_failed'));
                }
                return $result;
            }
        }

        $this->saveHash($hash);
        return ['ok' => true, 'edge_records' => count($rrsets), 'effective_hash' => $hash];
    }

    public function validate(): array
    {
        $pool = $this->activeEdgePool();
        $records = $this->buildEdgeRecords($pool);
        $customer = [];
        $stmt = Database::pdo()->query(
            'SELECT d.*, s.domain FROM dns_records d JOIN domains s ON s.id = d.domain_id ORDER BY s.domain ASC, d.name ASC'
        );
        $projection = new CustomerDnsService();
        foreach ($stmt->fetchAll() as $row) {
            $domain = ['domain' => (string) $row['domain']];
            $record = $this->castDnsRecord((array) $row);
            $public = $projection->publicRecordFor($domain, $record);
            $customer[] = [
                'domain' => (string) $row['domain'],
                'name' => (string) $record['name'],
                'proxied' => (bool) $record['proxied'],
                'public_type' => $public['type'],
                'public_content' => $public['content'],
            ];
        }

        return [
            'edge_base_domain' => $this->baseDomain(),
            'edge_zone_prefix' => $this->zonePrefix(),
            'active_edge_nodes' => $pool['nodes'],
            'generated_edge_hostnames' => array_values(array_unique(array_map(
                static fn(array $r): string => (string) $r['fqdn'],
                $records
            ))),
            'customer_records' => $customer,
            'invalid' => $pool['warnings'],
        ];
    }

    public function ensureBaseZone(): array
    {
        if (!$this->powerDns->isEnabled()) {
            return ['ok' => true, 'powerdns_enabled' => false];
        }
        return $this->powerDns->ensureZone($this->baseDomain());
    }

    private function syncBootstrapRecords(): void
    {
        if (!$this->powerDns->isEnabled()) {
            return;
        }

        $ttl = $this->ttl();
        $base = $this->baseDomain();
        $this->powerDns->syncReplace($base, '@', 'SOA', $ttl, $this->records->soa($base));
        $this->powerDns->syncReplaceMany($base, '@', 'NS', $ttl, $this->records->nameservers());

        foreach (['CDNLITE_NS1_IP' => 'ns1', 'CDNLITE_NS2_IP' => 'ns2'] as $env => $name) {
            $ip = trim((string) (getenv($env) ?: ''));
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $this->powerDns->syncReplace($base, $name, 'A', $ttl, $ip);
            }
        }
    }

    private function buildEdgeRecords(array $pool): array
    {
        $records = [];
        $prefix = $this->zonePrefix();
        $groups = [$prefix => $pool['all'], 'geo.' . $prefix => $pool['all']];
        foreach ($pool['regions'] as $region => $ips) {
            $groups[$region . '.' . $prefix] = $ips;
        }
        foreach (['ir', 'eu', 'us'] as $region) {
            $groups[$region . '.' . $prefix] ??= $pool['regions'][$region] ?? [];
        }

        foreach ($groups as $name => $ipsByType) {
            foreach (['A' => 'ipv4', 'AAAA' => 'ipv6'] as $type => $key) {
                $content = $this->health->luaRecord($type, $ipsByType[$key] ?? []);
                if ($content === null) {
                    Logger::warn('edge_dns_empty_pool', ['name' => $name, 'type' => $type]);
                    continue;
                }
                $records[] = [
                    'name' => $name,
                    'fqdn' => $this->records->hostname($name, $this->baseDomain()),
                    'type' => 'LUA',
                    'ttl' => $this->ttl(),
                    'content' => $content,
                ];
            }
        }

        usort($records, static fn(array $a, array $b): int => strcmp($a['fqdn'] . $a['type'] . $a['content'], $b['fqdn'] . $b['type'] . $b['content']));
        return $records;
    }

    private function activeEdgePool(): array
    {
        $stmt = Database::pdo()->query(
            "SELECT * FROM edge_nodes
             WHERE status = 'online' AND is_enabled = true
             ORDER BY region ASC, public_ipv4 ASC, public_ipv6 ASC, public_ip ASC"
        );

        $nodes = [];
        $warnings = [];
        $all = ['ipv4' => [], 'ipv6' => []];
        $regions = [];
        foreach ($stmt->fetchAll() as $row) {
            $ipv4 = trim((string) ($row['public_ipv4'] ?: $row['public_ip'] ?? ''));
            $ipv6 = trim((string) ($row['public_ipv6'] ?? ''));
            $region = $this->normalizeRegion((string) ($row['region'] ?? ''));
            $country = $this->normalizeCode((string) ($row['country'] ?? ''));
            $continent = $this->normalizeCode((string) ($row['continent'] ?? ''));
            $valid4 = $ipv4 !== '' && filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $valid6 = $ipv6 !== '' && filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            if (!$valid4 && !$valid6) {
                $warnings[] = ['edge_id' => (string) $row['edge_id'], 'error' => 'no_valid_public_ip'];
                continue;
            }

            $regions[$region] ??= ['ipv4' => [], 'ipv6' => []];
            if ($valid4) {
                $all['ipv4'][$ipv4] = $ipv4;
                $regions[$region]['ipv4'][$ipv4] = $ipv4;
            }
            if ($valid6) {
                $all['ipv6'][$ipv6] = $ipv6;
                $regions[$region]['ipv6'][$ipv6] = $ipv6;
            }
            $nodes[] = [
                'edge_id' => (string) $row['edge_id'],
                'hostname' => (string) $row['hostname'],
                'public_ipv4' => $valid4 ? $ipv4 : '',
                'public_ipv6' => $valid6 ? $ipv6 : '',
                'region' => $region,
                'country' => $country,
                'continent' => $continent,
                'health_status' => (string) ($row['health_status'] ?? 'unknown'),
            ];
        }

        foreach ($regions as $region => $ips) {
            $regions[$region] = ['ipv4' => array_values($ips['ipv4']), 'ipv6' => array_values($ips['ipv6'])];
        }
        return [
            'nodes' => $nodes,
            'warnings' => $warnings,
            'all' => ['ipv4' => array_values($all['ipv4']), 'ipv6' => array_values($all['ipv6'])],
            'regions' => $regions,
        ];
    }

    private function castDnsRecord(array $row): array
    {
        $row['proxied'] = ((int) $row['proxied']) === 1;
        return $row;
    }

    private function normalizeRegion(string $region): string
    {
        $region = strtolower(trim($region));
        $region = preg_replace('/[^a-z0-9-]/', '-', $region) ?? '';
        $region = trim($region, '-');
        return $region === '' ? 'global' : $region;
    }

    private function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : '';
    }

    private function lastHash(): ?string
    {
        $stmt = Database::pdo()->query('SELECT effective_hash FROM edge_dns_state WHERE id = 1 LIMIT 1');
        $value = $stmt->fetchColumn();
        return is_string($value) ? $value : null;
    }

    private function saveHash(string $hash): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO edge_dns_state (id, effective_hash, synced_at)
             VALUES (1, :hash, :synced_at)
             ON CONFLICT(id) DO UPDATE SET effective_hash = excluded.effective_hash, synced_at = excluded.synced_at'
        );
        $stmt->execute([':hash' => $hash, ':synced_at' => time()]);
    }

    private function baseDomain(): string
    {
        return rtrim(strtolower((string) (getenv('CDNLITE_EDGE_BASE_DOMAIN') ?: 'vaheed.net')), '.');
    }

    private function zonePrefix(): string
    {
        return strtolower((string) (getenv('CDNLITE_EDGE_ZONE_PREFIX') ?: 'edge'));
    }

    private function ttl(): int
    {
        $ttl = (int) (getenv('CDNLITE_EDGE_TTL') ?: 60);
        return $ttl > 0 ? $ttl : 60;
    }
}
