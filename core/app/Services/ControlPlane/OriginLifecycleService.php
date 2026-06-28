<?php

namespace App\Services\ControlPlane;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OriginLifecycleService
{
    public function __construct(
        private DomainLifecycleService $domains,
        private AuditWriter $audit,
        private ConfigStateWriter $configState,
    ) {
    }

    public function list(string $domainId): ?array
    {
        if ($this->domains->find($domainId) === null) {
            return null;
        }

        return DB::table('domain_origins')
            ->where('domain_id', $domainId)
            ->orderByDesc('enabled')
            ->orderBy('role')
            ->orderBy('weight')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    public function create(string $domainId, array $input, ?array $actor = null): ?array
    {
        if ($this->domains->find($domainId) === null) {
            return null;
        }

        $now = UnixTime::now();
        $scheme = (string) ($input['scheme'] ?? 'http');
        $host = strtolower(trim((string) $input['host']));
        $origin = [
            'id' => (string) Str::uuid(),
            'domain_id' => $domainId,
            'dns_record_id' => $input['dns_record_id'] ?? null,
            'source' => $input['source'] ?? 'manual',
            'role' => $input['role'] ?? 'primary',
            'weight' => $input['weight'] ?? 1,
            'load_balancing_algorithm' => $input['load_balancing_algorithm'] ?? 'weighted_hash',
            'scheme' => $scheme,
            'host' => $host,
            'port' => $input['port'] ?? ($scheme === 'https' ? 443 : 80),
            'host_header' => array_key_exists('host_header', $input) ? trim((string) $input['host_header']) : $host,
            'sni' => array_key_exists('sni', $input) ? trim((string) $input['sni']) : '',
            'tls_verify' => $input['tls_verify'] ?? 'ignore',
            'preserve_host' => $input['preserve_host'] ?? true,
            'is_primary' => false,
            'health_check_enabled' => $input['health_check_enabled'] ?? false,
            'health_check_path' => $input['health_check_path'] ?? '/',
            'health_check_interval_seconds' => $input['health_check_interval_seconds'] ?? 30,
            'health_check_timeout_seconds' => $input['health_check_timeout_seconds'] ?? 5,
            'connection_timeout_seconds' => $input['connection_timeout_seconds'] ?? 5,
            'response_timeout_seconds' => $input['response_timeout_seconds'] ?? 30,
            'retry_attempts' => $input['retry_attempts'] ?? 1,
            'retry_budget_per_minute' => $input['retry_budget_per_minute'] ?? 60,
            'circuit_breaker_enabled' => $input['circuit_breaker_enabled'] ?? true,
            'circuit_failure_threshold' => $input['circuit_failure_threshold'] ?? 5,
            'circuit_recovery_seconds' => $input['circuit_recovery_seconds'] ?? 30,
            'max_concurrent_requests' => $input['max_concurrent_requests'] ?? 0,
            'drain' => $input['drain'] ?? false,
            'shield_enabled' => $input['shield_enabled'] ?? false,
            'health_status' => 'unknown',
            'last_check_at' => null,
            'last_error' => null,
            'enabled' => $input['enabled'] ?? true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('domain_origins')->insert($origin);
        $created = $this->find($domainId, $origin['id']);
        $this->audit->write('origin.create', 'origin', $origin['id'], null, $created, 'admin', $actor['id'] ?? null, $domainId);
        $this->configState->markDirty('origin.changed');

        return $created;
    }

    public function update(string $domainId, string $originId, array $input, ?array $actor = null): ?array
    {
        $existing = $this->find($domainId, $originId);
        if ($existing === null) {
            return null;
        }

        $patch = array_intersect_key($input, array_flip([
            'scheme',
            'host',
            'port',
            'host_header',
            'sni',
            'tls_verify',
            'preserve_host',
            'role',
            'weight',
            'load_balancing_algorithm',
            'health_check_enabled',
            'health_check_path',
            'health_check_interval_seconds',
            'health_check_timeout_seconds',
            'connection_timeout_seconds',
            'response_timeout_seconds',
            'retry_attempts',
            'retry_budget_per_minute',
            'circuit_breaker_enabled',
            'circuit_failure_threshold',
            'circuit_recovery_seconds',
            'max_concurrent_requests',
            'drain',
            'shield_enabled',
            'enabled',
        ]));
        if (array_key_exists('host', $patch)) {
            $patch['host'] = strtolower(trim((string) $patch['host']));
        }
        if (array_key_exists('host_header', $patch)) {
            $patch['host_header'] = trim((string) $patch['host_header']);
        }
        if (array_key_exists('sni', $patch)) {
            $patch['sni'] = trim((string) $patch['sni']);
        }
        $patch['updated_at'] = UnixTime::now();

        DB::table('domain_origins')->where('domain_id', $domainId)->where('id', $originId)->update($patch);
        $updated = $this->find($domainId, $originId);
        $this->audit->write('origin.update', 'origin', $originId, $existing, $updated, 'admin', $actor['id'] ?? null, $domainId);
        $this->configState->markDirty('origin.changed');

        return $updated;
    }

    public function delete(string $domainId, string $originId, ?array $actor = null): bool
    {
        $existing = $this->find($domainId, $originId);
        if ($existing === null) {
            return false;
        }

        DB::table('domain_origins')->where('domain_id', $domainId)->where('id', $originId)->delete();
        $this->audit->write('origin.delete', 'origin', $originId, $existing, null, 'admin', $actor['id'] ?? null, $domainId);
        $this->configState->markDirty('origin.changed');

        return true;
    }

    private function find(string $domainId, string $originId): ?array
    {
        $row = DB::table('domain_origins')->where('domain_id', $domainId)->where('id', $originId)->first();

        return $row === null ? null : (array) $row;
    }
}
