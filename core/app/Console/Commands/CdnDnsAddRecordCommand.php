<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\DnsService;
use App\Support\CommandIO;

class CdnDnsAddRecordCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $domainId = trim((string) ($opts['domain_id'] ?? ''));
        foreach (['type', 'name', 'content'] as $r) {
            if (empty($opts[$r])) {
                fwrite(STDERR, "Missing --{$r}\n");
                return 1;
            }
        }
        if ($domainId === '') {
            fwrite(STDERR, "Missing --domain_id\n");
            return 1;
        }

        try {
            $row = (new DnsService())->create($domainId, [
                'type' => $opts['type'],
                'name' => $opts['name'],
                'content' => $opts['content'],
                'ttl' => (int) ($opts['ttl'] ?? 300),
                'priority' => isset($opts['priority']) ? (int) $opts['priority'] : null,
                'proxied' => ($opts['proxied'] ?? '0') !== '0',
                'geo_policy_id' => $opts['geo_policy_id'] ?? null,
                'edge_target' => $opts['edge_target'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        }
        CommandIO::printJson(['data' => $row]);
        return 0;
    }
}
