<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\DnsService;
use App\Support\CommandIO;

class CdnDnsUpdateRecordCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $domainId = trim((string) ($opts['domain_id'] ?? ''));
        $recordId = trim((string) ($opts['record_id'] ?? ''));
        if ($domainId === '' || $recordId === '') {
            fwrite(STDERR, "Missing --domain_id or --record_id\n");
            return 1;
        }

        $patch = [];
        foreach (['type', 'name', 'content', 'status', 'geo_policy_id', 'origin_host', 'origin_tls_verify'] as $field) {
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
        if (isset($opts['geo_origins_json'])) {
            $decoded = json_decode((string) $opts['geo_origins_json'], true);
            if (!is_array($decoded)) {
                fwrite(STDERR, "Invalid --geo_origins_json\n");
                return 1;
            }
            $patch['geo_origins'] = $decoded;
        }
        if ($patch === []) {
            fwrite(STDERR, "Missing update options\n");
            return 1;
        }

        try {
            $record = (new DnsService())->update($domainId, $recordId, $patch);
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
