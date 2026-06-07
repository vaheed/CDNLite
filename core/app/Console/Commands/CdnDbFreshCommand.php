<?php

namespace App\Console\Commands;

use App\Support\CommandIO;
use App\Support\Database;

class CdnDbFreshCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        if (($opts['force'] ?? false) === false) {
            fwrite(STDERR, "Refusing destructive reset without --force\n");
            return 1;
        }

        $pdo = Database::pdo();
        $pdo->exec("DROP SCHEMA public CASCADE; CREATE SCHEMA public");
        $schema = file_get_contents(dirname(__DIR__, 3) . '/database/schema.sql');
        if ($schema === false) {
            fwrite(STDERR, "schema_not_readable\n");
            return 1;
        }
        $pdo->exec($schema);
        CommandIO::printJson(['ok' => true, 'reset' => true]);
        return 0;
    }
}
