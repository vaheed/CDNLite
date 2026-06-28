<?php

namespace Database\Seeders;

use App\Services\ControlPlane\UnixTime;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $now = UnixTime::now();

        DB::table('platform_settings')->upsert([
            [
                'key' => 'platform.nameservers',
                'group_name' => 'platform',
                'value_json' => json_encode(['hostnames' => ['ns1.cdnlite.test', 'ns2.cdnlite.test']], JSON_UNESCAPED_SLASHES),
                'is_secret' => false,
                'description' => 'Local development authoritative nameservers.',
                'updated_by' => 'seeder',
                'updated_at' => $now,
            ],
            [
                'key' => 'platform.cdn_hostname',
                'group_name' => 'platform',
                'value_json' => json_encode(['hostname' => 'edge.cdnlite.test'], JSON_UNESCAPED_SLASHES),
                'is_secret' => false,
                'description' => 'Stable CDN hostname for proxied customer records.',
                'updated_by' => 'seeder',
                'updated_at' => $now,
            ],
        ], ['key'], ['value_json', 'description', 'updated_by', 'updated_at']);

        DB::table('config_state')->upsert([
            [
                'id' => 1,
                'version' => 1,
                'active_snapshot_version' => null,
                'dirty' => true,
                'dirty_at' => $now,
                'published_at' => null,
                'last_publish_error' => null,
                'publishing_started_at' => null,
            ],
        ], ['id'], ['version', 'dirty', 'dirty_at']);

        $adminUsername = (string) env('CDNLITE_DEV_ADMIN_USERNAME', 'admin@example.test');
        $adminPassword = (string) env('CDNLITE_DEV_ADMIN_PASSWORD', 'cdnlite-local-admin');

        DB::table('admin_users')->upsert([
            [
                'id' => (string) Str::uuid(),
                'username' => strtolower($adminUsername),
                'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
                'display_name' => 'Local Admin',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['username'], ['password_hash', 'display_name', 'status', 'updated_at']);

        $edgeId = (string) env('EDGE_ID', 'edge-local-1');
        $edgeToken = (string) env('EDGE_TOKEN', 'edge-dev-token');

        DB::table('edge_tokens')->upsert([
            [
                'edge_id' => $edgeId,
                'token_hash' => password_hash($edgeToken, PASSWORD_BCRYPT),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['edge_id'], ['token_hash', 'updated_at']);
    }
}
