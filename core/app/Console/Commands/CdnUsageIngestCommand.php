<?php

namespace App\Console\Commands;

use App\Modules\Collector\Services\CollectorService;
use App\Support\CommandIO;

class CdnUsageIngestCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);

        $required = ['site_id', 'edge_node_id', 'requests_count', 'bytes_in', 'bytes_out', 'status'];
        foreach ($required as $key) {
            if (!isset($opts[$key])) {
                fwrite(STDERR, "Missing --{$key} option\n");
                return 1;
            }
        }

        $item = [
            'ts' => isset($opts['ts']) ? (int) $opts['ts'] : time(),
            'site_id' => (int) $opts['site_id'],
            'edge_node_id' => (string) $opts['edge_node_id'],
            'requests_count' => (int) $opts['requests_count'],
            'bytes_in' => (int) $opts['bytes_in'],
            'bytes_out' => (int) $opts['bytes_out'],
            'status' => (int) $opts['status'],
        ];

        $idempotencyKey = isset($opts['idempotency_key']) ? (string) $opts['idempotency_key'] : null;

        $result = (new CollectorService())->ingest([$item], $idempotencyKey);
        CommandIO::printJson($result);
        return 0;
    }
}
