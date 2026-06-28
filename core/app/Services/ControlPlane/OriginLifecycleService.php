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

    public function diagnose(string $domainId, string $originId): ?array
    {
        $origin = $this->find($domainId, $originId);
        if ($origin === null) {
            return null;
        }

        return $this->probeDetailed($origin);
    }

    public function healthReport(string $domainId): ?array
    {
        if ($this->domains->find($domainId) === null) {
            return null;
        }

        $origins = DB::table('domain_origins')
            ->where('domain_id', $domainId)
            ->orderByDesc('enabled')
            ->orderBy('role')
            ->orderBy('weight')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        $items = [];
        foreach ($origins as $origin) {
            $edges = DB::table('origin_health_observations as oho')
                ->leftJoin('edge_nodes as e', 'e.edge_id', '=', 'oho.edge_node_id')
                ->where('oho.domain_id', $domainId)
                ->where('oho.origin_id', $origin['id'])
                ->orderByDesc('oho.last_observed_at')
                ->orderBy('oho.edge_node_id')
                ->get([
                    'oho.edge_node_id',
                    'e.hostname',
                    'e.region',
                    'e.country',
                    'oho.status',
                    'oho.reason',
                    'oho.upstream_status',
                    'oho.latency_ms',
                    'oho.jitter_ms',
                    'oho.sample_count',
                    'oho.first_observed_at',
                    'oho.last_observed_at',
                    'oho.last_success_at',
                    'oho.last_failure_at',
                ])
                ->map(fn ($row) => $this->castObservation((array) $row))
                ->all();

            $items[] = [
                'origin_id' => (string) $origin['id'],
                'host' => (string) $origin['host'],
                'role' => (string) ($origin['role'] ?? 'primary'),
                'enabled' => $this->bool($origin['enabled'] ?? false),
                'health_check_enabled' => $this->bool($origin['health_check_enabled'] ?? false),
                'status' => (string) ($origin['health_status'] ?? 'unknown'),
                'last_check_at' => $origin['last_check_at'] === null ? null : (int) $origin['last_check_at'],
                'last_error' => $origin['last_error'] === null ? null : (string) $origin['last_error'],
                'edge_count' => count($edges),
                'healthy_edges' => count(array_filter($edges, static fn (array $edge): bool => $edge['status'] === 'healthy')),
                'slow_edges' => count(array_filter($edges, static fn (array $edge): bool => $edge['status'] === 'slow')),
                'unhealthy_edges' => count(array_filter($edges, static fn (array $edge): bool => $edge['status'] === 'unhealthy')),
                'max_latency_ms' => $this->maxNullable($edges, 'latency_ms'),
                'max_jitter_ms' => $this->maxNullable($edges, 'jitter_ms'),
                'edges' => $edges,
            ];
        }

        return [
            'items' => $items,
            'source' => 'edge_observations',
            'core_active_checks' => false,
            'message' => 'Origin routing health is updated from edge metrics. Core checks are diagnostic-only.',
        ];
    }

    private function find(string $domainId, string $originId): ?array
    {
        $row = DB::table('domain_origins')->where('domain_id', $domainId)->where('id', $originId)->first();

        return $row === null ? null : (array) $row;
    }

    private function probeDetailed(array $origin): array
    {
        $started = microtime(true);
        $host = (string) $origin['host'];
        $port = (int) $origin['port'];
        $scheme = (string) $origin['scheme'];
        $path = '/' . ltrim((string) ($origin['health_check_path'] ?? '/'), '/');
        $timeout = max(1, (int) ($origin['health_check_timeout_seconds'] ?? 5));
        $hostHeader = (string) (($origin['host_header'] ?? '') !== '' ? $origin['host_header'] : $host);
        $sni = (string) (($origin['sni'] ?? '') !== '' ? $origin['sni'] : $hostHeader);

        $result = [
            'origin_id' => (string) $origin['id'],
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'host_header' => $hostHeader,
            'sni' => $scheme === 'https' ? $sni : null,
            'tls_verify' => (string) ($origin['tls_verify'] ?? 'ignore'),
            'path' => $path,
            'started_at' => UnixTime::now(),
            'finished_at' => null,
            'duration_ms' => null,
            'dns' => ['ok' => false, 'addresses' => [], 'error' => null, 'duration_ms' => null],
            'tcp' => ['ok' => false, 'remote' => $host . ':' . $port, 'error' => null, 'duration_ms' => null],
            'tls' => ['ok' => $scheme !== 'https', 'error' => null, 'duration_ms' => null],
            'http' => ['ok' => false, 'status' => null, 'error' => null, 'duration_ms' => null],
            'healthy' => false,
            'error' => null,
        ];

        $dnsStarted = microtime(true);
        $addresses = @gethostbynamel($host);
        if (is_array($addresses) && $addresses !== []) {
            $result['dns']['ok'] = true;
            $result['dns']['addresses'] = array_values(array_unique($addresses));
        } elseif (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $result['dns']['ok'] = true;
            $result['dns']['addresses'] = [$host];
        } else {
            $result['dns']['error'] = 'dns_resolution_failed';
            $result['error'] = 'dns_resolution_failed';
        }
        $result['dns']['duration_ms'] = $this->elapsedMs($dnsStarted);

        $tcpStarted = microtime(true);
        $context = stream_context_create([
            'ssl' => [
                'SNI_enabled' => $sni !== '',
                'peer_name' => $sni !== '' ? $sni : $host,
                'verify_peer' => (string) ($origin['tls_verify'] ?? 'ignore') === 'verify',
                'verify_peer_name' => (string) ($origin['tls_verify'] ?? 'ignore') === 'verify',
            ],
        ]);
        $socket = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        $result['tcp']['duration_ms'] = $this->elapsedMs($tcpStarted);
        if (!is_resource($socket)) {
            $result['tcp']['error'] = $errstr !== '' ? $errstr : 'tcp_connect_failed';
            $result['error'] = $result['error'] ?? 'tcp_connect_failed';

            return $this->finishProbeDetailed($result, $started);
        }

        $result['tcp']['ok'] = true;
        stream_set_timeout($socket, $timeout);

        if ($scheme === 'https') {
            $tlsStarted = microtime(true);
            $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $result['tls']['duration_ms'] = $this->elapsedMs($tlsStarted);
            if ($crypto !== true) {
                $result['tls']['ok'] = false;
                $result['tls']['error'] = 'tls_handshake_failed';
                $result['error'] = $result['error'] ?? 'tls_handshake_failed';
                fclose($socket);

                return $this->finishProbeDetailed($result, $started);
            }
            $result['tls']['ok'] = true;
        }

        $httpStarted = microtime(true);
        $request = "GET {$path} HTTP/1.1\r\n"
            . 'Host: ' . $hostHeader . "\r\n"
            . "User-Agent: CDNLite-Origin-Diagnostic/1.0\r\n"
            . "Connection: close\r\n\r\n";
        if (@fwrite($socket, $request) === false) {
            $result['http']['error'] = 'http_write_failed';
            $result['error'] = $result['error'] ?? 'http_write_failed';
            fclose($socket);

            return $this->finishProbeDetailed($result, $started);
        }

        $line = @fgets($socket, 4096);
        fclose($socket);
        $result['http']['duration_ms'] = $this->elapsedMs($httpStarted);
        if (is_string($line) && preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $matches) === 1) {
            $status = (int) $matches[1];
            $result['http']['status'] = $status;
            $result['http']['ok'] = $status >= 100;
            $result['healthy'] = $status >= 200 && $status < 500;
            $result['error'] = $result['healthy'] ? null : 'http_' . $status;

            return $this->finishProbeDetailed($result, $started);
        }

        $result['http']['error'] = 'invalid_http_response';
        $result['error'] = $result['error'] ?? 'invalid_http_response';

        return $this->finishProbeDetailed($result, $started);
    }

    private function finishProbeDetailed(array $result, float $started): array
    {
        $result['finished_at'] = UnixTime::now();
        $result['duration_ms'] = $this->elapsedMs($started);

        return $result;
    }

    private function elapsedMs(float $started): int
    {
        return max(0, (int) round((microtime(true) - $started) * 1000));
    }

    private function castObservation(array $row): array
    {
        foreach (['latency_ms', 'jitter_ms', 'sample_count', 'first_observed_at', 'last_observed_at', 'last_success_at', 'last_failure_at'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }

        return [
            'edge_node_id' => (string) $row['edge_node_id'],
            'edge_label' => (string) ($row['hostname'] ?? $row['edge_node_id']),
            'region' => $row['region'] === null ? null : (string) $row['region'],
            'country' => $row['country'] === null ? null : (string) $row['country'],
            'status' => (string) $row['status'],
            'reason' => $row['reason'] === null ? null : (string) $row['reason'],
            'upstream_status' => $row['upstream_status'] === null ? null : (string) $row['upstream_status'],
            'latency_ms' => $row['latency_ms'],
            'jitter_ms' => $row['jitter_ms'],
            'sample_count' => $row['sample_count'],
            'first_observed_at' => $row['first_observed_at'],
            'last_observed_at' => $row['last_observed_at'],
            'last_success_at' => $row['last_success_at'],
            'last_failure_at' => $row['last_failure_at'],
        ];
    }

    private function maxNullable(array $rows, string $key): ?int
    {
        $values = array_values(array_filter(array_map(
            static fn (array $row): ?int => $row[$key] === null ? null : (int) $row[$key],
            $rows
        ), static fn (?int $value): bool => $value !== null));

        return $values === [] ? null : max($values);
    }

    private function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
