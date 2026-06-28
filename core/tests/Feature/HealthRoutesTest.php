<?php

namespace Tests\Feature;

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
}
