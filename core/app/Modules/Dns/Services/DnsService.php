<?php

namespace App\Modules\Dns\Services;

use App\Support\Database;

class DnsService
{
    public function listBySite(int $siteId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM dns_records WHERE site_id = :site_id ORDER BY id ASC');
        $stmt->execute([':site_id' => $siteId]);
        $rows = $stmt->fetchAll();
        return array_map([$this, 'castRow'], $rows);
    }

    public function create(int $siteId, array $input): array
    {
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
        return $this->castRow((array) $stmt->fetch());
    }

    public function delete(int $siteId, int $recordId): bool
    {
        $stmt = Database::pdo()->prepare('DELETE FROM dns_records WHERE site_id = :site_id AND id = :id');
        $stmt->execute([':site_id' => $siteId, ':id' => $recordId]);
        return $stmt->rowCount() > 0;
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
