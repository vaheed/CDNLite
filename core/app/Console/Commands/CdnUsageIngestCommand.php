<?php

namespace App\Console\Commands;

use App\Modules\Collector\Services\CollectorService;
use App\Support\CommandIO;

class CdnUsageIngestCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);

        $required = ['domain_id', 'edge_node_id', 'requests_count', 'bytes_in', 'bytes_out', 'status'];
        foreach ($required as $key) {
            if (!isset($opts[$key])) {
                fwrite(STDERR, "Missing --{$key} option\n");
                return 1;
            }
        }

        $item = [
            'ts' => isset($opts['ts']) ? (int) $opts['ts'] : time(),
            'domain_id' => (string) $opts['domain_id'],
            'edge_node_id' => (string) $opts['edge_node_id'],
            'requests_count' => (int) $opts['requests_count'],
            'bytes_in' => (int) $opts['bytes_in'],
            'bytes_out' => (int) $opts['bytes_out'],
            'status' => (int) $opts['status'],
            'cache_status' => strtoupper(trim((string) ($opts['cache_status'] ?? 'UNKNOWN'))),
        ];

        if (!in_array($item['cache_status'], ['HIT', 'MISS', 'EXPIRED', 'STALE', 'BYPASS', 'UNKNOWN'], true)) {
            fwrite(STDERR, "Invalid --cache_status; expected HIT, MISS, EXPIRED, STALE, BYPASS, or UNKNOWN\n");
            return 1;
        }

        $idempotencyKey = isset($opts['idempotency_key']) ? (string) $opts['idempotency_key'] : null;

        $result = (new CollectorService())->ingest([$item], $idempotencyKey);
        CommandIO::printJson($result);
        return 0;
    }
}
