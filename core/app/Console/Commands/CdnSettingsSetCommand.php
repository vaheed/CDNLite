<?php

namespace App\Console\Commands;

use App\Modules\Settings\Repositories\SettingsRepository;
use App\Support\CommandIO;

class CdnSettingsSetCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $key = trim((string) ($opts['key'] ?? ''));
        if ($key === '' || !array_key_exists('value', $opts)) {
            fwrite(STDERR, "Missing --key or --value\n");
            return 1;
        }
        $dot = strrpos($key, '.');
        if ($dot === false) {
            fwrite(STDERR, "Invalid --key; expected group.name\n");
            return 1;
        }

        $group = substr($key, 0, $dot);
        $name = substr($key, $dot + 1);
        $raw = (string) $opts['value'];
        $decoded = json_decode($raw, true);
        $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;

        try {
            $result = (new SettingsRepository())->patch($group, [$name => $value], 'cli');
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        }

        CommandIO::printJson(['data' => $result]);
        return 0;
    }
}
