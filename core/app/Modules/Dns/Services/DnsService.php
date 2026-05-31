<?php

namespace App\Modules\Dns\Services;

use App\Modules\Sites\Services\SiteService;
use App\Support\Database;
use App\Support\Logger;
use App\Support\Uuid;

class DnsService
{
    private PowerDnsService $powerDns;
    private SiteService $sites;
    private CustomerDnsService $customerDns;

    public function __construct()
    {
        $this->powerDns = new PowerDnsService();
        $this->sites = new SiteService();
        $this->customerDns = new CustomerDnsService();
    }

    public function listBySite(string $siteId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM dns_records WHERE site_id = :site_id ORDER BY id ASC');
        $stmt->execute([':site_id' => $siteId]);
        return array_map([$this, 'castRow'], $stmt->fetchAll());
    }

    public function create(string $siteId, array $input): array
    {
        $site = $this->sites->find($siteId);
        if ($site === null) {
            throw new \RuntimeException('site_not_found');
        }

        $originType = strtoupper((string) $input['type']);
        $originContent = (string) $input['content'];
        $record = [
            'type' => $originType,
            'content' => $originContent,
            'name' => (string) $input['name'],
            'proxied' => (bool) ($input['proxied'] ?? false),
            'geo_policy_id' => $input['geo_policy_id'] ?? null,
            'edge_target' => $input['edge_target'] ?? null,
        ];
        $public = $this->customerDns->publicRecordFor($site, $record);

        $now = time();
        $id = Uuid::v4();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO dns_records (
                id, site_id, type, name, content, ttl, priority, proxied, geo_policy_id, edge_target,
                origin_type, origin_content, public_type, public_content, status, created_at, updated_at
             )
             VALUES (
                :id, :site_id, :type, :name, :content, :ttl, :priority, :proxied, :geo_policy_id, :edge_target,
                :origin_type, :origin_content, :public_type, :public_content, :status, :created_at, :updated_at
             )'
        );
        $stmt->execute([
            ':id' => $id,
            ':site_id' => $siteId,
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
            ':status' => 'active',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $created = $this->find($siteId, $id);
        if ($created === null) {
            throw new \RuntimeException('dns_record_create_failed');
        }
        $this->syncPowerDnsCreate($site, $created);
        return $created;
    }

    public function update(string $siteId, string $recordId, array $input): ?array
    {
        $site = $this->sites->find($siteId);
        if ($site === null) {
            return null;
        }

        $oldRecord = $this->find($siteId, $recordId);
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
        ];

        foreach (['type', 'name', 'content', 'status', 'geo_policy_id', 'edge_target'] as $field) {
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

        $recordForProjection = [
            'type' => strtoupper((string) $patch['type']),
            'content' => (string) $patch['content'],
            'name' => (string) $patch['name'],
            'proxied' => (bool) $patch['proxied'],
            'geo_policy_id' => $patch['geo_policy_id'],
            'edge_target' => $patch['edge_target'],
        ];
        $public = $this->customerDns->publicRecordFor($site, $recordForProjection);

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
                status = :status,
                updated_at = :updated_at
             WHERE site_id = :site_id AND id = :id'
        );
        $stmt->execute([
            ':site_id' => $siteId,
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
            ':status' => (string) $patch['status'],
            ':updated_at' => time(),
        ]);

        $updated = $this->find($siteId, $recordId);
        if ($updated === null) {
            return null;
        }
        if ($this->publicIdentity($oldRecord) !== $this->publicIdentity($updated)) {
            $this->syncPowerDnsDelete($site, $oldRecord);
            $this->syncReplacementForIdentity($site, $oldRecord);
        }
        $this->syncPowerDnsCreate($site, $updated);
        return $updated;
    }

    public function delete(string $siteId, string $recordId): bool
    {
        $site = $this->sites->find($siteId);
        if ($site === null) {
            return false;
        }

        $record = $this->find($siteId, $recordId);
        if ($record === null) {
            return false;
        }

        $stmt = Database::pdo()->prepare('DELETE FROM dns_records WHERE site_id = :site_id AND id = :id');
        $stmt->execute([':site_id' => $siteId, ':id' => $recordId]);
        $deleted = $stmt->rowCount() > 0;
        if ($deleted && !$this->syncReplacementForIdentity($site, $record)) {
            $this->syncPowerDnsDelete($site, $record);
        }
        return $deleted;
    }

    public function rebuildCustomerZones(): array
    {
        $stmt = Database::pdo()->query(
            'SELECT d.*, s.domain, s.id AS site_row_id FROM dns_records d JOIN sites s ON s.id = d.site_id ORDER BY d.id ASC'
        );
        $count = 0;
        foreach ($stmt->fetchAll() as $row) {
            $site = ['id' => (string) $row['site_id'], 'domain' => (string) $row['domain']];
            $record = $this->castRow((array) $row);
            $public = $this->customerDns->publicRecordFor($site, $record);
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
            $this->syncPowerDnsCreate($site, $record);
            $count++;
        }
        return ['ok' => true, 'rebuilt' => $count];
    }

    private function syncPowerDnsCreate(array $site, array $record): void
    {
        if (!$this->powerDns->isEnabled()) {
            return;
        }

        $type = (string) ($record['public_type'] ?: $record['type']);
        $content = (string) ($record['public_content'] ?: $record['content']);
        $result = $this->powerDns->syncReplace(
            (string) $site['domain'],
            (string) $record['name'],
            $type,
            (int) $record['ttl'],
            $content
        );

        if (($result['ok'] ?? false) !== true) {
            $this->handlePowerDnsFailure('powerdns_sync_replace_failed', $site, $record, $result);
        }
    }

    private function syncPowerDnsDelete(array $site, array $record): void
    {
        if (!$this->powerDns->isEnabled()) {
            return;
        }

        $result = $this->powerDns->syncDelete(
            (string) $site['domain'],
            (string) $record['name'],
            (string) ($record['public_type'] ?: $record['type'])
        );

        if (($result['ok'] ?? false) !== true) {
            $this->handlePowerDnsFailure('powerdns_sync_delete_failed', $site, $record, $result);
        }
    }

    private function handlePowerDnsFailure(string $event, array $site, array $record, array $result): void
    {
        Logger::error($event, [
            'site_id' => (string) $site['id'],
            'domain' => (string) $site['domain'],
            'record_name' => (string) $record['name'],
            'record_type' => (string) ($record['public_type'] ?: $record['type']),
            'status' => (int) ($result['status'] ?? 0),
            'error' => (string) ($result['error'] ?? 'powerdns_sync_failed'),
            'response' => (string) ($result['response'] ?? ''),
        ]);
        if ($this->powerDns->isStrict()) {
            throw new \RuntimeException((string) ($result['error'] ?? 'powerdns_sync_failed'));
        }
    }

    private function find(string $siteId, string $recordId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM dns_records WHERE site_id = :site_id AND id = :id LIMIT 1');
        $stmt->execute([':site_id' => $siteId, ':id' => $recordId]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->castRow((array) $row);
    }

    private function syncReplacementForIdentity(array $site, array $record): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM dns_records
             WHERE site_id = :site_id AND lower(name) = lower(:name) AND upper(COALESCE(public_type, type)) = upper(:public_type)
             ORDER BY updated_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute([
            ':site_id' => (string) $record['site_id'],
            ':name' => (string) $record['name'],
            ':public_type' => (string) ($record['public_type'] ?: $record['type']),
        ]);
        $row = $stmt->fetch();
        if ($row === false) {
            return false;
        }

        $this->syncPowerDnsCreate($site, $this->castRow((array) $row));
        return true;
    }

    private function castRow(array $row): array
    {
        $row['id'] = (string) $row['id'];
        $row['site_id'] = (string) $row['site_id'];
        $row['type'] = strtoupper((string) $row['type']);
        $row['content'] = (string) $row['content'];
        $row['origin_type'] = (string) ($row['origin_type'] ?: $row['type']);
        $row['origin_content'] = (string) ($row['origin_content'] ?: $row['content']);
        $row['public_type'] = (string) ($row['public_type'] ?: $row['type']);
        $row['public_content'] = (string) ($row['public_content'] ?: $row['content']);
        $row['geo_policy_id'] = $row['geo_policy_id'] === null ? null : (string) $row['geo_policy_id'];
        $row['edge_target'] = $row['edge_target'] === null ? null : (string) $row['edge_target'];
        $row['ttl'] = (int) $row['ttl'];
        $row['priority'] = $row['priority'] === null ? null : (int) $row['priority'];
        $row['proxied'] = ((int) $row['proxied']) === 1;
        $row['created_at'] = (int) $row['created_at'];
        $row['updated_at'] = (int) $row['updated_at'];
        return $row;
    }

    private function publicIdentity(array $record): string
    {
        return implode('|', [
            strtolower((string) $record['name']),
            strtoupper((string) ($record['public_type'] ?: $record['type'])),
        ]);
    }
}
