<?php

namespace App\Modules\Proxy\Services;

use App\Support\Database;
use App\Support\Uuid;

class CertRenewalService
{
    public function __construct(
        private ?TrafficRulesService $certificates = null,
        private ?AcmeIssuerService $issuer = null
    ) {
        $this->certificates ??= new TrafficRulesService();
        $this->issuer ??= new AcmeIssuerService($this->certificates);
    }

    public static function isDue(array $certificate, int $now): bool
    {
        return ($certificate['provider'] ?? null) === 'acme'
            && ($certificate['status'] ?? null) !== 'revoked'
            && isset($certificate['renewal_due_at'])
            && (int) $certificate['renewal_due_at'] <= $now + 14 * 86400;
    }

    public function renewDue(?int $now = null): array
    {
        $now ??= time();
        $stmt = Database::pdo()->prepare(
            "SELECT c.* FROM ssl_certificates c
             JOIN domain_ssl_settings s ON s.domain_id=c.domain_id
             WHERE s.auto_renew=true AND c.provider='acme' AND c.status<>'revoked'
               AND c.renewal_due_at IS NOT NULL AND c.renewal_due_at<=:cutoff
             ORDER BY c.renewal_due_at ASC"
        );
        $stmt->execute([':cutoff' => $now + 14 * 86400]);
        $results = [];
        foreach ($stmt->fetchAll() as $certificate) {
            $results[] = $this->issue(
                (string) $certificate['domain_id'],
                [(string) $certificate['hostname']],
                'automatic_renewal'
            );
        }
        return ['checked_at' => $now, 'attempted' => count($results), 'results' => $results];
    }

    public function request(string $domainId, array $hostnames = []): array
    {
        $this->certificates->requestSslCertificate($domainId, $hostnames);
        return $this->issue($domainId, $hostnames, 'issuance');
    }

    public function forceRenew(string $domainId): array
    {
        $rows = $this->certificates->listSslCertificates($domainId);
        $hostnames = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['hostname'],
            array_filter($rows, static fn (array $row): bool => ($row['provider'] ?? null) === 'acme' && ($row['status'] ?? null) !== 'revoked')
        )));
        if ($hostnames === []) {
            throw new \OutOfBoundsException('acme_certificate_not_found');
        }
        return $this->issue($domainId, $hostnames, 'forced_renewal');
    }

    public function status(string $domainId): array
    {
        $certificates = $this->certificates->listSslCertificates($domainId);
        $history = Database::pdo()->prepare(
            'SELECT * FROM ssl_renewal_history WHERE domain_id=:domain_id ORDER BY started_at DESC LIMIT 50'
        );
        $history->execute([':domain_id' => $domainId]);
        return [
            'progress' => array_map(static fn (array $row): array => [
                'certificate_id' => $row['id'],
                'hostname' => $row['hostname'],
                'status' => $row['acme_status'] ?: (($row['status'] ?? '') === 'active' ? 'issued' : 'idle'),
                'error' => $row['last_error'],
                'updated_at' => $row['updated_at'],
            ], $certificates),
            'history' => $history->fetchAll(),
            'jobs' => $this->certificates->listSslJobs($domainId),
        ];
    }

    private function issue(string $domainId, array $hostnames, string $action): array
    {
        $targets = $hostnames;
        if ($targets === []) {
            $domain = Database::pdo()->prepare('SELECT domain FROM domains WHERE id=:id LIMIT 1');
            $domain->execute([':id' => $domainId]);
            $hostname = $domain->fetchColumn();
            if ($hostname === false) {
                throw new \OutOfBoundsException('domain_not_found');
            }
            $targets = [(string) $hostname];
        }

        $now = time();
        $historyIds = [];
        $this->setProgress($domainId, $targets, 'pending_dns', null);
        foreach ($targets as $hostname) {
            $hostname = strtolower(trim((string) $hostname));
            $certificateId = $this->certificateId($domainId, $hostname);
            $historyId = Uuid::v4();
            Database::pdo()->prepare(
                'INSERT INTO ssl_renewal_history (id,certificate_id,domain_id,hostname,action,status,error,started_at,completed_at)
                 VALUES (:id,:certificate_id,:domain_id,:hostname,:action,:status,NULL,:started_at,NULL)'
            )->execute([
                ':id' => $historyId, ':certificate_id' => $certificateId, ':domain_id' => $domainId,
                ':hostname' => $hostname, ':action' => $action, ':status' => 'verifying', ':started_at' => $now,
            ]);
            $historyIds[] = $historyId;
        }
        $this->setProgress($domainId, $targets, 'verifying', null);

        try {
            $rows = $this->issuer->issue($domainId, $targets);
            $this->setProgress($domainId, $targets, 'issued', null);
            $this->finishHistory($historyIds, 'issued', null);
            return ['status' => 'issued', 'certificates' => $rows, 'history_ids' => $historyIds];
        } catch (\Throwable $e) {
            $this->setProgress($domainId, $targets, 'error', $e->getMessage());
            $this->finishHistory($historyIds, 'error', $e->getMessage());
            return ['status' => 'error', 'error' => $e->getMessage(), 'history_ids' => $historyIds];
        }
    }

    private function certificateId(string $domainId, string $hostname): ?string
    {
        $stmt = Database::pdo()->prepare('SELECT id FROM ssl_certificates WHERE domain_id=:domain_id AND hostname=:hostname LIMIT 1');
        $stmt->execute([':domain_id' => $domainId, ':hostname' => $hostname]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (string) $id;
    }

    private function setProgress(string $domainId, array $hostnames, string $status, ?string $error): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE ssl_certificates SET acme_status=:status,status=:certificate_status,last_error=:error,last_checked_at=:now,updated_at=:now
             WHERE domain_id=:domain_id AND hostname=:hostname'
        );
        foreach ($hostnames as $hostname) {
            $stmt->execute([
                ':status' => $status,
                ':certificate_status' => $status === 'error' ? 'error' : ($status === 'issued' ? 'active' : 'pending'),
                ':error' => $error, ':now' => time(), ':domain_id' => $domainId, ':hostname' => strtolower(trim((string) $hostname)),
            ]);
        }
    }

    private function finishHistory(array $ids, string $status, ?string $error): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE ssl_renewal_history SET status=:status,error=:error,completed_at=:completed_at WHERE id=:id'
        );
        foreach ($ids as $id) {
            $stmt->execute([':status' => $status, ':error' => $error, ':completed_at' => time(), ':id' => $id]);
        }
    }
}
