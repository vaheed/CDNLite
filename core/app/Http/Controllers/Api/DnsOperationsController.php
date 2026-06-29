<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ControlPlane\DnsDesiredStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DnsOperationsController extends Controller
{
    public function __construct(private DnsDesiredStateService $desiredState)
    {
    }

    public function status(): JsonResponse
    {
        $zones = $this->zoneStatuses();

        return response()->json([
            'data' => [
                'setup' => $this->setupStatus(),
                'dnsgeo' => [
                    'powerdns_auth' => $this->settingBool('platform.powerdns.enabled', false),
                    'postgresql' => true,
                    'mmdb' => true,
                    'edns_subnet_processing' => true,
                    'lua_records' => true,
                    'alias_expansion' => false,
                    'resolver_configured' => true,
                    'resolver' => 'pdns-recursor:5300',
                    'api_publicly_exposed' => false,
                ],
                'sync' => [
                    'zones' => $zones,
                    'pending_zones' => collect($zones)->where('converged', false)->count(),
                ],
            ],
        ]);
    }

    public function zones(): JsonResponse
    {
        return response()->json([
            'data' => $this->zoneStatuses(),
        ]);
    }

    public function desired(Request $request): JsonResponse
    {
        $query = DB::table('desired_dns_rrsets')->orderBy('zone_name')->orderBy('rrset_name');
        if ($request->filled('zone')) {
            $query->where('zone_name', (string) $request->query('zone'));
        }

        return response()->json([
            'data' => $query->get()->map(fn (object $row): array => [
                'zone_name' => (string) $row->zone_name,
                'rrset_name' => (string) $row->rrset_name,
                'rrset_type' => (string) $row->rrset_type,
                'ttl' => (int) $row->ttl,
                'records' => json_decode((string) $row->records_json, true) ?: [],
                'source' => (string) $row->source,
                'desired_hash' => (string) $row->desired_hash,
            ])->all(),
        ]);
    }

    public function dryRun(): JsonResponse
    {
        return response()->json($this->desiredState->dryRun());
    }

    public function forceSync(): JsonResponse
    {
        return response()->json([
            'data' => $this->desiredState->persistDesiredState(),
        ]);
    }

    private function zoneStatuses(): array
    {
        $counts = [];
        foreach ($this->desiredState->zoneSummaries($this->desiredState->build()) as $zone) {
            $counts[$zone['zone_name']] = (int) $zone['rrset_count'];
        }

        $states = DB::table('dns_sync_state')->orderBy('zone_name')->get()->map(function (object $row) use ($counts): array {
            $pending = (int) $row->pending_changes;

            return [
                'zone_name' => (string) $row->zone_name,
                'status' => (string) $row->status,
                'pending_changes' => $pending,
                'desired_rrsets' => $counts[(string) $row->zone_name] ?? $pending,
                'last_attempt_at' => $row->last_attempt_at === null ? null : (int) $row->last_attempt_at,
                'last_success_at' => $row->last_success_at === null ? null : (int) $row->last_success_at,
                'last_error' => $row->last_error,
                'desired_hash' => $row->desired_hash,
                'applied_hash' => $row->applied_hash,
                'converged' => $row->status === 'ok' && $row->desired_hash === $row->applied_hash && $pending === 0,
            ];
        })->all();

        $known = collect($states)->pluck('zone_name')->all();
        foreach ($counts as $zone => $count) {
            if (!in_array($zone, $known, true)) {
                $states[] = [
                    'zone_name' => $zone,
                    'status' => 'unknown',
                    'pending_changes' => $count,
                    'desired_rrsets' => $count,
                    'last_attempt_at' => null,
                    'last_success_at' => null,
                    'last_error' => null,
                    'desired_hash' => null,
                    'applied_hash' => null,
                    'converged' => false,
                ];
            }
        }

        usort($states, static fn (array $a, array $b): int => $a['zone_name'] <=> $b['zone_name']);

        return $states;
    }

    private function setupStatus(): array
    {
        $enabled = $this->settingBool('platform.powerdns.enabled', false);
        $apiUrl = $this->settingString('platform.powerdns.api_url', '');
        $apiKey = $this->settingString('platform.powerdns.api_key', '');
        $serverId = $this->settingString('platform.powerdns.server_id', 'localhost');

        return [
            'enabled' => $enabled,
            'configured' => $enabled && $apiUrl !== '' && $apiKey !== '',
            'api_url' => $apiUrl,
            'server_id' => $serverId,
            'api_key_configured' => $apiKey !== '',
            'cdn_zone' => (string) env('CDNLITE_CDN_ZONE', 'cdn.example.net'),
            'cdn_proxy_host' => (string) env('CDNLITE_CDN_PROXY_HOST', 'proxy.cdn.example.net'),
            'static_anycast' => $this->staticAnycastIps(),
            'apex_proxy_mode' => 'LUA',
            'bundled_dnsgeo' => true,
            'poweradmin_url' => (string) env('CDNLITE_POWERADMIN_URL', 'http://localhost:8084'),
            'api' => ['ok' => $enabled, 'disabled' => !$enabled],
        ];
    }

    private function settingBool(string $key, bool $default): bool
    {
        return filter_var($this->setting($key, $default), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function settingString(string $key, string $default): string
    {
        $value = $this->setting($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    private function setting(string $key, mixed $default): mixed
    {
        $raw = DB::table('platform_settings')->where('key', $key)->value('value_json');
        if (!is_string($raw)) {
            return $default;
        }

        return json_decode($raw, true) ?? $default;
    }

    private function staticAnycastIps(): array
    {
        return [
            'ipv4' => $this->ipListSetting('platform.edge_dns.anycast_ipv4', 'anycast_ipv4', FILTER_FLAG_IPV4),
            'ipv6' => $this->ipListSetting('platform.edge_dns.anycast_ipv6', 'anycast_ipv6', FILTER_FLAG_IPV6),
        ];
    }

    private function ipListSetting(string $key, string $groupKey, int $flag): array
    {
        $value = $this->setting($key, null);
        if ($value === null) {
            $group = $this->setting('platform.edge_dns', []);
            $value = is_array($group) ? ($group[$groupKey] ?? []) : [];
        }

        $items = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value);
        $ips = [];
        foreach ((array) $items as $item) {
            $ip = trim((string) $item);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, $flag) !== false) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }
}
