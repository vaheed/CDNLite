<?php

namespace App\Modules\Edge\Services;

use App\Support\Database;
use PDOException;

class EdgeAuthService
{
    private const MAX_CLOCK_SKEW_SECONDS = 120;
    private const NONCE_TTL_SECONDS = 300;

    public function authenticate(string $edgeId, string $token, int $timestamp, string $nonce): array
    {
        if ($edgeId === '' || $token === '' || $nonce === '') {
            return ['ok' => false, 'error' => 'edge_auth_required', 'status' => 401];
        }

        if (abs(time() - $timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
            return ['ok' => false, 'error' => 'edge_auth_timestamp_out_of_range', 'status' => 401];
        }

        $hash = $this->tokenHashByEdgeId($edgeId);
        if ($hash === null || !password_verify($token, $hash)) {
            return ['ok' => false, 'error' => 'edge_auth_invalid_token', 'status' => 401];
        }

        $this->cleanupExpiredNonces();

        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO edge_request_nonces (edge_id, nonce, created_at, expires_at)
                 VALUES (:edge_id, :nonce, :created_at, :expires_at)'
            );
            $now = time();
            $stmt->execute([
                ':edge_id' => $edgeId,
                ':nonce' => $nonce,
                ':created_at' => $now,
                ':expires_at' => $now + self::NONCE_TTL_SECONDS,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'error' => 'edge_auth_replay_detected', 'status' => 409];
            }
            throw $e;
        }

        return ['ok' => true];
    }

    private function tokenHashByEdgeId(string $edgeId): ?string
    {
        $stmt = Database::pdo()->prepare('SELECT token_hash FROM edge_tokens WHERE edge_id = :edge_id LIMIT 1');
        $stmt->execute([':edge_id' => $edgeId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        return (string) $row['token_hash'];
    }

    private function cleanupExpiredNonces(): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM edge_request_nonces WHERE expires_at < :now');
        $stmt->execute([':now' => time()]);
    }
}
