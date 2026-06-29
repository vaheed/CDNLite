<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ControlPlane\UnixTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CollectorController extends Controller
{
    private const MAX_ITEMS = 1000;
    private const MAX_PAYLOAD_BYTES = 1048576;
    private const DEFAULT_ANALYTICS_POINTS = 500;
    private const BUCKET_SECONDS = [
        'minute' => 60,
        'hour' => 3600,
        'day' => 86400,
    ];
    private const SECURITY_EVENTS = [
        'waf_match' => true,
        'rate_limited' => true,
        'bot_match' => true,
        'geo_block' => true,
        'ip_block' => true,
        'challenge' => true,
        'waiting_room' => true,
    ];

    public function usage(Request $request): JsonResponse
    {
        $events = $request->input('events', $request->input('items'));
        if (!is_array($events)) {
            return response()->json(['error' => 'items_must_be_array'], 422);
        }
        $sizeError = $this->validateBatchSize($request, $events);
        if ($sizeError !== null) {
            return $sizeError;
        }

        $result = $this->recordBatch($request, 'usage', $events);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function securityEvents(Request $request): JsonResponse
    {
        $items = $request->input('items');
        if (!is_array($items)) {
            return response()->json(['error' => 'items_must_be_array'], 422);
        }
        $sizeError = $this->validateBatchSize($request, $items);
        if ($sizeError !== null) {
            return $sizeError;
        }

        $result = $this->recordBatch($request, 'security', $items);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function usageSummary(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->usageSummaryPayload($request, $request->query('domain_id'))]);
    }

    public function domainUsageSummary(Request $request, string $domainId): JsonResponse
    {
        if (!$this->domainExists($domainId)) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }

        return response()->json(['data' => $this->usageSummaryPayload($request, $domainId)]);
    }

    public function recalculateUsage(Request $request): JsonResponse
    {
        $domainId = $this->nullableString($request->input('domain_id'));
        if ($domainId !== null && !$this->domainExists($domainId)) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }

        $bucket = $this->nullableString($request->input('bucket'));
        if ($bucket !== null && !isset(self::BUCKET_SECONDS[$bucket])) {
            return response()->json(['error' => 'bucket_must_be_one_of_minute_hour_day'], 422);
        }

        $jobId = (string) Str::uuid();
        $now = UnixTime::now();
        $range = $this->rollupRange($request, $bucket);

        DB::table('analytics_rollup_jobs')->insert([
            'id' => $jobId,
            'domain_id' => $domainId,
            'bucket' => $bucket,
            'range_start' => $range['from'],
            'range_end' => $range['to'],
            'status' => 'queued',
            'requested_by' => 'api',
            'progress_json' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $result = $this->runRollupJob($jobId);

        return response()->json([
            'data' => [
                'ok' => true,
                'accepted' => true,
                'status' => 202,
                'job_id' => $jobId,
                'domain_id' => $domainId,
                'bucket' => $bucket,
                'range' => $range,
                'job_status' => (string) ($result['status'] ?? 'succeeded'),
                'inserted' => $result['inserted'] ?? [],
            ],
        ], 202);
    }

    public function recalculateJob(string $jobId): JsonResponse
    {
        $row = DB::table('analytics_rollup_jobs')->where('id', $jobId)->first();
        if (!$row) {
            return response()->json(['error' => 'job_not_found'], 404);
        }

        return response()->json(['data' => $this->castRollupJob($row)]);
    }

    public function cacheAnalytics(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->cacheAnalyticsPayload($request, null)]);
    }

    public function domainCacheAnalytics(Request $request, string $domainId): JsonResponse
    {
        if (!$this->domainExists($domainId)) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }

        return response()->json(['data' => $this->cacheAnalyticsPayload($request, $domainId)]);
    }

    public function recentRequests(Request $request, string $domainId): JsonResponse
    {
        if (!$this->domainExists($domainId)) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }

        $limit = min(max($request->integer('limit', 25), 1), 250);
        $offset = max($request->integer('offset', 0), 0);
        [$where, $params] = $this->requestWhere($domainId, $request);
        $total = DB::table('usage_rollups')->whereRaw(implode(' AND ', $where), $params)->count();
        $rows = DB::table('usage_rollups')
            ->select($this->requestColumns())
            ->whereRaw(implode(' AND ', $where), $params)
            ->orderByDesc('ts')
            ->orderByDesc('id')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(fn (object $row): array => $this->castRequestRow($row))
            ->all();

        return response()->json(['data' => ['items' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset]]);
    }

    public function findRequest(string $domainId, string $requestId): JsonResponse
    {
        if (!$this->domainExists($domainId)) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }

        $row = DB::table('usage_rollups')
            ->select($this->requestColumns())
            ->where('domain_id', $domainId)
            ->where('request_id', $requestId)
            ->orderByDesc('ts')
            ->orderByDesc('id')
            ->first();

        if (!$row) {
            return response()->json(['error' => 'request_not_found'], 404);
        }

        return response()->json(['data' => $this->castRequestRow($row)]);
    }

    public function activityTimeline(Request $request, string $domainId): JsonResponse
    {
        if (!$this->domainExists($domainId)) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }

        return response()->json(['data' => $this->activityTimelinePayload($request, $domainId)]);
    }

    public function activitySummary(Request $request, string $domainId): JsonResponse
    {
        if (!$this->domainExists($domainId)) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }

        [$where, $params] = $this->timeWhere($domainId, $request, 'ts');
        $whereSql = implode(' AND ', $where);
        $from = isset($params['from_ts']) ? (int) $params['from_ts'] : null;
        $to = isset($params['to_ts']) ? (int) $params['to_ts'] : null;
        $row = (array) DB::table('usage_rollups')
            ->selectRaw("
                COALESCE(SUM(requests_count),0) AS total_requests,
                COALESCE(SUM(bytes_in),0) AS bytes_in,
                COALESCE(SUM(bytes_out),0) AS bytes_out,
                COALESCE(SUM(requests_count) FILTER (WHERE status BETWEEN 200 AND 299),0) AS status_2xx,
                COALESCE(SUM(requests_count) FILTER (WHERE status BETWEEN 300 AND 399),0) AS status_3xx,
                COALESCE(SUM(requests_count) FILTER (WHERE status BETWEEN 400 AND 499),0) AS status_4xx,
                COALESCE(SUM(requests_count) FILTER (WHERE status >= 500),0) AS status_5xx,
                COALESCE(SUM(requests_count) FILTER (WHERE status = 502),0) AS status_502,
                COALESCE(SUM(requests_count) FILTER (WHERE origin_id IS NOT NULL),0) AS forwarded_requests,
                COALESCE(SUM(requests_count) FILTER (WHERE UPPER(cache_status)='HIT'),0) AS cache_hits,
                COALESCE(SUM(requests_count) FILTER (WHERE UPPER(cache_status)='MISS'),0) AS cache_misses
            ")
            ->whereRaw($whereSql, $params)
            ->first();
        $hitMiss = (int) ($row['cache_hits'] ?? 0) + (int) ($row['cache_misses'] ?? 0);
        $status5xx = (int) ($row['status_5xx'] ?? 0);

        return response()->json(['data' => [
            'total_requests' => (int) ($row['total_requests'] ?? 0),
            'forwarded_requests' => (int) ($row['forwarded_requests'] ?? 0),
            'bytes_in' => (int) ($row['bytes_in'] ?? 0),
            'bytes_out' => (int) ($row['bytes_out'] ?? 0),
            'cache_hit_ratio' => $hitMiss > 0 ? round(((int) ($row['cache_hits'] ?? 0)) / $hitMiss, 4) : 0.0,
            'status_counts' => [
                '2xx' => (int) ($row['status_2xx'] ?? 0),
                '3xx' => (int) ($row['status_3xx'] ?? 0),
                '4xx' => (int) ($row['status_4xx'] ?? 0),
                '5xx' => $status5xx,
                '502' => (int) ($row['status_502'] ?? 0),
            ],
            'top_paths' => $this->topUsageDimension('path', $whereSql, $params),
            'top_countries' => $this->topUsageDimension('client_country', $whereSql, $params),
            'top_origins' => $this->topUsageDimension('origin_id', $whereSql, $params),
            'top_edge_nodes' => $this->topUsageDimension('edge_node_id', $whereSql, $params),
            'recent_origin_errors' => $this->recentOriginErrors($whereSql, $params),
            'beginner' => [
                'headline' => $status5xx > 0 ? 'Recent edge activity includes origin or routing errors.' : 'Recent edge activity is available for this domain.',
                'counts' => ['errors' => $status5xx, 'security' => $this->securityCount($domainId, $request)],
                'cards' => [
                    ['key' => 'errors', 'label' => '5xx responses', 'count' => $status5xx, 'category' => 'reliability'],
                    ['key' => 'security', 'label' => 'Security events', 'count' => $this->securityCount($domainId, $request), 'category' => 'security'],
                ],
                'recommendations' => [],
            ],
        ]]);
    }

    public function activityExport(Request $request, string $domainId): JsonResponse
    {
        if (!$this->domainExists($domainId)) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }

        $timeline = $this->activityTimelinePayload($request, $domainId);

        return response()->json(['data' => [
            'domain_id' => $domainId,
            'generated_at' => UnixTime::now(),
            'format' => 'json',
            'items' => $timeline['items'],
        ]]);
    }

    public function securityEventList(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->securityEventsPayload($request, $request->query('domain_id'))]);
    }

    public function domainSecurityEvents(Request $request, string $domainId): JsonResponse
    {
        if (!$this->domainExists($domainId)) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }

        return response()->json(['data' => $this->securityEventsPayload($request, $domainId)]);
    }

    public function securitySummary(Request $request): JsonResponse
    {
        [$where, $params] = $this->securityWhere($request, $request->query('domain_id'));
        $whereSql = implode(' AND ', $where);
        $total = DB::table('audit_log')->whereRaw($whereSql, $params)->count();
        $byType = DB::table('audit_log')
            ->selectRaw("COALESCE(event, 'unknown') AS type, COUNT(*) AS count")
            ->whereRaw($whereSql, $params)
            ->groupByRaw("COALESCE(event, 'unknown')")
            ->pluck('count', 'type')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
        [$joinedWhere, $joinedParams] = $this->securityWhere($request, $request->query('domain_id'), 'a.');
        $topDomains = DB::table('audit_log AS a')
            ->leftJoin('domains AS d', 'd.id', '=', 'a.domain_id')
            ->selectRaw('a.domain_id, d.name, COUNT(*) AS count')
            ->whereRaw(implode(' AND ', $joinedWhere), $joinedParams)
            ->groupBy('a.domain_id', 'd.name')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => ['domain_id' => $row->domain_id, 'name' => $row->name, 'count' => (int) $row->count])
            ->all();

        return response()->json(['data' => [
            'total' => $total,
            'by_type' => $byType,
            'top_ips' => $this->topSecurityJsonValue($whereSql, $params, 'client_ip'),
            'top_domains' => $topDomains,
        ]]);
    }

    /**
     * Store bounded edge batches in one transaction so reports never see a
     * receipt without the corresponding accepted or rejected event rows.
     *
     * @param array<int,mixed> $events
     * @return array<string,mixed>
     */
    private function recordBatch(Request $request, string $stream, array $events): array
    {
        $idempotencyKey = (string) ($request->input('idempotency_key') ?: $request->header('X-Idempotency-Key') ?: Str::uuid());
        $batchId = (string) Str::uuid();
        $now = UnixTime::now();
        $sourceEdgeId = (string) $request->attributes->get('edge_id', 'unknown');
        $qualifiedKey = "{$stream}:{$idempotencyKey}";
        $accepted = 0;
        $rejected = 0;
        $firstTs = null;
        $lastTs = null;

        if (DB::table('telemetry_ingest_batches')->where('idempotency_key', $qualifiedKey)->exists()) {
            return ['ok' => true, 'accepted' => 0, 'rejected' => 0, 'duplicate' => true];
        }

        DB::transaction(function () use ($request, $stream, $events, $batchId, $sourceEdgeId, $qualifiedKey, $now, &$accepted, &$rejected, &$firstTs, &$lastTs): void {
            DB::table('telemetry_ingest_batches')->insert([
                'batch_id' => $batchId,
                'source_edge_id' => $sourceEdgeId,
                'idempotency_key' => $qualifiedKey,
                'event_count' => count($events),
                'accepted_count' => 0,
                'rejected_count' => 0,
                'first_event_ts' => null,
                'last_event_ts' => null,
                'payload_bytes' => strlen($request->getContent()),
                'status' => 'accepted',
                'rejection_reason' => null,
                'ingested_at' => $now,
            ]);

            foreach ($events as $index => $event) {
                if (!is_array($event)) {
                    $this->recordRejectedEvent($batchId, $sourceEdgeId, null, null, 'event_must_be_object', ['index' => $index], $now);
                    $rejected++;
                    continue;
                }

                $ts = isset($event['ts']) && is_numeric($event['ts']) ? (int) $event['ts'] : $now;
                $firstTs = $firstTs === null ? $ts : min($firstTs, $ts);
                $lastTs = $lastTs === null ? $ts : max($lastTs, $ts);

                $reason = $stream === 'usage'
                    ? $this->storeUsageEvent($event, $sourceEdgeId, $ts)
                    : $this->storeSecurityEvent($event, $sourceEdgeId, $ts);

                if ($reason === null) {
                    $accepted++;
                    continue;
                }

                $this->recordRejectedEvent(
                    $batchId,
                    $sourceEdgeId,
                    isset($event['request_id']) ? (string) $event['request_id'] : null,
                    $ts,
                    $reason,
                    $this->payloadExcerpt($event),
                    $now
                );
                $rejected++;
            }

            DB::table('telemetry_ingest_batches')->where('batch_id', $batchId)->update([
                'accepted_count' => $accepted,
                'rejected_count' => $rejected,
                'first_event_ts' => $firstTs,
                'last_event_ts' => $lastTs,
                'status' => $accepted > 0 && $rejected > 0 ? 'partial' : ($accepted > 0 ? 'accepted' : 'rejected'),
                'rejection_reason' => $accepted === 0 && $rejected > 0 ? 'no_valid_events' : null,
            ]);
        });

        return [
            'ok' => $accepted > 0 || count($events) === 0,
            'accepted' => $accepted,
            'rejected' => $rejected,
            'duplicate' => false,
        ];
    }

    /** @param array<int,mixed> $events */
    private function validateBatchSize(Request $request, array $events): ?JsonResponse
    {
        if (count($events) > self::MAX_ITEMS) {
            return response()->json(['error' => 'telemetry_batch_too_large', 'max_items' => self::MAX_ITEMS], 413);
        }
        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return response()->json(['error' => 'telemetry_payload_too_large', 'max_payload_bytes' => self::MAX_PAYLOAD_BYTES], 413);
        }

        return null;
    }

    /** @param array<string,mixed> $event */
    private function storeUsageEvent(array $event, string $sourceEdgeId, int $ts): ?string
    {
        $domainId = trim((string) ($event['domain_id'] ?? ''));
        if ($domainId === '' || !$this->domainExists($domainId)) {
            return 'unknown_domain';
        }

        DB::table('usage_rollups')->insert([
            'id' => (string) Str::uuid(),
            'ts' => $ts,
            'domain_id' => $domainId,
            'edge_node_id' => trim((string) ($event['edge_node_id'] ?? $sourceEdgeId)),
            'requests_count' => max(1, (int) ($event['requests_count'] ?? 1)),
            'bytes_in' => max(0, (int) ($event['bytes_in'] ?? 0)),
            'bytes_out' => max(0, (int) ($event['bytes_out'] ?? 0)),
            'status' => max(0, (int) ($event['status'] ?? 0)),
            'cache_status' => strtoupper(trim((string) ($event['cache_status'] ?? 'UNKNOWN'))) ?: 'UNKNOWN',
            'rule_id' => $this->nullableString($event['rule_id'] ?? null),
            'request_id' => $this->nullableString($event['request_id'] ?? null),
            'origin_status' => isset($event['origin_status']) ? (int) $event['origin_status'] : null,
            'origin_time_ms' => isset($event['origin_time_ms']) ? (int) $event['origin_time_ms'] : null,
            'host' => $this->nullableString($event['host'] ?? null),
            'method' => $this->nullableString($event['method'] ?? null),
            'path' => $this->nullableString($event['path'] ?? null),
            'query_redacted' => isset($event['query_redacted']) || isset($event['query'])
                ? json_encode($event['query_redacted'] ?? $event['query'], JSON_UNESCAPED_SLASHES)
                : null,
            'client_ip' => $this->nullableString($event['client_ip'] ?? null),
            'client_country' => $this->nullableString($event['client_country'] ?? null),
            'origin_id' => $this->nullableString($event['origin_id'] ?? null),
            'origin_host' => $this->nullableString($event['origin_host'] ?? null),
            'upstream_status' => $this->nullableString($event['upstream_status'] ?? null),
            'upstream_response_time_ms' => $this->durationMs($event['upstream_response_time_ms'] ?? $event['upstream_response_time'] ?? null),
            'upstream_addr' => $this->nullableString($event['upstream_addr'] ?? null),
            'request_time_ms' => $this->durationMs($event['request_time_ms'] ?? $event['request_time'] ?? null),
            'router_error' => $this->nullableString($event['router_error'] ?? null),
            'security_event_type' => $this->nullableString($event['security_event_type'] ?? null),
        ]);

        return null;
    }

    /** @param array<string,mixed> $event */
    private function storeSecurityEvent(array $event, string $sourceEdgeId, int $ts): ?string
    {
        $type = trim((string) ($event['type'] ?? ''));
        if (!isset(self::SECURITY_EVENTS[$type])) {
            return 'unsupported_security_event';
        }

        $domainId = trim((string) ($event['domain_id'] ?? ''));
        if ($domainId === '' || !$this->domainExists($domainId)) {
            return 'unknown_domain';
        }

        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid(),
            'actor_type' => 'edge',
            'actor_id' => $sourceEdgeId,
            'action' => (string) ($event['action'] ?? 'inspect'),
            'resource_type' => $type === 'rate_limited' ? 'rate_limit' : 'security',
            'resource_id' => $this->nullableString($event['rule_id'] ?? $event['rate_limit_id'] ?? null),
            'domain_id' => $domainId,
            'details_json' => json_encode($this->securityDetails($event), JSON_UNESCAPED_SLASHES),
            'event' => $type,
            'before_json' => null,
            'after_json' => null,
            'created_at' => $ts,
        ]);

        return null;
    }

    /** @param array<string,mixed> $event */
    private function securityDetails(array $event): array
    {
        return [
            'decision' => (string) ($event['action'] ?? ''),
            'request_id' => (string) ($event['request_id'] ?? ''),
            'method' => (string) ($event['method'] ?? ''),
            'path' => (string) ($event['path'] ?? ''),
            'client_ip' => $this->nullableString($event['client_ip'] ?? null),
            'country' => $this->nullableString($event['country'] ?? $event['client_country'] ?? null),
            'rule_id' => $this->nullableString($event['rule_id'] ?? null),
            'rate_limit_id' => $this->nullableString($event['rate_limit_id'] ?? null),
            'severity' => $this->nullableString($event['severity'] ?? null),
            'confidence' => $this->nullableString($event['confidence'] ?? null),
            'bot_class' => $this->nullableString($event['bot_class'] ?? null),
            'bot_score' => isset($event['bot_score']) ? (int) $event['bot_score'] : null,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function recordRejectedEvent(string $batchId, string $sourceEdgeId, ?string $eventId, ?int $eventTs, string $reason, array $payload, int $now): void
    {
        DB::table('telemetry_rejected_events')->insert([
            'id' => (string) Str::uuid(),
            'batch_id' => $batchId,
            'source_edge_id' => $sourceEdgeId,
            'event_id' => $eventId,
            'event_ts' => $eventTs,
            'reason' => $reason,
            'payload_excerpt' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
        ]);
    }

    private function domainExists(string $domainId): bool
    {
        return DB::table('domains')->where('id', $domainId)->exists();
    }

    private function usageSummaryPayload(Request $request, mixed $domainId): array
    {
        $domainId = is_string($domainId) && trim($domainId) !== '' ? trim($domainId) : null;
        $bucket = (string) $request->query('bucket', '');
        if ($bucket !== '' && !isset(self::BUCKET_SECONDS[$bucket])) {
            $bucket = '';
        }

        [$where, $params] = $this->timeWhere($domainId, $request, 'ts');
        $whereSql = implode(' AND ', $where);
        $row = (array) DB::table('usage_rollups')
            ->selectRaw("
                COALESCE(SUM(requests_count),0) AS requests_count,
                COALESCE(SUM(bytes_in),0) AS bytes_in,
                COALESCE(SUM(bytes_out),0) AS bytes_out,
                COALESCE(SUM(requests_count) FILTER (WHERE UPPER(cache_status)='HIT'),0) AS cache_hits,
                COALESCE(SUM(requests_count) FILTER (WHERE UPPER(cache_status) IN ('HIT','MISS')),0) AS cache_known,
                COUNT(*) AS records
            ")
            ->whereRaw($whereSql, $params)
            ->first();

        $points = [];
        $limitPoints = self::DEFAULT_ANALYTICS_POINTS;
        if ($bucket !== '') {
            $seconds = self::BUCKET_SECONDS[$bucket];
            $limitPoints = max(1, min(self::DEFAULT_ANALYTICS_POINTS, $request->integer('limit_points', self::DEFAULT_ANALYTICS_POINTS)));
            $points = DB::table('usage_rollups')
                ->selectRaw("(FLOOR(ts / {$seconds}) * {$seconds})::BIGINT AS bucket_ts, COALESCE(SUM(requests_count),0) AS requests_count, COALESCE(SUM(bytes_in),0) AS bytes_in, COALESCE(SUM(bytes_out),0) AS bytes_out")
                ->whereRaw($whereSql, $params)
                ->groupByRaw("(FLOOR(ts / {$seconds}) * {$seconds})")
                ->orderBy('bucket_ts')
                ->limit($limitPoints)
                ->get()
                ->map(fn (object $point): array => [
                    'bucket_ts' => (int) $point->bucket_ts,
                    'requests_count' => (int) $point->requests_count,
                    'bytes_in' => (int) $point->bytes_in,
                    'bytes_out' => (int) $point->bytes_out,
                ])
                ->all();
        }

        $cacheKnown = (int) ($row['cache_known'] ?? 0);

        return [
            'domain_id' => $domainId,
            'bucket' => $bucket !== '' ? $bucket : null,
            'effective_range' => ['from' => $from, 'to' => $to, 'timezone' => 'UTC'],
            'requests_count' => (int) ($row['requests_count'] ?? 0),
            'total_requests' => (int) ($row['requests_count'] ?? 0),
            'requests' => (int) ($row['requests_count'] ?? 0),
            'bytes_in' => (int) ($row['bytes_in'] ?? 0),
            'bytes_out' => (int) ($row['bytes_out'] ?? 0),
            'records' => (int) ($row['records'] ?? 0),
            'cache_hit_ratio' => $cacheKnown > 0 ? round(((int) ($row['cache_hits'] ?? 0)) / $cacheKnown, 4) : 0.0,
            'points' => $points,
            'point_count' => count($points),
            'freshness' => $this->analyticsFreshness($domainId, $bucket !== '' ? $bucket : null),
            'aggregation_watermark' => $this->aggregationWatermark($domainId, $bucket !== '' ? $bucket : null),
            'partial_data' => count($points) >= $limitPoints,
            'query_id' => sha1(json_encode([$domainId, $bucket, $from, $to, $limitPoints])),
            'cache_status' => $bucket !== '' ? 'live' : 'not_bucketed',
            'limit_points' => $limitPoints,
        ];
    }

    private function analyticsFreshness(?string $domainId, ?string $bucket): array
    {
        $latestRaw = DB::table('usage_rollups')
            ->when($domainId !== null, fn ($query) => $query->where('domain_id', $domainId))
            ->max('ts');
        $latestAggregate = $bucket === null ? null : DB::table('usage_aggregates')
            ->where('bucket', $bucket)
            ->when($domainId !== null, fn ($query) => $query->where('domain_id', $domainId))
            ->max('updated_at');

        return [
            'latest_raw_ts' => $latestRaw === null ? null : (int) $latestRaw,
            'latest_aggregate_update' => $latestAggregate === null ? null : (int) $latestAggregate,
        ];
    }

    private function aggregationWatermark(?string $domainId, ?string $bucket): ?int
    {
        if ($bucket === null) {
            return null;
        }

        $watermark = DB::table('usage_aggregates')
            ->where('bucket', $bucket)
            ->when($domainId !== null, fn ($query) => $query->where('domain_id', $domainId))
            ->max('bucket_ts');

        return $watermark === null ? null : (int) $watermark;
    }

    private function cacheAnalyticsPayload(Request $request, ?string $domainId): array
    {
        [$where, $params] = $this->timeWhere($domainId, $request, 'ts');
        $whereSql = implode(' AND ', $where);
        $rows = DB::table('usage_rollups')
            ->selectRaw("UPPER(COALESCE(cache_status, 'UNKNOWN')) AS cache_status, COALESCE(SUM(requests_count),0) AS count, COALESCE(SUM(bytes_out),0) AS bytes_out")
            ->whereRaw($whereSql, $params)
            ->groupByRaw("UPPER(COALESCE(cache_status, 'UNKNOWN'))")
            ->orderBy('cache_status')
            ->get()
            ->map(fn (object $row): array => [
                'cache_status' => (string) $row->cache_status,
                'count' => (int) $row->count,
                'bytes_out' => (int) $row->bytes_out,
            ])
            ->all();

        $byStatus = [];
        $total = 0;
        $bytesOut = 0;
        foreach ($rows as $row) {
            $key = strtolower((string) $row['cache_status']);
            $byStatus[$key] = (int) $row['count'];
            $total += (int) $row['count'];
            $bytesOut += (int) $row['bytes_out'];
        }
        $hit = $byStatus['hit'] ?? 0;
        $miss = $byStatus['miss'] ?? 0;

        return [
            'rows' => $rows,
            'total_requests' => $total,
            'bytes_out' => $bytesOut,
            'hit' => $hit,
            'miss' => $miss,
            'expired' => $byStatus['expired'] ?? 0,
            'stale' => $byStatus['stale'] ?? 0,
            'bypass' => $byStatus['bypass'] ?? 0,
            'unknown' => $byStatus['unknown'] ?? 0,
            'hit_ratio' => ($hit + $miss) > 0 ? round($hit / ($hit + $miss), 4) : 0.0,
        ];
    }

    /** @return array{status:string,inserted:array<string,int>} */
    private function runRollupJob(string $jobId): array
    {
        $job = DB::table('analytics_rollup_jobs')->where('id', $jobId)->where('status', 'queued')->first();
        if (!$job) {
            return ['status' => 'queued', 'inserted' => []];
        }

        $now = UnixTime::now();
        DB::table('analytics_rollup_jobs')->where('id', $jobId)->update([
            'status' => 'running',
            'locked_by' => 'api-inline',
            'locked_at' => $now,
            'started_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            $inserted = $this->rebuildUsageAggregates(
                $job->domain_id === null ? null : (string) $job->domain_id,
                $job->bucket === null ? null : (string) $job->bucket,
                $job->range_start === null ? null : (int) $job->range_start,
                $job->range_end === null ? null : (int) $job->range_end
            );
            DB::table('analytics_rollup_jobs')->where('id', $jobId)->update([
                'status' => 'succeeded',
                'progress_json' => json_encode(['inserted' => $inserted]),
                'finished_at' => UnixTime::now(),
                'updated_at' => UnixTime::now(),
            ]);

            return ['status' => 'succeeded', 'inserted' => $inserted];
        } catch (\Throwable $error) {
            DB::table('analytics_rollup_jobs')->where('id', $jobId)->update([
                'status' => 'failed',
                'error' => $error->getMessage(),
                'updated_at' => UnixTime::now(),
            ]);
            throw $error;
        }
    }

    /** @return array<string,int> */
    private function rebuildUsageAggregates(?string $domainId, ?string $onlyBucket, ?int $rangeStart, ?int $rangeEnd): array
    {
        $now = UnixTime::now();
        $inserted = [];

        foreach (self::BUCKET_SECONDS as $bucket => $seconds) {
            if ($onlyBucket !== null && $bucket !== $onlyBucket) {
                continue;
            }

            $where = [];
            $params = [
                'bucket_hash' => $bucket,
                'bucket_value' => $bucket,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($domainId !== null) {
                $where[] = 'domain_id = :domain_id_filter';
                $params['domain_id_filter'] = $domainId;
            }
            if ($rangeStart !== null) {
                $where[] = 'ts >= :range_start';
                $params['range_start'] = $rangeStart;
            }
            if ($rangeEnd !== null) {
                $where[] = 'ts < :range_end';
                $params['range_end'] = $rangeEnd;
            }
            $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

            $sql = sprintf(
                "WITH source AS (
                    SELECT ((ts / %d) * %d) AS bucket_ts,
                           domain_id,
                           edge_node_id,
                           status,
                           COALESCE(cache_status, 'UNKNOWN') AS cache_status,
                           requests_count,
                           bytes_in,
                           bytes_out
                    FROM usage_rollups
                    %s
                )
                INSERT INTO usage_aggregates
                (id, bucket, bucket_ts, domain_id, edge_node_id, status, cache_status, requests_count, bytes_in, bytes_out, created_at, updated_at)
                SELECT md5((:bucket_hash || ':' || bucket_ts || ':' || domain_id || ':' || edge_node_id || ':' || status || ':' || COALESCE(cache_status, 'UNKNOWN'))::text),
                       :bucket_value,
                       bucket_ts,
                       domain_id,
                       edge_node_id,
                       status,
                       cache_status,
                       COALESCE(SUM(requests_count),0),
                       COALESCE(SUM(bytes_in),0),
                       COALESCE(SUM(bytes_out),0),
                       :created_at,
                       :updated_at
                FROM source
                GROUP BY bucket_ts, domain_id, edge_node_id, status, cache_status
                ON CONFLICT (bucket, bucket_ts, domain_id, edge_node_id, status, cache_status)
                DO UPDATE SET requests_count = EXCLUDED.requests_count,
                              bytes_in = EXCLUDED.bytes_in,
                              bytes_out = EXCLUDED.bytes_out,
                              updated_at = EXCLUDED.updated_at",
                $seconds,
                $seconds,
                $whereSql
            );

            DB::statement($sql, $params);
            $inserted[$bucket] = DB::table('usage_aggregates')
                ->where('bucket', $bucket)
                ->when($domainId !== null, fn ($query) => $query->where('domain_id', $domainId))
                ->when($rangeStart !== null, fn ($query) => $query->where('bucket_ts', '>=', $rangeStart))
                ->when($rangeEnd !== null, fn ($query) => $query->where('bucket_ts', '<', $rangeEnd))
                ->count();
        }

        return $inserted;
    }

    /** @return array{from:int|null,to:int|null} */
    private function rollupRange(Request $request, ?string $bucket): array
    {
        $from = $request->input('from');
        $to = $request->input('to');
        if (is_numeric($from) || is_numeric($to)) {
            return [
                'from' => is_numeric($from) ? (int) $from : null,
                'to' => is_numeric($to) ? (int) $to : null,
            ];
        }

        if ($bucket === null) {
            return ['from' => null, 'to' => null];
        }

        $now = UnixTime::now();
        $window = match ($bucket) {
            'minute' => 3600,
            'hour' => 86400,
            'day' => 2592000,
            default => 86400,
        };

        return ['from' => $now - $window, 'to' => $now + 1];
    }

    private function castRollupJob(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'status' => (string) $row->status,
            'domain_id' => $row->domain_id,
            'bucket' => $row->bucket,
            'range' => ['from' => $row->range_start === null ? null : (int) $row->range_start, 'to' => $row->range_end === null ? null : (int) $row->range_end],
            'progress' => $this->decodeJson($row->progress_json) ?: [],
            'error' => $row->error,
            'created_at' => (int) $row->created_at,
            'updated_at' => (int) $row->updated_at,
            'started_at' => $row->started_at === null ? null : (int) $row->started_at,
            'finished_at' => $row->finished_at === null ? null : (int) $row->finished_at,
        ];
    }

    private function activityTimelinePayload(Request $request, string $domainId): array
    {
        $limit = min(max($request->integer('limit', 100), 1), 250);
        $offset = max($request->integer('offset', 0), 0);
        $type = trim((string) $request->query('type', ''));
        $items = [];
        $total = 0;

        if ($type === '' || $type === 'request' || $type === 'error') {
            [$where, $params] = $this->requestWhere($domainId, $request, $type === 'error');
            $total += DB::table('usage_rollups')->whereRaw(implode(' AND ', $where), $params)->count();
            foreach (DB::table('usage_rollups')->select($this->requestColumns())->whereRaw(implode(' AND ', $where), $params)->orderByDesc('ts')->orderByDesc('id')->limit($limit + $offset)->get() as $row) {
                $requestRow = $this->castRequestRow($row);
                $isError = ((int) $requestRow['status'] >= 500) || $requestRow['router_error'] !== null;
                $items[] = [
                    'id' => 'request:' . $requestRow['id'],
                    'type' => $isError ? 'error' : 'request',
                    'ts' => (int) $requestRow['ts'],
                    'title' => $isError ? 'Edge request needs attention' : 'Edge request served',
                    'summary' => trim(($requestRow['method'] ?? 'GET') . ' ' . ($requestRow['path'] ?? '/') . ' returned ' . $requestRow['status']),
                    'request_id' => $requestRow['request_id'],
                    'friendly' => $this->friendly('traffic', $isError ? 'error' : 'request', $isError ? 'Request error' : 'Request served', $isError ? 'Review origin and routing details for this request.' : 'The edge recorded a request for this domain.', $isError ? 'warning' : 'info'),
                    'details' => $requestRow,
                ];
            }
        }

        if ($type === '' || $type === 'audit' || $type === 'security') {
            [$where, $params] = $this->auditWhere($domainId, $request, $type);
            $total += DB::table('audit_log')->whereRaw(implode(' AND ', $where), $params)->count();
            foreach (DB::table('audit_log')->whereRaw(implode(' AND ', $where), $params)->orderByDesc('created_at')->orderByDesc('id')->limit($limit + $offset)->get() as $row) {
                $details = $this->decodeJson($row->details_json);
                $security = isset(self::SECURITY_EVENTS[(string) $row->event]);
                $items[] = [
                    'id' => 'audit:' . $row->id,
                    'type' => $security ? 'security' : 'audit',
                    'ts' => (int) $row->created_at,
                    'title' => $security ? 'Security decision recorded' : 'Configuration activity recorded',
                    'summary' => trim((string) ($row->event ?: $row->action) . ' on ' . (string) $row->resource_type),
                    'request_id' => is_array($details) && isset($details['request_id']) ? (string) $details['request_id'] : null,
                    'friendly' => $this->friendly($security ? 'security' : 'audit', (string) ($row->event ?: $row->action), $security ? 'Security decision' : 'Configuration change', $security ? 'A protection rule made a decision for this domain.' : 'An operator or system change was recorded.', $security ? 'warning' : 'info'),
                    'details' => [
                        'id' => (string) $row->id,
                        'actor_type' => (string) $row->actor_type,
                        'actor_id' => $row->actor_id,
                        'action' => (string) $row->action,
                        'resource_type' => (string) $row->resource_type,
                        'resource_id' => $row->resource_id,
                        'event' => $row->event,
                        'details' => $details,
                    ],
                ];
            }
        }

        usort($items, static fn (array $a, array $b): int => ($b['ts'] <=> $a['ts']) ?: strcmp((string) $b['id'], (string) $a['id']));
        $items = array_slice($items, $offset, $limit);

        return [
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'cursor' => count($items) === $limit ? (string) min(array_map(static fn (array $item): int => (int) $item['ts'], $items)) : null,
        ];
    }

    private function securityEventsPayload(Request $request, mixed $domainId): array
    {
        $limit = min(max($request->integer('limit', 50), 1), 250);
        $offset = max($request->integer('offset', 0), 0);
        [$where, $params] = $this->securityWhere($request, $domainId);
        $whereSql = implode(' AND ', $where);
        $total = DB::table('audit_log')->whereRaw($whereSql, $params)->count();
        [$joinedWhere, $joinedParams] = $this->securityWhere($request, $domainId, 'a.');
        $items = DB::table('audit_log AS a')
            ->leftJoin('domains AS d', 'd.id', '=', 'a.domain_id')
            ->selectRaw('a.*, d.name AS domain_name')
            ->whereRaw(implode(' AND ', $joinedWhere), $joinedParams)
            ->orderByDesc('a.created_at')
            ->orderByDesc('a.id')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(fn (object $row): array => $this->castSecurityEvent($row))
            ->all();

        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    private function requestColumns(): array
    {
        return [
            'id', 'ts', 'request_id', 'domain_id', 'edge_node_id', 'host', 'method', 'path',
            'query_redacted', 'client_ip', 'client_country', 'status', 'bytes_in', 'bytes_out',
            'cache_status', 'origin_id', 'origin_host', 'upstream_status', 'upstream_response_time_ms',
            'upstream_addr', 'request_time_ms', 'router_error', 'security_event_type', 'rule_id',
        ];
    }

    /** @return array{0:array<int,string>,1:array<string,mixed>} */
    private function timeWhere(?string $domainId, Request $request, string $column): array
    {
        $where = ['1=1'];
        $params = [];
        if ($domainId !== null && $domainId !== '') {
            $where[] = "domain_id = :domain_id";
            $params['domain_id'] = $domainId;
        }
        if ($request->query('from') !== null && is_numeric($request->query('from'))) {
            $where[] = "{$column} >= :from_ts";
            $params['from_ts'] = (int) $request->query('from');
        }
        if ($request->query('to') !== null && is_numeric($request->query('to'))) {
            $where[] = "{$column} <= :to_ts";
            $params['to_ts'] = (int) $request->query('to');
        }

        return [$where, $params];
    }

    /** @return array{0:array<int,string>,1:array<string,mixed>} */
    private function requestWhere(string $domainId, Request $request, bool $errorsOnly = false): array
    {
        [$where, $params] = $this->timeWhere($domainId, $request, 'ts');
        $type = trim((string) $request->query('type', ''));
        if ($errorsOnly || $type === 'error') {
            $where[] = "(status >= 500 OR router_error IS NOT NULL OR upstream_status LIKE '5%')";
        }
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $where[] = '(request_id ILIKE :search OR host ILIKE :search OR path ILIKE :search OR client_ip ILIKE :search OR client_country ILIKE :search OR origin_id ILIKE :search OR router_error ILIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        return [$where, $params];
    }

    /** @return array{0:array<int,string>,1:array<string,mixed>} */
    private function auditWhere(string $domainId, Request $request, string $type): array
    {
        [$where, $params] = $this->timeWhere($domainId, $request, 'created_at');
        if ($type === 'security') {
            $where[] = "event IN ('" . implode("','", array_keys(self::SECURITY_EVENTS)) . "')";
        } elseif ($type === 'audit') {
            $where[] = "(event IS NULL OR event NOT IN ('" . implode("','", array_keys(self::SECURITY_EVENTS)) . "'))";
        }
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $where[] = '(details_json ILIKE :audit_search OR event ILIKE :audit_search OR action ILIKE :audit_search OR resource_type ILIKE :audit_search OR resource_id ILIKE :audit_search)';
            $params['audit_search'] = '%' . $search . '%';
        }

        return [$where, $params];
    }

    /** @return array{0:array<int,string>,1:array<string,mixed>} */
    private function securityWhere(Request $request, mixed $domainId, string $prefix = ''): array
    {
        $domain = is_string($domainId) && trim($domainId) !== '' ? trim($domainId) : null;
        [$where, $params] = $this->timeWhere($domain, $request, "{$prefix}created_at");
        if ($prefix !== '') {
            $where = array_map(static fn (string $clause): string => str_replace('domain_id = :domain_id', "{$prefix}domain_id = :domain_id", $clause), $where);
        }
        $where[] = "{$prefix}event IN ('" . implode("','", array_keys(self::SECURITY_EVENTS)) . "')";
        $type = trim((string) $request->query('type', ''));
        if ($type !== '') {
            $where[] = "{$prefix}event = :event_type";
            $params['event_type'] = $type;
        }
        $edge = trim((string) $request->query('edge_id', ''));
        if ($edge !== '') {
            $where[] = "{$prefix}actor_id = :edge_id";
            $params['edge_id'] = $edge;
        }
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $where[] = "({$prefix}details_json ILIKE :security_search OR {$prefix}event ILIKE :security_search OR {$prefix}action ILIKE :security_search)";
            $params['security_search'] = '%' . $search . '%';
        }

        return [$where, $params];
    }

    private function castRequestRow(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'ts' => (int) $row->ts,
            'request_id' => $row->request_id,
            'domain_id' => (string) $row->domain_id,
            'edge_node_id' => (string) $row->edge_node_id,
            'host' => $row->host,
            'method' => $row->method,
            'path' => $row->path,
            'query_redacted' => $this->decodeJson($row->query_redacted),
            'client_ip' => $row->client_ip,
            'client_country' => $row->client_country,
            'status' => (int) $row->status,
            'bytes_in' => (int) $row->bytes_in,
            'bytes_out' => (int) $row->bytes_out,
            'cache_status' => $row->cache_status,
            'origin_id' => $row->origin_id,
            'origin_host' => $row->origin_host,
            'upstream_status' => $row->upstream_status,
            'upstream_response_time_ms' => $row->upstream_response_time_ms === null ? null : (int) $row->upstream_response_time_ms,
            'upstream_addr' => $row->upstream_addr,
            'request_time_ms' => $row->request_time_ms === null ? null : (int) $row->request_time_ms,
            'router_error' => $row->router_error,
            'security_event_type' => $row->security_event_type,
            'rule_id' => $row->rule_id,
        ];
    }

    private function castSecurityEvent(object $row): array
    {
        $details = $this->decodeJson($row->details_json);

        return [
            'id' => (string) $row->id,
            'domain_id' => $row->domain_id,
            'domain_name' => $row->domain_name ?? null,
            'actor_id' => $row->actor_id,
            'edge_id' => $row->actor_id,
            'type' => $row->event,
            'decision' => is_array($details) ? ($details['decision'] ?? null) : null,
            'action' => $row->action,
            'severity' => is_array($details) ? ($details['severity'] ?? 'warning') : 'warning',
            'timestamp' => (int) $row->created_at,
            'created_at' => (int) $row->created_at,
            'payload' => $details,
            'details' => is_array($details) ? $details : null,
        ];
    }

    private function topUsageDimension(string $column, string $whereSql, array $params): array
    {
        return DB::table('usage_rollups')
            ->selectRaw("COALESCE({$column}, 'unknown') AS value, COALESCE(SUM(requests_count),0) AS count")
            ->whereRaw($whereSql, $params)
            ->groupByRaw("COALESCE({$column}, 'unknown')")
            ->orderByDesc('count')
            ->orderBy('value')
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => ['value' => (string) $row->value, 'count' => (int) $row->count])
            ->all();
    }

    private function recentOriginErrors(string $whereSql, array $params): array
    {
        return DB::table('usage_rollups')
            ->select($this->requestColumns())
            ->whereRaw($whereSql, $params)
            ->whereRaw("(status >= 500 OR router_error IS NOT NULL OR upstream_status LIKE '5%')")
            ->orderByDesc('ts')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => $this->castRequestRow($row))
            ->all();
    }

    private function securityCount(string $domainId, Request $request): int
    {
        [$where, $params] = $this->securityWhere($request, $domainId);
        return DB::table('audit_log')->whereRaw(implode(' AND ', $where), $params)->count();
    }

    private function topSecurityJsonValue(string $whereSql, array $params, string $key): array
    {
        return DB::table('audit_log')
            ->selectRaw("COALESCE(NULLIF(details_json::jsonb->>'{$key}', ''), 'unknown') AS value, COUNT(*) AS count")
            ->whereRaw($whereSql, $params)
            ->groupByRaw("COALESCE(NULLIF(details_json::jsonb->>'{$key}', ''), 'unknown')")
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => ['value' => (string) $row->value, 'count' => (int) $row->count])
            ->all();
    }

    private function decodeJson(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function friendly(string $category, string $intent, string $title, string $summary, string $severity): array
    {
        return [
            'category' => $category,
            'intent' => $intent,
            'label' => $title,
            'title' => $title,
            'summary' => $summary,
            'severity' => $severity,
            'recommendation' => null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function durationMs(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $number = (float) $value;
        return $number > 0 && $number < 1000 ? (int) round($number * 1000) : (int) round($number);
    }

    /** @param array<string,mixed> $event */
    private function payloadExcerpt(array $event): array
    {
        return array_slice($event, 0, 12, true);
    }
}
