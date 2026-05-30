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
        $hosts = [];
        foreach ($this->sites->all() as $site) {
            if (empty($site['proxy_enabled'])) {
                continue;
            }

            $hosts[$site['domain']] = [
                'site_id' => (int) $site['id'],
                'upstream' => sprintf('%s://%s:%d', $site['origin_scheme'], $site['origin_host'], $site['origin_port']),
                'headers' => ['X-CDNT-Site' => (string) $site['id']],
                'dns_records' => $this->dns->listBySite((int) $site['id']),
            ];
        }

        $version = $this->nextVersion();
        return [
            'version' => $version,
            'generated_at' => time(),
            'hosts' => $hosts,
        ];
    }

    private function nextVersion(): int
    {
        $pdo = Database::pdo();
        $pdo->exec('UPDATE config_state SET version = version + 1 WHERE id = 1');
        $stmt = $pdo->query('SELECT version FROM config_state WHERE id = 1');
        $row = (array) $stmt->fetch();
        return (int) $row['version'];
    }
}
