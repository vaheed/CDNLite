<?php

namespace App\Modules\Proxy\Services;

use App\Modules\Dns\Services\DnsService;
use App\Modules\Domains\Services\DomainService;
use App\Support\AuditLog;
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
        $previousActiveVersion = $this->activeSnapshotVersion();
        $hosts = [];
        foreach ($this->domains->all() as $domain) {
            if ((string) ($domain['status'] ?? '') !== 'active'
                || (string) ($domain['nameserver_status'] ?? '') !== 'verified') {
                continue;
            }
            $records = $this->dns->listByDomain((string) $domain['id']);
            if (!array_filter($records, static fn (array $record): bool => !empty($record['proxied']) && ($record['status'] ?? 'active') === 'active')) {
                continue;
            }
            $configuredOrigins = $this->origins->list((string) $domain['id']);
            $domainHost = strtolower((string) $domain['domain']);
            $origins = $this->originsForSnapshot($configuredOrigins);
            if ($origins === []) {
                $origins = $this->originsFromDnsRecords($records, $domainHost);
            }
            if ($origins === []) {
                continue;
            }
            $baseConfig = [
                'domain_id' => (string) $domain['id'],
                'origin' => $origins[0],
                'origins' => $origins,
                'geo_origins' => $this->buildGeoOrigins($this->dnsRecordsGeoOrigins($records)),
                'cache' => $this->rules->getDomainCacheSettings((string) $domain['id']),
                'cache_rules' => ['enabled' => false, 'rules' => []],
                'headers' => ['X-CDNLITE-Domain' => (string) $domain['id']],
                'dns_records' => $records,
                'ssl' => $this->rules->getSslSettings((string) $domain['id']),
                'verified_bot_sources' => $this->rules->listVerifiedBotSourcesForConfig((string) $domain['id']),
            ];
            $shieldHeaderName = isset($domain['origin_shield_header_name']) ? trim((string) $domain['origin_shield_header_name']) : '';
            $shieldHash = isset($domain['origin_shield_header_value_hash']) ? trim((string) $domain['origin_shield_header_value_hash']) : '';
            $shieldSecret = (string) (getenv('CDNLITE_ORIGIN_SHIELD_SECRET') ?: '');
            if ($shieldHeaderName !== '' && $shieldHash !== '' && $shieldSecret !== '' && hash('sha256', $shieldSecret) === $shieldHash) {
                $baseConfig['headers'][$shieldHeaderName] = $shieldSecret;
            }

            foreach ($this->proxiedRecordHosts($domainHost, $records, $configuredOrigins) as $recordHost => $recordOrigins) {
                $recordConfig = $baseConfig;
                if ($recordOrigins !== []) {
                    $recordConfig['origin'] = $recordOrigins[0];
                    $recordConfig['origins'] = $recordOrigins;
                    $recordConfig['geo_origins'] = $this->buildGeoOrigins($this->dnsRecordsGeoOriginsForHost($records, $recordHost, $domainHost));
                }
                $hosts[$recordHost] = $recordConfig;
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
            foreach ($this->rules->listWaitingRoomPoliciesForConfig($domainId) as $row) { $hosts[$host]['waiting_room'] = $row; }
        }
        // Keep hash deterministic for unchanged config content.
        // `generated_at` is intentionally excluded so no-op syncs reuse version.
        $contentHash = hash('sha256', json_encode(['hosts' => $hosts, 'redirects' => $redirects, 'rate_limits' => $rateLimits, 'waf_rules' => $wafRules, 'header_rules' => $headerRules, 'ip_rules' => $ipRules, 'cache_rules' => $cacheRules, 'cache_purge_versions' => $cachePurgeVersions, 'page_rules' => $pageRules, 'ssl_certificates' => $sslCertificates], JSON_UNESCAPED_SLASHES));

        $existing = $this->findReusableActiveSnapshot($previousActiveVersion, $contentHash);
        if ($existing !== null) {
            if ($ifVersion !== null && $ifVersion === (int) $existing['version']) {
                $this->activateSnapshotVersion((int) $existing['version']);
                return ['not_modified' => true, 'version' => (int) $existing['version']];
            }

            $existing = $this->refreshSnapshotGeneratedAt($existing);
            $this->activateSnapshotVersion((int) $existing['version']);
            $this->auditSnapshotPublish((int) $existing['version'], $previousActiveVersion, (int) $existing['version'], true);

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
        $this->storeSnapshot($version, $contentHash, $payload);
        $this->activateSnapshotVersion($version);
        $this->auditSnapshotPublish($version, $previousActiveVersion, $version, false);

        if ($ifVersion !== null && $ifVersion === $version) {
            return ['not_modified' => true, 'version' => $version];
        }

        return $payload;
    }

    public function snapshots(): array
    {
        $rows = Database::pdo()->query(
            'SELECT s.version,s.generated_at,s.content_hash,pg_column_size(s.payload_json) AS size,
                    (s.version=cs.active_snapshot_version) AS active
             FROM config_snapshots s CROSS JOIN config_state cs
             WHERE cs.id=1 ORDER BY s.version DESC'
        )->fetchAll();
        return array_map(static fn (array $row): array => self::snapshotSummaryFromRow($row), $rows);
    }

    public function latestSnapshotSummary(): ?array
    {
        $row = Database::pdo()->query(
            'SELECT s.version,s.generated_at,s.content_hash,pg_column_size(s.payload_json) AS size,
                    (s.version=cs.active_snapshot_version) AS active
             FROM config_snapshots s CROSS JOIN config_state cs
             WHERE cs.id=1 ORDER BY s.version DESC LIMIT 1'
        )->fetch();
        return $row === false ? null : self::snapshotSummaryFromRow((array) $row);
    }

    private static function snapshotSummaryFromRow(array $row): array
    {
        return [
            'version' => (int) $row['version'],
            'generated_at' => (int) $row['generated_at'],
            'content_hash' => (string) $row['content_hash'],
            'size' => (int) $row['size'],
            'active' => in_array($row['active'], [true, 1, '1', 't', 'true'], true),
        ];
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
        $previousActiveVersion = $this->activeSnapshotVersion();
        Database::pdo()->prepare('UPDATE config_state SET active_snapshot_version=:version WHERE id=1')
            ->execute([':version' => $version]);
        AuditLog::write('config.rollback', 'config_snapshot', (string) $version, null, ['active_version' => $previousActiveVersion], ['active_version' => $version]);
        return $payload;
    }

    public function debugRoute(string $domainId, array $input): array
    {
        $snapshot = $this->activeSnapshot();
        $payload = $snapshot['payload'] ?? null;
        if (!is_array($payload)) {
            $payload = $this->buildSnapshot();
            $snapshot = ['version' => (int) ($payload['version'] ?? 0), 'payload' => $payload];
        }

        $host = strtolower(trim((string) ($input['host'] ?? '')));
        $path = (string) ($input['path'] ?? '/');
        $country = strtoupper(trim((string) ($input['country'] ?? '')));
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }

        $matchedHost = null;
        $domainConfig = null;
        foreach (($payload['hosts'] ?? []) as $configuredHost => $config) {
            if ((string) ($config['domain_id'] ?? '') !== $domainId) {
                continue;
            }
            if ($host === '' || $host === strtolower((string) $configuredHost)) {
                $matchedHost = (string) $configuredHost;
                $domainConfig = is_array($config) ? $config : null;
                break;
            }
        }

        if ($domainConfig === null) {
            return [
                'configured' => false,
                'domain_id' => $domainId,
                'host' => $host,
                'path' => $path,
                'country' => $country,
                'snapshot_version' => (int) ($snapshot['version'] ?? $payload['version'] ?? 0),
                'router_error' => 'domain_not_configured',
            ];
        }

        $origin = null;
        $originSource = 'origins';
        $geoOrigins = $domainConfig['geo_origins'] ?? [];
        if ($country !== '' && is_array($geoOrigins) && isset($geoOrigins[$country]) && is_array($geoOrigins[$country])) {
            $origin = $geoOrigins[$country];
            $originSource = 'geo_origins.' . $country;
        }
        $origins = array_values(array_filter(
            is_array($domainConfig['origins'] ?? null) ? $domainConfig['origins'] : [],
            static fn (mixed $origin): bool => is_array($origin) && !empty($origin['enabled'])
        ));
        if ($origin === null) {
            $origin = $this->selectOriginFromPool($origins, $host . '|' . $path);
        }

        return [
            'configured' => true,
            'domain_id' => $domainId,
            'host' => $matchedHost,
            'request_host' => $host === '' ? $matchedHost : $host,
            'path' => $path,
            'country' => $country === '' ? null : $country,
            'snapshot_version' => (int) ($snapshot['version'] ?? $payload['version'] ?? 0),
            'selected_origin' => $origin,
            'selected_origin_source' => $origin === null ? null : $originSource,
            'origin_pool_size' => count($origins),
            'cache_rules_count' => count($domainConfig['cache_rules']['rules'] ?? []),
            'waf_rules_count' => count($payload['waf_rules'] ?? []),
            'rate_limits_count' => count($payload['rate_limits'] ?? []),
            'ssl' => [
                'enabled' => (bool) ($domainConfig['ssl']['enabled'] ?? false),
                'certificate_status' => (string) ($domainConfig['ssl']['certificate_status'] ?? 'unknown'),
            ],
            'router_error' => $origin === null ? 'missing_origin' : null,
        ];
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

    private function findReusableActiveSnapshot(?int $activeVersion, string $contentHash): ?array
    {
        if ($activeVersion === null) {
            return null;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT s.version, s.generated_at, s.payload_json
             FROM config_snapshots s
             WHERE s.version = :version AND s.content_hash = :content_hash
             LIMIT 1'
        );
        $stmt->execute([':version' => $activeVersion, ':content_hash' => $contentHash]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return (array) $row;
    }

    private function refreshSnapshotGeneratedAt(array $snapshot): array
    {
        $generatedAt = time();
        // Avoid rewriting the large snapshot JSON on no-op publishes; PostgreSQL
        // stores that value in TOAST, so metadata-only refreshes must stay small.
        Database::pdo()->prepare('UPDATE config_snapshots SET generated_at = :generated_at WHERE version = :version')
            ->execute([':generated_at' => $generatedAt, ':version' => (int) $snapshot['version']]);

        $snapshot['generated_at'] = $generatedAt;
        return $snapshot;
    }

    private function storeSnapshot(int $version, string $contentHash, array $payload): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO config_snapshots (version, content_hash, payload_json, generated_at)
             VALUES (:version, :content_hash, :payload_json, :generated_at)'
        );
        $stmt->execute([
            ':version' => $version,
            ':content_hash' => $contentHash,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            ':generated_at' => (int) $payload['generated_at'],
        ]);
    }

    private function activateSnapshotVersion(int $version): void
    {
        Database::pdo()->prepare('UPDATE config_state SET active_snapshot_version = :version WHERE id = 1')
            ->execute([':version' => $version]);
    }

    private function activeSnapshotVersion(): ?int
    {
        $value = Database::pdo()->query('SELECT active_snapshot_version FROM config_state WHERE id = 1')->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    }

    private function auditSnapshotPublish(int $version, ?int $beforeVersion, int $afterVersion, bool $existing): void
    {
        AuditLog::write(
            $existing ? 'config.publish.reused' : 'config.publish',
            'config_snapshot',
            (string) $version,
            null,
            ['active_version' => $beforeVersion],
            ['active_version' => $afterVersion, 'snapshot_version' => $version]
        );
    }

    private function originForSnapshot(array $origin): array
    {
        return [
            'id' => (string) $origin['id'],
            'dns_record_id' => $origin['dns_record_id'] ?? null,
            'source' => (string) ($origin['source'] ?? 'manual'),
            'role' => (string) ($origin['role'] ?? 'origin'),
            'weight' => (int) ($origin['weight'] ?? 1),
            'enabled' => (bool) ($origin['enabled'] ?? true),
            'host' => (string) $origin['host'],
            'scheme' => (string) $origin['scheme'],
            'port' => (int) $origin['port'],
            'host_header' => (string) ($origin['host_header'] ?? $origin['host']),
            'sni' => (string) ($origin['sni'] ?? ''),
            'tls_verify' => (string) ($origin['tls_verify'] ?? 'ignore'),
            'preserve_host' => (bool) ($origin['preserve_host'] ?? true),
            'health_check_enabled' => (bool) ($origin['health_check_enabled'] ?? false),
            'status' => (string) $origin['health_status'],
            'health_status' => (string) $origin['health_status'],
        ];
    }

    private function originsForSnapshot(array $origins): array
    {
        $out = [];
        foreach ($origins as $origin) {
            if (empty($origin['enabled'])) {
                continue;
            }
            if (!empty($origin['health_check_enabled']) && (string) ($origin['health_status'] ?? 'unknown') === 'unhealthy') {
                continue;
            }
            $out[] = $this->originForSnapshot($origin);
        }
        usort($out, static function (array $a, array $b): int {
            $health = ['healthy' => 0, 'unknown' => 1, 'unhealthy' => 2];
            return [$health[$a['health_status']] ?? 1, $a['weight'], $a['id']]
                <=> [$health[$b['health_status']] ?? 1, $b['weight'], $b['id']];
        });
        return $out;
    }

    private function originsFromDnsRecords(array $records, string $domainHost): array
    {
        $origins = [];
        foreach ($records as $record) {
            if (empty($record['proxied']) || ($record['status'] ?? 'active') !== 'active') {
                continue;
            }
            $host = trim((string) ($record['origin_host'] ?? $record['origin_content'] ?? $record['content'] ?? ''));
            if ($host === '') {
                continue;
            }
            $scheme = $this->schemeForDnsRecord($record);
            $requestedHost = $this->recordHost($domainHost, (string) ($record['name'] ?? '')) ?? $host;
            $origins[] = [
                'id' => (string) ($record['id'] ?? ''),
                'dns_record_id' => (string) ($record['id'] ?? ''),
                'source' => 'dns_record',
                'role' => 'origin',
                'weight' => 1,
                'enabled' => true,
                'scheme' => $scheme,
                'host' => $host,
                'port' => $scheme === 'https' ? 443 : 80,
                'host_header' => $requestedHost,
                'sni' => $requestedHost,
                'tls_verify' => (string) ($record['origin_tls_verify'] ?? 'ignore'),
                'preserve_host' => true,
                'health_check_enabled' => false,
                'health_status' => (string) ($record['origin_status'] ?? 'pending'),
                'status' => (string) ($record['origin_status'] ?? 'pending'),
            ];
        }
        usort($origins, static function (array $a, array $b): int {
            $health = ['healthy' => 0, 'unknown' => 1, 'unhealthy' => 2];
            return [$health[$a['health_status']] ?? 1, $a['weight'], $a['id']]
                <=> [$health[$b['health_status']] ?? 1, $b['weight'], $b['id']];
        });
        return $origins;
    }

    private function proxiedRecordHosts(string $domainHost, array $records, array $configuredOrigins): array
    {
        $hosts = [];
        foreach ($records as $record) {
            if (empty($record['proxied']) || ($record['status'] ?? 'active') !== 'active') {
                continue;
            }
            $recordHost = $this->recordHost($domainHost, (string) ($record['name'] ?? ''));
            if ($recordHost === null) {
                continue;
            }
            $recordOrigins = $this->originsForDnsRecord((string) ($record['id'] ?? ''), $configuredOrigins);
            if ($recordOrigins === []) {
                $origin = $this->originFromDnsRecord($record, $domainHost);
                if ($origin === null) {
                    continue;
                }
                $recordOrigins = [$origin];
            }
            $hosts[$recordHost] ??= [];
            array_push($hosts[$recordHost], ...$recordOrigins);
        }
        foreach ($hosts as &$origins) {
            usort($origins, static function (array $a, array $b): int {
                $health = ['healthy' => 0, 'unknown' => 1, 'unhealthy' => 2];
                return [$health[$a['health_status']] ?? 1, $a['weight'], $a['id']]
                    <=> [$health[$b['health_status']] ?? 1, $b['weight'], $b['id']];
            });
        }
        unset($origins);
        return $hosts;
    }

    private function originsForDnsRecord(string $recordId, array $configuredOrigins): array
    {
        if ($recordId === '') {
            return [];
        }
        $origins = [];
        foreach ($configuredOrigins as $origin) {
            if ((string) ($origin['dns_record_id'] ?? '') !== $recordId || empty($origin['enabled'])) {
                continue;
            }
            if (!empty($origin['health_check_enabled']) && (string) ($origin['health_status'] ?? 'unknown') === 'unhealthy') {
                continue;
            }
            $origins[] = $this->originForSnapshot($origin);
        }
        return $origins;
    }

    private function recordHost(string $domainHost, string $name): ?string
    {
        $domainHost = strtolower(rtrim(trim($domainHost), '.'));
        if ($domainHost === '') {
            return null;
        }
        $name = strtolower(rtrim(trim($name), '.'));
        if ($name === '' || $name === '@') {
            return $domainHost;
        }
        if ($name === $domainHost || str_ends_with($name, '.' . $domainHost)) {
            return $name;
        }
        return $name . '.' . $domainHost;
    }

    private function originFromDnsRecord(array $record, string $domainHost = ''): ?array
    {
        $host = trim((string) ($record['origin_host'] ?? $record['origin_content'] ?? $record['content'] ?? ''));
        if ($host === '') {
            return null;
        }
        $scheme = $this->schemeForDnsRecord($record);
        $requestedHost = $this->recordHost($domainHost, (string) ($record['name'] ?? '')) ?? $host;
        return [
            'id' => (string) ($record['id'] ?? ''),
            'dns_record_id' => (string) ($record['id'] ?? ''),
            'source' => 'dns_record',
            'role' => 'origin',
            'weight' => 1,
            'enabled' => true,
            'scheme' => $scheme,
            'host' => $host,
            'port' => $scheme === 'https' ? 443 : 80,
            'host_header' => $requestedHost,
            'sni' => $requestedHost,
            'tls_verify' => (string) ($record['origin_tls_verify'] ?? 'ignore'),
            'preserve_host' => true,
            'health_check_enabled' => false,
            'health_status' => (string) ($record['origin_status'] ?? 'pending'),
            'status' => (string) ($record['origin_status'] ?? 'pending'),
        ];
    }

    private function dnsRecordsGeoOrigins(array $records): array
    {
        foreach ($records as $record) {
            if (!empty($record['proxied']) && ($record['status'] ?? 'active') === 'active' && is_array($record['geo_origins'] ?? null)) {
                return $record['geo_origins'];
            }
        }
        return [];
    }

    private function dnsRecordsGeoOriginsForHost(array $records, string $host, string $domainHost): array
    {
        foreach ($records as $record) {
            if (empty($record['proxied']) || ($record['status'] ?? 'active') !== 'active') {
                continue;
            }
            if ($this->recordHost($domainHost, (string) ($record['name'] ?? '')) !== $host) {
                continue;
            }
            if (is_array($record['geo_origins'] ?? null)) {
                return $record['geo_origins'];
            }
        }
        return [];
    }

    private function schemeForDnsRecord(array $record): string
    {
        $scheme = (string) ($record['origin_scheme'] ?? '');
        if ($scheme !== '') {
            return $scheme;
        }

        // Keep DNS-linked origins compatible with plain HTTP by default.
        // Users can opt into HTTPS explicitly when the backend really serves it.
        return 'http';
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
                'id' => (string) ($origin['id'] ?? 'geo-' . strtoupper(trim($key))),
                'role' => 'origin',
                'source' => 'geo_origin',
                'host' => $host,
                // Geo origins should not silently assume TLS. Mirror the
                // explicit scheme when present, otherwise stay on HTTP/80.
                'scheme' => (string) ($origin['scheme'] ?? 'http'),
                'port' => (int) ($origin['port'] ?? (((string) ($origin['scheme'] ?? 'http') === 'https') ? 443 : 80)),
                'host_header' => (string) ($origin['host_header'] ?? $host),
                'sni' => (string) ($origin['sni'] ?? ''),
                'tls_verify' => (string) ($origin['tls_verify'] ?? 'ignore'),
                'preserve_host' => (bool) ($origin['preserve_host'] ?? true),
                'health_check_enabled' => (bool) ($origin['health_check_enabled'] ?? false),
            ];
        }
        ksort($out);
        return $out;
    }

    private function selectOriginFromPool(array $origins, string $seed): ?array
    {
        if ($origins === []) {
            return null;
        }
        $healthy = [];
        $unknown = [];
        foreach ($origins as $origin) {
            $status = (string) ($origin['health_status'] ?? 'unknown');
            $checked = !empty($origin['health_check_enabled']);
            if ($status === 'healthy') {
                $healthy[] = $origin;
            } elseif (!$checked || $status !== 'unhealthy') {
                $unknown[] = $origin;
            }
        }
        $pool = $healthy !== [] ? $healthy : $unknown;
        if ($pool === []) {
            return null;
        }
        if (count($pool) === 1) {
            return $pool[0];
        }
        $hash = function_exists('crc32') ? (int) sprintf('%u', crc32($seed)) : abs((int) crc32($seed));
        $index = $hash % count($pool);
        return $pool[$index] ?? $pool[0];
    }
}
