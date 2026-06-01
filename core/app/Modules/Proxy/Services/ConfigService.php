<?php

namespace App\Modules\Proxy\Services;

use App\Modules\Dns\Services\DnsService;
use App\Modules\Sites\Services\SiteService;
use App\Support\Database;

class ConfigService
{
    public function __construct(
        private SiteService $sites,
        private DnsService $dns,
        private ?TrafficRulesService $rules = null
    ) {
        $this->rules ??= new TrafficRulesService();
    }

    public function buildSnapshot(): array
    {
        return $this->buildSnapshotForVersion(null);
    }

    public function buildSnapshotForVersion(?int $ifVersion = null): array
    {
        $hosts = [];
        foreach ($this->sites->all() as $site) {
            if (empty($site['proxy_enabled'])) {
                continue;
            }

            $hosts[$site['domain']] = [
                'site_id' => (string) $site['id'],
                'upstream' => sprintf('%s://%s:%d', $site['origin_scheme'], $site['origin_host'], $site['origin_port']),
                'geo_upstreams' => $this->buildGeoUpstreams($site['geo_origins'] ?? []),
                'cache_rules' => ['enabled' => false, 'rules' => []],
                'headers' => ['X-CDNLITE-Site' => (string) $site['id']],
                'dns_records' => $this->dns->listBySite((string) $site['id']),
            ];
        }

        ksort($hosts);
        $redirects = [];
        $rateLimits = [];
        $wafRules = [];
        $cacheRules = [];
        $cachePurgeVersions = [];
        $pageRules = [];
        $sslCertificates = [];
        foreach ($hosts as $host => $siteCfg) {
            $siteId = (string) $siteCfg['site_id'];
            foreach ($this->rules->listRedirects($siteId) as $row) { if (!empty($row['enabled'])) { $row['host'] = $host; $redirects[] = $row; } }
            $rl = $this->rules->getRateLimit($siteId); if ($rl !== null && !empty($rl['enabled'])) { $rl['host'] = $host; $rateLimits[] = $rl; }
            foreach ($this->rules->listWaf($siteId) as $row) { if (!empty($row['enabled'])) { $row['host'] = $host; $wafRules[] = $row; } }
            foreach ($this->rules->listCacheRules($siteId) as $row) { if (!empty($row['enabled'])) { $row['host'] = $host; $cacheRules[] = $row; } }
            foreach ($this->rules->listCachePurgeVersionsForConfig($siteId, $host) as $row) { $cachePurgeVersions[] = $row; }
            foreach ($this->rules->listPageRules($siteId) as $row) { if (!empty($row['enabled'])) { $row['host'] = $host; $pageRules[] = $row; } }
            foreach ($this->rules->listSslCertificatesForConfig($siteId, $host) as $row) { $sslCertificates[] = $row; }
        }
        // Keep hash deterministic for unchanged config content.
        // `generated_at` is intentionally excluded so no-op syncs reuse version.
        $contentHash = hash('sha256', json_encode(['hosts' => $hosts, 'redirects' => $redirects, 'rate_limits' => $rateLimits, 'waf_rules' => $wafRules, 'cache_rules' => $cacheRules, 'cache_purge_versions' => $cachePurgeVersions, 'page_rules' => $pageRules, 'ssl_certificates' => $sslCertificates], JSON_UNESCAPED_SLASHES));

        $existing = $this->findByHash($contentHash);
        if ($existing !== null) {
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
            'cache_rules' => $cacheRules,
            'cache_purge_versions' => $cachePurgeVersions,
            'page_rules' => $pageRules,
            'ssl_certificates' => $sslCertificates,
        ];
        if (!$this->storeSnapshot($version, $contentHash, $payload)) {
            $existing = $this->findByHash($contentHash);
            if ($existing !== null) {
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
                    'cache_rules' => $cacheRules,
                    'cache_purge_versions' => $cachePurgeVersions,
                    'page_rules' => $pageRules,
                    'ssl_certificates' => $sslCertificates,
                    'reused' => true,
                ];
            }

            throw new \RuntimeException('config_snapshot_store_failed');
        }

        if ($ifVersion !== null && $ifVersion === $version) {
            return ['not_modified' => true, 'version' => $version];
        }

        return $payload;
    }

    private function nextVersion(): int
    {
        $pdo = Database::pdo();
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

    private function buildGeoUpstreams(array $geoOrigins): array
    {
        $out = [];
        foreach ($geoOrigins as $key => $origin) {
            if (!is_string($key) || !is_array($origin)) {
                continue;
            }
            $scheme = isset($origin['scheme']) ? (string) $origin['scheme'] : 'http';
            $host = isset($origin['host']) ? (string) $origin['host'] : '';
            $port = isset($origin['port']) ? (int) $origin['port'] : 0;
            if ($host === '' || $port <= 0) {
                continue;
            }
            $out[strtoupper(trim($key))] = sprintf('%s://%s:%d', $scheme, $host, $port);
        }
        ksort($out);
        return $out;
    }
}
