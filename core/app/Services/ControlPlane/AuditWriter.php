<?php

namespace App\Services\ControlPlane;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AuditWriter
{
    public function write(string $action, string $resourceType, ?string $resourceId, ?array $before = null, ?array $after = null, string $actorType = 'system', ?string $actorId = null, ?string $domainId = null, ?array $details = null): void
    {
        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid(),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'event' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'domain_id' => $domainId,
            'details_json' => $details === null ? null : json_encode($details, JSON_UNESCAPED_SLASHES),
            'before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES),
            'after_json' => $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES),
            'created_at' => UnixTime::now(),
        ]);
    }
}
