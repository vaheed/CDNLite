<?php

namespace App\Console\Commands;

use App\Modules\Domains\Services\DomainVerificationService;
use App\Support\CommandIO;

class CdnDomainsVerifyAllCommand
{
    public function __invoke(array $argv): int
    {
        CommandIO::printJson(['data' => (new DomainVerificationService())->verifyAll()]);
        return 0;
    }
}
