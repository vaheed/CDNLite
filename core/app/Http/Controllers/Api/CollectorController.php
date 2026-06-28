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

        DB::table('telemetry_ingest_batches')->insert([
            'batch_id' => (string) Str::uuid(),
            'source_edge_id' => (string) $request->attributes->get('edge_id', 'unknown'),
            'idempotency_key' => (string) ($request->header('X-Idempotency-Key') ?: Str::uuid()),
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

        return response()->json(['ok' => true, 'accepted' => count($events)]);
    }
}
