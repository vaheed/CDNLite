<?php

namespace App\Console\Commands;

use App\Support\CommandIO;

class CdnBootstrapFreshCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $mode = (string) ($opts['seed-settings'] ?? $opts['seed_settings'] ?? 'dev');
        if ($mode !== 'dev') {
            fwrite(STDERR, "Unsupported --seed-settings; expected dev\n");
            return 1;
        }

        CommandIO::printJson(['ok' => true, 'seed_settings' => $mode]);
        return 0;
    }
}
