<?php

namespace App\Console\Commands;

use App\Modules\Sites\Services\SiteService;
use App\Support\CommandIO;

class CdnSiteCreateCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        foreach (['name', 'domain', 'origin_host'] as $required) {
            if (empty($opts[$required])) {
                fwrite(STDERR, "Missing --{$required}\n");
                return 1;
            }
        }

        $site = (new SiteService())->create([
            'name' => $opts['name'],
            'domain' => $opts['domain'],
            'origin_host' => $opts['origin_host'],
            'origin_port' => (int) ($opts['origin_port'] ?? 8080),
            'origin_scheme' => $opts['origin_scheme'] ?? 'http',
            'geo_origins' => $this->parseGeoOrigins($opts['geo_origins_json'] ?? null),
            'proxy_enabled' => ($opts['proxy_enabled'] ?? '1') !== '0',
            'user_id' => (int) ($opts['user_id'] ?? 1),
        ]);

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
