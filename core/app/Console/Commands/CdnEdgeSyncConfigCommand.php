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

        $payload = is_array($snapshot['snapshot'] ?? null) ? $snapshot['snapshot'] : $snapshot;
        $version = (int) ($payload['version'] ?? $snapshot['version'] ?? 0);
        $options = CommandIO::parseOptions($argv);
        if (($options['if_version'] ?? null) !== null && (int) $options['if_version'] === $version) {
            CommandIO::printJson(['not_modified' => true, 'version' => $version]);

            return 0;
        }

        CommandIO::printJson($payload);

        return 0;
    }
}
