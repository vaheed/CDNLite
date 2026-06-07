<?php

namespace App\Console\Commands;

use App\Modules\Proxy\Services\OriginHealthService;
use App\Support\CommandIO;

class CdnOriginsListCommand
{
    public function __invoke(array $argv): int
    {
        $options = CommandIO::parseOptions($argv);
        if (empty($options['domain_id'])) {
            fwrite(STDERR, "Missing --domain_id\n");
            return 1;
        }
        CommandIO::printJson(['data' => (new OriginHealthService())->list((string) $options['domain_id'])]);
        return 0;
    }
}
