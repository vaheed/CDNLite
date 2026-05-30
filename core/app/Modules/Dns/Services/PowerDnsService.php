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

    private function patchZone(string $zoneId, array $payload): array
    {
        $apiUrl = rtrim((string) getenv('POWERDNS_API_URL'), '/');
        $apiKey = (string) getenv('POWERDNS_API_KEY');
        $serverId = (string) (getenv('POWERDNS_SERVER_ID') ?: 'localhost');

        if ($apiUrl === '' || $apiKey === '') {
            return ['ok' => false, 'error' => 'powerdns_missing_config'];
        }

        $url = sprintf('%s/api/v1/servers/%s/zones/%s', $apiUrl, rawurlencode($serverId), rawurlencode($zoneId));
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'error' => 'powerdns_payload_encode_failed'];
        }

        $headers = implode("\r\n", [
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey,
        ]);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'PATCH',
                'header' => $headers . "\r\n",
                'content' => $json,
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $ctx);
        $status = $this->httpStatus($http_response_header ?? []);
        if ($status === 204) {
            return ['ok' => true];
        }

        return [
            'ok' => false,
            'error' => 'powerdns_api_error',
            'status' => $status,
            'response' => is_string($response) ? $response : '',
        ];
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

