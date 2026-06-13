<?php

namespace App\Modules\Proxy\Services;

use App\Support\AuditLog;
use App\Support\Database;
use App\Support\Uuid;

class OriginHealthService
{
    public function list(string $domainId): array
    {
        $this->ensurePrimaryFromDnsRecords($domainId);
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM domain_origins WHERE domain_id=:domain_id ORDER BY is_primary DESC, enabled DESC, created_at ASC'
        );
        $stmt->execute([':domain_id' => $domainId]);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    public function create(string $domainId, array $input): array
    {
        $now = time();
        $isPrimary = array_key_exists('is_primary', $input) ? !empty($input['is_primary']) : false;
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($isPrimary) {
                $pdo->prepare('UPDATE domain_origins SET is_primary=false, updated_at=:updated_at WHERE domain_id=:domain_id')
                    ->execute([':domain_id' => $domainId, ':updated_at' => $now]);
            }
            $id = Uuid::v4();
            $row = [
                'id' => $id,
                'domain_id' => $domainId,
                'scheme' => (string) ($input['scheme'] ?? 'http'),
                'host' => strtolower(trim((string) ($input['host'] ?? ''))),
                'port' => (int) ($input['port'] ?? ((string) ($input['scheme'] ?? 'http') === 'https' ? 443 : 80)),
                'is_primary' => $isPrimary,
                'health_check_path' => (string) ($input['health_check_path'] ?? '/'),
                'health_check_interval_seconds' => (int) ($input['health_check_interval_seconds'] ?? 30),
                'health_check_timeout_seconds' => (int) ($input['health_check_timeout_seconds'] ?? 5),
                'health_status' => 'unknown',
                'enabled' => array_key_exists('enabled', $input) ? !empty($input['enabled']) : true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $pdo->prepare(
                'INSERT INTO domain_origins
                 (id,domain_id,scheme,host,port,is_primary,health_check_path,health_check_interval_seconds,health_check_timeout_seconds,health_status,last_check_at,last_error,enabled,created_at,updated_at)
                 VALUES
                 (:id,:domain_id,:scheme,:host,:port,:is_primary,:health_check_path,:health_check_interval_seconds,:health_check_timeout_seconds,:health_status,NULL,NULL,:enabled,:created_at,:updated_at)'
            )->execute([
                ':id' => $row['id'],
                ':domain_id' => $row['domain_id'],
                ':scheme' => $row['scheme'],
                ':host' => $row['host'],
                ':port' => $row['port'],
                ':is_primary' => (int) $row['is_primary'],
                ':health_check_path' => $row['health_check_path'],
                ':health_check_interval_seconds' => $row['health_check_interval_seconds'],
                ':health_check_timeout_seconds' => $row['health_check_timeout_seconds'],
                ':health_status' => $row['health_status'],
                ':enabled' => (int) $row['enabled'],
                ':created_at' => $row['created_at'],
                ':updated_at' => $row['updated_at'],
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $created = $this->find($domainId, $id);
        AuditLog::write('origin.create', 'origin', $id, $domainId, null, $created);
        $this->invalidateConfig();
        return $created;
    }

    public function update(string $domainId, string $originId, array $input): ?array
    {
        $existing = $this->find($domainId, $originId);
        if ($existing === null) {
            return null;
        }
        $now = time();
        $patch = [
            'scheme' => (string) ($input['scheme'] ?? $existing['scheme']),
            'host' => strtolower(trim((string) ($input['host'] ?? $existing['host']))),
            'port' => (int) ($input['port'] ?? $existing['port']),
            'is_primary' => array_key_exists('is_primary', $input) ? !empty($input['is_primary']) : (bool) $existing['is_primary'],
            'health_check_path' => (string) ($input['health_check_path'] ?? $existing['health_check_path']),
            'health_check_interval_seconds' => (int) ($input['health_check_interval_seconds'] ?? $existing['health_check_interval_seconds']),
            'health_check_timeout_seconds' => (int) ($input['health_check_timeout_seconds'] ?? $existing['health_check_timeout_seconds']),
            'enabled' => array_key_exists('enabled', $input) ? !empty($input['enabled']) : (bool) $existing['enabled'],
        ];
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($patch['is_primary']) {
                $pdo->prepare('UPDATE domain_origins SET is_primary=false, updated_at=:updated_at WHERE domain_id=:domain_id AND id<>:id')
                    ->execute([':domain_id' => $domainId, ':id' => $originId, ':updated_at' => $now]);
            }
            $pdo->prepare(
                'UPDATE domain_origins SET scheme=:scheme,host=:host,port=:port,is_primary=:is_primary,
                 health_check_path=:health_check_path,health_check_interval_seconds=:health_check_interval_seconds,
                 health_check_timeout_seconds=:health_check_timeout_seconds,enabled=:enabled,updated_at=:updated_at
                 WHERE domain_id=:domain_id AND id=:id'
            )->execute([
                ':domain_id' => $domainId,
                ':id' => $originId,
                ':scheme' => $patch['scheme'],
                ':host' => $patch['host'],
                ':port' => $patch['port'],
                ':is_primary' => (int) $patch['is_primary'],
                ':health_check_path' => $patch['health_check_path'],
                ':health_check_interval_seconds' => $patch['health_check_interval_seconds'],
                ':health_check_timeout_seconds' => $patch['health_check_timeout_seconds'],
                ':enabled' => (int) $patch['enabled'],
                ':updated_at' => $now,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $updated = $this->find($domainId, $originId);
        AuditLog::write('origin.update', 'origin', $originId, $domainId, $existing, $updated);
        $this->invalidateConfig();
        return $updated;
    }

    public function delete(string $domainId, string $originId): bool
    {
        $existing = $this->find($domainId, $originId);
        if ($existing === null) {
            return false;
        }
        $stmt = Database::pdo()->prepare('DELETE FROM domain_origins WHERE domain_id=:domain_id AND id=:id');
        $stmt->execute([':domain_id' => $domainId, ':id' => $originId]);
        AuditLog::write('origin.delete', 'origin', $originId, $domainId, $existing, null);
        $this->invalidateConfig();
        return $stmt->rowCount() > 0;
    }

    public function check(string $domainId, string $originId): ?array
    {
        $origin = $this->find($domainId, $originId);
        if ($origin === null) {
            return null;
        }
        $result = $this->probe($origin);
        $stmt = Database::pdo()->prepare(
            'UPDATE domain_origins SET health_status=:health_status,last_check_at=:last_check_at,last_error=:last_error,updated_at=:updated_at
             WHERE domain_id=:domain_id AND id=:id'
        );
        $stmt->execute([
            ':domain_id' => $domainId,
            ':id' => $originId,
            ':health_status' => $result['health_status'],
            ':last_check_at' => $result['last_check_at'],
            ':last_error' => $result['last_error'],
            ':updated_at' => $result['last_check_at'],
        ]);
        return $this->find($domainId, $originId);
    }

    public function checkDue(): array
    {
        $cutoff = time();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM domain_origins
             WHERE enabled=true AND (last_check_at IS NULL OR last_check_at + health_check_interval_seconds <= :now)
             ORDER BY COALESCE(last_check_at, 0) ASC'
        );
        $stmt->execute([':now' => $cutoff]);
        $results = [];
        foreach ($stmt->fetchAll() as $row) {
            $checked = $this->check((string) $row['domain_id'], (string) $row['id']);
            if ($checked !== null) {
                $results[] = $checked;
            }
        }
        return ['checked' => count($results), 'results' => $results];
    }

    public function primaryAndBackupForDomain(string $domainId): array
    {
        $rows = array_values(array_filter($this->list($domainId), static fn (array $row): bool => !empty($row['enabled'])));
        $primary = null;
        $backup = null;
        foreach ($rows as $row) {
            if (!empty($row['is_primary']) && $primary === null) {
                $primary = $row;
                continue;
            }
            if (empty($row['is_primary']) && $backup === null) {
                $backup = $row;
            }
        }
        return ['primary' => $primary, 'backup' => $backup];
    }

    public function addBackupFromDnsRecord(string $domainId, array $record): array
    {
        $this->ensurePrimaryFromDnsRecords($domainId);
        $host = strtolower(trim((string) ($record['origin_host'] ?? $record['content'] ?? '')));
        $scheme = (string) ($record['origin_scheme'] ?? 'http') ?: 'http';
        $existing = Database::pdo()->prepare(
            'SELECT id FROM domain_origins WHERE domain_id=:domain_id AND lower(host)=:host AND scheme=:scheme LIMIT 1'
        );
        $existing->execute(['domain_id' => $domainId, 'host' => $host, 'scheme' => $scheme]);
        $id = $existing->fetchColumn();
        if ($id !== false) {
            $found = $this->find($domainId, (string) $id);
            if ($found !== null) {
                return $found;
            }
        }
        return $this->create($domainId, [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $scheme === 'https' ? 443 : 80,
            'is_primary' => false,
            'enabled' => true,
        ]);
    }

    private function find(string $domainId, string $originId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM domain_origins WHERE domain_id=:domain_id AND id=:id LIMIT 1');
        $stmt->execute([':domain_id' => $domainId, ':id' => $originId]);
        $row = $stmt->fetch();
        return $row ? $this->cast($row) : null;
    }

    private function probe(array $origin): array
    {
        $now = time();
        $path = '/' . ltrim((string) ($origin['health_check_path'] ?? '/'), '/');
        $url = sprintf('%s://%s:%d%s', $origin['scheme'], $origin['host'], (int) $origin['port'], $path);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => max(1, (int) ($origin['health_check_timeout_seconds'] ?? 5)),
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string) $header, $m)) {
                $status = (int) $m[1];
                break;
            }
        }
        $healthy = $body !== false && $status >= 200 && $status < 500;
        return [
            'health_status' => $healthy ? 'healthy' : 'unhealthy',
            'last_check_at' => $now,
            'last_error' => $healthy ? null : ($status > 0 ? 'http_' . $status : 'connection_failed'),
        ];
    }

    private function ensurePrimaryFromDnsRecords(string $domainId): void
    {
        $exists = Database::pdo()->prepare('SELECT 1 FROM domain_origins WHERE domain_id=:domain_id AND is_primary=true LIMIT 1');
        $exists->execute([':domain_id' => $domainId]);
        if ($exists->fetchColumn() !== false) {
            return;
        }
        $record = Database::pdo()->prepare(
            "SELECT * FROM dns_records
             WHERE domain_id=:domain_id AND proxied=true
               AND COALESCE(NULLIF(origin_host, ''), NULLIF(origin_content, ''), content) IS NOT NULL
             ORDER BY name='@' DESC, created_at ASC LIMIT 1"
        );
        $record->execute([':domain_id' => $domainId]);
        $row = $record->fetch();
        if (!$row) {
            return;
        }
        $this->create($domainId, [
            'scheme' => (string) ($row['origin_scheme'] ?? 'http') ?: 'http',
            'host' => (string) ($row['origin_host'] ?: ($row['origin_content'] ?: $row['content'])),
            'port' => (string) ($row['origin_scheme'] ?? 'http') === 'https' ? 443 : 80,
            'is_primary' => true,
            'enabled' => true,
        ]);
    }

    private function cast(array $row): array
    {
        foreach (['port', 'health_check_interval_seconds', 'health_check_timeout_seconds', 'created_at', 'updated_at'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['last_check_at'] = $row['last_check_at'] === null ? null : (int) $row['last_check_at'];
        $row['is_primary'] = in_array($row['is_primary'], [true, 1, '1', 't', 'true'], true);
        $row['enabled'] = in_array($row['enabled'], [true, 1, '1', 't', 'true'], true);
        return $row;
    }

    private function invalidateConfig(): void
    {
        Database::pdo()->exec('UPDATE config_state SET active_snapshot_version = NULL WHERE id = 1');
    }
}
