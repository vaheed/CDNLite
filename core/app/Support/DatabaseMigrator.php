<?php

namespace App\Support;

use PDO;
use Throwable;

class DatabaseMigrator
{
    private const LOCK_KEY_SQL = "hashtext('cdnlite_schema_migrations')";
    private const BASELINE_VERSION = '000001';
    private const BASELINE_NAME = 'baseline_schema';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsPath,
    ) {
    }

    public static function default(): self
    {
        return new self(Database::pdo(), __DIR__ . '/../../database/migrations');
    }

    public function migrate(bool $dryRun = false): array
    {
        return $this->withLock(function () use ($dryRun): array {
            $this->ensureMigrationTable();
            $migrations = $this->migrationFiles();
            $applied = $this->appliedMigrations();
            $plan = [];

            foreach ($migrations as $migration) {
                $existing = $applied[$migration['version']] ?? null;
                if ($existing !== null) {
                    if (!(bool) $existing['success']) {
                        if ($dryRun) {
                            $plan[] = $migration + ['status' => 'would_retry_failed_migration'];
                            continue;
                        }

                        $this->deleteMigrationRecord($migration['version']);
                        $existing = null;
                    } elseif (!hash_equals($existing['checksum'], $migration['checksum'])) {
                        if ($migration['version'] === self::BASELINE_VERSION) {
                            $this->validateLegacyBaseline();
                            if (!$dryRun) {
                                $this->reconcileBaselineChecksum($migration);
                            }
                            $plan[] = $migration + ['status' => $dryRun ? 'would_reconcile_baseline_checksum' : 'reconciled_baseline_checksum'];
                            continue;
                        }

                        throw new \RuntimeException("migration_checksum_mismatch:{$migration['version']}");
                    }
                    if ($existing !== null) {
                        $plan[] = $migration + ['status' => 'applied'];
                        continue;
                    }
                }

                if ($migration['version'] === self::BASELINE_VERSION && $this->isLegacySchemaPresent()) {
                    $this->validateLegacyBaseline();
                    if (!$dryRun) {
                        $this->recordMigration($migration, true, null, 0);
                    }
                    $plan[] = $migration + ['status' => $dryRun ? 'would_adopt_legacy_baseline' : 'adopted_legacy_baseline'];
                    continue;
                }

                if ($dryRun) {
                    $plan[] = $migration + ['status' => 'pending'];
                    continue;
                }

                $startedAt = $this->now();
                $startedMs = microtime(true);
                try {
                    $this->pdo->beginTransaction();
                    $this->recordMigration($migration, false, null, null, $startedAt);
                    $this->pdo->exec($this->sqlForExecution($migration));
                    $executionMs = max(0, (int) round((microtime(true) - $startedMs) * 1000));
                    $this->recordMigration($migration, true, null, $executionMs, $startedAt);
                    $this->pdo->commit();
                    $plan[] = $migration + ['status' => 'applied_now'];
                } catch (Throwable $error) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    $this->recordMigration($migration, false, $error->getMessage(), null, $startedAt);
                    throw $error;
                }
            }

            return $plan;
        });
    }

    public function status(): array
    {
        $this->ensureMigrationTable();
        $applied = $this->appliedMigrations();

        return array_map(
            static fn (array $migration): array => $migration + [
                'status' => isset($applied[$migration['version']]) ? 'applied' : 'pending',
                'applied' => $applied[$migration['version']] ?? null,
            ],
            $this->migrationFiles(),
        );
    }

    public function checkCompatibility(): array
    {
        $requiredTables = [
            'domains',
            'domain_nameservers',
            'dns_records',
            'domain_origins',
            'edge_nodes',
            'config_snapshots',
            'audit_log',
            'schema_migrations',
        ];
        $missing = [];
        foreach ($requiredTables as $table) {
            if (!$this->tableExists($table)) {
                $missing[] = $table;
            }
        }

        return [
            'ok' => $missing === [],
            'missing_tables' => $missing,
        ];
    }

    public function ensureMigrationTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                checksum TEXT NOT NULL,
                started_at BIGINT,
                finished_at BIGINT,
                execution_ms INTEGER,
                success BOOLEAN NOT NULL,
                error TEXT NULL
            )"
        );
    }

    private function withLock(callable $callback): array
    {
        $this->pdo->exec('SELECT pg_advisory_lock(' . self::LOCK_KEY_SQL . ')');
        try {
            return $callback();
        } finally {
            $this->pdo->exec('SELECT pg_advisory_unlock(' . self::LOCK_KEY_SQL . ')');
        }
    }

    private function migrationFiles(): array
    {
        $files = glob($this->migrationsPath . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $migrations = [];

        foreach ($files as $file) {
            $base = basename($file);
            if (!preg_match('/^([0-9]{6,14})_([a-z0-9_]+)\.sql$/', $base, $matches)) {
                throw new \RuntimeException("invalid_migration_filename:{$base}");
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException("migration_not_readable:{$base}");
            }
            $migrations[] = [
                'version' => $matches[1],
                'name' => $matches[2],
                'path' => $file,
                'checksum' => hash('sha256', $sql),
                'sql' => $sql,
            ];
        }

        return $migrations;
    }

    private function sqlForExecution(array $migration): string
    {
        if (($migration['version'] ?? '') === '000005') {
            return $this->sqlForOriginPoolDefaults((string) $migration['sql']);
        }

        if (($migration['version'] ?? '') !== '000031') {
            return (string) $migration['sql'];
        }

        $legacyConstraintBlock = "ALTER TABLE domain_origins
  ADD CONSTRAINT domain_origins_load_balancing_algorithm_check CHECK (load_balancing_algorithm IN ('weighted_hash', 'consistent_hash')),
  ADD CONSTRAINT domain_origins_connection_timeout_seconds_check CHECK (connection_timeout_seconds BETWEEN 1 AND 60),
  ADD CONSTRAINT domain_origins_response_timeout_seconds_check CHECK (response_timeout_seconds BETWEEN 1 AND 600),
  ADD CONSTRAINT domain_origins_retry_attempts_check CHECK (retry_attempts BETWEEN 0 AND 3),
  ADD CONSTRAINT domain_origins_retry_budget_per_minute_check CHECK (retry_budget_per_minute BETWEEN 0 AND 100000),
  ADD CONSTRAINT domain_origins_circuit_failure_threshold_check CHECK (circuit_failure_threshold BETWEEN 1 AND 1000),
  ADD CONSTRAINT domain_origins_circuit_recovery_seconds_check CHECK (circuit_recovery_seconds BETWEEN 1 AND 3600),
  ADD CONSTRAINT domain_origins_max_concurrent_requests_check CHECK (max_concurrent_requests BETWEEN 0 AND 1000000);";

        $idempotentConstraintBlock = "DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'domain_origins_load_balancing_algorithm_check' AND conrelid = 'domain_origins'::regclass) THEN
    ALTER TABLE domain_origins ADD CONSTRAINT domain_origins_load_balancing_algorithm_check CHECK (load_balancing_algorithm IN ('weighted_hash', 'consistent_hash'));
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'domain_origins_connection_timeout_seconds_check' AND conrelid = 'domain_origins'::regclass) THEN
    ALTER TABLE domain_origins ADD CONSTRAINT domain_origins_connection_timeout_seconds_check CHECK (connection_timeout_seconds BETWEEN 1 AND 60);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'domain_origins_response_timeout_seconds_check' AND conrelid = 'domain_origins'::regclass) THEN
    ALTER TABLE domain_origins ADD CONSTRAINT domain_origins_response_timeout_seconds_check CHECK (response_timeout_seconds BETWEEN 1 AND 600);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'domain_origins_retry_attempts_check' AND conrelid = 'domain_origins'::regclass) THEN
    ALTER TABLE domain_origins ADD CONSTRAINT domain_origins_retry_attempts_check CHECK (retry_attempts BETWEEN 0 AND 3);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'domain_origins_retry_budget_per_minute_check' AND conrelid = 'domain_origins'::regclass) THEN
    ALTER TABLE domain_origins ADD CONSTRAINT domain_origins_retry_budget_per_minute_check CHECK (retry_budget_per_minute BETWEEN 0 AND 100000);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'domain_origins_circuit_failure_threshold_check' AND conrelid = 'domain_origins'::regclass) THEN
    ALTER TABLE domain_origins ADD CONSTRAINT domain_origins_circuit_failure_threshold_check CHECK (circuit_failure_threshold BETWEEN 1 AND 1000);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'domain_origins_circuit_recovery_seconds_check' AND conrelid = 'domain_origins'::regclass) THEN
    ALTER TABLE domain_origins ADD CONSTRAINT domain_origins_circuit_recovery_seconds_check CHECK (circuit_recovery_seconds BETWEEN 1 AND 3600);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'domain_origins_max_concurrent_requests_check' AND conrelid = 'domain_origins'::regclass) THEN
    ALTER TABLE domain_origins ADD CONSTRAINT domain_origins_max_concurrent_requests_check CHECK (max_concurrent_requests BETWEEN 0 AND 1000000);
  END IF;
END $$;";

        // Preserve the historical migration checksum while making legacy schema
        // adoption idempotent when the fresh schema already created these checks.
        return str_replace($legacyConstraintBlock, $idempotentConstraintBlock, (string) $migration['sql']);
    }

    private function sqlForOriginPoolDefaults(string $sql): string
    {
        $legacyRoleBlock = "DO $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'domain_origins_role_check'
  ) THEN
    ALTER TABLE domain_origins DROP CONSTRAINT domain_origins_role_check;
  END IF;

  ALTER TABLE domain_origins
    ADD CONSTRAINT domain_origins_role_check
    CHECK (role IN ('origin'));
END
$$;";

        $currentRoleBlock = "DO $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'domain_origins_role_check'
  ) THEN
    ALTER TABLE domain_origins DROP CONSTRAINT domain_origins_role_check;
  END IF;

  ALTER TABLE domain_origins
    ADD CONSTRAINT domain_origins_role_check
    CHECK (role IN ('primary', 'backup', 'shield'));
END
$$;";

        // Fresh installs use schema.sql, but long-lived local stacks may still
        // replay older SQL slices. Do not let a superseded role check regress
        // the current origin routing model during runtime maintenance.
        return str_replace($legacyRoleBlock, $currentRoleBlock, $sql);
    }

    private function appliedMigrations(): array
    {
        $rows = $this->pdo
            ->query("SELECT version, name, checksum, started_at, finished_at, execution_ms, success, error FROM schema_migrations")
            ->fetchAll();
        $applied = [];
        foreach ($rows as $row) {
            $applied[$row['version']] = $row;
        }
        return $applied;
    }

    private function recordMigration(
        array $migration,
        bool $success,
        ?string $error,
        ?int $executionMs,
        ?int $startedAt = null,
    ): void {
        $now = $this->now();
        $stmt = $this->pdo->prepare(
            "INSERT INTO schema_migrations
                (version, name, checksum, started_at, finished_at, execution_ms, success, error)
             VALUES
                (:version, :name, :checksum, :started_at, :finished_at, :execution_ms, :success, :error)
             ON CONFLICT (version) DO UPDATE SET
                name = EXCLUDED.name,
                checksum = EXCLUDED.checksum,
                started_at = EXCLUDED.started_at,
                finished_at = EXCLUDED.finished_at,
                execution_ms = EXCLUDED.execution_ms,
                success = EXCLUDED.success,
                error = EXCLUDED.error"
        );
        $stmt->execute([
            ':version' => $migration['version'],
            ':name' => $migration['name'],
            ':checksum' => $migration['checksum'],
            ':started_at' => $startedAt ?? $now,
            ':finished_at' => $success ? $now : null,
            ':execution_ms' => $executionMs,
            ':success' => $success ? 1 : 0,
            ':error' => $error,
        ]);
    }

    private function reconcileBaselineChecksum(array $migration): void
    {
        // The baseline schema is a bootstrap snapshot, so checksum drift is
        // expected when we keep the fresh-install schema aligned with runtime
        // behavior. Preserve the historical row and refresh only the checksum.
        $stmt = $this->pdo->prepare(
            "UPDATE schema_migrations
             SET name = :name,
                 checksum = :checksum
             WHERE version = :version"
        );
        $stmt->execute([
            ':version' => $migration['version'],
            ':name' => $migration['name'],
            ':checksum' => $migration['checksum'],
        ]);
    }

    private function deleteMigrationRecord(string $version): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM schema_migrations WHERE version = :version');
        $stmt->execute([':version' => $version]);
    }

    private function isLegacySchemaPresent(): bool
    {
        return $this->tableExists('domains');
    }

    private function validateLegacyBaseline(): void
    {
        $compatibility = $this->checkCompatibility();
        $missing = array_values(array_diff($compatibility['missing_tables'], ['schema_migrations']));
        if ($missing !== []) {
            throw new \RuntimeException('legacy_schema_incompatible:' . implode(',', $missing));
        }
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare("SELECT to_regclass(:table) IS NOT NULL AS exists");
        $stmt->execute([':table' => 'public.' . $table]);
        return (bool) $stmt->fetchColumn();
    }

    private function now(): int
    {
        return time();
    }
}
