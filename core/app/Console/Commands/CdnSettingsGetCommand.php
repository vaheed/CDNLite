<?php

namespace App\Console\Commands;

use App\Modules\Settings\Repositories\SettingsRepository;
use App\Support\CommandIO;

class CdnSettingsGetCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $settings = new SettingsRepository();
        try {
            $payload = isset($opts['group'])
                ? $settings->group((string) $opts['group'])
                : $settings->groups();
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        }

        CommandIO::printJson(['data' => $payload]);
        return 0;
    }
}
