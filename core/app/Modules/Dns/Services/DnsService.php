<?php

namespace App\Modules\Dns\Services;

use App\Support\Database;
use App\Modules\Sites\Services\SiteService;
use App\Support\Logger;
use App\Support\Uuid;

class DnsService
{
    private PowerDnsService $powerDns;
    private SiteService $sites;

    public function __construct()
    {
        $this->powerDns = new PowerDnsService();
        $this->sites = new SiteService();
    }

    public function listBySite(string $siteId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM dns_records WHERE site_id = :site_id ORDER BY id ASC');
        $stmt->execute([':site_id' => $siteId]);
        $rows = $stmt->fetchAll();
        return array_map([$this, 'castRow'], $rows);
    }

    public function create(string $siteId, array $input): array
    {
        $site = $this->sites->find($siteId);
        if ($site === null) {
            throw new \RuntimeException('site_not_found');
        }

        $now = time();
        $id = Uuid::v4();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO dns_records (id, site_id, type, name, content, ttl, priority, proxied, status, created_at, updated_at)
             VALUES (:id, :site_id, :type, :name, :content, :ttl, :priority, :proxied, :status, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':id' => $id,
            ':site_id' => $siteId,
            ':type' => strtoupper((string) $input['type']),
            ':name' => (string) $input['name'],
            ':content' => (string) $input['content'],
            ':ttl' => (int) ($input['ttl'] ?? 300),
            ':priority' => isset($input['priority']) ? (int) $input['priority'] : null,
            ':proxied' => (int) ((bool) ($input['proxied'] ?? false)),
            ':status' => 'active',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $stmt = Database::pdo()->prepare('SELECT * FROM dns_records WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $created = $this->castRow((array) $stmt->fetch());
        $this->syncPowerDnsCreate($site, $created);
        return $created;
    }

    public function update(string $siteId, string $recordId, array $input): ?array
    {
        $site = $this->sites->find($siteId);
        if ($site === null) {
            return null;
        }

        $find = Database::pdo()->prepare('SELECT * FROM dns_records WHERE site_id = :site_id AND id = :id LIMIT 1');
        $find->execute([':site_id' => $siteId, ':id' => $recordId]);
        $existing = $find->fetch();
        if ($existing === false) {
            return null;
        }

        $oldRecord = $this->castRow((array) $existing);
        $patch = [
            'type' => (string) $oldRecord['type'],
            'name' => (string) $oldRecord['name'],
            'content' => (string) $oldRecord['content'],
            'ttl' => (int) $oldRecord['ttl'],
            'priority' => $oldRecord['priority'],
            'proxied' => (bool) $oldRecord['proxied'],
            'status' => (string) $oldRecord['status'],
        ];

        foreach (['type', 'name', 'content', 'status'] as $field) {
            if (isset($input[$field])) {
                $patch[$field] = (string) $input[$field];
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

        $stmt = Database::pdo()->prepare(
            'UPDATE dns_records SET
                type = :type,
                name = :name,
                content = :content,
                ttl = :ttl,
                priority = :priority,
                proxied = :proxied,
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
            ':status' => (string) $patch['status'],
            ':updated_at' => time(),
        ]);

        $find->execute([':site_id' => $siteId, ':id' => $recordId]);
        $updated = $this->castRow((array) $find->fetch());
        if (
            strtoupper((string) $oldRecord['type']) !== strtoupper((string) $updated['type'])
            || (string) $oldRecord['name'] !== (string) $updated['name']
        ) {
            $this->syncPowerDnsDelete($site, $oldRecord);
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

        $find = Database::pdo()->prepare('SELECT * FROM dns_records WHERE site_id = :site_id AND id = :id LIMIT 1');
        $find->execute([':site_id' => $siteId, ':id' => $recordId]);
        $record = $find->fetch();
        if ($record === false) {
            return false;
        }

        $stmt = Database::pdo()->prepare('DELETE FROM dns_records WHERE site_id = :site_id AND id = :id');
        $stmt->execute([':site_id' => $siteId, ':id' => $recordId]);
        $deleted = $stmt->rowCount() > 0;
        if ($deleted) {
            $this->syncPowerDnsDelete($site, $this->castRow((array) $record));
        }
        return $deleted;
    }

    public function refreshAllProxiedARecords(): void
    {
        if (!$this->powerDns->isEnabled()) {
            return;
        }

        $stmt = Database::pdo()->query(
            'SELECT d.*, s.domain FROM dns_records d
             JOIN sites s ON s.id = d.site_id
             WHERE d.proxied = true AND upper(d.type) = \'A\''
        );
        $rows = $stmt->fetchAll();

        foreach ($rows as $row) {
            $record = $this->castRow((array) $row);
            $site = ['id' => (string) $record['site_id'], 'domain' => (string) $row['domain']];
            $this->syncProxiedARecord($site, $record);
        }
    }

    private function syncPowerDnsCreate(array $site, array $record): void
    {
        if (!$this->powerDns->isEnabled()) {
            return;
        }

        $type = strtoupper((string) $record['type']);
        $result = null;
        if ($record['proxied'] === true && $type === 'A') {
            $result = $this->syncProxiedARecord($site, $record);
        }
        if (!is_array($result)) {
            $result = $this->powerDns->syncReplace(
                (string) $site['domain'],
                (string) $record['name'],
                $type,
                (int) $record['ttl'],
                (string) $record['content']
            );
        }

        if (($result['ok'] ?? false) !== true) {
            Logger::error('powerdns_sync_replace_failed', [
                'site_id' => (string) $site['id'],
                'domain' => (string) $site['domain'],
                'record_name' => (string) $record['name'],
                'record_type' => (string) $record['type'],
                'status' => (int) ($result['status'] ?? 0),
                'error' => (string) ($result['error'] ?? 'powerdns_sync_failed'),
                'response' => (string) ($result['response'] ?? ''),
            ]);
            if ($this->powerDns->isStrict()) {
                throw new \RuntimeException((string) ($result['error'] ?? 'powerdns_sync_failed'));
            }
        }
    }

    private function syncPowerDnsDelete(array $site, array $record): void
    {
        if (!$this->powerDns->isEnabled()) {
            return;
        }

        $deleteType = (string) $record['type'];
        if ($record['proxied'] === true && strtoupper((string) $record['type']) === 'A') {
            $deleteType = 'LUA';
        }

        $result = $this->powerDns->syncDelete(
            (string) $site['domain'],
            (string) $record['name'],
            $deleteType
        );

        if (($result['ok'] ?? false) !== true) {
            Logger::error('powerdns_sync_delete_failed', [
                'site_id' => (string) $site['id'],
                'domain' => (string) $site['domain'],
                'record_name' => (string) $record['name'],
                'record_type' => (string) $record['type'],
                'status' => (int) ($result['status'] ?? 0),
                'error' => (string) ($result['error'] ?? 'powerdns_sync_failed'),
                'response' => (string) ($result['response'] ?? ''),
            ]);
            if ($this->powerDns->isStrict()) {
                throw new \RuntimeException((string) ($result['error'] ?? 'powerdns_sync_failed'));
            }
        }
    }

    private function castRow(array $row): array
    {
        $row['id'] = (string) $row['id'];
        $row['site_id'] = (string) $row['site_id'];
        $row['ttl'] = (int) $row['ttl'];
        $row['priority'] = $row['priority'] === null ? null : (int) $row['priority'];
        $row['proxied'] = ((int) $row['proxied']) === 1;
        $row['created_at'] = (int) $row['created_at'];
        $row['updated_at'] = (int) $row['updated_at'];
        return $row;
    }

    private function activeEdgeNodes(): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT public_ip, region FROM edge_nodes WHERE status = :status AND public_ip <> :empty ORDER BY public_ip ASC'
        );
        $stmt->execute([
            ':status' => 'online',
            ':empty' => '',
        ]);
        $rows = $stmt->fetchAll();
        $nodes = [];
        $seen = [];
        foreach ($rows as $row) {
            $ip = trim((string) ($row['public_ip'] ?? ''));
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }
            $region = strtoupper(trim((string) ($row['region'] ?? '')));
            $key = $ip . '|' . $region;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $nodes[] = [
                'ip' => $ip,
                'region' => $region,
            ];
        }
        return $nodes;
    }

    private function syncProxiedARecord(array $site, array $record): ?array
    {
        $edgeNodes = $this->activeEdgeNodes();
        if ($edgeNodes !== []) {
            $lua = $this->buildGeoLuaARecord($edgeNodes);
            if ($lua !== null) {
                return $this->powerDns->syncReplace(
                    (string) $site['domain'],
                    (string) $record['name'],
                    'LUA',
                    (int) $record['ttl'],
                    $lua
                );
            }

            $edgeIps = array_values(array_unique(array_map(static fn(array $n): string => (string) $n['ip'], $edgeNodes)));
            return $this->powerDns->syncReplaceMany(
                (string) $site['domain'],
                (string) $record['name'],
                'A',
                (int) $record['ttl'],
                $edgeIps
            );
        }

        Logger::warn('proxied_record_no_active_edges_fallback_to_content', [
            'site_id' => (string) $site['id'],
            'domain' => (string) $site['domain'],
            'record_name' => (string) $record['name'],
            'record_type' => 'A',
        ]);

        return $this->powerDns->syncReplace(
            (string) $site['domain'],
            (string) $record['name'],
            'A',
            (int) $record['ttl'],
            (string) $record['content']
        );
    }

    private function buildGeoLuaARecord(array $edgeNodes): ?string
    {
        $regionIp = [];
        foreach ($edgeNodes as $node) {
            $region = (string) ($node['region'] ?? '');
            $ip = (string) ($node['ip'] ?? '');
            if ($region === '' || $ip === '' || isset($regionIp[$region])) {
                continue;
            }
            if (preg_match('/^[A-Z]{2}$/', $region) !== 1) {
                continue;
            }
            $regionIp[$region] = $ip;
        }

        $fallback = null;
        foreach ($regionIp as $ip) {
            $fallback = $ip;
            break;
        }
        if ($fallback === null) {
            return null;
        }

        $branches = [];
        foreach ($regionIp as $cc => $ip) {
            $branches[] = ["country({'{$cc}'})", $ip];
        }
        if ($branches === []) {
            return null;
        }

        $parts = [];
        foreach ($branches as $idx => $branch) {
            [$cond, $ip] = $branch;
            if ($idx === 0) {
                $parts[] = "if {$cond} then return '{$ip}'";
                continue;
            }
            $parts[] = "elseif {$cond} then return '{$ip}'";
        }
        $parts[] = "else return '{$fallback}' end";
        return 'A ";' . implode(' ', $parts) . '"';
    }
}
