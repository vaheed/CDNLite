<?php

namespace App\Console\Commands;

use App\Modules\Sites\Services\SiteService;
use App\Support\CommandIO;

class CdnSiteListCommand
{
    public function __invoke(array $argv): int
    {
        CommandIO::printJson(['data' => (new SiteService())->all()]);
        return 0;
    }
}
