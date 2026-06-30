<?php

namespace App\Console\Commands;

use App\Services\ControlPlane\SslCertificateService;
use App\Support\CommandIO;

class CdnSslRequestCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $domainId = trim((string) ($opts['domain_id'] ?? ''));
        if ($domainId === '') {
            fwrite(STDERR, "Missing --domain_id\n");
            return 1;
        }
        $hostnames = array_values(array_filter(array_map('trim', explode(',', (string) ($opts['hostnames'] ?? '')))));

        try {
            $rows = (new SslCertificateService())->requestJob($domainId, $hostnames);
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        }

        CommandIO::printJson(['data' => $rows]);
        return 0;
    }
}
