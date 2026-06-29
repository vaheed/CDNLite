<?php

namespace App\Services\ControlPlane;

use Illuminate\Support\Facades\DB;

final class DnsPowerDnsSoaService
{
    public function __construct(private PowerDnsClient $powerDns)
    {
    }

    public function repair(string $zone, array $managedRrsets): array
    {
        $plan = $this->plan($zone, $managedRrsets);
        if (($plan['ok'] ?? false) !== true || ($plan['valid'] ?? false) === true) {
            return $plan;
        }

        $rrset = $this->soaRrset($plan);
        $result = $this->powerDns->patchRrsets((string) $plan['zone'], [$rrset]);
        if (($result['ok'] ?? false) === true) {
            $this->persistSerial((string) $plan['zone'], (int) $plan['expected']['serial'], (string) $plan['content_hash']);
        }

        return $result + [
            'zone' => $plan['zone'],
            'valid' => false,
            'repaired' => ($result['ok'] ?? false) === true,
            'issues' => $plan['issues'],
            'expected' => $plan['expected'],
        ];
    }

    public function plan(string $zone, array $managedRrsets): array
    {
        $zone = $this->zone($zone);
        $config = $this->config();
        if ($config['errors'] !== []) {
            return ['ok' => false, 'zone' => $zone, 'error' => 'powerdns_soa_config_invalid', 'issues' => $config['errors']];
        }

        $actualResult = $this->powerDns->getZone($zone);
        if (($actualResult['ok'] ?? false) !== true) {
            return $actualResult + ['zone' => $zone];
        }

        $contentHash = $this->contentHash($managedRrsets);
        $soa = $this->actualSoa($zone, (array) ($actualResult['zone']['rrsets'] ?? []));
        $serial = $this->nextSerial($zone, $contentHash, $soa['serial']);
        $expected = [
            'name' => $zone,
            'ttl' => $config['ttl'],
            'primary_ns' => $config['primary_ns'],
            'hostmaster' => $config['hostmaster'],
            'serial' => $serial,
            'refresh' => $config['refresh'],
            'retry' => $config['retry'],
            'expire' => $config['expire'],
            'minimum' => $config['minimum'],
        ];

        $issues = $this->issues($soa, $expected, $config, $this->storedSerial($zone));

        return [
            'ok' => true,
            'zone' => $zone,
            'valid' => $issues === [],
            'issues' => $issues,
            'actual' => $soa,
            'expected' => $expected,
            'content_hash' => $contentHash,
            'would_repair' => $issues !== [],
            'repair_rrset' => $issues === [] ? null : $this->soaRrset(['zone' => $zone, 'expected' => $expected]),
        ];
    }

    public function preview(array $zones): array
    {
        $plans = [];
        foreach ($zones as $zone => $rrsets) {
            $plans[] = $this->plan((string) $zone, (array) $rrsets);
        }

        return $plans;
    }

    private function issues(array $soa, array $expected, array $config, ?array $stored): array
    {
        $issues = [];
        if ($soa['count'] === 0) {
            $issues[] = 'missing SOA';
            return $issues;
        }
        if ($soa['count'] > 1) {
            $issues[] = 'duplicate SOA';
        }
        if ($soa['name'] !== $expected['name']) {
            $issues[] = 'invalid SOA content';
        }
        if ($soa['ttl'] !== $expected['ttl']) {
            $issues[] = 'invalid SOA timing values';
        }
        if ($soa['primary_ns'] !== $config['primary_ns']) {
            $issues[] = 'wrong primary nameserver';
        }
        if ($soa['hostmaster'] !== $config['hostmaster']) {
            $issues[] = 'wrong hostmaster RNAME';
        }
        foreach (['refresh', 'retry', 'expire', 'minimum'] as $field) {
            if ((int) ($soa[$field] ?? -1) !== (int) $expected[$field]) {
                $issues[] = 'invalid SOA timing values';
                break;
            }
        }
        if ($soa['serial'] === null || $soa['serial'] < $expected['serial']) {
            $issues[] = 'stale or decreasing serial';
        } elseif ($soa['serial'] > $expected['serial']) {
            $issues[] = 'invalid SOA content';
        }
        if ($stored !== null && $soa['serial'] !== null && $soa['serial'] < (int) $stored['serial']) {
            $issues[] = 'stale or decreasing serial';
        }

        return array_values(array_unique($issues));
    }

    private function actualSoa(string $zone, array $rrsets): array
    {
        $result = [
            'name' => null,
            'ttl' => null,
            'count' => 0,
            'content' => null,
            'primary_ns' => null,
            'hostmaster' => null,
            'serial' => null,
            'refresh' => null,
            'retry' => null,
            'expire' => null,
            'minimum' => null,
        ];
        foreach ($rrsets as $rrset) {
            if (strtolower((string) ($rrset['name'] ?? '')) !== $zone || strtoupper((string) ($rrset['type'] ?? '')) !== 'SOA') {
                continue;
            }
            $result['name'] = strtolower((string) $rrset['name']);
            $result['ttl'] = (int) ($rrset['ttl'] ?? 0);
            foreach ((array) ($rrset['records'] ?? []) as $record) {
                if (!empty($record['disabled'])) {
                    continue;
                }
                $result['count']++;
                if ($result['content'] !== null) {
                    continue;
                }
                $parts = preg_split('/\s+/', trim((string) ($record['content'] ?? ''))) ?: [];
                if (count($parts) !== 7) {
                    continue;
                }

                [$result['primary_ns'], $result['hostmaster'], $serial, $refresh, $retry, $expire, $minimum] = $parts;
                $result['content'] = trim((string) ($record['content'] ?? ''));
                $result['primary_ns'] = $this->fqdn((string) $result['primary_ns']);
                $result['hostmaster'] = $this->fqdn((string) $result['hostmaster']);
                $result['serial'] = ctype_digit((string) $serial) ? (int) $serial : null;
                $result['refresh'] = ctype_digit((string) $refresh) ? (int) $refresh : null;
                $result['retry'] = ctype_digit((string) $retry) ? (int) $retry : null;
                $result['expire'] = ctype_digit((string) $expire) ? (int) $expire : null;
                $result['minimum'] = ctype_digit((string) $minimum) ? (int) $minimum : null;
            }
        }

        return $result;
    }

    private function nextSerial(string $zone, string $contentHash, ?int $actualSerial): int
    {
        $stored = $this->storedSerial($zone);
        if ($stored === null) {
            return max((int) ($actualSerial ?? 0), $this->todaySerial() + 1);
        }

        $floor = max((int) ($stored['serial'] ?? 0), (int) ($actualSerial ?? 0), $this->todaySerial());
        if ((string) $stored['content_hash'] === $contentHash) {
            return max((int) $stored['serial'], (int) ($actualSerial ?? 0));
        }

        return $floor + 1;
    }

    private function persistSerial(string $zone, int $serial, string $contentHash): void
    {
        $now = UnixTime::now();
        $stored = $this->storedSerial($zone);
        $serial = max($serial, (int) ($stored['serial'] ?? 0));

        DB::table('powerdns_zone_serials')->upsert([[
            'zone_name' => $zone,
            'serial' => $serial,
            'content_hash' => $contentHash,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['zone_name'], ['serial', 'content_hash', 'updated_at']);
    }

    private function storedSerial(string $zone): ?array
    {
        $row = DB::table('powerdns_zone_serials')->where('zone_name', $zone)->first();

        return $row === null ? null : (array) $row;
    }

    private function soaRrset(array $plan): array
    {
        $expected = $plan['expected'];

        return [
            'name' => $plan['zone'],
            'type' => 'SOA',
            'ttl' => $expected['ttl'],
            'changetype' => 'REPLACE',
            'records' => [[
                'content' => sprintf(
                    '%s %s %d %d %d %d %d',
                    $expected['primary_ns'],
                    $expected['hostmaster'],
                    $expected['serial'],
                    $expected['refresh'],
                    $expected['retry'],
                    $expected['expire'],
                    $expected['minimum'],
                ),
                'disabled' => false,
            ]],
        ];
    }

    private function contentHash(array $rrsets): string
    {
        $normalized = [];
        foreach ($rrsets as $rrset) {
            if (strtoupper((string) ($rrset['type'] ?? '')) === 'SOA') {
                continue;
            }
            $copy = $rrset;
            unset($copy['changetype']);
            $normalized[] = $copy;
        }
        usort($normalized, static fn (array $a, array $b): int => strcmp(json_encode($a) ?: '', json_encode($b) ?: ''));

        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES) ?: '[]');
    }

    private function config(): array
    {
        $primary = $this->fqdn($this->settingString('platform.dns_authority.primary_ns', (string) env('CDNLITE_DNS_PRIMARY_NS', 'ns1.faratar.ir.')));
        $hostmaster = $this->fqdn($this->settingString('platform.dns_authority.hostmaster', (string) env('CDNLITE_DNS_HOSTMASTER', 'hostmaster.faratar.ir.')));
        $config = [
            'primary_ns' => $primary,
            'hostmaster' => $hostmaster,
            'refresh' => $this->settingInt('platform.dns_authority.soa_refresh', (int) env('CDNLITE_DNS_SOA_REFRESH', 7200)),
            'retry' => $this->settingInt('platform.dns_authority.soa_retry', (int) env('CDNLITE_DNS_SOA_RETRY', 3600)),
            'expire' => $this->settingInt('platform.dns_authority.soa_expire', (int) env('CDNLITE_DNS_SOA_EXPIRE', 1209600)),
            'minimum' => $this->settingInt('platform.dns_authority.soa_minimum', (int) env('CDNLITE_DNS_SOA_MINIMUM', 60)),
            'ttl' => $this->settingInt('platform.dns_authority.soa_ttl', (int) env('CDNLITE_DNS_SOA_TTL', 60)),
            'errors' => [],
        ];
        if (!$this->isFqdn($primary)) {
            $config['errors'][] = 'primary_ns must be a valid FQDN';
        }
        if (!$this->isRname($hostmaster)) {
            $config['errors'][] = 'hostmaster_rname must be a valid SOA RNAME';
        }
        foreach (['refresh', 'retry', 'expire', 'minimum', 'ttl'] as $field) {
            if ($config[$field] <= 0) {
                $config['errors'][] = 'invalid SOA timing values';
                break;
            }
        }

        return $config;
    }

    private function todaySerial(): int
    {
        return ((int) gmdate('Ymd')) * 100;
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

    private function zone(string $zone): string
    {
        return $this->fqdn($zone);
    }

    private function fqdn(string $value): string
    {
        $value = rtrim(strtolower(trim($value)), '.');

        return $value === '' ? '.' : $value.'.';
    }

    private function isFqdn(string $value): bool
    {
        return $value !== '.' && str_ends_with($value, '.') && preg_match('/^[a-z0-9][a-z0-9.-]*\.$/', $value) === 1;
    }

    private function isRname(string $value): bool
    {
        return $this->isFqdn($value) && substr_count(rtrim($value, '.'), '.') >= 1;
    }
}
