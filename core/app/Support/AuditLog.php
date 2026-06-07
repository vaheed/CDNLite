<?php

namespace App\Support;

class AuditLog
{
    public static function write(
        string $action,
        string $resourceType,
        ?string $resourceId,
        ?string $domainId,
        mixed $before,
        mixed $after,
        string $actor = 'api-token'
    ): void {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO audit_log
             (id, actor_type, actor_id, action, resource_type, resource_id, domain_id, details_json, before_json, after_json, event, created_at)
             VALUES
             (:id, :actor_type, :actor_id, :action, :resource_type, :resource_id, :domain_id, :details_json, :before_json, :after_json, :event, :created_at)'
        );
        $stmt->execute([
            ':id' => Uuid::v4(),
            ':actor_type' => 'admin',
            ':actor_id' => $actor,
            ':action' => $action,
            ':resource_type' => $resourceType,
            ':resource_id' => $resourceId,
            ':domain_id' => $domainId,
            ':details_json' => json_encode(['before' => $before, 'after' => $after], JSON_UNESCAPED_SLASHES),
            ':before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES),
            ':after_json' => $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES),
            ':event' => $action,
            ':created_at' => time(),
        ]);
    }
}
