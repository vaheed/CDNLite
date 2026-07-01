<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Recommendations\Services\RecommendationService;
use App\Services\ControlPlane\UnixTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReportController extends Controller
{
    private const BUCKET_SECONDS = ['minute' => 60, 'hour' => 3600, 'day' => 86400];
    private const SECURITY_EVENTS = ['waf_match', 'rate_limited', 'bot_match', 'geo_block', 'ip_block', 'challenge', 'waiting_room'];

    public function summary(Request $request): JsonResponse
    {
        $range = $this->range($request);
        $kpis = $this->kpis($range);
        $warnings = [];
        if ($kpis['origin_errors'] > 0) {
            $warnings[] = ['key' => 'origin_errors', 'severity' => 'warning', 'message' => 'Origin or routing errors were recorded.', 'link' => '/overview#recent-problem-requests', 'section' => 'traffic', 'count' => $kpis['origin_errors']];
        }
        if ($kpis['pending_dns_changes'] > 0) {
            $warnings[] = ['key' => 'pending_dns_changes', 'severity' => 'warning', 'message' => 'DNS zones have pending or failed sync state.', 'link' => '/dns-operations', 'section' => 'reliability', 'count' => $kpis['pending_dns_changes']];
        }
        if ($kpis['failed_jobs'] > 0) {
            $warnings[] = ['key' => 'failed_jobs', 'severity' => 'critical', 'message' => 'Failed jobs need operator review.', 'link' => '/overview', 'section' => 'operations', 'count' => $kpis['failed_jobs']];
        }

        return response()->json(['data' => [
            'time_range' => $range,
            'previous_time_range' => null,
            'kpis' => $kpis,
            'deltas' => null,
            'warnings' => $warnings,
            'generated_at' => UnixTime::now(),
        ]]);
    }

    public function traffic(Request $request): JsonResponse
    {
        $range = $this->range($request);
        $bucket = self::BUCKET_SECONDS[$range['bucket']];
        $where = $this->usageWhere($range);
        $points = DB::table('usage_rollups')
            ->selectRaw("(FLOOR(ts / {$bucket}) * {$bucket})::BIGINT AS bucket_ts, COALESCE(SUM(requests_count),0) AS requests_count, COALESCE(SUM(bytes_in),0) AS bytes_in, COALESCE(SUM(bytes_out),0) AS bytes_out, COALESCE(SUM(requests_count) FILTER (WHERE UPPER(cache_status)='HIT'),0) AS hits")
            ->whereRaw($where['sql'], $where['params'])
            ->groupByRaw("(FLOOR(ts / {$bucket}) * {$bucket})")
            ->orderBy('bucket_ts')
            ->limit(500)
            ->get();

        return response()->json(['data' => [
            'time_range' => $range,
            'requests' => $points->map(fn (object $p): array => ['bucket_ts' => (int) $p->bucket_ts, 'value' => (int) $p->requests_count, 'requests_count' => (int) $p->requests_count])->all(),
            'bandwidth' => [
                'in' => $points->map(fn (object $p): array => ['bucket_ts' => (int) $p->bucket_ts, 'value' => (int) $p->bytes_in])->all(),
                'out' => $points->map(fn (object $p): array => ['bucket_ts' => (int) $p->bucket_ts, 'value' => (int) $p->bytes_out])->all(),
            ],
            'cache_hit_ratio' => $points->map(fn (object $p): array => ['bucket_ts' => (int) $p->bucket_ts, 'value' => ((int) $p->requests_count) > 0 ? round(((int) $p->hits) / ((int) $p->requests_count), 4) : 0])->all(),
            'status_distribution' => $this->distribution('usage_rollups', 'status', $where),
            'top_domains' => $this->topDomains($where),
            'top_paths' => $this->topUsage('path', $where),
            'top_countries' => $this->topUsage('client_country', $where),
            'top_edge_nodes' => $this->trafficByEdge($where),
            'recent_problem_requests' => $this->problemRequests($where),
            'generated_at' => UnixTime::now(),
        ]]);
    }

    public function cache(Request $request): JsonResponse
    {
        $range = $this->range($request);
        $where = $this->usageWhere($range);
        $rows = DB::table('usage_rollups')
            ->selectRaw("UPPER(COALESCE(cache_status, 'UNKNOWN')) AS status, COALESCE(SUM(requests_count),0) AS count, COALESCE(SUM(bytes_out),0) AS bytes_out")
            ->whereRaw($where['sql'], $where['params'])
            ->groupByRaw("UPPER(COALESCE(cache_status, 'UNKNOWN'))")
            ->orderByDesc('count')
            ->get()
            ->map(fn (object $row): array => ['status' => (string) $row->status, 'count' => (int) $row->count, 'bytes_out' => (int) $row->bytes_out])
            ->all();
        $hit = array_sum(array_map(static fn (array $r): int => $r['status'] === 'HIT' ? $r['bytes_out'] : 0, $rows));
        $origin = array_sum(array_map(static fn (array $r): int => $r['status'] !== 'HIT' ? $r['bytes_out'] : 0, $rows));

        return response()->json(['data' => [
            'time_range' => $range,
            'status_distribution' => $rows,
            'hit_ratio_trend' => [],
            'bytes' => ['served_from_cache_bytes' => $hit, 'served_from_origin_bytes' => $origin],
            'top_uncached_paths' => $this->topUncachedPaths($where),
            'purge_timeline' => $this->purgeTimeline($range),
            'cache_rule_match_counts' => null,
            'unavailable' => ['cache_rule_match_counts' => 'Rule-level cache-match telemetry is not emitted by the current edge collector.'],
            'generated_at' => UnixTime::now(),
        ]]);
    }

    public function edge(Request $request): JsonResponse
    {
        $range = $this->range($request);
        $where = $this->usageWhere($range);
        $latest = (int) (DB::table('config_state')->where('id', 1)->value('active_snapshot_version') ?? 0);
        $nodes = DB::table('edge_nodes')->orderBy('edge_id')->get();
        $online = $nodes->filter(fn (object $n): bool => (string) $n->status === 'online')->count();

        return response()->json(['data' => [
            'time_range' => $range,
            'counts' => ['online' => $online, 'offline' => max(0, $nodes->count() - $online), 'total' => $nodes->count()],
            'by_region' => $this->edgeDistribution('region'),
            'by_country' => $this->edgeDistribution('country'),
            'last_heartbeat_age' => $nodes->map(fn (object $n): array => ['edge_id' => (string) $n->edge_id, 'hostname' => (string) $n->hostname, 'age_seconds' => UnixTime::now() - (int) ($n->last_heartbeat_at ?? $n->last_heartbeat ?? 0)])->all(),
            'config_version_drift' => $nodes->map(fn (object $n): array => ['edge_id' => (string) $n->edge_id, 'hostname' => (string) $n->hostname, 'applied_config_version' => $n->applied_config_version, 'latest_config_version' => $latest, 'drift' => (int) ($n->applied_config_version ?? 0) !== $latest])->all(),
            'failed_config_pulls' => $nodes->filter(fn (object $n): bool => trim((string) ($n->config_apply_error ?? '')) !== '')->map(fn (object $n): array => ['edge_id' => (string) $n->edge_id, 'hostname' => (string) $n->hostname, 'config_apply_error' => (string) $n->config_apply_error, 'last_config_pull_at' => $n->last_config_pull_at])->values()->all(),
            'traffic_by_edge_node' => $this->trafficByEdge($where),
            'error_rate_by_edge_node' => $this->errorRateByEdge($where),
            'nodes' => $nodes->map(fn (object $n): array => (array) $n)->all(),
            'generated_at' => UnixTime::now(),
        ]]);
    }

    public function security(Request $request): JsonResponse
    {
        $range = $this->range($request);
        $where = $this->auditWhere($range, true);
        $bucket = self::BUCKET_SECONDS[$range['bucket']];

        return response()->json(['data' => [
            'time_range' => $range,
            'events_over_time' => DB::table('audit_log')->selectRaw("(FLOOR(created_at / {$bucket}) * {$bucket})::BIGINT AS bucket_ts, COUNT(*) AS count")->whereRaw($where['sql'], $where['params'])->groupByRaw("(FLOOR(created_at / {$bucket}) * {$bucket})")->orderBy('bucket_ts')->get()->map(fn (object $r): array => ['bucket_ts' => (int) $r->bucket_ts, 'count' => (int) $r->count])->all(),
            'by_severity' => [['severity' => 'warning', 'count' => DB::table('audit_log')->whereRaw($where['sql'], $where['params'])->count()]],
            'by_type' => $this->distribution('audit_log', 'event', $where),
            'waf_actions' => $this->securityActionDistribution($where, 'waf_match'),
            'rate_limit_actions' => $this->securityActionDistribution($where, 'rate_limited'),
            'top_attacking_ips' => $this->topSecurityJson($where, 'client_ip'),
            'top_attacked_domains' => $this->topSecurityDomains($where),
            'recent_critical_events' => $this->recentSecurityEvents($where),
            'unavailable' => [],
            'generated_at' => UnixTime::now(),
        ]]);
    }

    public function reliability(Request $request): JsonResponse
    {
        $range = $this->range($request);
        $dnsTotal = DB::table('dns_sync_state')->count();
        $dnsPending = DB::table('dns_sync_state')->where(function ($query): void {
            $query->where('status', '!=', 'ok')->orWhere('pending_changes', '>', 0)->orWhere('in_progress', true);
        })->count();

        return response()->json(['data' => [
            'time_range' => $range,
            'ssl_statuses' => $this->distribution('ssl_certificates', 'status', ['sql' => '1=1', 'params' => []]),
            'certificates_expiring_soon' => [],
            'acme_job_progress' => $this->distribution('ssl_jobs', 'status', ['sql' => '1=1', 'params' => []]),
            'dns_zones' => ['total' => $dnsTotal, 'converged' => max(0, $dnsTotal - $dnsPending), 'pending' => $dnsPending],
            'powerdns_sync_status' => $this->distribution('dns_sync_state', 'status', ['sql' => '1=1', 'params' => []]),
            'nameserver_verification_status' => $this->distribution('domains', 'nameserver_status', ['sql' => '1=1', 'params' => []]),
            'recent_dns_errors' => DB::table('dns_sync_events')->where('status', 'failed')->orderByDesc('created_at')->limit(20)->get()->map(fn (object $r): array => (array) $r)->all(),
            'pending_dns_changes' => DB::table('dns_sync_state')->where('pending_changes', '>', 0)->orderBy('zone_name')->limit(50)->get()->map(fn (object $r): array => (array) $r)->all(),
            'origin_health_counts' => $this->distribution('domain_origins', 'health_status', ['sql' => '1=1', 'params' => []]),
            'generated_at' => UnixTime::now(),
        ]]);
    }

    public function operations(Request $request): JsonResponse
    {
        $range = $this->range($request);

        return response()->json(['data' => [
            'time_range' => $range,
            'job_queue_status_counts' => $this->distribution('ssl_jobs', 'status', ['sql' => '1=1', 'params' => []]),
            'failed_jobs_over_time' => [],
            'recent_jobs' => DB::table('ssl_jobs AS j')->leftJoin('domains AS d', 'd.id', '=', 'j.domain_id')->selectRaw('j.*, d.name AS domain_name')->orderByDesc('j.created_at')->limit(25)->get()->map(fn (object $r): array => (array) $r)->all(),
            'event_timeline' => DB::table('audit_log')->orderByDesc('created_at')->limit(25)->get()->map(fn (object $r): array => (array) $r)->all(),
            'recent_audit_entries' => DB::table('audit_log')->orderByDesc('created_at')->limit(25)->get()->map(fn (object $r): array => (array) $r)->all(),
            'most_active_actors' => $this->distribution('audit_log', 'actor_id', ['sql' => 'created_at BETWEEN :from_ts AND :to_ts', 'params' => ['from_ts' => $range['from'], 'to_ts' => $range['to']]]),
            'most_changed_resources' => $this->distribution('audit_log', 'resource_type', ['sql' => 'created_at BETWEEN :from_ts AND :to_ts', 'params' => ['from_ts' => $range['from'], 'to_ts' => $range['to']]]),
            'recent_config_snapshots' => DB::table('config_snapshots')->orderByDesc('generated_at')->limit(10)->get()->map(fn (object $r): array => ['version' => (int) $r->version, 'generated_at' => (int) $r->generated_at, 'content_hash' => (string) $r->content_hash, 'size' => strlen((string) $r->payload_json), 'active' => false])->all(),
            'unavailable' => [],
            'generated_at' => UnixTime::now(),
        ]]);
    }

    public function listRecommendations(Request $request, ?string $domainId = null): JsonResponse
    {
        $rows = DB::table('recommendations AS r')->join('domains AS d', 'd.id', '=', 'r.domain_id')->selectRaw('r.*, d.domain AS domain_name')
            ->when($domainId !== null, fn ($query) => $query->where('r.domain_id', $domainId))
            ->where('r.status', 'open')
            ->where(function ($query): void {
                $query->whereNull('r.snoozed_until')->orWhere('r.snoozed_until', '<=', UnixTime::now());
            })
            ->orderByDesc('r.confidence')
            ->orderByDesc('r.updated_at')
            ->limit(50)
            ->get()
            ->map(fn (object $row): array => $this->castRecommendation($row))
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function generateRecommendations(Request $request, ?string $domainId = null): JsonResponse
    {
        if ($domainId !== null && !DB::table('domains')->where('id', $domainId)->exists()) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }

        return response()->json(['data' => (new RecommendationService())->generate($domainId)]);
    }

    public function dismissRecommendation(string $domainId, string $recommendationId): JsonResponse
    {
        return $this->updateRecommendationStatus($domainId, $recommendationId, 'dismissed', ['dismissed_at' => UnixTime::now(), 'snoozed_until' => null]);
    }

    public function snoozeRecommendation(Request $request, string $domainId, string $recommendationId): JsonResponse
    {
        $seconds = min(max($request->integer('seconds', 86400), 3600), 2592000);
        return $this->updateRecommendationStatus($domainId, $recommendationId, 'snoozed', ['snoozed_until' => UnixTime::now() + $seconds]);
    }

    public function applyRecommendation(string $domainId, string $recommendationId): JsonResponse
    {
        $response = $this->updateRecommendationStatus($domainId, $recommendationId, 'applied', ['applied_at' => UnixTime::now()]);
        if ($response->getStatusCode() !== 200) {
            return $response;
        }
        $payload = json_decode((string) $response->getContent(), true);
        return response()->json(['data' => ['recommendation' => $payload['data'] ?? null, 'result' => ['ok' => true, 'applied' => true]]]);
    }

    private function range(Request $request): array
    {
        $bucket = (string) $request->query('bucket', 'hour');
        $bucket = isset(self::BUCKET_SECONDS[$bucket]) ? $bucket : 'hour';
        $to = is_numeric($request->query('to')) ? (int) $request->query('to') : UnixTime::now();
        $from = is_numeric($request->query('from')) ? (int) $request->query('from') : $to - match ($bucket) {
            'minute' => 3600,
            'hour' => 86400,
            'day' => 2592000,
            default => 86400,
        };
        return ['from' => $from, 'to' => $to, 'bucket' => $bucket, 'domain_id' => $request->query('domain_id') ?: null];
    }

    private function kpis(array $range): array
    {
        $where = $this->usageWhere($range);
        $usage = (array) DB::table('usage_rollups')->selectRaw("COALESCE(SUM(requests_count),0) total_requests, COALESCE(SUM(bytes_in),0) bytes_in, COALESCE(SUM(bytes_out),0) bytes_out, COALESCE(SUM(requests_count) FILTER (WHERE UPPER(cache_status)='HIT'),0) hits, COALESCE(SUM(requests_count) FILTER (WHERE status >= 500 OR router_error IS NOT NULL),0) origin_errors")->whereRaw($where['sql'], $where['params'])->first();
        $total = (int) ($usage['total_requests'] ?? 0);

        return [
            'total_requests' => $total,
            'bandwidth_in_bytes' => (int) ($usage['bytes_in'] ?? 0),
            'bandwidth_out_bytes' => (int) ($usage['bytes_out'] ?? 0),
            'cache_hit_ratio' => $total > 0 ? round(((int) ($usage['hits'] ?? 0)) / $total, 4) : 0.0,
            'active_domains' => DB::table('domains')->where('status', 'active')->count(),
            'online_edges' => DB::table('edge_nodes')->where('status', 'online')->count(),
            'offline_edges' => DB::table('edge_nodes')->where('status', '!=', 'online')->count(),
            'security_events' => DB::table('audit_log')->whereIn('event', self::SECURITY_EVENTS)->whereBetween('created_at', [$range['from'], $range['to']])->count(),
            'waf_blocks' => DB::table('audit_log')->where('event', 'waf_match')->whereBetween('created_at', [$range['from'], $range['to']])->count(),
            'rate_limited_requests' => DB::table('audit_log')->where('event', 'rate_limited')->whereBetween('created_at', [$range['from'], $range['to']])->count(),
            'origin_errors' => (int) ($usage['origin_errors'] ?? 0),
            'ssl_expiring_count' => 0,
            'pending_dns_changes' => DB::table('dns_sync_state')->where('pending_changes', '>', 0)->count(),
            'failed_jobs' => DB::table('ssl_jobs')->whereIn('status', ['failed', 'cancelled'])->count(),
        ];
    }

    private function usageWhere(array $range): array
    {
        $where = ['ts BETWEEN :from_ts AND :to_ts'];
        $params = ['from_ts' => $range['from'], 'to_ts' => $range['to']];
        if (!empty($range['domain_id'])) {
            $where[] = 'domain_id = :domain_id';
            $params['domain_id'] = $range['domain_id'];
        }
        return ['sql' => implode(' AND ', $where), 'params' => $params];
    }

    private function auditWhere(array $range, bool $securityOnly = false): array
    {
        $where = ['created_at BETWEEN :from_ts AND :to_ts'];
        $params = ['from_ts' => $range['from'], 'to_ts' => $range['to']];
        if (!empty($range['domain_id'])) {
            $where[] = 'domain_id = :domain_id';
            $params['domain_id'] = $range['domain_id'];
        }
        if ($securityOnly) {
            $where[] = "event IN ('" . implode("','", self::SECURITY_EVENTS) . "')";
        }
        return ['sql' => implode(' AND ', $where), 'params' => $params];
    }

    private function distribution(string $table, string $column, array $where): array
    {
        return DB::table($table)->selectRaw("COALESCE({$column}::text, 'unknown') AS value, COALESCE({$column}::text, 'unknown') AS status, COUNT(*) AS count")->whereRaw($where['sql'], $where['params'])->groupByRaw("COALESCE({$column}::text, 'unknown')")->orderByDesc('count')->limit(10)->get()->map(fn (object $r): array => ['value' => (string) $r->value, 'status' => (string) $r->status, 'count' => (int) $r->count])->all();
    }

    private function topUsage(string $column, array $where): array
    {
        return DB::table('usage_rollups')->selectRaw("COALESCE({$column}, 'unknown') AS value, COALESCE(SUM(requests_count),0) AS requests, COALESCE(SUM(requests_count),0) AS count, COALESCE(SUM(bytes_out),0) AS bytes_out")->whereRaw($where['sql'], $where['params'])->groupByRaw("COALESCE({$column}, 'unknown')")->orderByDesc('requests')->limit(10)->get()->map(fn (object $r): array => (array) $r)->all();
    }

    private function topDomains(array $where): array
    {
        return DB::table('usage_rollups AS u')->join('domains AS d', 'd.id', '=', 'u.domain_id')->selectRaw('d.id AS domain_id, d.name, d.domain, COALESCE(SUM(u.requests_count),0) AS requests')->whereRaw($this->qualifyUsageWhere($where['sql'], 'u'), $where['params'])->groupBy('d.id', 'd.name', 'd.domain')->orderByDesc('requests')->limit(10)->get()->map(fn (object $r): array => ['domain_id' => (string) $r->domain_id, 'name' => (string) $r->name, 'domain' => (string) $r->domain, 'requests' => (int) $r->requests])->all();
    }

    private function trafficByEdge(array $where): array
    {
        return DB::table('usage_rollups AS u')->leftJoin('edge_nodes AS e', 'e.edge_id', '=', 'u.edge_node_id')->selectRaw('u.edge_node_id, e.hostname, COALESCE(SUM(u.requests_count),0) AS requests, COALESCE(SUM(u.bytes_out),0) AS bytes_out')->whereRaw($this->qualifyUsageWhere($where['sql'], 'u'), $where['params'])->groupBy('u.edge_node_id', 'e.hostname')->orderByDesc('requests')->limit(10)->get()->map(fn (object $r): array => ['edge_node_id' => (string) $r->edge_node_id, 'hostname' => $r->hostname, 'requests' => (int) $r->requests, 'bytes_out' => (int) $r->bytes_out])->all();
    }

    private function errorRateByEdge(array $where): array
    {
        return DB::table('usage_rollups AS u')->leftJoin('edge_nodes AS e', 'e.edge_id', '=', 'u.edge_node_id')->selectRaw("u.edge_node_id, e.hostname, COALESCE(SUM(u.requests_count),0) AS requests, COALESCE(SUM(u.requests_count) FILTER (WHERE u.status >= 500 OR u.router_error IS NOT NULL),0) AS errors")->whereRaw($this->qualifyUsageWhere($where['sql'], 'u'), $where['params'])->groupBy('u.edge_node_id', 'e.hostname')->orderByDesc('errors')->limit(10)->get()->map(fn (object $r): array => ['edge_node_id' => (string) $r->edge_node_id, 'hostname' => $r->hostname, 'requests' => (int) $r->requests, 'errors' => (int) $r->errors, 'error_rate' => ((int) $r->requests) > 0 ? round(((int) $r->errors) / ((int) $r->requests), 4) : 0])->all();
    }

    private function problemRequests(array $where): array
    {
        return DB::table('usage_rollups')->whereRaw($where['sql'], $where['params'])->whereRaw('(status >= 500 OR router_error IS NOT NULL)')->orderByDesc('ts')->limit(20)->get()->map(fn (object $r): array => (array) $r)->all();
    }

    private function topUncachedPaths(array $where): array
    {
        return DB::table('usage_rollups')->selectRaw("COALESCE(path, 'unknown') AS path, COALESCE(SUM(requests_count),0) AS requests, COALESCE(SUM(bytes_out),0) AS bytes_out")->whereRaw($where['sql'], $where['params'])->whereRaw("UPPER(cache_status) != 'HIT'")->groupByRaw("COALESCE(path, 'unknown')")->orderByDesc('requests')->limit(10)->get()->map(fn (object $r): array => ['path' => (string) $r->path, 'requests' => (int) $r->requests, 'bytes_out' => (int) $r->bytes_out])->all();
    }

    private function purgeTimeline(array $range): array
    {
        $bucket = self::BUCKET_SECONDS[$range['bucket']];
        return DB::table('cache_purge_requests')->selectRaw("(FLOOR(created_at / {$bucket}) * {$bucket})::BIGINT AS bucket_ts, COUNT(*) AS count")->whereBetween('created_at', [$range['from'], $range['to']])->groupByRaw("(FLOOR(created_at / {$bucket}) * {$bucket})")->orderBy('bucket_ts')->get()->map(fn (object $r): array => ['bucket_ts' => (int) $r->bucket_ts, 'count' => (int) $r->count])->all();
    }

    private function edgeDistribution(string $column): array
    {
        return DB::table('edge_nodes')->selectRaw("COALESCE({$column}, 'unknown') AS value, COUNT(*) AS count")->groupByRaw("COALESCE({$column}, 'unknown')")->orderByDesc('count')->get()->map(fn (object $r): array => ['value' => (string) $r->value, 'count' => (int) $r->count])->all();
    }

    private function securityActionDistribution(array $where, string $event): array
    {
        return DB::table('audit_log')
            ->selectRaw("COALESCE(details_json::jsonb->>'decision', action, 'unknown') AS action, COUNT(*) AS count")
            ->whereRaw($where['sql'] . ' AND event = :action_event', $where['params'] + ['action_event' => $event])
            ->groupByRaw("COALESCE(details_json::jsonb->>'decision', action, 'unknown')")
            ->orderByDesc('count')
            ->get()
            ->map(fn (object $r): array => ['action' => (string) $r->action, 'count' => (int) $r->count])
            ->all();
    }

    private function topSecurityJson(array $where, string $key): array
    {
        return DB::table('audit_log')->selectRaw("COALESCE(NULLIF(details_json::jsonb->>'{$key}', ''), 'unknown') AS value, COUNT(*) AS count")->whereRaw($where['sql'], $where['params'])->groupByRaw("COALESCE(NULLIF(details_json::jsonb->>'{$key}', ''), 'unknown')")->orderByDesc('count')->limit(10)->get()->map(fn (object $r): array => ['value' => (string) $r->value, 'count' => (int) $r->count])->all();
    }

    private function topSecurityDomains(array $where): array
    {
        return DB::table('audit_log AS a')->leftJoin('domains AS d', 'd.id', '=', 'a.domain_id')->selectRaw('a.domain_id, d.name, COUNT(*) AS count')->whereRaw($this->qualifyAuditWhere($where['sql'], 'a'), $where['params'])->groupBy('a.domain_id', 'd.name')->orderByDesc('count')->limit(10)->get()->map(fn (object $r): array => ['domain_id' => $r->domain_id, 'name' => $r->name, 'count' => (int) $r->count])->all();
    }

    private function qualifyUsageWhere(string $sql, string $alias): string
    {
        return str_replace(['ts BETWEEN', 'domain_id ='], ["{$alias}.ts BETWEEN", "{$alias}.domain_id ="], $sql);
    }

    private function qualifyAuditWhere(string $sql, string $alias): string
    {
        return str_replace(['created_at BETWEEN', 'domain_id =', 'event IN'], ["{$alias}.created_at BETWEEN", "{$alias}.domain_id =", "{$alias}.event IN"], $sql);
    }

    private function recentSecurityEvents(array $where): array
    {
        return DB::table('audit_log')->whereRaw($where['sql'], $where['params'])->orderByDesc('created_at')->limit(20)->get()->map(fn (object $r): array => ['id' => (string) $r->id, 'domain_id' => $r->domain_id, 'actor_id' => $r->actor_id, 'edge_id' => $r->actor_id, 'type' => $r->event, 'action' => $r->action, 'created_at' => (int) $r->created_at, 'details' => json_decode((string) $r->details_json, true)])->all();
    }

    private function recommendationCandidates(string $domainId): array
    {
        $since = UnixTime::now() - 86400;
        $errors = DB::table('usage_rollups')->where('domain_id', $domainId)->where('ts', '>=', $since)->where(function ($q): void { $q->where('status', '>=', 500)->orWhereNotNull('router_error'); })->sum('requests_count');
        $total = DB::table('usage_rollups')->where('domain_id', $domainId)->where('ts', '>=', $since)->sum('requests_count');
        $hits = DB::table('usage_rollups')->where('domain_id', $domainId)->where('ts', '>=', $since)->whereRaw("UPPER(cache_status)='HIT'")->sum('requests_count');
        $security = DB::table('audit_log')->where('domain_id', $domainId)->whereIn('event', self::SECURITY_EVENTS)->where('created_at', '>=', $since)->count();
        $candidates = [];
        if ($errors >= 3) {
            $candidates[] = $this->candidate('origin_diagnostics', 'Run origin diagnostics', 'The edge has seen repeated origin or routing failures.', 'Request diagnostics include multiple 5xx responses or router errors in the last 24 hours.', min(95, 70 + (int) $errors), 'safe', 'reliability', ['origin_errors_24h' => (int) $errors], ['kind' => 'run_origin_test']);
        }
        if ($total >= 10 && ($hits / max(1, $total)) < 0.4) {
            $candidates[] = $this->candidate('static_asset_cache', 'Enable static asset caching', 'Cache hit ratio is low for recent traffic.', 'Cache analytics show a low hit ratio over the last 24 hours.', 78, 'safe', 'performance', ['requests_24h' => (int) $total], ['kind' => 'enable_static_asset_cache']);
        }
        if ($security >= 3) {
            $candidates[] = $this->candidate('common_exploits', 'Review exploit protection', 'Security events are recurring for this domain.', 'Security events include repeated protection decisions in the last 24 hours.', min(95, 70 + (int) $security), 'moderate', 'security', ['security_events_24h' => (int) $security], ['kind' => 'enable_protection_intent', 'intent_key' => 'common_exploits']);
        }
        return $candidates;
    }

    private function candidate(string $type, string $title, string $message, string $why, int $confidence, string $risk, string $impact, array $preview, array $action): array
    {
        return compact('type', 'title', 'message', 'why', 'confidence', 'risk', 'impact') + ['preview_payload' => $preview, 'one_click_action' => $action];
    }

    private function upsertRecommendation(string $domainId, array $candidate): ?array
    {
        $now = UnixTime::now();
        $existing = DB::table('recommendations')->where('domain_id', $domainId)->where('type', $candidate['type'])->first();
        if ($existing && in_array((string) $existing->status, ['applied', 'dismissed'], true)) {
            return null;
        }
        $values = [
            'domain_id' => $domainId,
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
        if ($existing) {
            DB::table('recommendations')->where('id', $existing->id)->update($values);
            return $this->castRecommendation(DB::table('recommendations AS r')->join('domains AS d', 'd.id', '=', 'r.domain_id')->selectRaw('r.*, d.domain AS domain_name')->where('r.id', $existing->id)->first());
        }
        $id = (string) Str::uuid();
        DB::table('recommendations')->insert($values + ['id' => $id, 'created_at' => $now]);
        return $this->castRecommendation(DB::table('recommendations AS r')->join('domains AS d', 'd.id', '=', 'r.domain_id')->selectRaw('r.*, d.domain AS domain_name')->where('r.id', $id)->first());
    }

    private function updateRecommendationStatus(string $domainId, string $recommendationId, string $status, array $extra): JsonResponse
    {
        $now = UnixTime::now();
        $updated = DB::table('recommendations')->where('domain_id', $domainId)->where('id', $recommendationId)->update($extra + ['status' => $status, 'updated_at' => $now]);
        if ($updated === 0) {
            return response()->json(['error' => 'recommendation_not_found'], 404);
        }
        $row = DB::table('recommendations AS r')->join('domains AS d', 'd.id', '=', 'r.domain_id')->selectRaw('r.*, d.domain AS domain_name')->where('r.id', $recommendationId)->first();
        DB::table('audit_log')->insert(['id' => (string) Str::uuid(), 'actor_type' => 'system', 'actor_id' => null, 'action' => "recommendation.{$status}", 'resource_type' => 'recommendation', 'resource_id' => $recommendationId, 'domain_id' => $domainId, 'details_json' => json_encode(['status' => $status]), 'event' => null, 'created_at' => $now]);
        return response()->json(['data' => $this->castRecommendation($row)]);
    }

    private function castRecommendation(?object $row): array
    {
        return [
            'id' => (string) $row->id,
            'domain_id' => (string) $row->domain_id,
            'domain_name' => $row->domain_name ?? null,
            'type' => (string) $row->type,
            'title' => (string) $row->title,
            'message' => (string) $row->message,
            'why' => (string) $row->why,
            'confidence' => (int) $row->confidence,
            'risk' => (string) $row->risk,
            'impact' => (string) $row->impact,
            'preview_payload' => json_decode((string) $row->preview_payload, true) ?: [],
            'one_click_action' => json_decode((string) $row->one_click_action, true) ?: [],
            'status' => (string) $row->status,
            'snoozed_until' => $row->snoozed_until === null ? null : (int) $row->snoozed_until,
            'dismissed_at' => $row->dismissed_at === null ? null : (int) $row->dismissed_at,
            'applied_at' => $row->applied_at === null ? null : (int) $row->applied_at,
            'created_at' => (int) $row->created_at,
            'updated_at' => (int) $row->updated_at,
        ];
    }
}
