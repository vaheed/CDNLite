<?php

namespace App\Services\ControlPlane;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class DnsRecordService
{
    private const TYPES = ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'CAA', 'NS', 'SRV'];

    public function __construct(
        private AuditWriter $audit,
        private ConfigStateWriter $config,
        private DnsReconcileQueue $dnsReconcile,
    ) {
    }

    public function list(string $domainId): ?array
    {
        $domain = $this->domain($domainId);
        if ($domain === null) {
            return null;
        }

        $records = DB::table('dns_records')
            ->where('domain_id', $domainId)
            ->select('dns_records.*')
            ->selectSub(function ($query): void {
                $query->from('dns_record_geo_routes')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('dns_record_geo_routes.dns_record_id', 'dns_records.id')
                    ->where('enabled', true)
                    ->where('route_scope', '<>', 'default');
            }, 'geo_routes_count')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => $this->cast((array) $row, $domain))
            ->all();

        return array_merge($this->platformNameserverRecords($domain), $records);
    }

    public function find(string $domainId, string $recordId): ?array
    {
        $domain = $this->domain($domainId);
        if ($domain === null) {
            return null;
        }

        $row = DB::table('dns_records')
            ->where('domain_id', $domainId)
            ->where('id', $recordId)
            ->select('dns_records.*')
            ->selectSub(function ($query): void {
                $query->from('dns_record_geo_routes')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('dns_record_geo_routes.dns_record_id', 'dns_records.id')
                    ->where('enabled', true)
                    ->where('route_scope', '<>', 'default');
            }, 'geo_routes_count')
            ->first();

        return $row === null ? null : $this->cast((array) $row, $domain);
    }

    public function create(string $domainId, array $input, ?array $actor): ?array
    {
        $domain = $this->domain($domainId);
        if ($domain === null) {
            return null;
        }

        $record = $this->normalize($domain, $input);
        $this->assertCompatible($domainId, null, $record);

        $now = UnixTime::now();
        $public = $this->publicRecordFor($domain, $record);
        $created = $record + [
            'id' => (string) Str::uuid(),
            'domain_id' => $domainId,
            'geo_policy_id' => null,
            'origin_type' => $record['type'],
            'origin_content' => $record['content'],
            'public_type' => $public['type'],
            'public_content' => $public['content'],
            'origin_host' => $record['proxied'] ? $record['origin_host'] : null,
            'origin_tls_verify' => 'ignore',
            'origin_scheme' => $record['proxied'] ? 'http' : null,
            'origin_status' => $record['proxied'] ? 'pending' : 'dns_only',
            'geo_origins_json' => null,
            'routing_policy' => 'standard',
            'managed_by' => null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('dns_records')->insert($created);
        if (array_key_exists('geo_routes', $input)) {
            $this->replaceGeoRoutes($domainId, $created['id'], (array) $input['geo_routes'], $actor, false);
        }
        $this->syncDnsOrigin($created);
        $stored = $this->find($domainId, $created['id']);
        $this->afterMutation('dns.record.create', null, $stored, $domainId, $created['id'], $actor);

        return $stored;
    }

    public function update(string $domainId, string $recordId, array $input, ?array $actor): ?array
    {
        $domain = $this->domain($domainId);
        $existing = $this->find($domainId, $recordId);
        if ($domain === null || $existing === null) {
            return null;
        }

        $record = $this->normalize($domain, $input + [
            'type' => $existing['type'],
            'name' => $existing['name'],
            'content' => $existing['content'],
            'ttl' => $existing['ttl'],
            'priority' => $existing['priority'],
            'proxied' => $existing['proxied'],
            'status' => $existing['status'],
        ], true);
        $this->assertCompatible($domainId, $recordId, $record);
        $public = $this->publicRecordFor($domain, $record);

        DB::table('dns_records')
            ->where('domain_id', $domainId)
            ->where('id', $recordId)
            ->update([
                'type' => $record['type'],
                'name' => $record['name'],
                'content' => $record['content'],
                'ttl' => $record['ttl'],
                'priority' => $record['priority'],
                'proxied' => $record['proxied'],
                'origin_type' => $record['type'],
                'origin_content' => $record['content'],
                'public_type' => $public['type'],
                'public_content' => $public['content'],
                'origin_host' => $record['proxied'] ? $record['origin_host'] : null,
                'origin_scheme' => $record['proxied'] ? 'http' : null,
                'origin_status' => $record['proxied'] ? 'pending' : 'dns_only',
                'status' => $record['status'],
                'updated_at' => UnixTime::now(),
            ]);

        if (array_key_exists('geo_routes', $input)) {
            $this->replaceGeoRoutes($domainId, $recordId, (array) $input['geo_routes'], $actor, false);
        }
        $updated = $this->find($domainId, $recordId);
        if ($updated !== null) {
            $this->syncDnsOrigin($updated);
        }
        $this->afterMutation('dns.record.update', $existing, $updated, $domainId, $recordId, $actor);

        return $updated;
    }

    public function delete(string $domainId, string $recordId, ?array $actor): bool
    {
        $existing = $this->find($domainId, $recordId);
        if ($existing === null) {
            return false;
        }

        $deleted = DB::table('dns_records')
            ->where('domain_id', $domainId)
            ->where('id', $recordId)
            ->delete() > 0;

        if ($deleted) {
            $this->afterMutation('dns.record.delete', $existing, null, $domainId, $recordId, $actor);
        }

        return $deleted;
    }

    public function queueReconcile(string $domainId, string $recordId, ?array $actor): ?array
    {
        $record = $this->find($domainId, $recordId);
        if ($record === null) {
            return null;
        }

        $this->audit->write('dns.record.reconcile', 'dns_record', $recordId, $record, $record, 'admin', $actor['id'] ?? null, $domainId, [
            'local_state_saved' => true,
        ]);
        $this->config->markDirty('dns.record.reconcile');
        $this->dnsReconcile->queueForDomain($domainId);

        return $this->find($domainId, $recordId);
    }

    public function geoRoutes(string $domainId, string $recordId): ?array
    {
        if ($this->find($domainId, $recordId) === null) {
            return null;
        }

        return DB::table('dns_record_geo_routes')
            ->where('dns_record_id', $recordId)
            ->orderBy('priority')
            ->orderBy('route_scope')
            ->orderBy('country_code')
            ->orderBy('continent_code')
            ->get()
            ->map(fn (object $row): array => $this->castGeoRoute((array) $row))
            ->all();
    }

    public function replaceGeoRoutes(string $domainId, string $recordId, array $routes, ?array $actor, bool $markChanged = true): ?array
    {
        $record = $this->find($domainId, $recordId);
        if ($record === null) {
            return null;
        }
        if ($this->bool($record['proxied'] ?? false)) {
            throw new RuntimeException('geo_routes_require_dns_only_record');
        }
        if (!in_array(strtoupper((string) $record['type']), ['A', 'AAAA'], true) && $routes !== []) {
            throw new RuntimeException('geo_routes_require_address_record');
        }

        $now = UnixTime::now();
        $normalized = $this->normalizeGeoRoutes($routes, strtoupper((string) $record['type']));
        DB::transaction(function () use ($recordId, $normalized, $now): void {
            DB::table('dns_record_geo_routes')->where('dns_record_id', $recordId)->delete();
            foreach ($normalized as $index => $route) {
                DB::table('dns_record_geo_routes')->insert($route + [
                    'id' => (string) Str::uuid(),
                    'dns_record_id' => $recordId,
                    'priority' => $index,
                    'weight' => 100,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        if ($markChanged) {
            $this->afterMutation('dns.geo_routes.update', $record, $this->find($domainId, $recordId), $domainId, $recordId, $actor);
        }

        return $this->geoRoutes($domainId, $recordId);
    }

    private function normalize(array $domain, array $input, bool $allowStatus = false): array
    {
        $type = strtoupper(trim((string) ($input['type'] ?? '')));
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException('invalid_dns_record_type');
        }

        $name = $this->normalizeName((string) ($input['name'] ?? ''), (string) $domain['domain']);
        $content = $this->normalizeContent($type, (string) ($input['content'] ?? ''), (bool) ($input['proxied'] ?? false));
        $ttl = (int) ($input['ttl'] ?? 300);
        if ($ttl < 60 || $ttl > 86400) {
            throw new RuntimeException('invalid_dns_record_ttl');
        }

        $priority = $type === 'MX' || $type === 'SRV'
            ? (int) ($input['priority'] ?? 0)
            : null;
        if ($priority !== null && ($priority < 0 || $priority > 65535)) {
            throw new RuntimeException('invalid_dns_record_priority');
        }

        $status = $allowStatus ? (string) ($input['status'] ?? 'active') : 'active';
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new RuntimeException('invalid_dns_record_status');
        }

        return [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'ttl' => $ttl,
            'priority' => $priority,
            'proxied' => (bool) ($input['proxied'] ?? false),
            'origin_host' => strtolower(trim((string) ($input['origin_host'] ?? $content))),
            'status' => $status,
        ];
    }

    private function normalizeName(string $name, string $domain): string
    {
        $name = strtolower(rtrim(trim($name), '.'));
        $domain = strtolower(rtrim($domain, '.'));
        if ($name === '' || $name === '@' || $name === $domain) {
            return '@';
        }
        if (str_ends_with($name, '.' . $domain)) {
            $name = substr($name, 0, -strlen('.' . $domain));
        }
        if (!preg_match('/^(?:\*|[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:\*|[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $name)) {
            throw new RuntimeException('invalid_dns_record_name');
        }

        return $name;
    }

    private function normalizeContent(string $type, string $content, bool $proxied): string
    {
        $content = trim($content);
        if ($content === '') {
            throw new RuntimeException('invalid_dns_record_content');
        }
        if ($proxied && in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            return strtolower(rtrim($content, '.'));
        }
        if ($type === 'A' && filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('invalid_dns_record_content');
        }
        if ($type === 'AAAA' && filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new RuntimeException('invalid_dns_record_content');
        }
        if (in_array($type, ['CNAME', 'NS', 'MX'], true)) {
            return strtolower(rtrim($content, '.'));
        }

        return $content;
    }

    private function publicRecordFor(array $domain, array $record): array
    {
        if (!$record['proxied']) {
            return ['type' => $record['type'], 'content' => $record['content']];
        }

        return $this->isApex($record['name'], (string) $domain['domain'])
            ? ['type' => 'LUA', 'content' => 'managed edge pool']
            : ['type' => 'CNAME', 'content' => $this->siteTarget((string) $domain['id'])];
    }

    private function assertCompatible(string $domainId, ?string $recordId, array $record): void
    {
        if ($record['status'] !== 'active') {
            return;
        }

        $duplicate = DB::table('dns_records')
            ->where('domain_id', $domainId)
            ->where('status', 'active')
            ->whereRaw('UPPER(type) = ?', [$record['type']])
            ->whereRaw('LOWER(name) = ?', [strtolower($record['name'])])
            ->where('content', $record['content'])
            ->when($recordId !== null, fn ($query) => $query->where('id', '<>', $recordId))
            ->exists();
        if ($duplicate) {
            throw new RuntimeException('dns_record_duplicate');
        }

        $public = $this->publicRecordFor($this->domain($domainId) ?? [], $record);
        $conflicts = DB::table('dns_records')
            ->where('domain_id', $domainId)
            ->where('status', 'active')
            ->whereRaw('LOWER(name) = ?', [strtolower($record['name'])])
            ->when($recordId !== null, fn ($query) => $query->where('id', '<>', $recordId))
            ->get();

        foreach ($conflicts as $row) {
            $existingType = strtoupper((string) ($row->public_type ?: $row->type));
            $existingContent = trim((string) ($row->public_content ?: $row->content));
            $existingProxied = $this->bool($row->proxied);
            $newType = strtoupper((string) $public['type']);
            if ($record['proxied'] && $existingProxied && $newType === $existingType
                && trim((string) $public['content']) === $existingContent
                && in_array($newType, ['LUA', 'CNAME'], true)) {
                continue;
            }
            if ($newType === 'CNAME' || $existingType === 'CNAME' || ($newType === 'LUA' && $existingType === 'LUA')) {
                throw new RuntimeException('dns_record_name_conflict');
            }
        }
    }

    private function afterMutation(string $action, ?array $before, ?array $after, string $domainId, string $recordId, ?array $actor): void
    {
        $this->audit->write($action, 'dns_record', $recordId, $before, $after, 'admin', $actor['id'] ?? null, $domainId);
        $this->config->markDirty('dns.record.changed');
        $this->dnsReconcile->queueForDomain($domainId);
    }

    private function syncDnsOrigin(array $record): void
    {
        if (!$this->bool($record['proxied'] ?? false)) {
            DB::table('domain_origins')->where('dns_record_id', $record['id'])->delete();
            return;
        }

        $now = UnixTime::now();
        $host = strtolower(trim((string) ($record['origin_host'] ?: $record['origin_content'] ?: $record['content'])));
        $scheme = (string) ($record['origin_scheme'] ?: 'http');
        $existing = DB::table('domain_origins')->where('dns_record_id', $record['id'])->first();
        $values = [
            'domain_id' => $record['domain_id'],
            'dns_record_id' => $record['id'],
            'source' => 'dns_record',
            'role' => 'primary',
            'weight' => 1,
            'load_balancing_algorithm' => 'weighted_hash',
            'scheme' => $scheme,
            'host' => $host,
            'port' => $scheme === 'https' ? 443 : 80,
            'host_header' => $host,
            'sni' => $host,
            'tls_verify' => (string) ($record['origin_tls_verify'] ?: 'ignore'),
            'preserve_host' => true,
            'is_primary' => false,
            'health_check_enabled' => false,
            'health_check_path' => '/',
            'health_check_interval_seconds' => 30,
            'health_check_timeout_seconds' => 5,
            'connection_timeout_seconds' => 5,
            'response_timeout_seconds' => 30,
            'retry_attempts' => 1,
            'retry_budget_per_minute' => 60,
            'circuit_breaker_enabled' => true,
            'circuit_failure_threshold' => 5,
            'circuit_recovery_seconds' => 30,
            'max_concurrent_requests' => 0,
            'drain' => false,
            'shield_enabled' => false,
            'health_status' => 'unknown',
            'last_check_at' => null,
            'last_error' => null,
            'enabled' => true,
            'updated_at' => $now,
        ];

        if ($existing === null) {
            DB::table('domain_origins')->insert($values + [
                'id' => (string) Str::uuid(),
                'created_at' => $now,
            ]);
            return;
        }

        DB::table('domain_origins')->where('id', $existing->id)->update($values);
    }

    private function domain(string $domainId): ?array
    {
        $row = DB::table('domains')->where('id', $domainId)->first();

        return $row === null ? null : (array) $row;
    }

    private function cast(array $row, array $domain): array
    {
        $row['type'] = strtoupper((string) $row['type']);
        $row['name'] = (string) $row['name'];
        $row['content'] = (string) $row['content'];
        $row['ttl'] = (int) $row['ttl'];
        $row['priority'] = $row['priority'] === null ? null : (int) $row['priority'];
        $row['proxied'] = $this->bool($row['proxied']);
        $row['public_type'] = $row['public_type'] ?: $row['type'];
        $row['public_content'] = $row['public_content'] ?: $row['content'];
        $row['origin_type'] = $row['origin_type'] ?: $row['type'];
        $row['origin_content'] = $row['origin_content'] ?: $row['content'];
        $row['publication_status'] = 'queued';
        $row['geo_routes_count'] = (int) ($row['geo_routes_count'] ?? 0);
        $row['effective_status'] = $row['status'] === 'active'
            && ($domain['status'] ?? null) === 'active'
            && ($domain['nameserver_status'] ?? null) === 'verified'
            ? 'active'
            : 'disabled';
        $row['disabled_reason'] = $row['status'] !== 'active'
            ? 'record_disabled'
            : (($domain['nameserver_status'] ?? null) !== 'verified' ? 'nameservers_not_verified' : null);

        return $row;
    }

    private function platformNameserverRecords(array $domain): array
    {
        $now = UnixTime::now();

        return array_map(static function (string $nameserver) use ($domain, $now): array {
            return [
                'id' => 'platform-ns:'.rtrim($nameserver, '.'),
                'domain_id' => (string) $domain['id'],
                'type' => 'NS',
                'name' => '@',
                'content' => $nameserver,
                'ttl' => 300,
                'priority' => null,
                'proxied' => false,
                'geo_policy_id' => null,
                'origin_type' => 'NS',
                'origin_content' => $nameserver,
                'public_type' => 'NS',
                'public_content' => $nameserver,
                'origin_host' => null,
                'origin_tls_verify' => 'ignore',
                'origin_scheme' => null,
                'origin_status' => 'dns_only',
                'geo_origins_json' => null,
                'routing_policy' => 'standard',
                'managed_by' => 'platform_nameservers',
                'status' => 'active',
                'publication_status' => 'queued',
                'effective_status' => 'active',
                'disabled_reason' => null,
                'readonly' => true,
                'created_at' => (int) ($domain['created_at'] ?? $now),
                'updated_at' => (int) ($domain['updated_at'] ?? $now),
            ];
        }, $this->nameservers());
    }

    private function nameservers(): array
    {
        $raw = DB::table('platform_settings')->where('key', 'platform.nameservers')->value('value_json');
        $value = is_string($raw) ? json_decode($raw, true) : null;
        $hostnames = is_array($value) ? ($value['hostnames'] ?? $value) : ['ns1.cdnlite.test', 'ns2.cdnlite.test'];
        $nameservers = [];

        foreach ((array) $hostnames as $hostname) {
            $hostname = rtrim(strtolower(trim((string) $hostname)), '.');
            if ($hostname !== '') {
                $nameservers[] = $hostname.'.';
            }
        }

        return $nameservers === [] ? ['ns1.cdnlite.test.'] : array_values(array_unique($nameservers));
    }

    private function isApex(string $name, string $domain): bool
    {
        $name = strtolower(rtrim(trim($name), '.'));
        $domain = strtolower(rtrim(trim($domain), '.'));

        return $name === '' || $name === '@' || $name === $domain;
    }

    private function proxyHost(): string
    {
        return strtolower(rtrim((string) env('CDNLITE_CDN_PROXY_HOST', 'proxy.cdn.example.net'), '.'));
    }

    private function siteTarget(string $domainId): string
    {
        return 'site-'.$this->label($domainId).'.'.strtolower(rtrim((string) env('CDNLITE_CDN_ZONE', 'cdn.example.net'), '.')).'.';
    }

    private function label(string $value): string
    {
        $value = strtolower(preg_replace('/[^a-zA-Z0-9-]/', '', $value) ?? '');

        return trim($value, '-') ?: 'site';
    }

    private function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    private function normalizeGeoRoutes(array $routes, string $recordType): array
    {
        $seen = [];
        return array_map(function (array $route) use ($recordType, &$seen): array {
            $scope = (string) ($route['route_scope'] ?? '');
            $country = isset($route['country_code']) ? strtoupper((string) $route['country_code']) : null;
            $continent = isset($route['continent_code']) ? strtoupper((string) $route['continent_code']) : null;
            $answerType = strtoupper((string) ($route['answer_type'] ?? $recordType));
            $answer = trim((string) ($route['answer_value'] ?? ''));

            if (!in_array($scope, ['default', 'country', 'continent'], true)) {
                throw new RuntimeException('invalid_geo_route_scope');
            }
            if ($answerType !== $recordType || !in_array($answerType, ['A', 'AAAA'], true)) {
                throw new RuntimeException('invalid_geo_route_answer_type');
            }
            if ($answerType === 'A' && filter_var($answer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new RuntimeException('invalid_geo_route_answer');
            }
            if ($answerType === 'AAAA' && filter_var($answer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new RuntimeException('invalid_geo_route_answer');
            }
            if ($scope === 'country' && ($country === null || !preg_match('/^[A-Z]{2}$/', $country))) {
                throw new RuntimeException('invalid_geo_route_country');
            }
            if ($scope === 'continent' && ($continent === null || !in_array($continent, ['AF', 'AN', 'AS', 'EU', 'NA', 'OC', 'SA'], true))) {
                throw new RuntimeException('invalid_geo_route_continent');
            }

            $key = $scope.':'.($scope === 'country' ? $country : ($scope === 'continent' ? $continent : 'default'));
            if (isset($seen[$key])) {
                throw new RuntimeException('duplicate_geo_route');
            }
            $seen[$key] = true;

            return [
                'route_scope' => $scope,
                'country_code' => $scope === 'country' ? $country : null,
                'continent_code' => $scope === 'continent' ? $continent : null,
                'edge_node_id' => null,
                'edge_pool_id' => null,
                'answer_type' => $answerType,
                'answer_value' => $answer,
                'enabled' => (bool) ($route['enabled'] ?? true),
            ];
        }, $routes);
    }

    private function castGeoRoute(array $row): array
    {
        $row['priority'] = (int) $row['priority'];
        $row['weight'] = (int) $row['weight'];
        $row['enabled'] = $this->bool($row['enabled']);

        return $row;
    }
}
