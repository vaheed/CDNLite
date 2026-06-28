<?php

namespace App\Console\Commands;

use App\Support\CommandIO;
use App\Support\Logger;

class ScheduleRunCommand
{
    /** @var array<int,array{name:string,command:string,interval_env:string,default_interval:int,enabled_env:?string,enabled_default:bool}> */
    private array $tasks = [
        [
            'name' => 'dns_reconcile',
            'command' => 'cdn:dns:reconcile',
            'interval_env' => 'CDNLITE_SYNC_INTERVAL_SECONDS',
            'default_interval' => 30,
            'enabled_env' => null,
            'enabled_default' => true,
        ],
        [
            'name' => 'ssl_renew_due',
            'command' => 'cdn:ssl:renew-due',
            'interval_env' => 'CDNLITE_SSL_SCHEDULER_INTERVAL_SECONDS',
            'default_interval' => 30,
            'enabled_env' => null,
            'enabled_default' => true,
        ],
        [
            'name' => 'origin_health_check',
            'command' => 'cdn:origins:health-check',
            'interval_env' => 'CDNLITE_ORIGIN_HEALTH_INTERVAL_SECONDS',
            'default_interval' => 30,
            'enabled_env' => null,
            'enabled_default' => true,
        ],
        [
            'name' => 'nameserver_verify_all',
            'command' => 'cdn:domains:verify-all',
            'interval_env' => 'CDNLITE_NAMESERVER_CHECK_INTERVAL_SECONDS',
            'default_interval' => 86400,
            'enabled_env' => null,
            'enabled_default' => true,
        ],
        [
            'name' => 'retention_prune',
            'command' => 'cdn:usage:prune --all',
            'interval_env' => 'CDNLITE_RETENTION_INTERVAL_SECONDS',
            'default_interval' => 86400,
            'enabled_env' => 'CDNLITE_RETENTION_PRUNE_ENABLED',
            'enabled_default' => false,
        ],
        [
            'name' => 'config_snapshot_prune',
            'command' => 'cdn:config-snapshots:prune --keep="${CDNLITE_CONFIG_SNAPSHOT_KEEP_LAST:-2}" --batch="${CDNLITE_CONFIG_SNAPSHOT_PRUNE_BATCH_SIZE:-5000}"',
            'interval_env' => 'CDNLITE_RETENTION_INTERVAL_SECONDS',
            'default_interval' => 86400,
            'enabled_env' => 'CDNLITE_RETENTION_PRUNE_ENABLED',
            'enabled_default' => false,
        ],
    ];

    public function __invoke(array $argv): int
    {
        $opts = CommandIO::parseOptions($argv);
        $now = time();
        $state = $this->readState();
        $results = [];

        foreach ($this->tasks as $task) {
            if (!$this->enabled($task)) {
                $results[] = ['name' => $task['name'], 'status' => 'disabled'];
                continue;
            }

            $interval = $this->interval($task['interval_env'], $task['default_interval']);
            $lastRun = (int) ($state[$task['name']]['last_run'] ?? 0);
            if (!isset($opts['force']) && $lastRun > 0 && ($now - $lastRun) < $interval) {
                $results[] = [
                    'name' => $task['name'],
                    'status' => 'skipped',
                    'next_due_at' => $lastRun + $interval,
                ];
                continue;
            }

            $startedAt = time();
            $exitCode = $this->runTask($task['command']);
            $state[$task['name']] = [
                'last_run' => $startedAt,
                'last_exit_code' => $exitCode,
                'last_success_at' => $exitCode === 0 ? time() : (int) ($state[$task['name']]['last_success_at'] ?? 0),
            ];
            $results[] = [
                'name' => $task['name'],
                'status' => $exitCode === 0 ? 'ran' : 'failed',
                'exit_code' => $exitCode,
            ];
        }

        $this->writeState($state);
        CommandIO::printJson(['data' => ['ran_at' => $now, 'tasks' => $results]]);
        return 0;
    }

    private function runTask(string $command): int
    {
        $cmd = 'php ' . escapeshellarg(dirname(__DIR__, 3) . '/artisan') . ' ' . $command;
        passthru($cmd, $exitCode);
        if ($exitCode !== 0) {
            Logger::warn('scheduled_task_failed', ['command' => $command, 'exit_code' => $exitCode]);
        }
        return (int) $exitCode;
    }

    private function enabled(array $task): bool
    {
        $envName = $task['enabled_env'];
        if ($envName === null) {
            return true;
        }
        $raw = getenv($envName);
        if ($raw === false || trim((string) $raw) === '') {
            return (bool) $task['enabled_default'];
        }
        return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
    }

    private function interval(string $envName, int $default): int
    {
        $raw = getenv($envName);
        if ($raw !== false && is_numeric($raw)) {
            return max(1, (int) $raw);
        }
        return $default;
    }

    private function statePath(): string
    {
        return (string) (getenv('CDNLITE_SCHEDULER_STATE_PATH') ?: dirname(__DIR__, 3) . '/storage/scheduler-state.json');
    }

    private function readState(): array
    {
        $path = $this->statePath();
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeState(array $state): void
    {
        $path = $this->statePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
