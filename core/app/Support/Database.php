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

        self::ensureColumn($pdo, 'sites', 'geo_origins_json', 'TEXT NULL');
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
}
