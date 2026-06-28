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
            'username' => 'test-admin@example.test',
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
