<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ControlPlane\UnixTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EdgeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('edge_nodes')->orderBy('edge_id')->get()]);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'edge_id' => ['required', 'string', 'max:120'],
            'hostname' => ['nullable', 'string', 'max:253'],
            'public_ip' => ['required_without:public_ipv4', 'nullable', 'ip'],
            'public_ipv4' => ['nullable', 'ipv4'],
            'public_ipv6' => ['nullable', 'ipv6'],
            'region' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'continent' => ['nullable', 'string', 'size:2'],
            'version' => ['nullable', 'string', 'max:80'],
        ]);

        $edge = $this->upsertEdge($validated + ['health_status' => 'healthy']);

        return response()->json(['data' => $edge], 201);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'edge_id' => ['required', 'string', 'max:120'],
            'hostname' => ['nullable', 'string', 'max:253'],
            'public_ip' => ['nullable', 'ip'],
            'public_ipv4' => ['nullable', 'ipv4'],
            'public_ipv6' => ['nullable', 'ipv6'],
            'region' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'continent' => ['nullable', 'string', 'size:2'],
            'version' => ['nullable', 'string', 'max:80'],
            'health_status' => ['nullable', 'in:healthy,unhealthy,unknown'],
            'config_version' => ['nullable', 'integer'],
            'config_apply_error' => ['nullable', 'string', 'max:2000'],
        ]);

        $edge = $this->upsertEdge($validated);

        return response()->json(['ok' => true, 'data' => $edge]);
    }

    public function config(Request $request): JsonResponse
    {
        $version = (int) DB::table('config_state')->where('id', 1)->value('version');
        $requested = $request->integer('if_version');

        if ($requested > 0 && $requested === $version) {
            return response()->json(['changed' => false, 'version' => $version]);
        }

        $domains = DB::table('domains')->where('status', 'active')->orderBy('domain')->get();
        $origins = DB::table('domain_origins')->where('enabled', true)->get()->groupBy('domain_id');
        $records = DB::table('dns_records')->where('status', 'active')->get()->groupBy('domain_id');

        return response()->json([
            'changed' => true,
            'version' => $version,
            'generated_at' => UnixTime::now(),
            'domains' => $domains->map(fn ($domain) => [
                'id' => $domain->id,
                'domain' => $domain->domain,
                'origins' => ($origins[$domain->id] ?? collect())->values(),
                'dns_records' => ($records[$domain->id] ?? collect())->values(),
            ])->values(),
        ]);
    }

    public function dns(): JsonResponse
    {
        $edges = DB::table('edge_nodes')
            ->where('is_enabled', true)
            ->where('dns_enabled', true)
            ->orderBy('edge_id')
            ->get();

        return response()->json(['data' => $edges]);
    }

    private function upsertEdge(array $input): array
    {
        $now = UnixTime::now();
        $edgeId = (string) $input['edge_id'];
        $publicIp = (string) ($input['public_ip'] ?? $input['public_ipv4'] ?? $input['public_ipv6'] ?? '');
        $publicIpv4 = (string) ($input['public_ipv4'] ?? (filter_var($publicIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $publicIp : ''));
        $publicIpv6 = (string) ($input['public_ipv6'] ?? (filter_var($publicIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? $publicIp : ''));

        DB::table('edge_nodes')->upsert([[
            'id' => (string) Str::uuid(),
            'edge_id' => $edgeId,
            'hostname' => (string) ($input['hostname'] ?? $edgeId),
            'public_ip' => $publicIp,
            'public_ipv4' => $publicIpv4,
            'public_ipv6' => $publicIpv6,
            'region' => strtolower((string) ($input['region'] ?? 'unknown')),
            'country' => strtoupper((string) ($input['country'] ?? '')),
            'continent' => strtoupper((string) ($input['continent'] ?? '')),
            'latitude' => null,
            'longitude' => null,
            'version' => (string) ($input['version'] ?? 'dev'),
            'status' => 'online',
            'is_enabled' => true,
            'last_heartbeat' => $now,
            'last_heartbeat_at' => $now,
            'health_status' => (string) ($input['health_status'] ?? 'healthy'),
            'applied_config_version' => $input['config_version'] ?? null,
            'last_config_pull_at' => isset($input['config_version']) ? $now : null,
            'config_apply_error' => $input['config_apply_error'] ?? null,
            'weight' => 100,
            'priority' => 100,
            'geo_enabled' => true,
            'anycast_enabled' => false,
            'proxy_enabled' => true,
            'dns_enabled' => true,
            'cache_enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['edge_id'], [
            'hostname', 'public_ip', 'public_ipv4', 'public_ipv6', 'region', 'country', 'continent',
            'version', 'status', 'last_heartbeat', 'last_heartbeat_at', 'health_status',
            'applied_config_version', 'last_config_pull_at', 'config_apply_error', 'updated_at',
        ]);

        return (array) DB::table('edge_nodes')->where('edge_id', $edgeId)->first();
    }
}
