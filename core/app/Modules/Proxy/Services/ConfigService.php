<?php

namespace App\Modules\Proxy\Services;

use App\Modules\Dns\Services\DnsService;
use App\Modules\Sites\Services\SiteService;
use App\Support\Database;

class ConfigService
{
    public function __construct(
        private SiteService $sites,
        private DnsService $dns
    ) {
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
        // Keep hash deterministic for unchanged config content.
        // `generated_at` is intentionally excluded so no-op syncs reuse version.
        $contentHash = hash('sha256', json_encode(['hosts' => $hosts], JSON_UNESCAPED_SLASHES));

        $existing = $this->findByHash($contentHash);
        if ($existing !== null) {
            if ($ifVersion !== null && $ifVersion === (int) $existing['version']) {
                return ['not_modified' => true, 'version' => (int) $existing['version']];
            }

            return [
                'version' => (int) $existing['version'],
                'generated_at' => (int) $existing['generated_at'],
                'hosts' => $hosts,
                'reused' => true,
            ];
        }

        $version = $this->nextVersion();
        $payload = [
            'version' => $version,
            'generated_at' => time(),
            'hosts' => $hosts,
        ];
        $this->storeSnapshot($version, $contentHash, $payload);

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
