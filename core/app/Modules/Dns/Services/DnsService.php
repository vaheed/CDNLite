<?php

namespace App\Modules\Dns\Services;

use App\Support\Database;
use App\Modules\Sites\Services\SiteService;

class DnsService
{
    private PowerDnsService $powerDns;
    private SiteService $sites;

    public function __construct()
    {
        $this->powerDns = new PowerDnsService();
        $this->sites = new SiteService();
    }

    public function listBySite(int $siteId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM dns_records WHERE site_id = :site_id ORDER BY id ASC');
        $stmt->execute([':site_id' => $siteId]);
        $rows = $stmt->fetchAll();
        return array_map([$this, 'castRow'], $rows);
    }

    public function create(int $siteId, array $input): array
    {
        $site = $this->sites->find($siteId);
        if ($site === null) {
            throw new \RuntimeException('site_not_found');
        }

        $now = time();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO dns_records (site_id, type, name, content, ttl, priority, proxied, status, created_at, updated_at)
             VALUES (:site_id, :type, :name, :content, :ttl, :priority, :proxied, :status, :created_at, :updated_at)'
        );
        $stmt->execute([
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

        $id = (int) Database::pdo()->lastInsertId();
        $stmt = Database::pdo()->prepare('SELECT * FROM dns_records WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $created = $this->castRow((array) $stmt->fetch());
        $this->syncPowerDnsCreate($site, $created);
        return $created;
    }

    public function delete(int $siteId, int $recordId): bool
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

    private function syncPowerDnsCreate(array $site, array $record): void
    {
        if (!$this->powerDns->isEnabled()) {
            return;
        }

        $result = $this->powerDns->syncReplace(
            (string) $site['domain'],
            (string) $record['name'],
            (string) $record['type'],
            (int) $record['ttl'],
            (string) $record['content']
        );

        if (($result['ok'] ?? false) !== true && $this->powerDns->isStrict()) {
            throw new \RuntimeException((string) ($result['error'] ?? 'powerdns_sync_failed'));
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
            (string) $record['type']
        );

        if (($result['ok'] ?? false) !== true && $this->powerDns->isStrict()) {
            throw new \RuntimeException((string) ($result['error'] ?? 'powerdns_sync_failed'));
        }
    }

    private function castRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['site_id'] = (int) $row['site_id'];
        $row['ttl'] = (int) $row['ttl'];
        $row['priority'] = $row['priority'] === null ? null : (int) $row['priority'];
        $row['proxied'] = ((int) $row['proxied']) === 1;
        $row['created_at'] = (int) $row['created_at'];
        $row['updated_at'] = (int) $row['updated_at'];
        return $row;
    }
}
