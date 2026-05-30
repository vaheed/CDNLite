<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\DnsService;
use App\Support\CommandIO;

class CdnDnsAddRecordCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $siteId = (int) ($opts['site_id'] ?? 0);
        foreach (['type', 'name', 'content'] as $r) {
            if (empty($opts[$r])) {
                fwrite(STDERR, "Missing --{$r}\n");
                return 1;
            }
        }
        if ($siteId <= 0) {
            fwrite(STDERR, "Missing --site_id\n");
            return 1;
        }

        try {
            $row = (new DnsService())->create($siteId, [
                'type' => $opts['type'],
                'name' => $opts['name'],
                'content' => $opts['content'],
                'ttl' => (int) ($opts['ttl'] ?? 300),
                'priority' => isset($opts['priority']) ? (int) $opts['priority'] : null,
                'proxied' => ($opts['proxied'] ?? '0') !== '0',
            ]);
        } catch (\RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        }
        CommandIO::printJson(['data' => $row]);
        return 0;
    }
}
