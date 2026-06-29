<?php

namespace Tests\Feature;

use App\Services\ControlPlane\UnixTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelemetryRetentionCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('telemetry_rejected_events')->delete();
        DB::table('telemetry_ingest_batches')->delete();
        DB::table('usage_ingest_keys')->delete();
        DB::table('usage_rollups')->delete();
        DB::table('audit_log')->delete();
        DB::table('dns_sync_events')->delete();
        DB::table('ssl_jobs')->delete();
        DB::table('edge_request_nonces')->delete();
    }

    public function test_usage_prune_dry_run_reports_all_laravel_retention_tables(): void
    {
        $now = UnixTime::now();
        $domainId = (string) Str::uuid();
        $this->insertDomain($domainId);
        $this->seedOldRetentionRows($domainId, $now);

        $this->artisan('cdn:usage:prune', [
            '--all' => true,
            '--dry-run' => true,
            '--days' => 30,
            '--security-days' => 90,
            '--dns-days' => 30,
            '--ssl-job-days' => 180,
            '--idempotency-days' => 7,
            '--batch-size' => 100,
        ])->assertExitCode(0);

        $this->assertSame(1, DB::table('usage_rollups')->where('domain_id', $domainId)->count());
        $this->assertSame(1, DB::table('audit_log')->where('event', 'waf_match')->count());
        $this->assertSame(1, DB::table('telemetry_rejected_events')->where('reason', 'unknown_domain')->count());
        $this->assertSame(1, DB::table('telemetry_ingest_batches')->where('batch_id', 'old-batch')->count());
        $this->assertSame(1, DB::table('usage_ingest_keys')->where('idempotency_key', 'old-ingest-key')->count());
        $this->assertSame(1, DB::table('dns_sync_events')->where('status', 'success')->count());
        $this->assertSame(1, DB::table('ssl_jobs')->where('status', 'issued')->count());
        $this->assertSame(1, DB::table('edge_request_nonces')->where('nonce', 'expired-retention-nonce')->count());
    }

    public function test_usage_prune_deletes_only_bounded_eligible_rows(): void
    {
        $now = UnixTime::now();
        $domainId = (string) Str::uuid();
        $this->insertDomain($domainId);
        $this->seedOldRetentionRows($domainId, $now);
        DB::table('usage_rollups')->insert([
            'id' => (string) Str::uuid(),
            'ts' => $now - (45 * 86400),
            'domain_id' => $domainId,
            'edge_node_id' => 'edge-retention',
            'requests_count' => 1,
            'bytes_in' => 1,
            'bytes_out' => 1,
            'status' => 200,
            'cache_status' => 'MISS',
        ]);

        $this->artisan('cdn:usage:prune', [
            '--all' => true,
            '--days' => 30,
            '--security-days' => 90,
            '--dns-days' => 30,
            '--ssl-job-days' => 180,
            '--idempotency-days' => 7,
            '--batch-size' => 100,
        ])->assertExitCode(0);

        $this->assertSame(0, DB::table('audit_log')->where('event', 'waf_match')->count());
        $this->assertSame(1, DB::table('audit_log')->where('event', 'operator_login')->count());
        $this->assertSame(0, DB::table('telemetry_rejected_events')->where('reason', 'unknown_domain')->count());
        $this->assertSame(0, DB::table('telemetry_ingest_batches')->where('batch_id', 'old-batch')->count());
        $this->assertSame(0, DB::table('usage_ingest_keys')->where('idempotency_key', 'old-ingest-key')->count());
        $this->assertSame(0, DB::table('dns_sync_events')->where('status', 'success')->count());
        $this->assertSame(1, DB::table('dns_sync_events')->where('status', 'failed')->count());
        $this->assertSame(0, DB::table('ssl_jobs')->where('status', 'issued')->count());
        $this->assertSame(1, DB::table('ssl_jobs')->where('status', 'issuing')->count());
        $this->assertSame(0, DB::table('edge_request_nonces')->where('nonce', 'expired-retention-nonce')->count());
        $this->assertSame(0, DB::table('usage_rollups')->where('ts', '<', $now - (30 * 86400))->count());
    }

    private function seedOldRetentionRows(string $domainId, int $now): void
    {
        DB::table('usage_rollups')->insert([
            'id' => (string) Str::uuid(),
            'ts' => $now - (45 * 86400),
            'domain_id' => $domainId,
            'edge_node_id' => 'edge-retention',
            'requests_count' => 1,
            'bytes_in' => 1,
            'bytes_out' => 1,
            'status' => 200,
            'cache_status' => 'HIT',
        ]);
        DB::table('audit_log')->insert([
            [
                'id' => (string) Str::uuid(),
                'actor_type' => 'edge',
                'actor_id' => 'edge-retention',
                'action' => 'block',
                'resource_type' => 'security',
                'resource_id' => 'waf-retention',
                'domain_id' => $domainId,
                'details_json' => '{}',
                'event' => 'waf_match',
                'created_at' => $now - (120 * 86400),
            ],
            [
                'id' => (string) Str::uuid(),
                'actor_type' => 'admin',
                'actor_id' => 'admin-retention',
                'action' => 'login',
                'resource_type' => 'session',
                'resource_id' => 'session-retention',
                'domain_id' => $domainId,
                'details_json' => '{}',
                'event' => 'operator_login',
                'created_at' => $now - (120 * 86400),
            ],
        ]);
        DB::table('telemetry_ingest_batches')->insert([
            'batch_id' => 'old-batch',
            'source_edge_id' => 'edge-retention',
            'idempotency_key' => 'usage:old-batch',
            'event_count' => 1,
            'accepted_count' => 0,
            'rejected_count' => 1,
            'payload_bytes' => 64,
            'status' => 'partial',
            'ingested_at' => $now - (10 * 86400),
        ]);
        DB::table('telemetry_rejected_events')->insert([
            'id' => (string) Str::uuid(),
            'batch_id' => 'old-batch',
            'source_edge_id' => 'edge-retention',
            'event_id' => 'bad-retention-event',
            'event_ts' => $now - (120 * 86400),
            'reason' => 'unknown_domain',
            'payload_excerpt' => '{}',
            'created_at' => $now - (120 * 86400),
        ]);
        DB::table('usage_ingest_keys')->insert([
            'idempotency_key' => 'old-ingest-key',
            'item_count' => 1,
            'created_at' => $now - (10 * 86400),
        ]);
        DB::table('dns_sync_events')->insert([
            [
                'zone_name' => 'retention.example',
                'action' => 'patch',
                'status' => 'success',
                'created_at' => $now - (45 * 86400),
            ],
            [
                'zone_name' => 'retention.example',
                'action' => 'patch',
                'status' => 'failed',
                'created_at' => $now - (45 * 86400),
            ],
        ]);
        DB::table('ssl_jobs')->insert([
            [
                'id' => (string) Str::uuid(),
                'domain_id' => $domainId,
                'status' => 'issued',
                'created_at' => $now - (200 * 86400),
                'updated_at' => $now - (200 * 86400),
            ],
            [
                'id' => (string) Str::uuid(),
                'domain_id' => $domainId,
                'status' => 'issuing',
                'created_at' => $now - (200 * 86400),
                'updated_at' => $now - (200 * 86400),
            ],
        ]);
        DB::table('edge_request_nonces')->insert([
            'id' => (string) Str::uuid(),
            'edge_id' => 'edge-retention',
            'nonce' => 'expired-retention-nonce',
            'created_at' => $now - 120,
            'expires_at' => $now - 60,
        ]);
    }

    private function insertDomain(string $domainId): void
    {
        $now = UnixTime::now();
        DB::table('domains')->insert([
            'id' => $domainId,
            'user_id' => 'system',
            'name' => 'Retention Example',
            'domain' => "retention-{$domainId}.example",
            'status' => 'active',
            'nameserver_status' => 'verified',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
