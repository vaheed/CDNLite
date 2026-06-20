<?php

namespace App\Console\Commands;

use App\Modules\Admin\Services\AdminAuthService;
use App\Support\CommandIO;

class CdnAdminListCommand
{
    public function __invoke(array $argv): int
    {
        CommandIO::printJson(['data' => (new AdminAuthService())->listUsers()]);
        return 0;
    }
}
