<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDnsRecordRequest;
use App\Http\Requests\StoreDomainRequest;
use App\Http\Requests\StoreOriginRequest;
use App\Http\Resources\DomainResource;
use App\Services\ControlPlane\AuditWriter;
use App\Services\ControlPlane\UnixTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DomainController extends Controller
{
    public function index(): JsonResponse
    {
        $domains = DB::table('domains')->orderBy('created_at')->get()->map(fn ($row) => $this->withNameservers((array) $row));

        return response()->json(['data' => DomainResource::collection($domains)]);
    }

    public function store(StoreDomainRequest $request, AuditWriter $audit): JsonResponse
    {
        $payload = $request->validated();
        $id = (string) Str::uuid();
        $now = UnixTime::now();
        $domainName = strtolower(trim($payload['domain']));

        $row = [
            'id' => $id,
            'user_id' => (string) ($payload['user_id'] ?? Str::uuid()),
            'name' => (string) ($payload['name'] ?? $domainName),
            'domain' => $domainName,
            'origin_shield_header_name' => null,
            'origin_shield_header_value_hash' => null,
            'status' => 'pending_nameserver',
            'nameserver_status' => 'unknown',
            'verification_token' => bin2hex(random_bytes(16)),
            'last_ns_check_at' => null,
            'powerdns_zone_created' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::transaction(function () use ($row, $id, $now): void {
            DB::table('domains')->insert($row);
            foreach ($this->defaultNameservers() as $hostname) {
                DB::table('domain_nameservers')->insert([
                    'id' => (string) Str::uuid(),
                    'domain_id' => $id,
                    'hostname' => $hostname,
                    'expected' => true,
                    'observed' => false,
                    'last_checked_at' => null,
                ]);
            }

            DB::table('domain_routing_settings')->insert([
                'domain_id' => $id,
                'routing_mode' => 'geo',
                'geo_health_port' => 443,
                'geo_selector' => 'pickclosest',
                'anycast_ipv4' => null,
                'anycast_ipv6' => null,
                'anycast_cname' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $created = $this->withNameservers($row);
        $adminUser = request()->attributes->get('admin_user');
        $audit->write('domain.create', 'domain', $id, null, $created, 'admin', is_array($adminUser) ? ($adminUser['id'] ?? null) : null, $id);

        return response()->json(['data' => new DomainResource($created)], 201);
    }

    public function show(string $domainId): JsonResponse
    {
        $domain = $this->findDomain($domainId);

        return $domain === null
            ? response()->json(['error' => 'domain_not_found'], 404)
            : response()->json(['data' => new DomainResource($domain)]);
    }

    public function update(Request $request, string $domainId, AuditWriter $audit): JsonResponse
    {
        $existing = $this->findDomain($domainId);
        if ($existing === null) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'status' => ['sometimes', 'in:pending_nameserver,active,disabled'],
        ]);

        DB::table('domains')->where('id', $domainId)->update($validated + ['updated_at' => UnixTime::now()]);
        $updated = $this->findDomain($domainId);
        $adminUser = $request->attributes->get('admin_user');
        $audit->write('domain.update', 'domain', $domainId, $existing, $updated, 'admin', is_array($adminUser) ? ($adminUser['id'] ?? null) : null, $domainId);

        return response()->json(['data' => new DomainResource($updated)]);
    }

    public function destroy(string $domainId, AuditWriter $audit): JsonResponse
    {
        $existing = $this->findDomain($domainId);
        if ($existing === null) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }

        DB::table('domains')->where('id', $domainId)->delete();
        $adminUser = request()->attributes->get('admin_user');
        $audit->write('domain.delete', 'domain', $domainId, $existing, null, 'admin', is_array($adminUser) ? ($adminUser['id'] ?? null) : null, $domainId);

        return response()->json(['ok' => true]);
    }

    public function dnsRecords(string $domainId): JsonResponse
    {
        return response()->json(['data' => DB::table('dns_records')->where('domain_id', $domainId)->orderBy('name')->get()]);
    }

    public function storeDnsRecord(StoreDnsRecordRequest $request, string $domainId): JsonResponse
    {
        if ($this->findDomain($domainId) === null) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }

        $record = $request->validated();
        $now = UnixTime::now();
        $record += [
            'ttl' => 300,
            'priority' => null,
            'proxied' => true,
        ];
        $record = array_merge($record, [
            'id' => (string) Str::uuid(),
            'domain_id' => $domainId,
            'origin_type' => null,
            'origin_content' => null,
            'public_type' => null,
            'public_content' => null,
            'origin_host' => null,
            'origin_tls_verify' => 'ignore',
            'origin_scheme' => null,
            'origin_status' => 'pending',
            'geo_origins_json' => null,
            'routing_policy' => 'standard',
            'managed_by' => null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('dns_records')->insert($record);

        return response()->json(['data' => $record], 201);
    }

    public function origins(string $domainId): JsonResponse
    {
        return response()->json(['data' => DB::table('domain_origins')->where('domain_id', $domainId)->orderByDesc('is_primary')->get()]);
    }

    public function storeOrigin(StoreOriginRequest $request, string $domainId): JsonResponse
    {
        if ($this->findDomain($domainId) === null) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }

        $input = $request->validated();
        $now = UnixTime::now();
        $origin = [
            'id' => (string) Str::uuid(),
            'domain_id' => $domainId,
            'dns_record_id' => null,
            'source' => 'manual',
            'role' => $input['role'] ?? 'primary',
            'weight' => $input['weight'] ?? 1,
            'load_balancing_algorithm' => 'weighted_hash',
            'scheme' => $input['scheme'] ?? 'http',
            'host' => $input['host'],
            'port' => $input['port'] ?? (($input['scheme'] ?? 'http') === 'https' ? 443 : 80),
            'host_header' => null,
            'sni' => null,
            'tls_verify' => 'ignore',
            'preserve_host' => true,
            'is_primary' => ($input['role'] ?? 'primary') === 'primary',
            'health_check_enabled' => $input['health_check_enabled'] ?? false,
            'health_check_path' => $input['health_check_path'] ?? '/',
            'health_check_interval_seconds' => 30,
            'health_check_timeout_seconds' => 5,
            'connection_timeout_seconds' => 5,
            'response_timeout_seconds' => 30,
            'retry_attempts' => 1,
            'retry_budget_per_minute' => 60,
            'circuit_breaker_enabled' => true,
            'circuit_failure_threshold' => 5,
            'circuit_recovery_seconds' => 30,
            'max_concurrent_requests' => 0,
            'drain' => false,
            'shield_enabled' => false,
            'health_status' => 'unknown',
            'last_check_at' => null,
            'last_error' => null,
            'enabled' => $input['enabled'] ?? true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('domain_origins')->insert($origin);

        return response()->json(['data' => $origin], 201);
    }

    private function findDomain(string $domainId): ?array
    {
        $row = DB::table('domains')->where('id', $domainId)->first();

        return $row === null ? null : $this->withNameservers((array) $row);
    }

    private function withNameservers(array $domain): array
    {
        $domain['nameservers'] = DB::table('domain_nameservers')
            ->select('hostname', 'expected', 'observed', 'last_checked_at')
            ->where('domain_id', $domain['id'])
            ->orderBy('hostname')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        return $domain;
    }

    private function defaultNameservers(): array
    {
        $value = DB::table('platform_settings')->where('key', 'platform.nameservers')->value('value_json');
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return array_values(array_filter(array_map('strval', $decoded['hostnames'] ?? ['ns1.cdnlite.test', 'ns2.cdnlite.test'])));
    }
}
