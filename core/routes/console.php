<?php

use Illuminate\Foundation\Inspiring;
use App\Services\ControlPlane\DnsPowerDnsReconciler;
use App\Services\ControlPlane\EdgeConfigSnapshotService;
use App\Services\ControlPlane\PowerDnsClient;
use App\Services\ControlPlane\SslRenewalService;
use App\Services\ControlPlane\TelemetryRetentionService;
use App\Services\ControlPlane\UnixTime;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

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

$bridgeOptions = implode(' ', [
    '{--username=}', '{--password=}', '{--display_name=}', '{--force}', '{--format=}',
    '{--domain=}', '{--domain_id=}', '{--name=}', '{--user_id=}', '{--id=}',
    '{--record_id=}', '{--type=}', '{--content=}', '{--ttl=}', '{--priority=}',
    '{--proxied=}', '{--origin_host=}', '{--origin_tls_verify=}', '{--geo_policy_id=}',
    '{--geo_origins_json=}', '{--source_path=}', '{--target_url=}', '{--status_code=}',
    '{--enabled=}', '{--pattern=}', '{--rule_id=}', '{--path_prefix=}', '{--ttl_seconds=}',
    '{--key=}', '{--value=}', '{--group=}', '{--edge_id=}', '{--token=}', '{--hostnames=}',
    '{--dry-run}', '{--dry_run}', '{--seed-settings}', '{--seed_settings}', '{--keep=}',
    '{--batch=}',
]);

$bridgedCommands = [
    'cdn:admin:create' => \App\Console\Commands\CdnAdminCreateCommand::class,
    'cdn:admin:list' => \App\Console\Commands\CdnAdminListCommand::class,
    'cdn:admin:password' => \App\Console\Commands\CdnAdminPasswordCommand::class,
    'cdn:admin:delete' => \App\Console\Commands\CdnAdminDeleteCommand::class,
    'cdn:domain:create' => \App\Console\Commands\CdnDomainCreateCommand::class,
    'cdn:domain:list' => \App\Console\Commands\CdnDomainListCommand::class,
    'cdn:domain:show' => \App\Console\Commands\CdnDomainShowCommand::class,
    'cdn:domain:activate' => \App\Console\Commands\CdnDomainActivateCommand::class,
    'cdn:domain:verify-ns' => \App\Console\Commands\CdnDomainVerifyNsCommand::class,
    'cdn:domains:verify-all' => \App\Console\Commands\CdnDomainsVerifyAllCommand::class,
    'cdn:domain:update' => \App\Console\Commands\CdnDomainUpdateCommand::class,
    'cdn:domain:delete' => \App\Console\Commands\CdnDomainDeleteCommand::class,
    'cdn:dns:add-record' => \App\Console\Commands\CdnDnsAddRecordCommand::class,
    'cdn:dns:list-records' => \App\Console\Commands\CdnDnsListRecordsCommand::class,
    'cdn:dns:update-record' => \App\Console\Commands\CdnDnsUpdateRecordCommand::class,
    'cdn:dns:delete-record' => \App\Console\Commands\CdnDnsDeleteRecordCommand::class,
    'cdn:dns:bootstrap-edge-domain' => \App\Console\Commands\CdnDnsBootstrapEdgeDomainCommand::class,
    'cdn:dns:sync-edge-domain' => \App\Console\Commands\CdnDnsSyncEdgeDomainCommand::class,
    'cdn:dns:rebuild-customer-zones' => \App\Console\Commands\CdnDnsRebuildCustomerZonesCommand::class,
    'cdn:dns:validate-routing' => \App\Console\Commands\CdnDnsValidateRoutingCommand::class,
    'cdn:edge:disable' => \App\Console\Commands\CdnEdgeDisableCommand::class,
    'cdn:config-snapshots:prune' => \App\Console\Commands\CdnConfigSnapshotsPruneCommand::class,
    'cdn:settings:get' => \App\Console\Commands\CdnSettingsGetCommand::class,
    'cdn:settings:set' => \App\Console\Commands\CdnSettingsSetCommand::class,
    'cdn:settings:test-powerdns' => \App\Console\Commands\CdnSettingsTestPowerDnsCommand::class,
    'cdn:readiness:check' => \App\Console\Commands\CdnReadinessCheckCommand::class,
    'cdn:redirect:create' => \App\Console\Commands\CdnRedirectCreateCommand::class,
    'cdn:redirect:list' => \App\Console\Commands\CdnRedirectListCommand::class,
    'cdn:redirect:update' => \App\Console\Commands\CdnRedirectUpdateCommand::class,
    'cdn:redirect:delete' => \App\Console\Commands\CdnRedirectDeleteCommand::class,
    'cdn:waf:create' => \App\Console\Commands\CdnWafCreateCommand::class,
    'cdn:waf:list' => \App\Console\Commands\CdnWafListCommand::class,
    'cdn:waf:update' => \App\Console\Commands\CdnWafUpdateCommand::class,
    'cdn:waf:delete' => \App\Console\Commands\CdnWafDeleteCommand::class,
    'cdn:cache-rule:create' => \App\Console\Commands\CdnCacheRuleCreateCommand::class,
    'cdn:cache-rule:list' => \App\Console\Commands\CdnCacheRuleListCommand::class,
    'cdn:cache-rule:update' => \App\Console\Commands\CdnCacheRuleUpdateCommand::class,
    'cdn:cache-rule:delete' => \App\Console\Commands\CdnCacheRuleDeleteCommand::class,
    'cdn:cache:purge' => \App\Console\Commands\CdnCachePurgeCommand::class,
    'cdn:cache:settings' => \App\Console\Commands\CdnCacheSettingsCommand::class,
    'cdn:header:create' => \App\Console\Commands\CdnHeaderCreateCommand::class,
    'cdn:header:list' => \App\Console\Commands\CdnHeaderListCommand::class,
    'cdn:header:update' => \App\Console\Commands\CdnHeaderUpdateCommand::class,
    'cdn:header:delete' => \App\Console\Commands\CdnHeaderDeleteCommand::class,
    'cdn:ip-rule:create' => \App\Console\Commands\CdnIpRuleCreateCommand::class,
    'cdn:ip-rule:list' => \App\Console\Commands\CdnIpRuleListCommand::class,
    'cdn:ip-rule:update' => \App\Console\Commands\CdnIpRuleUpdateCommand::class,
    'cdn:ip-rule:delete' => \App\Console\Commands\CdnIpRuleDeleteCommand::class,
    'cdn:origins:health-check' => \App\Console\Commands\CdnOriginsHealthCheckCommand::class,
    'cdn:origins:list' => \App\Console\Commands\CdnOriginsListCommand::class,
    'cdn:ssl:list' => \App\Console\Commands\CdnSslListCommand::class,
    'cdn:ssl:request' => \App\Console\Commands\CdnSslRequestCommand::class,
    'cdn:db:migrate' => \App\Console\Commands\CdnDbMigrateCommand::class,
    'cdn:db:status' => \App\Console\Commands\CdnDbStatusCommand::class,
    'cdn:db:fresh' => \App\Console\Commands\CdnDbFreshCommand::class,
    'cdn:bootstrap:fresh' => \App\Console\Commands\CdnBootstrapFreshCommand::class,
    'cdn:scheduler:run' => \App\Console\Commands\ScheduleRunCommand::class,
];

foreach ($bridgedCommands as $commandName => $handlerClass) {
    Artisan::command("{$commandName} {$bridgeOptions}", function () use ($handlerClass): int {
        return app($handlerClass)($_SERVER['argv'] ?? []);
    })->purpose("Run {$commandName} through the Laravel console bootstrap");
}

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

Artisan::command('cdn:ssl:renew-due {--limit=}', function (): int {
    $limit = $this->option('limit');
    $service = app(SslRenewalService::class);
    $queued = $service->processQueuedJobs($limit === null || $limit === '' ? null : (int) $limit);
    $renewals = $service->renewDue();
    $result = ['queued' => $queued, 'renewals' => $renewals];
    $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
    foreach (array_merge($queued['results'], $renewals['results']) as $item) {
        if (($item['status'] ?? null) === 'error') {
            return self::FAILURE;
        }
    }

    return self::SUCCESS;
})->purpose('Process queued SSL jobs and renew ACME certificates that are due');

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

Artisan::command('cdn:edge:sync-config {--if_version=}', function (): int {
    try {
        $result = app(EdgeConfigSnapshotService::class)->publish();
    } catch (\RuntimeException $error) {
        if (str_starts_with($error->getMessage(), 'config_snapshot_too_large:')) {
            $this->line(json_encode(['error' => 'config_snapshot_too_large', 'detail' => $error->getMessage()], JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        throw $error;
    }

    $payload = is_array($result['snapshot'] ?? null) ? $result['snapshot'] : $result;
    $version = (int) ($payload['version'] ?? $result['version'] ?? 0);
    $ifVersion = $this->option('if_version');
    if ($ifVersion !== null && $ifVersion !== '' && (int) $ifVersion === $version) {
        $this->line(json_encode(['not_modified' => true, 'version' => $version], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES));

    return self::SUCCESS;
})->purpose('Publish the active Laravel edge config snapshot');

Artisan::command('cdn:usage:ingest {--domain_id=} {--edge_node_id=} {--requests_count=} {--bytes_in=} {--bytes_out=} {--status=} {--cache_status=UNKNOWN} {--ts=} {--idempotency_key=}', function (): int {
    foreach (['domain_id', 'edge_node_id', 'requests_count', 'bytes_in', 'bytes_out', 'status'] as $key) {
        if ($this->option($key) === null || $this->option($key) === '') {
            $this->error("missing_{$key}");

            return self::FAILURE;
        }
    }

    $cacheStatus = strtoupper(trim((string) $this->option('cache_status'))) ?: 'UNKNOWN';
    if (!in_array($cacheStatus, ['HIT', 'MISS', 'EXPIRED', 'STALE', 'BYPASS', 'UNKNOWN'], true)) {
        $this->error('invalid_cache_status');

        return self::FAILURE;
    }

    $idempotencyKey = trim((string) $this->option('idempotency_key')) ?: null;
    if ($idempotencyKey !== null) {
        $existing = DB::table('usage_ingest_keys')->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            $this->line(json_encode([
                'ingested' => 0,
                'duplicate' => true,
                'idempotency_key' => $idempotencyKey,
                'item_count' => (int) $existing->item_count,
            ], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
    }

    $now = UnixTime::now();
    $domainId = trim((string) $this->option('domain_id'));
    $ingested = 0;
    $skippedUnknownDomains = 0;
    DB::transaction(function () use ($domainId, $cacheStatus, $idempotencyKey, $now, &$ingested, &$skippedUnknownDomains): void {
        if (!DB::table('domains')->where('id', $domainId)->exists()) {
            $skippedUnknownDomains++;
        } else {
            DB::table('usage_rollups')->insert([
                'id' => (string) Str::uuid(),
                'ts' => is_numeric($this->option('ts')) ? (int) $this->option('ts') : $now,
                'domain_id' => $domainId,
                'edge_node_id' => trim((string) $this->option('edge_node_id')),
                'requests_count' => max(0, (int) $this->option('requests_count')),
                'bytes_in' => max(0, (int) $this->option('bytes_in')),
                'bytes_out' => max(0, (int) $this->option('bytes_out')),
                'status' => max(0, (int) $this->option('status')),
                'cache_status' => $cacheStatus,
            ]);
            $ingested = 1;
        }

        if ($idempotencyKey !== null) {
            DB::table('usage_ingest_keys')->insert([
                'idempotency_key' => $idempotencyKey,
                'item_count' => $ingested,
                'created_at' => $now,
            ]);
        }
    });

    $this->line(json_encode([
        'ingested' => $ingested,
        'skipped_unknown_domains' => $skippedUnknownDomains,
        'duplicate' => false,
        'idempotency_key' => $idempotencyKey,
    ], JSON_UNESCAPED_SLASHES));

    return self::SUCCESS;
})->purpose('Ingest one Laravel usage analytics row');

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

Artisan::command('cdn:recommendations:generate {--domain_id=}', function (): int {
    $domainId = trim((string) $this->option('domain_id')) ?: null;
    if ($domainId !== null && !DB::table('domains')->where('id', $domainId)->exists()) {
        $this->error('domain_not_found');

        return self::FAILURE;
    }

    $now = UnixTime::now();
    $since = $now - 86400;
    $generated = [];
    $domains = DB::table('domains')->when($domainId !== null, fn ($query) => $query->where('id', $domainId))->get();
    foreach ($domains as $domain) {
        $candidates = [];
        $errors = DB::table('usage_rollups')->where('domain_id', $domain->id)->where('ts', '>=', $since)->where(function ($query): void {
            $query->where('status', '>=', 500)->orWhereNotNull('router_error');
        })->sum('requests_count');
        $total = DB::table('usage_rollups')->where('domain_id', $domain->id)->where('ts', '>=', $since)->sum('requests_count');
        $hits = DB::table('usage_rollups')->where('domain_id', $domain->id)->where('ts', '>=', $since)->whereRaw("UPPER(cache_status)='HIT'")->sum('requests_count');
        $security = DB::table('audit_log')->where('domain_id', $domain->id)->whereIn('event', ['waf_match', 'rate_limited', 'bot_match', 'geo_block', 'ip_block', 'challenge', 'waiting_room'])->where('created_at', '>=', $since)->count();

        if ($errors >= 3) {
            $candidates[] = ['type' => 'origin_diagnostics', 'title' => 'Run origin diagnostics', 'message' => 'The edge has seen repeated origin or routing failures.', 'why' => 'Request diagnostics include multiple 5xx responses or router errors in the last 24 hours.', 'confidence' => min(95, 70 + (int) $errors), 'risk' => 'safe', 'impact' => 'reliability', 'preview_payload' => ['origin_errors_24h' => (int) $errors], 'one_click_action' => ['kind' => 'run_origin_test']];
        }
        if ($total >= 10 && ($hits / max(1, $total)) < 0.4) {
            $candidates[] = ['type' => 'static_asset_cache', 'title' => 'Enable static asset caching', 'message' => 'Cache hit ratio is low for recent traffic.', 'why' => 'Cache analytics show a low hit ratio over the last 24 hours.', 'confidence' => 78, 'risk' => 'safe', 'impact' => 'performance', 'preview_payload' => ['requests_24h' => (int) $total], 'one_click_action' => ['kind' => 'enable_static_asset_cache']];
        }
        if ($security >= 3) {
            $candidates[] = ['type' => 'common_exploits', 'title' => 'Review exploit protection', 'message' => 'Security events are recurring for this domain.', 'why' => 'Security events include repeated protection decisions in the last 24 hours.', 'confidence' => min(95, 70 + (int) $security), 'risk' => 'moderate', 'impact' => 'security', 'preview_payload' => ['security_events_24h' => (int) $security], 'one_click_action' => ['kind' => 'enable_protection_intent', 'intent_key' => 'common_exploits']];
        }

        foreach ($candidates as $candidate) {
            $existing = DB::table('recommendations')->where('domain_id', $domain->id)->where('type', $candidate['type'])->first();
            if ($existing && in_array((string) $existing->status, ['applied', 'dismissed'], true)) {
                continue;
            }

            $values = [
                'domain_id' => (string) $domain->id,
                'type' => $candidate['type'],
                'title' => $candidate['title'],
                'message' => $candidate['message'],
                'why' => $candidate['why'],
                'confidence' => $candidate['confidence'],
                'risk' => $candidate['risk'],
                'impact' => $candidate['impact'],
                'preview_payload' => json_encode($candidate['preview_payload']),
                'one_click_action' => json_encode($candidate['one_click_action']),
                'status' => 'open',
                'updated_at' => $now,
            ];
            $id = $existing ? (string) $existing->id : (string) Str::uuid();
            if ($existing) {
                DB::table('recommendations')->where('id', $id)->update($values);
            } else {
                DB::table('recommendations')->insert($values + ['id' => $id, 'created_at' => $now]);
            }
            $generated[] = ['id' => $id, 'domain_id' => (string) $domain->id, 'type' => $candidate['type'], 'status' => 'open'];
        }
    }

    $this->line(json_encode(['data' => ['generated' => $generated, 'count' => count($generated)]], JSON_UNESCAPED_SLASHES));

    return self::SUCCESS;
})->purpose('Generate Laravel activity recommendations');
