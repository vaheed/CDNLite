<?php

namespace App\Modules\Proxy\Services;

use App\Support\AuditLog;
use App\Support\Database;
use App\Support\Uuid;

class OriginHealthService
{
    public function list(string $domainId): array
    {
        $this->ensurePrimaryFromDnsRecords($domainId);
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM domain_origins WHERE domain_id=:domain_id ORDER BY is_primary DESC, enabled DESC, created_at ASC, id ASC'
        );
        $stmt->execute([':domain_id' => $domainId]);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    public function create(string $domainId, array $input): array
    {
        $now = time();
        $isPrimary = array_key_exists('is_primary', $input) ? !empty($input['is_primary']) : false;
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($isPrimary) {
                $pdo->prepare('UPDATE domain_origins SET is_primary=false, updated_at=:updated_at WHERE domain_id=:domain_id')
                    ->execute([':domain_id' => $domainId, ':updated_at' => $now]);
            }
            $id = Uuid::v4();
            $row = [
                'id' => $id,
                'domain_id' => $domainId,
                'scheme' => (string) ($input['scheme'] ?? 'http'),
                'host' => strtolower(trim((string) ($input['host'] ?? ''))),
                'port' => (int) ($input['port'] ?? ((string) ($input['scheme'] ?? 'http') === 'https' ? 443 : 80)),
                'host_header' => trim((string) ($input['host_header'] ?? $input['host'] ?? '')),
                'sni' => trim((string) ($input['sni'] ?? $input['host'] ?? '')),
                'tls_verify' => (string) ($input['tls_verify'] ?? 'verify'),
                'preserve_host' => array_key_exists('preserve_host', $input) ? !empty($input['preserve_host']) : false,
                'dns_record_id' => $input['dns_record_id'] ?? null,
                'source' => (string) ($input['source'] ?? 'manual'),
                'role' => $isPrimary ? 'primary' : (string) ($input['role'] ?? 'backup'),
                'weight' => (int) ($input['weight'] ?? 1),
                'is_primary' => $isPrimary,
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
            'is_primary' => array_key_exists('is_primary', $input) ? !empty($input['is_primary']) : (bool) $existing['is_primary'],
            'health_check_path' => (string) ($input['health_check_path'] ?? $existing['health_check_path']),
            'health_check_interval_seconds' => (int) ($input['health_check_interval_seconds'] ?? $existing['health_check_interval_seconds']),
            'health_check_timeout_seconds' => (int) ($input['health_check_timeout_seconds'] ?? $existing['health_check_timeout_seconds']),
            'host_header' => array_key_exists('host_header', $input) ? trim((string) $input['host_header']) : (string) ($existing['host_header'] ?? ''),
            'sni' => array_key_exists('sni', $input) ? trim((string) $input['sni']) : (string) ($existing['sni'] ?? ''),
            'tls_verify' => (string) ($input['tls_verify'] ?? $existing['tls_verify'] ?? 'verify'),
            'preserve_host' => array_key_exists('preserve_host', $input) ? !empty($input['preserve_host']) : (bool) ($existing['preserve_host'] ?? false),
            'role' => array_key_exists('role', $input) ? (string) $input['role'] : (string) ($existing['role'] ?? 'backup'),
            'weight' => (int) ($input['weight'] ?? $existing['weight'] ?? 1),
            'enabled' => array_key_exists('enabled', $input) ? !empty($input['enabled']) : (bool) $existing['enabled'],
        ];
        if ($patch['host_header'] === '') {
            $patch['host_header'] = $patch['host'];
        }
        if ($patch['sni'] === '') {
            $patch['sni'] = $patch['host'];
        }
        if ($patch['is_primary']) {
            $patch['role'] = 'primary';
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($patch['is_primary']) {
                $pdo->prepare('UPDATE domain_origins SET is_primary=false, updated_at=:updated_at WHERE domain_id=:domain_id AND id<>:id')
                    ->execute([':domain_id' => $domainId, ':id' => $originId, ':updated_at' => $now]);
            }
            $pdo->prepare(
                'UPDATE domain_origins SET scheme=:scheme,host=:host,port=:port,host_header=:host_header,sni=:sni,
                 tls_verify=:tls_verify,preserve_host=:preserve_host,role=:role,weight=:weight,is_primary=:is_primary,
                 health_check_path=:health_check_path,health_check_interval_seconds=:health_check_interval_seconds,
                 health_check_timeout_seconds=:health_check_timeout_seconds,enabled=:enabled,updated_at=:updated_at
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
                ':is_primary' => (int) $patch['is_primary'],
                ':health_check_path' => $patch['health_check_path'],
                ':health_check_interval_seconds' => $patch['health_check_interval_seconds'],
                ':health_check_timeout_seconds' => $patch['health_check_timeout_seconds'],
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

    public function primaryAndBackupForDomain(string $domainId): array
    {
        $rows = array_values(array_filter($this->list($domainId), static fn (array $row): bool => !empty($row['enabled'])));
        $primary = null;
        $backup = null;
        foreach ($rows as $row) {
            if (!empty($row['is_primary']) && $primary === null) {
                $primary = $row;
                continue;
            }
            if (empty($row['is_primary']) && $backup === null) {
                $backup = $row;
            }
        }
        return ['primary' => $primary, 'backup' => $backup];
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
            'tls_verify' => (string) ($record['origin_tls_verify'] ?? 'verify'),
            'source' => 'dns_record',
            'role' => $this->hasPrimaryOrigin($domainId, (string) ($existing['id'] ?? '')) ? 'backup' : 'primary',
            'is_primary' => !$this->hasPrimaryOrigin($domainId, (string) ($existing['id'] ?? '')),
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

    public function addBackupFromDnsRecord(string $domainId, array $record): array
    {
        $host = strtolower(trim((string) ($record['origin_host'] ?? $record['content'] ?? '')));
        $scheme = $this->schemeForDnsRecord($record);
        $existing = Database::pdo()->prepare(
            'SELECT id FROM domain_origins WHERE domain_id=:domain_id AND lower(host)=:host AND scheme=:scheme LIMIT 1'
        );
        $existing->execute(['domain_id' => $domainId, 'host' => $host, 'scheme' => $scheme]);
        $id = $existing->fetchColumn();
        if ($id !== false) {
            $found = $this->find($domainId, (string) $id);
            if ($found !== null) {
                return $found;
            }
        }
        return $this->create($domainId, [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $scheme === 'https' ? 443 : 80,
            'is_primary' => false,
            'enabled' => true,
        ]);
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

        return (string) ($record['origin_tls_verify'] ?? 'verify') === 'ignore' ? 'https' : 'http';
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

    private function hasPrimaryOrigin(string $domainId, string $exceptOriginId = ''): bool
    {
        $sql = 'SELECT 1 FROM domain_origins WHERE domain_id=:domain_id AND is_primary=true';
        $params = [':domain_id' => $domainId];
        if ($exceptOriginId !== '') {
            $sql .= ' AND id<>:id';
            $params[':id'] = $exceptOriginId;
        }
        $sql .= ' LIMIT 1';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
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
            $geoOrigins['DEFAULT']['tls_verify'] = (string) ($origin['tls_verify'] ?? 'verify');
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
            ':origin_tls_verify' => (string) ($origin['tls_verify'] ?? 'verify'),
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
            'tls_verify' => (string) ($origin['tls_verify'] ?? 'verify'),
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
                'verify_peer' => (string) ($origin['tls_verify'] ?? 'verify') === 'verify',
                'verify_peer_name' => (string) ($origin['tls_verify'] ?? 'verify') === 'verify',
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

    private function ensurePrimaryFromDnsRecords(string $domainId): void
    {
        $exists = Database::pdo()->prepare('SELECT 1 FROM domain_origins WHERE domain_id=:domain_id AND is_primary=true LIMIT 1');
        $exists->execute([':domain_id' => $domainId]);
        if ($exists->fetchColumn() !== false) {
            return;
        }
        $record = Database::pdo()->prepare(
            "SELECT * FROM dns_records
             WHERE domain_id=:domain_id AND proxied=true
               AND COALESCE(NULLIF(origin_host, ''), NULLIF(origin_content, ''), content) IS NOT NULL
             ORDER BY name='@' DESC, created_at ASC LIMIT 1"
        );
        $record->execute([':domain_id' => $domainId]);
        $row = $record->fetch();
        if (!$row) {
            return;
        }
        $this->create($domainId, [
            'scheme' => (string) ($row['origin_scheme'] ?? 'http') ?: 'http',
            'host' => (string) ($row['origin_host'] ?: ($row['origin_content'] ?: $row['content'])),
            'port' => (string) ($row['origin_scheme'] ?? 'http') === 'https' ? 443 : 80,
            'is_primary' => true,
            'enabled' => true,
        ]);
    }

    private function cast(array $row): array
    {
        foreach (['port', 'weight', 'health_check_interval_seconds', 'health_check_timeout_seconds', 'created_at', 'updated_at'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['dns_record_id'] = $row['dns_record_id'] === null ? null : (string) $row['dns_record_id'];
        $row['source'] = (string) ($row['source'] ?? 'manual');
        $row['role'] = (string) ($row['role'] ?? (!empty($row['is_primary']) ? 'primary' : 'backup'));
        $row['host_header'] = (string) ($row['host_header'] ?: $row['host']);
        $row['sni'] = (string) ($row['sni'] ?: $row['host']);
        $row['tls_verify'] = (string) ($row['tls_verify'] ?? 'verify');
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
