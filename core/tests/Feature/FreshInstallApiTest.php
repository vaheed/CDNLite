<?php

namespace Tests\Feature;

use App\Services\ControlPlane\UnixTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FreshInstallApiTest extends TestCase
{
    public function test_admin_can_create_domain_origin_and_dns_record_through_laravel_routes(): void
    {
        $token = $this->adminToken();

        $domain = $this->withToken($token)
            ->postJson('/api/v1/domains', ['domain' => 'example.test'])
            ->assertCreated()
            ->json('data');

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/origins", ['host' => 'origin.example.test', 'scheme' => 'https'])
            ->assertCreated()
            ->assertJsonPath('data.host', 'origin.example.test');

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/dns/records", [
                'type' => 'CNAME',
                'name' => 'www',
                'content' => 'edge.cdnlite.test',
                'proxied' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'CNAME');
    }

    public function test_dns_record_lifecycle_is_laravel_native_and_records_side_effects(): void
    {
        $token = $this->adminToken();
        $this->enablePowerDnsQueue();
        DB::table('config_state')->where('id', 1)->update(['dirty' => false, 'dirty_at' => null]);

        $domain = $this->withToken($token)
            ->postJson('/api/v1/domains', ['domain' => 'dns-lifecycle.example'])
            ->assertCreated()
            ->json('data');

        $record = $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/dns/records", [
                'type' => 'A',
                'name' => '@',
                'content' => 'origin.dns-lifecycle.example',
                'proxied' => true,
                'ttl' => 600,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', '@')
            ->assertJsonPath('data.public_type', 'LUA')
            ->assertJsonPath('data.public_content', 'managed edge pool')
            ->assertJsonPath('data.publication_status', 'queued')
            ->json('data');

        $this->assertDatabaseHas('audit_log', ['action' => 'dns.record.create', 'domain_id' => $domain['id']]);
        $this->assertDatabaseHas('audit_log', ['action' => 'dns.reconcile.queued', 'domain_id' => $domain['id']]);
        $this->assertDatabaseHas('config_state', ['id' => 1, 'dirty' => true]);

        $this->withToken($token)
            ->getJson("/api/v1/domains/{$domain['id']}/dns/records/{$record['id']}")
            ->assertOk()
            ->assertJsonPath('data.id', $record['id']);

        $this->withToken($token)
            ->getJson("/api/v1/domains/{$domain['id']}/dns/status")
            ->assertOk()
            ->assertJsonPath('data.zone_name', 'dns-lifecycle.example')
            ->assertJsonPath('data.status', 'pending');

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/dns/records/{$record['id']}/reconcile")
            ->assertOk()
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.record.id', $record['id']);

        $this->withToken($token)
            ->patchJson("/api/v1/domains/{$domain['id']}/dns/records/{$record['id']}", [
                'name' => 'www',
                'content' => 'origin2.dns-lifecycle.example',
                'ttl' => 120,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'www')
            ->assertJsonPath('data.public_type', 'CNAME');

        $this->assertDatabaseHas('audit_log', ['action' => 'dns.record.update', 'domain_id' => $domain['id']]);

        $this->withToken($token)
            ->deleteJson("/api/v1/domains/{$domain['id']}/dns/records/{$record['id']}")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('dns_records', ['id' => $record['id']]);
        $this->assertDatabaseHas('audit_log', ['action' => 'dns.record.delete', 'domain_id' => $domain['id']]);
    }

    public function test_dns_record_duplicate_and_name_conflicts_return_stable_errors(): void
    {
        $token = $this->adminToken();
        $domain = $this->withToken($token)
            ->postJson('/api/v1/domains', ['domain' => 'dns-conflict.example'])
            ->assertCreated()
            ->json('data');

        $payload = [
            'type' => 'TXT',
            'name' => 'verify',
            'content' => 'same-token',
            'proxied' => false,
        ];

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/dns/records", $payload)
            ->assertCreated();

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/dns/records", $payload)
            ->assertStatus(409)
            ->assertJson(['error' => 'dns_record_duplicate']);

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/dns/records", [
                'type' => 'CNAME',
                'name' => 'verify',
                'content' => 'target.example',
                'proxied' => false,
            ])
            ->assertStatus(409)
            ->assertJson(['error' => 'dns_record_name_conflict']);
    }

    public function test_dns_operations_build_and_persist_laravel_desired_state(): void
    {
        $token = $this->adminToken();

        $domain = $this->withToken($token)
            ->postJson('/api/v1/domains', ['domain' => 'desired.example'])
            ->assertCreated()
            ->json('data');

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/nameservers/force-verify", ['reason' => 'feature test'])
            ->assertOk();

        $apex = $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/dns/records", [
                'type' => 'A',
                'name' => '@',
                'content' => 'origin.desired.example',
                'proxied' => true,
                'ttl' => 300,
            ])
            ->assertCreated()
            ->json('data');

        $www = $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/dns/records", [
                'type' => 'CNAME',
                'name' => 'www',
                'content' => 'origin.desired.example',
                'proxied' => true,
                'ttl' => 300,
            ])
            ->assertCreated()
            ->json('data');

        $geo = $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/dns/records", [
                'type' => 'A',
                'name' => 'geo',
                'content' => '198.51.100.10',
                'proxied' => false,
                'ttl' => 120,
            ])
            ->assertCreated()
            ->json('data');

        $inactiveDomain = $this->withToken($token)
            ->postJson('/api/v1/domains', ['domain' => 'not-delegated.example'])
            ->assertCreated()
            ->json('data');

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$inactiveDomain['id']}/dns/records", [
                'type' => 'A',
                'name' => '@',
                'content' => '203.0.113.50',
                'proxied' => false,
            ])
            ->assertCreated();

        $now = UnixTime::now();
        DB::table('edge_nodes')->insert([
            'id' => (string) Str::uuid(),
            'edge_id' => 'edge-dns-desired-1',
            'hostname' => 'edge-dns-desired-1.local',
            'public_ip' => '203.0.113.10',
            'public_ipv4' => '203.0.113.10',
            'public_ipv6' => null,
            'region' => 'iad',
            'country' => 'US',
            'continent' => 'NA',
            'latitude' => null,
            'longitude' => null,
            'version' => 'test',
            'status' => 'online',
            'is_enabled' => true,
            'last_heartbeat' => $now,
            'last_heartbeat_at' => $now,
            'health_status' => 'healthy',
            'applied_config_version' => null,
            'last_config_pull_at' => null,
            'config_apply_error' => null,
            'weight' => 100,
            'priority' => 100,
            'geo_enabled' => true,
            'anycast_enabled' => false,
            'proxy_enabled' => true,
            'dns_enabled' => true,
            'cache_enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('dns_record_geo_routes')->insert([
            [
                'id' => (string) Str::uuid(),
                'dns_record_id' => $geo['id'],
                'route_scope' => 'default',
                'country_code' => null,
                'continent_code' => null,
                'edge_node_id' => null,
                'edge_pool_id' => null,
                'answer_type' => 'A',
                'answer_value' => '198.51.100.10',
                'priority' => 0,
                'weight' => 100,
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => (string) Str::uuid(),
                'dns_record_id' => $geo['id'],
                'route_scope' => 'country',
                'country_code' => 'US',
                'continent_code' => null,
                'edge_node_id' => null,
                'edge_pool_id' => null,
                'answer_type' => 'A',
                'answer_value' => '198.51.100.11',
                'priority' => 10,
                'weight' => 100,
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/dns/dry-run')
            ->assertOk()
            ->assertJsonPath('mode', 'dry_run')
            ->assertJsonFragment(['zone_name' => 'desired.example.'])
            ->assertJsonFragment(['rrset_type' => 'LUA'])
            ->assertJsonFragment(['source' => 'dns_record:'.$apex['id'].':apex_a']);

        DB::table('platform_settings')->upsert([[
            'key' => 'platform.edge_dns.anycast_ipv4',
            'group_name' => 'platform.edge_dns',
            'value_json' => json_encode(['198.51.100.200'], JSON_UNESCAPED_SLASHES),
            'is_secret' => false,
            'updated_at' => $now,
        ]], ['key'], ['value_json', 'updated_at']);

        $this->withToken($token)
            ->postJson('/api/v1/dns/force-sync')
            ->assertOk()
            ->assertJsonPath('data.mode', 'desired_state_persisted')
            ->assertJsonPath('data.ok', true);

        $this->withToken($token)
            ->getJson('/api/v1/dns/operations')
            ->assertOk()
            ->assertJsonPath('data.setup.apex_proxy_mode', 'LUA')
            ->assertJsonPath('data.setup.static_anycast.ipv4.0', '198.51.100.200')
            ->assertJsonPath('data.dnsgeo.alias_expansion', false)
            ->assertJsonFragment(['zone_name' => 'desired.example.']);

        $this->withToken($token)
            ->getJson('/api/v1/dns/zones')
            ->assertOk()
            ->assertJsonFragment(['zone_name' => 'desired.example.', 'status' => 'unknown']);

        $this->assertDatabaseHas('desired_dns_rrsets', [
            'zone_name' => 'desired.example.',
            'rrset_name' => 'desired.example.',
            'rrset_type' => 'A',
            'source' => 'dns_record:'.$apex['id'].':apex_a',
        ]);
        $this->assertDatabaseHas('desired_dns_rrsets', [
            'zone_name' => 'desired.example.',
            'rrset_name' => 'www.desired.example.',
            'rrset_type' => 'CNAME',
            'source' => 'dns_record:'.$www['id'],
        ]);
        $this->assertDatabaseHas('desired_dns_rrsets', [
            'zone_name' => 'desired.example.',
            'rrset_name' => 'geo.desired.example.',
            'rrset_type' => 'LUA',
            'source' => 'dns_record:'.$geo['id'].':raw_geodns',
        ]);
        $this->assertDatabaseHas('dns_sync_state', [
            'zone_name' => 'desired.example.',
            'status' => 'unknown',
            'in_progress' => false,
        ]);
        $this->assertDatabaseMissing('desired_dns_rrsets', [
            'zone_name' => 'not-delegated.example.',
            'rrset_type' => 'A',
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/dns/desired?zone=desired.example.')
            ->assertOk()
            ->assertJsonFragment(['rrset_name' => 'www.desired.example.'])
            ->assertJsonFragment(['records' => ['proxy.cdn.example.net.']])
            ->assertJsonFragment(['records' => ['198.51.100.200']]);
    }

    public function test_dns_force_sync_writes_laravel_desired_state_to_powerdns(): void
    {
        $token = $this->adminToken();
        $this->configurePowerDns([
            'platform.powerdns.verify_after_write' => false,
        ]);

        $requests = [];
        $zoneExists = false;
        $rrsets = [];
        Http::fake(function ($request) use (&$requests, &$zoneExists, &$rrsets) {
            $requests[] = ['method' => $request->method(), 'url' => (string) $request->url(), 'body' => $request->data()];

            if ($request->method() === 'GET' && str_contains((string) $request->url(), '/zones/')) {
                return $zoneExists
                    ? Http::response(['id' => 'pdns-sync.example.', 'name' => 'pdns-sync.example.', 'rrsets' => array_values($rrsets)], 200)
                    : Http::response(['error' => 'not found'], 404);
            }
            if ($request->method() === 'POST' && str_ends_with((string) $request->url(), '/zones')) {
                $zoneExists = true;
                return Http::response(['id' => 'created'], 201);
            }
            if ($request->method() === 'PATCH') {
                foreach ((array) ($request->data()['rrsets'] ?? []) as $rrset) {
                    $key = strtolower((string) ($rrset['name'] ?? '')).'|'.strtoupper((string) ($rrset['type'] ?? ''));
                    if (($rrset['changetype'] ?? 'REPLACE') === 'DELETE') {
                        unset($rrsets[$key]);
                        continue;
                    }
                    $rrsets[$key] = $rrset;
                }
                return Http::response(null, 204);
            }

            return Http::response(['id' => 'localhost'], 200);
        });

        $domain = $this->withToken($token)
            ->postJson('/api/v1/domains', ['domain' => 'pdns-sync.example'])
            ->assertCreated()
            ->json('data');

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/nameservers/force-verify", ['reason' => 'feature test'])
            ->assertOk();

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/dns/records", [
                'type' => 'CNAME',
                'name' => 'www',
                'content' => 'origin.pdns-sync.example',
                'proxied' => true,
            ])
            ->assertCreated();

        $this->withToken($token)
            ->postJson('/api/v1/dns/force-sync')
            ->assertOk()
            ->assertJsonPath('data.mode', 'powerdns_reconciled')
            ->assertJsonPath('data.ok', true);

        $this->assertTrue(collect($requests)->contains(
            fn (array $request): bool => $request['method'] === 'POST' && str_ends_with($request['url'], '/zones')
        ));
        $this->assertTrue(collect($requests)->contains(
            fn (array $request): bool => $request['method'] === 'PATCH'
                && collect($request['body']['rrsets'] ?? [])->contains(fn (array $rrset): bool => ($rrset['type'] ?? null) === 'CNAME')
        ));
        $this->assertTrue(collect($requests)->contains(
            fn (array $request): bool => $request['method'] === 'PATCH'
                && collect($request['body']['rrsets'] ?? [])->contains(fn (array $rrset): bool => ($rrset['type'] ?? null) === 'SOA')
        ));
        $this->assertDatabaseHas('dns_sync_state', [
            'zone_name' => 'pdns-sync.example.',
            'status' => 'ok',
            'pending_changes' => 0,
            'in_progress' => false,
        ]);
        $this->assertDatabaseHas('dns_sync_events', [
            'zone_name' => 'pdns-sync.example.',
            'action' => 'patch_rrsets',
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('powerdns_zone_serials', [
            'zone_name' => 'pdns-sync.example.',
        ]);
    }

    public function test_dns_actual_zone_endpoint_reads_powerdns_through_laravel(): void
    {
        $token = $this->adminToken();
        $this->configurePowerDns();

        Http::fake([
            'http://powerdns.test/api/v1/servers/localhost/zones/actual.example.' => Http::response([
                'id' => 'actual.example.',
                'name' => 'actual.example.',
                'rrsets' => [
                    ['name' => 'actual.example.', 'type' => 'NS', 'ttl' => 300, 'records' => [['content' => 'ns1.cdnlite.test.', 'disabled' => false]]],
                ],
            ], 200),
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/dns/zones/actual.example./actual')
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.zone.name', 'actual.example.');
    }

    public function test_domain_lifecycle_crud_writes_audit_and_marks_config_dirty(): void
    {
        $token = $this->adminToken();

        DB::table('config_state')->where('id', 1)->update(['dirty' => false, 'dirty_at' => null]);

        $domain = $this->withToken($token)
            ->postJson('/api/v1/domains', [
                'domain' => 'lifecycle.example',
                'name' => 'Lifecycle',
                'origin_shield_header_name' => 'X-Origin-Secret',
                'origin_shield_secret' => 'secret-value',
            ])
            ->assertCreated()
            ->assertJsonPath('data.domain', 'lifecycle.example')
            ->assertJsonPath('data.status', 'pending_nameserver')
            ->assertJsonPath('data.origin_shield_header_name', 'X-Origin-Secret')
            ->json('data');

        $this->assertCount(2, $domain['nameservers']);
        $this->assertDatabaseHas('config_state', ['id' => 1, 'dirty' => true]);
        $this->assertDatabaseHas('audit_log', ['action' => 'domain.create', 'domain_id' => $domain['id']]);

        $this->withToken($token)
            ->getJson('/api/v1/domains')
            ->assertOk()
            ->assertJsonFragment(['domain' => 'lifecycle.example']);

        $this->withToken($token)
            ->patchJson("/api/v1/domains/{$domain['id']}", ['name' => 'Renamed lifecycle'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed lifecycle');

        $this->assertDatabaseHas('audit_log', ['action' => 'domain.update', 'domain_id' => $domain['id']]);

        $this->withToken($token)
            ->deleteJson("/api/v1/domains/{$domain['id']}")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('domains', ['id' => $domain['id']]);
        $this->assertDatabaseHas('audit_log', ['action' => 'domain.delete', 'domain_id' => $domain['id']]);
    }

    public function test_duplicate_domains_are_rejected_without_legacy_aliases(): void
    {
        $token = $this->adminToken();

        $this->withToken($token)
            ->postJson('/api/v1/domains', ['domain' => 'duplicate.example'])
            ->assertCreated();

        $this->withToken($token)
            ->postJson('/api/v1/domains', ['domain' => 'DUPLICATE.example'])
            ->assertStatus(422)
            ->assertJson(['error' => 'domain_already_exists']);

        $this->withToken($token)
            ->postJson('/api/v1/domains', ['zone_name' => 'alias.example'])
            ->assertStatus(422);
    }

    public function test_nameserver_force_verify_reseed_and_activation(): void
    {
        $token = $this->adminToken();
        $domain = $this->withToken($token)
            ->postJson('/api/v1/domains', ['domain' => 'verify.example'])
            ->assertCreated()
            ->json('data');

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/activate")
            ->assertStatus(422)
            ->assertJson(['error' => 'nameservers_not_verified']);

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/nameservers/force-verify", ['reason' => 'Registrar verified manually'])
            ->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.nameserver_status', 'verified')
            ->assertJsonPath('data.forced_verified', true);

        $this->assertDatabaseHas('domains', ['id' => $domain['id'], 'status' => 'active', 'nameserver_status' => 'verified']);
        $this->assertDatabaseHas('audit_log', ['action' => 'domain.nameserver.force_verify', 'domain_id' => $domain['id']]);

        DB::table('platform_settings')->where('key', 'platform.nameservers')->update([
            'value_json' => json_encode(['hostnames' => ['ns3.cdnlite.test', 'ns4.cdnlite.test']], JSON_UNESCAPED_SLASHES),
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/nameservers/reseed-expected")
            ->assertOk()
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.nameserver_status', 'partial')
            ->assertJsonPath('data.reseeded_expected', true);

        $this->assertDatabaseHas('domain_nameservers', ['domain_id' => $domain['id'], 'hostname' => 'ns3.cdnlite.test']);
        $this->assertDatabaseHas('audit_log', ['action' => 'domain.nameserver.reseed_expected', 'domain_id' => $domain['id']]);

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/activate", ['override' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_origin_lifecycle_update_and_delete(): void
    {
        $token = $this->adminToken();
        $domain = $this->withToken($token)
            ->postJson('/api/v1/domains', ['domain' => 'origins.example'])
            ->assertCreated()
            ->json('data');

        $origin = $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/origins", [
                'host' => 'Origin.EXAMPLE',
                'scheme' => 'https',
                'tls_verify' => 'verify',
                'health_check_path' => '/health',
            ])
            ->assertCreated()
            ->assertJsonPath('data.host', 'origin.example')
            ->assertJsonPath('data.port', 443)
            ->assertJsonPath('data.tls_verify', 'verify')
            ->json('data');

        $this->withToken($token)
            ->patchJson("/api/v1/domains/{$domain['id']}/origins/{$origin['id']}", [
                'enabled' => false,
                'weight' => 20,
                'role' => 'backup',
            ])
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.weight', 20)
            ->assertJsonPath('data.role', 'backup');

        $this->assertDatabaseHas('audit_log', ['action' => 'origin.update', 'domain_id' => $domain['id']]);

        $this->withToken($token)
            ->deleteJson("/api/v1/domains/{$domain['id']}/origins/{$origin['id']}")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('domain_origins', ['id' => $origin['id']]);
        $this->assertDatabaseHas('audit_log', ['action' => 'origin.delete', 'domain_id' => $domain['id']]);
    }

    public function test_origin_diagnostics_and_edge_health_report_are_laravel_native(): void
    {
        $token = $this->adminToken();
        $now = UnixTime::now();
        $domain = $this->withToken($token)
            ->postJson('/api/v1/domains', ['domain' => 'origin-health.example'])
            ->assertCreated()
            ->json('data');

        $origin = $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/origins", [
                'host' => '127.0.0.1',
                'scheme' => 'http',
                'health_check_enabled' => true,
                'health_check_path' => '/health',
            ])
            ->assertCreated()
            ->json('data');

        DB::table('edge_nodes')->insert([
            'id' => (string) Str::uuid(),
            'edge_id' => 'edge-origin-report-1',
            'hostname' => 'edge-origin-report-1.local',
            'public_ip' => '203.0.113.10',
            'public_ipv4' => '203.0.113.10',
            'public_ipv6' => null,
            'region' => 'iad',
            'country' => 'US',
            'continent' => 'NA',
            'latitude' => null,
            'longitude' => null,
            'version' => 'test',
            'status' => 'online',
            'is_enabled' => true,
            'last_heartbeat' => $now,
            'last_heartbeat_at' => $now,
            'health_status' => 'healthy',
            'applied_config_version' => null,
            'last_config_pull_at' => null,
            'config_apply_error' => null,
            'weight' => 100,
            'priority' => 100,
            'geo_enabled' => true,
            'anycast_enabled' => false,
            'proxy_enabled' => true,
            'dns_enabled' => true,
            'cache_enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('origin_health_observations')->insert([
            'id' => (string) Str::uuid(),
            'domain_id' => $domain['id'],
            'origin_id' => $origin['id'],
            'edge_node_id' => 'edge-origin-report-1',
            'status' => 'slow',
            'reason' => 'origin_jitter',
            'upstream_status' => '200',
            'latency_ms' => 3250,
            'jitter_ms' => 2250,
            'sample_count' => 2,
            'first_observed_at' => $now - 10,
            'last_observed_at' => $now,
            'last_success_at' => $now,
            'last_failure_at' => null,
        ]);

        $this->withToken($token)
            ->getJson("/api/v1/domains/{$domain['id']}/origins/health")
            ->assertOk()
            ->assertJsonPath('source', 'edge_observations')
            ->assertJsonPath('core_active_checks', false)
            ->assertJsonPath('items.0.origin_id', $origin['id'])
            ->assertJsonPath('items.0.edge_count', 1)
            ->assertJsonPath('items.0.slow_edges', 1)
            ->assertJsonPath('items.0.max_jitter_ms', 2250)
            ->assertJsonPath('items.0.edges.0.edge_label', 'edge-origin-report-1.local');

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/origins/{$origin['id']}/check")
            ->assertOk()
            ->assertJsonPath('data.origin_id', $origin['id'])
            ->assertJsonPath('data.authoritative', false)
            ->assertJsonPath('data.source', 'core_diagnostic_only');

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/origins/{$origin['id']}/test")
            ->assertOk()
            ->assertJsonPath('data.origin_id', $origin['id'])
            ->assertJsonMissingPath('data.authoritative');

        $this->assertDatabaseHas('domain_origins', [
            'id' => $origin['id'],
            'health_status' => 'unknown',
            'last_check_at' => null,
        ]);
    }

    public function test_origin_validation_rejects_invalid_health_check_path(): void
    {
        $token = $this->adminToken();
        $domain = $this->withToken($token)
            ->postJson('/api/v1/domains', ['domain' => 'origin-validation.example'])
            ->assertCreated()
            ->json('data');

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domain['id']}/origins", [
                'host' => 'origin.example',
                'health_check_path' => 'health',
            ])
            ->assertStatus(422);
    }

    public function test_admin_auth_is_required_for_domain_routes(): void
    {
        $this->getJson('/api/v1/domains')
            ->assertUnauthorized()
            ->assertJson(['error' => 'admin_auth_required']);
    }

    public function test_laravel_owns_traffic_rule_workflows(): void
    {
        $token = $this->adminToken();
        $hostname = 'traffic-rules-' . Str::lower(Str::random(8)) . '.example';
        $originHost = 'origin.' . $hostname;
        $domainId = $this->insertDomain($hostname);

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/origins", [
                'host' => $originHost,
                'scheme' => 'https',
            ])
            ->assertCreated();

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/dns/records", [
                'type' => 'A',
                'name' => '@',
                'content' => '192.0.2.25',
                'proxied' => true,
                'origin_host' => $originHost,
                'origin_scheme' => 'https',
            ])
            ->assertCreated();

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/cache-rules", [
                'enabled' => true,
                'path_prefix' => '/assets',
                'ttl_seconds' => 300,
            ])
            ->assertCreated()
            ->assertJsonPath('data.path_prefix', '/assets');

        $this->withToken($token)
            ->putJson("/api/v1/domains/{$domainId}/cache/settings", [
                'enabled' => true,
                'default_edge_ttl_seconds' => 600,
                'cache_query_string_mode' => 'include_all',
                'respect_origin_cache_control' => true,
                'cache_authorized_requests' => false,
                'stale_if_error_seconds' => 60,
                'static_asset_cache_enabled' => true,
                'ignore_query_strings_for_static' => true,
                'bypass_logged_in_users' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.default_edge_ttl_seconds', 600);

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/cache/purge", [
                'type' => 'prefix',
                'value' => '/assets',
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'prefix');

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/waf-rules", [
                'enabled' => true,
                'type' => 'path_contains',
                'pattern' => '../',
                'action' => 'challenge',
                'challenge_difficulty' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('data.action', 'challenge');

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/rate-limits", [
                'enabled' => true,
                'path_prefix' => '/api',
                'requests_per_minute' => 120,
                'key_type' => 'ip_path',
                'action' => 'block',
            ])
            ->assertCreated()
            ->assertJsonPath('data.requests_per_minute', 120);

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/ip-rules", [
                'rule_type' => 'block',
                'cidr' => '192.0.2.0/24',
            ])
            ->assertCreated()
            ->assertJsonPath('data.rule_type', 'block');

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/redirects", [
                'enabled' => true,
                'source_path' => '/old',
                'target_url' => "https://{$hostname}/new",
                'status_code' => 308,
                'match_type' => 'exact_path',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status_code', 308);

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/redirects/test", ['path' => '/old'])
            ->assertOk()
            ->assertJsonPath('data.matched', true);

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/headers", [
                'operation' => 'set',
                'header_name' => 'X-CDNLite-Test',
                'header_value' => 'enabled',
                'path_pattern' => '/*',
            ])
            ->assertCreated()
            ->assertJsonPath('data.header_name', 'X-CDNLite-Test');

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/page-rules", [
                'enabled' => true,
                'pattern' => '/docs*',
                'actions' => ['cache_ttl' => 60],
            ])
            ->assertCreated()
            ->assertJsonPath('data.pattern', '/docs*');

        $this->withToken($token)
            ->patchJson("/api/v1/domains/{$domainId}/waiting-room", [
                'enabled' => true,
                'mode' => 'manual',
                'state' => 'healthy',
                'admission_rate_per_minute' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/waiting-room/emergency/activate", [
                'ttl_seconds' => 300,
                'reason' => 'load-test',
            ])
            ->assertOk()
            ->assertJsonPath('data.state', 'manual_emergency');

        $snapshot = $this->withToken($token)
            ->postJson('/api/v1/edge/config/publish')
            ->assertOk()
            ->assertJsonPath('data.snapshot.schema', 'edge-config.v1')
            ->json('data.snapshot');

        $this->assertSame($domainId, $snapshot['hosts'][$hostname]['domain_id']);
        $this->assertSame(1, $this->countSnapshotRowsForDomain($snapshot['redirects'], $domainId));
        $this->assertSame(1, $this->countSnapshotRowsForDomain($snapshot['waf_rules'], $domainId));
        $this->assertSame(1, $this->countSnapshotRowsForDomain($snapshot['rate_limits'], $domainId));
        $this->assertSame(1, $this->countSnapshotRowsForDomain($snapshot['ip_rules'], $domainId));
        $this->assertSame(1, $this->countSnapshotRowsForDomain($snapshot['header_rules'], $domainId));
        $this->assertSame(1, $this->countSnapshotRowsForDomain($snapshot['cache_rules'], $domainId));
        $this->assertSame('manual_emergency', $snapshot['hosts'][$hostname]['waiting_room']['state']);

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/route-debug", [
                'host' => $hostname,
                'path' => 'assets/app.css',
                'country' => 'us',
            ])
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.host', $hostname)
            ->assertJsonPath('data.path', '/assets/app.css')
            ->assertJsonPath('data.country', 'US')
            ->assertJsonPath('data.origin_pool_size', 1)
            ->assertJsonPath('data.redirects_count', 1)
            ->assertJsonPath('data.cache_rules_count', 1)
            ->assertJsonPath('data.waf_rules_count', 1)
            ->assertJsonPath('data.rate_limits_count', 1)
            ->assertJsonPath('data.ip_rules_count', 1)
            ->assertJsonPath('data.header_rules_count', 1)
            ->assertJsonPath('data.waiting_room_enabled', true)
            ->assertJsonPath('data.waiting_room_state', 'manual_emergency')
            ->assertJsonPath('data.router_error', null);
    }

    public function test_admin_auth_is_required_for_traffic_rule_routes(): void
    {
        $this->getJson('/api/v1/domains/domain-test/cache-rules')
            ->assertUnauthorized()
            ->assertJson(['error' => 'admin_auth_required']);
    }

    public function test_laravel_owns_protection_catalog_and_onboarding_routes(): void
    {
        $token = $this->adminToken();
        $hostname = 'protection-' . Str::lower(Str::random(8)) . '.example';
        $domainId = $this->insertDomain($hostname);

        $this->withToken($token)
            ->getJson("/api/v1/domains/{$domainId}/protection/waf-presets")
            ->assertOk()
            ->assertJsonPath('data.mutates', false)
            ->assertJsonPath('data.groups.0.group_id', 'sql_injection');

        $this->withToken($token)
            ->getJson("/api/v1/domains/{$domainId}/protection/rate-limit-templates")
            ->assertOk()
            ->assertJsonPath('data.mutates', false)
            ->assertJsonPath('data.window_seconds', 60);

        $this->withToken($token)
            ->getJson("/api/v1/domains/{$domainId}/protection/api-paths")
            ->assertOk()
            ->assertJsonPath('data.recommended_header_key', 'Authorization')
            ->assertJsonPath('data.paths.0.path_prefix', '/api/');

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/protection/profiles/api/preview")
            ->assertOk()
            ->assertJsonPath('data.profile_key', 'api')
            ->assertJsonPath('data.mutates', false);

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/protection/intents/protect_api/preview")
            ->assertOk()
            ->assertJsonPath('data.intent_key', 'protect_api')
            ->assertJsonPath('data.mutates', false);

        $this->withToken($token)
            ->getJson("/api/v1/domains/{$domainId}/onboarding")
            ->assertOk()
            ->assertJsonPath('data.status', 'not_started');

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/onboarding/answers", [
                'answers' => [
                    'site_type' => 'api',
                    'has_api' => true,
                    'framework' => 'other',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.recommended_profile_key', 'api');

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/onboarding/preview")
            ->assertOk()
            ->assertJsonPath('data.mutates', false)
            ->assertJsonPath('data.profile_preview.profile_key', 'api');

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/onboarding/skip")
            ->assertOk()
            ->assertJsonPath('data.status', 'skipped');

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/onboarding/resume")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
    }

    public function test_laravel_owns_ssl_settings_and_queued_job_routes(): void
    {
        $token = $this->adminToken();
        $hostname = 'ssl-' . Str::lower(Str::random(8)) . '.example';
        $domainId = $this->insertDomain($hostname);

        $this->withToken($token)
            ->getJson("/api/v1/domains/{$domainId}/ssl")
            ->assertOk()
            ->assertJsonPath('data.domain_id', $domainId)
            ->assertJsonPath('data.force_https', false)
            ->assertJsonPath('data.auto_renew', true);

        $this->withToken($token)
            ->patchJson("/api/v1/domains/{$domainId}/ssl/settings", [
                'min_tls_version' => '1.3',
                'auto_renew' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.min_tls_version', '1.3')
            ->assertJsonPath('data.auto_renew', false);

        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/ssl/request", [
                'hostnames' => 'not-an-array',
            ])
            ->assertStatus(422)
            ->assertJsonPath('field', 'hostnames');

        $request = $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/ssl/request", [
                'hostnames' => [$hostname, '*.'.$hostname],
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.job.status', 'queued')
            ->json('data');

        $this->withToken($token)
            ->getJson("/api/v1/domains/{$domainId}/ssl/jobs/{$request['job_id']}")
            ->assertOk()
            ->assertJsonPath('data.id', $request['job_id'])
            ->assertJsonPath('data.status', 'queued');

        $this->withToken($token)
            ->getJson("/api/v1/domains/{$domainId}/ssl/acme-status")
            ->assertOk()
            ->assertJsonPath('data.jobs.0.id', $request['job_id']);

        $this->withToken($token)
            ->getJson("/api/v1/domains/{$domainId}/ssl/certificates")
            ->assertOk()
            ->assertJsonFragment(['hostname' => $hostname]);
    }

    public function test_laravel_owns_config_snapshot_operations(): void
    {
        $token = $this->adminToken();
        $hostname = 'snapshot-' . Str::lower(Str::random(8)) . '.example';

        $first = $this->withToken($token)
            ->postJson('/api/v1/config/snapshots/rebuild')
            ->assertOk()
            ->assertJsonPath('data.snapshot.schema', 'edge-config.v1')
            ->json('data.version');

        $domainId = $this->insertDomain($hostname);
        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/origins", [
                'host' => 'origin.'.$hostname,
                'scheme' => 'https',
            ])
            ->assertCreated();
        $this->withToken($token)
            ->postJson("/api/v1/domains/{$domainId}/dns/records", [
                'type' => 'A',
                'name' => '@',
                'content' => '192.0.2.41',
                'proxied' => true,
                'origin_host' => 'origin.'.$hostname,
                'origin_scheme' => 'https',
            ])
            ->assertCreated();

        $secondResponse = $this->withToken($token)
            ->postJson('/api/v1/config/snapshots/rebuild')
            ->assertOk()
            ->json('data');
        $second = $secondResponse['version'];
        $this->assertSame($domainId, $secondResponse['snapshot']['hosts'][$hostname]['domain_id']);

        $this->withToken($token)
            ->getJson('/api/v1/config/snapshots?limit=5')
            ->assertOk()
            ->assertJsonFragment(['version' => $second, 'active' => true]);

        $this->withToken($token)
            ->getJson('/api/v1/config/snapshots/latest')
            ->assertOk()
            ->assertJsonPath('data.version', $second);

        $this->withToken($token)
            ->getJson("/api/v1/config/snapshots/{$second}")
            ->assertStatus(403)
            ->assertJsonPath('error', 'config_snapshot_history_disabled');

        config()->set('cdnlite.edge.snapshot_history_enabled', true);

        $snapshot = $this->withToken($token)
            ->getJson("/api/v1/config/snapshots/{$second}")
            ->assertOk()
            ->json('data');
        $this->assertSame($domainId, $snapshot['hosts'][$hostname]['domain_id']);

        $this->withToken($token)
            ->postJson('/api/v1/config/snapshots/diff', [
                'from_version' => $first,
                'to_version' => $second,
            ])
            ->assertOk()
            ->assertJsonPath('data.from_version', $first)
            ->assertJsonPath('data.to_version', $second);

        $this->withToken($token)
            ->postJson("/api/v1/config/snapshots/{$first}/rollback")
            ->assertStatus(403)
            ->assertJsonPath('error', 'config_snapshot_rollback_disabled');
    }

    private function adminToken(): string
    {
        $now = UnixTime::now();
        $userId = (string) Str::uuid();
        $token = bin2hex(random_bytes(32));

        DB::table('admin_users')->insert([
            'id' => $userId,
            'username' => "test-admin-{$userId}@example.test",
            'password_hash' => password_hash('secret-for-tests', PASSWORD_DEFAULT),
            'display_name' => 'Test Admin',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('admin_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'created_at' => $now,
            'expires_at' => $now + 3600,
            'revoked_at' => null,
        ]);

        return $token;
    }

    private function countSnapshotRowsForDomain(array $rows, string $domainId): int
    {
        return count(array_filter($rows, static fn (array $row): bool => (string) ($row['domain_id'] ?? '') === $domainId));
    }

    private function insertDomain(string $hostname): string
    {
        $now = UnixTime::now();
        $domainId = (string) Str::uuid();

        DB::table('domains')->insert([
            'id' => $domainId,
            'user_id' => 'system',
            'name' => $hostname,
            'domain' => $hostname,
            'status' => 'active',
            'nameserver_status' => 'verified',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $domainId;
    }

    private function enablePowerDnsQueue(): void
    {
        DB::table('platform_settings')->upsert([[
            'key' => 'platform.powerdns.enabled',
            'group_name' => 'platform',
            'value_json' => json_encode(true),
            'is_secret' => false,
            'updated_at' => UnixTime::now(),
        ]], ['key'], ['value_json', 'updated_at']);
    }

    private function configurePowerDns(array $overrides = []): void
    {
        $settings = [
            'platform.powerdns.enabled' => true,
            'platform.powerdns.api_url' => 'http://powerdns.test',
            'platform.powerdns.api_key' => 'test-key',
            'platform.powerdns.server_id' => 'localhost',
            'platform.powerdns.zone_kind' => 'NATIVE',
            'platform.powerdns.verify_after_write' => true,
            'platform.powerdns.retries' => 0,
            'platform.powerdns.retry_sleep_ms' => 0,
            'platform.powerdns.timeout_seconds' => 1,
        ];
        foreach ($overrides as $key => $value) {
            $settings[$key] = $value;
        }

        $rows = [];
        foreach ($settings as $key => $value) {
            $rows[] = [
                'key' => $key,
                'group_name' => 'platform',
                'value_json' => json_encode($value, JSON_UNESCAPED_SLASHES),
                'is_secret' => $key === 'platform.powerdns.api_key',
                'updated_at' => UnixTime::now(),
            ];
        }

        DB::table('platform_settings')->upsert($rows, ['key'], ['value_json', 'is_secret', 'updated_at']);
    }
}
