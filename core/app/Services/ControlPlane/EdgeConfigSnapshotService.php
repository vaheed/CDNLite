<?php

namespace App\Services\ControlPlane;

use Illuminate\Support\Facades\DB;

final class EdgeConfigSnapshotService
{
    private const EMPTY_CONTENT_HASH = 'empty';

    public function publish(): array
    {
        DB::table('config_state')->insertOrIgnore(['id' => 1, 'version' => 0]);

        DB::table('config_state')->where('id', 1)->update(['publishing_started_at' => UnixTime::now()]);

        try {
            return DB::transaction(function (): array {
                $payload = $this->buildPayload($this->nextVersion());
                $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($encoded)) {
                    throw new \RuntimeException('config_snapshot_encode_failed');
                }
                $this->assertWithinSizeLimit($encoded);

                $contentHash = hash('sha256', $this->stableContent($payload));
                $existingVersion = DB::table('config_snapshots')->where('content_hash', $contentHash)->value('version');
                $version = $existingVersion === null ? (int) $payload['version'] : (int) $existingVersion;
                $payload['version'] = $version;
                $payload['content_hash'] = $contentHash;
                $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($encoded)) {
                    throw new \RuntimeException('config_snapshot_encode_failed');
                }
                $this->assertWithinSizeLimit($encoded);

                if ($existingVersion === null) {
                    DB::table('config_snapshots')->insert([
                        'version' => $version,
                        'content_hash' => $contentHash,
                        'payload_json' => $encoded,
                        'generated_at' => $payload['generated_at'],
                    ]);
                }

                DB::table('config_state')->where('id', 1)->update([
                    'version' => $version,
                    'active_snapshot_version' => $version,
                    'dirty' => false,
                    'dirty_at' => null,
                    'published_at' => UnixTime::now(),
                    'last_publish_error' => null,
                    'publishing_started_at' => null,
                ]);

                return [
                    'changed' => true,
                    'version' => $version,
                    'content_hash' => $contentHash,
                    'reused' => $existingVersion !== null,
                    'snapshot' => $payload,
                ];
            });
        } catch (\Throwable $error) {
            DB::table('config_state')->where('id', 1)->update([
                'last_publish_error' => $error->getMessage(),
                'publishing_started_at' => null,
            ]);

            throw $error;
        }
    }

    public function edgeResponse(?int $ifVersion, string $edgeId): array
    {
        $snapshot = $this->activeSnapshot();
        $version = (int) $snapshot['version'];

        if ($ifVersion !== null && $ifVersion > 0 && $ifVersion === $version) {
            $this->recordConfigPull($edgeId, $version);

            return ['not_modified' => true, 'version' => $version];
        }

        $this->recordConfigPull($edgeId, $version);

        return $snapshot;
    }

    public function status(): array
    {
        DB::table('config_state')->insertOrIgnore(['id' => 1, 'version' => 0]);
        $state = (array) DB::table('config_state')->where('id', 1)->first();
        $activeVersion = $state['active_snapshot_version'] ?? null;
        $activeBytes = null;
        if ($activeVersion !== null) {
            $payload = DB::table('config_snapshots')->where('version', (int) $activeVersion)->value('payload_json');
            $activeBytes = is_string($payload) ? strlen($payload) : null;
        }

        return [
            'version' => (int) ($state['version'] ?? 0),
            'active_snapshot_version' => $state['active_snapshot_version'] === null ? null : (int) $state['active_snapshot_version'],
            'dirty' => (bool) ($state['dirty'] ?? true),
            'dirty_at' => $state['dirty_at'] === null ? null : (int) $state['dirty_at'],
            'published_at' => $state['published_at'] === null ? null : (int) $state['published_at'],
            'last_publish_error' => $state['last_publish_error'] ?? null,
            'publishing_started_at' => $state['publishing_started_at'] === null ? null : (int) $state['publishing_started_at'],
            'active_snapshot_bytes' => $activeBytes,
            'max_snapshot_bytes' => $this->maxSnapshotBytes(),
        ];
    }

    private function activeSnapshot(): array
    {
        $activeVersion = DB::table('config_state')->where('id', 1)->value('active_snapshot_version');
        if ($activeVersion !== null) {
            $row = DB::table('config_snapshots')->where('version', (int) $activeVersion)->first();
            if ($row !== null) {
                $payload = json_decode((string) $row->payload_json, true);
                if (is_array($payload)) {
                    return $payload;
                }
            }
        }

        return $this->emptySnapshot();
    }

    private function buildPayload(int $version): array
    {
        $domains = DB::table('domains')
            ->where('status', 'active')
            ->where('nameserver_status', 'verified')
            ->orderBy('domain')
            ->get();
        $origins = DB::table('domain_origins')->where('enabled', true)->where('drain', false)->get()->groupBy('domain_id');
        $records = DB::table('dns_records')->where('status', 'active')->get()->groupBy('domain_id');

        $hosts = [];
        foreach ($domains as $domain) {
            $domainName = strtolower((string) $domain->domain);
            $domainRecords = ($records[$domain->id] ?? collect())->values()->map(fn ($record) => (array) $record)->all();
            $proxiedRecords = array_values(array_filter($domainRecords, static fn (array $record): bool => (bool) ($record['proxied'] ?? false)));
            if ($proxiedRecords === []) {
                continue;
            }

            $configuredOrigins = ($origins[$domain->id] ?? collect())->values()->map(fn ($origin) => (array) $origin)->all();
            $baseOrigins = $this->originsForSnapshot($configuredOrigins);
            if ($baseOrigins === []) {
                $baseOrigins = $this->originsFromDnsRecords($proxiedRecords, $domainName);
            }
            if ($baseOrigins === []) {
                continue;
            }

            $baseConfig = $this->hostPayload($domain, $domainName, $baseOrigins, $domainRecords);
            foreach ($this->proxiedRecordHosts($domainName, $proxiedRecords, $configuredOrigins) as $host => $hostOrigins) {
                $config = $baseConfig;
                if ($hostOrigins !== []) {
                    $config['origin'] = $hostOrigins[0];
                    $config['origins'] = $hostOrigins;
                }
                $hosts[$host] = $config;
            }
        }
        ksort($hosts);

        $redirects = $this->hostedRows('redirect_rules', $hosts);
        $rateLimits = $this->hostedRows('rate_limit_rules', $hosts);
        $wafRules = $this->hostedRows('waf_rules', $hosts);
        $headerRules = $this->hostedRows('domain_header_rules', $hosts);
        $ipRules = $this->hostedRows('domain_ip_rules', $hosts);
        $cacheRules = $this->hostedRows('cache_rules', $hosts);
        $cachePurgeVersions = $this->hostedRows('cache_purge_versions', $hosts, false);
        $pageRules = $this->hostedRows('page_rules', $hosts);
        $sslCertificates = $this->sslCertificates($hosts);

        return [
            'version' => $version,
            'generated_at' => UnixTime::now(),
            'schema' => 'edge-config.v1',
            'schema_version' => 1,
            'hosts' => (object) $hosts,
            'redirects' => $redirects,
            'rate_limits' => $rateLimits,
            'waf_rules' => $wafRules,
            'header_rules' => $headerRules,
            'ip_rules' => $ipRules,
            'cache_rules' => $cacheRules,
            'cache_purge_versions' => $cachePurgeVersions,
            'page_rules' => $pageRules,
            'ssl_certificates' => $sslCertificates,
            'defaults' => [
                'telemetry' => ['enabled' => true],
                'cache' => ['enabled' => true],
            ],
        ];
    }

    private function emptySnapshot(): array
    {
        return [
            'version' => 0,
            'generated_at' => UnixTime::now(),
            'schema' => 'edge-config.v1',
            'schema_version' => 1,
            'content_hash' => self::EMPTY_CONTENT_HASH,
            'hosts' => (object) [],
            'redirects' => [],
            'rate_limits' => [],
            'waf_rules' => [],
            'header_rules' => [],
            'ip_rules' => [],
            'cache_rules' => [],
            'cache_purge_versions' => [],
            'page_rules' => [],
            'ssl_certificates' => [],
            'defaults' => [
                'telemetry' => ['enabled' => true],
                'cache' => ['enabled' => true],
            ],
        ];
    }

    private function hostPayload(object $domain, string $domainName, array $origins, array $records): array
    {
        return [
            'domain_id' => (string) $domain->id,
            'domain' => $domainName,
            'origin' => $origins[0],
            'origins' => $origins,
            'geo_origins' => $this->geoOrigins($records),
            'dns_records' => array_values(array_map(fn (array $record): array => $this->recordPayload($record), $records)),
            'cache' => $this->cacheSettings((string) $domain->id),
            'headers' => ['X-CDNLITE-Domain' => (string) $domain->id],
            'header_rules' => [],
            'ip_rules' => [],
            'waiting_room' => $this->waitingRoom((string) $domain->id),
            'ssl' => $this->sslSettings((string) $domain->id),
            'verified_bot_sources' => $this->verifiedBotSources((string) $domain->id),
            'telemetry' => ['enabled' => true],
        ];
    }

    private function originPayload(array $origin): array
    {
        return [
            'id' => (string) $origin['id'],
            'dns_record_id' => $origin['dns_record_id'] ?? null,
            'source' => (string) ($origin['source'] ?? 'manual'),
            'role' => (string) ($origin['role'] ?? 'primary'),
            'weight' => (int) ($origin['weight'] ?? 1),
            'load_balancing_algorithm' => (string) ($origin['load_balancing_algorithm'] ?? 'weighted_hash'),
            'enabled' => (bool) ($origin['enabled'] ?? true),
            'scheme' => (string) $origin['scheme'],
            'host' => (string) $origin['host'],
            'port' => (int) $origin['port'],
            'host_header' => (string) ($origin['host_header'] ?? $origin['host']),
            'sni' => (string) ($origin['sni'] ?? ''),
            'tls_verify' => (string) ($origin['tls_verify'] ?? 'ignore'),
            'preserve_host' => (bool) ($origin['preserve_host'] ?? true),
            'health_check_enabled' => (bool) ($origin['health_check_enabled'] ?? false),
            'health_check_path' => (string) ($origin['health_check_path'] ?? '/'),
            'health_check_interval_seconds' => (int) ($origin['health_check_interval_seconds'] ?? 30),
            'health_check_timeout_seconds' => (int) ($origin['health_check_timeout_seconds'] ?? 5),
            'connection_timeout_seconds' => (int) ($origin['connection_timeout_seconds'] ?? 5),
            'response_timeout_seconds' => (int) ($origin['response_timeout_seconds'] ?? 30),
            'retry_attempts' => (int) ($origin['retry_attempts'] ?? 1),
            'retry_budget_per_minute' => (int) ($origin['retry_budget_per_minute'] ?? 60),
            'circuit_breaker_enabled' => (bool) ($origin['circuit_breaker_enabled'] ?? true),
            'circuit_failure_threshold' => (int) ($origin['circuit_failure_threshold'] ?? 5),
            'circuit_recovery_seconds' => (int) ($origin['circuit_recovery_seconds'] ?? 30),
            'max_concurrent_requests' => (int) ($origin['max_concurrent_requests'] ?? 0),
            'drain' => (bool) ($origin['drain'] ?? false),
            'shield_enabled' => (bool) ($origin['shield_enabled'] ?? false),
            'health_status' => (string) ($origin['health_status'] ?? 'unknown'),
            'status' => (string) ($origin['health_status'] ?? 'unknown'),
        ];
    }

    private function originsForSnapshot(array $origins): array
    {
        $out = [];
        foreach ($origins as $origin) {
            if (!(bool) ($origin['enabled'] ?? true) || (bool) ($origin['drain'] ?? false)) {
                continue;
            }
            if ((bool) ($origin['health_check_enabled'] ?? false) && (string) ($origin['health_status'] ?? 'unknown') === 'unhealthy') {
                continue;
            }
            $out[] = $this->originPayload($origin);
        }

        return $this->sortOrigins($out);
    }

    private function originsFromDnsRecords(array $records, string $domainName): array
    {
        $origins = [];
        foreach ($records as $record) {
            $origin = $this->originFromDnsRecord($record, $domainName);
            if ($origin !== null) {
                $origins[] = $origin;
            }
        }

        return $this->sortOrigins($origins);
    }

    private function proxiedRecordHosts(string $domainName, array $records, array $configuredOrigins): array
    {
        $hosts = [];
        foreach ($records as $record) {
            $host = $this->recordHost($domainName, (string) ($record['name'] ?? ''));
            if ($host === null) {
                continue;
            }

            $recordOrigins = array_values(array_map(
                fn (array $origin): array => $this->originPayload($origin),
                array_filter($configuredOrigins, static fn (array $origin): bool => (string) ($origin['dns_record_id'] ?? '') === (string) ($record['id'] ?? ''))
            ));
            if ($recordOrigins === []) {
                $origin = $this->originFromDnsRecord($record, $domainName);
                $recordOrigins = $origin === null ? [] : [$origin];
            }
            $hosts[$host] = $this->sortOrigins($recordOrigins);
        }

        return $hosts;
    }

    private function originFromDnsRecord(array $record, string $domainName): ?array
    {
        $host = trim((string) ($record['origin_host'] ?? $record['origin_content'] ?? $record['content'] ?? ''));
        if ($host === '') {
            return null;
        }
        $scheme = (string) ($record['origin_scheme'] ?? 'http');
        $requestedHost = $this->recordHost($domainName, (string) ($record['name'] ?? '')) ?? $host;

        return [
            'id' => (string) ($record['id'] ?? ''),
            'dns_record_id' => (string) ($record['id'] ?? ''),
            'source' => 'dns_record',
            'role' => 'primary',
            'weight' => 1,
            'load_balancing_algorithm' => 'weighted_hash',
            'enabled' => true,
            'scheme' => $scheme,
            'host' => $host,
            'port' => $scheme === 'https' ? 443 : 80,
            'host_header' => $requestedHost,
            'sni' => $requestedHost,
            'tls_verify' => (string) ($record['origin_tls_verify'] ?? 'ignore'),
            'preserve_host' => true,
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
            'health_status' => (string) ($record['origin_status'] ?? 'unknown'),
            'status' => (string) ($record['origin_status'] ?? 'unknown'),
        ];
    }

    private function sortOrigins(array $origins): array
    {
        usort($origins, static function (array $a, array $b): int {
            $health = ['healthy' => 0, 'unknown' => 1, 'pending' => 1, 'unhealthy' => 2];

            return [$health[$a['health_status']] ?? 1, $a['weight'] ?? 1, $a['id'] ?? '']
                <=> [$health[$b['health_status']] ?? 1, $b['weight'] ?? 1, $b['id'] ?? ''];
        });

        return $origins;
    }

    private function recordHost(string $domainName, string $name): ?string
    {
        $domainName = strtolower(rtrim(trim($domainName), '.'));
        $name = strtolower(rtrim(trim($name), '.'));
        if ($domainName === '') {
            return null;
        }
        if ($name === '' || $name === '@') {
            return $domainName;
        }
        if ($name === $domainName || str_ends_with($name, '.'.$domainName)) {
            return $name;
        }

        return $name.'.'.$domainName;
    }

    private function recordPayload(array $record): array
    {
        return [
            'id' => (string) $record['id'],
            'type' => (string) $record['type'],
            'name' => (string) $record['name'],
            'content' => (string) $record['content'],
            'ttl' => (int) $record['ttl'],
            'proxied' => (bool) $record['proxied'],
            'public_type' => $record['public_type'] ?? null,
            'public_content' => $record['public_content'] ?? null,
            'routing_policy' => (string) $record['routing_policy'],
            'origin_host' => $record['origin_host'] ?? null,
        ];
    }

    private function cacheSettings(string $domainId): array
    {
        $row = DB::table('domain_cache_settings')->where('domain_id', $domainId)->first();
        if ($row === null) {
            return [
                'enabled' => true,
                'default_edge_ttl_seconds' => 3600,
                'default_browser_ttl_seconds' => null,
                'cache_query_string_mode' => 'include_all',
                'respect_origin_cache_control' => true,
                'cache_authorized_requests' => false,
                'stale_if_error_seconds' => 86400,
                'static_asset_cache_enabled' => false,
                'ignore_query_strings_for_static' => false,
                'bypass_logged_in_users' => true,
                'cache_methods' => ['GET', 'HEAD'],
                'cache_status_code_policy' => ['200' => true, '301' => true, '302' => true, '404' => false, '500' => false, '502' => false, '503' => false, '504' => false],
                'bypass_headers' => ['authorization'],
                'bypass_cookies' => ['session', 'auth', 'wordpress_logged_in', 'laravel_session'],
                'vary_headers' => ['accept-encoding'],
                'cache_key_dimensions' => ['scheme' => true, 'host' => true, 'path' => true, 'query' => 'include_all', 'headers' => ['accept-encoding'], 'device' => false, 'country' => false, 'language' => false, 'domain_id' => true, 'rule_version' => true],
                'debug_headers_enabled' => false,
                'stale_while_revalidate_seconds' => 0,
                'negative_ttl_seconds' => 0,
                'max_object_size_bytes' => 104857600,
            ];
        }

        return $this->castRow((array) $row);
    }

    private function sslSettings(string $domainId): array
    {
        $row = DB::table('domain_ssl_settings')->where('domain_id', $domainId)->first();
        if ($row === null) {
            return ['force_https' => false, 'min_tls_version' => '1.2', 'auto_renew' => true, 'mode' => 'off'];
        }
        $settings = $this->castRow((array) $row);
        $settings['mode'] = !empty($settings['force_https']) ? 'full' : 'off';

        return $settings;
    }

    private function waitingRoom(string $domainId): array
    {
        $row = DB::table('waiting_room_policies')->where('domain_id', $domainId)->first();
        if ($row === null) {
            return ['enabled' => false];
        }

        return $this->castRow((array) $row);
    }

    private function verifiedBotSources(string $domainId): array
    {
        return DB::table('verified_bot_sources')
            ->where('domain_id', $domainId)
            ->where('enabled', true)
            ->orderBy('provider')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => $this->castRow((array) $row))
            ->all();
    }

    private function hostedRows(string $table, array &$hosts, bool $enabledOnly = true): array
    {
        if ($hosts === []) {
            return [];
        }

        $domainHosts = [];
        foreach ($hosts as $host => $config) {
            $domainHosts[(string) $config['domain_id']][] = (string) $host;
        }

        $query = DB::table($table)->whereIn('domain_id', array_keys($domainHosts));
        if ($enabledOnly) {
            $query->where('enabled', true);
        }
        $rows = $query->orderBy('domain_id')->orderBy('id')->get();
        $out = [];

        foreach ($rows as $row) {
            $payload = $this->castRow((array) $row);
            $domainId = (string) $payload['domain_id'];
            foreach ($domainHosts[$domainId] ?? [] as $host) {
                $hostPayload = $payload;
                $hostPayload['host'] = $host;
                $out[] = $hostPayload;
                if ($table === 'domain_header_rules') {
                    $hosts[$host]['header_rules'][] = $hostPayload;
                }
                if ($table === 'domain_ip_rules') {
                    $hosts[$host]['ip_rules'][] = $hostPayload;
                }
            }
        }

        return $out;
    }

    private function sslCertificates(array $hosts): array
    {
        if ($hosts === []) {
            return [];
        }
        $domainIds = array_values(array_unique(array_map(static fn (array $host): string => (string) $host['domain_id'], $hosts)));

        return DB::table('ssl_certificates')
            ->whereIn('domain_id', $domainIds)
            ->where('status', 'active')
            ->whereNotNull('certificate_pem')
            ->whereNotNull('private_key_pem')
            ->orderBy('hostname')
            ->get()
            ->map(fn ($row) => $this->castRow((array) $row))
            ->all();
    }

    private function geoOrigins(array $records): array
    {
        foreach ($records as $record) {
            $decoded = $this->decodeJson($record['geo_origins_json'] ?? null, []);
            if ($decoded !== []) {
                return $decoded;
            }
        }

        return [];
    }

    private function castRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (str_ends_with((string) $key, '_json')) {
                $outKey = substr((string) $key, 0, -5);
                $row[$outKey] = $this->decodeJson($value, []);
                unset($row[$key]);
                continue;
            }
            if (is_bool($value) || $value === null) {
                continue;
            }
            if (is_int($value) || is_float($value)) {
                continue;
            }
            if (in_array($value, ['t', 'true', '1'], true)) {
                $row[$key] = true;
            } elseif (in_array($value, ['f', 'false', '0'], true)) {
                $row[$key] = false;
            }
        }

        return $row;
    }

    private function decodeJson(mixed $value, mixed $fallback): mixed
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $fallback;
    }

    private function nextVersion(): int
    {
        $current = (int) DB::table('config_state')->where('id', 1)->value('version');

        return max(1, $current + 1);
    }

    private function assertWithinSizeLimit(string $encoded): void
    {
        $bytes = strlen($encoded);
        $max = $this->maxSnapshotBytes();
        if ($max > 0 && $bytes > $max) {
            throw new \RuntimeException("config_snapshot_too_large:{$bytes}:{$max}");
        }
    }

    private function maxSnapshotBytes(): int
    {
        return max(0, (int) config('cdnlite.edge.config_max_bytes', 1048576));
    }

    private function stableContent(array $payload): string
    {
        unset($payload['version'], $payload['generated_at'], $payload['content_hash']);

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function recordConfigPull(string $edgeId, int $version): void
    {
        DB::table('edge_nodes')->where('edge_id', $edgeId)->update([
            'last_config_pull_at' => UnixTime::now(),
            'applied_config_version' => $version,
            'updated_at' => UnixTime::now(),
        ]);
    }
}
