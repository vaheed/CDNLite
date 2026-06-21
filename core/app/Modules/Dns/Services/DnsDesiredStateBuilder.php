<?php

namespace App\Modules\Dns\Services;

use App\Support\Database;

class DnsDesiredStateBuilder
{
    public function __construct(
        private DnsPublishingPlanner $planner = new DnsPublishingPlanner(),
        private EdgeDnsService $edgeDns = new EdgeDnsService(),
        private PowerDnsRecordBuilder $records = new PowerDnsRecordBuilder(),
        private EdgeDnsPoolRenderer $edgePool = new EdgeDnsPoolRenderer()
    ) {
    }

    public function build(): array
    {
        $rrsets = $this->edgeDns->desiredRrsets(true);
        $rrsets = array_merge($rrsets, $this->customerZoneAuthorityRrsets());
        $stmt = Database::pdo()->query(
            "SELECT r.*, d.id AS site_id, d.domain
             FROM dns_records r
             JOIN domains d ON d.id = r.domain_id
             WHERE r.status = 'active'
               AND d.status = 'active'
               AND d.nameserver_status = 'verified'
             ORDER BY d.domain, r.name, r.id"
        );
        foreach ($stmt->fetchAll() as $row) {
            $domain = ['id' => (string) $row['site_id'], 'domain' => (string) $row['domain']];
            $record = $this->castRecord((array) $row);
            if ($record['proxied'] === true && $this->isApex((string) $record['name'], (string) $domain['domain'])) {
                foreach ($this->edgePool->luaRecords() as $type => $content) {
                    $rrsets[] = $this->rrset(
                        (string) $domain['domain'],
                        '@',
                        'LUA',
                        (int) $record['ttl'],
                        [$content],
                        'dns_record:' . $record['id'] . ':apex_lua:' . $type
                    );
                }
                continue;
            }
            $plan = $this->planner->plan($domain, $record);
            $contents = array_values(array_map(
                fn (mixed $content): string => $this->normalizeContent(
                    (string) $plan['type'],
                    (string) $content,
                    $record['priority']
                ),
                (array) ($plan['contents'] ?? [$plan['content']])
            ));
            $rrsets[] = $this->rrset(
                (string) $domain['domain'],
                (string) $record['name'],
                (string) $plan['type'],
                (int) $record['ttl'],
                $contents,
                'dns_record:' . $record['id']
            );
        }

        $grouped = [];
        foreach ($rrsets as $rrset) {
            $key = $rrset['zone_name'] . '|' . strtolower($rrset['rrset_name']) . '|' . $rrset['rrset_type'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = $rrset;
                continue;
            }
            $grouped[$key]['records'] = array_values(array_unique(array_merge(
                $grouped[$key]['records'],
                $rrset['records']
            )));
            sort($grouped[$key]['records']);
            $grouped[$key]['desired_hash'] = $this->hash($grouped[$key]);
        }
        ksort($grouped);
        return array_values($grouped);
    }

    private function customerZoneAuthorityRrsets(): array
    {
        $stmt = Database::pdo()->query('SELECT domain FROM domains ORDER BY domain');
        $rrsets = [];
        foreach ($stmt->fetchAll() as $row) {
            $zone = rtrim(strtolower((string) $row['domain']), '.') . '.';
            $rrsets[] = $this->rrset($zone, '@', 'NS', 300, $this->records->nameservers(), 'customer_zone_nameservers');
        }
        return $rrsets;
    }

    public function persist(array $rrsets): int
    {
        $pdo = Database::pdo();
        $hash = hash('sha256', json_encode($rrsets, JSON_UNESCAPED_SLASHES) ?: '[]');
        $generationStmt = $pdo->prepare(
            'INSERT INTO dns_desired_generations (desired_hash, created_at)
             VALUES (:hash, :now)
             ON CONFLICT (desired_hash) DO UPDATE SET desired_hash = EXCLUDED.desired_hash
             RETURNING id'
        );
        $generationStmt->execute(['hash' => $hash, 'now' => time()]);
        $generation = (int) $generationStmt->fetchColumn();
        $insert = $pdo->prepare(
            'INSERT INTO desired_dns_rrsets
             (zone_name, rrset_name, rrset_type, ttl, records_json, owner, source, generation_id, desired_hash, created_at, updated_at)
             VALUES (:zone, :name, :type, :ttl, CAST(:records AS jsonb), :owner, :source, :generation, :hash, :now, :now)
             ON CONFLICT (zone_name, rrset_name, rrset_type, owner) DO UPDATE SET
               ttl = EXCLUDED.ttl,
               records_json = EXCLUDED.records_json,
               source = EXCLUDED.source,
               generation_id = EXCLUDED.generation_id,
               desired_hash = EXCLUDED.desired_hash,
               updated_at = EXCLUDED.updated_at'
        );
        $now = time();
        foreach ($rrsets as $rrset) {
            $insert->execute([
                'zone' => $rrset['zone_name'],
                'name' => $rrset['rrset_name'],
                'type' => $rrset['rrset_type'],
                'ttl' => $rrset['ttl'],
                'records' => json_encode($rrset['records'], JSON_UNESCAPED_SLASHES),
                'owner' => 'cdnlite',
                'source' => $rrset['source'],
                'generation' => $generation,
                'hash' => $rrset['desired_hash'],
                'now' => $now,
            ]);
        }
        return $generation;
    }

    public function prune(int $generationId): void
    {
        Database::pdo()->prepare(
            "DELETE FROM desired_dns_rrsets WHERE owner = 'cdnlite' AND generation_id <> :generation"
        )->execute(['generation' => $generationId]);
    }

    private function rrset(string $zone, string $name, string $type, int $ttl, array $records, string $source): array
    {
        $zone = rtrim(strtolower($zone), '.') . '.';
        $name = trim($name);
        $fqdn = $name === '' || $name === '@'
            ? $zone
            : (str_ends_with($name, '.') ? strtolower($name) : strtolower($name) . '.' . $zone);
        $rrset = [
            'zone_name' => $zone,
            'rrset_name' => $fqdn,
            'rrset_type' => strtoupper($type),
            'ttl' => $ttl,
            'records' => $records,
            'source' => $source,
        ];
        $rrset['desired_hash'] = $this->hash($rrset);
        return $rrset;
    }

    private function hash(array $rrset): string
    {
        unset($rrset['desired_hash']);
        return hash('sha256', json_encode($rrset, JSON_UNESCAPED_SLASHES) ?: '[]');
    }

    private function castRecord(array $row): array
    {
        $row['proxied'] = (bool) $row['proxied'];
        $row['ttl'] = (int) $row['ttl'];
        $row['priority'] = $row['priority'] === null ? null : (int) $row['priority'];
        return $row;
    }

    private function isApex(string $name, string $domain): bool
    {
        $name = strtolower(rtrim(trim($name), '.'));
        $domain = strtolower(rtrim(trim($domain), '.'));
        return $name === '' || $name === '@' || $name === $domain;
    }

    private function normalizeContent(string $type, string $content, ?int $priority): string
    {
        $value = trim($content);
        $recordType = strtoupper($type);
        if ($recordType === 'TXT' && !str_starts_with($value, '"')) {
            return '"' . str_replace('"', '\"', $value) . '"';
        }
        if ($recordType === 'MX') {
            $target = str_ends_with($value, '.') ? strtolower($value) : strtolower($value) . '.';
            return sprintf('%d %s', $priority ?? 0, $target);
        }
        if (in_array($recordType, ['ALIAS', 'CNAME', 'NS', 'PTR'], true) && !str_ends_with($value, '.')) {
            return strtolower($value) . '.';
        }
        return $value;
    }
}
