<?php

namespace App\Console\Commands;

use App\Support\Database;

class CdnMigrateCommand
{
    public function __invoke(array $argv): int
    {
        $db = Database::pdo();
        $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at BIGINT NOT NULL)');
        $db->beginTransaction();
        try {
            $migrationDir = dirname(__DIR__, 3) . '/database/migrations';
            $files = glob($migrationDir . '/*.sql');
            sort($files);
            $applied = 0;

            foreach ($files as $file) {
                $version = basename($file);
                $stmt = $db->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
                $stmt->execute(['version' => $version]);
                if ($stmt->fetchColumn()) {
                    continue;
                }

                $sql = file_get_contents($file);
                if ($sql === false) {
                    throw new \RuntimeException('Failed to read migration: ' . $version);
                }

                $executableSql = preg_replace('/^\s*--.*$/m', '', $sql);
                if (trim((string) $executableSql) !== '') {
                    try {
                        $db->exec($sql);
                    } catch (\Throwable $e) {
                        $detail = trim($e->getMessage());
                        throw new \RuntimeException(
                            'Migration failed: ' . $version . ($detail === '' ? '' : ' - ' . $detail),
                            0,
                            $e
                        );
                    }
                }
                $insert = $db->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)');
                $insert->execute([
                    'version' => $version,
                    'applied_at' => time(),
                ]);
                $applied++;
            }

            $db->commit();
            fwrite(STDOUT, json_encode(['ok' => true, 'applied' => $applied]) . PHP_EOL);
            return 0;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            return 1;
        }
    }
}
