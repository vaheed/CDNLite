<?php

namespace App\Services\ControlPlane;

use App\Modules\Proxy\Services\AcmeIssuerService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OutOfBoundsException;
use Throwable;

final class SslRenewalService
{
    private SslCertificateService $certificates;
    private AcmeIssuerService $issuer;
    private AuditWriter $audit;

    public function __construct(?SslCertificateService $certificates = null, ?AcmeIssuerService $issuer = null, ?AuditWriter $audit = null)
    {
        $this->certificates = $certificates ?? new SslCertificateService();
        $this->issuer = $issuer ?? new AcmeIssuerService($this->certificates);
        $this->audit = $audit ?? new AuditWriter();
    }

    public function processQueuedJobs(?int $limit = null): array
    {
        $limit = max(1, min(50, $limit ?? (int) (getenv('CDNLITE_SSL_JOB_BATCH_SIZE') ?: 10)));
        $staleBefore = UnixTime::now() - max(60, (int) (getenv('CDNLITE_SSL_JOB_STALE_RETRY_SECONDS') ?: 900));
        $jobs = DB::table('ssl_jobs')
            ->where('status', 'queued')
            ->orWhere(function ($query) use ($staleBefore): void {
                $query->whereIn('status', ['checking_dns', 'creating_order', 'validating_challenge', 'issuing', 'installing'])
                    ->where('updated_at', '<=', $staleBefore);
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $results = [];
        foreach ($jobs as $job) {
            $results[] = $this->processQueuedJob($job);
        }

        return ['attempted' => count($results), 'results' => $results];
    }

    public function renewDue(?int $now = null): array
    {
        $now ??= UnixTime::now();
        $certificates = DB::table('ssl_certificates as c')
            ->join('domain_ssl_settings as s', 's.domain_id', '=', 'c.domain_id')
            ->where('s.auto_renew', true)
            ->where('c.provider', 'acme')
            ->where('c.status', '<>', 'revoked')
            ->whereNotNull('c.renewal_due_at')
            ->where('c.renewal_due_at', '<=', $now + 14 * 86400)
            ->orderBy('c.renewal_due_at')
            ->get(['c.domain_id', 'c.hostname'])
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $results = [];
        foreach ($certificates as $certificate) {
            $results[] = $this->issue((string) $certificate['domain_id'], [(string) $certificate['hostname']], 'automatic_renewal');
        }

        return ['checked_at' => $now, 'attempted' => count($results), 'results' => $results];
    }

    public function request(string $domainId, array $hostnames = []): array
    {
        $this->certificates->requestJob($domainId, $hostnames);

        return $this->issue($domainId, $hostnames, 'issuance');
    }

    public function forceRenew(string $domainId): array
    {
        $hostnames = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['hostname'],
            array_filter($this->certificates->listCertificates($domainId), static fn (array $row): bool => ($row['provider'] ?? null) === 'acme' && ($row['status'] ?? null) !== 'revoked')
        )));
        if ($hostnames === []) {
            throw new OutOfBoundsException('acme_certificate_not_found');
        }

        return $this->issue($domainId, $hostnames, 'forced_renewal');
    }

    private function processQueuedJob(array $job): array
    {
        $jobId = (string) $job['id'];
        $domainId = (string) $job['domain_id'];
        $claimed = DB::table('ssl_jobs')
            ->where('id', $jobId)
            ->whereIn('status', ['queued', 'checking_dns', 'validating_challenge', 'issuing', 'installing'])
            ->update([
                'status' => 'checking_dns',
                'progress_percent' => 20,
                'message' => 'Checking DNS validation prerequisites.',
                'updated_at' => UnixTime::now(),
            ]);

        if ($claimed !== 1) {
            return ['job_id' => $jobId, 'status' => 'skipped', 'reason' => 'job_already_claimed'];
        }

        return ['job_id' => $jobId, 'domain_id' => $domainId] + $this->issue($domainId, $this->jobHostnames($job), 'queued_issuance');
    }

    private function issue(string $domainId, array $hostnames, string $action): array
    {
        $domain = $this->domainOrFail($domainId);
        $targets = $this->normalizeHostnames($hostnames === [] ? $this->defaultManagedSslHostnames((string) $domain['domain']) : $hostnames, (string) $domain['domain']);
        if ((string) $domain['status'] !== 'active' || (string) ($domain['nameserver_status'] ?? '') !== 'verified') {
            throw new DomainException('domain_must_be_active');
        }

        $historyIds = [];
        $this->setProgress($domainId, $targets, 'pending_dns', null);
        $this->auditSslLifecycle('ssl.validation_pending', $domainId, null, $targets, [
            'action' => $action,
            'status' => 'pending_dns',
            'message' => 'Waiting for ACME DNS validation prerequisites.',
        ]);

        foreach ($targets as $hostname) {
            $historyId = (string) Str::uuid();
            DB::table('ssl_renewal_history')->insert([
                'id' => $historyId,
                'certificate_id' => $this->certificateId($domainId, $hostname),
                'domain_id' => $domainId,
                'hostname' => $hostname,
                'action' => $action,
                'status' => 'verifying',
                'error' => null,
                'started_at' => UnixTime::now(),
                'completed_at' => null,
            ]);
            $historyIds[] = $historyId;
        }

        $this->setProgress($domainId, $targets, 'verifying', null);
        $this->auditSslLifecycle('ssl.validation_pending', $domainId, null, $targets, [
            'action' => $action,
            'status' => 'verifying',
            'history_ids' => $historyIds,
            'message' => 'ACME DNS validation is in progress.',
        ]);

        try {
            $rows = $this->issuer->issue($domainId, $targets);
            $this->setProgress($domainId, $targets, 'issued', null);
            $this->finishHistory($historyIds, 'issued', null);
            $this->auditSslLifecycle('ssl.issued', $domainId, null, $targets, [
                'action' => $action,
                'status' => 'issued',
                'history_ids' => $historyIds,
                'certificate_ids' => array_values(array_filter(array_map(
                    static fn (array $row): ?string => isset($row['id']) ? (string) $row['id'] : null,
                    $rows
                ))),
            ]);

            return ['status' => 'issued', 'certificates' => $rows, 'history_ids' => $historyIds];
        } catch (Throwable $error) {
            $this->setProgress($domainId, $targets, 'error', $error->getMessage());
            $this->finishHistory($historyIds, 'error', $error->getMessage());
            $this->auditSslLifecycle('ssl.failed', $domainId, null, $targets, [
                'action' => $action,
                'status' => 'error',
                'history_ids' => $historyIds,
                'error_code' => $this->safeErrorMessage($error->getMessage()),
                'error_detail' => $error->getMessage(),
            ]);

            return ['status' => 'error', 'error' => $error->getMessage(), 'history_ids' => $historyIds];
        }
    }

    private function setProgress(string $domainId, array $hostnames, string $status, ?string $error): void
    {
        $this->updateActiveJobs($domainId, $status, $error);
        foreach ($hostnames as $hostname) {
            DB::table('ssl_certificates')
                ->where('domain_id', $domainId)
                ->where('hostname', $hostname)
                ->update([
                    'acme_status' => $status,
                    'status' => $status === 'error' ? 'error' : ($status === 'issued' ? 'active' : 'pending'),
                    'last_error' => $error,
                    'last_checked_at' => UnixTime::now(),
                    'updated_at' => UnixTime::now(),
                ]);
        }
    }

    private function updateActiveJobs(string $domainId, string $acmeStatus, ?string $error): void
    {
        $map = [
            'pending_dns' => ['checking_dns', 20, 'Checking DNS validation prerequisites.'],
            'verifying' => ['validating_challenge', 50, 'Validating ACME DNS challenge.'],
            'issued' => ['issued', 100, 'Certificate issued and ready to install.'],
            'error' => ['failed', 100, 'SSL issuance failed.'],
        ];
        if (!isset($map[$acmeStatus])) {
            return;
        }
        [$jobStatus, $progress, $message] = $map[$acmeStatus];
        DB::table('ssl_jobs')
            ->where('domain_id', $domainId)
            ->whereIn('status', ['queued', 'checking_dns', 'creating_order', 'validating_challenge', 'issuing', 'installing'])
            ->update([
                'status' => $jobStatus,
                'progress_percent' => $progress,
                'message' => $error === null ? $message : $message . ' ' . $error,
                'error_code' => $error === null ? null : $this->safeErrorMessage($error),
                'error_detail' => $error,
                'updated_at' => UnixTime::now(),
                'finished_at' => in_array($jobStatus, ['issued', 'failed'], true) ? UnixTime::now() : null,
            ]);
    }

    private function finishHistory(array $ids, string $status, ?string $error): void
    {
        foreach ($ids as $id) {
            DB::table('ssl_renewal_history')->where('id', $id)->update([
                'status' => $status,
                'error' => $error,
                'completed_at' => UnixTime::now(),
            ]);
        }
    }

    private function auditSslLifecycle(string $event, string $domainId, ?string $resourceId, array $hostnames, array $details): void
    {
        $payload = array_merge([
            'domain_id' => $domainId,
            'hostnames' => array_values(array_unique(array_map(static fn (string $hostname): string => strtolower(trim($hostname)), $hostnames))),
            'created_at' => UnixTime::now(),
        ], $details);
        $this->audit->write($event, 'ssl', $resourceId, null, null, 'system', 'system', $domainId, $payload);
    }

    private function certificateId(string $domainId, string $hostname): ?string
    {
        $id = DB::table('ssl_certificates')->where('domain_id', $domainId)->where('hostname', $hostname)->value('id');

        return $id === null ? null : (string) $id;
    }

    private function domainOrFail(string $domainId): array
    {
        $domain = DB::table('domains')->where('id', $domainId)->first();
        if ($domain === null) {
            throw new OutOfBoundsException('domain_not_found');
        }

        return (array) $domain;
    }

    private function jobHostnames(array $job): array
    {
        $decoded = json_decode((string) ($job['hostnames_json'] ?? '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }

        return $this->cleanHostnames($decoded);
    }

    private function normalizeHostnames(array $hostnames, string $zoneDomain): array
    {
        $out = [];
        foreach ($this->cleanHostnames($hostnames) as $hostname) {
            if (!$this->validHostname($hostname) || !$this->hostnameBelongsToZone($hostname, $zoneDomain)) {
                throw new \InvalidArgumentException('hostname_outside_domain');
            }
            $out[$hostname] = $hostname;
        }

        return array_values($out);
    }

    private function cleanHostnames(array $hostnames): array
    {
        $out = [];
        foreach ($hostnames as $hostname) {
            $h = strtolower(trim((string) $hostname));
            if ($h !== '') {
                $out[$h] = $h;
            }
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

    private function defaultManagedSslHostnames(string $domain): array
    {
        $domain = strtolower(trim($domain));

        return $domain === '' ? [] : [$domain, '*.' . $domain];
    }

    private function safeErrorMessage(string $error): string
    {
        $message = strtolower(trim($error));
        if ($message === '') {
            return 'ssl_issuance_failed';
        }

        return preg_replace('/[^a-z0-9_]+/', '_', $message) ?: 'ssl_issuance_failed';
    }
}
