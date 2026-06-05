<?php

namespace App\Console\Commands;

use App\Modules\Dns\Services\DnsService;
use App\Support\CommandIO;

class CdnDnsDeleteRecordCommand
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

        $ok = (new DnsService())->delete($domainId, $recordId);
        if (!$ok) {
            fwrite(STDERR, "Record not found\n");
            return 1;
        }

        CommandIO::printJson(['ok' => true]);
        return 0;
    }
}
