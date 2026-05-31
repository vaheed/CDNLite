<?php

namespace App\Support;

use PDO;

class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $host = getenv('DB_HOST') ?: 'postgres';
        $port = (int) (getenv('DB_PORT') ?: 5432);
        $database = getenv('DB_DATABASE') ?: 'cdnlite';
        $username = getenv('DB_USERNAME') ?: 'cdnlite';
        $password = getenv('DB_PASSWORD') ?: 'cdnlite';
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database);
        self::$pdo = new PDO($dsn, $username, $password);
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        self::migrate(self::$pdo);

        return self::$pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $schema = file_get_contents(__DIR__ . '/../../database/schema.sql');
        if ($schema !== false) {
            $pdo->exec($schema);
        }

        self::migrateLegacyIntegerIdsToText($pdo);
        self::migrateSiteUserIdToUuidText($pdo);
        self::ensureColumn($pdo, 'sites', 'geo_origins_json', 'TEXT NULL');
        self::ensureUniqueIndex($pdo, 'sites', 'idx_sites_domain_unique', 'domain');
    }

    private static function migrateLegacyIntegerIdsToText(PDO $pdo): void
    {
        $siteIdType = self::columnType($pdo, 'sites', 'id');
        if ($siteIdType !== 'bigint' && $siteIdType !== 'integer') {
            return;
        }

        $pdo->beginTransaction();
        try {
            $pdo->exec('ALTER TABLE dns_records DROP CONSTRAINT IF EXISTS dns_records_site_id_fkey');
            $pdo->exec('ALTER TABLE usage_rollups DROP CONSTRAINT IF EXISTS usage_rollups_site_id_fkey');
            $pdo->exec('ALTER TABLE usage_aggregates DROP CONSTRAINT IF EXISTS usage_aggregates_site_id_fkey');

            $pdo->exec('ALTER TABLE sites ALTER COLUMN id TYPE TEXT USING id::text');
            $pdo->exec('ALTER TABLE dns_records ALTER COLUMN id TYPE TEXT USING id::text');
            $pdo->exec('ALTER TABLE dns_records ALTER COLUMN site_id TYPE TEXT USING site_id::text');
            $pdo->exec('ALTER TABLE edge_nodes ALTER COLUMN id TYPE TEXT USING id::text');
            $pdo->exec('ALTER TABLE edge_request_nonces ALTER COLUMN id TYPE TEXT USING id::text');
            $pdo->exec('ALTER TABLE usage_rollups ALTER COLUMN id TYPE TEXT USING id::text');
            $pdo->exec('ALTER TABLE usage_rollups ALTER COLUMN site_id TYPE TEXT USING site_id::text');
            $pdo->exec('ALTER TABLE usage_aggregates ALTER COLUMN id TYPE TEXT USING id::text');
            $pdo->exec('ALTER TABLE usage_aggregates ALTER COLUMN site_id TYPE TEXT USING site_id::text');

            $pdo->exec('ALTER TABLE dns_records ADD CONSTRAINT dns_records_site_id_fkey FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE');
            $pdo->exec('ALTER TABLE usage_rollups ADD CONSTRAINT usage_rollups_site_id_fkey FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE');
            $pdo->exec('ALTER TABLE usage_aggregates ADD CONSTRAINT usage_aggregates_site_id_fkey FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function columnType(PDO $pdo, string $table, string $column): ?string
    {
        $stmt = $pdo->prepare(
            'SELECT data_type FROM information_schema.columns WHERE table_name = :table_name AND column_name = :column_name LIMIT 1'
        );
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return strtolower((string) $row['data_type']);
    }

    private static function migrateSiteUserIdToUuidText(PDO $pdo): void
    {
        $userIdType = self::columnType($pdo, 'sites', 'user_id');
        if ($userIdType === null) {
            return;
        }

        if ($userIdType === 'bigint' || $userIdType === 'integer') {
            $pdo->exec('ALTER TABLE sites ALTER COLUMN user_id TYPE TEXT USING user_id::text');
        }

        $stmt = $pdo->query('SELECT DISTINCT user_id FROM sites');
        $rows = $stmt->fetchAll();
        $update = $pdo->prepare('UPDATE sites SET user_id = :new_user_id WHERE user_id = :old_user_id');
        foreach ($rows as $row) {
            $old = (string) ($row['user_id'] ?? '');
            if ($old === '' || self::isUuid($old)) {
                continue;
            }
            $update->execute([
                ':new_user_id' => Uuid::v4(),
                ':old_user_id' => $old,
            ]);
        }
    }

    private static function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns WHERE table_name = :table_name AND column_name = :column_name LIMIT 1'
        );
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);
        if ($stmt->fetch() !== false) {
            return;
        }

        $pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }

    private static function ensureUniqueIndex(PDO $pdo, string $table, string $indexName, string $column): void
    {
        $stmt = $pdo->prepare('SELECT 1 FROM pg_indexes WHERE tablename = :table_name AND indexname = :index_name LIMIT 1');
        $stmt->execute([
            ':table_name' => $table,
            ':index_name' => $indexName,
        ]);
        if ($stmt->fetch() !== false) {
            return;
        }

        $pdo->exec(sprintf('CREATE UNIQUE INDEX %s ON %s (%s)', $indexName, $table, $column));
    }
}
