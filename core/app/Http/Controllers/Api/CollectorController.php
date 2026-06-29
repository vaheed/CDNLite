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
    public function usage(Request $request): JsonResponse
    {
        $events = (array) $request->input('events', []);

        $this->recordBatch($request, 'usage', $events);

        return response()->json(['ok' => true, 'accepted' => count($events)]);
    }

    public function securityEvents(Request $request): JsonResponse
    {
        $items = (array) $request->input('items', []);

        $this->recordBatch($request, 'security', $items);

        return response()->json(['ok' => true, 'accepted' => count($items)]);
    }

    private function recordBatch(Request $request, string $stream, array $events): void
    {
        $idempotencyKey = (string) ($request->input('idempotency_key') ?: $request->header('X-Idempotency-Key') ?: Str::uuid());
        $batchId = (string) Str::uuid();

        $inserted = DB::table('telemetry_ingest_batches')->insertOrIgnore([
            'batch_id' => $batchId,
            'source_edge_id' => (string) $request->attributes->get('edge_id', 'unknown'),
            'idempotency_key' => "{$stream}:{$idempotencyKey}",
            'event_count' => count($events),
            'accepted_count' => count($events),
            'rejected_count' => 0,
            'first_event_ts' => null,
            'last_event_ts' => null,
            'payload_bytes' => strlen($request->getContent()),
            'status' => 'accepted',
            'rejection_reason' => null,
            'ingested_at' => UnixTime::now(),
        ]);

        if ($inserted !== 1) {
            DB::table('telemetry_ingest_batches')->insert([
                'batch_id' => (string) Str::uuid(),
                'source_edge_id' => (string) $request->attributes->get('edge_id', 'unknown'),
                'idempotency_key' => "{$stream}:duplicate:{$batchId}",
                'event_count' => count($events),
                'accepted_count' => 0,
                'rejected_count' => 0,
                'first_event_ts' => null,
                'last_event_ts' => null,
                'payload_bytes' => strlen($request->getContent()),
                'status' => 'duplicate',
                'rejection_reason' => null,
                'ingested_at' => UnixTime::now(),
            ]);
        }
    }
}
