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
                'site_id' => (int) $site['id'],
                'upstream' => sprintf('%s://%s:%d', $site['origin_scheme'], $site['origin_host'], $site['origin_port']),
                'headers' => ['X-CDNLITE-Site' => (string) $site['id']],
                'dns_records' => $this->dns->listBySite((int) $site['id']),
            ];
        }

        ksort($hosts);
        $generatedAt = time();
        $basePayload = [
            'generated_at' => $generatedAt,
            'hosts' => $hosts,
        ];
        $contentHash = hash('sha256', json_encode($basePayload, JSON_UNESCAPED_SLASHES));

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
}
