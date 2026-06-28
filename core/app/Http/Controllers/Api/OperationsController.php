<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OperationsController extends Controller
{
    public function audit(Request $request): JsonResponse
    {
        $limit = min(max($request->integer('limit', 50), 1), 200);

        return response()->json([
            'data' => DB::table('audit_log')->orderByDesc('created_at')->limit($limit)->get(),
        ]);
    }

    public function overview(): JsonResponse
    {
        return response()->json([
            'domains' => DB::table('domains')->count(),
            'active_domains' => DB::table('domains')->where('status', 'active')->count(),
            'edge_nodes' => DB::table('edge_nodes')->count(),
            'online_edges' => DB::table('edge_nodes')->where('status', 'online')->count(),
            'dns_records' => DB::table('dns_records')->count(),
            'open_jobs' => DB::table('ssl_jobs')->whereNotIn('status', ['complete', 'failed', 'cancelled'])->count(),
        ]);
    }

    public function settings(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('platform_settings')->orderBy('group_name')->orderBy('key')->get(),
        ]);
    }
}
