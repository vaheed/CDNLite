<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DnsOperationsController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('dns_sync_state')->orderBy('zone_name')->get(),
        ]);
    }

    public function zones(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('domains')->select('id', 'domain', 'powerdns_zone_created')->orderBy('domain')->get(),
        ]);
    }

    public function desired(Request $request): JsonResponse
    {
        $query = DB::table('desired_dns_rrsets')->orderBy('zone_name')->orderBy('rrset_name');
        if ($request->filled('zone')) {
            $query->where('zone_name', (string) $request->query('zone'));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function dryRun(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'mode' => 'dry_run',
            'planned_changes' => 0,
            'message' => 'Fresh Laravel reconciler is ready; no PowerDNS writes were attempted.',
        ]);
    }
}
