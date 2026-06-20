<?php

namespace App\Modules\Admin\Services;

use App\Support\Database;
use App\Support\Uuid;

class AdminAuthService
{
    public function hasUsers(): bool
    {
        try {
            $stmt = Database::pdo()->query("SELECT COUNT(*) FROM admin_users WHERE status = 'active'");
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function createOrUpdateUser(string $username, string $password, ?string $displayName = null): array
    {
        return $this->upsertUser($username, $password, $displayName, 12);
    }

    public function listUsers(): array
    {
        $now = time();
        $stmt = Database::pdo()->prepare(
            'SELECT u.id, u.username, u.display_name, u.status, u.created_at, u.updated_at,
                    COALESCE(COUNT(s.id) FILTER (WHERE s.revoked_at IS NULL AND s.expires_at > :active_now), 0) AS active_sessions,
                    MAX(s.created_at) AS last_login_at,
                    MAX(s.expires_at) FILTER (WHERE s.revoked_at IS NULL AND s.expires_at > :expiry_now) AS latest_session_expires_at
             FROM admin_users u
             LEFT JOIN admin_sessions s ON s.user_id = u.id
             GROUP BY u.id, u.username, u.display_name, u.status, u.created_at, u.updated_at
             ORDER BY username ASC'
        );
        $stmt->execute(['active_now' => $now, 'expiry_now' => $now]);

        return array_map(
            fn (array $row): array => $this->publicUser(
                (string) $row['id'],
                (string) $row['username'],
                $row['display_name'] === null ? null : (string) $row['display_name'],
                (string) $row['status'],
                (int) $row['created_at'],
                (int) $row['updated_at']
            ) + [
                'active_sessions' => (int) ($row['active_sessions'] ?? 0),
                'last_login_at' => $row['last_login_at'] === null ? null : (int) $row['last_login_at'],
                'latest_session_expires_at' => $row['latest_session_expires_at'] === null ? null : (int) $row['latest_session_expires_at'],
            ],
            $stmt->fetchAll()
        );
    }

    public function changePassword(string $username, string $password): array
    {
        $username = $this->normalizeUsername($username);
        if ($username === '') {
            throw new \InvalidArgumentException('username_required');
        }
        if (strlen($password) < 12) {
            throw new \InvalidArgumentException('password_min_12');
        }

        $existing = $this->findUserByUsername($username);
        if ($existing === null) {
            throw new \InvalidArgumentException('admin_user_not_found');
        }

        $now = time();
        $stmt = Database::pdo()->prepare(
            'UPDATE admin_users
             SET password_hash = :password_hash, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $existing['id'],
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'updated_at' => $now,
        ]);
        $this->revokeSessionsForUser((string) $existing['id']);

        return $this->publicUser(
            (string) $existing['id'],
            (string) $existing['username'],
            $existing['display_name'] === null ? null : (string) $existing['display_name'],
            (string) $existing['status'],
            (int) $existing['created_at'],
            $now
        );
    }

    public function deleteUser(string $username, bool $force = false): array
    {
        $username = $this->normalizeUsername($username);
        if ($username === '') {
            throw new \InvalidArgumentException('username_required');
        }

        $existing = $this->findUserByUsername($username);
        if ($existing === null) {
            throw new \InvalidArgumentException('admin_user_not_found');
        }
        if (!$force && (string) $existing['status'] === 'active' && $this->activeUserCount() <= 1) {
            throw new \InvalidArgumentException('cannot_delete_last_active_admin');
        }

        $this->revokeSessionsForUser((string) $existing['id']);
        $stmt = Database::pdo()->prepare('DELETE FROM admin_users WHERE id = :id');
        $stmt->execute(['id' => $existing['id']]);

        return $this->publicUser(
            (string) $existing['id'],
            (string) $existing['username'],
            $existing['display_name'] === null ? null : (string) $existing['display_name'],
            (string) $existing['status'],
            (int) $existing['created_at'],
            (int) $existing['updated_at']
        );
    }

    public function bootstrapUser(string $username, string $password, ?string $displayName = null): array
    {
        return $this->upsertUser($username, $password, $displayName, 5);
    }

    private function upsertUser(string $username, string $password, ?string $displayName, int $minimumPasswordLength): array
    {
        $username = $this->normalizeUsername($username);
        if ($username === '') {
            throw new \InvalidArgumentException('username_required');
        }
        if (strlen($password) < $minimumPasswordLength) {
            throw new \InvalidArgumentException('password_min_' . $minimumPasswordLength);
        }

        $now = time();
        $existing = $this->findUserByUsername($username);
        $hash = password_hash($password, PASSWORD_DEFAULT);

        if ($existing !== null) {
            $stmt = Database::pdo()->prepare(
                "UPDATE admin_users
                 SET password_hash = :password_hash, display_name = :display_name, status = 'active', updated_at = :updated_at
                 WHERE id = :id"
            );
            $stmt->execute([
                'id' => $existing['id'],
                'password_hash' => $hash,
                'display_name' => $displayName,
                'updated_at' => $now,
            ]);
            return $this->publicUser((string) $existing['id'], $username, $displayName, 'active', (int) $existing['created_at'], $now);
        }

        $id = Uuid::v4();
        $stmt = Database::pdo()->prepare(
            "INSERT INTO admin_users (id, username, password_hash, display_name, status, created_at, updated_at)
             VALUES (:id, :username, :password_hash, :display_name, 'active', :created_at, :updated_at)"
        );
        $stmt->execute([
            'id' => $id,
            'username' => $username,
            'password_hash' => $hash,
            'display_name' => $displayName,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->publicUser($id, $username, $displayName, 'active', $now, $now);
    }

    public function login(string $username, string $password): ?array
    {
        $user = $this->findUserByUsername($username);
        if ($user === null || (string) $user['status'] !== 'active') {
            return null;
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $now = time();
        $expiresAt = $now + (int) (getenv('CDNLITE_ADMIN_SESSION_TTL_SECONDS') ?: 28800);
        $stmt = Database::pdo()->prepare(
            "INSERT INTO admin_sessions (id, user_id, token_hash, created_at, expires_at, revoked_at)
             VALUES (:id, :user_id, :token_hash, :created_at, :expires_at, NULL)"
        );
        $stmt->execute([
            'id' => Uuid::v4(),
            'user_id' => $user['id'],
            'token_hash' => hash('sha256', $token),
            'created_at' => $now,
            'expires_at' => $expiresAt,
        ]);
        $this->deleteExpiredSessions();

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
            'user' => $this->publicUser((string) $user['id'], (string) $user['username'], $user['display_name'] === null ? null : (string) $user['display_name'], (string) $user['status'], (int) $user['created_at'], (int) $user['updated_at']),
        ];
    }

    public function userForToken(?string $token): ?array
    {
        $token = trim((string) ($token ?? ''));
        if ($token === '') {
            return null;
        }

        try {
            $stmt = Database::pdo()->prepare(
                "SELECT u.id, u.username, u.display_name, u.status, u.created_at, u.updated_at, s.expires_at
                 FROM admin_sessions s
                 JOIN admin_users u ON u.id = s.user_id
                 WHERE s.token_hash = :token_hash
                   AND s.revoked_at IS NULL
                   AND s.expires_at > :now
                   AND u.status = 'active'
                 LIMIT 1"
            );
            $stmt->execute(['token_hash' => hash('sha256', $token), 'now' => time()]);
            $row = $stmt->fetch();
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($row)) {
            return null;
        }

        return $this->publicUser((string) $row['id'], (string) $row['username'], $row['display_name'] === null ? null : (string) $row['display_name'], (string) $row['status'], (int) $row['created_at'], (int) $row['updated_at']) + ['session_expires_at' => (int) $row['expires_at']];
    }

    public function revokeToken(?string $token): bool
    {
        $token = trim((string) ($token ?? ''));
        if ($token === '') {
            return false;
        }
        $stmt = Database::pdo()->prepare('UPDATE admin_sessions SET revoked_at = :revoked_at WHERE token_hash = :token_hash AND revoked_at IS NULL');
        $stmt->execute(['revoked_at' => time(), 'token_hash' => hash('sha256', $token)]);
        return $stmt->rowCount() > 0;
    }

    private function findUserByUsername(string $username): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM admin_users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $this->normalizeUsername($username)]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function normalizeUsername(string $username): string
    {
        return strtolower(trim($username));
    }

    private function deleteExpiredSessions(): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM admin_sessions WHERE expires_at <= :now OR revoked_at IS NOT NULL');
        $stmt->execute(['now' => time()]);
    }

    private function revokeSessionsForUser(string $userId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE admin_sessions
             SET revoked_at = :revoked_at
             WHERE user_id = :user_id AND revoked_at IS NULL'
        );
        $stmt->execute(['revoked_at' => time(), 'user_id' => $userId]);
    }

    private function activeUserCount(): int
    {
        $stmt = Database::pdo()->query("SELECT COUNT(*) FROM admin_users WHERE status = 'active'");
        return (int) $stmt->fetchColumn();
    }

    private function publicUser(string $id, string $username, ?string $displayName, string $status, int $createdAt, int $updatedAt): array
    {
        return [
            'id' => $id,
            'username' => $username,
            'display_name' => $displayName,
            'status' => $status,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }
}
