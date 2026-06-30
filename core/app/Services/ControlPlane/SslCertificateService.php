<?php

namespace App\Services\ControlPlane;

use App\Support\Secrets;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OutOfBoundsException;
use RuntimeException;

final class SslCertificateService
{
    public function __construct(
        private ?AuditWriter $audit = null,
        private ?ConfigStateWriter $configState = null
    ) {
        $this->audit ??= new AuditWriter();
        $this->configState ??= new ConfigStateWriter($this->audit);
    }

    public function listCertificates(string $domainId): array
    {
        return DB::table('ssl_certificates')
            ->where('domain_id', $domainId)
            ->orderBy('hostname')
            ->get()
            ->map(fn (object $row): array => $this->castCertificate((array) $row))
            ->all();
    }

    public function settings(string $domainId): array
    {
        $row = DB::table('domain_ssl_settings')->where('domain_id', $domainId)->first();
        if ($row !== null) {
            return $this->castSettings((array) $row);
        }

        $this->domainOrFail($domainId);
        $now = UnixTime::now();
        DB::table('domain_ssl_settings')->insert([
            'domain_id' => $domainId,
            'force_https' => false,
            'min_tls_version' => '1.2',
            'auto_renew' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->settings($domainId);
    }

    public function updateSettings(string $domainId, array $input, string $actor = 'system'): array
    {
        $current = $this->settings($domainId);
        $forceHttps = array_key_exists('force_https', $input) ? (bool) $input['force_https'] : (bool) $current['force_https'];
        $minTlsVersion = (string) ($input['min_tls_version'] ?? $current['min_tls_version']);
        $autoRenew = array_key_exists('auto_renew', $input) ? (bool) $input['auto_renew'] : (bool) $current['auto_renew'];

        if (!in_array($minTlsVersion, ['1.2', '1.3'], true)) {
            throw new InvalidArgumentException('invalid_min_tls_version');
        }
        if ($forceHttps && !$this->hasValidApexCertificate($domainId)) {
            throw new DomainException('valid_ssl_certificate_required');
        }

        $updated = DB::transaction(function () use ($domainId, $forceHttps, $minTlsVersion, $autoRenew): array {
            DB::table('domain_ssl_settings')->where('domain_id', $domainId)->update([
                'force_https' => $forceHttps,
                'min_tls_version' => $minTlsVersion,
                'auto_renew' => $autoRenew,
                'updated_at' => UnixTime::now(),
            ]);
            $forceHttps ? $this->ensureForceHttpsRedirect($domainId) : $this->removeForceHttpsRedirect($domainId);

            return $this->settings($domainId);
        });

        $this->audit->write('ssl.settings.update', 'domain_ssl_settings', $domainId, $current, $updated, 'admin', $actor, $domainId);
        $this->configState->markDirty('ssl.settings.changed');

        return $updated;
    }

    public function requestJob(string $domainId, array $hostnames = [], string $actor = 'system'): array
    {
        $domain = $this->activeVerifiedDomain($domainId);
        $targets = $this->normalizeHostnames($hostnames === [] ? $this->defaultManagedSslHostnames((string) $domain['domain']) : $hostnames, (string) $domain['domain']);
        if ($targets === []) {
            throw new DomainException('ssl_hostnames_required');
        }

        DB::transaction(function () use ($domainId, $targets): void {
            $this->ensurePendingCertificates($domainId, $targets);
        });

        $now = UnixTime::now();
        $job = [
            'id' => (string) Str::uuid(),
            'domain_id' => $domainId,
            'status' => 'queued',
            'progress_percent' => 5,
            'message' => 'SSL request queued. DNS validation will start shortly.',
            'error_code' => null,
            'error_detail' => null,
            'hostnames_json' => json_encode($targets, JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
            'finished_at' => null,
        ];
        DB::table('ssl_jobs')->insert($job);

        $out = $this->castJob($job);
        $this->audit->write('ssl.requested', 'ssl_job', (string) $job['id'], null, $out, 'admin', $actor, $domainId);

        return [
            'job_id' => $job['id'],
            'status' => $job['status'],
            'message' => $job['message'],
            'job' => $out,
        ];
    }

    public function getJob(string $domainId, string $jobId): ?array
    {
        $row = DB::table('ssl_jobs')->where('domain_id', $domainId)->where('id', $jobId)->first();

        return $row === null ? null : $this->castJob((array) $row);
    }

    public function listJobs(string $domainId, int $limit = 20): array
    {
        return DB::table('ssl_jobs')
            ->where('domain_id', $domainId)
            ->orderByDesc('created_at')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(fn (object $row): array => $this->castJob((array) $row))
            ->all();
    }

    public function status(string $domainId): array
    {
        $certificates = $this->listCertificates($domainId);
        $history = DB::table('ssl_renewal_history')
            ->where('domain_id', $domainId)
            ->orderByDesc('started_at')
            ->limit(50)
            ->get()
            ->map(fn (object $row): array => $this->castIntegers((array) $row, ['started_at', 'completed_at']))
            ->all();

        return [
            'progress' => array_map(static fn (array $row): array => [
                'certificate_id' => $row['id'],
                'hostname' => $row['hostname'],
                'status' => $row['acme_status'] ?: (($row['status'] ?? '') === 'active' ? 'issued' : 'idle'),
                'error' => $row['last_error'],
                'updated_at' => $row['updated_at'],
            ], $certificates),
            'history' => $history,
            'jobs' => $this->listJobs($domainId),
        ];
    }

    public function checkCertificates(string $domainId, array $hostnames = []): array
    {
        $domain = $this->domainOrFail($domainId);
        $targets = $hostnames === [] ? [] : $this->normalizeHostnames($hostnames, (string) $domain['domain']);
        $now = UnixTime::now();
        foreach ($targets as $hostname) {
            $existingId = DB::table('ssl_certificates')->where('domain_id', $domainId)->where('hostname', $hostname)->value('id');
            if ($existingId === null) {
                DB::table('ssl_certificates')->insert([
                    'id' => (string) Str::uuid(),
                    'domain_id' => $domainId,
                    'hostname' => $hostname,
                    'provider' => 'manual',
                    'status' => 'missing',
                    'last_checked_at' => $now,
                    'last_error' => 'certificate_not_provisioned',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                continue;
            }
            DB::table('ssl_certificates')->where('id', $existingId)->update([
                'last_checked_at' => $now,
                'last_error' => 'certificate_not_provisioned',
                'updated_at' => $now,
            ]);
        }

        return $this->listCertificates($domainId);
    }

    public function importManualCertificate(string $domainId, string $hostname, string $certificatePem, string $privateKeyPem, string $actor = 'system'): array
    {
        $domain = $this->domainOrFail($domainId);
        $hostname = $this->normalizeHostnames([$hostname], (string) $domain['domain'])[0] ?? '';
        if ($hostname === '') {
            throw new InvalidArgumentException('hostname_required');
        }

        $stored = $this->storeIssuedCertificate($domainId, $hostname, 'manual', $certificatePem, $privateKeyPem);
        $this->audit->write('ssl.manual_certificate.import', 'ssl_certificate', (string) $stored['id'], null, $stored, 'admin', $actor, $domainId);

        return $stored;
    }

    public function storeIssuedCertificate(string $domainId, string $hostname, string $provider, string $certificatePem, string $privateKeyPem): array
    {
        if (!Secrets::isConfigured()) {
            throw new RuntimeException('ssl_secret_key_missing');
        }
        $cert = openssl_x509_read($certificatePem);
        if ($cert === false) {
            throw new InvalidArgumentException('invalid_certificate_pem');
        }
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new InvalidArgumentException('invalid_private_key_pem');
        }
        if (!openssl_x509_check_private_key($cert, $key)) {
            throw new InvalidArgumentException('certificate_key_mismatch');
        }

        $parsed = openssl_x509_parse($cert) ?: [];
        $now = UnixTime::now();
        $notBefore = isset($parsed['validFrom_time_t']) ? (int) $parsed['validFrom_time_t'] : null;
        $notAfter = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
        if ($notAfter !== null && $notAfter < $now) {
            throw new InvalidArgumentException('certificate_not_active');
        }
        $days = $notAfter === null ? null : (int) floor(($notAfter - $now) / 86400);
        $renewalDueAt = $notAfter === null ? null : max($now, $notAfter - 30 * 86400);
        $issuer = isset($parsed['issuer']) && is_array($parsed['issuer']) ? json_encode($parsed['issuer'], JSON_UNESCAPED_SLASHES) : null;
        $serial = isset($parsed['serialNumberHex']) ? (string) $parsed['serialNumberHex'] : null;
        $id = (string) (DB::table('ssl_certificates')->where('domain_id', $domainId)->where('hostname', $hostname)->value('id') ?: Str::uuid());

        DB::table('ssl_certificates')->updateOrInsert(
            ['domain_id' => $domainId, 'hostname' => $hostname],
            [
                'id' => $id,
                'provider' => $provider,
                'status' => 'active',
                'issuer' => $issuer,
                'serial_number' => $serial,
                'not_before' => $notBefore,
                'not_after' => $notAfter,
                'days_until_expiry' => $days,
                'renewal_due_at' => $renewalDueAt,
                'last_checked_at' => $now,
                'last_error' => null,
                'certificate_pem' => $certificatePem,
                'private_key_pem' => Secrets::encrypt($privateKeyPem),
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $this->configState->markDirty('ssl.certificate.changed');

        return $this->castCertificate((array) DB::table('ssl_certificates')->where('id', $id)->first());
    }

    public function storeIssuedSslCertificate(string $domainId, string $hostname, string $provider, string $certificatePem, string $privateKeyPem): array
    {
        return $this->storeIssuedCertificate($domainId, $hostname, $provider, $certificatePem, $privateKeyPem);
    }

    public function ensureManagedWildcardJob(string $domainId): ?array
    {
        $domain = $this->domain($domainId);
        if ($domain === null || (string) $domain['status'] !== 'active' || (string) ($domain['nameserver_status'] ?? '') !== 'verified') {
            return null;
        }
        $hostnames = $this->defaultManagedSslHostnames((string) $domain['domain']);
        if ($this->hasActiveManagedCertificate($domainId, $hostnames) || $this->hasActiveJob($domainId, $hostnames)) {
            return null;
        }
        if (empty($this->settings($domainId)['auto_renew'])) {
            $this->updateSettings($domainId, ['auto_renew' => true]);
        }

        return $this->requestJob($domainId, $hostnames);
    }

    private function ensurePendingCertificates(string $domainId, array $hostnames): void
    {
        $now = UnixTime::now();
        foreach ($hostnames as $hostname) {
            $existingId = DB::table('ssl_certificates')->where('domain_id', $domainId)->where('hostname', $hostname)->value('id');
            DB::table('ssl_certificates')->updateOrInsert(
                ['domain_id' => $domainId, 'hostname' => $hostname],
                [
                    'id' => $existingId ?: (string) Str::uuid(),
                    'provider' => 'acme',
                    'status' => 'pending',
                    'last_checked_at' => $now,
                    'last_error' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    private function domainOrFail(string $domainId): array
    {
        $domain = $this->domain($domainId);
        if ($domain === null) {
            throw new OutOfBoundsException('domain_not_found');
        }

        return $domain;
    }

    private function activeVerifiedDomain(string $domainId): array
    {
        $domain = $this->domainOrFail($domainId);
        if ((string) $domain['status'] !== 'active' || (string) ($domain['nameserver_status'] ?? '') !== 'verified') {
            throw new DomainException('domain_must_be_active');
        }

        return $domain;
    }

    private function domain(string $domainId): ?array
    {
        $row = DB::table('domains')->where('id', $domainId)->first();

        return $row === null ? null : (array) $row;
    }

    private function hasValidApexCertificate(string $domainId): bool
    {
        $domain = $this->domainOrFail($domainId);

        return DB::table('ssl_certificates')
            ->where('domain_id', $domainId)
            ->whereRaw('lower(hostname) = lower(?)', [(string) $domain['domain']])
            ->where('status', 'active')
            ->where('not_after', '>', UnixTime::now())
            ->whereNotNull('certificate_pem')
            ->whereNotNull('private_key_pem')
            ->exists();
    }

    private function ensureForceHttpsRedirect(string $domainId): void
    {
        $domain = $this->domainOrFail($domainId);
        $now = UnixTime::now();
        DB::statement(
            "INSERT INTO redirect_rules (id,domain_id,enabled,source_path,target_url,status_code,priority,match_type,preserve_query,managed_by,created_at,updated_at)
             VALUES (?, ?, true, '/', ?, 308, 1, 'prefix', true, 'force_https', ?, ?)
             ON CONFLICT (domain_id,managed_by) WHERE managed_by='force_https'
             DO UPDATE SET enabled=true,source_path='/',target_url=EXCLUDED.target_url,status_code=308,priority=1,match_type='prefix',preserve_query=true,updated_at=EXCLUDED.updated_at",
            [(string) Str::uuid(), $domainId, 'https://' . strtolower((string) $domain['domain']), $now, $now]
        );
    }

    private function removeForceHttpsRedirect(string $domainId): void
    {
        DB::table('redirect_rules')->where('domain_id', $domainId)->where('managed_by', 'force_https')->delete();
    }

    private function defaultManagedSslHostnames(string $domain): array
    {
        $domain = strtolower(trim($domain));

        return $domain === '' ? [] : [$domain, '*.' . $domain];
    }

    private function normalizeHostnames(array $hostnames, string $zoneDomain): array
    {
        $out = [];
        foreach ($hostnames as $hostname) {
            $h = strtolower(trim((string) $hostname));
            if ($h === '') {
                continue;
            }
            if (!$this->validHostname($h) || !$this->hostnameBelongsToZone($h, $zoneDomain)) {
                throw new InvalidArgumentException('hostname_outside_domain');
            }
            $out[$h] = $h;
        }

        return array_values($out);
    }

    private function hostnameBelongsToZone(string $hostname, string $zoneDomain): bool
    {
        $zone = rtrim(strtolower($zoneDomain), '.');
        $host = rtrim(strtolower($hostname), '.');
        if (str_starts_with($host, '*.')) {
            $host = substr($host, 2);
        }

        return $host === $zone || str_ends_with($host, '.' . $zone);
    }

    private function validHostname(string $hostname): bool
    {
        if ($hostname === '') {
            return false;
        }
        if (str_starts_with($hostname, '*.')) {
            $hostname = substr($hostname, 2);
        }

        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])$/', $hostname);
    }

    private function hasActiveManagedCertificate(string $domainId, array $hostnames): bool
    {
        if ($hostnames === []) {
            return false;
        }

        return DB::table('ssl_certificates')
            ->where('domain_id', $domainId)
            ->whereIn('hostname', $hostnames)
            ->where('provider', 'acme')
            ->where('status', 'active')
            ->where('not_after', '>', UnixTime::now())
            ->whereNotNull('certificate_pem')
            ->whereNotNull('private_key_pem')
            ->distinct('hostname')
            ->count('hostname') === count($hostnames);
    }

    private function hasActiveJob(string $domainId, array $hostnames): bool
    {
        $wanted = array_values(array_unique(array_map(static fn (string $hostname): string => strtolower(trim($hostname)), $hostnames)));
        sort($wanted);
        $jobs = DB::table('ssl_jobs')
            ->where('domain_id', $domainId)
            ->whereIn('status', ['queued', 'checking_dns', 'creating_order', 'validating_challenge', 'issuing', 'installing'])
            ->orderByDesc('created_at')
            ->get(['hostnames_json']);
        foreach ($jobs as $job) {
            $decoded = json_decode((string) $job->hostnames_json, true);
            if (!is_array($decoded)) {
                continue;
            }
            $current = array_values(array_unique(array_map(static fn (mixed $hostname): string => strtolower(trim((string) $hostname)), $decoded)));
            sort($current);
            if ($current === $wanted) {
                return true;
            }
        }

        return false;
    }

    private function castSettings(array $row): array
    {
        $row['force_https'] = (bool) $row['force_https'];
        $row['auto_renew'] = (bool) $row['auto_renew'];

        return $this->castIntegers($row, ['created_at', 'updated_at']);
    }

    private function castCertificate(array $row): array
    {
        unset($row['private_key_pem']);

        return $this->castIntegers($row, ['not_before', 'not_after', 'days_until_expiry', 'renewal_due_at', 'last_checked_at', 'created_at', 'updated_at']);
    }

    private function castJob(array $row): array
    {
        $row = $this->castIntegers($row, ['created_at', 'updated_at', 'finished_at', 'progress_percent']);
        $row['hostnames'] = json_decode((string) ($row['hostnames_json'] ?? '[]'), true) ?: [];
        unset($row['hostnames_json']);
        $staleAfter = max(60, (int) config('cdnlite.ssl.scheduler_interval_seconds', 30) * 2);
        $age = UnixTime::now() - (int) ($row['updated_at'] ?? UnixTime::now());
        $row['stale_seconds'] = $age;
        $row['scheduler_stale'] = ($row['status'] ?? '') === 'queued' && $age >= $staleAfter;
        if ($row['scheduler_stale']) {
            $row['scheduler_hint'] = 'The core scheduler has not claimed this queued job. Check core logs and run php artisan cdn:scheduler:run --force or php artisan cdn:ssl:renew-due to process queued SSL jobs.';
        }

        return $row;
    }

    private function castIntegers(array $row, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int) $row[$key];
            }
        }

        return $row;
    }
}
