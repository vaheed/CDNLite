<?php

namespace App\Services\ControlPlane;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class PowerDnsClient
{
    public function enabled(): bool
    {
        return $this->settingBool('platform.powerdns.enabled', false);
    }

    public function configured(): bool
    {
        return $this->apiUrl() !== '' && $this->apiKey() !== '';
    }

    public function strict(): bool
    {
        return $this->settingBool('platform.powerdns.strict', false);
    }

    public function status(): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'powerdns_missing_config', 'status' => 0];
        }

        return $this->normalize($this->request('GET', $this->serverUrl()));
    }

    public function getZone(string $zone): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'powerdns_missing_config', 'status' => 0];
        }

        $response = $this->request('GET', $this->zoneUrl($zone));
        $result = $this->normalize($response);
        if (($result['ok'] ?? false) !== true) {
            return $result;
        }
        if (!$response instanceof Response) {
            return ['ok' => false, 'error' => 'powerdns_api_error', 'status' => 0];
        }

        $zoneBody = $response->json();
        if (!is_array($zoneBody)) {
            return ['ok' => false, 'error' => 'powerdns_invalid_json', 'status' => $response->status()];
        }

        return $result + ['zone' => $zoneBody];
    }

    public function ensureZone(string $zone): array
    {
        $existing = $this->getZone($zone);
        if (($existing['ok'] ?? false) === true) {
            return ['ok' => true, 'exists' => true, 'status' => (int) $existing['status']];
        }
        if ((int) ($existing['status'] ?? 0) !== 404) {
            return $existing;
        }

        $kind = strtoupper($this->settingString('platform.powerdns.zone_kind', 'NATIVE'));
        if (!in_array($kind, ['NATIVE', 'MASTER', 'SLAVE'], true)) {
            $kind = 'NATIVE';
        }

        return $this->normalize($this->request('POST', $this->zonesUrl(), [
            'name' => $this->zoneId($zone),
            'kind' => $kind,
            'nameservers' => $this->nameservers(),
        ]));
    }

    public function patchRrsets(string $zone, array $rrsets): array
    {
        if ($rrsets === []) {
            return ['ok' => true, 'verified' => true, 'status' => 200];
        }

        $result = $this->normalize($this->request('PATCH', $this->zoneUrl($zone), ['rrsets' => $rrsets]));
        if (($result['ok'] ?? false) !== true || !$this->settingBool('platform.powerdns.verify_after_write', true)) {
            return $result + ['verified' => false];
        }

        return $this->verifyRrsetsWithRetry($zone, $rrsets);
    }

    public function deleteZone(string $zone): array
    {
        $response = $this->request('DELETE', $this->zoneUrl($zone));
        if (!$response instanceof Response) {
            return $this->normalize($response) + ['deleted' => false];
        }
        if ($response->status() === 404) {
            return ['ok' => true, 'deleted' => false, 'status' => 404];
        }

        return $this->normalize($response) + ['deleted' => $response->successful()];
    }

    public function verifyRrsets(string $zone, array $desiredRrsets): array
    {
        $result = $this->getZone($zone);
        if (($result['ok'] ?? false) !== true) {
            return $result;
        }

        $actual = [];
        foreach ((array) ($result['zone']['rrsets'] ?? []) as $rrset) {
            $key = strtolower((string) ($rrset['name'] ?? '')).'|'.strtoupper((string) ($rrset['type'] ?? ''));
            $actual[$key] = $rrset;
        }

        foreach ($desiredRrsets as $desired) {
            $key = strtolower((string) ($desired['name'] ?? '')).'|'.strtoupper((string) ($desired['type'] ?? ''));
            if (($desired['changetype'] ?? 'REPLACE') === 'DELETE') {
                if (isset($actual[$key])) {
                    return ['ok' => false, 'verified' => false, 'error' => 'powerdns_verify_delete_failed', 'status' => 200];
                }
                continue;
            }

            if (!isset($actual[$key])) {
                return ['ok' => false, 'verified' => false, 'error' => 'powerdns_verify_mismatch', 'status' => 200];
            }

            if (!$this->rrsetMatches($desired, (array) $actual[$key])) {
                return ['ok' => false, 'verified' => false, 'error' => 'powerdns_verify_mismatch', 'status' => 200];
            }
        }

        return ['ok' => true, 'verified' => true, 'status' => 200];
    }

    private function verifyRrsetsWithRetry(string $zone, array $desiredRrsets): array
    {
        $attempts = max(1, $this->settingInt('platform.powerdns.retries', 2) + 1);
        $sleepMs = max(0, $this->settingInt('platform.powerdns.retry_sleep_ms', 250));
        $last = ['ok' => false, 'verified' => false, 'error' => 'powerdns_verify_mismatch', 'status' => 200];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $last = $this->verifyRrsets($zone, $desiredRrsets);
            if (($last['ok'] ?? false) === true || ($last['error'] ?? null) !== 'powerdns_verify_mismatch') {
                return $last;
            }
            if ($attempt < $attempts && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        return $last;
    }

    private function request(string $method, string $url, ?array $payload = null): Response|array
    {
        $request = Http::withHeaders([
            'X-API-Key' => $this->apiKey(),
            'X-Request-ID' => 'pdns-'.bin2hex(random_bytes(8)),
        ])->acceptJson()
            ->asJson()
            ->timeout(max(1, $this->settingInt('platform.powerdns.timeout_seconds', 10)))
            ->retry(
                max(0, $this->settingInt('platform.powerdns.retries', 2)),
                max(0, $this->settingInt('platform.powerdns.retry_sleep_ms', 250)),
                null,
                false,
            );

        try {
            return match ($method) {
                'GET' => $request->get($url),
                'POST' => $request->post($url, $payload ?? []),
                'PATCH' => $request->patch($url, $payload ?? []),
                'DELETE' => $request->delete($url),
                default => throw new \InvalidArgumentException('unsupported_powerdns_method'),
            };
        } catch (ConnectionException $exception) {
            return ['ok' => false, 'status' => 0, 'error' => 'powerdns_api_unreachable', 'response' => $exception->getMessage()];
        }
    }

    private function normalize(Response|array $response): array
    {
        if (is_array($response)) {
            return $response + ['ok' => false, 'status' => 0, 'error' => 'powerdns_api_error', 'response' => ''];
        }

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'error' => $response->successful() ? null : 'powerdns_api_error',
            'response' => $response->body(),
        ];
    }

    private function recordContents(array $rrset): array
    {
        $contents = array_map(
            static fn (array $record): string => trim((string) ($record['content'] ?? '')),
            (array) ($rrset['records'] ?? []),
        );
        sort($contents);

        return $contents;
    }

    private function rrsetMatches(array $desired, array $actual): bool
    {
        if ((int) ($actual['ttl'] ?? 0) !== (int) ($desired['ttl'] ?? 0)) {
            return false;
        }

        if (strtoupper((string) ($desired['type'] ?? '')) === 'SOA') {
            return $this->soaContentsMatch($this->recordContents($desired), $this->recordContents($actual));
        }

        return $this->recordContents($desired) === $this->recordContents($actual);
    }

    private function soaContentsMatch(array $wanted, array $found): bool
    {
        if (count($wanted) !== 1 || count($found) !== 1) {
            return false;
        }

        $expected = $this->parseSoaContent($wanted[0]);
        $actual = $this->parseSoaContent($found[0]);
        if ($expected === null || $actual === null) {
            return false;
        }

        foreach (['primary_ns', 'hostmaster', 'refresh', 'retry', 'expire', 'minimum'] as $field) {
            if ($expected[$field] !== $actual[$field]) {
                return false;
            }
        }

        // PowerDNS may advance the SOA serial while applying a zone update.
        return $actual['serial'] >= $expected['serial'];
    }

    private function parseSoaContent(string $content): ?array
    {
        $parts = preg_split('/\s+/', trim($content)) ?: [];
        if (count($parts) !== 7) {
            return null;
        }

        [$primary, $hostmaster, $serial, $refresh, $retry, $expire, $minimum] = $parts;
        foreach ([$serial, $refresh, $retry, $expire, $minimum] as $number) {
            if (!ctype_digit((string) $number)) {
                return null;
            }
        }

        return [
            'primary_ns' => $this->fqdn((string) $primary),
            'hostmaster' => $this->fqdn((string) $hostmaster),
            'serial' => (int) $serial,
            'refresh' => (int) $refresh,
            'retry' => (int) $retry,
            'expire' => (int) $expire,
            'minimum' => (int) $minimum,
        ];
    }

    private function fqdn(string $value): string
    {
        $value = rtrim(strtolower(trim($value)), '.');

        return $value === '' ? '.' : $value.'.';
    }

    private function serverUrl(): string
    {
        return sprintf('%s/api/v1/servers/%s', $this->apiUrl(), rawurlencode($this->serverId()));
    }

    private function zonesUrl(): string
    {
        return $this->serverUrl().'/zones';
    }

    private function zoneUrl(string $zone): string
    {
        return $this->zonesUrl().'/'.rawurlencode($this->zoneId($zone));
    }

    private function zoneId(string $zone): string
    {
        return rtrim(strtolower(trim($zone)), '.').'.';
    }

    private function nameservers(): array
    {
        $value = $this->setting('platform.nameservers', ['hostnames' => ['ns1.cdnlite.test', 'ns2.cdnlite.test']]);
        $hostnames = is_array($value) ? ($value['hostnames'] ?? $value) : [];
        $nameservers = [];
        foreach ((array) $hostnames as $hostname) {
            $hostname = rtrim(strtolower(trim((string) $hostname)), '.');
            if ($hostname !== '') {
                $nameservers[] = $hostname.'.';
            }
        }

        return $nameservers === [] ? ['ns1.cdnlite.test.'] : array_values(array_unique($nameservers));
    }

    private function apiUrl(): string
    {
        return rtrim($this->settingString('platform.powerdns.api_url', ''), '/');
    }

    private function apiKey(): string
    {
        return $this->settingString('platform.powerdns.api_key', '');
    }

    private function serverId(): string
    {
        return $this->settingString('platform.powerdns.server_id', 'localhost');
    }

    private function settingBool(string $key, bool $default): bool
    {
        return filter_var($this->setting($key, $default), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function settingInt(string $key, int $default): int
    {
        $value = $this->setting($key, $default);

        return is_numeric($value) ? (int) $value : $default;
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
}
