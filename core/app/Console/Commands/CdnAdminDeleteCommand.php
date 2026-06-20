<?php

namespace App\Console\Commands;

use App\Modules\Admin\Services\AdminAuthService;
use App\Support\CommandIO;

class CdnAdminDeleteCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $username = (string) ($opts['username'] ?? '');
        $force = filter_var($opts['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($username === '') {
            fwrite(STDERR, "Missing --username\n");
            return 1;
        }

        try {
            $user = (new AdminAuthService())->deleteUser($username, $force);
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        }

        CommandIO::printJson(['ok' => true, 'deleted_user' => $user, 'sessions_revoked' => true]);
        return 0;
    }
}
