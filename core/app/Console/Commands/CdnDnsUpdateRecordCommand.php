<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\DnsService;
use App\Support\CommandIO;

class CdnDnsUpdateRecordCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $siteId = trim((string) ($opts['site_id'] ?? ''));
        $recordId = trim((string) ($opts['record_id'] ?? ''));
        if ($siteId === '' || $recordId === '') {
            fwrite(STDERR, "Missing --site_id or --record_id\n");
            return 1;
        }

        $patch = [];
        foreach (['type', 'name', 'content', 'status'] as $field) {
            if (isset($opts[$field])) {
                $patch[$field] = $opts[$field];
            }
        }
        if (isset($opts['ttl'])) {
            $patch['ttl'] = (int) $opts['ttl'];
        }
        if (array_key_exists('priority', $opts)) {
            $patch['priority'] = $opts['priority'] === '' || strtolower((string) $opts['priority']) === 'null'
                ? null
                : (int) $opts['priority'];
        }
        if (isset($opts['proxied'])) {
            $patch['proxied'] = $opts['proxied'] !== '0';
        }
        if ($patch === []) {
            fwrite(STDERR, "Missing update options\n");
            return 1;
        }

        try {
            $record = (new DnsService())->update($siteId, $recordId, $patch);
        } catch (\RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        }

        if ($record === null) {
            fwrite(STDERR, "Record not found\n");
            return 1;
        }

        CommandIO::printJson(['data' => $record]);
        return 0;
    }
}
