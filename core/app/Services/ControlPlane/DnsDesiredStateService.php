<?php

namespace App\Services\ControlPlane;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DnsDesiredStateService
{
    public function build(): array
    {
        $rrsets = array_merge(
            $this->sharedCdnRrsets(),
            $this->customerZoneAuthorityRrsets(),
            $this->customerRecordRrsets(),
        );

        $grouped = [];
        foreach ($rrsets as $rrset) {
            $key = $rrset['zone_name'].'|'.strtolower($rrset['rrset_name']).'|'.$rrset['rrset_type'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = $rrset;
                continue;
            }

            $grouped[$key]['records'] = array_values(array_unique(array_merge(
                $grouped[$key]['records'],
                $rrset['records'],
            )));
            sort($grouped[$key]['records']);
            $grouped[$key]['desired_hash'] = $this->hash($grouped[$key]);
        }

        ksort($grouped);

        return array_values($grouped);
    }

    public function dryRun(): array
    {
        $rrsets = $this->build();

        return [
            'ok' => true,
            'mode' => 'dry_run',
            'planned_changes' => $this->plannedChanges($rrsets),
            'zones' => $this->zoneSummaries($rrsets),
            'rrsets' => $rrsets,
            'message' => 'Desired DNS state was built without writing PowerDNS.',
        ];
    }

    public function zoneSummaries(array $rrsets): array
    {
        $zones = [];
        foreach ($rrsets as $rrset) {
            $zone = $rrset['zone_name'];
            $zones[$zone]['zone_name'] = $zone;
            $zones[$zone]['rrset_count'] = ($zones[$zone]['rrset_count'] ?? 0) + 1;
            $zones[$zone]['rrsets'][] = $rrset['desired_hash'];
        }

        foreach ($zones as &$zone) {
            sort($zone['rrsets']);
            $zone['desired_hash'] = hash('sha256', json_encode($zone['rrsets'], JSON_UNESCAPED_SLASHES) ?: '[]');
            unset($zone['rrsets']);
        }

        ksort($zones);

        return array_values($zones);
    }

    public function persistDesiredState(): array
    {
        return DB::transaction(function (): array {
            $rrsets = $this->build();
            $generationId = $this->persistGeneration($rrsets);
            $this->pruneGeneration($generationId);
            $this->refreshSyncState($rrsets, $generationId);

            return [
                'ok' => true,
                'mode' => 'desired_state_persisted',
                'generation_id' => $generationId,
                'planned_changes' => $this->plannedChanges($rrsets),
                'zones' => $this->zoneSummaries($rrsets),
                'rrsets' => $rrsets,
                'message' => 'Desired DNS state was persisted; PowerDNS writes are handled by the reconciler migration.',
            ];
        });
    }

    private function sharedCdnRrsets(): array
    {
        $zone = $this->cdnZone();
        $rrsets = [
            $this->rrset($zone, '@', 'NS', 300, $this->nameservers(), 'platform_nameservers'),
        ];

        $edgeRecords = $this->edgeSelectionRrsets();
        foreach (['A', 'AAAA'] as $type) {
            if ($edgeRecords[$type] !== []) {
                $rrsets[] = $this->rrset(
                    $zone,
                    $this->proxyLabel(),
                    $edgeRecords[$type]['type'],
                    60,
                    $edgeRecords[$type]['records'],
                    'shared_cdn_edge_pool:'.strtolower($type),
                );
            }
        }

        return $rrsets;
    }

    private function customerZoneAuthorityRrsets(): array
    {
        return DB::table('domains')
            ->select('domain')
            ->orderBy('domain')
            ->get()
            ->map(fn (object $row): array => $this->rrset((string) $row->domain, '@', 'NS', 300, $this->nameservers(), 'customer_zone_nameservers'))
            ->all();
    }

    private function customerRecordRrsets(): array
    {
        $records = DB::table('dns_records as r')
            ->join('domains as d', 'd.id', '=', 'r.domain_id')
            ->select('r.*', 'd.id as site_id', 'd.domain')
            ->where('r.status', 'active')
            ->where('d.status', 'active')
            ->where('d.nameserver_status', 'verified')
            ->orderBy('d.domain')
            ->orderBy('r.name')
            ->orderBy('r.id')
            ->get();

        $rrsets = [];
        foreach ($records as $row) {
            $record = (array) $row;
            $type = strtoupper((string) ($record['public_type'] ?: $record['type']));
            $content = (string) ($record['public_content'] ?: $record['content']);

            if ($this->bool($record['proxied']) && $this->isApex((string) $record['name'], (string) $record['domain'])) {
                foreach ($this->edgeSelectionRrsets() as $dnsType => $edgeRrset) {
                    if ($edgeRrset === []) {
                        continue;
                    }

                    $rrsets[] = $this->rrset(
                        (string) $record['domain'],
                        '@',
                        (string) $edgeRrset['type'],
                        (int) $record['ttl'],
                        (array) $edgeRrset['records'],
                        'dns_record:'.$record['id'].':apex_'.strtolower((string) $dnsType),
                    );
                }
                continue;
            }

            $geoRoutes = $this->bool($record['proxied']) ? [] : $this->geoRoutes((string) $record['id']);
            if ($geoRoutes !== [] && in_array(strtoupper((string) $record['type']), ['A', 'AAAA'], true)) {
                $lua = $this->rawGeoDnsLuaRecord(strtoupper((string) $record['type']), $geoRoutes);
                if ($lua !== null) {
                    $rrsets[] = $this->rrset((string) $record['domain'], (string) $record['name'], 'LUA', (int) $record['ttl'], [$lua], 'dns_record:'.$record['id'].':raw_geodns');
                    continue;
                }
            }

            $rrsets[] = $this->rrset(
                (string) $record['domain'],
                (string) $record['name'],
                $type,
                (int) $record['ttl'],
                [$this->normalizeContent($type, $content, $record['priority'] === null ? null : (int) $record['priority'])],
                'dns_record:'.$record['id'],
            );
        }

        return $rrsets;
    }

    public function persistGeneration(array $rrsets): int
    {
        $now = UnixTime::now();
        $desiredHash = hash('sha256', json_encode($rrsets, JSON_UNESCAPED_SLASHES) ?: '[]');
        $generationId = DB::table('dns_desired_generations')->where('desired_hash', $desiredHash)->value('id');
        if ($generationId === null) {
            $generationId = DB::table('dns_desired_generations')->insertGetId([
                'desired_hash' => $desiredHash,
                'created_at' => $now,
            ]);
        }

        foreach ($rrsets as $rrset) {
            DB::table('desired_dns_rrsets')->upsert([[
                'zone_name' => $rrset['zone_name'],
                'rrset_name' => $rrset['rrset_name'],
                'rrset_type' => $rrset['rrset_type'],
                'ttl' => $rrset['ttl'],
                'records_json' => json_encode($rrset['records'], JSON_UNESCAPED_SLASHES),
                'owner' => 'cdnlite',
                'source' => $rrset['source'],
                'generation_id' => $generationId,
                'desired_hash' => $rrset['desired_hash'],
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['zone_name', 'rrset_name', 'rrset_type', 'owner'], [
                'ttl',
                'records_json',
                'source',
                'generation_id',
                'desired_hash',
                'updated_at',
            ]);
        }

        return (int) $generationId;
    }

    public function pruneGeneration(int $generationId): void
    {
        DB::table('desired_dns_rrsets')
            ->where('owner', 'cdnlite')
            ->where('generation_id', '<>', $generationId)
            ->delete();
    }

    public function refreshSyncState(array $rrsets, int $generationId): void
    {
        $now = UnixTime::now();
        foreach ($this->zoneSummaries($rrsets) as $zone) {
            $existing = DB::table('dns_sync_state')->where('zone_name', $zone['zone_name'])->first();
            $appliedHash = $existing?->applied_hash;
            $status = $appliedHash === $zone['desired_hash'] ? 'ok' : 'unknown';

            DB::table('dns_sync_state')->upsert([[
                'zone_name' => $zone['zone_name'],
                'desired_hash' => $zone['desired_hash'],
                'applied_hash' => $appliedHash,
                'generation_id' => $generationId,
                'status' => $status,
                'last_attempt_at' => $existing?->last_attempt_at,
                'last_success_at' => $existing?->last_success_at,
                'last_error' => $status === 'ok' ? null : $existing?->last_error,
                'last_status_code' => $existing?->last_status_code,
                'pending_changes' => $status === 'ok' ? 0 : (int) $zone['rrset_count'],
                'in_progress' => false,
                'updated_at' => $now,
            ]], ['zone_name'], [
                'desired_hash',
                'applied_hash',
                'generation_id',
                'status',
                'last_attempt_at',
                'last_success_at',
                'last_error',
                'last_status_code',
                'pending_changes',
                'in_progress',
                'updated_at',
            ]);
        }
    }

    private function plannedChanges(array $rrsets): int
    {
        $planned = 0;
        foreach ($this->zoneSummaries($rrsets) as $zone) {
            $applied = DB::table('dns_sync_state')->where('zone_name', $zone['zone_name'])->value('applied_hash');
            if ($applied !== $zone['desired_hash']) {
                $planned += (int) $zone['rrset_count'];
            }
        }

        return $planned;
    }

    private function rrset(string $zone, string $name, string $type, int $ttl, array $records, string $source): array
    {
        $zone = $this->fqdn($zone);
        $name = trim($name);
        $rrsetName = $name === '' || $name === '@'
            ? $zone
            : (str_ends_with($name, '.') ? strtolower($name) : strtolower($name).'.'.$zone);

        $rrset = [
            'zone_name' => $zone,
            'rrset_name' => $rrsetName,
            'rrset_type' => strtoupper($type),
            'ttl' => $ttl,
            'records' => array_values(array_unique($records)),
            'source' => $source,
        ];
        sort($rrset['records']);
        $rrset['desired_hash'] = $this->hash($rrset);

        return $rrset;
    }

    private function hash(array $rrset): string
    {
        unset($rrset['desired_hash']);

        return hash('sha256', json_encode($rrset, JSON_UNESCAPED_SLASHES) ?: '[]');
    }

    private function edgeSelectionRrsets(): array
    {
        $anycast = $this->staticAnycastIps();
        $rrsets = [
            'A' => [],
            'AAAA' => [],
        ];

        foreach (['A' => 'ipv4', 'AAAA' => 'ipv6'] as $type => $family) {
            if ($anycast[$family] !== []) {
                $rrsets[$type] = ['type' => $type, 'records' => $anycast[$family]];
                continue;
            }

            $lua = $this->edgeGeoLuaRecord($type);
            if ($lua !== null) {
                $rrsets[$type] = ['type' => 'LUA', 'records' => [$lua]];
            }
        }

        return $rrsets;
    }

    private function healthyEdgeTargets(string $type): array
    {
        if (!DB::getSchemaBuilder()->hasTable('edge_state')) {
            return [];
        }

        return DB::table('edge_state')
            ->select('ip', 'country', 'continent')
            ->where('ip_family', $type)
            ->where('healthy', true)
            ->orderBy('country')
            ->orderBy('continent')
            ->orderBy('ip')
            ->get()
            ->map(fn (object $row): array => [
                'ip' => (string) $row->ip,
                'country' => strtoupper((string) ($row->country ?? '')),
                'continent' => strtoupper((string) ($row->continent ?? '')),
            ])
            ->unique('ip')
            ->values()
            ->all();
    }

    private function edgeGeoLuaRecord(string $dnsType): ?string
    {
        $targets = $this->healthyEdgeTargets($dnsType);
        if ($targets === []) {
            return null;
        }

        $default = (string) $targets[0]['ip'];
        $countries = [];
        $continents = [];
        foreach ($targets as $target) {
            if ($target['country'] !== '') {
                $countries[$target['country']] = $target['ip'];
            }
            if ($target['continent'] !== '') {
                $continents[$target['continent']] = $target['ip'];
            }
        }

        $routes = [[
            'route_scope' => 'default',
            'answer_value' => $default,
            'enabled' => true,
        ]];
        foreach ($countries as $country => $ip) {
            $routes[] = ['route_scope' => 'country', 'country_code' => $country, 'answer_value' => $ip, 'enabled' => true];
        }
        foreach ($continents as $continent => $ip) {
            $routes[] = ['route_scope' => 'continent', 'continent_code' => $continent, 'answer_value' => $ip, 'enabled' => true];
        }

        return $this->rawGeoDnsLuaRecord($dnsType, $routes);
    }

    private function geoRoutes(string $recordId): array
    {
        return DB::table('dns_record_geo_routes')
            ->select('route_scope', 'country_code', 'continent_code', 'answer_type', 'answer_value', 'enabled')
            ->where('dns_record_id', $recordId)
            ->orderByRaw("CASE route_scope WHEN 'default' THEN 0 WHEN 'country' THEN 1 ELSE 2 END")
            ->orderBy('country_code')
            ->orderBy('continent_code')
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->map(function (object $row): array {
                $route = (array) $row;
                $route['enabled'] = $this->bool($route['enabled']);

                return $route;
            })
            ->all();
    }

    private function rawGeoDnsLuaRecord(string $dnsType, array $routes): ?string
    {
        $default = null;
        $countries = [];
        $continents = [];

        foreach ($routes as $route) {
            if (($route['enabled'] ?? true) !== true) {
                continue;
            }

            $answer = trim((string) ($route['answer_value'] ?? ''));
            if ($answer === '') {
                continue;
            }

            $scope = (string) ($route['route_scope'] ?? 'default');
            if ($scope === 'default') {
                $default = $answer;
            } elseif ($scope === 'country') {
                $countries[(string) $route['country_code']] = $answer;
            } elseif ($scope === 'continent') {
                $continents[(string) $route['continent_code']] = $answer;
            }
        }

        if ($default === null) {
            return null;
        }

        $branches = [];
        $first = true;
        foreach ($countries as $code => $answer) {
            $branches[] = sprintf('%s country(%s) then return %s', $first ? 'if' : 'elseif', $this->luaString($code), $this->luaString($answer));
            $first = false;
        }
        foreach ($continents as $code => $answer) {
            $branches[] = sprintf('%s continent(%s) then return %s', $first ? 'if' : 'elseif', $this->luaString($code), $this->luaString($answer));
            $first = false;
        }

        $lua = $branches === []
            ? $this->luaString($default)
            : ';'.implode(' ', $branches).' else return '.$this->luaString($default).' end';

        return $dnsType.' "'.strtr($lua, ["\\" => "\\\\", '"' => '\\"']).'"';
    }

    private function normalizeContent(string $type, string $content, ?int $priority): string
    {
        $value = trim($content);
        if ($type === 'TXT' && !str_starts_with($value, '"')) {
            return '"'.str_replace('"', '\"', $value).'"';
        }
        if ($type === 'MX') {
            return sprintf('%d %s', $priority ?? 0, $this->fqdn($value));
        }
        if (in_array($type, ['CNAME', 'NS', 'PTR'], true)) {
            return $this->fqdn($value);
        }

        return $value;
    }

    private function nameservers(): array
    {
        $value = $this->setting('platform.nameservers', ['hostnames' => ['ns1.cdnlite.test', 'ns2.cdnlite.test']]);
        $hostnames = is_array($value) ? ($value['hostnames'] ?? $value) : [];
        $nameservers = [];
        foreach ((array) $hostnames as $hostname) {
            $hostname = trim((string) $hostname);
            if ($hostname !== '') {
                $nameservers[] = $this->fqdn($hostname);
            }
        }

        return $nameservers === [] ? ['ns1.cdnlite.test.'] : array_values(array_unique($nameservers));
    }

    private function setting(string $key, mixed $default): mixed
    {
        $raw = DB::table('platform_settings')->where('key', $key)->value('value_json');
        if (!is_string($raw)) {
            return $default;
        }

        return json_decode($raw, true) ?? $default;
    }

    private function staticAnycastIps(): array
    {
        return [
            'ipv4' => $this->ipListSetting('platform.edge_dns.anycast_ipv4', 'anycast_ipv4', FILTER_FLAG_IPV4),
            'ipv6' => $this->ipListSetting('platform.edge_dns.anycast_ipv6', 'anycast_ipv6', FILTER_FLAG_IPV6),
        ];
    }

    private function ipListSetting(string $key, string $groupKey, int $flag): array
    {
        $value = $this->setting($key, null);
        if ($value === null) {
            $group = $this->setting('platform.edge_dns', []);
            $value = is_array($group) ? ($group[$groupKey] ?? []) : [];
        }

        $items = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value);
        $ips = [];
        foreach ((array) $items as $item) {
            $ip = trim((string) $item);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, $flag) !== false) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    private function cdnZone(): string
    {
        $zone = trim((string) env('CDNLITE_CDN_ZONE', 'cdn.example.net'));

        return $zone === '' ? 'cdn.example.net' : $zone;
    }

    private function proxyHost(): string
    {
        $host = strtolower(rtrim((string) env('CDNLITE_CDN_PROXY_HOST', 'proxy.cdn.example.net'), '.'));
        $zone = strtolower(rtrim($this->cdnZone(), '.'));
        if ($host === '' || !str_ends_with($host, '.'.$zone)) {
            throw new RuntimeException('cdn_proxy_host_must_belong_to_cdn_zone');
        }

        return $host;
    }

    private function proxyLabel(): string
    {
        $host = $this->proxyHost();
        $suffix = '.'.strtolower(rtrim($this->cdnZone(), '.'));

        return substr($host, 0, -strlen($suffix));
    }

    private function isApex(string $name, string $domain): bool
    {
        $name = strtolower(rtrim(trim($name), '.'));
        $domain = strtolower(rtrim(trim($domain), '.'));

        return $name === '' || $name === '@' || $name === $domain;
    }

    private function fqdn(string $name): string
    {
        $name = rtrim(strtolower(trim($name)), '.');

        return $name === '' ? '.' : $name.'.';
    }

    private function luaString(string $value): string
    {
        return "'".strtr($value, [
            "\\" => "\\\\",
            "'" => "\\'",
            "\n" => "\\n",
            "\r" => "\\r",
            "\t" => "\\t",
        ])."'";
    }

    private function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
