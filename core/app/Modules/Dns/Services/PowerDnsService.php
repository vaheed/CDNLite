<?php

namespace App\Modules\Dns\Services;

class PowerDnsService
{
    public function isEnabled(): bool
    {
        return $this->envBool('POWERDNS_ENABLED', false);
    }

    public function isStrict(): bool
    {
        return $this->envBool('POWERDNS_STRICT', false);
    }

    public function isConfigured(): bool
    {
        return trim((string) getenv('POWERDNS_API_URL')) !== ''
            && trim((string) getenv('POWERDNS_API_KEY')) !== '';
    }

    public function healthCheck(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'powerdns_missing_config', 'status' => 0];
        }

        $apiUrl = rtrim((string) getenv('POWERDNS_API_URL'), '/');
        $serverId = rawurlencode((string) (getenv('POWERDNS_SERVER_ID') ?: 'localhost'));
        $result = $this->request('GET', sprintf('%s/api/v1/servers/%s', $apiUrl, $serverId), null);
        $status = (int) ($result['status'] ?? 0);
        return [
            'ok' => $this->isSuccessStatus($status),
            'status' => $status,
            'error' => $this->isSuccessStatus($status) ? null : ($result['error'] ?? 'powerdns_api_error'),
        ];
    }

    public function syncReplace(string $zoneDomain, string $name, string $type, int $ttl, string $content): array
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

    public function syncReplaceMany(string $zoneDomain, string $name, string $type, int $ttl, array $contents): array
    {
        $fqdn = $this->toFqdn($name, $zoneDomain);
        $zoneId = $this->zoneId($zoneDomain);

        $records = [];
        foreach ($contents as $content) {
            if (!is_string($content) || trim($content) === '') {
                continue;
            }
            $records[] = [
                'content' => $this->normalizeContent($type, $content),
                'disabled' => false,
            ];
        }
        if ($records === []) {
            return ['ok' => false, 'error' => 'powerdns_records_empty', 'status' => 0, 'response' => ''];
        }

        $payload = [
            'rrsets' => [[
                'name' => $fqdn,
                'type' => strtoupper($type),
                'ttl' => $ttl,
                'changetype' => 'REPLACE',
                'records' => $records,
            ]],
        ];

        return $this->patchZone($zoneId, $payload);
    }

    public function syncDelete(string $zoneDomain, string $name, string $type): array
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

        $kind = strtoupper((string) (getenv('POWERDNS_ZONE_KIND') ?: 'NATIVE'));
        if (!in_array($kind, ['NATIVE', 'MASTER', 'SLAVE'], true)) {
            $kind = 'NATIVE';
        }
        $payload = [
            'name' => $zoneId,
            'kind' => $kind,
            'nameservers' => $this->zoneNameservers(),
        ];

        $result = $this->request('POST', $this->zonesBaseUrl(), $payload);
        if ($this->isSuccessStatus((int) ($result['status'] ?? 0))) {
            return ['ok' => true, 'created' => true];
        }
        return $result;
    }

    private function patchZone(string $zoneId, array $payload): array
    {
        $url = sprintf('%s/%s', $this->zonesBaseUrl(), rawurlencode($zoneId));
        $result = $this->request('PATCH', $url, $payload);
        $status = (int) ($result['status'] ?? 0);
        if ($this->isSuccessStatus($status)) {
            return ['ok' => true];
        }
        return $result;
    }

    private function getZone(string $zoneId): array
    {
        $url = sprintf('%s/%s', $this->zonesBaseUrl(), rawurlencode($zoneId));
        $result = $this->request('GET', $url, null);
        $status = (int) ($result['status'] ?? 0);
        if ($this->isSuccessStatus($status)) {
            return ['ok' => true];
        }
        return $result;
    }

    private function zonesBaseUrl(): string
    {
        $apiUrl = rtrim((string) getenv('POWERDNS_API_URL'), '/');
        $serverId = (string) (getenv('POWERDNS_SERVER_ID') ?: 'localhost');
        return sprintf('%s/api/v1/servers/%s/zones', $apiUrl, rawurlencode($serverId));
    }

    private function zoneNameservers(): array
    {
        $raw = trim((string) (getenv('POWERDNS_ZONE_NAMESERVERS') ?: ''));
        if ($raw === '') {
            return ['ns1.' . (string) (getenv('POWERDNS_DEFAULT_BASE_DOMAIN') ?: 'local.')];
        }

        $items = array_map('trim', explode(',', $raw));
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
        $apiUrl = rtrim((string) getenv('POWERDNS_API_URL'), '/');
        $apiKey = (string) getenv('POWERDNS_API_KEY');
        if ($apiUrl === '' || $apiKey === '') {
            return ['ok' => false, 'error' => 'powerdns_missing_config', 'status' => 0, 'response' => ''];
        }

        $headers = [
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey,
        ];
        $content = null;
        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return ['ok' => false, 'error' => 'powerdns_payload_encode_failed', 'status' => 0, 'response' => ''];
            }
            $content = $json;
        }

        $http = [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'timeout' => 8,
            'ignore_errors' => true,
        ];
        if ($content !== null) {
            $http['content'] = $content;
        }

        $ctx = stream_context_create(['http' => $http]);
        $response = @file_get_contents($url, false, $ctx);
        $status = $this->httpStatus($http_response_header ?? []);
        return [
            'ok' => false,
            'error' => 'powerdns_api_error',
            'status' => $status,
            'response' => is_string($response) ? $response : '',
        ];
    }

    private function isSuccessStatus(int $status): bool
    {
        return $status >= 200 && $status < 300;
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
        if (strtoupper($type) === 'TXT' && !str_starts_with($value, '"')) {
            return '"' . str_replace('"', '\"', $value) . '"';
        }
        return $value;
    }

    private function envBool(string $key, bool $default): bool
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        $value = strtolower(trim((string) $value));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
