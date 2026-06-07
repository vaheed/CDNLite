<?php

namespace App\Console\Commands;

use App\Support\CommandIO;
use App\Support\Database;

class CdnEdgeShowCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $edgeId = trim((string) ($opts['id'] ?? $opts['edge_id'] ?? ''));
        if ($edgeId === '') {
            fwrite(STDERR, "Missing --id\n");
            return 1;
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM edge_nodes WHERE id=:id OR edge_id=:edge_id LIMIT 1');
        $stmt->execute([':id' => $edgeId, ':edge_id' => $edgeId]);
        $row = $stmt->fetch();
        if (!$row) {
            fwrite(STDERR, "edge_not_found\n");
            return 1;
        }

        $row['is_enabled'] = ((int) ($row['is_enabled'] ?? 0)) === 1;
        CommandIO::printJson(['data' => $row]);
        return 0;
    }
}
