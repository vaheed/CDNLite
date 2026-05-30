<?php

namespace App\Console\Commands;

use App\Modules\Edge\Services\EdgeService;
use App\Support\CommandIO;

class CdnEdgeRotateTokenCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $edgeId = (string) ($opts['edge_id'] ?? '');
        if ($edgeId === '') {
            fwrite(STDERR, "Missing --edge_id\n");
            return 1;
        }

        $token = bin2hex(random_bytes(16));
        (new EdgeService())->registerToken($edgeId, $token);
        CommandIO::printJson([
            'ok' => true,
            'edge_id' => $edgeId,
            'token' => $token,
            'rotated_at' => time(),
        ]);
        return 0;
    }
}
