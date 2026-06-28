<?php

namespace App\Services\ControlPlane;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class DomainNameserverVerifier
{
    /** @var callable(string): array */
    private $resolver;

    public function __construct(
        private DomainLifecycleService $domains,
        private AuditWriter $audit,
        ?callable $resolver = null,
    ) {
        $this->resolver = $resolver ?? static function (string $domain): array {
            $records = @dns_get_record($domain, DNS_NS);

            return array_values(array_filter(array_map(
                static fn (array $record): string => (string) ($record['target'] ?? ''),
                is_array($records) ? $records : []
            )));
        };
    }

    public function verify(string $domainId): ?array
    {
        $domain = $this->domains->find($domainId);
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

        return $this->applyVerification($domain, $observed, $resolverErrors);
    }

    public function forceVerify(string $domainId, string $reason, ?array $actor = null): ?array
    {
        $domain = $this->domains->find($domainId);
        if ($domain === null) {
            return null;
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('reason_required');
        }

        $expected = $this->expected($domain);
        $now = UnixTime::now();
        DB::transaction(function () use ($domainId, $now): void {
            DB::table('domain_nameservers')->where('domain_id', $domainId)->update([
                'observed' => true,
                'last_checked_at' => $now,
            ]);
            DB::table('domains')->where('id', $domainId)->update([
                'nameserver_status' => 'verified',
                'status' => 'active',
                'last_ns_check_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $updated = $this->domains->find($domainId);
        $verification = [
            'expected_nameservers' => $expected,
            'observed_nameservers' => $expected,
            'matched_nameservers' => $expected,
            'missing_nameservers' => [],
            'checked_at' => $now,
            'status' => 'verified',
            'resolver_errors' => [],
            'forced_verified' => true,
            'reason' => $reason,
        ];
        $this->audit->write('domain.nameserver.force_verify', 'domain', $domainId, $domain, $updated, 'admin', $actor['id'] ?? null, $domainId, [
            'reason' => $reason,
            'forced_verified' => true,
        ]);
        $this->domains->afterDomainMutation($domainId, 'domain.verification.changed');

        return ['domain' => $updated, 'verification' => $verification];
    }

    public function reseedExpected(string $domainId, ?array $actor = null): ?array
    {
        $domain = $this->domains->find($domainId);
        if ($domain === null) {
            return null;
        }

        $expected = $this->platformNameservers();
        if ($expected === []) {
            throw new RuntimeException('platform_nameservers_not_configured');
        }

        $previous = $domain['nameservers'] ?? [];
        $previousObserved = [];
        foreach ($previous as $row) {
            $hostname = $this->normalize([$row['hostname'] ?? ''])[0] ?? '';
            if ($hostname !== '' && (bool) ($row['observed'] ?? false)) {
                $previousObserved[$hostname] = true;
            }
        }

        $now = UnixTime::now();
        $matched = array_values(array_filter($expected, static fn (string $hostname): bool => isset($previousObserved[$hostname])));
        $missing = array_values(array_diff($expected, $matched));
        $status = $missing === [] ? 'verified' : 'partial';

        DB::transaction(function () use ($domainId, $expected, $previousObserved, $status, $now): void {
            DB::table('domain_nameservers')->where('domain_id', $domainId)->delete();
            foreach ($expected as $hostname) {
                $observed = isset($previousObserved[$hostname]);
                DB::table('domain_nameservers')->insert([
                    'id' => (string) Str::uuid(),
                    'domain_id' => $domainId,
                    'hostname' => $hostname,
                    'expected' => true,
                    'observed' => $observed,
                    'last_checked_at' => $observed ? $now : null,
                ]);
            }
            DB::table('domains')->where('id', $domainId)->update([
                'nameserver_status' => $status,
                'status' => $status === 'verified' ? 'active' : 'pending_nameserver',
                'updated_at' => $now,
            ]);
        });

        $updated = $this->domains->find($domainId);
        $verification = [
            'expected_nameservers' => $expected,
            'observed_nameservers' => $matched,
            'matched_nameservers' => $matched,
            'missing_nameservers' => $missing,
            'checked_at' => $now,
            'status' => $status,
            'resolver_errors' => [],
            'reseeded_expected' => true,
        ];
        $this->audit->write('domain.nameserver.reseed_expected', 'domain', $domainId, $domain, $updated, 'admin', $actor['id'] ?? null, $domainId, [
            'expected_nameservers' => $expected,
            'previous_nameservers' => $previous,
        ]);
        $this->domains->afterDomainMutation($domainId, 'domain.verification.changed');

        return ['domain' => $updated, 'verification' => $verification];
    }

    private function applyVerification(array $domain, array $observed, array $resolverErrors): array
    {
        $domainId = (string) $domain['id'];
        $expected = $this->expected($domain);
        $matched = array_values(array_intersect($expected, $observed));
        $missing = array_values(array_diff($expected, $matched));
        $status = $matched === []
            ? ($observed === [] ? 'not_configured' : 'partial')
            : (count($matched) === count($expected) ? 'verified' : 'partial');
        $now = UnixTime::now();

        DB::transaction(function () use ($domainId, $matched, $status, $now): void {
            DB::table('domain_nameservers')->where('domain_id', $domainId)->update([
                'observed' => false,
                'last_checked_at' => $now,
            ]);
            foreach ($matched as $hostname) {
                DB::table('domain_nameservers')
                    ->where('domain_id', $domainId)
                    ->whereRaw('lower(hostname) = ?', [$hostname])
                    ->update(['observed' => true]);
            }
            DB::table('domains')->where('id', $domainId)->update([
                'nameserver_status' => $status,
                'status' => $status === 'verified' ? 'active' : 'pending_nameserver',
                'last_ns_check_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $updated = $this->domains->find($domainId);
        $this->domains->afterDomainMutation($domainId, 'domain.verification.changed');

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

    private function expected(array $domain): array
    {
        return $this->normalize(array_column((array) ($domain['nameservers'] ?? []), 'hostname'));
    }

    private function platformNameservers(): array
    {
        $value = DB::table('platform_settings')->where('key', 'platform.nameservers')->value('value_json');
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return $this->normalize($decoded['hostnames'] ?? []);
    }

    private function normalize(array $hostnames): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $hostname): string => strtolower(rtrim(trim((string) $hostname), '.')),
            $hostnames
        ))));
    }
}

