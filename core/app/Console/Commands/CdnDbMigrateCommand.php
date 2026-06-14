<?php

namespace App\Console\Commands;

use App\Support\CommandIO;
use App\Support\DatabaseMigrator;
use Throwable;

class CdnDbMigrateCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $dryRun = ($opts['dry-run'] ?? false) !== false;

        try {
            $plan = DatabaseMigrator::default()->migrate($dryRun);
            CommandIO::printJson([
                'ok' => true,
                'dry_run' => $dryRun,
                'migrations' => array_map(static fn (array $migration): array => [
                    'version' => $migration['version'],
                    'name' => $migration['name'],
                    'checksum' => $migration['checksum'],
                    'status' => $migration['status'],
                ], $plan),
            ]);
            return 0;
        } catch (Throwable $error) {
            CommandIO::printJson([
                'ok' => false,
                'error' => $error->getMessage(),
            ]);
            return 1;
        }
    }
}
