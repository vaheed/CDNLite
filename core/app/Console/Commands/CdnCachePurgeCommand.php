<?php

namespace App\Console\Commands;

use App\Services\ControlPlane\TrafficRulesService;
use App\Support\CommandIO;

class CdnCachePurgeCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $domainId = trim((string) ($opts['domain_id'] ?? ''));
        $type = (string) ($opts['type'] ?? 'all');
        if ($domainId === '') {
            fwrite(STDERR, "Missing --domain_id\n");
            return 1;
        }
        if (!in_array($type, ['all', 'url', 'prefix'], true)) {
            fwrite(STDERR, "Invalid --type; expected all, url, or prefix\n");
            return 1;
        }
        if ($type !== 'all' && trim((string) ($opts['value'] ?? '')) === '') {
            fwrite(STDERR, "Missing --value\n");
            return 1;
        }

        $request = (new TrafficRulesService())->createCachePurgeRequest($domainId, [
            'type' => $type === 'all' ? 'everything' : $type,
            'value' => $opts['value'] ?? null,
        ]);
        CommandIO::printJson(['data' => $request]);
        return 0;
    }
}
