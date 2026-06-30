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

    public function events(Request $request): JsonResponse
    {
        $limit = min(max($request->integer('limit', 50), 1), 500);
        $offset = max($request->integer('offset', 0), 0);
        $query = DB::table('audit_log as a')
            ->leftJoin('domains as d', 'd.id', '=', 'a.domain_id');

        if ($request->filled('domain_id')) {
            $query->where('a.domain_id', (string) $request->query('domain_id'));
        }
        if ($request->filled('type')) {
            $query->where('a.action', (string) $request->query('type'));
        }
        if ($request->filled('from')) {
            $query->where('a.created_at', '>=', $request->integer('from'));
        }
        if ($request->filled('to')) {
            $query->where('a.created_at', '<=', $request->integer('to'));
        }
        if ($request->filled('search')) {
            $search = '%'.strtolower((string) $request->query('search')).'%';
            $query->where(function ($inner) use ($search): void {
                $inner->whereRaw('LOWER(a.action) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(a.resource_type) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(COALESCE(d.domain, d.name, \'\')) LIKE ?', [$search]);
            });
        }

        $total = (clone $query)->count();
        $items = $query
            ->orderByDesc('a.created_at')
            ->offset($offset)
            ->limit($limit)
            ->get([
                'a.id',
                'a.action',
                'a.resource_type',
                'a.resource_id',
                'a.domain_id',
                'a.details_json',
                'a.event',
                'a.created_at',
                'd.name as domain_name',
                'd.domain',
            ])
            ->map(function (object $row): array {
                $details = $this->decodeJson($row->details_json);
                $type = (string) ($row->event ?: $row->action);

                return [
                    'id' => (string) $row->id,
                    'source' => 'audit',
                    'type' => $type,
                    'severity' => str_contains($type, 'failed') || str_contains($type, 'error') ? 'critical' : 'info',
                    'status' => (string) $row->action,
                    'summary' => trim((string) $row->action.' '.(string) $row->resource_type),
                    'domain_id' => $row->domain_id,
                    'domain_name' => $row->domain_name ?? $row->domain,
                    'created_at' => (int) $row->created_at,
                    'details' => $details,
                ];
            })
            ->all();

        return response()->json(['data' => ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset]]);
    }

    public function jobs(Request $request): JsonResponse
    {
        $limit = min(max($request->integer('limit', 50), 1), 500);
        $offset = max($request->integer('offset', 0), 0);
        $query = DB::table('ssl_jobs as j')
            ->leftJoin('domains as d', 'd.id', '=', 'j.domain_id');

        if ($request->filled('domain_id')) {
            $query->where('j.domain_id', (string) $request->query('domain_id'));
        }
        if ($request->filled('status')) {
            $query->where('j.status', (string) $request->query('status'));
        }
        if ($request->boolean('active')) {
            $query->whereNotIn('j.status', ['issued', 'failed', 'cancelled']);
        }
        if ($request->filled('from')) {
            $query->where('j.updated_at', '>=', $request->integer('from'));
        }
        if ($request->filled('to')) {
            $query->where('j.updated_at', '<=', $request->integer('to'));
        }
        if ($request->filled('search')) {
            $search = '%'.strtolower((string) $request->query('search')).'%';
            $query->where(function ($inner) use ($search): void {
                $inner->whereRaw('LOWER(j.id) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(j.status) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(j.message) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(COALESCE(d.domain, d.name, \'\')) LIKE ?', [$search]);
            });
        }

        $total = (clone $query)->count();
        $items = $query
            ->orderByDesc('j.updated_at')
            ->offset($offset)
            ->limit($limit)
            ->get([
                'j.id',
                'j.domain_id',
                'j.status',
                'j.progress_percent',
                'j.message',
                'j.error_code',
                'j.error_detail',
                'j.hostnames_json',
                'j.created_at',
                'j.updated_at',
                'j.finished_at',
                'd.name as domain_name',
                'd.domain',
            ])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'domain_id' => (string) $row->domain_id,
                'domain_name' => $row->domain_name ?? $row->domain,
                'status' => (string) $row->status,
                'progress_percent' => (int) $row->progress_percent,
                'message' => (string) $row->message,
                'error_code' => $row->error_code,
                'error_detail' => $row->error_detail,
                'hostnames' => $this->decodeJson($row->hostnames_json),
                'created_at' => (int) $row->created_at,
                'updated_at' => (int) $row->updated_at,
                'finished_at' => $row->finished_at === null ? null : (int) $row->finished_at,
            ])
            ->all();

        return response()->json(['data' => ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset]]);
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

    private function decodeJson(?string $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
