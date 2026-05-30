<?php

namespace App\Console\Commands;

use App\Modules\Sites\Services\SiteService;
use App\Support\CommandIO;

class CdnSiteUpdateCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $siteId = (int) ($opts['id'] ?? 0);
        if ($siteId <= 0) {
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
}
