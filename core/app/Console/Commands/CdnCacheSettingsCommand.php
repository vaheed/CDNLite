<?php

namespace App\Console\Commands;

use App\Modules\Proxy\Services\TrafficRulesService;
use App\Support\CommandIO;

class CdnCacheSettingsCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $domainId = trim((string) ($opts['domain_id'] ?? ''));
        if ($domainId === '') {
            fwrite(STDERR, "Missing --domain_id\n");
            return 1;
        }

        CommandIO::printJson(['data' => (new TrafficRulesService())->getDomainCacheSettings($domainId)]);
        return 0;
    }
}
