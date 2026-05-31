<?php

namespace App\Modules\Edge\Services;

use App\Support\Database;
use App\Support\Uuid;
use PDOException;

class EdgeAuthService
{
    private const MAX_CLOCK_SKEW_SECONDS = 120;
    private const NONCE_TTL_SECONDS = 300;

    public function authenticate(
        string $edgeId,
        string $token,
        int $timestamp,
        string $nonce,
        string $method,
        string $path,
        string $bodyRaw,
        string $signature
    ): array
    {
        if ($edgeId === '' || $token === '' || $nonce === '' || $signature === '') {
            return ['ok' => false, 'error' => 'edge_auth_required', 'status' => 401];
        }

        if (abs(time() - $timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
            return ['ok' => false, 'error' => 'edge_auth_timestamp_out_of_range', 'status' => 401];
        }

        $hash = $this->tokenHashByEdgeId($edgeId);
        if ($hash === null || !password_verify($token, $hash)) {
            return ['ok' => false, 'error' => 'edge_auth_invalid_token', 'status' => 401];
        }

        if (!$this->isValidSignature($token, $method, $path, $timestamp, $nonce, $bodyRaw, $signature)) {
            return ['ok' => false, 'error' => 'edge_auth_invalid_signature', 'status' => 401];
        }

        $this->cleanupExpiredNonces();

        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO edge_request_nonces (id, edge_id, nonce, created_at, expires_at)
                 VALUES (:id, :edge_id, :nonce, :created_at, :expires_at)'
            );
            $now = time();
            $stmt->execute([
                ':id' => Uuid::v4(),
                ':edge_id' => $edgeId,
                ':nonce' => $nonce,
                ':created_at' => $now,
                ':expires_at' => $now + self::NONCE_TTL_SECONDS,
            ]);
        } catch (PDOException $e) {
            $sqlState = (string) $e->getCode();
            if ($sqlState === '23000' || $sqlState === '23505') {
                return ['ok' => false, 'error' => 'edge_auth_replay_detected', 'status' => 409];
            }
            throw $e;
        }

        return ['ok' => true];
    }

    private function isValidSignature(
        string $token,
        string $method,
        string $path,
        int $timestamp,
        string $nonce,
        string $bodyRaw,
        string $signature
    ): bool {
        $bodyHash = hash('sha256', $bodyRaw);
        $canonical = strtoupper($method) . "\n"
            . $path . "\n"
            . $timestamp . "\n"
            . $nonce . "\n"
            . $bodyHash;
        $expected = hash_hmac('sha256', $canonical, hash('sha256', $token));
        return hash_equals($expected, strtolower($signature));
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
