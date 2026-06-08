<?php

namespace App\Modules\Proxy\Services;

use App\Modules\Dns\Services\DnsService;
use App\Modules\Domains\Services\DomainService;
use App\Support\Database;

class ConfigService
{
    public function __construct(
        private DomainService $domains,
        private DnsService $dns,
        private ?TrafficRulesService $rules = null,
        private ?OriginHealthService $origins = null
    ) {
        $this->rules ??= new TrafficRulesService();
        $this->origins ??= new OriginHealthService();
    }

    public function buildSnapshot(): array
    {
        return $this->buildSnapshotForVersion(null);
    }

    public function buildSnapshotForVersion(?int $ifVersion = null): array
    {
        return $this->rebuild($ifVersion);
    }

    public function rebuild(?int $ifVersion = null): array
    {
        Database::pdo()->exec('UPDATE config_state SET active_snapshot_version = NULL WHERE id = 1');
        $hosts = [];
        foreach ($this->domains->all() as $domain) {
            if ((string) ($domain['status'] ?? '') !== 'active') {
                continue;
            }
            $records = $this->dns->listByDomain((string) $domain['id']);
            $record = $this->primaryProxiedRecord($records);
            if ($record === null) {
                continue;
            }
            $originHost = trim((string) ($record['origin_host'] ?? $record['origin_content'] ?? $record['content'] ?? ''));
            if ($originHost === '') {
                continue;
            }
            $configuredOrigins = $this->origins->primaryAndBackupForDomain((string) $domain['id']);
            $primaryOrigin = [
                'host' => $originHost,
                'tls_verify' => (string) ($record['origin_tls_verify'] ?? 'verify'),
                'scheme' => $record['origin_scheme'] ?? null,
                'status' => (string) ($record['origin_status'] ?? 'pending'),
            ];
            $backupOrigin = $configuredOrigins['backup'] !== null ? $this->originForSnapshot($configuredOrigins['backup']) : null;
            $hosts[$domain['domain']] = [
                'domain_id' => (string) $domain['id'],
                'origin' => $primaryOrigin,
                'primary_origin' => $primaryOrigin,
                'backup_origin' => $backupOrigin,
                'geo_origins' => $this->buildGeoOrigins($record['geo_origins'] ?? []),
                'cache_rules' => ['enabled' => false, 'rules' => []],
                'headers' => ['X-CDNLITE-Domain' => (string) $domain['id']],
                'dns_records' => $records,
                'ssl' => $this->rules->getSslSettings((string) $domain['id']),
            ];
            $shieldHeaderName = isset($domain['origin_shield_header_name']) ? trim((string) $domain['origin_shield_header_name']) : '';
            $shieldHash = isset($domain['origin_shield_header_value_hash']) ? trim((string) $domain['origin_shield_header_value_hash']) : '';
            $shieldSecret = (string) (getenv('CDNLITE_ORIGIN_SHIELD_SECRET') ?: '');
            if ($shieldHeaderName !== '' && $shieldHash !== '' && $shieldSecret !== '' && hash('sha256', $shieldSecret) === $shieldHash) {
                $hosts[$domain['domain']]['headers'][$shieldHeaderName] = $shieldSecret;
            }
        }

        ksort($hosts);
        $redirects = [];
        $rateLimits = [];
        $wafRules = [];
        $headerRules = [];
        $ipRules = [];
        $cacheRules = [];
        $cachePurgeVersions = [];
        $pageRules = [];
        $sslCertificates = [];
        foreach ($hosts as $host => $domainCfg) {
            $domainId = (string) $domainCfg['domain_id'];
            foreach ($this->rules->listRedirects($domainId) as $row) { if (!empty($row['enabled'])) { $row['host'] = $host; $redirects[] = $row; } }
            foreach ($this->rules->listRateLimits($domainId) as $row) { if (!empty($row['enabled'])) { $row['host'] = $host; $rateLimits[] = $row; } }
            foreach ($this->rules->listWaf($domainId) as $row) { if (!empty($row['enabled'])) { $row['host'] = $host; $wafRules[] = $row; } }
            foreach ($this->rules->listHeaderRules($domainId) as $row) { if (!empty($row['enabled'])) { $row['host'] = $host; $headerRules[] = $row; $hosts[$host]['header_rules'][] = $row; } }
            foreach ($this->rules->listIpRules($domainId) as $row) { if (!empty($row['enabled'])) { $row['host'] = $host; $ipRules[] = $row; $hosts[$host]['ip_rules'][] = $row; } }
            foreach ($this->rules->listCacheRules($domainId) as $row) { if (!empty($row['enabled'])) { $row['host'] = $host; $cacheRules[] = $row; } }
            foreach ($this->rules->listCachePurgeVersionsForConfig($domainId, $host) as $row) { $cachePurgeVersions[] = $row; }
            foreach ($this->rules->listPageRules($domainId) as $row) { if (!empty($row['enabled'])) { $row['host'] = $host; $pageRules[] = $row; } }
            foreach ($this->rules->listSslCertificatesForConfig($domainId, $host) as $row) { $sslCertificates[] = $row; }
        }
        // Keep hash deterministic for unchanged config content.
        // `generated_at` is intentionally excluded so no-op syncs reuse version.
        $contentHash = hash('sha256', json_encode(['hosts' => $hosts, 'redirects' => $redirects, 'rate_limits' => $rateLimits, 'waf_rules' => $wafRules, 'header_rules' => $headerRules, 'ip_rules' => $ipRules, 'cache_rules' => $cacheRules, 'cache_purge_versions' => $cachePurgeVersions, 'page_rules' => $pageRules, 'ssl_certificates' => $sslCertificates], JSON_UNESCAPED_SLASHES));

        $existing = $this->findByHash($contentHash);
        if ($existing !== null) {
            $existing = $this->refreshSnapshotGeneratedAt($existing);
            $this->activateSnapshotVersion((int) $existing['version']);
            if ($ifVersion !== null && $ifVersion === (int) $existing['version']) {
                return ['not_modified' => true, 'version' => (int) $existing['version']];
            }

            return [
                'schema_version' => 1,
                'version' => (int) $existing['version'],
                'generated_at' => (int) $existing['generated_at'],
                'hosts' => $hosts,
                'redirects' => $redirects,
                'rate_limits' => $rateLimits,
                'waf_rules' => $wafRules,
                'header_rules' => $headerRules,
                'ip_rules' => $ipRules,
                'cache_rules' => $cacheRules,
                'cache_purge_versions' => $cachePurgeVersions,
                'page_rules' => $pageRules,
                'ssl_certificates' => $sslCertificates,
                'reused' => true,
            ];
        }

        $version = $this->nextVersion();
        $payload = [
            'schema_version' => 1,
            'version' => $version,
            'generated_at' => time(),
            'hosts' => $hosts,
            'redirects' => $redirects,
            'rate_limits' => $rateLimits,
            'waf_rules' => $wafRules,
            'header_rules' => $headerRules,
            'ip_rules' => $ipRules,
            'cache_rules' => $cacheRules,
            'cache_purge_versions' => $cachePurgeVersions,
            'page_rules' => $pageRules,
            'ssl_certificates' => $sslCertificates,
        ];
        if (!$this->storeSnapshot($version, $contentHash, $payload)) {
            $existing = $this->findByHash($contentHash);
            if ($existing !== null) {
                $existing = $this->refreshSnapshotGeneratedAt($existing);
                $this->activateSnapshotVersion((int) $existing['version']);
                if ($ifVersion !== null && $ifVersion === (int) $existing['version']) {
                    return ['not_modified' => true, 'version' => (int) $existing['version']];
                }

                return [
                    'schema_version' => 1,
                    'version' => (int) $existing['version'],
                    'generated_at' => (int) $existing['generated_at'],
                    'hosts' => $hosts,
                    'redirects' => $redirects,
                    'rate_limits' => $rateLimits,
                    'waf_rules' => $wafRules,
                    'header_rules' => $headerRules,
                    'ip_rules' => $ipRules,
                    'cache_rules' => $cacheRules,
                    'cache_purge_versions' => $cachePurgeVersions,
                    'page_rules' => $pageRules,
                    'ssl_certificates' => $sslCertificates,
                    'reused' => true,
                ];
            }

            throw new \RuntimeException('config_snapshot_store_failed');
        }
        $this->activateSnapshotVersion($version);

        if ($ifVersion !== null && $ifVersion === $version) {
            return ['not_modified' => true, 'version' => $version];
        }

        return $payload;
    }

    public function snapshots(): array
    {
        $rows = Database::pdo()->query(
            'SELECT s.version,s.generated_at,s.content_hash,length(s.payload_json) AS size,
                    (s.version=cs.active_snapshot_version) AS active
             FROM config_snapshots s CROSS JOIN config_state cs
             WHERE cs.id=1 ORDER BY s.version DESC'
        )->fetchAll();
        return array_map(static fn (array $row): array => [
            'version' => (int) $row['version'],
            'generated_at' => (int) $row['generated_at'],
            'content_hash' => (string) $row['content_hash'],
            'size' => (int) $row['size'],
            'active' => in_array($row['active'], [true, 1, '1', 't', 'true'], true),
        ], $rows);
    }

    public function snapshot(int $version): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT payload_json FROM config_snapshots WHERE version=:version');
        $stmt->execute([':version' => $version]);
        $payload = $stmt->fetchColumn();
        return $payload === false ? null : json_decode((string) $payload, true);
    }

    public function diff(int $fromVersion, int $toVersion): array
    {
        $from = $this->snapshot($fromVersion);
        $to = $this->snapshot($toVersion);
        if ($from === null || $to === null) {
            throw new \OutOfBoundsException('config_snapshot_not_found');
        }
        return ['from_version' => $fromVersion, 'to_version' => $toVersion, 'changes' => $this->diffValues($from, $to)];
    }

    public function rollback(int $version): array
    {
        $payload = $this->snapshot($version);
        if ($payload === null) {
            throw new \OutOfBoundsException('config_snapshot_not_found');
        }
        Database::pdo()->prepare('UPDATE config_state SET active_snapshot_version=:version WHERE id=1')
            ->execute([':version' => $version]);
        return $payload;
    }

    private function activeSnapshot(): ?array
    {
        $row = Database::pdo()->query(
            'SELECT s.version,s.payload_json FROM config_state cs
             JOIN config_snapshots s ON s.version=cs.active_snapshot_version WHERE cs.id=1'
        )->fetch();
        return $row ? ['version' => (int) $row['version'], 'payload' => json_decode((string) $row['payload_json'], true)] : null;
    }

    private function diffValues(mixed $from, mixed $to, string $path = ''): array
    {
        if (!is_array($from) || !is_array($to)) {
            return $from === $to ? [] : [['path' => $path ?: '/', 'before' => $from, 'after' => $to]];
        }
        $changes = [];
        foreach (array_unique(array_merge(array_keys($from), array_keys($to))) as $key) {
            $child = $path . '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $key);
            if (!array_key_exists($key, $from)) { $changes[] = ['path' => $child, 'before' => null, 'after' => $to[$key]]; continue; }
            if (!array_key_exists($key, $to)) { $changes[] = ['path' => $child, 'before' => $from[$key], 'after' => null]; continue; }
            array_push($changes, ...$this->diffValues($from[$key], $to[$key], $child));
        }
        return $changes;
    }

    private function nextVersion(): int
    {
        $pdo = Database::pdo();
        $pdo->exec('INSERT INTO config_state (id, version) VALUES (1, 0) ON CONFLICT (id) DO NOTHING');
        $pdo->exec('UPDATE config_state SET version = version + 1 WHERE id = 1');
        $stmt = $pdo->query('SELECT version FROM config_state WHERE id = 1');
        $row = (array) $stmt->fetch();
        return (int) $row['version'];
    }

    private function findByHash(string $contentHash): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT version, generated_at, payload_json FROM config_snapshots WHERE content_hash = :content_hash LIMIT 1'
        );
        $stmt->execute([':content_hash' => $contentHash]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return (array) $row;
    }

    private function refreshSnapshotGeneratedAt(array $snapshot): array
    {
        $generatedAt = time();
        $payload = json_decode((string) ($snapshot['payload_json'] ?? ''), true);
        if (is_array($payload)) {
            $payload['generated_at'] = $generatedAt;
            Database::pdo()->prepare(
                'UPDATE config_snapshots SET generated_at = :generated_at, payload_json = :payload_json WHERE version = :version'
            )->execute([
                ':generated_at' => $generatedAt,
                ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
                ':version' => (int) $snapshot['version'],
            ]);
        } else {
            Database::pdo()->prepare('UPDATE config_snapshots SET generated_at = :generated_at WHERE version = :version')
                ->execute([':generated_at' => $generatedAt, ':version' => (int) $snapshot['version']]);
        }

        $snapshot['generated_at'] = $generatedAt;
        return $snapshot;
    }

    private function storeSnapshot(int $version, string $contentHash, array $payload): bool
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO config_snapshots (version, content_hash, payload_json, generated_at)
             VALUES (:version, :content_hash, :payload_json, :generated_at)'
            . ' ON CONFLICT (content_hash) DO NOTHING'
        );
        $stmt->execute([
            ':version' => $version,
            ':content_hash' => $contentHash,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            ':generated_at' => (int) $payload['generated_at'],
        ]);
        return $stmt->rowCount() === 1;
    }

    private function activateSnapshotVersion(int $version): void
    {
        Database::pdo()->prepare('UPDATE config_state SET active_snapshot_version = :version WHERE id = 1')
            ->execute([':version' => $version]);
    }

    private function originForSnapshot(array $origin): array
    {
        return [
            'id' => (string) $origin['id'],
            'host' => (string) $origin['host'],
            'scheme' => (string) $origin['scheme'],
            'port' => (int) $origin['port'],
            'status' => (string) $origin['health_status'],
            'health_status' => (string) $origin['health_status'],
        ];
    }

    private function primaryProxiedRecord(array $records): ?array
    {
        foreach ($records as $record) {
            if (!empty($record['proxied']) && ($record['status'] ?? 'active') === 'active' && ($record['name'] ?? '') === '@') {
                return $record;
            }
        }
        foreach ($records as $record) {
            if (!empty($record['proxied']) && ($record['status'] ?? 'active') === 'active') {
                return $record;
            }
        }
        return null;
    }

    private function buildGeoOrigins(array $geoOrigins): array
    {
        $out = [];
        foreach ($geoOrigins as $key => $origin) {
            if (!is_string($key) || !is_array($origin)) {
                continue;
            }
            $host = trim((string) ($origin['host'] ?? ''));
            if ($host === '') {
                continue;
            }
            $out[strtoupper(trim($key))] = [
                'host' => $host,
                'tls_verify' => (string) ($origin['tls_verify'] ?? 'verify'),
            ];
        }
        ksort($out);
        return $out;
    }
}
