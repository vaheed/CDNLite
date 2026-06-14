<?php

namespace App\Console\Commands;

use App\Support\CommandIO;
use App\Support\DatabaseMigrator;
use Throwable;

class CdnDbStatusCommand
{
    public function __invoke(array $argv): int
    {
        try {
            $migrator = DatabaseMigrator::default();
            $status = $migrator->status();
            $compatibility = $migrator->checkCompatibility();
            CommandIO::printJson([
                'ok' => $compatibility['ok'],
                'compatibility' => $compatibility,
                'migrations' => array_map(static fn (array $migration): array => [
                    'version' => $migration['version'],
                    'name' => $migration['name'],
                    'checksum' => $migration['checksum'],
                    'status' => $migration['status'],
                    'applied' => $migration['applied'],
                ], $status),
            ]);
            return $compatibility['ok'] ? 0 : 1;
        } catch (Throwable $error) {
            CommandIO::printJson([
                'ok' => false,
                'error' => $error->getMessage(),
            ]);
            return 1;
        }
    }
}
