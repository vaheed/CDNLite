<?php

namespace Tests\Feature;

use App\Services\ControlPlane\UnixTime;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EdgeAuthTest extends TestCase
{
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
        DB::table('edge_tokens')->insert([
            'edge_id' => $edgeId,
            'token_hash' => password_hash($token, PASSWORD_BCRYPT),
            'created_at' => UnixTime::now(),
            'updated_at' => UnixTime::now(),
        ]);

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
}
