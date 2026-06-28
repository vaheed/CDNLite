<?php

namespace App\Services\ControlPlane;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class DomainLifecycleService
{
    public function __construct(
        private AuditWriter $audit,
        private ConfigStateWriter $configState,
        private DnsReconcileQueue $dnsReconcile,
    ) {
    }

    public function list(): array
    {
        return DB::table('domains')->orderBy('created_at')->orderBy('domain')->get()->map(fn ($row) => $this->withNameservers((array) $row))->all();
    }

    public function create(array $input, ?array $actor = null): array
    {
        $id = (string) Str::uuid();
        $now = UnixTime::now();
        $domainName = strtolower(rtrim(trim((string) $input['domain']), '.'));
        $row = [
            'id' => $id,
            'user_id' => (string) ($input['user_id'] ?? Str::uuid()),
            'name' => trim((string) ($input['name'] ?? $domainName)),
            'domain' => $domainName,
            'origin_shield_header_name' => $input['origin_shield_header_name'] ?? null,
            'origin_shield_header_value_hash' => $this->originShieldHash($input),
            'status' => 'pending_nameserver',
            'nameserver_status' => 'unknown',
            'verification_token' => bin2hex(random_bytes(16)),
            'last_ns_check_at' => null,
            'powerdns_zone_created' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::transaction(function () use ($row, $id, $now): void {
            DB::table('domains')->insert($row);
            foreach ($this->defaultNameservers() as $hostname) {
                DB::table('domain_nameservers')->insert([
                    'id' => (string) Str::uuid(),
                    'domain_id' => $id,
                    'hostname' => $hostname,
                    'expected' => true,
                    'observed' => false,
                    'last_checked_at' => null,
                ]);
            }

            DB::table('domain_routing_settings')->insert([
                'domain_id' => $id,
                'routing_mode' => 'geo',
                'geo_health_port' => 443,
                'geo_selector' => 'pickclosest',
                'anycast_ipv4' => null,
                'anycast_ipv6' => null,
                'anycast_cname' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $created = $this->find($id);
        if ($created === null) {
            throw new RuntimeException('domain_create_failed');
        }

        $this->audit->write('domain.create', 'domain', $id, null, $created, 'admin', $actor['id'] ?? null, $id);
        $this->afterDomainMutation($id, 'domain.changed');

        return $created;
    }

    public function find(string $domainId): ?array
    {
        $row = DB::table('domains')->where('id', $domainId)->first();

        return $row === null ? null : $this->withNameservers((array) $row);
    }

    public function findByDomain(string $domain): ?array
    {
        $row = DB::table('domains')->whereRaw('lower(domain) = lower(?)', [rtrim(trim($domain), '.')])->first();

        return $row === null ? null : $this->withNameservers((array) $row);
    }

    public function update(string $domainId, array $input, ?array $actor = null): ?array
    {
        $existing = $this->find($domainId);
        if ($existing === null) {
            return null;
        }

        $patch = [];
        foreach (['name', 'domain', 'origin_shield_header_name'] as $field) {
            if (array_key_exists($field, $input)) {
                $patch[$field] = $field === 'domain'
                    ? strtolower(rtrim(trim((string) $input[$field]), '.'))
                    : $input[$field];
            }
        }
        if (array_key_exists('origin_shield_secret', $input)) {
            $patch['origin_shield_header_value_hash'] = hash('sha256', (string) $input['origin_shield_secret']);
        }
        if (array_key_exists('status', $input)) {
            $patch['status'] = $input['status'];
        }
        if ($patch === []) {
            return $existing;
        }

        $patch['updated_at'] = UnixTime::now();
        DB::table('domains')->where('id', $domainId)->update($patch);

        $updated = $this->find($domainId);
        $this->audit->write('domain.update', 'domain', $domainId, $existing, $updated, 'admin', $actor['id'] ?? null, $domainId);
        $this->afterDomainMutation($domainId, 'domain.changed');

        return $updated;
    }

    public function delete(string $domainId, ?array $actor = null): bool
    {
        $existing = $this->find($domainId);
        if ($existing === null) {
            return false;
        }

        DB::table('domains')->where('id', $domainId)->delete();
        $this->audit->write('domain.delete', 'domain', $domainId, $existing, null, 'admin', $actor['id'] ?? null, $domainId);
        $this->afterDomainMutation($domainId, 'domain.changed');

        return true;
    }

    public function activate(string $domainId, bool $override, ?array $actor = null): ?array
    {
        $domain = $this->find($domainId);
        if ($domain === null) {
            return null;
        }
        if (!$override && ($domain['nameserver_status'] ?? null) !== 'verified') {
            throw new RuntimeException('nameservers_not_verified');
        }

        return $this->update($domainId, ['status' => 'active'], $actor);
    }

    public function afterDomainMutation(string $domainId, string $reason): void
    {
        $this->configState->markDirty($reason);
        $this->dnsReconcile->queueForDomain($domainId);
    }

    private function withNameservers(array $domain): array
    {
        $domain['nameservers'] = DB::table('domain_nameservers')
            ->select('hostname', 'expected', 'observed', 'last_checked_at')
            ->where('domain_id', $domain['id'])
            ->orderBy('hostname')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        return $domain;
    }

    private function defaultNameservers(): array
    {
        $value = DB::table('platform_settings')->where('key', 'platform.nameservers')->value('value_json');
        $decoded = is_string($value) ? json_decode($value, true) : null;
        $hostnames = $decoded['hostnames'] ?? ['ns1.cdnlite.test', 'ns2.cdnlite.test'];

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $hostname): string => strtolower(rtrim(trim((string) $hostname), '.')),
            $hostnames
        ))));
    }

    private function originShieldHash(array $input): ?string
    {
        if (array_key_exists('origin_shield_secret', $input)) {
            return hash('sha256', (string) $input['origin_shield_secret']);
        }

        return $input['origin_shield_header_value_hash'] ?? null;
    }
}

