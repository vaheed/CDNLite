<?php

namespace App\Console\Commands;

use App\Support\CommandIO;
use App\Support\Database;

class CdnEdgeDisableCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $edgeId = trim((string) ($opts['id'] ?? $opts['edge_id'] ?? ''));
        if ($edgeId === '') {
            fwrite(STDERR, "Missing --id\n");
            return 1;
        }

        $stmt = Database::pdo()->prepare('UPDATE edge_nodes SET is_enabled=false, updated_at=:updated_at WHERE id=:id OR edge_id=:edge_id');
        $stmt->execute([':updated_at' => time(), ':id' => $edgeId, ':edge_id' => $edgeId]);
        if ($stmt->rowCount() < 1) {
            fwrite(STDERR, "edge_not_found\n");
            return 1;
        }

        CommandIO::printJson(['ok' => true, 'id' => $edgeId]);
        return 0;
    }
}
