<?php

namespace App\Modules\Settings\Repositories;

use App\Support\Database;
use App\Support\Uuid;
use PDO;

class SettingsRepository
{
    private const GROUPS = [
        'platform.powerdns' => [
            'enabled' => ['type' => 'bool', 'default' => false, 'description' => 'Enable PowerDNS synchronization.'],
            'strict' => ['type' => 'bool', 'default' => false, 'description' => 'Fail local DNS operations when PowerDNS fails.'],
            'api_url' => ['type' => 'string', 'default' => '', 'description' => 'PowerDNS API base URL.'],
            'api_key' => ['type' => 'string', 'default' => '', 'secret' => true, 'description' => 'PowerDNS API key.'],
            'server_id' => ['type' => 'string', 'default' => 'localhost', 'description' => 'PowerDNS server identifier.'],
            'zone_kind' => ['type' => 'string', 'default' => 'NATIVE', 'description' => 'PowerDNS zone kind.'],
        ],
        'platform.nameservers' => [
            'hostnames' => ['type' => 'list', 'default' => ['ns1.local.', 'ns2.local.'], 'description' => 'Authoritative nameserver hostnames.'],
            'default_base_domain' => ['type' => 'string', 'default' => 'local.', 'description' => 'Fallback nameserver base domain.'],
        ],
        'platform.edge_dns' => [
            'health_port' => ['env' => 'EDGE_DNS_HEALTH_PORT', 'type' => 'int', 'default' => 443, 'description' => 'Default edge DNS health-check port.'],
            'heartbeat_window_seconds' => ['env' => 'EDGE_DNS_HEARTBEAT_WINDOW_SECONDS', 'type' => 'int', 'default' => 300, 'description' => 'Healthy edge heartbeat window.'],
        ],
        'platform.cache' => [
            'default_ttl' => ['env' => 'CDNLITE_CACHE_DEFAULT_TTL', 'type' => 'string', 'default' => '60s', 'description' => 'Default proxy cache TTL.'],
            'stale_if_error_seconds' => ['env' => 'CDNLITE_CACHE_STALE_IF_ERROR_SECONDS', 'type' => 'int', 'default' => 86400, 'description' => 'Default stale-if-error duration.'],
        ],
        'platform.analytics' => [
            'retention_days' => ['env' => 'CDNLITE_ANALYTICS_RETENTION_DAYS', 'type' => 'int', 'default' => 30, 'description' => 'Usage analytics retention period.'],
            'default_bucket' => ['env' => 'CDNLITE_ANALYTICS_DEFAULT_BUCKET', 'type' => 'string', 'default' => 'hour', 'description' => 'Default analytics bucket.'],
        ],
        'platform.security' => [
            'admin_session_ttl_seconds' => ['env' => 'CDNLITE_ADMIN_SESSION_TTL_SECONDS', 'type' => 'int', 'default' => 28800, 'description' => 'Admin session lifetime.'],
            'event_retention_days' => ['env' => 'CDNLITE_SECURITY_EVENT_RETENTION_DAYS', 'type' => 'int', 'default' => 30, 'description' => 'Security event retention period.'],
        ],
    ];

    public function groups(): array
    {
        $result = [];
        foreach (array_keys(self::GROUPS) as $group) {
            $result[$group] = $this->group($group);
        }
        return $result;
    }

    public function group(string $group): array
    {
        $definitions = $this->definitions($group);
        $stored = $this->storedRows($group);
        $values = [];
        foreach ($definitions as $name => $definition) {
            $row = $stored[$name] ?? null;
            $isSecret = (bool) ($definition['secret'] ?? false);
            if ($isSecret) {
                $value = $row === null ? $this->envValue($definition) : $this->decode((string) $row['value_json']);
                $values[$name] = [
                    'configured' => is_string($value) ? trim($value) !== '' : $value !== null,
                    'updated_at' => $row === null ? null : (int) $row['updated_at'],
                ];
                continue;
            }
            $values[$name] = $row === null ? $this->envValue($definition) : $this->decode((string) $row['value_json']);
        }

        return [
            'group' => $group,
            'values' => $values,
            'fields' => $this->publicDefinitions($definitions),
            'audit' => $this->audit($group),
        ];
    }

    public function value(string $group, string $name): mixed
    {
        $definition = $this->definitions($group)[$name] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException('unknown_setting');
        }
        try {
            $stmt = Database::pdo()->prepare('SELECT value_json FROM platform_settings WHERE key = :key');
            $stmt->execute(['key' => $group . '.' . $name]);
            $raw = $stmt->fetchColumn();
        } catch (\Throwable) {
            return $this->envValue($definition);
        }
        return $raw === false ? $this->envValue($definition) : $this->decode((string) $raw);
    }

    public function patch(string $group, array $values, ?string $actor): array
    {
        $definitions = $this->definitions($group);
        $unknown = array_diff(array_keys($values), array_keys($definitions));
        if ($unknown !== []) {
            throw new \InvalidArgumentException('unknown_setting:' . reset($unknown));
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($values as $name => $value) {
                $definition = $definitions[$name];
                $normalized = $this->normalize($value, $definition);
                $this->upsert($pdo, $group, $name, $normalized, $definition, $actor);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $this->group($group);
    }

    public function validate(string $group, array $values): array
    {
        $definitions = $this->definitions($group);
        $errors = [];
        foreach ($values as $name => $value) {
            if (!isset($definitions[$name])) {
                $errors[$name] = 'unknown_setting';
                continue;
            }
            try {
                $this->normalize($value, $definitions[$name]);
            } catch (\InvalidArgumentException $e) {
                $errors[$name] = $e->getMessage();
            }
        }
        return ['valid' => $errors === [], 'errors' => $errors];
    }

    private function definitions(string $group): array
    {
        if (!isset(self::GROUPS[$group])) {
            throw new \InvalidArgumentException('settings_group_not_found');
        }
        return self::GROUPS[$group];
    }

    private function storedRows(string $group): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM platform_settings WHERE group_name = :group_name');
        $stmt->execute(['group_name' => $group]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $prefix = $group . '.';
            $rows[substr((string) $row['key'], strlen($prefix))] = $row;
        }
        return $rows;
    }

    private function upsert(PDO $pdo, string $group, string $name, mixed $value, array $definition, ?string $actor): void
    {
        $key = $group . '.' . $name;
        $oldStmt = $pdo->prepare('SELECT value_json FROM platform_settings WHERE key = :key FOR UPDATE');
        $oldStmt->execute(['key' => $key]);
        $oldRaw = $oldStmt->fetchColumn();
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        $now = time();
        $secret = (bool) ($definition['secret'] ?? false);
        $stmt = $pdo->prepare(
            'INSERT INTO platform_settings (key, group_name, value_json, is_secret, description, updated_by, updated_at)
             VALUES (:key, :group_name, CAST(:value_json AS JSONB), :is_secret, :description, :updated_by, :updated_at)
             ON CONFLICT (key) DO UPDATE SET value_json = EXCLUDED.value_json, is_secret = EXCLUDED.is_secret,
             description = EXCLUDED.description, updated_by = EXCLUDED.updated_by, updated_at = EXCLUDED.updated_at'
        );
        $stmt->execute([
            'key' => $key, 'group_name' => $group, 'value_json' => $encoded,
            'is_secret' => $secret ? 'true' : 'false', 'description' => $definition['description'] ?? null,
            'updated_by' => $actor, 'updated_at' => $now,
        ]);

        $audit = $pdo->prepare(
            'INSERT INTO platform_settings_audit (id, key, actor, old_redacted, new_redacted, created_at)
             VALUES (:id, :key, :actor, CAST(:old_redacted AS JSONB), CAST(:new_redacted AS JSONB), :created_at)'
        );
        $old = $oldRaw === false ? null : $this->decode((string) $oldRaw);
        $audit->execute([
            'id' => Uuid::v4(), 'key' => $key, 'actor' => $actor,
            'old_redacted' => json_encode($this->redact($old, $secret), JSON_UNESCAPED_SLASHES),
            'new_redacted' => json_encode($this->redact($value, $secret), JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
        ]);
    }

    private function audit(string $group): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, key, actor, old_redacted, new_redacted, created_at
             FROM platform_settings_audit WHERE key LIKE :prefix ORDER BY created_at DESC, id DESC LIMIT 50'
        );
        $stmt->execute(['prefix' => $group . '.%']);
        return array_map(function (array $row): array {
            $row['old_value'] = $this->decodeNullable($row['old_redacted']);
            $row['new_value'] = $this->decodeNullable($row['new_redacted']);
            unset($row['old_redacted'], $row['new_redacted']);
            $row['created_at'] = (int) $row['created_at'];
            return $row;
        }, $stmt->fetchAll());
    }

    private function publicDefinitions(array $definitions): array
    {
        $fields = [];
        foreach ($definitions as $name => $definition) {
            $fields[$name] = [
                'type' => $definition['type'],
                'secret' => (bool) ($definition['secret'] ?? false),
                'description' => $definition['description'] ?? null,
            ];
        }
        return $fields;
    }

    private function envValue(array $definition): mixed
    {
        if (!isset($definition['env'])) {
            return $definition['default'];
        }
        $raw = getenv((string) $definition['env']);
        return $raw === false || trim((string) $raw) === '' ? $definition['default'] : $this->normalize($raw, $definition);
    }

    private function normalize(mixed $value, array $definition): mixed
    {
        return match ($definition['type']) {
            'bool' => $this->normalizeBool($value),
            'int' => $this->normalizeInt($value),
            'list' => $this->normalizeList($value),
            default => is_scalar($value) ? trim((string) $value) : throw new \InvalidArgumentException('must_be_string'),
        };
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) throw new \InvalidArgumentException('must_be_boolean');
        return $parsed;
    }

    private function normalizeInt(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new \InvalidArgumentException('must_be_non_negative_integer');
        }
        return (int) $value;
    }

    private function normalizeList(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);
        $items = array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $items)));
        if ($items === []) throw new \InvalidArgumentException('must_not_be_empty');
        return $items;
    }

    private function decode(string $value): mixed
    {
        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    private function decodeNullable(mixed $value): mixed
    {
        return $value === null ? null : $this->decode((string) $value);
    }

    private function redact(mixed $value, bool $secret): mixed
    {
        return $secret ? ['configured' => is_string($value) ? trim($value) !== '' : $value !== null] : $value;
    }
}
