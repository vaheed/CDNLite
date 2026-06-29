<?php

namespace Tests\Feature;

use App\Services\ControlPlane\UnixTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EdgeAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('edge_request_nonces')->delete();
        DB::table('telemetry_rejected_events')->delete();
        DB::table('telemetry_ingest_batches')->delete();
        DB::table('usage_aggregates')->delete();
        DB::table('analytics_rollup_jobs')->delete();
        DB::table('recommendations')->delete();
        DB::table('usage_rollups')->delete();
    }

    public function test_edge_heartbeat_requires_signed_request(): void
    {
        $this->postJson('/api/v1/edge/heartbeat', ['edge_id' => 'edge-test'])
            ->assertUnauthorized()
            ->assertJson(['error' => 'edge_auth_required']);
    }

    public function test_edge_can_register_with_hmac_signature(): void
    {
        $edgeId = 'edge-test';
        $token = 'edge-secret-for-tests';
        $this->insertEdgeToken($edgeId, $token);

        $body = json_encode([
            'edge_id' => $edgeId,
            'hostname' => 'edge-test.local',
            'public_ip' => '192.0.2.10',
            'region' => 'local',
        ], JSON_UNESCAPED_SLASHES);
        $timestamp = UnixTime::now();
        $nonce = bin2hex(random_bytes(8));
        $path = '/api/v1/edge/register';
        $canonical = "POST\n{$path}\n{$timestamp}\n{$nonce}\n".hash('sha256', $body);
        $signature = hash_hmac('sha256', $canonical, hash('sha256', $token));

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-CDNLITE-Edge-Id' => $edgeId,
            'X-CDNLITE-Timestamp' => (string) $timestamp,
            'X-CDNLITE-Nonce' => $nonce,
            'X-CDNLITE-Signature' => $signature,
        ])->postJson($path, json_decode($body, true))
            ->assertCreated()
            ->assertJsonPath('data.edge_id', $edgeId);
    }

    public function test_admin_can_publish_edge_config_snapshot_and_edge_fetches_active_version(): void
    {
        $this->resetConfigSnapshots();
        $adminToken = $this->adminToken();
        $edgeId = 'edge-config-test';
        $edgeToken = 'edge-config-secret';
        $now = UnixTime::now();
        $domainId = (string) Str::uuid();
        $originId = (string) Str::uuid();
        $recordId = (string) Str::uuid();
        $wwwRecordId = (string) Str::uuid();

        $this->insertEdgeToken($edgeId, $edgeToken);
        $this->insertEdgeNode($edgeId);

        DB::table('domains')->insert([
            'id' => $domainId,
            'user_id' => 'system',
            'name' => 'Edge Config Example',
            'domain' => 'edge-config.example',
            'status' => 'active',
            'nameserver_status' => 'verified',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('domain_origins')->insert([
            'id' => $originId,
            'domain_id' => $domainId,
            'source' => 'manual',
            'role' => 'primary',
            'weight' => 1,
            'load_balancing_algorithm' => 'weighted_hash',
            'scheme' => 'https',
            'host' => 'origin.edge-config.example',
            'port' => 443,
            'tls_verify' => 'verify',
            'preserve_host' => true,
            'is_primary' => true,
            'enabled' => true,
            'drain' => false,
            'health_status' => 'healthy',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('dns_records')->insert([
            'id' => $recordId,
            'domain_id' => $domainId,
            'type' => 'A',
            'name' => '@',
            'content' => 'origin.edge-config.example',
            'ttl' => 300,
            'proxied' => true,
            'origin_tls_verify' => 'verify',
            'public_type' => 'LUA',
            'public_content' => 'managed edge pool',
            'routing_policy' => 'standard',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('dns_records')->insert([
            'id' => $wwwRecordId,
            'domain_id' => $domainId,
            'type' => 'A',
            'name' => 'www',
            'content' => 'www-origin.edge-config.example',
            'ttl' => 300,
            'proxied' => true,
            'origin_tls_verify' => 'ignore',
            'origin_scheme' => 'http',
            'public_type' => 'CNAME',
            'public_content' => 'edge-config.example.cdn.test',
            'routing_policy' => 'standard',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insertEdgeConfigRules($domainId, $now);

        $published = $this->withToken($adminToken)
            ->postJson('/api/v1/edge/config/publish')
            ->assertOk()
            ->json('data');

        $version = (int) $published['version'];
        $this->assertSame($domainId, $published['snapshot']['hosts']['edge-config.example']['domain_id']);
        $this->assertSame('origin.edge-config.example', $published['snapshot']['hosts']['edge-config.example']['origins'][0]['host']);
        $this->assertArrayHasKey('www.edge-config.example', $published['snapshot']['hosts']);

        $response = $this->signedEdgeGet('/api/v1/edge/config', $edgeId, $edgeToken)
            ->assertOk()
            ->assertJsonPath('version', $version);

        $snapshot = $response->json();
        $this->assertSame('LUA', $snapshot['hosts']['edge-config.example']['dns_records'][0]['public_type']);
        $this->assertSame('full', $snapshot['hosts']['edge-config.example']['ssl']['mode']);
        $this->assertSame('set', $snapshot['hosts']['edge-config.example']['header_rules'][0]['operation']);
        $this->assertSame('block', $snapshot['hosts']['edge-config.example']['ip_rules'][0]['rule_type']);
        $this->assertSame('redirect-1', $snapshot['redirects'][0]['id']);
        $this->assertSame('rate-1', $snapshot['rate_limits'][0]['id']);
        $this->assertSame('waf-1', $snapshot['waf_rules'][0]['id']);
        $this->assertSame('cache-rule-1', $snapshot['cache_rules'][0]['id']);
        $this->assertSame('purge-1', $snapshot['cache_purge_versions'][0]['id']);
        $this->assertSame('page-rule-1', $snapshot['page_rules'][0]['id']);
        $this->assertSame('cert-1', $snapshot['ssl_certificates'][0]['id']);
        $this->assertSame('bot-source-1', $snapshot['hosts']['edge-config.example']['verified_bot_sources'][0]['id']);

        $this->assertDatabaseHas('edge_nodes', [
            'edge_id' => $edgeId,
            'applied_config_version' => $version,
        ]);
    }

    public function test_edge_config_conditional_fetch_returns_not_modified_from_published_snapshot(): void
    {
        $this->resetConfigSnapshots();
        $adminToken = $this->adminToken();
        $edgeId = 'edge-config-conditional';
        $edgeToken = 'edge-config-conditional-secret';

        $this->insertEdgeToken($edgeId, $edgeToken);
        $this->insertEdgeNode($edgeId);

        $version = (int) $this->withToken($adminToken)
            ->postJson('/api/v1/edge/config/publish')
            ->assertOk()
            ->json('data.version');

        $this->signedEdgeGet("/api/v1/edge/config?if_version={$version}", $edgeId, $edgeToken)
            ->assertOk()
            ->assertJson([
                'not_modified' => true,
                'version' => $version,
            ])
            ->assertJsonMissingPath('hosts');
    }

    public function test_edge_config_publish_rejects_snapshots_over_configured_size_limit(): void
    {
        $this->resetConfigSnapshots();
        config(['cdnlite.edge.config_max_bytes' => 64]);
        $adminToken = $this->adminToken();

        $this->withToken($adminToken)
            ->postJson('/api/v1/edge/config/publish')
            ->assertStatus(422)
            ->assertJson(['error' => 'config_snapshot_too_large']);

        $this->withToken($adminToken)
            ->getJson('/api/v1/edge/config/status')
            ->assertOk()
            ->assertJsonPath('data.active_snapshot_version', null)
            ->assertJsonPath('data.max_snapshot_bytes', 64);

        $error = (string) DB::table('config_state')->where('id', 1)->value('last_publish_error');
        $this->assertStringStartsWith('config_snapshot_too_large:', $error);
    }

    public function test_edge_config_falls_back_to_empty_last_known_good_shape_before_first_publish(): void
    {
        $this->resetConfigSnapshots();
        $edgeId = 'edge-config-empty';
        $edgeToken = 'edge-config-empty-secret';

        $this->insertEdgeToken($edgeId, $edgeToken);
        $this->insertEdgeNode($edgeId);

        $this->signedEdgeGet('/api/v1/edge/config', $edgeId, $edgeToken)
            ->assertOk()
            ->assertJsonPath('version', 0)
            ->assertJsonPath('schema', 'edge-config.v1')
            ->assertJsonPath('content_hash', 'empty')
            ->assertJsonPath('hosts', []);
    }

    public function test_edge_can_push_security_events_to_laravel_collector(): void
    {
        $edgeId = 'edge-security-events';
        $edgeToken = 'edge-security-secret';
        $domainId = (string) Str::uuid();

        $this->insertEdgeToken($edgeId, $edgeToken);
        $this->insertEdgeNode($edgeId);
        $this->insertDomain($domainId, "security-events-{$domainId}.example");

        $payload = [
            'idempotency_key' => 'sec-test-batch',
            'items' => [
                [
                    'ts' => UnixTime::now(),
                    'domain_id' => $domainId,
                    'type' => 'waf_match',
                    'action' => 'block',
                    'path' => '/wp-config.php',
                ],
            ],
        ];

        $this->signedEdgePost('/api/v1/collector/security-events', $edgeId, $edgeToken, $payload)
            ->assertOk()
            ->assertJson(['ok' => true, 'accepted' => 1]);

        $this->assertDatabaseHas('telemetry_ingest_batches', [
            'source_edge_id' => $edgeId,
            'idempotency_key' => 'security:sec-test-batch',
            'event_count' => 1,
            'accepted_count' => 1,
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('audit_log', [
            'actor_type' => 'edge',
            'actor_id' => $edgeId,
            'event' => 'waf_match',
            'domain_id' => $domainId,
        ]);
    }

    public function test_edge_usage_ingest_persists_rollups_and_rejects_unknown_domains(): void
    {
        $edgeId = 'edge-usage-events';
        $edgeToken = 'edge-usage-secret';
        $domainId = (string) Str::uuid();

        $this->insertEdgeToken($edgeId, $edgeToken);
        $this->insertEdgeNode($edgeId);
        $this->insertDomain($domainId, "usage-events-{$domainId}.example");

        $payload = [
            'idempotency_key' => 'usage-test-batch',
            'events' => [
                [
                    'ts' => UnixTime::now(),
                    'domain_id' => $domainId,
                    'request_id' => 'req-usage-1',
                    'status' => 200,
                    'cache_status' => 'hit',
                    'bytes_in' => 91,
                    'bytes_out' => 4096,
                    'host' => "usage-events-{$domainId}.example",
                    'method' => 'GET',
                    'path' => '/index.html',
                ],
                [
                    'ts' => UnixTime::now(),
                    'domain_id' => (string) Str::uuid(),
                    'request_id' => 'req-unknown-domain',
                    'status' => 200,
                ],
            ],
        ];

        $this->signedEdgePost('/api/v1/collector/usage', $edgeId, $edgeToken, $payload)
            ->assertOk()
            ->assertJson(['ok' => true, 'accepted' => 1, 'rejected' => 1, 'duplicate' => false]);

        $this->assertDatabaseHas('usage_rollups', [
            'domain_id' => $domainId,
            'edge_node_id' => $edgeId,
            'request_id' => 'req-usage-1',
            'status' => 200,
            'cache_status' => 'HIT',
        ]);
        $this->assertDatabaseHas('telemetry_ingest_batches', [
            'source_edge_id' => $edgeId,
            'idempotency_key' => 'usage:usage-test-batch',
            'event_count' => 2,
            'accepted_count' => 1,
            'rejected_count' => 1,
            'status' => 'partial',
        ]);
        $this->assertDatabaseHas('telemetry_rejected_events', [
            'source_edge_id' => $edgeId,
            'event_id' => 'req-unknown-domain',
            'reason' => 'unknown_domain',
        ]);
    }

    public function test_collector_idempotency_replays_do_not_duplicate_events(): void
    {
        $edgeId = 'edge-idempotent-events';
        $edgeToken = 'edge-idempotent-secret';
        $domainId = (string) Str::uuid();

        $this->insertEdgeToken($edgeId, $edgeToken);
        $this->insertEdgeNode($edgeId);
        $this->insertDomain($domainId, "idempotent-events-{$domainId}.example");

        $payload = [
            'idempotency_key' => 'same-usage-batch',
            'events' => [[
                'ts' => UnixTime::now(),
                'domain_id' => $domainId,
                'request_id' => 'req-idempotent-1',
                'status' => 204,
            ]],
        ];

        $this->signedEdgePost('/api/v1/collector/usage', $edgeId, $edgeToken, $payload)
            ->assertOk()
            ->assertJson(['accepted' => 1, 'duplicate' => false]);

        $this->signedEdgePost('/api/v1/collector/usage', $edgeId, $edgeToken, $payload)
            ->assertOk()
            ->assertJson(['accepted' => 0, 'duplicate' => true]);

        $this->assertSame(1, DB::table('usage_rollups')->where('request_id', 'req-idempotent-1')->count());
    }

    public function test_collector_rejects_malformed_batches_before_persisting_receipts(): void
    {
        $edgeId = 'edge-malformed-events';
        $edgeToken = 'edge-malformed-secret';

        $this->insertEdgeToken($edgeId, $edgeToken);
        $this->insertEdgeNode($edgeId);

        $this->signedEdgePost('/api/v1/collector/security-events', $edgeId, $edgeToken, [
            'idempotency_key' => 'bad-security-batch',
            'items' => 'not-an-array',
        ])
            ->assertStatus(422)
            ->assertJson(['error' => 'items_must_be_array']);

        $this->assertDatabaseMissing('telemetry_ingest_batches', [
            'idempotency_key' => 'security:bad-security-batch',
        ]);
    }

    public function test_admin_activity_and_security_reads_are_laravel_native(): void
    {
        $adminToken = $this->adminToken();
        $domainId = (string) Str::uuid();
        $edgeId = 'edge-admin-activity';
        $now = UnixTime::now();

        $this->insertDomain($domainId, "admin-activity-{$domainId}.example");
        $this->insertEdgeNode($edgeId);

        DB::table('usage_rollups')->insert([
            'id' => (string) Str::uuid(),
            'ts' => $now,
            'domain_id' => $domainId,
            'edge_node_id' => $edgeId,
            'requests_count' => 3,
            'bytes_in' => 120,
            'bytes_out' => 900,
            'status' => 502,
            'cache_status' => 'MISS',
            'request_id' => 'req-admin-activity',
            'host' => "admin-activity-{$domainId}.example",
            'method' => 'GET',
            'path' => '/checkout',
            'client_ip' => '198.51.100.24',
            'client_country' => 'US',
            'router_error' => 'origin_unavailable',
        ]);
        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid(),
            'actor_type' => 'edge',
            'actor_id' => $edgeId,
            'action' => 'block',
            'resource_type' => 'security',
            'resource_id' => 'waf-admin-1',
            'domain_id' => $domainId,
            'details_json' => json_encode(['decision' => 'block', 'client_ip' => '198.51.100.24', 'request_id' => 'req-admin-activity']),
            'event' => 'waf_match',
            'created_at' => $now,
        ]);

        $this->withToken($adminToken)
            ->getJson("/api/v1/domains/{$domainId}/activity/summary")
            ->assertOk()
            ->assertJsonPath('data.total_requests', 3)
            ->assertJsonPath('data.status_counts.5xx', 3)
            ->assertJsonPath('data.beginner.counts.security', 1);

        $this->withToken($adminToken)
            ->getJson("/api/v1/domains/{$domainId}/activity?type=security")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.type', 'security');

        $this->withToken($adminToken)
            ->getJson("/api/v1/domains/{$domainId}/activity/requests/req-admin-activity")
            ->assertOk()
            ->assertJsonPath('data.router_error', 'origin_unavailable');

        $this->withToken($adminToken)
            ->getJson("/api/v1/domains/{$domainId}/analytics/cache")
            ->assertOk()
            ->assertJsonPath('data.miss', 3);

        $this->withToken($adminToken)
            ->getJson("/api/v1/security/summary?domain_id={$domainId}")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.by_type.waf_match', 1)
            ->assertJsonPath('data.top_ips.0.value', '198.51.100.24');

        $jobId = $this->withToken($adminToken)
            ->postJson('/api/v1/usage/recalculate', ['domain_id' => $domainId, 'bucket' => 'hour'])
            ->assertStatus(202)
            ->assertJsonPath('data.job_status', 'succeeded')
            ->json('data.job_id');

        $this->assertDatabaseHas('usage_aggregates', [
            'domain_id' => $domainId,
            'bucket' => 'hour',
            'edge_node_id' => $edgeId,
            'status' => 502,
            'cache_status' => 'MISS',
            'requests_count' => 3,
        ]);

        $this->withToken($adminToken)
            ->getJson("/api/v1/usage/recalculate/{$jobId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.progress.inserted.hour', 1);

        $this->withToken($adminToken)
            ->getJson('/api/v1/reports/summary?bucket=hour')
            ->assertOk()
            ->assertJsonPath('data.kpis.total_requests', 3);

        $this->withToken($adminToken)
            ->getJson('/api/v1/reports/traffic?bucket=hour')
            ->assertOk()
            ->assertJsonPath('data.top_domains.0.domain_id', $domainId);

        $this->withToken($adminToken)
            ->getJson('/api/v1/reports/security?bucket=hour')
            ->assertOk()
            ->assertJsonPath('data.by_type.0.value', 'waf_match');

        $recommendation = $this->withToken($adminToken)
            ->postJson("/api/v1/domains/{$domainId}/recommendations/generate")
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->json('data.generated.0');

        $this->withToken($adminToken)
            ->getJson("/api/v1/domains/{$domainId}/recommendations")
            ->assertOk()
            ->assertJsonPath('data.0.id', $recommendation['id']);

        $this->withToken($adminToken)
            ->postJson("/api/v1/domains/{$domainId}/recommendations/{$recommendation['id']}/dismiss")
            ->assertOk()
            ->assertJsonPath('data.status', 'dismissed');
    }

    private function adminToken(): string
    {
        $now = UnixTime::now();
        $userId = (string) Str::uuid();
        $token = bin2hex(random_bytes(32));

        DB::table('admin_users')->insert([
            'id' => $userId,
            'username' => "edge-admin-{$userId}@example.test",
            'password_hash' => password_hash('secret-for-tests', PASSWORD_DEFAULT),
            'display_name' => 'Edge Admin',
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

    private function insertEdgeToken(string $edgeId, string $token): void
    {
        DB::table('edge_tokens')->upsert([[
            'edge_id' => $edgeId,
            'token_hash' => password_hash($token, PASSWORD_BCRYPT),
            'created_at' => UnixTime::now(),
            'updated_at' => UnixTime::now(),
        ]], ['edge_id'], ['token_hash', 'updated_at']);
    }

    private function insertDomain(string $domainId, string $domain): void
    {
        $now = UnixTime::now();

        DB::table('domains')->insert([
            'id' => $domainId,
            'user_id' => 'system',
            'name' => ucfirst(str_replace('.', ' ', $domain)),
            'domain' => $domain,
            'status' => 'active',
            'nameserver_status' => 'verified',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertEdgeNode(string $edgeId): void
    {
        $now = UnixTime::now();

        DB::table('edge_nodes')->upsert([[
            'id' => (string) Str::uuid(),
            'edge_id' => $edgeId,
            'hostname' => "{$edgeId}.local",
            'public_ip' => '192.0.2.10',
            'public_ipv4' => '192.0.2.10',
            'region' => 'local',
            'country' => 'US',
            'continent' => 'NA',
            'version' => 'test',
            'status' => 'online',
            'is_enabled' => true,
            'last_heartbeat' => $now,
            'last_heartbeat_at' => $now,
            'health_status' => 'healthy',
            'weight' => 100,
            'priority' => 100,
            'geo_enabled' => true,
            'anycast_enabled' => false,
            'proxy_enabled' => true,
            'dns_enabled' => true,
            'cache_enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['edge_id'], [
            'hostname', 'public_ip', 'public_ipv4', 'region', 'country', 'continent', 'version',
            'status', 'is_enabled', 'last_heartbeat', 'last_heartbeat_at', 'health_status',
            'weight', 'priority', 'geo_enabled', 'anycast_enabled', 'proxy_enabled', 'dns_enabled',
            'cache_enabled', 'updated_at',
        ]);
    }

    private function insertEdgeConfigRules(string $domainId, int $now): void
    {
        DB::table('domain_cache_settings')->upsert([[
            'domain_id' => $domainId,
            'enabled' => true,
            'default_edge_ttl_seconds' => 120,
            'cache_query_string_mode' => 'include_all',
            'respect_origin_cache_control' => true,
            'cache_authorized_requests' => false,
            'stale_if_error_seconds' => 60,
            'static_asset_cache_enabled' => true,
            'ignore_query_strings_for_static' => false,
            'bypass_logged_in_users' => true,
            'cache_methods_json' => json_encode(['GET', 'HEAD']),
            'cache_status_code_policy_json' => json_encode(['200' => true]),
            'bypass_headers_json' => json_encode(['authorization']),
            'bypass_cookies_json' => json_encode(['session']),
            'vary_headers_json' => json_encode(['accept-encoding']),
            'cache_key_dimensions_json' => json_encode(['host' => true, 'path' => true]),
            'debug_headers_enabled' => true,
            'stale_while_revalidate_seconds' => 0,
            'negative_ttl_seconds' => 0,
            'max_object_size_bytes' => 1024,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['domain_id'], ['enabled', 'default_edge_ttl_seconds', 'updated_at']);

        DB::table('domain_ssl_settings')->upsert([[
            'domain_id' => $domainId,
            'force_https' => true,
            'min_tls_version' => '1.2',
            'auto_renew' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['domain_id'], ['force_https', 'min_tls_version', 'auto_renew', 'updated_at']);

        DB::table('redirect_rules')->upsert([[
            'id' => 'redirect-1',
            'domain_id' => $domainId,
            'enabled' => true,
            'source_path' => '/old',
            'target_url' => 'https://edge-config.example/new',
            'status_code' => 301,
            'priority' => 10,
            'match_type' => 'exact_path',
            'preserve_query' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['id'], ['domain_id', 'enabled', 'updated_at']);

        DB::table('rate_limit_rules')->upsert([[
            'id' => 'rate-1',
            'domain_id' => $domainId,
            'enabled' => true,
            'priority' => 10,
            'path_prefix' => '/api',
            'key_type' => 'ip',
            'requests_per_minute' => 60,
            'action' => 'block',
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['id'], ['domain_id', 'enabled', 'updated_at']);

        DB::table('waf_rules')->upsert([[
            'id' => 'waf-1',
            'domain_id' => $domainId,
            'enabled' => true,
            'name' => 'Block scanners',
            'priority' => 10,
            'type' => 'path_contains',
            'pattern' => 'wp-config.php',
            'action' => 'block',
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['id'], ['domain_id', 'enabled', 'updated_at']);

        DB::table('domain_header_rules')->upsert([[
            'id' => 'header-1',
            'domain_id' => $domainId,
            'enabled' => true,
            'priority' => 10,
            'operation' => 'set',
            'header_name' => 'X-Test',
            'header_value' => 'edge',
            'path_pattern' => '/*',
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['id'], ['domain_id', 'enabled', 'updated_at']);

        DB::table('domain_ip_rules')->upsert([[
            'id' => 'ip-1',
            'domain_id' => $domainId,
            'enabled' => true,
            'rule_type' => 'block',
            'cidr' => '203.0.113.0/24',
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['id'], ['domain_id', 'enabled', 'updated_at']);

        DB::table('cache_rules')->upsert([[
            'id' => 'cache-rule-1',
            'domain_id' => $domainId,
            'enabled' => true,
            'path_prefix' => '/assets',
            'ttl_seconds' => 300,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['id'], ['domain_id', 'enabled', 'updated_at']);

        DB::table('cache_purge_versions')->upsert([[
            'id' => 'purge-1',
            'domain_id' => $domainId,
            'scope' => 'path',
            'value' => '/assets',
            'version' => 7,
            'updated_at' => $now,
        ]], ['domain_id', 'scope', 'value'], ['version', 'updated_at']);

        DB::table('page_rules')->upsert([[
            'id' => 'page-rule-1',
            'domain_id' => $domainId,
            'enabled' => true,
            'priority' => 10,
            'pattern' => '/promo',
            'actions_json' => json_encode(['cache_ttl' => 60]),
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['id'], ['domain_id', 'enabled', 'updated_at']);

        DB::table('ssl_certificates')->upsert([[
            'id' => 'cert-1',
            'domain_id' => $domainId,
            'hostname' => 'edge-config.example',
            'provider' => 'manual',
            'status' => 'active',
            'certificate_pem' => "-----BEGIN CERTIFICATE-----\ntest\n-----END CERTIFICATE-----",
            'private_key_pem' => "-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----",
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['domain_id', 'hostname'], ['status', 'certificate_pem', 'private_key_pem', 'updated_at']);

        DB::table('verified_bot_sources')->upsert([[
            'id' => 'bot-source-1',
            'domain_id' => $domainId,
            'bot_class' => 'verified_search_bot',
            'provider' => 'search',
            'user_agent_pattern' => 'Googlebot',
            'cidr' => '66.249.64.0/19',
            'enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['id'], ['domain_id', 'enabled', 'updated_at']);
    }

    private function signedEdgeGet(string $path, string $edgeId, string $token)
    {
        $timestamp = UnixTime::now();
        $nonce = bin2hex(random_bytes(8));
        $pathOnly = strtok($path, '?') ?: $path;
        $canonical = "GET\n{$pathOnly}\n{$timestamp}\n{$nonce}\n".hash('sha256', '');
        $signature = hash_hmac('sha256', $canonical, hash('sha256', $token));

        return $this->call('GET', $path, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_X_CDNLITE_EDGE_ID' => $edgeId,
            'HTTP_X_CDNLITE_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_CDNLITE_NONCE' => $nonce,
            'HTTP_X_CDNLITE_SIGNATURE' => $signature,
            'HTTP_ACCEPT' => 'application/json',
        ], '');
    }

    private function signedEdgePost(string $path, string $edgeId, string $token, array $body)
    {
        $bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES);
        $timestamp = UnixTime::now();
        $nonce = bin2hex(random_bytes(8));
        $canonical = "POST\n{$path}\n{$timestamp}\n{$nonce}\n".hash('sha256', (string) $bodyJson);
        $signature = hash_hmac('sha256', $canonical, hash('sha256', $token));

        return $this->call('POST', $path, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_X_CDNLITE_EDGE_ID' => $edgeId,
            'HTTP_X_CDNLITE_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_CDNLITE_NONCE' => $nonce,
            'HTTP_X_CDNLITE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], (string) $bodyJson);
    }

    private function resetConfigSnapshots(): void
    {
        DB::table('domains')->where('domain', 'edge-config.example')->delete();
        DB::table('config_snapshots')->delete();
        DB::table('config_state')->where('id', 1)->update([
            'version' => 0,
            'active_snapshot_version' => null,
            'dirty' => true,
            'dirty_at' => null,
            'published_at' => null,
            'last_publish_error' => null,
            'publishing_started_at' => null,
        ]);
    }
}
