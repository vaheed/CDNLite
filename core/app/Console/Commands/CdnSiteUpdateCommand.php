<?php

namespace App\Console\Commands;

use App\Modules\Sites\Services\SiteService;
use App\Support\CommandIO;

class CdnSiteUpdateCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $siteId = trim((string) ($opts['id'] ?? ''));
        if ($siteId === '') {
            fwrite(STDERR, "Missing --id\n");
            return 1;
        }

        $patch = [];
        foreach (['name', 'domain', 'origin_scheme', 'origin_host', 'status'] as $f) {
            if (isset($opts[$f])) {
                $patch[$f] = $opts[$f];
            }
        }
        if (isset($opts['origin_port'])) {
            $patch['origin_port'] = (int) $opts['origin_port'];
        }
        if (isset($opts['geo_origins_json'])) {
            $patch['geo_origins'] = $this->parseGeoOrigins($opts['geo_origins_json']);
        }
        if (isset($opts['proxy_enabled'])) {
            $patch['proxy_enabled'] = $opts['proxy_enabled'] !== '0';
        }

        $site = (new SiteService())->update($siteId, $patch);
        if ($site === null) {
            fwrite(STDERR, "Site not found\n");
            return 1;
        }

        CommandIO::printJson(['data' => $site]);
        return 0;
    }

    private function parseGeoOrigins(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }
}
