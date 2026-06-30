<?php

namespace App\Console\Commands;

use App\Services\ControlPlane\SslCertificateService;
use App\Support\CommandIO;

class CdnSslListCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $domainId = trim((string) ($opts['domain_id'] ?? ''));
        if ($domainId === '') {
            fwrite(STDERR, "Missing --domain_id\n");
            return 1;
        }

        CommandIO::printJson(['data' => (new SslCertificateService())->listCertificates($domainId)]);
        return 0;
    }
}
