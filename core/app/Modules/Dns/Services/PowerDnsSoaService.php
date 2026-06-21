<?php

namespace App\Modules\Dns\Services;

use App\Modules\Settings\Repositories\SettingsRepository;
use App\Support\Database;

class PowerDnsSoaService
{
    public function __construct(
        private PowerDnsService $powerDns = new PowerDnsService(),
        private ?SettingsRepository $settings = null
    ) {
        $this->settings ??= new SettingsRepository();
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
                $content = trim((string) ($record['content'] ?? ''));
                $result['content'] = $content;
                $parts = preg_split('/\s+/', $content) ?: [];
                if (count($parts) === 7) {
                    [$result['primary_ns'], $result['hostmaster'], $serial, $refresh, $retry, $expire, $minimum] = $parts;
                    $result['primary_ns'] = $this->fqdn((string) $result['primary_ns']);
                    $result['hostmaster'] = $this->fqdn((string) $result['hostmaster']);
                    $result['serial'] = ctype_digit((string) $serial) ? (int) $serial : null;
                    $result['refresh'] = ctype_digit((string) $refresh) ? (int) $refresh : null;
                    $result['retry'] = ctype_digit((string) $retry) ? (int) $retry : null;
                    $result['expire'] = ctype_digit((string) $expire) ? (int) $expire : null;
                    $result['minimum'] = ctype_digit((string) $minimum) ? (int) $minimum : null;
                }
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
        $now = time();
        Database::pdo()->prepare(
            'INSERT INTO powerdns_zone_serials (zone_name, serial, content_hash, created_at, updated_at)
             VALUES (:zone, :serial, :hash, :now, :now)
             ON CONFLICT (zone_name) DO UPDATE SET
               serial = GREATEST(powerdns_zone_serials.serial, EXCLUDED.serial),
               content_hash = EXCLUDED.content_hash,
               updated_at = EXCLUDED.updated_at'
        )->execute(['zone' => $zone, 'serial' => $serial, 'hash' => $contentHash, 'now' => $now]);
    }

    private function storedSerial(string $zone): ?array
    {
        try {
            $stmt = Database::pdo()->prepare('SELECT serial, content_hash FROM powerdns_zone_serials WHERE zone_name = :zone');
            $stmt->execute(['zone' => $zone]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        } catch (\Throwable) {
            return null;
        }
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
                    $expected['minimum']
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
        $primary = $this->fqdn((string) $this->settings->value('platform.dns_authority', 'primary_ns'));
        $hostmaster = $this->fqdn((string) $this->settings->value('platform.dns_authority', 'hostmaster'));
        $config = [
            'primary_ns' => $primary,
            'hostmaster' => $hostmaster,
            'refresh' => (int) $this->settings->value('platform.dns_authority', 'soa_refresh'),
            'retry' => (int) $this->settings->value('platform.dns_authority', 'soa_retry'),
            'expire' => (int) $this->settings->value('platform.dns_authority', 'soa_expire'),
            'minimum' => (int) $this->settings->value('platform.dns_authority', 'soa_minimum'),
            'ttl' => (int) $this->settings->value('platform.dns_authority', 'soa_ttl'),
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

    private function isFqdn(string $name): bool
    {
        if (!str_ends_with($name, '.') || strlen($name) > 253) {
            return false;
        }
        $labels = explode('.', rtrim($name, '.'));
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63 || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i', $label)) {
                return false;
            }
        }
        return count($labels) >= 2;
    }

    private function isRname(string $name): bool
    {
        return $this->isFqdn($name) && !str_contains(rtrim($name, '.'), '@');
    }

    private function fqdn(string $name): string
    {
        $name = strtolower(trim($name));
        return str_ends_with($name, '.') ? $name : $name . '.';
    }

    private function zone(string $zone): string
    {
        return rtrim(strtolower(trim($zone)), '.') . '.';
    }

    private function todaySerial(): int
    {
        return (int) gmdate('Ymd') * 100;
    }
}
