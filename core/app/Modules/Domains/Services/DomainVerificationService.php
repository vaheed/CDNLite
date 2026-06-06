<?php

namespace App\Modules\Domains\Services;

use App\Support\Database;

class DomainVerificationService
{
    /** @var callable(string): array */
    private $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $domain): array {
            $records = dns_get_record($domain, DNS_NS);
            return array_values(array_filter(array_map(
                static fn (array $record): string => (string) ($record['target'] ?? ''),
                is_array($records) ? $records : []
            )));
        };
    }

    public function verify(string $domainId): ?array
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
            $update = $pdo->prepare('UPDATE domains SET nameserver_status = :status, last_ns_check_at = :checked, updated_at = :checked WHERE id = :id');
            $update->execute(['status' => $status, 'checked' => $now, 'id' => $domainId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return (new DomainService())->find($domainId);
    }

    private function normalize(array $hostnames): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $hostname): string => strtolower(rtrim(trim((string) $hostname), '.')),
            $hostnames
        ))));
    }
}
