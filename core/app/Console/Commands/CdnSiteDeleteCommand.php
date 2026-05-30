<?php

namespace App\Console\Commands;

use App\Modules\Sites\Services\SiteService;
use App\Support\CommandIO;

class CdnSiteDeleteCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $siteId = (int) ($opts['id'] ?? 0);
        if ($siteId <= 0) {
            fwrite(STDERR, "Missing --id\n");
            return 1;
        }

        $ok = (new SiteService())->delete($siteId);
        if (!$ok) {
            fwrite(STDERR, "Site not found\n");
            return 1;
        }

        CommandIO::printJson(['ok' => true]);
        return 0;
    }
}
