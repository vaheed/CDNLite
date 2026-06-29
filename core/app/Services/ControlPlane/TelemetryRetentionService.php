<?php

namespace App\Services\ControlPlane;

use Illuminate\Support\Facades\DB;

class TelemetryRetentionService
{
    /** @var array<string> */
    private const SECURITY_EVENTS = [
        'waf_match',
        'rate_limited',
        'bot_match',
        'geo_block',
        'ip_block',
        'challenge',
        'waiting_room',
    ];

    /** @var array<string> */
    private const TERMINAL_SSL_JOB_STATUSES = [
        'issued',
        'failed',
        'cancelled',
    ];

    /** @var array<string> */
    private const SUCCESSFUL_DNS_STATUSES = [
        'success',
        'verified',
    ];

    public function pruneDetailedEvents(?int $retentionDays = null, bool $dryRun = false, ?int $batchSize = null): array
    {
        $days = $this->retentionDays($retentionDays, 'CDNLITE_ANALYTICS_RETENTION_DAYS', 30, 1, 3650);
        $batch = $this->batchSize($batchSize);
        $cutoff = UnixTime::now() - ($days * 86400);
        $plan = $this->pruneTable(
            'usage_rollups',
            'id',
            ['ts', '<', $cutoff],
            $days,
            $dryRun,
            $batch,
        );

        return [
            'retention_days' => $days,
            'cutoff' => $cutoff,
            'dry_run' => $dryRun,
            'batch_size' => $batch,
            'matching' => $plan['matched'],
            'deleted' => $plan['deleted'],
        ];
    }

    public function pruneOperationalRetention(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $batchSize = $this->batchSize($this->nullableInt($options['batch_size'] ?? null));
        $now = UnixTime::now();
        $usageDays = $this->retentionDays($options['usage_days'] ?? null, 'CDNLITE_ANALYTICS_RETENTION_DAYS', 30, 1, 3650);
        $securityDays = $this->retentionDays($options['security_days'] ?? null, 'CDNLITE_SECURITY_EVENT_RETENTION_DAYS', 90, 1, 3650);
        $dnsDays = $this->retentionDays($options['dns_days'] ?? null, 'CDNLITE_DNS_EVENT_RETENTION_DAYS', 30, 1, 3650);
        $sslJobDays = $this->retentionDays($options['ssl_job_days'] ?? null, 'CDNLITE_SSL_JOB_RETENTION_DAYS', 180, 1, 3650);
        $idempotencyDays = $this->retentionDays($options['idempotency_days'] ?? null, 'CDNLITE_INGEST_KEY_RETENTION_DAYS', 7, 1, 3650);

        return [
            'dry_run' => $dryRun,
            'batch_size' => $batchSize,
            'tables' => [
                'usage_rollups' => $this->pruneTable('usage_rollups', 'id', ['ts', '<', $now - ($usageDays * 86400)], $usageDays, $dryRun, $batchSize),
                'security_events' => $this->pruneTable('audit_log', 'id', ['created_at', '<', $now - ($securityDays * 86400)], $securityDays, $dryRun, $batchSize, static fn ($query) => $query->whereIn('event', self::SECURITY_EVENTS)),
                'telemetry_rejected_events' => $this->pruneTable('telemetry_rejected_events', 'id', ['created_at', '<', $now - ($securityDays * 86400)], $securityDays, $dryRun, $batchSize),
                'telemetry_ingest_batches' => $this->pruneTable('telemetry_ingest_batches', 'batch_id', ['ingested_at', '<', $now - ($idempotencyDays * 86400)], $idempotencyDays, $dryRun, $batchSize),
                'usage_ingest_keys' => $this->pruneTable('usage_ingest_keys', 'idempotency_key', ['created_at', '<', $now - ($idempotencyDays * 86400)], $idempotencyDays, $dryRun, $batchSize),
                'dns_sync_events' => $this->pruneTable('dns_sync_events', 'id', ['created_at', '<', $now - ($dnsDays * 86400)], $dnsDays, $dryRun, $batchSize, static fn ($query) => $query->whereIn('status', self::SUCCESSFUL_DNS_STATUSES)),
                'ssl_jobs' => $this->pruneTable('ssl_jobs', 'id', ['created_at', '<', $now - ($sslJobDays * 86400)], $sslJobDays, $dryRun, $batchSize, static fn ($query) => $query->whereIn('status', self::TERMINAL_SSL_JOB_STATUSES)),
                'edge_request_nonces' => $this->pruneTable('edge_request_nonces', 'id', ['expires_at', '<', $now], 0, $dryRun, $batchSize),
            ],
        ];
    }

    /**
     * @param array{0:string,1:string,2:int} $cutoff
     */
    private function pruneTable(string $table, string $keyColumn, array $cutoff, int $retentionDays, bool $dryRun, int $batchSize, ?callable $scope = null): array
    {
        [$column, $operator, $value] = $cutoff;
        $base = DB::table($table)->where($column, $operator, $value);
        if ($scope !== null) {
            $scope($base);
        }

        $matched = (clone $base)->count();
        $deleted = 0;
        if (!$dryRun && $matched > 0) {
            $ids = (clone $base)->orderBy($column)->limit($batchSize)->pluck($keyColumn)->all();
            if ($ids !== []) {
                $deleted = DB::table($table)->whereIn($keyColumn, $ids)->delete();
            }
        }

        return [
            'retention_days' => $retentionDays,
            'cutoff' => $value,
            'matched' => $matched,
            'deleted' => $deleted,
        ];
    }

    private function retentionDays(mixed $value, string $envName, int $default, int $min, int $max): int
    {
        $parsed = $this->nullableInt($value);
        if ($parsed === null) {
            $parsed = $this->nullableInt(env($envName));
        }

        return max($min, min($max, $parsed ?? $default));
    }

    private function batchSize(?int $value = null): int
    {
        return max(100, min(50000, $value ?? $this->nullableInt(env('CDNLITE_RETENTION_BATCH_SIZE')) ?? 5000));
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
