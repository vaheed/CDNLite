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
        $opts = self::parseOptions($_SERVER['argv'] ?? []);
        if (($opts['format'] ?? 'json') === 'table') {
            self::printTable($payload);
            return;
        }
        fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    private static function printTable(array $payload): void
    {
        $data = $payload['data'] ?? $payload;
        $rows = self::tableRows($data);
        if ($rows === []) {
            fwrite(STDOUT, "No rows\n");
            return;
        }

        $columns = array_values(array_unique(array_merge(...array_map('array_keys', $rows))));
        $widths = [];
        foreach ($columns as $column) {
            $widths[$column] = strlen((string) $column);
            foreach ($rows as $row) {
                $widths[$column] = max($widths[$column], strlen(self::formatCell($row[$column] ?? '')));
            }
        }

        $line = static function (array $values) use ($columns, $widths): string {
            $cells = [];
            foreach ($columns as $column) {
                $cells[] = str_pad((string) ($values[$column] ?? ''), $widths[$column]);
            }
            return implode('  ', $cells);
        };

        fwrite(STDOUT, $line(array_combine($columns, $columns) ?: []) . PHP_EOL);
        fwrite(STDOUT, $line(array_map(static fn (int $width): string => str_repeat('-', $width), $widths)) . PHP_EOL);
        foreach ($rows as $row) {
            fwrite(STDOUT, $line(array_map([self::class, 'formatCell'], $row)) . PHP_EOL);
        }
    }

    private static function tableRows(mixed $data): array
    {
        if (!is_array($data)) {
            return [['value' => $data]];
        }
        if ($data === []) {
            return [];
        }
        if (array_is_list($data)) {
            return array_map(static fn (mixed $row): array => is_array($row) ? $row : ['value' => $row], $data);
        }
        return [$data];
    }

    private static function formatCell(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '';
        }
        return (string) $value;
    }
}
