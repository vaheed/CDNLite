<?php

namespace App\Console\Commands;

use App\Modules\Domains\Services\DomainService;
use App\Support\CommandIO;

class CdnDomainUpdateCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $domainId = trim((string) ($opts['id'] ?? ''));
        if ($domainId === '') {
            fwrite(STDERR, "Missing --id\n");
            return 1;
        }

        $patch = [];
        foreach (['name', 'domain', 'status'] as $f) {
            if (isset($opts[$f])) {
                $patch[$f] = $opts[$f];
            }
        }

        $domain = (new DomainService())->update($domainId, $patch);
        if ($domain === null) {
            fwrite(STDERR, "Domain not found\n");
            return 1;
        }

        CommandIO::printJson(['data' => $domain]);
        return 0;
    }

}
