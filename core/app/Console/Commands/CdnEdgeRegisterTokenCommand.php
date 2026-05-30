<?php

namespace App\Console\Commands;

use App\Modules\Edge\Services\EdgeService;
use App\Support\CommandIO;

class CdnEdgeRegisterTokenCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $edgeId = (string) ($opts['edge_id'] ?? '');
        $token = (string) ($opts['token'] ?? '');
        if ($edgeId === '' || $token === '') {
            fwrite(STDERR, "Missing --edge_id or --token\n");
            return 1;
        }

        (new EdgeService())->registerToken($edgeId, $token);

        CommandIO::printJson(['ok' => true, 'edge_id' => $edgeId]);
        return 0;
    }
}
