<?php

namespace App\Console\Commands;

use App\Modules\Domains\Services\DomainService;
use App\Support\CommandIO;

class CdnDomainActivateCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $domainId = trim((string) ($opts['id'] ?? ''));
        if ($domainId === '') {
            fwrite(STDERR, "Missing --id\n");
            return 1;
        }

        try {
            $domain = (new DomainService())->activate($domainId, ($opts['force'] ?? '0') !== '0');
        } catch (\RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        }

        if ($domain === null) {
            fwrite(STDERR, "domain_not_found\n");
            return 1;
        }

        CommandIO::printJson(['data' => $domain]);
        return 0;
    }
}
