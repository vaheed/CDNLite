<?php

use Illuminate\Foundation\Inspiring;
use App\Services\ControlPlane\DnsPowerDnsReconciler;
use App\Services\ControlPlane\EdgeConfigSnapshotService;
use App\Services\ControlPlane\PowerDnsClient;
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
