<?php

namespace App\Console\Commands;

use App\Support\CommandIO;

class CdnMigrateCommand
{
    public function __invoke(array $argv): int
    {
        CommandIO::printJson([
            'ok' => true,
            'fresh_install_only' => true,
            'message' => 'The canonical schema is applied automatically; historical migrations are unsupported.',
        ]);
        return 0;
    }
}
