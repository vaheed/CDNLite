<?php

namespace App\Support;

use PDO;

class DatabaseWorkload
{
    public const CONTROL = 'control';
    public const TELEMETRY_INGEST = 'telemetry_ingest';
    public const REPORTING = 'reporting';
    public const JOBS = 'jobs';
    public const MAINTENANCE = 'maintenance';

    private const FALLBACKS = [
        self::CONTROL => ['statement_timeout_ms' => 5000, 'lock_timeout_ms' => 1000, 'max_query_range_seconds' => null, 'max_result_rows' => 500],
        self::TELEMETRY_INGEST => ['statement_timeout_ms' => 10000, 'lock_timeout_ms' => 1000, 'max_query_range_seconds' => 86400, 'max_result_rows' => 1000],
        self::REPORTING => ['statement_timeout_ms' => 3000, 'lock_timeout_ms' => 500, 'max_query_range_seconds' => 31622400, 'max_result_rows' => 1000],
        self::JOBS => ['statement_timeout_ms' => 30000, 'lock_timeout_ms' => 2000, 'max_query_range_seconds' => 2592000, 'max_result_rows' => 5000],
        self::MAINTENANCE => ['statement_timeout_ms' => 120000, 'lock_timeout_ms' => 5000, 'max_query_range_seconds' => 31622400, 'max_result_rows' => 10000],
    ];

    public static function budget(string $workload): array
    {
        $fallback = self::FALLBACKS[$workload] ?? self::FALLBACKS[self::CONTROL];
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT statement_timeout_ms, lock_timeout_ms, max_query_range_seconds, max_result_rows
                 FROM database_workload_budgets WHERE workload = :workload LIMIT 1'
            );
            $stmt->execute([':workload' => $workload]);
            $row = $stmt->fetch();
            if ($row === false) {
                return $fallback;
            }
            return [
                'statement_timeout_ms' => (int) $row['statement_timeout_ms'],
                'lock_timeout_ms' => (int) $row['lock_timeout_ms'],
                'max_query_range_seconds' => $row['max_query_range_seconds'] === null ? null : (int) $row['max_query_range_seconds'],
                'max_result_rows' => $row['max_result_rows'] === null ? null : (int) $row['max_result_rows'],
            ];
        } catch (\Throwable) {
            return $fallback;
        }
    }

    public static function apply(PDO $pdo, string $workload): void
    {
        $budget = self::budget($workload);
        $statementTimeout = (int) $budget['statement_timeout_ms'];
        $lockTimeout = (int) $budget['lock_timeout_ms'];
        $pdo->exec('SET statement_timeout = ' . $statementTimeout);
        $pdo->exec('SET lock_timeout = ' . $lockTimeout);
    }
}
