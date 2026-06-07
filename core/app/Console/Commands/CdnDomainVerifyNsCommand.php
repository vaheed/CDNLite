<?php

namespace App\Console\Commands;

use App\Modules\Domains\Services\DomainVerificationService;
use App\Support\CommandIO;

class CdnDomainVerifyNsCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $domainId = trim((string) ($opts['id'] ?? ''));
        if ($domainId === '') {
            fwrite(STDERR, "Missing --id\n");
            return 1;
        }

        $domain = (new DomainVerificationService())->verify($domainId);
        if ($domain === null) {
            fwrite(STDERR, "domain_not_found\n");
            return 1;
        }

        CommandIO::printJson(['data' => $domain]);
        return 0;
    }
}
