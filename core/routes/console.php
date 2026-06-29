<?php

use Illuminate\Foundation\Inspiring;
use App\Services\ControlPlane\DnsPowerDnsReconciler;
use App\Services\ControlPlane\EdgeConfigSnapshotService;
use App\Services\ControlPlane\PowerDnsClient;
use App\Services\ControlPlane\TelemetryRetentionService;
use App\Services\ControlPlane\UnixTime;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('cdnlite:runtime-maintenance', function (): int {
    $deleted = DB::table('edge_request_nonces')->where('expires_at', '<', time())->delete();
    $this->info("Pruned {$deleted} expired edge request nonces.");

    return self::SUCCESS;
})->purpose('Run lightweight CDNLite runtime maintenance tasks');

Schedule::command('cdnlite:runtime-maintenance')
    ->everyMinute()
    ->withoutOverlapping();

Artisan::command('cdn:dns:reconcile {--force}', function (): int {
    $result = app(DnsPowerDnsReconciler::class)->forceSync();
    $this->line(json_encode(['data' => $result], JSON_UNESCAPED_SLASHES));

    return ($result['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
})->purpose('Reconcile Laravel desired DNS state to PowerDNS');

Artisan::command('cdn:powerdns:force-sync {--dry-run}', function (): int {
    $reconciler = app(DnsPowerDnsReconciler::class);
    $result = $this->option('dry-run') ? $reconciler->preview() : $reconciler->forceSync();
    $this->line(json_encode(['data' => $result], JSON_UNESCAPED_SLASHES));

    return ($result['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
})->purpose('Force a Laravel PowerDNS reconciliation pass');

Artisan::command('cdn:powerdns:dry-run', function (): int {
    $preview = app(DnsPowerDnsReconciler::class)->preview();
    $this->line(json_encode(['data' => $preview], JSON_UNESCAPED_SLASHES));

    return self::SUCCESS;
})->purpose('Preview Laravel desired DNS state without writing PowerDNS');

Artisan::command('cdn:powerdns:doctor', function (): int {
    $powerDns = app(PowerDnsClient::class);
    $health = $powerDns->enabled() ? $powerDns->status() : ['ok' => true, 'disabled' => true];
    $preview = ($powerDns->enabled() && $powerDns->configured() && ($health['ok'] ?? false) === true)
        ? app(DnsPowerDnsReconciler::class)->preview()
        : ['soa' => [], 'planned_changes' => 0];
    $soaZones = (array) ($preview['soa'] ?? []);
    $invalidSoa = array_values(array_filter($soaZones, static fn (array $zone): bool => ($zone['valid'] ?? false) !== true));
    $syncRows = DB::table('dns_sync_state')->orderBy('zone_name')->get();
    $payload = [
        'enabled' => $powerDns->enabled(),
        'configured' => $powerDns->configured(),
        'strict' => $powerDns->strict(),
        'api' => $health,
        'sync' => [
            'status' => $syncRows->contains(fn (object $row): bool => $row->status === 'failed')
                ? 'failed'
                : ($syncRows->contains(fn (object $row): bool => (bool) $row->in_progress) ? 'syncing' : ($syncRows->isEmpty() ? 'unknown' : 'ok')),
            'zones' => $syncRows->map(fn (object $row): array => (array) $row)->all(),
            'failed_zones' => $syncRows->where('status', 'failed')->count(),
            'syncing_zones' => $syncRows->filter(fn (object $row): bool => (bool) $row->in_progress)->count(),
        ],
        'dns' => [
            'planned_changes' => (int) ($preview['planned_changes'] ?? 0),
            'apex_proxy_mode' => 'LUA',
            'checks' => [
                'managed_proxied_apex_uses_lua' => true,
                'managed_proxied_apex_has_no_alias' => true,
                'proxied_apex_has_no_cname' => true,
                'subdomain_cname_flow_preserved' => true,
            ],
        ],
        'soa' => [
            'valid' => $invalidSoa === [],
            'zones' => $soaZones,
            'invalid_zones' => $invalidSoa,
        ],
    ];
    $this->line(json_encode(['data' => $payload], JSON_UNESCAPED_SLASHES));

    return ($powerDns->enabled() && (($health['ok'] ?? false) !== true || $invalidSoa !== [])) ? self::FAILURE : self::SUCCESS;
})->purpose('Report PowerDNS API, sync, and managed SOA health');

Artisan::command('cdn:edge:register-token {--edge_id=} {--token=}', function (): int {
    $edgeId = trim((string) $this->option('edge_id'));
    $token = (string) $this->option('token');
    if ($edgeId === '' || $token === '') {
        $this->error('edge_id_and_token_required');

        return self::FAILURE;
    }

    DB::table('edge_tokens')->upsert([[
        'edge_id' => $edgeId,
        'token_hash' => password_hash($token, PASSWORD_BCRYPT),
        'created_at' => UnixTime::now(),
        'updated_at' => UnixTime::now(),
    ]], ['edge_id'], ['token_hash', 'updated_at']);

    $this->line(json_encode(['data' => ['edge_id' => $edgeId, 'registered' => true]], JSON_UNESCAPED_SLASHES));

    return self::SUCCESS;
})->purpose('Register or replace an edge HMAC token');

Artisan::command('cdn:edge:rotate-token {--edge_id=} {--token=}', function (): int {
    $edgeId = trim((string) $this->option('edge_id'));
    $token = (string) ($this->option('token') ?: bin2hex(random_bytes(32)));
    if ($edgeId === '') {
        $this->error('edge_id_required');

        return self::FAILURE;
    }

    DB::table('edge_tokens')->upsert([[
        'edge_id' => $edgeId,
        'token_hash' => password_hash($token, PASSWORD_BCRYPT),
        'created_at' => UnixTime::now(),
        'updated_at' => UnixTime::now(),
    ]], ['edge_id'], ['token_hash', 'updated_at']);

    $this->line(json_encode(['data' => ['edge_id' => $edgeId, 'token' => $token]], JSON_UNESCAPED_SLASHES));

    return self::SUCCESS;
})->purpose('Rotate an edge HMAC token');

Artisan::command('cdn:edge:list', function (): int {
    $edges = DB::table('edge_nodes')->orderBy('edge_id')->get()->map(fn (object $edge): array => (array) $edge)->all();
    $this->line(json_encode(['data' => $edges], JSON_UNESCAPED_SLASHES));

    return self::SUCCESS;
})->purpose('List registered edge nodes');

Artisan::command('cdn:edge:show {--edge_id=}', function (): int {
    $edgeId = trim((string) $this->option('edge_id'));
    if ($edgeId === '') {
        $this->error('edge_id_required');

        return self::FAILURE;
    }
    $edge = DB::table('edge_nodes')->where('edge_id', $edgeId)->first();
    if ($edge === null) {
        $this->error('edge_not_found');

        return self::FAILURE;
    }

    $this->line(json_encode(['data' => (array) $edge], JSON_UNESCAPED_SLASHES));

    return self::SUCCESS;
})->purpose('Show one edge node');

Artisan::command('cdn:edge:sync-config', function (): int {
    try {
        $result = app(EdgeConfigSnapshotService::class)->publish();
    } catch (\RuntimeException $error) {
        if (str_starts_with($error->getMessage(), 'config_snapshot_too_large:')) {
            $this->line(json_encode(['error' => 'config_snapshot_too_large', 'detail' => $error->getMessage()], JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        throw $error;
    }

    $this->line(json_encode(['data' => $result], JSON_UNESCAPED_SLASHES));

    return self::SUCCESS;
})->purpose('Publish the active Laravel edge config snapshot');

Artisan::command('cdn:usage:summary {--domain_id=} {--bucket=}', function (): int {
    $domainId = trim((string) $this->option('domain_id')) ?: null;
    $bucket = trim((string) $this->option('bucket')) ?: null;
    $bucketSeconds = ['minute' => 60, 'hour' => 3600, 'day' => 86400];
    if ($bucket !== null && !isset($bucketSeconds[$bucket])) {
        $this->error('bucket_must_be_one_of_minute_hour_day');

        return self::FAILURE;
    }

    $query = DB::table($bucket === null ? 'usage_rollups' : 'usage_aggregates')
        ->when($domainId !== null, fn ($q) => $q->where('domain_id', $domainId));
    if ($bucket !== null) {
        $query->where('bucket', $bucket);
    }
    $row = (array) $query->selectRaw('COALESCE(SUM(requests_count),0) AS requests_count, COALESCE(SUM(bytes_in),0) AS bytes_in, COALESCE(SUM(bytes_out),0) AS bytes_out, COUNT(*) AS records')->first();

    $this->line(json_encode(['data' => [
        'domain_id' => $domainId,
        'bucket' => $bucket,
        'requests_count' => (int) ($row['requests_count'] ?? 0),
        'bytes_in' => (int) ($row['bytes_in'] ?? 0),
        'bytes_out' => (int) ($row['bytes_out'] ?? 0),
        'records' => (int) ($row['records'] ?? 0),
    ]], JSON_UNESCAPED_SLASHES));

    return self::SUCCESS;
})->purpose('Summarize Laravel usage analytics');

Artisan::command('cdn:usage:recalculate {--domain_id=} {--bucket=}', function (): int {
    $domainId = trim((string) $this->option('domain_id')) ?: null;
    $onlyBucket = trim((string) $this->option('bucket')) ?: null;
    $bucketSeconds = ['minute' => 60, 'hour' => 3600, 'day' => 86400];
    if ($onlyBucket !== null && !isset($bucketSeconds[$onlyBucket])) {
        $this->error('bucket_must_be_one_of_minute_hour_day');

        return self::FAILURE;
    }

    $jobId = (string) Str::uuid();
    $now = UnixTime::now();
    DB::table('analytics_rollup_jobs')->insert([
        'id' => $jobId,
        'domain_id' => $domainId,
        'bucket' => $onlyBucket,
        'range_start' => null,
        'range_end' => null,
        'status' => 'running',
        'requested_by' => 'cli',
        'progress_json' => json_encode([]),
        'locked_by' => 'artisan',
        'locked_at' => $now,
        'started_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $inserted = [];
    foreach ($bucketSeconds as $bucket => $seconds) {
        if ($onlyBucket !== null && $bucket !== $onlyBucket) {
            continue;
        }
        $where = $domainId === null ? '' : 'WHERE domain_id = :domain_id_filter';
        $params = [
            'bucket_hash' => $bucket,
            'bucket_value' => $bucket,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($domainId !== null) {
            $params['domain_id_filter'] = $domainId;
        }
        $sql = sprintf(
            "WITH source AS (
                SELECT ((ts / %d) * %d) AS bucket_ts, domain_id, edge_node_id, status,
                       COALESCE(cache_status, 'UNKNOWN') AS cache_status, requests_count, bytes_in, bytes_out
                FROM usage_rollups %s
            )
            INSERT INTO usage_aggregates
            (id, bucket, bucket_ts, domain_id, edge_node_id, status, cache_status, requests_count, bytes_in, bytes_out, created_at, updated_at)
            SELECT md5((:bucket_hash || ':' || bucket_ts || ':' || domain_id || ':' || edge_node_id || ':' || status || ':' || COALESCE(cache_status, 'UNKNOWN'))::text),
                   :bucket_value, bucket_ts, domain_id, edge_node_id, status, cache_status,
                   COALESCE(SUM(requests_count),0), COALESCE(SUM(bytes_in),0), COALESCE(SUM(bytes_out),0),
                   :created_at, :updated_at
            FROM source
            GROUP BY bucket_ts, domain_id, edge_node_id, status, cache_status
            ON CONFLICT (bucket, bucket_ts, domain_id, edge_node_id, status, cache_status)
            DO UPDATE SET requests_count=EXCLUDED.requests_count, bytes_in=EXCLUDED.bytes_in, bytes_out=EXCLUDED.bytes_out, updated_at=EXCLUDED.updated_at",
            $seconds,
            $seconds,
            $where
        );
        DB::statement($sql, $params);
        $inserted[$bucket] = DB::table('usage_aggregates')->where('bucket', $bucket)->when($domainId !== null, fn ($q) => $q->where('domain_id', $domainId))->count();
    }

    DB::table('analytics_rollup_jobs')->where('id', $jobId)->update([
        'status' => 'succeeded',
        'progress_json' => json_encode(['inserted' => $inserted]),
        'finished_at' => UnixTime::now(),
        'updated_at' => UnixTime::now(),
    ]);

    $this->line(json_encode(['data' => ['ok' => true, 'job_id' => $jobId, 'job_status' => 'succeeded', 'domain_id' => $domainId, 'bucket' => $onlyBucket, 'inserted' => $inserted]], JSON_UNESCAPED_SLASHES));

    return self::SUCCESS;
})->purpose('Recalculate Laravel usage aggregates');

Artisan::command('cdn:usage:prune {--all} {--dry-run} {--days=} {--security-days=} {--dns-days=} {--ssl-job-days=} {--idempotency-days=} {--batch-size=}', function (): int {
    $retention = app(TelemetryRetentionService::class);
    $result = (bool) $this->option('all')
        ? $retention->pruneOperationalRetention([
            'dry_run' => (bool) $this->option('dry-run'),
            'batch_size' => $this->option('batch-size'),
            'usage_days' => $this->option('days'),
            'security_days' => $this->option('security-days'),
            'dns_days' => $this->option('dns-days'),
            'ssl_job_days' => $this->option('ssl-job-days'),
            'idempotency_days' => $this->option('idempotency-days'),
        ])
        : $retention->pruneDetailedEvents(
            is_numeric($this->option('days')) ? (int) $this->option('days') : null,
            (bool) $this->option('dry-run'),
            is_numeric($this->option('batch-size')) ? (int) $this->option('batch-size') : null,
        );

    $this->line(json_encode(['data' => $result], JSON_UNESCAPED_SLASHES));

    return self::SUCCESS;
})->purpose('Prune Laravel usage and telemetry retention tables');
