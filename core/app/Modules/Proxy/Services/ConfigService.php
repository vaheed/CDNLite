<?php

namespace App\Modules\Proxy\Services;

use App\Modules\Dns\Services\DnsService;
use App\Modules\Domains\Services\DomainService;
use App\Services\ControlPlane\TrafficRulesService;
use App\Support\AuditLog;
use App\Support\Database;

class ConfigService
{
    private bool $publishLockHeld = false;

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
        return $this->publishSnapshot(false);
    }

    public function buildSnapshotForVersion(?int $ifVersion = null): array
    {
        return $this->edgeConfig($ifVersion);
    }

    public function rebuild(?int $ifVersion = null): array
    {
        $payload = $this->publishSnapshot(true);
        if ($ifVersion !== null && isset($payload['version']) && $ifVersion === (int) $payload['version']) {
            return ['not_modified' => true, 'version' => (int) $payload['version']];
        }
        return $payload;
    }

    public function edgeConfig(?int $ifVersion = null): array
    {
        $this->ensureStateRow();
        $state = $this->configState();
        $activeVersion = $state['active_snapshot_version'];
        $active = $this->activeSnapshot();

        if ($activeVersion !== null && $active !== null && !$state['dirty']) {
            if ($ifVersion !== null && $ifVersion === $activeVersion) {
                return ['not_modified' => true, 'version' => $activeVersion];
            }
            return $active['payload'];
        }

        if ($this->tryPublishLock()) {
            try {
                return $this->publishSnapshot(false, true);
            } finally {
                $this->unlockPublish();
            }
        }

        if ($activeVersion !== null && $active !== null) {
            if ($ifVersion !== null && $ifVersion === $activeVersion) {
                return ['not_modified' => true, 'version' => $activeVersion, 'stale_while_rebuilding' => true];
            }
            $payload = $active['payload'];
            $payload['stale_while_rebuilding'] = true;
            return $payload;
        }

        return ['error' => 'config_publish_in_progress', 'status' => 503];
    }

    public function publishSnapshot(bool $force = false, bool $lockAlreadyHeld = false): array
    {
        $this->ensureStateRow();
        $state = $this->configState();
        $active = $this->activeSnapshot();
        if (!$force && !$state['dirty'] && $active !== null) {
            return $active['payload'];
        }

        if (!$lockAlreadyHeld && !$this->tryPublishLock()) {
            if ($active !== null) {
                $payload = $active['payload'];
                $payload['stale_while_rebuilding'] = true;
                return $payload;
            }
            return ['error' => 'config_publish_in_progress', 'status' => 503];
        }

        try {
            $this->setPublishingStartedAt(time());
            $payload = $this->buildAndStoreSnapshot();
            $this->markPublished((int) ($payload['generated_at'] ?? time()));
            $this->pruneSnapshots($this->snapshotKeepLast(), $this->snapshotPruneBatchSize());
            return $payload;
        } catch (\Throwable $e) {
            $this->markPublishFailed($e->getMessage());
            AuditLog::write('config.publish.failed', 'config_snapshot', null, null, null, ['error' => $e->getMessage()]);
            throw $e;
        } finally {
            $this->clearPublishingStartedAt();
            if (!$lockAlreadyHeld) {
                $this->unlockPublish();
            }
        }
    }

    public function buildPayload(): array
    {
        return $this->buildPayloadData();
    }

    public static function markDirty(?string $reason = null): void
    {
        $pdo = Database::pdo();
        $pdo->exec('INSERT INTO config_state (id, version) VALUES (1, 0) ON CONFLICT (id) DO NOTHING');
        $wasDirty = in_array($pdo->query('SELECT dirty FROM config_state WHERE id = 1')->fetchColumn(), [true, 1, '1', 't', 'true'], true);
        $stmt = $pdo->prepare(
            'UPDATE config_state
             SET dirty = true, dirty_at = :dirty_at
             WHERE id = 1'
        );
        $stmt->execute([':dirty_at' => time()]);
        if (!$wasDirty) {
            AuditLog::write('config.dirty', 'config_state', '1', null, ['dirty' => false], ['dirty' => true, 'reason' => $reason]);
        }
    }

    private function buildAndStoreSnapshot(): array
    {
        $previousActiveVersion = $this->activeSnapshotVersion();
        $payloadData = $this->buildPayloadData();
        $contentHash = $this->contentHash($payloadData);
        $existing = $this->findReusableActiveSnapshot($previousActiveVersion, $contentHash);
        if ($existing !== null) {
            $payload = json_decode((string) $existing['payload_json'], true) ?: $payloadData;
            $payload['reused'] = true;
            $this->activateSnapshotVersion((int) $existing['version']);
            $this->auditSnapshotPublish((int) $existing['version'], $previousActiveVersion, (int) $existing['version'], true);
            return $payload;
        }

        $version = $this->nextVersion();
        $payload = $payloadData + ['version' => $version, 'generated_at' => time()];
        $this->storeSnapshot($version, $contentHash, $payload);
        $this->activateSnapshotVersion($version);
        $this->auditSnapshotPublish($version, $previousActiveVersion, $version, false);
        return $payload;
    }

    private function buildPayloadData(): array
    {
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
        return [
            'schema_version' => 1,
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
    }

    public function snapshots(int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $stmt = Database::pdo()->prepare(
            'SELECT s.version,s.generated_at,s.content_hash,pg_column_size(s.payload_json) AS size,
                    (s.version=cs.active_snapshot_version) AS active
             FROM config_snapshots s CROSS JOIN config_state cs
             WHERE cs.id=1 ORDER BY s.version DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
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

    public function activeSnapshot(): ?array
    {
        $row = Database::pdo()->query(
            'SELECT s.version,s.payload_json FROM config_state cs
             JOIN config_snapshots s ON s.version=cs.active_snapshot_version WHERE cs.id=1'
        )->fetch();
        return $row ? ['version' => (int) $row['version'], 'payload' => json_decode((string) $row['payload_json'], true)] : null;
    }

    public function pruneSnapshots(int $keepLast = 2, ?int $batchSize = null, bool $dryRun = false): array
    {
        $keepLast = max(0, $keepLast);
        $batchSize = $batchSize === null ? null : max(1, $batchSize);
        $activeVersion = $this->activeSnapshotVersion();
        $limitClause = $batchSize === null ? '' : ' LIMIT :batch_size';
        $sql = $dryRun
            ? "WITH keep_versions AS (
                   SELECT active_snapshot_version AS version FROM config_state WHERE id = 1 AND active_snapshot_version IS NOT NULL
                   UNION
                   SELECT version FROM config_snapshots ORDER BY version DESC LIMIT :keep_last
               ),
               victims AS (
                   SELECT version FROM config_snapshots
                   WHERE version NOT IN (SELECT version FROM keep_versions)
                   ORDER BY version ASC{$limitClause}
               )
               SELECT COUNT(*) AS deleted_count FROM victims"
            : "WITH keep_versions AS (
                   SELECT active_snapshot_version AS version FROM config_state WHERE id = 1 AND active_snapshot_version IS NOT NULL
                   UNION
                   SELECT version FROM config_snapshots ORDER BY version DESC LIMIT :keep_last
               ),
               victims AS (
                   SELECT version FROM config_snapshots
                   WHERE version NOT IN (SELECT version FROM keep_versions)
                   ORDER BY version ASC{$limitClause}
               ),
               deleted AS (
                   DELETE FROM config_snapshots
                   WHERE version IN (SELECT version FROM victims)
                   RETURNING version
               )
               SELECT COUNT(*) AS deleted_count FROM deleted";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->bindValue(':keep_last', $keepLast, \PDO::PARAM_INT);
        if ($batchSize !== null) {
            $stmt->bindValue(':batch_size', $batchSize, \PDO::PARAM_INT);
        }
        $stmt->execute();
        $deletedCount = (int) ($stmt->fetchColumn() ?: 0);
        $remaining = (int) (Database::pdo()->query('SELECT COUNT(*) FROM config_snapshots')->fetchColumn() ?: 0);
        if (!$dryRun) {
            AuditLog::write('config.snapshots.prune', 'config_snapshot', null, null, null, ['deleted_count' => $deletedCount, 'keep_last' => $keepLast, 'active_version' => $activeVersion]);
        }
        return [
            'ok' => true,
            'dry_run' => $dryRun,
            'deleted_count' => $deletedCount,
            'keep_last' => $keepLast,
            'active_version' => $activeVersion,
            'remaining_count_estimate' => $dryRun ? max(0, $remaining - $deletedCount) : $remaining,
        ];
    }

    private function ensureStateRow(): void
    {
        Database::pdo()->exec('INSERT INTO config_state (id, version) VALUES (1, 0) ON CONFLICT (id) DO NOTHING');
    }

    private function configState(): array
    {
        $this->ensureStateRow();
        $row = Database::pdo()->query('SELECT version, active_snapshot_version, dirty, dirty_at, published_at, last_publish_error, publishing_started_at FROM config_state WHERE id = 1')->fetch();
        return [
            'version' => (int) ($row['version'] ?? 0),
            'active_snapshot_version' => isset($row['active_snapshot_version']) && $row['active_snapshot_version'] !== null ? (int) $row['active_snapshot_version'] : null,
            'dirty' => in_array($row['dirty'] ?? true, [true, 1, '1', 't', 'true'], true),
            'dirty_at' => isset($row['dirty_at']) && $row['dirty_at'] !== null ? (int) $row['dirty_at'] : null,
            'published_at' => isset($row['published_at']) && $row['published_at'] !== null ? (int) $row['published_at'] : null,
            'last_publish_error' => isset($row['last_publish_error']) ? (string) $row['last_publish_error'] : null,
            'publishing_started_at' => isset($row['publishing_started_at']) && $row['publishing_started_at'] !== null ? (int) $row['publishing_started_at'] : null,
        ];
    }

    private function tryPublishLock(): bool
    {
        $locked = Database::pdo()->query("SELECT pg_try_advisory_lock(hashtext('cdnlite_config_publish'))")->fetchColumn();
        $this->publishLockHeld = in_array($locked, [true, 1, '1', 't', 'true'], true);
        return $this->publishLockHeld;
    }

    private function unlockPublish(): void
    {
        if (!$this->publishLockHeld) {
            return;
        }
        Database::pdo()->query("SELECT pg_advisory_unlock(hashtext('cdnlite_config_publish'))");
        $this->publishLockHeld = false;
    }

    private function setPublishingStartedAt(int $time): void
    {
        Database::pdo()->prepare('UPDATE config_state SET publishing_started_at = :time WHERE id = 1')->execute([':time' => $time]);
    }

    private function clearPublishingStartedAt(): void
    {
        Database::pdo()->exec('UPDATE config_state SET publishing_started_at = NULL WHERE id = 1');
    }

    private function markPublished(int $publishedAt): void
    {
        Database::pdo()->prepare(
            'UPDATE config_state
             SET dirty = false, published_at = :published_at, last_publish_error = NULL
             WHERE id = 1'
        )->execute([':published_at' => $publishedAt]);
    }

    private function markPublishFailed(string $error): void
    {
        Database::pdo()->prepare(
            'UPDATE config_state
             SET dirty = true, last_publish_error = :error
             WHERE id = 1'
        )->execute([':error' => substr($error, 0, 4000)]);
    }

    private function snapshotKeepLast(): int
    {
        return max(0, (int) (getenv('CDNLITE_CONFIG_SNAPSHOT_KEEP_LAST') ?: 2));
    }

    private function snapshotPruneBatchSize(): ?int
    {
        $value = trim((string) (getenv('CDNLITE_CONFIG_SNAPSHOT_PRUNE_BATCH_SIZE') ?: '5000'));
        return $value === '' || (int) $value <= 0 ? null : (int) $value;
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

    private function contentHash(array $payloadData): string
    {
        // generated_at/version are publish metadata; the hash tracks only edge behavior.
        return hash('sha256', json_encode($payloadData, JSON_UNESCAPED_SLASHES));
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
        $this->ensureStateRow();
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
            'role' => (string) ($origin['role'] ?? 'primary'),
            'weight' => (int) ($origin['weight'] ?? 1),
            'load_balancing_algorithm' => (string) ($origin['load_balancing_algorithm'] ?? 'weighted_hash'),
            'enabled' => (bool) ($origin['enabled'] ?? true),
            'host' => (string) $origin['host'],
            'scheme' => (string) $origin['scheme'],
            'port' => (int) $origin['port'],
            'host_header' => (string) ($origin['host_header'] ?? $origin['host']),
            'sni' => (string) ($origin['sni'] ?? ''),
            'tls_verify' => (string) ($origin['tls_verify'] ?? 'ignore'),
            'preserve_host' => (bool) ($origin['preserve_host'] ?? true),
            'health_check_enabled' => (bool) ($origin['health_check_enabled'] ?? false),
            'health_check_path' => (string) ($origin['health_check_path'] ?? '/'),
            'health_check_interval_seconds' => (int) ($origin['health_check_interval_seconds'] ?? 30),
            'health_check_timeout_seconds' => (int) ($origin['health_check_timeout_seconds'] ?? 5),
            'connection_timeout_seconds' => (int) ($origin['connection_timeout_seconds'] ?? 5),
            'response_timeout_seconds' => (int) ($origin['response_timeout_seconds'] ?? 30),
            'retry_attempts' => (int) ($origin['retry_attempts'] ?? 1),
            'retry_budget_per_minute' => (int) ($origin['retry_budget_per_minute'] ?? 60),
            'circuit_breaker_enabled' => (bool) ($origin['circuit_breaker_enabled'] ?? true),
            'circuit_failure_threshold' => (int) ($origin['circuit_failure_threshold'] ?? 5),
            'circuit_recovery_seconds' => (int) ($origin['circuit_recovery_seconds'] ?? 30),
            'max_concurrent_requests' => (int) ($origin['max_concurrent_requests'] ?? 0),
            'drain' => (bool) ($origin['drain'] ?? false),
            'shield_enabled' => (bool) ($origin['shield_enabled'] ?? false),
            'status' => (string) $origin['health_status'],
            'health_status' => (string) $origin['health_status'],
        ];
    }

    private function originsForSnapshot(array $origins): array
    {
        $out = [];
        foreach ($origins as $origin) {
            if (empty($origin['enabled']) || !empty($origin['drain'])) {
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
                'role' => 'primary',
                'weight' => 1,
                'load_balancing_algorithm' => 'weighted_hash',
                'enabled' => true,
                'scheme' => $scheme,
                'host' => $host,
                'port' => $scheme === 'https' ? 443 : 80,
                'host_header' => $requestedHost,
                'sni' => $requestedHost,
                'tls_verify' => (string) ($record['origin_tls_verify'] ?? 'ignore'),
                'preserve_host' => true,
                'health_check_enabled' => false,
                'health_check_path' => '/',
                'health_check_interval_seconds' => 30,
                'health_check_timeout_seconds' => 5,
                'connection_timeout_seconds' => 5,
                'response_timeout_seconds' => 30,
                'retry_attempts' => 1,
                'retry_budget_per_minute' => 60,
                'circuit_breaker_enabled' => true,
                'circuit_failure_threshold' => 5,
                'circuit_recovery_seconds' => 30,
                'max_concurrent_requests' => 0,
                'drain' => false,
                'shield_enabled' => false,
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
            if ((string) ($origin['dns_record_id'] ?? '') !== $recordId || empty($origin['enabled']) || !empty($origin['drain'])) {
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
            'role' => 'primary',
            'weight' => 1,
            'load_balancing_algorithm' => 'weighted_hash',
            'enabled' => true,
            'scheme' => $scheme,
            'host' => $host,
            'port' => $scheme === 'https' ? 443 : 80,
            'host_header' => $requestedHost,
            'sni' => $requestedHost,
            'tls_verify' => (string) ($record['origin_tls_verify'] ?? 'ignore'),
            'preserve_host' => true,
            'health_check_enabled' => false,
            'health_check_path' => '/',
            'health_check_interval_seconds' => 30,
            'health_check_timeout_seconds' => 5,
            'connection_timeout_seconds' => 5,
            'response_timeout_seconds' => 30,
            'retry_attempts' => 1,
            'retry_budget_per_minute' => 60,
            'circuit_breaker_enabled' => true,
            'circuit_failure_threshold' => 5,
            'circuit_recovery_seconds' => 30,
            'max_concurrent_requests' => 0,
            'drain' => false,
            'shield_enabled' => false,
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
                'role' => 'primary',
                'source' => 'geo_origin',
                'load_balancing_algorithm' => 'weighted_hash',
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
                'health_check_path' => (string) ($origin['health_check_path'] ?? '/'),
                'health_check_interval_seconds' => (int) ($origin['health_check_interval_seconds'] ?? 30),
                'health_check_timeout_seconds' => (int) ($origin['health_check_timeout_seconds'] ?? 5),
                'connection_timeout_seconds' => (int) ($origin['connection_timeout_seconds'] ?? 5),
                'response_timeout_seconds' => (int) ($origin['response_timeout_seconds'] ?? 30),
                'retry_attempts' => (int) ($origin['retry_attempts'] ?? 1),
                'retry_budget_per_minute' => (int) ($origin['retry_budget_per_minute'] ?? 60),
                'circuit_breaker_enabled' => (bool) ($origin['circuit_breaker_enabled'] ?? true),
                'circuit_failure_threshold' => (int) ($origin['circuit_failure_threshold'] ?? 5),
                'circuit_recovery_seconds' => (int) ($origin['circuit_recovery_seconds'] ?? 30),
                'max_concurrent_requests' => (int) ($origin['max_concurrent_requests'] ?? 0),
                'drain' => (bool) ($origin['drain'] ?? false),
                'shield_enabled' => (bool) ($origin['shield_enabled'] ?? false),
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
