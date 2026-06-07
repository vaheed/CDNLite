<?php

namespace App\Console\Commands;

use App\Modules\Domains\Services\DomainService;
use App\Support\CommandIO;

class CdnDomainShowCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $domainId = trim((string) ($opts['id'] ?? ''));
        if ($domainId === '') {
            fwrite(STDERR, "Missing --id\n");
            return 1;
        }

        $domain = (new DomainService())->find($domainId);
        if ($domain === null) {
            fwrite(STDERR, "domain_not_found\n");
            return 1;
        }

        CommandIO::printJson(['data' => $domain]);
        return 0;
    }
}
