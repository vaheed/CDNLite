<?php

namespace App\Console\Commands;

use App\Modules\Domains\Services\DomainService;
use App\Support\CommandIO;

class CdnDomainCreateCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        foreach (['name', 'domain'] as $required) {
            if (empty($opts[$required])) {
                fwrite(STDERR, "Missing --{$required}\n");
                return 1;
            }
        }

        $domain = (new DomainService())->create([
            'name' => $opts['name'],
            'domain' => $opts['domain'],
            'user_id' => isset($opts['user_id']) ? (string) $opts['user_id'] : null,
        ]);

        CommandIO::printJson(['data' => $domain]);
        return 0;
    }

}
