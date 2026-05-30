<?php

namespace App\Support;

class CommandIO
{
    public static function parseOptions(array $argv): array
    {
        $opts = [];
        foreach ($argv as $arg) {
            if (!str_starts_with($arg, '--')) {
                continue;
            }
            $pair = explode('=', substr($arg, 2), 2);
            $opts[$pair[0]] = $pair[1] ?? true;
        }
        return $opts;
    }

    public static function printJson(array $payload): void
    {
        fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
}
