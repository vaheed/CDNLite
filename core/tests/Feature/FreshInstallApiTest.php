<?php

namespace Tests\Feature;

use App\Services\ControlPlane\UnixTime;
use Illuminate\Support\Facades\DB;
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
}
