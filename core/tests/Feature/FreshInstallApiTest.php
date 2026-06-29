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
