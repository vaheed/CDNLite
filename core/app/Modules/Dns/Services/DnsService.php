<?php

namespace App\Modules\Dns\Services;

use App\Modules\Domains\Services\DomainService;
use App\Modules\Proxy\Services\OriginHealthService;
use App\Modules\Proxy\Services\TrafficRulesService;
use App\Modules\Settings\Repositories\SettingsRepository;
use App\Support\AuditLog;
use App\Support\Database;
use App\Support\Uuid;
use App\Support\Validator;

class DnsService
{
    private DomainService $domains;
    private CustomerDnsService $customerDns;
    private DnsPublishingPlanner $planner;
    private OriginHealthService $origins;
    private PowerDnsRecordBuilder $records;

    public function __construct()
    {
        $this->domains = new DomainService();
        $this->planner = new DnsPublishingPlanner();
        $this->customerDns = new CustomerDnsService($this->planner);
        $this->origins = new OriginHealthService();
        $this->records = new PowerDnsRecordBuilder();
    }

    public function listByDomain(string $domainId): array
    {
        $domain = $this->domains->find($domainId);
        if ($domain === null) {
            return [];
        }
        $stmt = Database::pdo()->prepare(
            'SELECT r.*, (SELECT COUNT(*) FROM dns_record_geo_routes g WHERE g.dns_record_id = r.id AND g.enabled = true AND g.route_scope <> \'default\') AS geo_routes_count
             FROM dns_records r WHERE r.domain_id = :domain_id ORDER BY r.id ASC'
        );
        $stmt->execute([':domain_id' => $domainId]);
        $records = array_map(function (array $row) use ($domain): array {
            $record = $this->castRow($row);
            $record['effective_status'] = $record['status'] === 'active'
                && ($domain['status'] ?? null) === 'active'
                && ($domain['nameserver_status'] ?? null) === 'verified'
                ? 'active'
                : 'disabled';
            $record['disabled_reason'] = $record['status'] !== 'active'
                ? 'record_disabled'
                : (($domain['nameserver_status'] ?? null) !== 'verified' ? 'nameservers_not_verified' : null);
            return $record;
        }, $stmt->fetchAll());
        return array_merge($this->platformNameserverRecords($domain), $records);
    }

    private function platformNameserverRecords(array $domain): array
    {
        $now = time();
        return array_map(static function (string $nameserver) use ($domain, $now): array {
            return [
                'id' => 'platform-ns:' . rtrim($nameserver, '.'),
                'domain_id' => (string) $domain['id'],
                'type' => 'NS',
                'name' => '@',
                'content' => $nameserver,
                'ttl' => 300,
                'priority' => null,
                'proxied' => false,
                'geo_policy_id' => null,
                'origin_type' => 'NS',
                'origin_content' => $nameserver,
                'public_type' => 'NS',
                'public_content' => $nameserver,
                'origin_host' => null,
                'origin_tls_verify' => 'ignore',
                'origin_scheme' => null,
                'origin_status' => 'dns_only',
                'geo_origins' => [],
                'routing_policy' => 'standard',
                'status' => 'active',
                'effective_status' => 'active',
                'disabled_reason' => null,
                'geo_routes_count' => 0,
                'managed_by' => 'platform_nameservers',
                'readonly' => true,
                'created_at' => (int) ($domain['created_at'] ?? $now),
                'updated_at' => (int) ($domain['updated_at'] ?? $now),
            ];
        }, $this->records->nameservers());
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
        $this->reconcile($domainId);
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
        $input = $this->normalizeAndValidate($domain, $input);
        $originType = (string) $input['type'];
        $originContent = (string) $input['content'];
        $id = Uuid::v4();
        $record = [
            'id' => $id,
            'type' => $originType,
            'content' => $originContent,
            'name' => (string) $input['name'],
            'proxied' => (bool) ($input['proxied'] ?? false),
            'geo_policy_id' => $input['geo_policy_id'] ?? null,
            'origin_host' => trim((string) ($input['origin_host'] ?? $originContent)),
            'origin_tls_verify' => (string) ($input['origin_tls_verify'] ?? 'ignore'),
            'origin_scheme' => (string) ($input['origin_scheme'] ?? 'http'),
            'geo_origins' => $input['geo_origins'] ?? [],
            'routing_policy' => (string) ($input['routing_policy'] ?? 'standard'),
            'managed_by' => $input['managed_by'] ?? null,
        ];
        $this->assertRoutingAvailable($record);
        $public = $this->customerDns->publicRecordFor($domain, $record);
        $this->assertNotDuplicate(
            $domainId,
            null,
            $originType,
            (string) $input['name'],
            $originContent,
            (string) $input['status']
        );
        $this->assertCompatiblePublicRecord(
            $domainId,
            null,
            (string) $input['name'],
            (string) $public['type'],
            (string) $public['content'],
            (bool) $record['proxied'],
            (string) $input['status']
        );

        $now = time();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO dns_records (
                id, domain_id, type, name, content, ttl, priority, proxied, geo_policy_id,
                origin_type, origin_content, public_type, public_content, origin_host, origin_tls_verify,
                origin_scheme, origin_status, geo_origins_json, routing_policy, managed_by,
                status, created_at, updated_at
             )
             VALUES (
                :id, :domain_id, :type, :name, :content, :ttl, :priority, :proxied, :geo_policy_id,
                :origin_type, :origin_content, :public_type, :public_content, :origin_host, :origin_tls_verify,
                :origin_scheme, :origin_status, :geo_origins_json, :routing_policy, :managed_by,
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
            ':origin_type' => $originType,
            ':origin_content' => $originContent,
            ':public_type' => $public['type'],
            ':public_content' => $public['content'],
            ':origin_host' => $record['origin_host'],
            ':origin_tls_verify' => $record['origin_tls_verify'],
            ':origin_scheme' => $record['origin_scheme'],
            ':origin_status' => $record['proxied'] ? 'pending' : 'dns_only',
            ':geo_origins_json' => $this->encodeGeoOrigins($record['geo_origins']),
            ':routing_policy' => $record['routing_policy'],
            ':managed_by' => $record['managed_by'],
            ':status' => 'active',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $created = $this->find($domainId, $id);
        if ($created === null) {
            throw new \RuntimeException('dns_record_create_failed');
        }
        $this->origins->syncFromDnsRecord($domainId, $created);
        $this->reconcile($domainId);
        $this->ensureManagedSslForProxiedRecord($domainId, $created);
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
        $input = $this->normalizeAndValidate($domain, $input, $oldRecord);

        $patch = [
            'type' => (string) $oldRecord['type'],
            'name' => (string) $oldRecord['name'],
            'content' => (string) $oldRecord['content'],
            'ttl' => (int) $oldRecord['ttl'],
            'priority' => $oldRecord['priority'],
            'proxied' => (bool) $oldRecord['proxied'],
            'geo_policy_id' => $oldRecord['geo_policy_id'],
            'status' => (string) $oldRecord['status'],
            'origin_host' => $oldRecord['origin_host'],
            'origin_tls_verify' => $oldRecord['origin_tls_verify'],
            'origin_scheme' => $oldRecord['origin_scheme'],
            'geo_origins' => $oldRecord['geo_origins'],
            'routing_policy' => $oldRecord['routing_policy'],
        ];

        foreach (['type', 'name', 'content', 'status', 'geo_policy_id', 'origin_host', 'origin_tls_verify', 'origin_scheme', 'routing_policy'] as $field) {
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
            'id' => $recordId,
            'routing_policy' => $patch['routing_policy'],
        ];
        $this->assertRoutingAvailable($recordForProjection);
        $public = $this->customerDns->publicRecordFor($domain, $recordForProjection);
        $this->assertNotDuplicate(
            $domainId,
            $recordId,
            (string) $recordForProjection['type'],
            (string) $recordForProjection['name'],
            (string) $recordForProjection['content'],
            (string) $patch['status']
        );
        $this->assertCompatiblePublicRecord(
            $domainId,
            $recordId,
            (string) $recordForProjection['name'],
            (string) $public['type'],
            (string) $public['content'],
            (bool) $recordForProjection['proxied'],
            (string) $patch['status']
        );

        $stmt = Database::pdo()->prepare(
            'UPDATE dns_records SET
                type = :type,
                name = :name,
                content = :content,
                ttl = :ttl,
                priority = :priority,
                proxied = :proxied,
                geo_policy_id = :geo_policy_id,
                origin_type = :origin_type,
                origin_content = :origin_content,
                public_type = :public_type,
                public_content = :public_content,
                origin_host = :origin_host,
                origin_tls_verify = :origin_tls_verify,
                origin_scheme = :origin_scheme,
                origin_status = :origin_status,
                geo_origins_json = :geo_origins_json,
                routing_policy = :routing_policy,
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
            ':origin_type' => strtoupper((string) $patch['type']),
            ':origin_content' => (string) $patch['content'],
            ':public_type' => $public['type'],
            ':public_content' => $public['content'],
            ':origin_host' => trim((string) $patch['origin_host']),
            ':origin_tls_verify' => (string) $patch['origin_tls_verify'],
            ':origin_scheme' => (string) $patch['origin_scheme'],
            ':origin_status' => $patch['proxied'] ? 'pending' : 'dns_only',
            ':geo_origins_json' => $this->encodeGeoOrigins($patch['geo_origins']),
            ':routing_policy' => (string) $patch['routing_policy'],
            ':status' => (string) $patch['status'],
            ':updated_at' => time(),
        ]);

        $updated = $this->find($domainId, $recordId);
        if ($updated === null) {
            return null;
        }
        if (!empty($updated['proxied'])) {
            Database::pdo()->prepare('DELETE FROM dns_record_geo_routes WHERE dns_record_id = :id')
                ->execute(['id' => $recordId]);
            $updated['geo_routes_count'] = 0;
        }
        $this->origins->syncFromDnsRecord($domainId, $updated);
        $this->reconcile($domainId);
        $this->ensureManagedSslForProxiedRecord($domainId, $updated);
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
            $this->origins->deleteForDnsRecord($domainId, $recordId);
            $this->reconcile($domainId);
        }
        return $deleted;
    }

    public function reconcileRecord(string $domainId, string $recordId): ?array
    {
        $domain = $this->domains->find($domainId);
        if ($domain === null) {
            return null;
        }

        $record = $this->find($domainId, $recordId);
        if ($record === null) {
            return null;
        }

        $this->invalidateConfigSnapshot();
        AuditLog::write('dns.record.reconcile', 'dns_record', $recordId, $domainId, [
            'status' => $record['status'],
            'origin_status' => $record['origin_status'],
        ], [
            'status' => $record['status'],
            'origin_status' => $record['origin_status'],
            'reconcile_requested' => true,
        ], 'system');

        $this->reconcile($domainId);

        return [
            'record' => $this->find($domainId, $recordId),
            'reconciled' => true,
        ];
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
        $stmt = Database::pdo()->prepare(
            'SELECT r.*, (SELECT COUNT(*) FROM dns_record_geo_routes g WHERE g.dns_record_id = r.id AND g.enabled = true AND g.route_scope <> \'default\') AS geo_routes_count
             FROM dns_records r WHERE r.domain_id = :domain_id AND r.id = :id LIMIT 1'
        );
        $stmt->execute([':domain_id' => $domainId, ':id' => $recordId]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->castRow((array) $row);
    }

    private function reconcile(?string $domainId = null): void
    {
        $this->invalidateConfigSnapshot();
        // Reconcile can lose a race with a brief PowerDNS restart or another
        // in-flight sync. Keep the retry window tiny so user writes stay fast,
        // but give transient failures one more chance to clear before we fail
        // a strict deployment.
        $result = ['ok' => false, 'error' => 'powerdns_reconcile_failed'];
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $result = (new DnsReconciler())->reconcile();
            if (($result['ok'] ?? false) === true) {
                break;
            }
            $error = (string) ($result['error'] ?? 'powerdns_reconcile_failed');
            if (!in_array($error, ['powerdns_reconcile_partial_failure', 'dns_reconciler_busy'], true) || $attempt === 3) {
                break;
            }
            usleep(250000 * $attempt);
        }
        if (($result['ok'] ?? false) !== true) {
            AuditLog::write('dns.reconcile.failed', 'dns', 'powerdns', $domainId, null, [
                'error' => (string) ($result['error'] ?? 'powerdns_reconcile_failed'),
                'local_state_saved' => true,
                'strict' => (new PowerDnsService())->isStrict(),
            ], 'system');
            if ((new PowerDnsService())->isStrict()) {
                throw new \RuntimeException((string) ($result['error'] ?? 'powerdns_reconcile_failed'));
            }
        }
    }

    private function ensureManagedSslForProxiedRecord(string $domainId, array $record): void
    {
        if (empty($record['proxied']) || (string) ($record['status'] ?? 'active') !== 'active') {
            return;
        }

        try {
            (new TrafficRulesService())->ensureManagedWildcardSslJob($domainId);
        } catch (\Throwable $e) {
            AuditLog::write('ssl.auto_request_failed', 'ssl', null, $domainId, null, [
                'domain_id' => $domainId,
                'dns_record_id' => $record['id'] ?? null,
                'error' => $e->getMessage(),
                'created_at' => time(),
            ], 'system');
        }
    }

    private function invalidateConfigSnapshot(): void
    {
        Database::pdo()->exec('UPDATE config_state SET active_snapshot_version = NULL WHERE id = 1');
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
        $row['origin_host'] = $row['origin_host'] === null ? null : (string) $row['origin_host'];
        $row['origin_tls_verify'] = (string) ($row['origin_tls_verify'] ?? 'ignore');
        $row['origin_scheme'] = $row['origin_scheme'] === null ? null : (string) $row['origin_scheme'];
        $row['origin_status'] = (string) ($row['origin_status'] ?? 'pending');
        $row['geo_origins'] = $this->decodeGeoOrigins($row['geo_origins_json'] ?? null);
        $row['routing_policy'] = (string) ($row['routing_policy'] ?? 'standard');
        $row['managed_by'] = $row['managed_by'] === null ? null : (string) $row['managed_by'];
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
    }

    private function assertNotDuplicate(
        string $domainId,
        ?string $recordId,
        string $type,
        string $name,
        string $content,
        string $status
    ): void {
        if ($status !== 'active') {
            return;
        }
        $sql = 'SELECT 1 FROM dns_records
                WHERE domain_id = :domain_id
                  AND status = \'active\'
                  AND UPPER(type) = :type
                  AND LOWER(name) = :name
                  AND content = :content';
        $params = [
            ':domain_id' => $domainId,
            ':type' => strtoupper(trim($type)),
            ':name' => strtolower(trim($name)),
            ':content' => trim($content),
        ];
        if ($recordId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $recordId;
        }
        $sql .= ' LIMIT 1';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn() !== false) {
            throw new \RuntimeException('dns_record_duplicate');
        }
    }

    private function assertCompatiblePublicRecord(
        string $domainId,
        ?string $recordId,
        string $name,
        string $publicType,
        string $publicContent,
        bool $proxied,
        string $status
    ): void {
        if ($status !== 'active') {
            return;
        }
        $sql = 'SELECT public_type, public_content, proxied FROM dns_records
                WHERE domain_id = :domain_id AND status = \'active\' AND LOWER(name) = :name';
        $params = [':domain_id' => $domainId, ':name' => strtolower(trim($name))];
        if ($recordId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $recordId;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $newType = strtoupper($publicType);
        foreach ($stmt->fetchAll() as $row) {
            $existingType = strtoupper((string) $row['public_type']);
            $existingContent = (string) ($row['public_content'] ?? '');
            $existingProxied = in_array($row['proxied'], [true, 1, '1', 't', 'true'], true);
            if ($proxied && $existingProxied && $newType === $existingType
                && trim($publicContent) === trim($existingContent)
                && in_array($newType, ['ALIAS', 'CNAME'], true)) {
                continue;
            }
            if ($newType === 'CNAME' || $existingType === 'CNAME'
                || ($newType === 'ALIAS' && $existingType === 'ALIAS')) {
                throw new \RuntimeException('dns_record_name_conflict');
            }
        }
    }

    private function normalizeAndValidate(array $domain, array $input, ?array $current = null): array
    {
        $next = array_merge($current ?? [], $input);
        $type = Validator::dnsRecordType((string) ($next['type'] ?? ''));
        $name = Validator::dnsRecordName((string) ($next['name'] ?? ''), (string) $domain['domain']);
        $proxied = (bool) ($next['proxied'] ?? false);
        $content = $proxied && in_array((string) ($type['value'] ?? ''), ['A', 'AAAA'], true)
            ? Validator::originHost((string) ($next['content'] ?? ''), 'content')
            : Validator::dnsRecordContent((string) ($type['value'] ?? ''), (string) ($next['content'] ?? ''));
        foreach ([$type, $name, $content] as $result) {
            if (($result['ok'] ?? false) !== true) {
                throw new \RuntimeException('invalid_dns_record_' . (string) ($result['field'] ?? 'input'));
            }
        }
        $next['type'] = $type['value'];
        $next['name'] = $name['value'];
        $next['content'] = $content['value'];
        $next['ttl'] = (int) ($next['ttl'] ?? 300);
        if ($next['ttl'] < 60 || $next['ttl'] > 86400) {
            throw new \RuntimeException('invalid_dns_record_ttl');
        }
        $next['priority'] = $next['type'] === 'MX' ? (int) ($next['priority'] ?? 0) : null;
        if ($next['priority'] !== null && ($next['priority'] < 0 || $next['priority'] > 65535)) {
            throw new \RuntimeException('invalid_dns_record_priority');
        }
        $next['status'] = (string) ($next['status'] ?? 'active');
        if (!in_array($next['status'], ['active', 'disabled'], true)) {
            throw new \RuntimeException('invalid_dns_record_status');
        }
        if (!array_key_exists('origin_tls_verify', $next) || $next['origin_tls_verify'] === '') {
            $next['origin_tls_verify'] = 'ignore';
        }
        return $next;
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
