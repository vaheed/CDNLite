<?php

namespace App\Modules\Dns\Services;

use App\Modules\Domains\Services\DomainService;
use App\Modules\Settings\Repositories\SettingsRepository;
use App\Support\Database;
use App\Support\Uuid;

class DnsService
{
    private DomainService $domains;
    private CustomerDnsService $customerDns;
    private DnsPublishingPlanner $planner;

    public function __construct()
    {
        $this->domains = new DomainService();
        $this->planner = new DnsPublishingPlanner();
        $this->customerDns = new CustomerDnsService($this->planner);
    }

    public function listByDomain(string $domainId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT r.*, (SELECT COUNT(*) FROM dns_record_geo_routes g WHERE g.dns_record_id = r.id) AS geo_routes_count
             FROM dns_records r WHERE r.domain_id = :domain_id ORDER BY r.id ASC'
        );
        $stmt->execute([':domain_id' => $domainId]);
        return array_map([$this, 'castRow'], $stmt->fetchAll());
    }

    public function routing(string $domainId): ?array
    {
        return $this->planner->settings($domainId);
    }

    public function updateRouting(string $domainId, array $input): ?array
    {
        $settings = $this->planner->updateSettings($domainId, $input);
        if ($settings === null) {
            return null;
        }
        $this->rebuildDomain($domainId);
        $this->reconcile();
        return $settings;
    }

    public function preview(string $domainId, string $recordId, array $input = []): ?array
    {
        $domain = $this->domains->find($domainId);
        $record = $this->find($domainId, $recordId);
        if ($domain === null || $record === null) {
            return null;
        }
        foreach (['type', 'name', 'content', 'proxied'] as $field) {
            if (array_key_exists($field, $input)) {
                $record[$field] = $input[$field];
            }
        }
        return $this->planner->plan($domain, $record);
    }

    public function create(string $domainId, array $input): array
    {
        $domain = $this->domains->find($domainId);
        if ($domain === null) {
            throw new \RuntimeException('domain_not_found');
        }
        $originType = strtoupper((string) $input['type']);
        $originContent = (string) $input['content'];
        $id = Uuid::v4();
        $record = [
            'id' => $id,
            'type' => $originType,
            'content' => $originContent,
            'name' => (string) $input['name'],
            'proxied' => (bool) ($input['proxied'] ?? false),
            'geo_policy_id' => $input['geo_policy_id'] ?? null,
            'edge_target' => $input['edge_target'] ?? null,
            'origin_host' => trim((string) ($input['origin_host'] ?? $originContent)),
            'origin_tls_verify' => (string) ($input['origin_tls_verify'] ?? 'verify'),
            'geo_origins' => $input['geo_origins'] ?? [],
            'routing_policy' => (string) ($input['routing_policy'] ?? 'standard'),
        ];
        $this->assertRoutingAvailable($record);
        $public = $this->customerDns->publicRecordFor($domain, $record);

        $now = time();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO dns_records (
                id, domain_id, type, name, content, ttl, priority, proxied, geo_policy_id, edge_target,
                origin_type, origin_content, public_type, public_content, origin_host, origin_tls_verify,
                origin_scheme, origin_status, geo_origins_json, routing_policy, canonical_edge_hostname,
                status, created_at, updated_at
             )
             VALUES (
                :id, :domain_id, :type, :name, :content, :ttl, :priority, :proxied, :geo_policy_id, :edge_target,
                :origin_type, :origin_content, :public_type, :public_content, :origin_host, :origin_tls_verify,
                NULL, :origin_status, :geo_origins_json, :routing_policy, :canonical_edge_hostname,
                :status, :created_at, :updated_at
             )'
        );
        $stmt->execute([
            ':id' => $id,
            ':domain_id' => $domainId,
            ':type' => $originType,
            ':name' => (string) $input['name'],
            ':content' => $originContent,
            ':ttl' => (int) ($input['ttl'] ?? 300),
            ':priority' => isset($input['priority']) ? (int) $input['priority'] : null,
            ':proxied' => (int) $record['proxied'],
            ':geo_policy_id' => $record['geo_policy_id'],
            ':edge_target' => $record['edge_target'],
            ':origin_type' => $originType,
            ':origin_content' => $originContent,
            ':public_type' => $public['type'],
            ':public_content' => $public['content'],
            ':origin_host' => $record['origin_host'],
            ':origin_tls_verify' => $record['origin_tls_verify'],
            ':origin_status' => $record['proxied'] ? 'pending' : 'dns_only',
            ':geo_origins_json' => $this->encodeGeoOrigins($record['geo_origins']),
            ':routing_policy' => $record['routing_policy'],
            ':canonical_edge_hostname' => $record['proxied'] ? $this->planner->canonicalHostname($id, $domainId) : null,
            ':status' => 'active',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $created = $this->find($domainId, $id);
        if ($created === null) {
            throw new \RuntimeException('dns_record_create_failed');
        }
        $this->reconcile();
        return $created;
    }

    public function update(string $domainId, string $recordId, array $input): ?array
    {
        $domain = $this->domains->find($domainId);
        if ($domain === null) {
            return null;
        }

        $oldRecord = $this->find($domainId, $recordId);
        if ($oldRecord === null) {
            return null;
        }

        $patch = [
            'type' => (string) $oldRecord['type'],
            'name' => (string) $oldRecord['name'],
            'content' => (string) $oldRecord['content'],
            'ttl' => (int) $oldRecord['ttl'],
            'priority' => $oldRecord['priority'],
            'proxied' => (bool) $oldRecord['proxied'],
            'geo_policy_id' => $oldRecord['geo_policy_id'],
            'edge_target' => $oldRecord['edge_target'],
            'status' => (string) $oldRecord['status'],
            'origin_host' => $oldRecord['origin_host'],
            'origin_tls_verify' => $oldRecord['origin_tls_verify'],
            'geo_origins' => $oldRecord['geo_origins'],
            'routing_policy' => $oldRecord['routing_policy'],
        ];

        foreach (['type', 'name', 'content', 'status', 'geo_policy_id', 'edge_target', 'origin_host', 'origin_tls_verify', 'routing_policy'] as $field) {
            if (array_key_exists($field, $input)) {
                $patch[$field] = $input[$field] === null ? null : (string) $input[$field];
            }
        }
        if (isset($input['ttl'])) {
            $patch['ttl'] = (int) $input['ttl'];
        }
        if (array_key_exists('priority', $input)) {
            $patch['priority'] = $input['priority'] === null ? null : (int) $input['priority'];
        }
        if (isset($input['proxied'])) {
            $patch['proxied'] = (bool) $input['proxied'];
        }
        if (array_key_exists('geo_origins', $input)) {
            $patch['geo_origins'] = $input['geo_origins'];
        }

        $recordForProjection = [
            'type' => strtoupper((string) $patch['type']),
            'content' => (string) $patch['content'],
            'name' => (string) $patch['name'],
            'proxied' => (bool) $patch['proxied'],
            'geo_policy_id' => $patch['geo_policy_id'],
            'edge_target' => $patch['edge_target'],
            'id' => $recordId,
            'routing_policy' => $patch['routing_policy'],
        ];
        $this->assertRoutingAvailable($recordForProjection);
        $public = $this->customerDns->publicRecordFor($domain, $recordForProjection);

        $stmt = Database::pdo()->prepare(
            'UPDATE dns_records SET
                type = :type,
                name = :name,
                content = :content,
                ttl = :ttl,
                priority = :priority,
                proxied = :proxied,
                geo_policy_id = :geo_policy_id,
                edge_target = :edge_target,
                origin_type = :origin_type,
                origin_content = :origin_content,
                public_type = :public_type,
                public_content = :public_content,
                origin_host = :origin_host,
                origin_tls_verify = :origin_tls_verify,
                origin_scheme = NULL,
                origin_status = :origin_status,
                geo_origins_json = :geo_origins_json,
                routing_policy = :routing_policy,
                canonical_edge_hostname = :canonical_edge_hostname,
                status = :status,
                updated_at = :updated_at
             WHERE domain_id = :domain_id AND id = :id'
        );
        $stmt->execute([
            ':domain_id' => $domainId,
            ':id' => $recordId,
            ':type' => strtoupper((string) $patch['type']),
            ':name' => (string) $patch['name'],
            ':content' => (string) $patch['content'],
            ':ttl' => (int) $patch['ttl'],
            ':priority' => $patch['priority'],
            ':proxied' => (int) ((bool) $patch['proxied']),
            ':geo_policy_id' => $patch['geo_policy_id'],
            ':edge_target' => $patch['edge_target'],
            ':origin_type' => strtoupper((string) $patch['type']),
            ':origin_content' => (string) $patch['content'],
            ':public_type' => $public['type'],
            ':public_content' => $public['content'],
            ':origin_host' => trim((string) $patch['origin_host']),
            ':origin_tls_verify' => (string) $patch['origin_tls_verify'],
            ':origin_status' => $patch['proxied'] ? 'pending' : 'dns_only',
            ':geo_origins_json' => $this->encodeGeoOrigins($patch['geo_origins']),
            ':routing_policy' => (string) $patch['routing_policy'],
            ':canonical_edge_hostname' => $patch['proxied'] ? $this->planner->canonicalHostname($recordId, $domainId) : null,
            ':status' => (string) $patch['status'],
            ':updated_at' => time(),
        ]);

        $updated = $this->find($domainId, $recordId);
        if ($updated === null) {
            return null;
        }
        $this->reconcile();
        return $updated;
    }

    public function delete(string $domainId, string $recordId): bool
    {
        $domain = $this->domains->find($domainId);
        if ($domain === null) {
            return false;
        }

        $record = $this->find($domainId, $recordId);
        if ($record === null) {
            return false;
        }

        $stmt = Database::pdo()->prepare('DELETE FROM dns_records WHERE domain_id = :domain_id AND id = :id');
        $stmt->execute([':domain_id' => $domainId, ':id' => $recordId]);
        $deleted = $stmt->rowCount() > 0;
        if ($deleted) {
            $this->reconcile();
        }
        return $deleted;
    }

    public function rebuildCustomerZones(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT d.*, s.domain, s.id AS domain_row_id FROM dns_records d JOIN domains s ON s.id = d.domain_id ORDER BY d.id ASC'
        );
        $count = 0;
        foreach ($stmt->fetchAll() as $row) {
            $domain = ['id' => (string) $row['domain_id'], 'domain' => (string) $row['domain']];
            $record = $this->castRow((array) $row);
            $public = $this->customerDns->publicRecordFor($domain, $record);
            $update = Database::pdo()->prepare(
                'UPDATE dns_records SET origin_type = :origin_type, origin_content = :origin_content,
                 public_type = :public_type, public_content = :public_content, updated_at = :updated_at WHERE id = :id'
            );
            $update->execute([
                ':id' => (string) $record['id'],
                ':origin_type' => (string) $record['type'],
                ':origin_content' => (string) $record['content'],
                ':public_type' => $public['type'],
                ':public_content' => $public['content'],
                ':updated_at' => time(),
            ]);
            $record['public_type'] = $public['type'];
            $record['public_content'] = $public['content'];
            $count++;
        }
        $this->reconcile();
        return ['ok' => true, 'rebuilt' => $count];
    }

    public function rebuildDomain(string $domainId): int
    {
        $domain = $this->domains->find($domainId);
        if ($domain === null) {
            return 0;
        }
        $count = 0;
        foreach ($this->listByDomain($domainId) as $record) {
            $public = $this->customerDns->publicRecordFor($domain, $record);
            Database::pdo()->prepare(
                'UPDATE dns_records SET public_type = :public_type, public_content = :public_content,
                 updated_at = :updated_at WHERE id = :id'
            )->execute([
                'id' => $record['id'],
                'public_type' => $public['type'],
                'public_content' => $public['content'],
                'updated_at' => time(),
            ]);
            $record['public_type'] = $public['type'];
            $record['public_content'] = $public['content'];
            $count++;
        }
        return $count;
    }

    public function rebuildGeoDomains(): int
    {
        $stmt = Database::pdo()->query(
            "SELECT DISTINCT domain_id FROM dns_records
             WHERE proxied = true AND status = 'active'
             ORDER BY domain_id"
        );
        $count = 0;
        foreach ($stmt->fetchAll() as $row) {
            $count += $this->rebuildDomain((string) $row['domain_id']);
        }
        return $count;
    }

    public function find(string $domainId, string $recordId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM dns_records WHERE domain_id = :domain_id AND id = :id LIMIT 1');
        $stmt->execute([':domain_id' => $domainId, ':id' => $recordId]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->castRow((array) $row);
    }

    private function reconcile(): void
    {
        $result = (new DnsReconciler())->reconcile();
        if (($result['ok'] ?? false) !== true && (new PowerDnsService())->isStrict()) {
            throw new \RuntimeException((string) ($result['error'] ?? 'powerdns_reconcile_failed'));
        }
    }

    private function castRow(array $row): array
    {
        $row['id'] = (string) $row['id'];
        $row['domain_id'] = (string) $row['domain_id'];
        $row['type'] = strtoupper((string) $row['type']);
        $row['content'] = (string) $row['content'];
        $row['origin_type'] = (string) ($row['origin_type'] ?: $row['type']);
        $row['origin_content'] = (string) ($row['origin_content'] ?: $row['content']);
        $row['public_type'] = (string) ($row['public_type'] ?: $row['type']);
        $row['public_content'] = (string) ($row['public_content'] ?: $row['content']);
        $row['geo_policy_id'] = $row['geo_policy_id'] === null ? null : (string) $row['geo_policy_id'];
        $row['edge_target'] = $row['edge_target'] === null ? null : (string) $row['edge_target'];
        $row['origin_host'] = $row['origin_host'] === null ? null : (string) $row['origin_host'];
        $row['origin_tls_verify'] = (string) ($row['origin_tls_verify'] ?? 'verify');
        $row['origin_scheme'] = $row['origin_scheme'] === null ? null : (string) $row['origin_scheme'];
        $row['origin_status'] = (string) ($row['origin_status'] ?? 'pending');
        $row['geo_origins'] = $this->decodeGeoOrigins($row['geo_origins_json'] ?? null);
        $row['routing_policy'] = (string) ($row['routing_policy'] ?? 'standard');
        $row['canonical_edge_hostname'] = $row['canonical_edge_hostname'] === null ? null : (string) $row['canonical_edge_hostname'];
        unset($row['geo_origins_json']);
        $row['ttl'] = (int) $row['ttl'];
        $row['priority'] = $row['priority'] === null ? null : (int) $row['priority'];
        $row['proxied'] = ((int) $row['proxied']) === 1;
        $row['created_at'] = (int) $row['created_at'];
        $row['updated_at'] = (int) $row['updated_at'];
        $row['geo_routes_count'] = isset($row['geo_routes_count']) ? (int) $row['geo_routes_count'] : 0;
        return $row;
    }

    private function assertRoutingAvailable(array $record): void
    {
        $policy = (string) ($record['routing_policy'] ?? 'standard');
        if (in_array($policy, ['anycast', 'geo_anycast'], true) && empty($record['proxied'])) {
            throw new \RuntimeException('anycast_requires_proxied_record');
        }
        if (!in_array($policy, ['anycast', 'geo_anycast'], true)) {
            return;
        }
        $settings = new SettingsRepository();
        foreach (['anycast_ipv4_1', 'anycast_ipv4_2', 'anycast_ipv6_1', 'anycast_ipv6_2'] as $field) {
            if (trim((string) $settings->value('platform.edge_dns', $field)) === '') {
                throw new \RuntimeException('global_anycast_not_configured');
            }
        }
    }

    private function decodeGeoOrigins(?string $json): array
    {
        $decoded = $json === null ? null : json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function encodeGeoOrigins(mixed $value): ?string
    {
        if (!is_array($value) || $value === []) {
            return null;
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }
}
