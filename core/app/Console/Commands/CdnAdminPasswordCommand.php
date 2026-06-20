<?php

namespace App\Console\Commands;

use App\Modules\Admin\Services\AdminAuthService;
use App\Support\CommandIO;

class CdnAdminPasswordCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $username = (string) ($opts['username'] ?? '');
        $password = (string) ($opts['password'] ?? '');

        if ($username === '' || $password === '') {
            fwrite(STDERR, "Missing --username or --password\n");
            return 1;
        }

        try {
            $user = (new AdminAuthService())->changePassword($username, $password);
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        }

        CommandIO::printJson(['ok' => true, 'user' => $user, 'sessions_revoked' => true]);
        return 0;
    }
}
