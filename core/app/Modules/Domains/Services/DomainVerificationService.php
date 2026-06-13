<?php

namespace App\Modules\Domains\Services;

use App\Modules\Dns\Services\DnsReconciler;
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
        $domain = (new DomainService())->find($domainId);
        if ($domain === null) {
            return null;
        }

        $observed = $this->normalize(($this->resolver)((string) $domain['domain']));
        $expected = $this->normalize(array_column((array) ($domain['nameservers'] ?? []), 'hostname'));
        $matched = array_values(array_intersect($expected, $observed));
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
        return (new DomainService())->find($domainId);
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
