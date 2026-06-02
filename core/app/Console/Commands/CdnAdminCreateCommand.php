<?php

namespace App\Console\Commands;

use App\Modules\Admin\Services\AdminAuthService;
use App\Support\CommandIO;

class CdnAdminCreateCommand
{
    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $username = (string) ($opts['username'] ?? '');
        $password = (string) ($opts['password'] ?? '');
        $displayName = isset($opts['display_name']) ? (string) $opts['display_name'] : null;

        if ($username === '' || $password === '') {
            fwrite(STDERR, "Missing --username or --password\n");
            return 1;
        }

        try {
            $user = (new AdminAuthService())->createOrUpdateUser($username, $password, $displayName);
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        }

        CommandIO::printJson(['ok' => true, 'user' => $user]);
        return 0;
    }
}
