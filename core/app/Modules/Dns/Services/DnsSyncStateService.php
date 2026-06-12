<?php

namespace App\Modules\Dns\Services;

use App\Support\Database;

class DnsSyncStateService
{
    public function begin(string $zone, array $rrsets, string $action): string
    {
        $zone = $this->zone($zone);
        $hash = $this->hash($rrsets);
        $now = time();
        Database::pdo()->prepare(
            "INSERT INTO dns_sync_state (
                zone_name, desired_hash, status, last_attempt_at, pending_changes, in_progress, updated_at
             ) VALUES (:zone, :hash, 'syncing', :now, :pending, true, :now)
             ON CONFLICT (zone_name) DO UPDATE SET
                desired_hash = EXCLUDED.desired_hash,
                status = 'syncing',
                last_attempt_at = EXCLUDED.last_attempt_at,
                last_error = NULL,
                pending_changes = EXCLUDED.pending_changes,
                in_progress = true,
                updated_at = EXCLUDED.updated_at"
        )->execute([
            ':zone' => $zone,
            ':hash' => $hash,
            ':now' => $now,
            ':pending' => count($rrsets),
        ]);
        $this->event($zone, $rrsets, $action, 'attempt', null, null, $hash, null);
        return $hash;
    }

    public function finish(string $zone, array $rrsets, string $action, string $desiredHash, array $result): void
    {
        $zone = $this->zone($zone);
        $ok = ($result['ok'] ?? false) === true;
        $status = (int) ($result['status'] ?? 0);
        $error = $ok ? null : (string) ($result['error'] ?? 'powerdns_sync_failed');
        $appliedHash = $ok ? $desiredHash : null;
        $now = time();
        Database::pdo()->prepare(
            "UPDATE dns_sync_state SET
                applied_hash = CASE WHEN :ok THEN :applied_hash ELSE applied_hash END,
                status = :status,
                last_success_at = CASE WHEN :ok THEN :now ELSE last_success_at END,
                last_error = :error,
                last_status_code = :status_code,
                pending_changes = CASE WHEN :ok THEN 0 ELSE pending_changes END,
                in_progress = false,
                updated_at = :now
             WHERE zone_name = :zone"
        )->execute([
            ':ok' => $ok,
            ':applied_hash' => $appliedHash,
            ':status' => $ok ? 'ok' : 'failed',
            ':now' => $now,
            ':error' => $error,
            ':status_code' => $status,
            ':zone' => $zone,
        ]);
        $this->event($zone, $rrsets, $action, $ok ? 'success' : 'failed', $status, $error, $desiredHash, $appliedHash);
    }

    public function summary(): array
    {
        $rows = Database::pdo()->query(
            'SELECT zone_name, desired_hash, applied_hash, status, last_attempt_at, last_success_at,
                    last_error, last_status_code, pending_changes, in_progress, updated_at
             FROM dns_sync_state ORDER BY zone_name'
        )->fetchAll();
        $failed = count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'failed'));
        $syncing = count(array_filter($rows, static fn (array $row): bool => (bool) $row['in_progress']));
        return [
            'status' => $failed > 0 ? 'failed' : ($syncing > 0 ? 'syncing' : ($rows === [] ? 'unknown' : 'ok')),
            'zones' => $rows,
            'failed_zones' => $failed,
            'syncing_zones' => $syncing,
        ];
    }

    private function event(
        string $zone,
        array $rrsets,
        string $action,
        string $status,
        ?int $statusCode,
        ?string $error,
        ?string $desiredHash,
        ?string $appliedHash
    ): void {
        $first = $rrsets[0] ?? [];
        Database::pdo()->prepare(
            'INSERT INTO dns_sync_events (
                zone_name, rrset_name, rrset_type, action, status, status_code,
                error, desired_hash, applied_hash, created_at
             ) VALUES (
                :zone, :name, :type, :action, :status, :status_code,
                :error, :desired_hash, :applied_hash, :created_at
             )'
        )->execute([
            ':zone' => $zone,
            ':name' => $first['name'] ?? null,
            ':type' => $first['type'] ?? null,
            ':action' => $action,
            ':status' => $status,
            ':status_code' => $statusCode,
            ':error' => $error,
            ':desired_hash' => $desiredHash,
            ':applied_hash' => $appliedHash,
            ':created_at' => time(),
        ]);
    }

    private function hash(array $rrsets): string
    {
        return hash('sha256', json_encode($rrsets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]');
    }

    private function zone(string $zone): string
    {
        return rtrim(strtolower(trim($zone)), '.') . '.';
    }
}
