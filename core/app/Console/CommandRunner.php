<?php

namespace App\Console;

class CommandRunner
{
    /** @var array<string, callable> */
    private array $registry = [];

    public function register(string $signature, callable $handler): void
    {
        $this->registry[$signature] = $handler;
    }

    public function run(array $argv): int
    {
        $signature = $argv[1] ?? 'list';
        if ($signature === 'help' || $signature === '--help' || $signature === '-h') {
            fwrite(STDOUT, "Usage: php artisan <command> [--key=value]\n\n");
            fwrite(STDOUT, "Commands:\n");
            foreach (array_keys($this->registry) as $cmd) {
                fwrite(STDOUT, "  " . $cmd . PHP_EOL);
            }
            return 0;
        }

        if ($signature === 'list') {
            foreach (array_keys($this->registry) as $cmd) {
                fwrite(STDOUT, $cmd . PHP_EOL);
            }
            return 0;
        }

        if (!isset($this->registry[$signature])) {
            fwrite(STDERR, "Unknown command: {$signature}\n");
            return 1;
        }

        return (int) ($this->registry[$signature])($argv);
    }
}
