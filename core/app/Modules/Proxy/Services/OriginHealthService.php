<?php

namespace App\Modules\Proxy\Services;

use App\Support\AuditLog;
use App\Support\Database;
use App\Support\Uuid;

class OriginHealthService
{
    public function list(string $domainId): array
    {
        $this->ensureOriginsFromDnsRecords($domainId);
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM domain_origins
             WHERE domain_id=:domain_id
             ORDER BY enabled DESC,
                      CASE health_status WHEN 'healthy' THEN 0 WHEN 'unknown' THEN 1 WHEN 'unhealthy' THEN 2 ELSE 1 END,
                      weight ASC,
                      created_at ASC,
                      id ASC"
        );
        $stmt->execute([':domain_id' => $domainId]);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    public function create(string $domainId, array $input): array
    {
        $now = time();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $id = Uuid::v4();
            $row = [
                'id' => $id,
                'domain_id' => $domainId,
                'scheme' => (string) ($input['scheme'] ?? 'http'),
                'host' => strtolower(trim((string) ($input['host'] ?? ''))),
                'port' => (int) ($input['port'] ?? ((string) ($input['scheme'] ?? 'http') === 'https' ? 443 : 80)),
                'host_header' => trim((string) ($input['host_header'] ?? $input['host'] ?? '')),
                'sni' => trim((string) ($input['sni'] ?? $input['host'] ?? '')),
                'tls_verify' => (string) ($input['tls_verify'] ?? 'ignore'),
                'preserve_host' => array_key_exists('preserve_host', $input) ? !empty($input['preserve_host']) : false,
                'dns_record_id' => $input['dns_record_id'] ?? null,
                'source' => (string) ($input['source'] ?? 'manual'),
                'role' => (string) ($input['role'] ?? 'origin'),
                'weight' => (int) ($input['weight'] ?? 1),
                'is_primary' => false,
                'health_check_path' => (string) ($input['health_check_path'] ?? '/'),
                'health_check_interval_seconds' => (int) ($input['health_check_interval_seconds'] ?? 30),
                'health_check_timeout_seconds' => (int) ($input['health_check_timeout_seconds'] ?? 5),
                'health_status' => 'unknown',
                'enabled' => array_key_exists('enabled', $input) ? !empty($input['enabled']) : true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($row['host_header'] === '') {
                $row['host_header'] = $row['host'];
            }
            if ($row['sni'] === '') {
                $row['sni'] = $row['host'];
            }
            $pdo->prepare(
                'INSERT INTO domain_origins
                 (id,domain_id,dns_record_id,source,role,weight,scheme,host,port,host_header,sni,tls_verify,preserve_host,is_primary,health_check_path,health_check_interval_seconds,health_check_timeout_seconds,health_status,last_check_at,last_error,enabled,created_at,updated_at)
                 VALUES
                 (:id,:domain_id,:dns_record_id,:source,:role,:weight,:scheme,:host,:port,:host_header,:sni,:tls_verify,:preserve_host,:is_primary,:health_check_path,:health_check_interval_seconds,:health_check_timeout_seconds,:health_status,NULL,NULL,:enabled,:created_at,:updated_at)'
            )->execute([
                ':id' => $row['id'],
                ':domain_id' => $row['domain_id'],
                ':dns_record_id' => $row['dns_record_id'],
                ':source' => $row['source'],
                ':role' => $row['role'],
                ':weight' => $row['weight'],
                ':scheme' => $row['scheme'],
                ':host' => $row['host'],
                ':port' => $row['port'],
                ':host_header' => $row['host_header'],
                ':sni' => $row['sni'],
                ':tls_verify' => $row['tls_verify'],
                ':preserve_host' => (int) $row['preserve_host'],
                ':is_primary' => (int) $row['is_primary'],
                ':health_check_path' => $row['health_check_path'],
                ':health_check_interval_seconds' => $row['health_check_interval_seconds'],
                ':health_check_timeout_seconds' => $row['health_check_timeout_seconds'],
                ':health_status' => $row['health_status'],
                ':enabled' => (int) $row['enabled'],
                ':created_at' => $row['created_at'],
                ':updated_at' => $row['updated_at'],
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $created = $this->find($domainId, $id);
        AuditLog::write('origin.create', 'origin', $id, $domainId, null, $created);
        $this->invalidateConfig();
        return $created;
    }

    public function update(string $domainId, string $originId, array $input): ?array
    {
        $existing = $this->find($domainId, $originId);
        if ($existing === null) {
            return null;
        }
        $now = time();
        $patch = [
            'scheme' => (string) ($input['scheme'] ?? $existing['scheme']),
            'host' => strtolower(trim((string) ($input['host'] ?? $existing['host']))),
            'port' => (int) ($input['port'] ?? $existing['port']),
            'health_check_path' => (string) ($input['health_check_path'] ?? $existing['health_check_path']),
            'health_check_interval_seconds' => (int) ($input['health_check_interval_seconds'] ?? $existing['health_check_interval_seconds']),
            'health_check_timeout_seconds' => (int) ($input['health_check_timeout_seconds'] ?? $existing['health_check_timeout_seconds']),
            'host_header' => array_key_exists('host_header', $input) ? trim((string) $input['host_header']) : (string) ($existing['host_header'] ?? ''),
            'sni' => array_key_exists('sni', $input) ? trim((string) $input['sni']) : (string) ($existing['sni'] ?? ''),
            'tls_verify' => (string) ($input['tls_verify'] ?? $existing['tls_verify'] ?? 'ignore'),
            'preserve_host' => array_key_exists('preserve_host', $input) ? !empty($input['preserve_host']) : (bool) ($existing['preserve_host'] ?? false),
            'role' => array_key_exists('role', $input) ? (string) $input['role'] : (string) ($existing['role'] ?? 'origin'),
            'weight' => (int) ($input['weight'] ?? $existing['weight'] ?? 1),
            'enabled' => array_key_exists('enabled', $input) ? !empty($input['enabled']) : (bool) $existing['enabled'],
            // DNS-linked origin edits should recover fast after a transient
            // outage. Reset health so the edge can route to the repaired origin
            // immediately instead of waiting for the next health probe.
            'health_status' => array_key_exists('health_status', $input) ? (string) $input['health_status'] : (string) ($existing['health_status'] ?? 'unknown'),
            'last_check_at' => array_key_exists('last_check_at', $input) ? ($input['last_check_at'] === null ? null : (int) $input['last_check_at']) : ($existing['last_check_at'] === null ? null : (int) $existing['last_check_at']),
            'last_error' => array_key_exists('last_error', $input) ? ($input['last_error'] === null ? null : (string) $input['last_error']) : ($existing['last_error'] === null ? null : (string) $existing['last_error']),
        ];
        if ($patch['host_header'] === '') {
            $patch['host_header'] = $patch['host'];
        }
        if ($patch['sni'] === '') {
            $patch['sni'] = $patch['host'];
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE domain_origins SET scheme=:scheme,host=:host,port=:port,host_header=:host_header,sni=:sni,
                 tls_verify=:tls_verify,preserve_host=:preserve_host,role=:role,weight=:weight,is_primary=:is_primary,
                 health_check_path=:health_check_path,health_check_interval_seconds=:health_check_interval_seconds,
                 health_check_timeout_seconds=:health_check_timeout_seconds,health_status=:health_status,
                 last_check_at=:last_check_at,last_error=:last_error,enabled=:enabled,updated_at=:updated_at
                 WHERE domain_id=:domain_id AND id=:id'
            )->execute([
                ':domain_id' => $domainId,
                ':id' => $originId,
                ':scheme' => $patch['scheme'],
                ':host' => $patch['host'],
                ':port' => $patch['port'],
                ':host_header' => $patch['host_header'],
                ':sni' => $patch['sni'],
                ':tls_verify' => $patch['tls_verify'],
                ':preserve_host' => (int) $patch['preserve_host'],
                ':role' => $patch['role'],
                ':weight' => $patch['weight'],
                ':is_primary' => 0,
                ':health_check_path' => $patch['health_check_path'],
                ':health_check_interval_seconds' => $patch['health_check_interval_seconds'],
                ':health_check_timeout_seconds' => $patch['health_check_timeout_seconds'],
                ':health_status' => $patch['health_status'],
                ':last_check_at' => $patch['last_check_at'],
                ':last_error' => $patch['last_error'],
                ':enabled' => (int) $patch['enabled'],
                ':updated_at' => $now,
            ]);
            if (!$this->skipDnsRecordSync($input) && $this->isDnsLinkedOrigin($existing)) {
                $this->syncDnsRecordFromLinkedOrigin($domainId, (string) $existing['dns_record_id'], $patch, $now);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $updated = $this->find($domainId, $originId);
        AuditLog::write('origin.update', 'origin', $originId, $domainId, $existing, $updated);
        $this->invalidateConfig();
        return $updated;
    }

    public function delete(string $domainId, string $originId): bool
    {
        $existing = $this->find($domainId, $originId);
        if ($existing === null) {
            return false;
        }
        $stmt = Database::pdo()->prepare('DELETE FROM domain_origins WHERE domain_id=:domain_id AND id=:id');
        $stmt->execute([':domain_id' => $domainId, ':id' => $originId]);
        AuditLog::write('origin.delete', 'origin', $originId, $domainId, $existing, null);
        $this->invalidateConfig();
        return $stmt->rowCount() > 0;
    }

    public function check(string $domainId, string $originId): ?array
    {
        $origin = $this->find($domainId, $originId);
        if ($origin === null) {
            return null;
        }
        $result = $this->probe($origin);
        $stmt = Database::pdo()->prepare(
            'UPDATE domain_origins SET health_status=:health_status,last_check_at=:last_check_at,last_error=:last_error,updated_at=:updated_at
             WHERE domain_id=:domain_id AND id=:id'
        );
        $stmt->execute([
            ':domain_id' => $domainId,
            ':id' => $originId,
            ':health_status' => $result['health_status'],
            ':last_check_at' => $result['last_check_at'],
            ':last_error' => $result['last_error'],
            ':updated_at' => $result['last_check_at'],
        ]);
        return $this->find($domainId, $originId);
    }

    public function test(string $domainId, string $originId): ?array
    {
        $origin = $this->find($domainId, $originId);
        if ($origin === null) {
            return null;
        }

        return $this->probeDetailed($origin);
    }

    public function checkDue(): array
    {
        $cutoff = time();
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM domain_origins
             WHERE enabled=true AND (last_check_at IS NULL OR last_check_at + health_check_interval_seconds <= :now)
             ORDER BY COALESCE(last_check_at, 0) ASC'
        );
        $stmt->execute([':now' => $cutoff]);
        $results = [];
        foreach ($stmt->fetchAll() as $row) {
            $checked = $this->check((string) $row['domain_id'], (string) $row['id']);
            if ($checked !== null) {
                $results[] = $checked;
            }
        }
        return ['checked' => count($results), 'results' => $results];
    }

    public function syncFromDnsRecord(string $domainId, array $record): ?array
    {
        if (empty($record['proxied']) || (string) ($record['status'] ?? 'active') !== 'active') {
            $this->deleteForDnsRecord($domainId, (string) $record['id']);
            return null;
        }

        $host = strtolower(trim((string) ($record['origin_host'] ?? $record['origin_content'] ?? $record['content'] ?? '')));
        if ($host === '') {
            return null;
        }

        $existing = $this->findForDnsRecord($domainId, (string) $record['id']);
        $scheme = $this->schemeForDnsRecord($record);
        $payload = [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $scheme === 'https' ? 443 : 80,
            'host_header' => $host,
            'sni' => $host,
            'tls_verify' => (string) ($record['origin_tls_verify'] ?? 'ignore'),
            'source' => 'dns_record',
            'role' => 'origin',
            'is_primary' => false,
            'enabled' => true,
            'dns_record_id' => (string) $record['id'],
        ];

        if ($existing !== null) {
            $payload['_skip_dns_record_sync'] = true;
            return $this->update($domainId, (string) $existing['id'], $payload);
        }

        return $this->create($domainId, $payload);
    }

    public function deleteForDnsRecord(string $domainId, string $dnsRecordId): void
    {
        $stmt = Database::pdo()->prepare(
            "DELETE FROM domain_origins WHERE domain_id=:domain_id AND dns_record_id=:dns_record_id AND source='dns_record'"
        );
        $stmt->execute([':domain_id' => $domainId, ':dns_record_id' => $dnsRecordId]);
    }

    private function find(string $domainId, string $originId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM domain_origins WHERE domain_id=:domain_id AND id=:id LIMIT 1');
        $stmt->execute([':domain_id' => $domainId, ':id' => $originId]);
        $row = $stmt->fetch();
        return $row ? $this->cast($row) : null;
    }

    private function schemeForDnsRecord(array $record): string
    {
        $scheme = (string) ($record['origin_scheme'] ?? '');
        if ($scheme !== '') {
            return $scheme;
        }

        // Fresh installs should favor the plain HTTP path unless the user
        // explicitly picked HTTPS. That keeps naked-IP and legacy backends
        // working without hidden TLS assumptions.
        return 'http';
    }

    private function findForDnsRecord(string $domainId, string $dnsRecordId): ?array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM domain_origins WHERE domain_id=:domain_id AND dns_record_id=:dns_record_id AND source='dns_record' LIMIT 1"
        );
        $stmt->execute([':domain_id' => $domainId, ':dns_record_id' => $dnsRecordId]);
        $row = $stmt->fetch();
        return $row ? $this->cast($row) : null;
    }

    private function isDnsLinkedOrigin(array $origin): bool
    {
        return (string) ($origin['source'] ?? '') === 'dns_record'
            && trim((string) ($origin['dns_record_id'] ?? '')) !== '';
    }

    private function skipDnsRecordSync(array $input): bool
    {
        return !empty($input['_skip_dns_record_sync']);
    }

    private function syncDnsRecordFromLinkedOrigin(string $domainId, string $dnsRecordId, array $origin, int $now): void
    {
        $pdo = Database::pdo();
        $record = $pdo->prepare(
            "SELECT * FROM dns_records WHERE domain_id=:domain_id AND id=:id AND proxied=true LIMIT 1"
        );
        $record->execute([':domain_id' => $domainId, ':id' => $dnsRecordId]);
        $row = $record->fetch();
        if (!$row) {
            return;
        }

        $host = strtolower(trim((string) $origin['host']));
        if ($host === '') {
            return;
        }
        $scheme = (string) $origin['scheme'] === 'https' ? 'https' : 'http';
        $geoOrigins = $this->decodeGeoOrigins($row['geo_origins_json'] ?? null);
        if (isset($geoOrigins['DEFAULT']) && is_array($geoOrigins['DEFAULT'])) {
            $geoOrigins['DEFAULT']['host'] = $host;
            $geoOrigins['DEFAULT']['scheme'] = $scheme;
            $geoOrigins['DEFAULT']['port'] = $scheme === 'https' ? 443 : 80;
            $geoOrigins['DEFAULT']['tls_verify'] = (string) ($origin['tls_verify'] ?? 'ignore');
            $geoOrigins['DEFAULT']['host_header'] = (string) ($origin['host_header'] ?? $host);
            $geoOrigins['DEFAULT']['sni'] = (string) ($origin['sni'] ?? $host);
            $geoOrigins['DEFAULT']['preserve_host'] = !empty($origin['preserve_host']);
        }

        $pdo->prepare(
            'UPDATE dns_records SET
                origin_host=:origin_host,
                origin_scheme=:origin_scheme,
                origin_tls_verify=:origin_tls_verify,
                geo_origins_json=:geo_origins_json,
                updated_at=:updated_at
             WHERE domain_id=:domain_id AND id=:id'
        )->execute([
            ':domain_id' => $domainId,
            ':id' => $dnsRecordId,
            ':origin_host' => $host,
            ':origin_scheme' => $scheme,
            ':origin_tls_verify' => (string) ($origin['tls_verify'] ?? 'ignore'),
            ':geo_origins_json' => $this->encodeGeoOrigins($geoOrigins),
            ':updated_at' => $now,
        ]);
    }

    private function decodeGeoOrigins(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function encodeGeoOrigins(array $origins): ?string
    {
        if ($origins === []) {
            return null;
        }
        return json_encode($origins, JSON_UNESCAPED_SLASHES);
    }

    private function probe(array $origin): array
    {
        $now = time();
        $path = '/' . ltrim((string) ($origin['health_check_path'] ?? '/'), '/');
        $url = sprintf('%s://%s:%d%s', $origin['scheme'], $origin['host'], (int) $origin['port'], $path);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => max(1, (int) ($origin['health_check_timeout_seconds'] ?? 5)),
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', (string) $header, $m)) {
                $status = (int) $m[1];
                break;
            }
        }
        $healthy = $body !== false && $status >= 200 && $status < 500;
        return [
            'health_status' => $healthy ? 'healthy' : 'unhealthy',
            'last_check_at' => $now,
            'last_error' => $healthy ? null : ($status > 0 ? 'http_' . $status : 'connection_failed'),
        ];
    }

    private function probeDetailed(array $origin): array
    {
        $started = microtime(true);
        $host = (string) $origin['host'];
        $port = (int) $origin['port'];
        $scheme = (string) $origin['scheme'];
        $path = '/' . ltrim((string) ($origin['health_check_path'] ?? '/'), '/');
        $timeout = max(1, (int) ($origin['health_check_timeout_seconds'] ?? 5));
        $hostHeader = !empty($origin['preserve_host'])
            ? $host
            : (string) ($origin['host_header'] ?: $host);
        $sni = (string) ($origin['sni'] ?: $hostHeader);

        $result = [
            'origin_id' => (string) $origin['id'],
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'host_header' => $hostHeader,
            'sni' => $scheme === 'https' ? $sni : null,
            'tls_verify' => (string) ($origin['tls_verify'] ?? 'ignore'),
            'path' => $path,
            'started_at' => time(),
            'finished_at' => null,
            'duration_ms' => null,
            'dns' => [
                'ok' => false,
                'addresses' => [],
                'error' => null,
                'duration_ms' => null,
            ],
            'tcp' => [
                'ok' => false,
                'remote' => $host . ':' . $port,
                'error' => null,
                'duration_ms' => null,
            ],
            'tls' => [
                'ok' => $scheme !== 'https',
                'error' => null,
                'duration_ms' => null,
            ],
            'http' => [
                'ok' => false,
                'status' => null,
                'error' => null,
                'duration_ms' => null,
            ],
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
                'SNI_enabled' => true,
                'peer_name' => $sni,
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
        if (is_string($line) && preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) {
            $status = (int) $m[1];
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
        $result['finished_at'] = time();
        $result['duration_ms'] = $this->elapsedMs($started);
        return $result;
    }

    private function elapsedMs(float $started): int
    {
        return max(0, (int) round((microtime(true) - $started) * 1000));
    }

    private function ensureOriginsFromDnsRecords(string $domainId): void
    {
        $record = Database::pdo()->prepare(
            "SELECT * FROM dns_records
             WHERE domain_id=:domain_id AND proxied=true AND status='active'
               AND COALESCE(NULLIF(origin_host, ''), NULLIF(origin_content, ''), content) IS NOT NULL
             ORDER BY name='@' DESC, created_at ASC, id ASC"
        );
        $record->execute([':domain_id' => $domainId]);
        foreach ($record->fetchAll() as $row) {
            $this->syncFromDnsRecord($domainId, $row);
        }
    }

    private function cast(array $row): array
    {
        foreach (['port', 'weight', 'health_check_interval_seconds', 'health_check_timeout_seconds', 'created_at', 'updated_at'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['dns_record_id'] = $row['dns_record_id'] === null ? null : (string) $row['dns_record_id'];
        $row['source'] = (string) ($row['source'] ?? 'manual');
        $row['role'] = (string) ($row['role'] ?? 'origin');
        $row['host_header'] = (string) ($row['host_header'] ?: $row['host']);
        $row['sni'] = (string) ($row['sni'] ?: $row['host']);
        $row['tls_verify'] = (string) ($row['tls_verify'] ?? 'ignore');
        $row['last_check_at'] = $row['last_check_at'] === null ? null : (int) $row['last_check_at'];
        $row['is_primary'] = in_array($row['is_primary'], [true, 1, '1', 't', 'true'], true);
        $row['enabled'] = in_array($row['enabled'], [true, 1, '1', 't', 'true'], true);
        $row['preserve_host'] = in_array($row['preserve_host'], [true, 1, '1', 't', 'true'], true);
        return $row;
    }

    private function invalidateConfig(): void
    {
        Database::pdo()->exec('UPDATE config_state SET active_snapshot_version = NULL WHERE id = 1');
    }
}
