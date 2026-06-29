<?php

namespace App\Services\ControlPlane;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class DnsRecordService
{
    private const TYPES = ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'CAA', 'NS', 'SRV'];

    public function __construct(
        private AuditWriter $audit,
        private ConfigStateWriter $config,
        private DnsReconcileQueue $dnsReconcile,
    ) {
    }

    public function list(string $domainId): ?array
    {
        $domain = $this->domain($domainId);
        if ($domain === null) {
            return null;
        }

        return DB::table('dns_records')
            ->where('domain_id', $domainId)
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => $this->cast((array) $row, $domain))
            ->all();
    }

    public function find(string $domainId, string $recordId): ?array
    {
        $domain = $this->domain($domainId);
        if ($domain === null) {
            return null;
        }

        $row = DB::table('dns_records')
            ->where('domain_id', $domainId)
            ->where('id', $recordId)
            ->first();

        return $row === null ? null : $this->cast((array) $row, $domain);
    }

    public function create(string $domainId, array $input, ?array $actor): ?array
    {
        $domain = $this->domain($domainId);
        if ($domain === null) {
            return null;
        }

        $record = $this->normalize($domain, $input);
        $this->assertCompatible($domainId, null, $record);

        $now = UnixTime::now();
        $public = $this->publicRecordFor($domain, $record);
        $created = $record + [
            'id' => (string) Str::uuid(),
            'domain_id' => $domainId,
            'geo_policy_id' => null,
            'origin_type' => $record['type'],
            'origin_content' => $record['content'],
            'public_type' => $public['type'],
            'public_content' => $public['content'],
            'origin_host' => $record['proxied'] ? $record['content'] : null,
            'origin_tls_verify' => 'ignore',
            'origin_scheme' => $record['proxied'] ? 'http' : null,
            'origin_status' => $record['proxied'] ? 'pending' : 'dns_only',
            'geo_origins_json' => null,
            'routing_policy' => 'standard',
            'managed_by' => null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('dns_records')->insert($created);
        $stored = $this->find($domainId, $created['id']);
        $this->afterMutation('dns.record.create', null, $stored, $domainId, $created['id'], $actor);

        return $stored;
    }

    public function update(string $domainId, string $recordId, array $input, ?array $actor): ?array
    {
        $domain = $this->domain($domainId);
        $existing = $this->find($domainId, $recordId);
        if ($domain === null || $existing === null) {
            return null;
        }

        $record = $this->normalize($domain, $input + [
            'type' => $existing['type'],
            'name' => $existing['name'],
            'content' => $existing['content'],
            'ttl' => $existing['ttl'],
            'priority' => $existing['priority'],
            'proxied' => $existing['proxied'],
            'status' => $existing['status'],
        ], true);
        $this->assertCompatible($domainId, $recordId, $record);
        $public = $this->publicRecordFor($domain, $record);

        DB::table('dns_records')
            ->where('domain_id', $domainId)
            ->where('id', $recordId)
            ->update([
                'type' => $record['type'],
                'name' => $record['name'],
                'content' => $record['content'],
                'ttl' => $record['ttl'],
                'priority' => $record['priority'],
                'proxied' => $record['proxied'],
                'origin_type' => $record['type'],
                'origin_content' => $record['content'],
                'public_type' => $public['type'],
                'public_content' => $public['content'],
                'origin_host' => $record['proxied'] ? $record['content'] : null,
                'origin_scheme' => $record['proxied'] ? 'http' : null,
                'origin_status' => $record['proxied'] ? 'pending' : 'dns_only',
                'status' => $record['status'],
                'updated_at' => UnixTime::now(),
            ]);

        $updated = $this->find($domainId, $recordId);
        $this->afterMutation('dns.record.update', $existing, $updated, $domainId, $recordId, $actor);

        return $updated;
    }

    public function delete(string $domainId, string $recordId, ?array $actor): bool
    {
        $existing = $this->find($domainId, $recordId);
        if ($existing === null) {
            return false;
        }

        $deleted = DB::table('dns_records')
            ->where('domain_id', $domainId)
            ->where('id', $recordId)
            ->delete() > 0;

        if ($deleted) {
            $this->afterMutation('dns.record.delete', $existing, null, $domainId, $recordId, $actor);
        }

        return $deleted;
    }

    public function queueReconcile(string $domainId, string $recordId, ?array $actor): ?array
    {
        $record = $this->find($domainId, $recordId);
        if ($record === null) {
            return null;
        }

        $this->audit->write('dns.record.reconcile', 'dns_record', $recordId, $record, $record, 'admin', $actor['id'] ?? null, $domainId, [
            'local_state_saved' => true,
        ]);
        $this->config->markDirty('dns.record.reconcile');
        $this->dnsReconcile->queueForDomain($domainId);

        return $this->find($domainId, $recordId);
    }

    private function normalize(array $domain, array $input, bool $allowStatus = false): array
    {
        $type = strtoupper(trim((string) ($input['type'] ?? '')));
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException('invalid_dns_record_type');
        }

        $name = $this->normalizeName((string) ($input['name'] ?? ''), (string) $domain['domain']);
        $content = $this->normalizeContent($type, (string) ($input['content'] ?? ''), (bool) ($input['proxied'] ?? false));
        $ttl = (int) ($input['ttl'] ?? 300);
        if ($ttl < 60 || $ttl > 86400) {
            throw new RuntimeException('invalid_dns_record_ttl');
        }

        $priority = $type === 'MX' || $type === 'SRV'
            ? (int) ($input['priority'] ?? 0)
            : null;
        if ($priority !== null && ($priority < 0 || $priority > 65535)) {
            throw new RuntimeException('invalid_dns_record_priority');
        }

        $status = $allowStatus ? (string) ($input['status'] ?? 'active') : 'active';
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new RuntimeException('invalid_dns_record_status');
        }

        return [
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'ttl' => $ttl,
            'priority' => $priority,
            'proxied' => (bool) ($input['proxied'] ?? false),
            'status' => $status,
        ];
    }

    private function normalizeName(string $name, string $domain): string
    {
        $name = strtolower(rtrim(trim($name), '.'));
        $domain = strtolower(rtrim($domain, '.'));
        if ($name === '' || $name === '@' || $name === $domain) {
            return '@';
        }
        if (str_ends_with($name, '.' . $domain)) {
            $name = substr($name, 0, -strlen('.' . $domain));
        }
        if (!preg_match('/^(?:\*|[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:\*|[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $name)) {
            throw new RuntimeException('invalid_dns_record_name');
        }

        return $name;
    }

    private function normalizeContent(string $type, string $content, bool $proxied): string
    {
        $content = trim($content);
        if ($content === '') {
            throw new RuntimeException('invalid_dns_record_content');
        }
        if ($proxied && in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            return strtolower(rtrim($content, '.'));
        }
        if ($type === 'A' && filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('invalid_dns_record_content');
        }
        if ($type === 'AAAA' && filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new RuntimeException('invalid_dns_record_content');
        }
        if (in_array($type, ['CNAME', 'NS', 'MX'], true)) {
            return strtolower(rtrim($content, '.'));
        }

        return $content;
    }

    private function publicRecordFor(array $domain, array $record): array
    {
        if (!$record['proxied']) {
            return ['type' => $record['type'], 'content' => $record['content']];
        }

        return $this->isApex($record['name'], (string) $domain['domain'])
            ? ['type' => 'LUA', 'content' => 'managed edge pool']
            : ['type' => 'CNAME', 'content' => $this->proxyHost()];
    }

    private function assertCompatible(string $domainId, ?string $recordId, array $record): void
    {
        if ($record['status'] !== 'active') {
            return;
        }

        $duplicate = DB::table('dns_records')
            ->where('domain_id', $domainId)
            ->where('status', 'active')
            ->whereRaw('UPPER(type) = ?', [$record['type']])
            ->whereRaw('LOWER(name) = ?', [strtolower($record['name'])])
            ->where('content', $record['content'])
            ->when($recordId !== null, fn ($query) => $query->where('id', '<>', $recordId))
            ->exists();
        if ($duplicate) {
            throw new RuntimeException('dns_record_duplicate');
        }

        $public = $this->publicRecordFor($this->domain($domainId) ?? [], $record);
        $conflicts = DB::table('dns_records')
            ->where('domain_id', $domainId)
            ->where('status', 'active')
            ->whereRaw('LOWER(name) = ?', [strtolower($record['name'])])
            ->when($recordId !== null, fn ($query) => $query->where('id', '<>', $recordId))
            ->get();

        foreach ($conflicts as $row) {
            $existingType = strtoupper((string) ($row->public_type ?: $row->type));
            $existingContent = trim((string) ($row->public_content ?: $row->content));
            $existingProxied = $this->bool($row->proxied);
            $newType = strtoupper((string) $public['type']);
            if ($record['proxied'] && $existingProxied && $newType === $existingType
                && trim((string) $public['content']) === $existingContent
                && in_array($newType, ['LUA', 'CNAME'], true)) {
                continue;
            }
            if ($newType === 'CNAME' || $existingType === 'CNAME' || ($newType === 'LUA' && $existingType === 'LUA')) {
                throw new RuntimeException('dns_record_name_conflict');
            }
        }
    }

    private function afterMutation(string $action, ?array $before, ?array $after, string $domainId, string $recordId, ?array $actor): void
    {
        $this->audit->write($action, 'dns_record', $recordId, $before, $after, 'admin', $actor['id'] ?? null, $domainId);
        $this->config->markDirty('dns.record.changed');
        $this->dnsReconcile->queueForDomain($domainId);
    }

    private function domain(string $domainId): ?array
    {
        $row = DB::table('domains')->where('id', $domainId)->first();

        return $row === null ? null : (array) $row;
    }

    private function cast(array $row, array $domain): array
    {
        $row['type'] = strtoupper((string) $row['type']);
        $row['name'] = (string) $row['name'];
        $row['content'] = (string) $row['content'];
        $row['ttl'] = (int) $row['ttl'];
        $row['priority'] = $row['priority'] === null ? null : (int) $row['priority'];
        $row['proxied'] = $this->bool($row['proxied']);
        $row['public_type'] = $row['public_type'] ?: $row['type'];
        $row['public_content'] = $row['public_content'] ?: $row['content'];
        $row['origin_type'] = $row['origin_type'] ?: $row['type'];
        $row['origin_content'] = $row['origin_content'] ?: $row['content'];
        $row['publication_status'] = 'queued';
        $row['effective_status'] = $row['status'] === 'active'
            && ($domain['status'] ?? null) === 'active'
            && ($domain['nameserver_status'] ?? null) === 'verified'
            ? 'active'
            : 'disabled';
        $row['disabled_reason'] = $row['status'] !== 'active'
            ? 'record_disabled'
            : (($domain['nameserver_status'] ?? null) !== 'verified' ? 'nameservers_not_verified' : null);

        return $row;
    }

    private function isApex(string $name, string $domain): bool
    {
        $name = strtolower(rtrim(trim($name), '.'));
        $domain = strtolower(rtrim(trim($domain), '.'));

        return $name === '' || $name === '@' || $name === $domain;
    }

    private function proxyHost(): string
    {
        return strtolower(rtrim((string) env('CDNLITE_CDN_PROXY_HOST', 'proxy.cdn.example.net'), '.'));
    }

    private function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
