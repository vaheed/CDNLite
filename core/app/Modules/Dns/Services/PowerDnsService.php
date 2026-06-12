<?php

namespace App\Modules\Dns\Services;

use App\Modules\Settings\Repositories\SettingsRepository;

class PowerDnsService
{
    private DnsSyncStateService $syncState;

    public function __construct(private ?SettingsRepository $settings = null)
    {
        $this->settings ??= new SettingsRepository();
        $this->syncState = new DnsSyncStateService();
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings->value('platform.powerdns', 'enabled');
    }

    public function isStrict(): bool
    {
        return (bool) $this->settings->value('platform.powerdns', 'strict');
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->settings->value('platform.powerdns', 'api_url')) !== ''
            && trim((string) $this->settings->value('platform.powerdns', 'api_key')) !== '';
    }

    public function healthCheck(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'powerdns_missing_config', 'status' => 0];
        }

        $apiUrl = rtrim((string) $this->settings->value('platform.powerdns', 'api_url'), '/');
        $serverId = rawurlencode((string) $this->settings->value('platform.powerdns', 'server_id'));
        $result = $this->request('GET', sprintf('%s/api/v1/servers/%s', $apiUrl, $serverId), null);
        $status = (int) ($result['status'] ?? 0);
        return [
            'ok' => $this->isSuccessStatus($status),
            'status' => $status,
            'error' => $this->isSuccessStatus($status) ? null : ($result['error'] ?? 'powerdns_api_error'),
        ];
    }

    public function putEphemeralRecord(string $zoneDomain, string $name, string $type, int $ttl, string $content): array
    {
        $fqdn = $this->toFqdn($name, $zoneDomain);
        $zoneId = $this->zoneId($zoneDomain);
        $payload = [
            'rrsets' => [[
                'name' => $fqdn,
                'type' => strtoupper($type),
                'ttl' => $ttl,
                'changetype' => 'REPLACE',
                'records' => [[
                    'content' => $this->normalizeContent($type, $content),
                    'disabled' => false,
                ]],
            ]],
        ];

        return $this->patchZone($zoneId, $payload);
    }

    public function deleteEphemeralRecord(string $zoneDomain, string $name, string $type): array
    {
        $fqdn = $this->toFqdn($name, $zoneDomain);
        $zoneId = $this->zoneId($zoneDomain);
        $payload = [
            'rrsets' => [[
                'name' => $fqdn,
                'type' => strtoupper($type),
                'changetype' => 'DELETE',
            ]],
        ];

        return $this->patchZone($zoneId, $payload);
    }

    public function patchRrsets(string $zoneDomain, array $rrsets): array
    {
        if ($rrsets === []) {
            return ['ok' => true, 'verified' => true, 'status' => 200];
        }
        return $this->patchZone($this->zoneId($zoneDomain), ['rrsets' => $rrsets]);
    }

    public function ensureZone(string $zoneDomain): array
    {
        $zoneId = $this->zoneId($zoneDomain);
        $existing = $this->getZone($zoneId);
        if (($existing['ok'] ?? false) === true) {
            return ['ok' => true, 'exists' => true];
        }
        if (($existing['status'] ?? 0) !== 404) {
            return $existing;
        }

        $kind = strtoupper((string) $this->settings->value('platform.powerdns', 'zone_kind'));
        if (!in_array($kind, ['NATIVE', 'MASTER', 'SLAVE'], true)) {
            $kind = 'NATIVE';
        }
        $payload = [
            'name' => $zoneId,
            'kind' => $kind,
            'nameservers' => $this->zoneNameservers(),
        ];

        $result = $this->request('POST', $this->zonesBaseUrl(), $payload);
        $normalized = $this->isSuccessStatus((int) ($result['status'] ?? 0))
            ? ['ok' => true, 'created' => true, 'status' => (int) $result['status']]
            : $result;
        $hash = $this->syncState->begin($zoneId, [], 'ensure_zone');
        $this->syncState->finish($zoneId, [], 'ensure_zone', $hash, $normalized);
        return $normalized;
    }

    private function patchZone(string $zoneId, array $payload): array
    {
        $rrsets = (array) ($payload['rrsets'] ?? []);
        $hash = $this->syncState->begin($zoneId, $rrsets, 'patch_rrsets');
        $url = sprintf('%s/%s', $this->zonesBaseUrl(), rawurlencode($zoneId));
        $result = $this->request('PATCH', $url, $payload);
        $status = (int) ($result['status'] ?? 0);
        if ($this->isSuccessStatus($status)) {
            if (!(bool) $this->settings->value('platform.powerdns', 'verify_after_write')) {
                $result = ['ok' => true, 'verified' => false, 'status' => $status];
            } else {
                $result = $this->verifyRrsets($zoneId, $rrsets);
            }
        }
        $this->syncState->finish($zoneId, $rrsets, 'patch_rrsets', $hash, $result);
        return $result;
    }

    public function getZone(string $zoneDomain): array
    {
        $zoneId = $this->zoneId($zoneDomain);
        $url = sprintf('%s/%s', $this->zonesBaseUrl(), rawurlencode($zoneId));
        $result = $this->request('GET', $url, null);
        $status = (int) ($result['status'] ?? 0);
        if ($this->isSuccessStatus($status)) {
            $zone = json_decode((string) ($result['response'] ?? ''), true);
            return is_array($zone)
                ? ['ok' => true, 'status' => $status, 'zone' => $zone]
                : ['ok' => false, 'error' => 'powerdns_invalid_json', 'status' => $status];
        }
        return $result;
    }

    public function verifyRrsets(string $zoneDomain, array $desiredRrsets): array
    {
        $result = $this->getZone($zoneDomain);
        if (($result['ok'] ?? false) !== true) {
            return $result;
        }

        $actual = [];
        foreach ((array) ($result['zone']['rrsets'] ?? []) as $rrset) {
            $actual[strtolower((string) $rrset['name']) . '|' . strtoupper((string) $rrset['type'])] = $rrset;
        }
        foreach ($desiredRrsets as $desired) {
            $key = strtolower((string) $desired['name']) . '|' . strtoupper((string) $desired['type']);
            if (($desired['changetype'] ?? 'REPLACE') === 'DELETE') {
                if (isset($actual[$key])) {
                    return ['ok' => false, 'verified' => false, 'error' => 'powerdns_verify_delete_failed', 'status' => 200];
                }
                continue;
            }
            if (!isset($actual[$key]) || $this->recordContents($actual[$key]) !== $this->recordContents($desired)) {
                return ['ok' => false, 'verified' => false, 'error' => 'powerdns_verify_mismatch', 'status' => 200];
            }
        }
        return ['ok' => true, 'verified' => true, 'status' => 200];
    }

    private function zonesBaseUrl(): string
    {
        $apiUrl = rtrim((string) $this->settings->value('platform.powerdns', 'api_url'), '/');
        $serverId = (string) $this->settings->value('platform.powerdns', 'server_id');
        return sprintf('%s/api/v1/servers/%s/zones', $apiUrl, rawurlencode($serverId));
    }

    private function zoneNameservers(): array
    {
        $configured = $this->settings->value('platform.nameservers', 'hostnames');
        $items = is_array($configured) ? $configured : explode(',', (string) $configured);
        $filtered = [];
        foreach ($items as $item) {
            if ($item === '') {
                continue;
            }
            $filtered[] = str_ends_with($item, '.') ? $item : ($item . '.');
        }
        return $filtered === [] ? ['ns1.local.'] : $filtered;
    }

    private function request(string $method, string $url, ?array $payload): array
    {
        $apiUrl = rtrim((string) $this->settings->value('platform.powerdns', 'api_url'), '/');
        $apiKey = (string) $this->settings->value('platform.powerdns', 'api_key');
        if ($apiUrl === '' || $apiKey === '') {
            return ['ok' => false, 'error' => 'powerdns_missing_config', 'status' => 0, 'response' => ''];
        }

        $headers = [
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey,
            'X-Request-ID: pdns-' . bin2hex(random_bytes(8)),
        ];
        $content = null;
        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return ['ok' => false, 'error' => 'powerdns_payload_encode_failed', 'status' => 0, 'response' => ''];
            }
            $content = $json;
        }

        $retries = max(0, (int) $this->settings->value('platform.powerdns', 'retries'));
        $sleepMs = max(0, (int) $this->settings->value('platform.powerdns', 'retry_sleep_ms'));
        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            $http = [
                'method' => $method,
                'header' => implode("\r\n", $headers) . "\r\n",
                'timeout' => max(1, (int) $this->settings->value('platform.powerdns', 'timeout_seconds')),
                'ignore_errors' => true,
            ];
            if ($content !== null) {
                $http['content'] = $content;
            }

            $ctx = stream_context_create(['http' => $http]);
            $response = @file_get_contents($url, false, $ctx);
            $status = $this->httpStatus($http_response_header ?? []);
            if (!$this->isRetryable($status) || $attempt === $retries) {
                return [
                    'ok' => $this->isSuccessStatus($status),
                    'error' => $this->isSuccessStatus($status) ? null : 'powerdns_api_error',
                    'status' => $status,
                    'response' => is_string($response) ? $response : '',
                    'attempts' => $attempt + 1,
                ];
            }
            usleep($sleepMs * (2 ** $attempt) * 1000);
        }
        return ['ok' => false, 'error' => 'powerdns_api_error', 'status' => 0, 'response' => ''];
    }

    private function isSuccessStatus(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    private function isRetryable(int $status): bool
    {
        return $status === 0 || $status === 429 || $status >= 500;
    }

    private function httpStatus(array $headers): int
    {
        if (empty($headers)) {
            return 0;
        }

        if (preg_match('#\s(\d{3})\s#', (string) $headers[0], $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    private function toFqdn(string $name, string $zoneDomain): string
    {
        $zone = rtrim(strtolower(trim($zoneDomain)), '.');
        $rrName = trim($name);
        if ($rrName === '' || $rrName === '@') {
            return $zone . '.';
        }
        if (str_ends_with($rrName, '.')) {
            return strtolower($rrName);
        }
        return strtolower($rrName . '.' . $zone . '.');
    }

    private function zoneId(string $zoneDomain): string
    {
        return rtrim(strtolower(trim($zoneDomain)), '.') . '.';
    }

    private function normalizeContent(string $type, string $content): string
    {
        $value = trim($content);
        $recordType = strtoupper($type);
        if ($recordType === 'TXT' && !str_starts_with($value, '"')) {
            return '"' . str_replace('"', '\"', $value) . '"';
        }
        if (in_array($recordType, ['ALIAS', 'CNAME', 'MX', 'NS', 'PTR'], true) && !str_ends_with($value, '.')) {
            return strtolower($value) . '.';
        }
        return $value;
    }

    private function recordContents(array $rrset): array
    {
        $contents = array_map(
            static fn (array $record): string => trim((string) ($record['content'] ?? '')),
            (array) ($rrset['records'] ?? [])
        );
        sort($contents);
        return $contents;
    }

}
