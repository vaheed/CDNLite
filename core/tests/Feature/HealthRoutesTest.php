<?php

namespace Tests\Feature;

use App\Services\ControlPlane\UnixTime;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthRoutesTest extends TestCase
{
    public function test_health_route_is_served_by_laravel(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk()
            ->assertJsonStructure(['ok', 'time'])
            ->assertJson(['ok' => true]);
    }

    public function test_cors_preflight_matches_legacy_headers(): void
    {
        $response = $this->withHeader('Origin', 'http://localhost:8082')
            ->options('/api/v1/domains');

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:8082');
        $response->assertHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->assertHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-CDNLITE-Edge-Id, X-CDNLITE-Timestamp, X-CDNLITE-Nonce, X-CDNLITE-Signature');
    }

    public function test_unknown_api_route_no_longer_falls_through_to_legacy_router(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound();
    }

    public function test_readiness_reports_oversized_active_edge_config_snapshot(): void
    {
        config(['cdnlite.edge.config_max_bytes' => 16]);
        $now = UnixTime::now();

        DB::table('config_snapshots')->upsert([[
            'version' => 909,
            'content_hash' => 'oversized-readiness-test',
            'payload_json' => json_encode([
                'version' => 909,
                'schema' => 'edge-config.v1',
                'hosts' => ['oversized.example' => ['domain_id' => 'domain-test']],
            ], JSON_UNESCAPED_SLASHES),
            'generated_at' => $now,
        ]], ['version'], ['content_hash', 'payload_json', 'generated_at']);

        DB::table('config_state')->where('id', 1)->update([
            'version' => 909,
            'active_snapshot_version' => 909,
            'dirty' => false,
            'published_at' => $now,
            'last_publish_error' => null,
        ]);

        $response = $this->getJson('/api/v1/readiness')
            ->assertOk()
            ->assertJsonPath('core.status', 'error');

        $checks = collect($response->json('core.checks'));
        $snapshot = $checks->firstWhere('key', 'config_snapshot');

        $this->assertSame('error', $snapshot['status']);
        $this->assertSame('Active edge configuration is larger than the edge limit', $snapshot['message']);
        $this->assertSame(16, $snapshot['details']['max_snapshot_bytes']);
    }
}
