<?php

namespace App\Modules\Domains\Services;

use App\Modules\Dns\Services\DnsReconciler;
use App\Support\AuditLog;
use App\Support\Database;

class DomainVerificationService
{
    /** @var callable(string): array */
    private $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $domain): array {
            $records = @dns_get_record($domain, DNS_NS);
            return array_values(array_filter(array_map(
                static fn (array $record): string => (string) ($record['target'] ?? ''),
                is_array($records) ? $records : []
            )));
        };
    }

    public function verify(string $domainId, bool $reconcile = true): ?array
    {
        $result = $this->verifyWithTrace($domainId, $reconcile);
        return $result === null ? null : $result['domain'];
    }

    public function verifyWithTrace(string $domainId, bool $reconcile = true): ?array
    {
        $domain = (new DomainService())->find($domainId);
        if ($domain === null) {
            return null;
        }

        $resolverErrors = [];
        try {
            $observed = $this->normalize(($this->resolver)((string) $domain['domain']));
        } catch (\Throwable $e) {
            $observed = [];
            $resolverErrors[] = $e->getMessage();
        }
        $expected = $this->normalize(array_column((array) ($domain['nameservers'] ?? []), 'hostname'));
        $matched = array_values(array_intersect($expected, $observed));
        $missing = array_values(array_diff($expected, $matched));
        $status = $matched === [] ? ($observed === [] ? 'not_configured' : 'partial') : (count($matched) === count($expected) ? 'verified' : 'partial');
        $now = time();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $reset = $pdo->prepare('UPDATE domain_nameservers SET observed = false, last_checked_at = :checked WHERE domain_id = :domain_id');
            $reset->execute(['checked' => $now, 'domain_id' => $domainId]);
            if ($matched !== []) {
                $mark = $pdo->prepare('UPDATE domain_nameservers SET observed = true WHERE domain_id = :domain_id AND lower(hostname) = :hostname');
                foreach ($matched as $hostname) {
                    $mark->execute(['domain_id' => $domainId, 'hostname' => $hostname]);
                }
            }
            $lifecycle = $status === 'verified' ? 'active' : 'pending_nameserver';
            $update = $pdo->prepare(
                'UPDATE domains SET nameserver_status = :nameserver_status, status = :status,
                 last_ns_check_at = :checked, updated_at = :checked WHERE id = :id'
            );
            $update->execute([
                'nameserver_status' => $status,
                'status' => $lifecycle,
                'checked' => $now,
                'id' => $domainId,
            ]);
            $pdo->exec('UPDATE config_state SET active_snapshot_version = NULL WHERE id = 1');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        if ($reconcile) {
            (new DnsReconciler())->reconcile();
        }
        $updated = (new DomainService())->find($domainId);
        return [
            'domain' => $updated,
            'verification' => [
                'expected_nameservers' => $expected,
                'observed_nameservers' => $observed,
                'matched_nameservers' => $matched,
                'missing_nameservers' => $missing,
                'checked_at' => $now,
                'status' => $status,
                'resolver_errors' => $resolverErrors,
            ],
        ];
    }

    public function forceVerify(string $domainId, string $reason, string $actor): ?array
    {
        $domain = (new DomainService())->find($domainId);
        if ($domain === null) {
            return null;
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('reason_required');
        }

        $expected = $this->normalize(array_column((array) ($domain['nameservers'] ?? []), 'hostname'));
        $now = time();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $reset = $pdo->prepare('UPDATE domain_nameservers SET observed = true, last_checked_at = :checked WHERE domain_id = :domain_id');
            $reset->execute(['checked' => $now, 'domain_id' => $domainId]);
            $update = $pdo->prepare(
                "UPDATE domains SET nameserver_status = 'verified', status = 'active',
                 last_ns_check_at = :checked, updated_at = :checked WHERE id = :id"
            );
            $update->execute(['checked' => $now, 'id' => $domainId]);
            $pdo->exec('UPDATE config_state SET active_snapshot_version = NULL WHERE id = 1');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        (new DnsReconciler())->reconcile();
        $updated = (new DomainService())->find($domainId);
        AuditLog::write('domain.nameserver.force_verify', 'domain', $domainId, $domainId, $domain, [
            'domain' => $updated,
            'reason' => $reason,
            'forced_verified' => true,
        ], $actor);

        return [
            'domain' => $updated,
            'verification' => [
                'expected_nameservers' => $expected,
                'observed_nameservers' => $expected,
                'matched_nameservers' => $expected,
                'missing_nameservers' => [],
                'checked_at' => $now,
                'status' => 'verified',
                'resolver_errors' => [],
                'forced_verified' => true,
                'reason' => $reason,
            ],
        ];
    }

    public function verifyAll(): array
    {
        $ids = Database::pdo()->query('SELECT id FROM domains ORDER BY id')->fetchAll();
        $results = [];
        foreach ($ids as $row) {
            $domain = $this->verify((string) $row['id'], false);
            if ($domain !== null) {
                $results[] = [
                    'id' => $domain['id'],
                    'domain' => $domain['domain'],
                    'status' => $domain['status'],
                    'nameserver_status' => $domain['nameserver_status'],
                ];
            }
        }
        return ['checked' => count($results), 'domains' => $results];
    }

    private function normalize(array $hostnames): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $hostname): string => strtolower(rtrim(trim((string) $hostname), '.')),
            $hostnames
        ))));
    }
}
