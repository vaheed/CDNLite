<?php

namespace App\Console\Commands;

use App\Services\ControlPlane\EdgeConfigSnapshotService;
use App\Support\CommandIO;

class CdnEdgeSyncConfigCommand
{
    public function __invoke(array $argv): int
    {
        try {
            $snapshot = (new EdgeConfigSnapshotService())->publish();
        } catch (\RuntimeException $error) {
            if (str_starts_with($error->getMessage(), 'config_snapshot_too_large:')) {
                CommandIO::printJson(['error' => 'config_snapshot_too_large', 'detail' => $error->getMessage()]);

                return 1;
            }

            throw $error;
        }

        CommandIO::printJson(['data' => $snapshot]);

        return 0;
    }
}
