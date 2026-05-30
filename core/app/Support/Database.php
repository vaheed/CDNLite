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

        $driver = getenv('DB_DRIVER') ?: getenv('DB_CONNECTION') ?: 'pgsql';
        if ($driver === 'sqlite') {
            $dbPath = getenv('DB_DATABASE') ?: '/tmp/cdnlite-test.sqlite';
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            self::$pdo = new PDO('sqlite:' . $dbPath);
        } else {
            $host = getenv('DB_HOST') ?: 'postgres';
            $port = (int) (getenv('DB_PORT') ?: 5432);
            $database = getenv('DB_DATABASE') ?: 'cdnlite';
            $username = getenv('DB_USERNAME') ?: 'cdnlite';
            $password = getenv('DB_PASSWORD') ?: 'cdnlite';
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database);
            self::$pdo = new PDO($dsn, $username, $password);
        }
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        self::migrate(self::$pdo, $driver);

        return self::$pdo;
    }

    private static function migrate(PDO $pdo, string $driver): void
    {
        $schemaFile = $driver === 'sqlite'
            ? __DIR__ . '/../../database/schema.sql'
            : __DIR__ . '/../../database/schema.pgsql.sql';
        $schema = file_get_contents($schemaFile);
        if ($schema !== false) {
            $pdo->exec($schema);
        }
    }
}
