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

        return self::$pdo;
    }

    public static function installFreshSchema(): void
    {
        DatabaseMigrator::default()->migrate();
    }
}
