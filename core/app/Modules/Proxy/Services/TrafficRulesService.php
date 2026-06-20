<?php

namespace App\Modules\Proxy\Services;

use App\Support\Database;
use App\Support\AuditLog;
use App\Support\Secrets;
use App\Support\Uuid;

class TrafficRulesService
{
    private ?bool $redirectV2ColumnsAvailable = null;
    private ?bool $rateLimitV2TableAvailable = null;

    public function listRedirects(string $domainId): array { return $this->listRows('redirect_rules', $domainId); }
    public function createRedirect(string $domainId, array $in): array {
        $status = (int)($in['status_code'] ?? 302);
        if (!in_array($status, [301,302,307,308], true)) { throw new \InvalidArgumentException('invalid_status_code'); }
        $payload = [
            'enabled' => !empty($in['enabled']),
            'source_path' => (string)($in['source_path'] ?? ''),
            'target_url' => (string)($in['target_url'] ?? ''),
            'status_code' => $status,
        ];
        if ($this->redirectV2Supported()) {
            $payload['priority'] = (int) ($in['priority'] ?? 100);
            $payload['match_type'] = (string) ($in['match_type'] ?? 'exact_path');
            $payload['preserve_query'] = array_key_exists('preserve_query', $in) ? !empty($in['preserve_query']) : true;
        }
        return $this->insert('redirect_rules', $domainId, $payload);
    }
    public function updateRedirect(string $domainId, string $id, array $in): ?array {
        if (array_key_exists('status_code', $in)) {
            $status = (int) $in['status_code'];
            if (!in_array($status, [301,302,307,308], true)) { throw new \InvalidArgumentException('invalid_status_code'); }
            $in['status_code'] = $status;
        }
        if (!$this->redirectV2Supported()) {
            unset($in['priority'], $in['match_type'], $in['preserve_query']);
        }
        return $this->update('redirect_rules', $domainId, $id, $in);
    }
    public function deleteRedirect(string $domainId, string $id): bool { return $this->delete('redirect_rules', $domainId, $id); }
    public function importRedirects(string $domainId, array $items): array {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $out[] = $this->createRedirect($domainId, $item);
        }
        return $out;
    }
    public function exportRedirects(string $domainId): array {
        return $this->listRedirects($domainId);
    }
    public function testRedirect(string $domainId, string $path, string $query = ''): ?array {
        $rules = $this->listRedirects($domainId);
        usort($rules, static fn (array $a, array $b): int => ((int) ($a['priority'] ?? 100)) <=> ((int) ($b['priority'] ?? 100)));
        foreach ($rules as $rule) {
            if (empty($rule['enabled'])) {
                continue;
            }
            $matchType = (string) ($rule['match_type'] ?? 'exact_path');
            $source = (string) ($rule['source_path'] ?? '');
            $matched = false;
            if ($matchType === 'exact_path') { $matched = $path === $source; }
            if ($matchType === 'prefix') { $matched = str_starts_with($path, $source); }
            if ($matchType === 'wildcard_simple') { $matched = fnmatch(str_replace('*', '*', $source), $path); }
            if (!$matched) {
                continue;
            }
            $target = (string) ($rule['target_url'] ?? '');
            if (!empty($rule['preserve_query']) && $query !== '') {
                $target .= (str_contains($target, '?') ? '&' : '?') . ltrim($query, '?');
            }
            return [
                'matched' => true,
                'rule_id' => (string) $rule['id'],
                'status_code' => (int) $rule['status_code'],
                'target_url' => $target,
            ];
        }
        return null;
    }

    public function listWaf(string $domainId): array { return $this->listRows('waf_rules', $domainId); }
    public function createWaf(string $domainId, array $in): array {
        $type = (string)($in['type'] ?? '');
        if (!in_array($type, ['path_contains', 'path_prefix', 'user_agent_contains', 'ip_cidr', 'country_is', 'method_is', 'header_contains'], true)) { throw new \InvalidArgumentException('invalid_type'); }
        $action = (string)($in['action'] ?? 'block');
        if (!in_array($action, ['block', 'log', 'allow', 'challenge'], true)) { throw new \InvalidArgumentException('invalid_action'); }
        return $this->insert('waf_rules', $domainId, [
            'enabled' => !empty($in['enabled']),
            'name' => isset($in['name']) ? (string) $in['name'] : null,
            'priority' => (int) ($in['priority'] ?? 100),
            'type' => $type,
            'pattern' => (string)($in['pattern'] ?? ''),
            'action' => $action,
            'description' => isset($in['description']) ? (string) $in['description'] : null,
        ] + $this->wafMetadataPayload($in) + $this->managedRulePayload($in));
    }
    public function updateWaf(string $domainId, string $id, array $in): ?array { return $this->update('waf_rules', $domainId, $id, $in); }
    public function deleteWaf(string $domainId, string $id): bool { return $this->delete('waf_rules', $domainId, $id); }

    public function listHeaderRules(string $domainId): array { return $this->listRows('domain_header_rules', $domainId, 'priority ASC, created_at ASC'); }
    public function createHeaderRule(string $domainId, array $in): array {
        $this->assertHeaderRule($in, false);
        return $this->insert('domain_header_rules', $domainId, [
            'enabled' => array_key_exists('enabled', $in) ? !empty($in['enabled']) : true,
            'priority' => (int) ($in['priority'] ?? 100),
            'operation' => (string) ($in['operation'] ?? 'set'),
            'header_name' => (string) ($in['header_name'] ?? ''),
            'header_value' => array_key_exists('header_value', $in) ? (string) $in['header_value'] : null,
            'path_pattern' => (string) ($in['path_pattern'] ?? '/*'),
        ] + $this->managedRulePayload($in));
    }
    public function updateHeaderRule(string $domainId, string $id, array $in): ?array { $this->assertHeaderRule($in, true); return $this->update('domain_header_rules', $domainId, $id, $in); }
    public function deleteHeaderRule(string $domainId, string $id): bool { return $this->delete('domain_header_rules', $domainId, $id); }

    public function listIpRules(string $domainId): array { return $this->listRows('domain_ip_rules', $domainId); }
    public function createIpRule(string $domainId, array $in): array {
        $this->assertIpRule($in, false);
        return $this->insert('domain_ip_rules', $domainId, [
            'enabled' => array_key_exists('enabled', $in) ? !empty($in['enabled']) : true,
            'rule_type' => (string) ($in['rule_type'] ?? 'block'),
            'cidr' => (string) ($in['cidr'] ?? ''),
            'description' => array_key_exists('description', $in) ? (string) $in['description'] : null,
        ] + $this->managedRulePayload($in));
    }
    public function updateIpRule(string $domainId, string $id, array $in): ?array { $this->assertIpRule($in, true); return $this->update('domain_ip_rules', $domainId, $id, $in); }
    public function deleteIpRule(string $domainId, string $id): bool { return $this->delete('domain_ip_rules', $domainId, $id); }

    public function listCacheRules(string $domainId): array { return $this->listRows('cache_rules', $domainId); }
    public function createCacheRule(string $domainId, array $in): array { return $this->insert('cache_rules', $domainId, ['enabled'=>!empty($in['enabled']),'path_prefix'=>(string)($in['path_prefix'] ?? '/'),'ttl_seconds'=>(int)($in['ttl_seconds'] ?? 60)] + $this->managedRulePayload($in)); }
    public function updateCacheRule(string $domainId, string $id, array $in): ?array { return $this->update('cache_rules', $domainId, $id, $in); }
    public function deleteCacheRule(string $domainId, string $id): bool { return $this->delete('cache_rules', $domainId, $id); }
    public function listPageRules(string $domainId): array { return $this->listRows('page_rules', $domainId); }
    public function createPageRule(string $domainId, array $in): array {
        return $this->insert('page_rules', $domainId, [
            'enabled' => !empty($in['enabled']),
            'priority' => (int) ($in['priority'] ?? 100),
            'pattern' => (string) ($in['pattern'] ?? ''),
            'actions_json' => json_encode(($in['actions'] ?? []), JSON_UNESCAPED_SLASHES),
        ]);
    }
    public function updatePageRule(string $domainId, string $id, array $in): ?array {
        if (array_key_exists('actions', $in)) {
            $in['actions_json'] = json_encode($in['actions'], JSON_UNESCAPED_SLASHES);
            unset($in['actions']);
        }
        return $this->update('page_rules', $domainId, $id, $in);
    }
    public function deletePageRule(string $domainId, string $id): bool { return $this->delete('page_rules', $domainId, $id); }
    public function testPageRule(string $domainId, string $path): array {
        $rules = array_values(array_filter($this->listPageRules($domainId), static fn (array $r): bool => !empty($r['enabled'])));
        usort($rules, static function (array $a, array $b): int {
            $p = ((int) ($a['priority'] ?? 100)) <=> ((int) ($b['priority'] ?? 100));
            if ($p !== 0) { return $p; }
            $l = strlen((string) ($b['pattern'] ?? '')) <=> strlen((string) ($a['pattern'] ?? ''));
            if ($l !== 0) { return $l; }
            return ((int) ($a['created_at'] ?? 0)) <=> ((int) ($b['created_at'] ?? 0));
        });
        foreach ($rules as $rule) {
            $pattern = (string) ($rule['pattern'] ?? '');
            $matched = str_ends_with($pattern, '*')
                ? str_starts_with($path, rtrim(substr($pattern, 0, -1), '/'))
                : $path === $pattern;
            if ($matched) {
                return ['matched' => true, 'rule' => $rule];
            }
        }
        return ['matched' => false];
    }
    public function listSslCertificates(string $domainId): array {
        $s = Database::pdo()->prepare('SELECT * FROM ssl_certificates WHERE domain_id=:domain_id ORDER BY hostname ASC');
        $s->execute([':domain_id' => $domainId]);
        return array_map([$this, 'cast'], $s->fetchAll());
    }
    public function getSslSettings(string $domainId): array {
        $s = Database::pdo()->prepare('SELECT * FROM domain_ssl_settings WHERE domain_id=:domain_id LIMIT 1');
        $s->execute([':domain_id' => $domainId]);
        $row = $s->fetch();
        if ($row) {
            return $this->cast((array) $row);
        }
        $now = time();
        Database::pdo()->prepare(
            'INSERT INTO domain_ssl_settings (domain_id,force_https,min_tls_version,auto_renew,created_at,updated_at)
             VALUES (:domain_id,false,:min_tls_version,true,:created_at,:updated_at)'
        )->execute([':domain_id' => $domainId, ':min_tls_version' => '1.2', ':created_at' => $now, ':updated_at' => $now]);
        return $this->getSslSettings($domainId);
    }
    public function setSslSettings(string $domainId, array $input): array {
        $current = $this->getSslSettings($domainId);
        $forceHttps = array_key_exists('force_https', $input) ? !empty($input['force_https']) : (bool) $current['force_https'];
        $minTlsVersion = (string) ($input['min_tls_version'] ?? $current['min_tls_version']);
        $autoRenew = array_key_exists('auto_renew', $input) ? !empty($input['auto_renew']) : (bool) $current['auto_renew'];
        if ($forceHttps && !$this->hasValidApexCertificate($domainId)) {
            throw new \DomainException('valid_ssl_certificate_required');
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE domain_ssl_settings SET force_https=:force_https,min_tls_version=:min_tls_version,auto_renew=:auto_renew,updated_at=:updated_at
                 WHERE domain_id=:domain_id'
            )->execute([
                ':domain_id' => $domainId,
                ':force_https' => (int) $forceHttps,
                ':min_tls_version' => $minTlsVersion,
                ':auto_renew' => (int) $autoRenew,
                ':updated_at' => time(),
            ]);
            $forceHttps ? $this->ensureForceHttpsRedirect($domainId) : $this->removeForceHttpsRedirect($domainId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $this->getSslSettings($domainId);
    }
    private function hasValidApexCertificate(string $domainId): bool {
        $s = Database::pdo()->prepare(
            "SELECT 1 FROM ssl_certificates c
             JOIN domains d ON d.id=c.domain_id
             WHERE c.domain_id=:domain_id AND lower(c.hostname)=lower(d.domain)
               AND c.status='active' AND c.not_after>:now
               AND c.certificate_pem IS NOT NULL AND c.private_key_pem IS NOT NULL
             LIMIT 1"
        );
        $s->execute([':domain_id' => $domainId, ':now' => time()]);
        return $s->fetchColumn() !== false;
    }
    private function ensureForceHttpsRedirect(string $domainId): void {
        $domain = Database::pdo()->prepare('SELECT domain FROM domains WHERE id=:id LIMIT 1');
        $domain->execute([':id' => $domainId]);
        $hostname = $domain->fetchColumn();
        if ($hostname === false) {
            throw new \OutOfBoundsException('domain_not_found');
        }
        $now = time();
        Database::pdo()->prepare(
            "INSERT INTO redirect_rules (id,domain_id,enabled,source_path,target_url,status_code,priority,match_type,preserve_query,managed_by,created_at,updated_at)
             VALUES (:id,:domain_id,true,'/',:target_url,308,1,'prefix',true,'force_https',:created_at,:updated_at)
             ON CONFLICT (domain_id,managed_by) WHERE managed_by='force_https'
             DO UPDATE SET enabled=true,source_path='/',target_url=EXCLUDED.target_url,status_code=308,priority=1,match_type='prefix',preserve_query=true,updated_at=EXCLUDED.updated_at"
        )->execute([
            ':id' => Uuid::v4(), ':domain_id' => $domainId, ':target_url' => 'https://' . strtolower((string) $hostname),
            ':created_at' => $now, ':updated_at' => $now,
        ]);
    }
    private function removeForceHttpsRedirect(string $domainId): void {
        Database::pdo()->prepare("DELETE FROM redirect_rules WHERE domain_id=:domain_id AND managed_by='force_https'")
            ->execute([':domain_id' => $domainId]);
    }
    public function requestSslCertificate(string $domainId, array $hostnames): array {
        $domain = $this->domainForSsl($domainId);
        if ($domain === null) {
            throw new \OutOfBoundsException('domain_not_found');
        }
        if ((string) $domain['status'] !== 'active') {
            throw new \DomainException('domain_must_be_active');
        }

        $targets = $hostnames === [] ? $this->defaultManagedSslHostnames((string) $domain['domain']) : $hostnames;
        $now = time();
        foreach ($targets as $hostname) {
            $h = strtolower(trim((string) $hostname));
            if ($h === '') {
                continue;
            }
            $s = Database::pdo()->prepare('SELECT id FROM ssl_certificates WHERE domain_id=:domain_id AND hostname=:hostname LIMIT 1');
            $s->execute([':domain_id' => $domainId, ':hostname' => $h]);
            $id = $s->fetchColumn();
            if ($id === false) {
                $i = Database::pdo()->prepare('INSERT INTO ssl_certificates (id,domain_id,hostname,provider,status,issuer,serial_number,not_before,not_after,days_until_expiry,renewal_due_at,last_checked_at,last_error,created_at,updated_at) VALUES (:id,:domain_id,:hostname,:provider,:status,:issuer,:serial,:not_before,:not_after,:days,:renewal,:checked,:error,:created,:updated)');
                $i->execute([
                    ':id' => Uuid::v4(), ':domain_id' => $domainId, ':hostname' => $h, ':provider' => 'acme', ':status' => 'pending',
                    ':issuer' => null, ':serial' => null, ':not_before' => null, ':not_after' => null, ':days' => null, ':renewal' => null,
                    ':checked' => $now, ':error' => null, ':created' => $now, ':updated' => $now,
                ]);
            } else {
                $u = Database::pdo()->prepare("UPDATE ssl_certificates SET provider=:provider,status=:status,last_checked_at=:checked,last_error=NULL,updated_at=:updated WHERE id=:id AND status IN ('missing','pending')");
                $u->execute([':provider' => 'acme', ':status' => 'pending', ':checked' => $now, ':updated' => $now, ':id' => $id]);
            }
        }
        return $this->listSslCertificates($domainId);
    }
    public function requestSslJob(string $domainId, array $hostnames): array {
        $domain = $this->domainForSsl($domainId);
        if ($domain === null) {
            throw new \OutOfBoundsException('domain_not_found');
        }
        $targets = $hostnames === [] ? $this->defaultManagedSslHostnames((string) $domain['domain']) : $hostnames;
        $normalized = [];
        foreach ($targets as $hostname) {
            $h = strtolower(trim((string) $hostname));
            if ($h !== '') {
                $normalized[] = $h;
            }
        }
        $normalized = array_values(array_unique($normalized));
        if ($normalized === []) {
            throw new \DomainException('ssl_hostnames_required');
        }

        $this->requestSslCertificate($domainId, $normalized);

        $now = time();
        $job = [
            'id' => Uuid::v4(),
            'domain_id' => $domainId,
            'status' => 'queued',
            'progress_percent' => 5,
            'message' => 'SSL request queued. DNS validation will start shortly.',
            'error_code' => null,
            'error_detail' => null,
            'hostnames' => $normalized,
            'created_at' => $now,
            'updated_at' => $now,
            'finished_at' => null,
        ];
        Database::pdo()->prepare(
            'INSERT INTO ssl_jobs
             (id,domain_id,status,progress_percent,message,error_code,error_detail,hostnames_json,created_at,updated_at,finished_at)
             VALUES
             (:id,:domain_id,:status,:progress_percent,:message,:error_code,:error_detail,:hostnames_json,:created_at,:updated_at,:finished_at)'
        )->execute([
            ':id' => $job['id'],
            ':domain_id' => $job['domain_id'],
            ':status' => $job['status'],
            ':progress_percent' => $job['progress_percent'],
            ':message' => $job['message'],
            ':error_code' => $job['error_code'],
            ':error_detail' => $job['error_detail'],
            ':hostnames_json' => json_encode($normalized, JSON_UNESCAPED_SLASHES),
            ':created_at' => $job['created_at'],
            ':updated_at' => $job['updated_at'],
            ':finished_at' => $job['finished_at'],
        ]);
        AuditLog::write('ssl.requested', 'ssl_job', (string) $job['id'], $domainId, null, $job);
        return [
            'job_id' => $job['id'],
            'status' => $job['status'],
            'message' => $job['message'],
            'job' => $job,
        ];
    }
    public function ensureManagedWildcardSslJob(string $domainId): ?array {
        $domain = $this->domainForSsl($domainId);
        if ($domain === null || (string) $domain['status'] !== 'active') {
            return null;
        }
        $hostnames = $this->defaultManagedSslHostnames((string) $domain['domain']);
        if ($this->hasActiveManagedCertificate($domainId, $hostnames) || $this->hasActiveSslJob($domainId, $hostnames)) {
            return null;
        }
        $settings = $this->getSslSettings($domainId);
        if (empty($settings['auto_renew'])) {
            $this->setSslSettings($domainId, ['auto_renew' => true]);
        }
        return $this->requestSslJob($domainId, $hostnames);
    }
    public function getSslJob(string $domainId, string $jobId): ?array {
        $s = Database::pdo()->prepare('SELECT * FROM ssl_jobs WHERE domain_id=:domain_id AND id=:id LIMIT 1');
        $s->execute([':domain_id' => $domainId, ':id' => $jobId]);
        $row = $s->fetch();
        return $row ? $this->castSslJob((array) $row) : null;
    }
    public function listSslJobs(string $domainId, int $limit = 20): array {
        $limit = max(1, min(100, $limit));
        $s = Database::pdo()->prepare("SELECT * FROM ssl_jobs WHERE domain_id=:domain_id ORDER BY created_at DESC LIMIT {$limit}");
        $s->execute([':domain_id' => $domainId]);
        return array_map([$this, 'castSslJob'], $s->fetchAll());
    }
    public function checkSslCertificates(string $domainId, array $hostnames): array {
        $now = time();
        $targets = $hostnames === [] ? [''] : $hostnames;
        foreach ($targets as $hostname) {
            $h = trim((string) $hostname);
            if ($h === '') {
                continue;
            }
            $s = Database::pdo()->prepare('SELECT id FROM ssl_certificates WHERE domain_id=:domain_id AND hostname=:hostname LIMIT 1');
            $s->execute([':domain_id' => $domainId, ':hostname' => $h]);
            $id = $s->fetchColumn();
            if ($id === false) {
                $i = Database::pdo()->prepare('INSERT INTO ssl_certificates (id,domain_id,hostname,provider,status,issuer,serial_number,not_before,not_after,days_until_expiry,renewal_due_at,last_checked_at,last_error,created_at,updated_at) VALUES (:id,:domain_id,:hostname,:provider,:status,:issuer,:serial,:not_before,:not_after,:days,:renewal,:checked,:error,:created,:updated)');
                $i->execute([
                    ':id' => Uuid::v4(), ':domain_id' => $domainId, ':hostname' => $h, ':provider' => 'manual', ':status' => 'missing',
                    ':issuer' => null, ':serial' => null, ':not_before' => null, ':not_after' => null, ':days' => null, ':renewal' => null,
                    ':checked' => $now, ':error' => 'certificate_not_provisioned', ':created' => $now, ':updated' => $now,
                ]);
            } else {
                $u = Database::pdo()->prepare('UPDATE ssl_certificates SET last_checked_at=:checked,last_error=:error,updated_at=:updated WHERE id=:id');
                $u->execute([':checked' => $now, ':error' => 'certificate_not_provisioned', ':updated' => $now, ':id' => $id]);
            }
        }
        return $this->listSslCertificates($domainId);
    }
    public function importManualSslCertificate(string $domainId, string $hostname, string $certificatePem, string $privateKeyPem): array {
        return $this->storeIssuedSslCertificate($domainId, $hostname, 'manual', $certificatePem, $privateKeyPem);
    }
    public function storeIssuedSslCertificate(string $domainId, string $hostname, string $provider, string $certificatePem, string $privateKeyPem): array {
        $cert = openssl_x509_read($certificatePem);
        if ($cert === false) {
            throw new \InvalidArgumentException('invalid_certificate_pem');
        }
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new \InvalidArgumentException('invalid_private_key_pem');
        }
        if (!openssl_x509_check_private_key($cert, $key)) {
            throw new \InvalidArgumentException('certificate_key_mismatch');
        }
        $parsed = openssl_x509_parse($cert) ?: [];
        $issuer = isset($parsed['issuer']) && is_array($parsed['issuer']) ? json_encode($parsed['issuer'], JSON_UNESCAPED_SLASHES) : null;
        $serial = isset($parsed['serialNumberHex']) ? (string) $parsed['serialNumberHex'] : null;
        $notBefore = isset($parsed['validFrom_time_t']) ? (int) $parsed['validFrom_time_t'] : null;
        $notAfter = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
        $now = time();
        $days = $notAfter !== null ? (int) floor(($notAfter - $now) / 86400) : null;
        $status = $notAfter !== null && $notAfter < $now ? 'expired' : 'active';
        $renewalDueAt = $notAfter !== null ? max($now, $notAfter - 30 * 86400) : null;
        if ($status !== 'active') {
            throw new \InvalidArgumentException('certificate_not_active');
        }

        $s = Database::pdo()->prepare('SELECT id FROM ssl_certificates WHERE domain_id=:domain_id AND hostname=:hostname LIMIT 1');
        $s->execute([':domain_id' => $domainId, ':hostname' => $hostname]);
        $id = $s->fetchColumn();
        if ($id === false) {
            $id = Uuid::v4();
            $i = Database::pdo()->prepare('INSERT INTO ssl_certificates (id,domain_id,hostname,provider,status,issuer,serial_number,not_before,not_after,days_until_expiry,renewal_due_at,last_checked_at,last_error,certificate_pem,private_key_pem,created_at,updated_at) VALUES (:id,:domain_id,:hostname,:provider,:status,:issuer,:serial,:not_before,:not_after,:days,:renewal,:checked,:error,:cert,:key,:created,:updated)');
            $i->execute([':id'=>$id,':domain_id'=>$domainId,':hostname'=>$hostname,':provider'=>$provider,':status'=>$status,':issuer'=>$issuer,':serial'=>$serial,':not_before'=>$notBefore,':not_after'=>$notAfter,':days'=>$days,':renewal'=>$renewalDueAt,':checked'=>$now,':error'=>null,':cert'=>$certificatePem,':key'=>Secrets::encrypt($privateKeyPem),':created'=>$now,':updated'=>$now]);
        } else {
            $u = Database::pdo()->prepare('UPDATE ssl_certificates SET provider=:provider,status=:status,issuer=:issuer,serial_number=:serial,not_before=:not_before,not_after=:not_after,days_until_expiry=:days,renewal_due_at=:renewal,last_checked_at=:checked,last_error=:error,certificate_pem=:cert,private_key_pem=:key,updated_at=:updated WHERE id=:id');
            $u->execute([':provider'=>$provider,':status'=>$status,':issuer'=>$issuer,':serial'=>$serial,':not_before'=>$notBefore,':not_after'=>$notAfter,':days'=>$days,':renewal'=>$renewalDueAt,':checked'=>$now,':error'=>null,':cert'=>$certificatePem,':key'=>Secrets::encrypt($privateKeyPem),':updated'=>$now,':id'=>$id]);
        }
        $this->invalidateConfigSnapshot();
        $q = Database::pdo()->prepare('SELECT * FROM ssl_certificates WHERE id=:id LIMIT 1');
        $q->execute([':id' => $id]);
        return $this->cast((array) $q->fetch());
    }
    private function domainForSsl(string $domainId): ?array {
        $s = Database::pdo()->prepare(
            "SELECT d.id,d.domain,d.status,
             (SELECT COUNT(*) FROM dns_records r WHERE r.domain_id=d.id) AS dns_record_count,
             CASE WHEN EXISTS (SELECT 1 FROM dns_records r WHERE r.domain_id=d.id AND r.proxied=true AND r.status='active') THEN 1 ELSE 0 END AS proxy_enabled
             FROM domains d WHERE d.id=:id LIMIT 1"
        );
        $s->execute([':id' => $domainId]);
        $row = $s->fetch();
        return $row ? (array) $row : null;
    }
    public function listSslCertificatesForConfig(string $domainId, string $host): array {
        $s = Database::pdo()->prepare("SELECT hostname,certificate_pem,private_key_pem,status FROM ssl_certificates WHERE domain_id=:domain_id AND status='active' AND certificate_pem IS NOT NULL AND private_key_pem IS NOT NULL");
        $s->execute([':domain_id' => $domainId]);
        $out = [];
        foreach ($s->fetchAll() as $r) {
            try {
                $decrypted = Secrets::decrypt((string) $r['private_key_pem']);
            } catch (\Throwable) {
                continue;
            }
            $out[] = [
                'host' => $host,
                'hostname' => (string) $r['hostname'],
                'certificate_pem' => (string) $r['certificate_pem'],
                'private_key_pem' => $decrypted,
                'status' => (string) $r['status'],
            ];
        }
        return $out;
    }
    private function defaultManagedSslHostnames(string $domain): array {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return [];
        }
        return [$domain, '*.' . $domain];
    }
    private function hasActiveManagedCertificate(string $domainId, array $hostnames): bool {
        if ($hostnames === []) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($hostnames), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(DISTINCT hostname) FROM ssl_certificates
             WHERE domain_id=? AND hostname IN ({$placeholders})
               AND provider='acme' AND status='active' AND not_after>?
               AND certificate_pem IS NOT NULL AND private_key_pem IS NOT NULL"
        );
        $stmt->execute(array_merge([$domainId], $hostnames, [time()]));
        return (int) $stmt->fetchColumn() === count($hostnames);
    }
    private function hasActiveSslJob(string $domainId, array $hostnames): bool {
        $wanted = array_values(array_unique(array_map(static fn (string $hostname): string => strtolower(trim($hostname)), $hostnames)));
        sort($wanted);
        $stmt = Database::pdo()->prepare(
            "SELECT hostnames_json FROM ssl_jobs
             WHERE domain_id=:domain_id
               AND status IN ('queued','checking_dns','creating_order','validating_challenge','issuing','installing')
             ORDER BY created_at DESC"
        );
        $stmt->execute([':domain_id' => $domainId]);
        foreach ($stmt->fetchAll() as $row) {
            $decoded = json_decode((string) ($row['hostnames_json'] ?? '[]'), true);
            if (!is_array($decoded)) {
                continue;
            }
            $current = array_values(array_unique(array_map(static fn ($hostname): string => strtolower(trim((string) $hostname)), $decoded)));
            sort($current);
            if ($current === $wanted) {
                return true;
            }
        }
        return false;
    }
    public function getDomainCacheSettings(string $domainId): array {
        $s = Database::pdo()->prepare('SELECT * FROM domain_cache_settings WHERE domain_id=:domain_id LIMIT 1');
        $s->execute([':domain_id' => $domainId]);
        $row = $s->fetch();
        if ($row) {
            return $this->cast((array) $row);
        }
        $now = time();
        $defaults = [
            'domain_id' => $domainId,
            'enabled' => true,
            'default_edge_ttl_seconds' => 3600,
            'default_browser_ttl_seconds' => null,
            'cache_query_string_mode' => 'include_all',
            'respect_origin_cache_control' => true,
            'cache_authorized_requests' => false,
            'stale_if_error_seconds' => 86400,
            'static_asset_cache_enabled' => false,
            'ignore_query_strings_for_static' => false,
            'bypass_logged_in_users' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $ins = Database::pdo()->prepare('INSERT INTO domain_cache_settings (domain_id,enabled,default_edge_ttl_seconds,default_browser_ttl_seconds,cache_query_string_mode,respect_origin_cache_control,cache_authorized_requests,stale_if_error_seconds,static_asset_cache_enabled,ignore_query_strings_for_static,bypass_logged_in_users,created_at,updated_at) VALUES (:domain_id,:enabled,:edge,:browser,:mode,:respect,:authorized,:stale,:static_assets,:ignore_static_query,:bypass_logged_in,:created_at,:updated_at)');
        $ins->execute([
            ':domain_id' => $domainId,
            ':enabled' => 1,
            ':edge' => 3600,
            ':browser' => null,
            ':mode' => 'include_all',
            ':respect' => 1,
            ':authorized' => 0,
            ':stale' => 86400,
            ':static_assets' => 0,
            ':ignore_static_query' => 0,
            ':bypass_logged_in' => 1,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        return $this->cast($defaults);
    }
    public function setDomainCacheSettings(string $domainId, array $in): array {
        $existing = $this->getDomainCacheSettings($domainId);
        $payload = [
            ':domain_id' => $domainId,
            ':enabled' => (int) ($in['enabled'] ?? $existing['enabled']),
            ':edge' => (int) ($in['default_edge_ttl_seconds'] ?? $existing['default_edge_ttl_seconds']),
            ':browser' => array_key_exists('default_browser_ttl_seconds', $in) ? $in['default_browser_ttl_seconds'] : $existing['default_browser_ttl_seconds'],
            ':mode' => (string) ($in['cache_query_string_mode'] ?? $existing['cache_query_string_mode']),
            ':respect' => (int) ($in['respect_origin_cache_control'] ?? $existing['respect_origin_cache_control']),
            ':authorized' => (int) ($in['cache_authorized_requests'] ?? $existing['cache_authorized_requests']),
            ':stale' => (int) ($in['stale_if_error_seconds'] ?? $existing['stale_if_error_seconds']),
            ':static_assets' => (int) ($in['static_asset_cache_enabled'] ?? $existing['static_asset_cache_enabled']),
            ':ignore_static_query' => (int) ($in['ignore_query_strings_for_static'] ?? $existing['ignore_query_strings_for_static']),
            ':bypass_logged_in' => (int) ($in['bypass_logged_in_users'] ?? $existing['bypass_logged_in_users']),
            ':updated_at' => time(),
        ];
        $u = Database::pdo()->prepare('UPDATE domain_cache_settings SET enabled=:enabled,default_edge_ttl_seconds=:edge,default_browser_ttl_seconds=:browser,cache_query_string_mode=:mode,respect_origin_cache_control=:respect,cache_authorized_requests=:authorized,stale_if_error_seconds=:stale,static_asset_cache_enabled=:static_assets,ignore_query_strings_for_static=:ignore_static_query,bypass_logged_in_users=:bypass_logged_in,updated_at=:updated_at WHERE domain_id=:domain_id');
        $u->execute($payload);
        $this->invalidateConfigSnapshot();
        return $this->getDomainCacheSettings($domainId);
    }
    public function createCachePurgeRequest(string $domainId, array $in): array {
        $type = (string) ($in['type'] ?? 'domain');
        $value = array_key_exists('value', $in) ? (string) $in['value'] : null;
        $scope = $type === 'everything' ? 'domain' : $type;
        $scopeValue = $scope === 'domain' ? '*' : (string) ($value ?? '*');
        $now = time();
        $requestId = Uuid::v4();

        $existing = Database::pdo()->prepare('SELECT * FROM cache_purge_versions WHERE domain_id=:domain_id AND scope=:scope AND value=:value LIMIT 1');
        $existing->execute([':domain_id' => $domainId, ':scope' => $scope, ':value' => $scopeValue]);
        $row = $existing->fetch();
        $beforeVersion = $row ? (int) $row['version'] : 1;
        $nextVersion = $beforeVersion + 1;
        if ($row) {
            $u = Database::pdo()->prepare('UPDATE cache_purge_versions SET version=:version,updated_at=:updated_at WHERE domain_id=:domain_id AND scope=:scope AND value=:value');
            $u->execute([':version' => $nextVersion, ':updated_at' => $now, ':domain_id' => $domainId, ':scope' => $scope, ':value' => $scopeValue]);
        } else {
            $i = Database::pdo()->prepare('INSERT INTO cache_purge_versions (id,domain_id,scope,value,version,updated_at) VALUES (:id,:domain_id,:scope,:value,:version,:updated_at)');
            $i->execute([':id' => Uuid::v4(), ':domain_id' => $domainId, ':scope' => $scope, ':value' => $scopeValue, ':version' => $nextVersion, ':updated_at' => $now]);
        }
        $r = Database::pdo()->prepare('INSERT INTO cache_purge_requests (id,domain_id,type,value,status,requested_by,edge_seen_count,error,created_at,updated_at,completed_at) VALUES (:id,:domain_id,:type,:value,:status,:requested_by,:edge_seen_count,:error,:created_at,:updated_at,:completed_at)');
        $r->execute([':id'=>$requestId,':domain_id'=>$domainId,':type'=>$type,':value'=>$value,':status'=>'completed',':requested_by'=>null,':edge_seen_count'=>0,':error'=>null,':created_at'=>$now,':updated_at'=>$now,':completed_at'=>$now]);
        $audit = Database::pdo()->prepare('INSERT INTO audit_log (id, actor_type, actor_id, action, resource_type, resource_id, domain_id, details_json, event, created_at) VALUES (:id,:actor_type,:actor_id,:action,:resource_type,:resource_id,:domain_id,:details_json,:event,:created_at)');
        $audit->execute([
            ':id' => Uuid::v4(),
            ':actor_type' => 'system',
            ':actor_id' => null,
            ':action' => 'purge',
            ':resource_type' => 'cache',
            ':resource_id' => $requestId,
            ':domain_id' => $domainId,
            ':details_json' => json_encode([
                'type' => $type,
                'value' => $value,
                'scope' => $scope,
                'scope_value' => $scopeValue,
                'version_before' => $beforeVersion,
                'version_after' => $nextVersion,
            ], JSON_UNESCAPED_SLASHES),
            ':event' => 'cache_purge_requested',
            ':created_at' => $now,
        ]);
        $q = Database::pdo()->prepare('SELECT * FROM cache_purge_requests WHERE id=:id LIMIT 1');
        $q->execute([':id' => $requestId]);
        return $this->cast((array) $q->fetch());
    }
    public function listCachePurgeRequests(string $domainId): array {
        $s = Database::pdo()->prepare('SELECT * FROM cache_purge_requests WHERE domain_id=:domain_id ORDER BY created_at DESC');
        $s->execute([':domain_id' => $domainId]);
        return array_map([$this, 'cast'], $s->fetchAll());
    }
    public function getCachePurgeRequest(string $domainId, string $id): ?array {
        $s = Database::pdo()->prepare('SELECT * FROM cache_purge_requests WHERE domain_id=:domain_id AND id=:id LIMIT 1');
        $s->execute([':domain_id' => $domainId, ':id' => $id]);
        $r = $s->fetch();
        return $r ? $this->cast((array) $r) : null;
    }
    public function listCachePurgeVersionsForConfig(string $domainId, string $host): array {
        $s = Database::pdo()->prepare('SELECT scope,value,version,updated_at FROM cache_purge_versions WHERE domain_id=:domain_id ORDER BY scope ASC, value ASC');
        $s->execute([':domain_id' => $domainId]);
        $rows = [];
        foreach ($s->fetchAll() as $r) {
            $rows[] = ['host' => $host, 'scope' => (string) $r['scope'], 'value' => (string) $r['value'], 'version' => (int) $r['version'], 'updated_at' => (int) $r['updated_at']];
        }
        return $rows;
    }
    public function listSecurityEvents(string $domainId, ?string $type = null, int $limit = 100): array {
        $query = "SELECT id,event,details_json,created_at FROM audit_log
                  WHERE domain_id=:domain_id
                    AND event IN ('waf_match','rate_limited','bot_match','geo_block')
                    AND (event IN ('waf_match','rate_limited','geo_block') OR event='bot_match')";
        $params = [':domain_id' => $domainId];
        if ($type !== null && $type !== '') {
            $query .= ' AND event=:event';
            $params[':event'] = $type;
        }
        $query .= ' ORDER BY created_at DESC LIMIT :limit';
        $stmt = Database::pdo()->prepare($query);
        $stmt->bindValue(':domain_id', $domainId);
        if (array_key_exists(':event', $params)) {
            $stmt->bindValue(':event', $params[':event']);
        }
        $stmt->bindValue(':limit', max(1, min(500, $limit)), \PDO::PARAM_INT);
        $stmt->execute();
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'id' => (string) $row['id'],
                'type' => (string) $row['event'],
                'details' => json_decode((string) ($row['details_json'] ?? '{}'), true) ?: [],
                'created_at' => (int) $row['created_at'],
            ];
        }
        return $rows;
    }

    public function listRateLimits(string $domainId): array {
        $s = Database::pdo()->prepare('SELECT * FROM rate_limit_rules WHERE domain_id=:domain_id ORDER BY priority ASC, created_at ASC');
        $s->execute([':domain_id' => $domainId]);
        return array_map([$this, 'cast'], $s->fetchAll());
    }
    public function createRateLimit(string $domainId, array $in): array {
        return $this->insert('rate_limit_rules', $domainId, $this->rateLimitPayload($in) + $this->managedRulePayload($in));
    }
    public function updateRateLimit(string $domainId, string $id, array $in): ?array {
        return $this->update('rate_limit_rules', $domainId, $id, $this->rateLimitPayload($in, true));
    }
    public function deleteRateLimit(string $domainId, string $id): bool {
        return $this->delete('rate_limit_rules', $domainId, $id);
    }
    public function dryRunRateLimit(string $domainId, array $in): array {
        $payload = $this->rateLimitPayload($in);
        $pathPrefix = (string) ($payload['path_prefix'] ?? '/');
        $windowSeconds = (int) ($payload['window_seconds'] ?? 60);
        $since = time() - max(60, $windowSeconds);
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(requests_count), 0) FROM usage_rollups
             WHERE domain_id=:domain_id AND ts>=:since AND path LIKE :path_prefix'
        );
        $stmt->execute([
            ':domain_id' => $domainId,
            ':since' => $since,
            ':path_prefix' => rtrim($pathPrefix, '%') . '%',
        ]);
        $wouldMatch = (int) $stmt->fetchColumn();
        return [
            'rule' => [
                'enabled' => !empty($payload['enabled']),
                'priority' => (int) ($payload['priority'] ?? 100),
                'path_prefix' => $pathPrefix,
                'key_type' => (string) ($payload['key_type'] ?? 'ip'),
                'key_header_name' => $payload['key_header_name'] ?? null,
                'requests_per_minute' => (int) ($payload['requests_per_minute'] ?? 60),
                'action' => (string) ($payload['action'] ?? 'block'),
            ],
            'preview_impact' => [
                'lookback_seconds' => 86400,
                'would_have_matched_24h' => $wouldMatch,
            ],
            'mutates' => false,
        ];
    }
    public function detachManagedRule(string $domainId, string $ruleType, string $id): ?array {
        $table = match ($ruleType) {
            'waf_rule', 'waf' => 'waf_rules',
            'rate_limit', 'rate-limit' => 'rate_limit_rules',
            'cache_rule', 'cache' => 'cache_rules',
            'header_rule', 'header' => 'domain_header_rules',
            'ip_rule', 'ip' => 'domain_ip_rules',
            default => throw new \InvalidArgumentException('invalid_rule_type'),
        };
        $q = Database::pdo()->prepare("SELECT * FROM {$table} WHERE id=:id AND domain_id=:domain LIMIT 1");
        $q->execute([':id' => $id, ':domain' => $domainId]);
        $before = $q->fetch();
        if (!$before) {
            return null;
        }

        $now = time();
        Database::pdo()->prepare(
            "UPDATE {$table}
             SET profile_id=NULL,intent_id=NULL,template_key=NULL,managed_by=NULL,user_modified=false,last_generated_at=NULL,last_applied_at=NULL,updated_at=:updated_at
             WHERE id=:id AND domain_id=:domain"
        )->execute([':updated_at' => $now, ':id' => $id, ':domain' => $domainId]);
        Database::pdo()->prepare('UPDATE managed_rule_links SET detached_at=:detached_at,updated_at=:updated_at WHERE rule_table=:rule_table AND rule_id=:rule_id AND detached_at IS NULL')
            ->execute([':detached_at' => $now, ':updated_at' => $now, ':rule_table' => $table, ':rule_id' => $id]);
        $r = Database::pdo()->prepare("SELECT * FROM {$table} WHERE id=:id");
        $r->execute([':id' => $id]);
        $updated = $this->cast((array) $r->fetch());
        AuditLog::write('protection_rule.detach', $this->auditResource($table), $id, $domainId, $this->cast((array) $before), $updated);
        $this->invalidateConfigSnapshot();
        return $updated;
    }

    public function listProtectionIntents(string $domainId): array {
        $templates = $this->protectionIntentTemplates();
        $rows = Database::pdo()->prepare('SELECT * FROM protection_intents WHERE domain_id=:domain_id AND profile_id IS NULL ORDER BY created_at ASC');
        $rows->execute([':domain_id' => $domainId]);
        $saved = [];
        foreach ($rows->fetchAll() as $row) {
            $cast = $this->castProtectionIntent((array) $row);
            $saved[(string) $cast['intent_key']] = $cast;
        }

        $out = [];
        foreach ($templates as $key => $template) {
            $intent = $saved[$key] ?? null;
            $out[] = [
                'intent_key' => $key,
                'name' => $template['name'],
                'summary' => $template['summary'],
                'risk' => $template['risk'],
                'recommended_mode' => $template['mode'],
                'status' => $intent['status'] ?? 'available',
                'intent' => $intent,
                'generated_rules' => $intent ? $this->managedRulesForIntent($domainId, (string) $intent['id']) : [],
            ];
        }
        return $out;
    }

    public function listProtectionProfiles(string $domainId): array {
        $templates = $this->protectionProfileTemplates();
        $rows = Database::pdo()->prepare('SELECT * FROM protection_profiles WHERE domain_id=:domain_id ORDER BY created_at ASC');
        $rows->execute([':domain_id' => $domainId]);
        $saved = [];
        foreach ($rows->fetchAll() as $row) {
            $cast = $this->castProtectionProfile((array) $row);
            $saved[(string) $cast['profile_key']] = $cast;
        }

        $out = [];
        foreach ($templates as $key => $template) {
            $profile = $saved[$key] ?? null;
            $out[] = [
                'profile_key' => $key,
                'name' => $template['name'],
                'summary' => $template['summary'],
                'risk' => $template['risk'],
                'intent_keys' => $template['intent_keys'],
                'status' => $profile['status'] ?? 'available',
                'profile' => $profile,
            ];
        }
        return $out;
    }

    public function managedWafPresets(string $domainId): array {
        $rulesByGroup = [];
        foreach ($this->protectionIntentTemplates() as $intentKey => $intent) {
            foreach ($intent['rules'] as $rule) {
                if (($rule['rule_table'] ?? '') !== 'waf_rules') {
                    continue;
                }
                $payload = $rule['payload'] ?? [];
                $groupId = (string) ($payload['waf_group_id'] ?? '');
                if ($groupId === '') {
                    continue;
                }
                $rulesByGroup[$groupId][] = [
                    'intent_key' => (string) $intentKey,
                    'template_key' => (string) $rule['template_key'],
                    'name' => (string) ($payload['name'] ?? $rule['template_key']),
                    'type' => (string) ($payload['type'] ?? ''),
                    'pattern' => (string) ($payload['pattern'] ?? ''),
                    'default_action' => (string) ($payload['action'] ?? 'log'),
                    'severity' => (string) ($payload['waf_severity'] ?? ''),
                    'confidence' => (string) ($payload['waf_confidence'] ?? ''),
                    'safe_reason' => (string) ($payload['waf_safe_reason'] ?? ''),
                ];
            }
        }

        $groups = [];
        foreach ($this->managedWafGroups() as $groupId => $group) {
            $groups[] = $group + [
                'group_id' => $groupId,
                'rules' => $rulesByGroup[$groupId] ?? [],
            ];
        }

        return [
            'domain_id' => $domainId,
            'modes' => array_values($this->managedWafModes()),
            'groups' => $groups,
            'mutates' => false,
        ];
    }

    public function smartRateLimitTemplates(string $domainId): array {
        $templates = [];
        foreach ($this->smartRateLimitTemplateDefinitions() as $intentKey => $template) {
            $rule = $template['rule'];
            $templates[] = [
                'intent_key' => $intentKey,
                'name' => $template['name'],
                'summary' => $template['summary'],
                'risk' => $template['risk'],
                'recommended_mode' => $template['mode'],
                'default_paths' => $template['default_paths'],
                'rule' => $rule,
                'preview_impact' => $this->smartRateLimitImpact($domainId, (string) $rule['path_prefix'], 86400),
                'mutates' => false,
            ];
        }
        return [
            'domain_id' => $domainId,
            'window_seconds' => 60,
            'templates' => $templates,
            'mutates' => false,
        ];
    }

    public function previewProtectionProfile(string $domainId, string $profileKey, array $input = []): array {
        $template = $this->protectionProfileTemplate($profileKey);
        $intents = [];
        foreach ($template['intent_keys'] as $intentKey) {
            $intents[] = $this->previewProtectionIntent($domainId, (string) $intentKey, $input);
        }
        return [
            'profile_key' => $profileKey,
            'name' => $template['name'],
            'risk' => $template['risk'],
            'intent_keys' => $template['intent_keys'],
            'intents' => $intents,
            'mutates' => false,
        ];
    }

    public function applyProtectionProfile(string $domainId, string $profileKey, array $input = []): array {
        $template = $this->protectionProfileTemplate($profileKey);
        $profile = $this->upsertProtectionProfile($domainId, $profileKey, $template, 'enabled');
        $before = $profile;
        $results = [];
        foreach ($template['intent_keys'] as $intentKey) {
            $results[] = $this->enableProtectionIntentForProfile($domainId, (string) $intentKey, (string) $profile['id'], $template, $input);
        }
        $after = ['profile' => $this->getProtectionProfile($domainId, (string) $profile['id']), 'intents' => $results];
        $this->recordProfileChange($domainId, (string) $profile['id'], null, 'protection_profile.apply', $before, $after);
        AuditLog::write('protection_profile.apply', 'protection_profile', (string) $profile['id'], $domainId, $before, $after);
        $this->invalidateConfigSnapshot();
        return $after;
    }

    public function disableProtectionProfile(string $domainId, string $profileId, array $input = []): ?array {
        $profile = $this->getProtectionProfile($domainId, $profileId);
        if ($profile === null) {
            return null;
        }
        $rows = Database::pdo()->prepare('SELECT * FROM protection_intents WHERE domain_id=:domain_id AND profile_id=:profile_id ORDER BY created_at ASC');
        $rows->execute([':domain_id' => $domainId, ':profile_id' => $profileId]);
        $results = [];
        foreach ($rows->fetchAll() as $row) {
            $results[] = $this->disableProtectionIntentForProfile($domainId, (string) $row['id'], $profileId, $input);
        }
        Database::pdo()->prepare('UPDATE protection_profiles SET status=:status,updated_at=:updated_at WHERE id=:id AND domain_id=:domain_id')
            ->execute([':status' => 'disabled', ':updated_at' => time(), ':id' => $profileId, ':domain_id' => $domainId]);
        $after = ['profile' => $this->getProtectionProfile($domainId, $profileId), 'intents' => $results];
        $this->recordProfileChange($domainId, $profileId, null, 'protection_profile.disable', $profile, $after);
        AuditLog::write('protection_profile.disable', 'protection_profile', $profileId, $domainId, $profile, $after);
        $this->invalidateConfigSnapshot();
        return $after;
    }

    public function previewProtectionIntent(string $domainId, string $intentKey, array $input = []): array {
        $template = $this->protectionIntentTemplate($intentKey);
        return [
            'intent_key' => $intentKey,
            'name' => $template['name'],
            'mode' => (string) ($input['mode'] ?? $template['mode']),
            'risk' => $template['risk'],
            'rules' => $this->generatedRulesForIntent($domainId, $intentKey, null, null, $input),
            'mutates' => false,
        ];
    }

    public function enableProtectionIntent(string $domainId, string $intentKey, array $input = []): array {
        return $this->enableProtectionIntentForProfile($domainId, $intentKey, null, null, $input);
    }

    private function enableProtectionIntentForProfile(string $domainId, string $intentKey, ?string $profileId, ?array $profileTemplate, array $input = []): array {
        $template = $this->protectionIntentTemplate($intentKey);
        if ($profileTemplate !== null) {
            $template['name'] = (string) $template['name'] . ' (' . (string) $profileTemplate['name'] . ')';
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $intent = $this->upsertProtectionIntent($domainId, $intentKey, $template, $input, 'enabled', $profileId);
            $this->assertManagedRulesCanBeRegenerated($domainId, (string) $intent['id'], !empty($input['confirm_overwrite']));
            $rollbackId = $this->createRollbackPoint($domainId, (string) $intent['id'], 'Before enabling ' . $template['name'], $profileId);
            $rules = $this->applyGeneratedRules($domainId, (string) $intent['id'], $intentKey, $profileId, $input, $profileTemplate);
            $after = ['intent' => $this->getProtectionIntent($domainId, (string) $intent['id']), 'rules' => $rules, 'rollback_point_id' => $rollbackId];
            $this->recordProfileChange($domainId, $profileId, (string) $intent['id'], 'protection_intent.enable', null, $after);
            AuditLog::write('protection_intent.enable', 'protection_intent', (string) $intent['id'], $domainId, null, $after);
            $pdo->commit();
            return $after;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function disableProtectionIntent(string $domainId, string $intentId, array $input = []): ?array {
        return $this->disableProtectionIntentForProfile($domainId, $intentId, null, $input);
    }

    private function disableProtectionIntentForProfile(string $domainId, string $intentId, ?string $profileId, array $input = []): ?array {
        $intent = $this->getProtectionIntent($domainId, $intentId);
        if ($intent === null) {
            return null;
        }
        $this->assertManagedRulesCanBeRegenerated($domainId, $intentId, !empty($input['confirm_overwrite']));
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $rollbackId = $this->createRollbackPoint($domainId, $intentId, 'Before disabling ' . (string) $intent['name'], $profileId);
            $now = time();
            foreach ($this->managedRulesForIntent($domainId, $intentId) as $link) {
                $table = (string) $link['rule_table'];
                $id = (string) $link['rule_id'];
                Database::pdo()->prepare("UPDATE {$table} SET enabled=false,last_applied_at=:last_applied_at,updated_at=:updated_at WHERE id=:id AND domain_id=:domain_id")
                    ->execute([':last_applied_at' => $now, ':updated_at' => $now, ':id' => $id, ':domain_id' => $domainId]);
            }
            Database::pdo()->prepare('UPDATE protection_intents SET status=:status,updated_at=:updated_at WHERE id=:id AND domain_id=:domain_id')
                ->execute([':status' => 'disabled', ':updated_at' => $now, ':id' => $intentId, ':domain_id' => $domainId]);
            $after = ['intent' => $this->getProtectionIntent($domainId, $intentId), 'rules' => $this->managedRulesForIntent($domainId, $intentId), 'rollback_point_id' => $rollbackId];
            $this->recordProfileChange($domainId, $profileId, $intentId, 'protection_intent.disable', $intent, $after);
            AuditLog::write('protection_intent.disable', 'protection_intent', $intentId, $domainId, $intent, $after);
            $this->invalidateConfigSnapshot();
            $pdo->commit();
            return $after;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function undoProtectionIntent(string $domainId, string $intentId): ?array {
        $intent = $this->getProtectionIntent($domainId, $intentId);
        if ($intent === null) {
            return null;
        }
        $q = Database::pdo()->prepare('SELECT * FROM profile_rollback_points WHERE domain_id=:domain_id AND intent_id=:intent_id ORDER BY created_at DESC LIMIT 1');
        $q->execute([':domain_id' => $domainId, ':intent_id' => $intentId]);
        $rollback = $q->fetch();
        if (!$rollback) {
            throw new \DomainException('rollback_point_not_found');
        }
        $snapshot = json_decode((string) $rollback['snapshot_json'], true) ?: [];
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $now = time();
            foreach (($snapshot['rules'] ?? []) as $rule) {
                if (!is_array($rule) || empty($rule['rule_table']) || empty($rule['rule_id'])) {
                    continue;
                }
                $table = (string) $rule['rule_table'];
                $id = (string) $rule['rule_id'];
                $enabled = !empty($rule['enabled']) ? 1 : 0;
                Database::pdo()->prepare("UPDATE {$table} SET enabled=:enabled,last_applied_at=:last_applied_at,updated_at=:updated_at WHERE id=:id AND domain_id=:domain_id")
                    ->execute([':enabled' => $enabled, ':last_applied_at' => $now, ':updated_at' => $now, ':id' => $id, ':domain_id' => $domainId]);
            }
            if (isset($snapshot['intent']['status'])) {
                Database::pdo()->prepare('UPDATE protection_intents SET status=:status,updated_at=:updated_at WHERE id=:id AND domain_id=:domain_id')
                    ->execute([':status' => (string) $snapshot['intent']['status'], ':updated_at' => $now, ':id' => $intentId, ':domain_id' => $domainId]);
            }
            $after = ['intent' => $this->getProtectionIntent($domainId, $intentId), 'rules' => $this->managedRulesForIntent($domainId, $intentId), 'rollback_point_id' => (string) $rollback['id']];
            $this->recordProfileChange($domainId, null, $intentId, 'protection_intent.undo', $intent, $after);
            AuditLog::write('protection_intent.undo', 'protection_intent', $intentId, $domainId, $intent, $after);
            $this->invalidateConfigSnapshot();
            $pdo->commit();
            return $after;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function listRows(string $table, string $domainId, string $orderBy = 'created_at ASC'): array { $s=Database::pdo()->prepare("SELECT * FROM {$table} WHERE domain_id=:domain_id ORDER BY {$orderBy}"); $s->execute([':domain_id'=>$domainId]); return array_map([$this,'cast'], $s->fetchAll()); }
    private function insert(string $table, string $domainId, array $in): array {
        $id=Uuid::v4(); $now=time(); $cols=array_keys($in); $names=implode(',', $cols); $bind=':'.implode(',:', $cols);
        $sql="INSERT INTO {$table} (id,domain_id,{$names},created_at,updated_at) VALUES (:id,:domain_id,{$bind},:created_at,:updated_at)";
        $p=[':id'=>$id,':domain_id'=>$domainId,':created_at'=>$now,':updated_at'=>$now]; foreach($in as $k=>$v){$p[':'.$k]=is_bool($v)?(int)$v:$v;}
        $s=Database::pdo()->prepare($sql); $s->execute($p); $q=Database::pdo()->prepare("SELECT * FROM {$table} WHERE id=:id"); $q->execute([':id'=>$id]); $created=$this->cast((array)$q->fetch());
        $this->storeManagedRuleLink($table, $domainId, $id, $created);
        AuditLog::write($this->auditResource($table).'.create', $this->auditResource($table), $id, $domainId, null, $created);
        $this->invalidateConfigSnapshot();
        return $created;
    }
    private function update(string $table, string $domainId, string $id, array $in): ?array {
        $q=Database::pdo()->prepare("SELECT * FROM {$table} WHERE id=:id AND domain_id=:domain LIMIT 1"); $q->execute([':id'=>$id,':domain'=>$domainId]); $before=$q->fetch(); if(!$before){return null;}
        if (($before['managed_by'] ?? null) !== null && !array_key_exists('user_modified', $in)) {
            $this->markUserModifiedForManagedRule($in);
        }
        $sets=[]; $p=[':id'=>$id,':domain'=>$domainId,':u'=>time()];
        foreach($in as $k=>$v){$sets[]="{$k}=:{$k}"; $p[':'.$k]=is_bool($v)?(int)$v:$v;}
        $sets[]='updated_at=:u';
        $s=Database::pdo()->prepare("UPDATE {$table} SET ".implode(',', $sets)." WHERE id=:id AND domain_id=:domain"); $s->execute($p);
        $r=Database::pdo()->prepare("SELECT * FROM {$table} WHERE id=:id"); $r->execute([':id'=>$id]); $updated=$this->cast((array)$r->fetch());
        $this->syncManagedRuleLink($table, $domainId, $id, $updated);
        AuditLog::write($this->auditResource($table).'.update', $this->auditResource($table), $id, $domainId, $this->cast((array)$before), $updated);
        $this->invalidateConfigSnapshot();
        return $updated;
    }
    private function delete(string $table, string $domainId, string $id): bool {
        $q=Database::pdo()->prepare("SELECT * FROM {$table} WHERE id=:id AND domain_id=:domain LIMIT 1"); $q->execute([':id'=>$id,':domain'=>$domainId]); $before=$q->fetch();
        if (!$before) { return false; }
        $s=Database::pdo()->prepare("DELETE FROM {$table} WHERE id=:id AND domain_id=:domain"); $s->execute([':id'=>$id,':domain'=>$domainId]);
        if ($s->rowCount() > 0) { AuditLog::write($this->auditResource($table).'.delete', $this->auditResource($table), $id, $domainId, $this->cast((array)$before), null); $this->invalidateConfigSnapshot(); return true; }
        return false;
    }
    private function invalidateConfigSnapshot(): void {
        Database::pdo()->exec('UPDATE config_state SET active_snapshot_version = NULL WHERE id = 1');
    }
    private function managedWafModes(): array {
        return [
            'log_only' => [
                'mode' => 'log_only',
                'name' => 'Log only',
                'summary' => 'Records matches without blocking so operators can learn false-positive risk first.',
                'default_action' => 'log',
                'requires_confirmation' => false,
            ],
            'challenge_suspicious' => [
                'mode' => 'challenge_suspicious',
                'name' => 'Challenge suspicious',
                'summary' => 'Uses friction for medium-confidence traffic before escalating to block.',
                'default_action' => 'challenge',
                'requires_confirmation' => false,
            ],
            'block_high_confidence' => [
                'mode' => 'block_high_confidence',
                'name' => 'Block high-confidence',
                'summary' => 'Blocks rules with high confidence while lower-confidence matches remain observable.',
                'default_action' => 'block',
                'requires_confirmation' => false,
            ],
            'strict_block' => [
                'mode' => 'strict_block',
                'name' => 'Strict block',
                'summary' => 'Blocks broader exploit patterns and should be previewed before use.',
                'default_action' => 'block',
                'requires_confirmation' => true,
            ],
        ];
    }
    private function managedWafGroups(): array {
        return [
            'sql_injection' => ['name' => 'SQL Injection', 'severity' => 'high', 'recommended_mode' => 'block_high_confidence'],
            'cross_site_scripting' => ['name' => 'Cross-Site Scripting', 'severity' => 'high', 'recommended_mode' => 'block_high_confidence'],
            'path_traversal' => ['name' => 'Path Traversal', 'severity' => 'high', 'recommended_mode' => 'block_high_confidence'],
            'local_file_inclusion' => ['name' => 'Local File Inclusion', 'severity' => 'high', 'recommended_mode' => 'block_high_confidence'],
            'remote_file_inclusion' => ['name' => 'Remote File Inclusion', 'severity' => 'high', 'recommended_mode' => 'block_high_confidence'],
            'command_injection' => ['name' => 'Command Injection', 'severity' => 'high', 'recommended_mode' => 'block_high_confidence'],
            'php_exploit' => ['name' => 'PHP Exploit Patterns', 'severity' => 'high', 'recommended_mode' => 'block_high_confidence'],
            'wordpress_exploit' => ['name' => 'WordPress Exploit Patterns', 'severity' => 'medium', 'recommended_mode' => 'challenge_suspicious'],
            'scanner_recon' => ['name' => 'Scanner / Recon Tools', 'severity' => 'medium', 'recommended_mode' => 'challenge_suspicious'],
            'suspicious_encodings' => ['name' => 'Suspicious Encodings', 'severity' => 'medium', 'recommended_mode' => 'log_only'],
            'known_bad_user_agents' => ['name' => 'Known Bad User Agents', 'severity' => 'medium', 'recommended_mode' => 'challenge_suspicious'],
            'api_method_probe' => ['name' => 'API Method Probes', 'severity' => 'medium', 'recommended_mode' => 'log_only'],
            'suspicious_bot' => ['name' => 'Suspicious Bot Signals', 'severity' => 'medium', 'recommended_mode' => 'challenge_suspicious'],
            'checkout_probe' => ['name' => 'Checkout Probes', 'severity' => 'medium', 'recommended_mode' => 'log_only'],
        ];
    }
    private function smartRateLimitTemplateDefinitions(): array {
        return [
            'login_protection' => [
                'name' => 'Login Protection',
                'summary' => 'Challenge or limit repeated requests to common login paths.',
                'risk' => 'moderate',
                'mode' => 'challenge_first',
                'default_paths' => ['/login', '/admin', '/wp-login.php'],
                'rule' => ['rule_table' => 'rate_limit_rules', 'template_key' => 'rate_login_paths', 'enabled' => true, 'priority' => 20, 'path_prefix' => '/login', 'key_type' => 'ip_path', 'requests_per_minute' => 10, 'window_seconds' => 60, 'action' => 'block', 'duration_seconds' => 600],
            ],
            'api_protection' => [
                'name' => 'API Protection',
                'summary' => 'Apply API-friendly 429 limits to API paths.',
                'risk' => 'moderate',
                'mode' => 'recommended',
                'default_paths' => ['/api/'],
                'rule' => ['rule_table' => 'rate_limit_rules', 'template_key' => 'rate_api_paths', 'enabled' => true, 'priority' => 30, 'path_prefix' => '/api/', 'key_type' => 'ip_path', 'requests_per_minute' => 120, 'window_seconds' => 60, 'action' => 'block', 'duration_seconds' => 300],
            ],
            'form_spam' => [
                'name' => 'Form Spam',
                'summary' => 'Limit repeated POSTs to forms such as contact and signup pages.',
                'risk' => 'moderate',
                'mode' => 'challenge_first',
                'default_paths' => ['/contact', '/signup'],
                'rule' => ['rule_table' => 'rate_limit_rules', 'template_key' => 'rate_form_spam', 'enabled' => true, 'priority' => 34, 'path_prefix' => '/contact', 'key_type' => 'ip_path', 'requests_per_minute' => 5, 'window_seconds' => 60, 'action' => 'block', 'duration_seconds' => 900],
            ],
            'expensive_pages' => [
                'name' => 'Expensive Pages',
                'summary' => 'Protect selected expensive paths after reviewing recent traffic.',
                'risk' => 'moderate',
                'mode' => 'preview_first',
                'default_paths' => ['/'],
                'rule' => ['rule_table' => 'rate_limit_rules', 'template_key' => 'rate_expensive_pages', 'enabled' => true, 'priority' => 45, 'path_prefix' => '/', 'key_type' => 'ip_path', 'requests_per_minute' => 30, 'window_seconds' => 60, 'action' => 'block', 'duration_seconds' => 300],
            ],
            'emergency_traffic_limit' => [
                'name' => 'Emergency Traffic Limit',
                'summary' => 'Temporarily tighten site-wide request volume during an active incident.',
                'risk' => 'risky',
                'mode' => 'confirm_first',
                'default_paths' => ['/'],
                'rule' => ['rule_table' => 'rate_limit_rules', 'template_key' => 'rate_emergency_sitewide', 'enabled' => true, 'priority' => 5, 'path_prefix' => '/', 'key_type' => 'ip', 'requests_per_minute' => 300, 'window_seconds' => 60, 'action' => 'block', 'duration_seconds' => 600],
            ],
        ];
    }
    private function smartRateLimitImpact(string $domainId, string $pathPrefix, int $lookbackSeconds): array {
        $since = time() - max(60, $lookbackSeconds);
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(requests_count), 0) FROM usage_rollups
             WHERE domain_id=:domain_id AND ts>=:since AND path LIKE :path_prefix'
        );
        $stmt->execute([
            ':domain_id' => $domainId,
            ':since' => $since,
            ':path_prefix' => rtrim($pathPrefix, '%') . '%',
        ]);
        return [
            'lookback_seconds' => $lookbackSeconds,
            'would_have_matched_24h' => (int) $stmt->fetchColumn(),
        ];
    }
    private function protectionIntentTemplates(): array {
        return [
            'common_exploits' => [
                'name' => 'Common Exploit Protection',
                'summary' => 'Blocks high-confidence traversal and scanner patterns.',
                'risk' => 'safe',
                'mode' => 'recommended',
                'rules' => [
                    ['rule_table' => 'waf_rules', 'template_key' => 'waf_path_traversal', 'payload' => ['enabled' => true, 'name' => 'Block path traversal', 'priority' => 20, 'type' => 'path_contains', 'pattern' => '../', 'action' => 'block', 'description' => 'Generated by common exploit protection.', 'waf_group_id' => 'path_traversal', 'waf_severity' => 'high', 'waf_confidence' => 'high', 'waf_safe_reason' => 'Blocks a high-confidence traversal sequence outside normal URL paths.']],
                    ['rule_table' => 'waf_rules', 'template_key' => 'waf_scanner_agent', 'payload' => ['enabled' => true, 'name' => 'Block scanner user agents', 'priority' => 30, 'type' => 'user_agent_contains', 'pattern' => 'sqlmap', 'action' => 'block', 'description' => 'Generated by common exploit protection.', 'waf_group_id' => 'scanner_recon', 'waf_severity' => 'medium', 'waf_confidence' => 'high', 'waf_safe_reason' => 'Matches a well-known automated scanner user agent.']],
                ],
            ],
            'login_shield' => [
                'name' => 'Login Shield',
                'summary' => 'Protects common login paths with challenge-safe rate limits.',
                'risk' => 'moderate',
                'mode' => 'recommended',
                'rules' => [
                    ['rule_table' => 'rate_limit_rules', 'template_key' => 'rate_login_paths', 'payload' => ['enabled' => true, 'priority' => 20, 'path_prefix' => '/login', 'key_type' => 'ip_path', 'requests_per_minute' => 10, 'action' => 'block']],
                    ['rule_table' => 'rate_limit_rules', 'template_key' => 'rate_wp_login', 'payload' => ['enabled' => true, 'priority' => 21, 'path_prefix' => '/wp-login.php', 'key_type' => 'ip_path', 'requests_per_minute' => 10, 'action' => 'block']],
                ],
            ],
            'protect_api' => [
                'name' => 'API Shield',
                'summary' => 'Protects API paths from request bursts and obvious probing.',
                'risk' => 'moderate',
                'mode' => 'recommended',
                'rules' => [
                    ['rule_table' => 'rate_limit_rules', 'template_key' => 'rate_api_paths', 'payload' => ['enabled' => true, 'priority' => 30, 'path_prefix' => '/api/', 'key_type' => 'ip_path', 'requests_per_minute' => 120, 'action' => 'block']],
                    ['rule_table' => 'waf_rules', 'template_key' => 'waf_api_method_probe', 'payload' => ['enabled' => true, 'name' => 'Log suspicious API method probes', 'priority' => 70, 'type' => 'method_is', 'pattern' => 'TRACE', 'action' => 'log', 'description' => 'Generated by API Shield to surface unusual API method probes before blocking.', 'waf_group_id' => 'api_method_probe', 'waf_severity' => 'medium', 'waf_confidence' => 'medium', 'waf_safe_reason' => 'Runs in log mode because method probes can overlap with diagnostics.']],
                ],
            ],
            'smart_rate_limiting' => [
                'name' => 'Smart Rate Limiting',
                'summary' => 'Adds conservative site-wide abuse limits without hiding the advanced rule.',
                'risk' => 'moderate',
                'mode' => 'recommended',
                'rules' => [
                    ['rule_table' => 'rate_limit_rules', 'template_key' => 'rate_site_abuse', 'payload' => ['enabled' => true, 'priority' => 60, 'path_prefix' => '/', 'key_type' => 'ip', 'requests_per_minute' => 300, 'action' => 'block']],
                ],
            ],
            'bot_shield' => [
                'name' => 'Bot Shield',
                'summary' => 'Logs fake search bots and blocks obvious scraper user agents.',
                'risk' => 'moderate',
                'mode' => 'recommended',
                'rules' => [
                    ['rule_table' => 'waf_rules', 'template_key' => 'waf_bot_user_agents', 'payload' => ['enabled' => true, 'name' => 'Block obvious scraper user agents', 'priority' => 55, 'type' => 'user_agent_contains', 'pattern' => 'python-requests', 'action' => 'block', 'description' => 'Generated by Bot Shield for common automation clients.', 'waf_group_id' => 'suspicious_bot', 'waf_severity' => 'medium', 'waf_confidence' => 'high', 'waf_safe_reason' => 'Targets a generic automation client instead of verified search bots.', 'bot_class' => 'scraper', 'bot_score' => 90, 'bot_action' => 'block']],
                    ['rule_table' => 'waf_rules', 'template_key' => 'waf_fake_search_bots', 'payload' => ['enabled' => true, 'name' => 'Challenge unverified Googlebot claims', 'priority' => 56, 'type' => 'user_agent_contains', 'pattern' => 'Googlebot', 'action' => 'challenge', 'description' => 'Generated by Bot Shield. A User-Agent claim alone is never treated as a verified search bot.', 'waf_group_id' => 'suspicious_bot', 'waf_severity' => 'low', 'waf_confidence' => 'low', 'waf_safe_reason' => 'Search-bot claims are challenged until a verified-bot source is configured.', 'bot_class' => 'unknown_automation', 'bot_score' => 55, 'bot_action' => 'challenge']],
                ],
            ],
            'wordpress_hardening' => [
                'name' => 'WordPress Hardening',
                'summary' => 'Adds WordPress-specific XML-RPC and scanner protections as editable WAF rules.',
                'risk' => 'moderate',
                'mode' => 'recommended',
                'rules' => [
                    ['rule_table' => 'waf_rules', 'template_key' => 'waf_wordpress_xmlrpc', 'payload' => ['enabled' => true, 'name' => 'Log XML-RPC abuse probes', 'priority' => 42, 'type' => 'path_prefix', 'pattern' => '/xmlrpc.php', 'action' => 'log', 'description' => 'Generated by WordPress Hardening as a log-only XML-RPC abuse signal before blocking.', 'waf_group_id' => 'wordpress_exploit', 'waf_severity' => 'medium', 'waf_confidence' => 'medium', 'waf_safe_reason' => 'Logs first because some WordPress integrations still use XML-RPC.']],
                    ['rule_table' => 'waf_rules', 'template_key' => 'waf_wordpress_scanners', 'payload' => ['enabled' => true, 'name' => 'Block WordPress scanner paths', 'priority' => 43, 'type' => 'path_contains', 'pattern' => 'wp-config.php', 'action' => 'block', 'description' => 'Generated by WordPress Hardening for high-confidence WordPress scanner paths.', 'waf_group_id' => 'wordpress_exploit', 'waf_severity' => 'high', 'waf_confidence' => 'high', 'waf_safe_reason' => 'Blocks direct probes for sensitive WordPress configuration files.']],
                ],
            ],
            'checkout_protection' => [
                'name' => 'Checkout Protection',
                'summary' => 'Protects checkout paths with conservative POST limits and method-probe logging.',
                'risk' => 'moderate',
                'mode' => 'recommended',
                'rules' => [
                    ['rule_table' => 'rate_limit_rules', 'template_key' => 'rate_checkout_paths', 'payload' => ['enabled' => true, 'priority' => 35, 'path_prefix' => '/checkout', 'key_type' => 'ip_path', 'requests_per_minute' => 30, 'action' => 'block']],
                    ['rule_table' => 'waf_rules', 'template_key' => 'waf_checkout_method_probe', 'payload' => ['enabled' => true, 'name' => 'Log unusual checkout methods', 'priority' => 44, 'type' => 'method_is', 'pattern' => 'TRACE', 'action' => 'log', 'description' => 'Generated by Checkout Protection to surface unusual checkout method probes before blocking.', 'waf_group_id' => 'checkout_probe', 'waf_severity' => 'medium', 'waf_confidence' => 'medium', 'waf_safe_reason' => 'Logs unusual checkout methods before an operator chooses stricter blocking.']],
                ],
            ],
            'emergency_protection' => [
                'name' => 'Emergency Protection',
                'summary' => 'Temporarily tightens site-wide limits and blocks common incident scanner patterns.',
                'risk' => 'risky',
                'mode' => 'confirm_first',
                'rules' => [
                    ['rule_table' => 'rate_limit_rules', 'template_key' => 'rate_emergency_sitewide', 'payload' => ['enabled' => true, 'priority' => 5, 'path_prefix' => '/', 'key_type' => 'ip', 'requests_per_minute' => 120, 'action' => 'block']],
                    ['rule_table' => 'waf_rules', 'template_key' => 'waf_emergency_scanners', 'payload' => ['enabled' => true, 'name' => 'Block emergency scanner traffic', 'priority' => 6, 'type' => 'user_agent_contains', 'pattern' => 'nikto', 'action' => 'block', 'description' => 'Generated by Emergency Protection for active incident response.', 'waf_group_id' => 'scanner_recon', 'waf_severity' => 'high', 'waf_confidence' => 'high', 'waf_safe_reason' => 'Emergency mode blocks a known scanner user agent and remains undoable.']],
                ],
            ],
            'static_asset_performance' => [
                'name' => 'Static Asset Performance',
                'summary' => 'Caches common static asset paths at the edge.',
                'risk' => 'safe',
                'mode' => 'recommended',
                'rules' => [
                    ['rule_table' => 'cache_rules', 'template_key' => 'cache_static_assets', 'payload' => ['enabled' => true, 'path_prefix' => '/assets', 'ttl_seconds' => 86400]],
                ],
            ],
        ];
    }
    private function protectionIntentTemplate(string $intentKey): array {
        $templates = $this->protectionIntentTemplates();
        if (!isset($templates[$intentKey])) {
            throw new \InvalidArgumentException('unknown_intent');
        }
        return $templates[$intentKey];
    }
    private function protectionProfileTemplates(): array {
        return [
            'basic_website' => ['name' => 'Basic Website', 'summary' => 'Safe starter protection and static asset caching for a typical site.', 'risk' => 'safe', 'intent_keys' => ['common_exploits', 'static_asset_performance']],
            'wordpress' => ['name' => 'WordPress', 'summary' => 'Common exploit, login, XML-RPC, scanner, bot, and static asset protections for WordPress.', 'risk' => 'moderate', 'intent_keys' => ['common_exploits', 'login_shield', 'wordpress_hardening', 'bot_shield', 'static_asset_performance']],
            'api' => ['name' => 'API', 'summary' => 'API path protection with rate limits and method-probe logging.', 'risk' => 'moderate', 'intent_keys' => ['protect_api', 'smart_rate_limiting', 'common_exploits']],
            'saas_app' => ['name' => 'SaaS App', 'summary' => 'Login, API, and suspicious automation protection for application dashboards.', 'risk' => 'moderate', 'intent_keys' => ['login_shield', 'protect_api', 'bot_shield']],
            'ecommerce' => ['name' => 'E-commerce', 'summary' => 'Protects login, checkout traffic, suspicious bots, and broader abuse bursts.', 'risk' => 'moderate', 'intent_keys' => ['login_shield', 'checkout_protection', 'bot_shield', 'smart_rate_limiting']],
            'emergency' => ['name' => 'Emergency Protection', 'summary' => 'High-friction temporary controls for active attacks.', 'risk' => 'risky', 'intent_keys' => ['emergency_protection', 'common_exploits', 'bot_shield']],
        ];
    }
    private function protectionProfileTemplate(string $profileKey): array {
        $templates = $this->protectionProfileTemplates();
        if (!isset($templates[$profileKey])) {
            throw new \InvalidArgumentException('unknown_profile');
        }
        return $templates[$profileKey];
    }
    private function generatedRulesForIntent(string $domainId, string $intentKey, ?string $intentId, ?string $profileId, array $input): array {
        $template = $this->protectionIntentTemplate($intentKey);
        $now = time();
        $rules = [];
        foreach ($template['rules'] as $rule) {
            $payload = $rule['payload'] + [
                'profile_id' => $profileId,
                'intent_id' => $intentId,
                'template_key' => (string) $rule['template_key'],
                'managed_by' => $template['name'],
                'user_modified' => false,
                'last_generated_at' => $now,
                'last_applied_at' => $now,
            ];
            $rules[] = [
                'rule_table' => (string) $rule['rule_table'],
                'template_key' => (string) $rule['template_key'],
                'payload' => $payload,
            ];
        }
        return $rules;
    }
    private function upsertProtectionIntent(string $domainId, string $intentKey, array $template, array $input, string $status, ?string $profileId = null): array {
        $now = time();
        $settings = json_encode($input['settings'] ?? [], JSON_UNESCAPED_SLASHES);
        $existing = Database::pdo()->prepare('SELECT * FROM protection_intents WHERE domain_id=:domain_id AND intent_key=:intent_key AND profile_id IS NOT DISTINCT FROM :profile_id ORDER BY created_at DESC LIMIT 1');
        $existing->execute([':domain_id' => $domainId, ':intent_key' => $intentKey, ':profile_id' => $profileId]);
        $row = $existing->fetch();
        if ($row) {
            Database::pdo()->prepare('UPDATE protection_intents SET profile_id=:profile_id,name=:name,status=:status,mode=:mode,settings_json=:settings_json,updated_at=:updated_at WHERE id=:id')
                ->execute([':profile_id' => $profileId, ':name' => $template['name'], ':status' => $status, ':mode' => (string) ($input['mode'] ?? $template['mode']), ':settings_json' => $settings, ':updated_at' => $now, ':id' => (string) $row['id']]);
            return $this->getProtectionIntent($domainId, (string) $row['id']) ?? [];
        }
        $id = Uuid::v4();
        Database::pdo()->prepare(
            'INSERT INTO protection_intents (id,domain_id,profile_id,intent_key,name,status,mode,settings_json,created_at,updated_at)
             VALUES (:id,:domain_id,:profile_id,:intent_key,:name,:status,:mode,:settings_json,:created_at,:updated_at)'
        )->execute([':id' => $id, ':domain_id' => $domainId, ':profile_id' => $profileId, ':intent_key' => $intentKey, ':name' => $template['name'], ':status' => $status, ':mode' => (string) ($input['mode'] ?? $template['mode']), ':settings_json' => $settings, ':created_at' => $now, ':updated_at' => $now]);
        return $this->getProtectionIntent($domainId, $id) ?? [];
    }
    private function getProtectionIntent(string $domainId, string $intentId): ?array {
        $q = Database::pdo()->prepare('SELECT * FROM protection_intents WHERE domain_id=:domain_id AND id=:id LIMIT 1');
        $q->execute([':domain_id' => $domainId, ':id' => $intentId]);
        $row = $q->fetch();
        return $row ? $this->castProtectionIntent((array) $row) : null;
    }
    private function upsertProtectionProfile(string $domainId, string $profileKey, array $template, string $status): array {
        $now = time();
        $settings = json_encode(['intent_keys' => $template['intent_keys']], JSON_UNESCAPED_SLASHES);
        $existing = Database::pdo()->prepare('SELECT * FROM protection_profiles WHERE domain_id=:domain_id AND profile_key=:profile_key LIMIT 1');
        $existing->execute([':domain_id' => $domainId, ':profile_key' => $profileKey]);
        $row = $existing->fetch();
        if ($row) {
            Database::pdo()->prepare('UPDATE protection_profiles SET name=:name,status=:status,settings_json=:settings_json,updated_at=:updated_at WHERE id=:id')
                ->execute([':name' => $template['name'], ':status' => $status, ':settings_json' => $settings, ':updated_at' => $now, ':id' => (string) $row['id']]);
            return $this->getProtectionProfile($domainId, (string) $row['id']) ?? [];
        }
        $id = Uuid::v4();
        Database::pdo()->prepare(
            'INSERT INTO protection_profiles (id,domain_id,profile_key,name,status,settings_json,created_at,updated_at)
             VALUES (:id,:domain_id,:profile_key,:name,:status,:settings_json,:created_at,:updated_at)'
        )->execute([':id' => $id, ':domain_id' => $domainId, ':profile_key' => $profileKey, ':name' => $template['name'], ':status' => $status, ':settings_json' => $settings, ':created_at' => $now, ':updated_at' => $now]);
        return $this->getProtectionProfile($domainId, $id) ?? [];
    }
    private function getProtectionProfile(string $domainId, string $profileId): ?array {
        $q = Database::pdo()->prepare('SELECT * FROM protection_profiles WHERE domain_id=:domain_id AND id=:id LIMIT 1');
        $q->execute([':domain_id' => $domainId, ':id' => $profileId]);
        $row = $q->fetch();
        return $row ? $this->castProtectionProfile((array) $row) : null;
    }
    private function applyGeneratedRules(string $domainId, string $intentId, string $intentKey, ?string $profileId, array $input, ?array $profileTemplate = null): array {
        $applied = [];
        foreach ($this->generatedRulesForIntent($domainId, $intentKey, $intentId, null, $input) as $rule) {
            if ($profileTemplate !== null) {
                $rule['payload'] = array_replace($rule['payload'], [
                    'profile_id' => $profileId,
                    'managed_by' => $profileTemplate['name'],
                ]);
            }
            $table = (string) $rule['rule_table'];
            $templateKey = (string) $rule['template_key'];
            $existing = $this->findManagedRule($domainId, $intentId, $table, $templateKey);
            if ($existing !== null) {
                $applied[] = $this->updateGeneratedRule($domainId, $table, (string) $existing['rule_id'], $rule['payload']);
                continue;
            }
            $applied[] = match ($table) {
                'waf_rules' => $this->createWaf($domainId, $rule['payload']),
                'rate_limit_rules' => $this->createRateLimit($domainId, $rule['payload']),
                'cache_rules' => $this->createCacheRule($domainId, $rule['payload']),
                default => throw new \InvalidArgumentException('invalid_rule_type'),
            };
        }
        return $applied;
    }
    private function findManagedRule(string $domainId, string $intentId, string $table, string $templateKey): ?array {
        $q = Database::pdo()->prepare('SELECT * FROM managed_rule_links WHERE domain_id=:domain_id AND intent_id=:intent_id AND rule_table=:rule_table AND template_key=:template_key AND detached_at IS NULL LIMIT 1');
        $q->execute([':domain_id' => $domainId, ':intent_id' => $intentId, ':rule_table' => $table, ':template_key' => $templateKey]);
        $row = $q->fetch();
        return $row ? (array) $row : null;
    }
    private function updateGeneratedRule(string $domainId, string $table, string $id, array $payload): array {
        $allowed = ['waf_rules', 'rate_limit_rules', 'cache_rules'];
        if (!in_array($table, $allowed, true)) {
            throw new \InvalidArgumentException('invalid_rule_type');
        }
        $payload['user_modified'] = false;
        $sets = [];
        $params = [':id' => $id, ':domain_id' => $domainId, ':updated_at' => time()];
        foreach ($payload as $key => $value) {
            $sets[] = "{$key}=:{$key}";
            $params[':' . $key] = is_bool($value) ? (int) $value : $value;
        }
        $sets[] = 'updated_at=:updated_at';
        Database::pdo()->prepare("UPDATE {$table} SET " . implode(',', $sets) . ' WHERE id=:id AND domain_id=:domain_id')->execute($params);
        $q = Database::pdo()->prepare("SELECT * FROM {$table} WHERE id=:id AND domain_id=:domain_id LIMIT 1");
        $q->execute([':id' => $id, ':domain_id' => $domainId]);
        $updated = $this->cast((array) $q->fetch());
        $this->storeManagedRuleLink($table, $domainId, $id, $updated);
        $this->invalidateConfigSnapshot();
        return $updated;
    }
    private function managedRulesForIntent(string $domainId, string $intentId): array {
        $links = Database::pdo()->prepare('SELECT * FROM managed_rule_links WHERE domain_id=:domain_id AND intent_id=:intent_id AND detached_at IS NULL ORDER BY created_at ASC');
        $links->execute([':domain_id' => $domainId, ':intent_id' => $intentId]);
        $out = [];
        foreach ($links->fetchAll() as $link) {
            $row = (array) $link;
            $table = (string) $row['rule_table'];
            if (!in_array($table, ['waf_rules', 'rate_limit_rules', 'cache_rules', 'domain_header_rules', 'domain_ip_rules'], true)) {
                continue;
            }
            $rule = Database::pdo()->prepare("SELECT * FROM {$table} WHERE id=:id AND domain_id=:domain_id LIMIT 1");
            $rule->execute([':id' => (string) $row['rule_id'], ':domain_id' => $domainId]);
            $ruleRow = $rule->fetch();
            if (!$ruleRow) {
                continue;
            }
            $out[] = $this->cast($row) + ['rule' => $this->cast((array) $ruleRow)];
        }
        return $out;
    }
    private function assertManagedRulesCanBeRegenerated(string $domainId, string $intentId, bool $confirmed): void {
        if ($confirmed) {
            return;
        }
        foreach ($this->managedRulesForIntent($domainId, $intentId) as $link) {
            $rule = $link['rule'] ?? [];
            if (!empty($rule['user_modified'])) {
                throw new \DomainException('user_modified_rule_conflict');
            }
        }
    }
    private function createRollbackPoint(string $domainId, string $intentId, string $label, ?string $profileId = null): string {
        $id = Uuid::v4();
        $snapshot = ['intent' => $this->getProtectionIntent($domainId, $intentId), 'rules' => []];
        foreach ($this->managedRulesForIntent($domainId, $intentId) as $link) {
            $rule = $link['rule'] ?? [];
            $snapshot['rules'][] = ['rule_table' => (string) $link['rule_table'], 'rule_id' => (string) $link['rule_id'], 'enabled' => !empty($rule['enabled'])];
        }
        Database::pdo()->prepare(
            'INSERT INTO profile_rollback_points (id,domain_id,profile_id,intent_id,label,snapshot_json,created_at)
             VALUES (:id,:domain_id,:profile_id,:intent_id,:label,:snapshot_json,:created_at)'
        )->execute([':id' => $id, ':domain_id' => $domainId, ':profile_id' => $profileId, ':intent_id' => $intentId, ':label' => $label, ':snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_SLASHES), ':created_at' => time()]);
        return $id;
    }
    private function recordProfileChange(string $domainId, ?string $profileId, ?string $intentId, string $action, mixed $before, mixed $after): void {
        Database::pdo()->prepare(
            'INSERT INTO profile_change_history (id,domain_id,profile_id,intent_id,action,reason,before_json,after_json,created_at)
             VALUES (:id,:domain_id,:profile_id,:intent_id,:action,NULL,:before_json,:after_json,:created_at)'
        )->execute([':id' => Uuid::v4(), ':domain_id' => $domainId, ':profile_id' => $profileId, ':intent_id' => $intentId, ':action' => $action, ':before_json' => json_encode($before, JSON_UNESCAPED_SLASHES), ':after_json' => json_encode($after, JSON_UNESCAPED_SLASHES), ':created_at' => time()]);
    }
    private function castProtectionIntent(array $r): array {
        foreach (['created_at', 'updated_at'] as $i) {
            if (isset($r[$i])) {
                $r[$i] = (int) $r[$i];
            }
        }
        $r['settings'] = isset($r['settings_json']) ? (json_decode((string) $r['settings_json'], true) ?: []) : [];
        unset($r['settings_json']);
        return $r;
    }
    private function castProtectionProfile(array $r): array {
        foreach (['created_at', 'updated_at'] as $i) {
            if (isset($r[$i])) {
                $r[$i] = (int) $r[$i];
            }
        }
        $r['settings'] = isset($r['settings_json']) ? (json_decode((string) $r['settings_json'], true) ?: []) : [];
        unset($r['settings_json']);
        return $r;
    }
    private function managedRulePayload(array $in): array {
        $payload = [];
        foreach (['profile_id', 'intent_id', 'template_key', 'managed_by'] as $key) {
            if (array_key_exists($key, $in)) {
                $payload[$key] = $in[$key] === null ? null : (string) $in[$key];
            }
        }
        if (array_key_exists('user_modified', $in)) {
            $payload['user_modified'] = !empty($in['user_modified']);
        }
        $now = time();
        if (array_key_exists('last_generated_at', $in)) {
            $payload['last_generated_at'] = $in['last_generated_at'] === null ? null : (int) $in['last_generated_at'];
        } elseif (($payload['managed_by'] ?? null) !== null) {
            $payload['last_generated_at'] = $now;
        }
        if (array_key_exists('last_applied_at', $in)) {
            $payload['last_applied_at'] = $in['last_applied_at'] === null ? null : (int) $in['last_applied_at'];
        } elseif (($payload['managed_by'] ?? null) !== null) {
            $payload['last_applied_at'] = $now;
        }
        return $payload;
    }
    private function wafMetadataPayload(array $in): array {
        $payload = [];
        foreach (['waf_group_id', 'waf_severity', 'waf_confidence', 'waf_safe_reason', 'bot_class', 'bot_action'] as $key) {
            if (array_key_exists($key, $in)) {
                $payload[$key] = $in[$key] === null ? null : (string) $in[$key];
            }
        }
        if (array_key_exists('bot_score', $in)) {
            $score = (int) $in['bot_score'];
            if ($score < 0 || $score > 100) { throw new \InvalidArgumentException('invalid_bot_score'); }
            $payload['bot_score'] = $score;
        }
        return $payload;
    }
    private function storeManagedRuleLink(string $table, string $domainId, string $id, array $rule): void {
        if (($rule['managed_by'] ?? null) === null || ($rule['template_key'] ?? null) === null) {
            return;
        }
        $now = time();
        Database::pdo()->prepare(
            'INSERT INTO managed_rule_links
             (id,domain_id,profile_id,intent_id,rule_table,rule_id,template_key,managed_by,user_modified,last_generated_at,last_applied_at,created_at,updated_at)
             VALUES (:id,:domain_id,:profile_id,:intent_id,:rule_table,:rule_id,:template_key,:managed_by,:user_modified,:last_generated_at,:last_applied_at,:created_at,:updated_at)
             ON CONFLICT (rule_table, rule_id) WHERE detached_at IS NULL
             DO UPDATE SET profile_id=EXCLUDED.profile_id,intent_id=EXCLUDED.intent_id,template_key=EXCLUDED.template_key,managed_by=EXCLUDED.managed_by,user_modified=EXCLUDED.user_modified,last_generated_at=EXCLUDED.last_generated_at,last_applied_at=EXCLUDED.last_applied_at,updated_at=EXCLUDED.updated_at'
        )->execute([
            ':id' => Uuid::v4(),
            ':domain_id' => $domainId,
            ':profile_id' => $rule['profile_id'] ?? null,
            ':intent_id' => $rule['intent_id'] ?? null,
            ':rule_table' => $table,
            ':rule_id' => $id,
            ':template_key' => (string) $rule['template_key'],
            ':managed_by' => (string) $rule['managed_by'],
            ':user_modified' => !empty($rule['user_modified']) ? 1 : 0,
            ':last_generated_at' => $rule['last_generated_at'] ?? null,
            ':last_applied_at' => $rule['last_applied_at'] ?? null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
    private function syncManagedRuleLink(string $table, string $domainId, string $id, array $rule): void {
        if (($rule['managed_by'] ?? null) === null) {
            return;
        }
        $this->storeManagedRuleLink($table, $domainId, $id, $rule);
    }
    private function markUserModifiedForManagedRule(array &$in): void {
        $managedKeys = ['profile_id', 'intent_id', 'template_key', 'managed_by', 'last_generated_at', 'last_applied_at'];
        foreach (array_keys($in) as $key) {
            if (!in_array($key, $managedKeys, true)) {
                $in['user_modified'] = true;
                return;
            }
        }
    }
    private function auditResource(string $table): string {
        return match ($table) {
            'rate_limit_rules' => 'rate_limit',
            'waf_rules' => 'waf_rule',
            'redirect_rules' => 'redirect',
            'page_rules' => 'page_rule',
            'cache_rules' => 'cache_rule',
            'domain_header_rules' => 'header_rule',
            'domain_ip_rules' => 'ip_rule',
            default => rtrim($table, 's'),
        };
    }
    private function assertHeaderRule(array $in, bool $partial): void {
        if ((!$partial || array_key_exists('operation', $in)) && !in_array((string) ($in['operation'] ?? ''), ['set', 'remove', 'append'], true)) {
            throw new \InvalidArgumentException('invalid_operation');
        }
        if (!$partial || array_key_exists('header_name', $in)) {
            $name = (string) ($in['header_name'] ?? '');
            if ($name === '' || !preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name)) {
                throw new \InvalidArgumentException('invalid_header_name');
            }
        }
        if (!$partial && (string) ($in['operation'] ?? '') !== 'remove' && trim((string) ($in['header_value'] ?? '')) === '') {
            throw new \InvalidArgumentException('header_value_required');
        }
        if (array_key_exists('path_pattern', $in) && !str_starts_with((string) $in['path_pattern'], '/')) {
            throw new \InvalidArgumentException('invalid_path_pattern');
        }
    }
    private function assertIpRule(array $in, bool $partial): void {
        if ((!$partial || array_key_exists('rule_type', $in)) && !in_array((string) ($in['rule_type'] ?? ''), ['allow', 'block'], true)) {
            throw new \InvalidArgumentException('invalid_rule_type');
        }
        if (!$partial || array_key_exists('cidr', $in)) {
            $parts = explode('/', (string) ($in['cidr'] ?? ''), 2);
            if (count($parts) !== 2 || filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || !ctype_digit($parts[1]) || (int) $parts[1] < 0 || (int) $parts[1] > 32) {
                throw new \InvalidArgumentException('invalid_cidr');
            }
        }
    }
    private function rateLimitPayload(array $in, bool $partial = false): array {
        $defaults = [
            'enabled' => true,
            'priority' => 100,
            'path_prefix' => '/',
            'key_type' => 'ip',
            'key_header_name' => null,
            'requests_per_minute' => 60,
            'action' => 'block',
        ];
        $payload = [];
        foreach ($defaults as $key => $default) {
            if (!$partial || array_key_exists($key, $in)) {
                $value = $in[$key] ?? $default;
                if ($key === 'key_header_name' && ($value === null || trim((string) $value) === '')) {
                    $payload[$key] = null;
                } else {
                    $payload[$key] = $key === 'enabled' ? !empty($value) : ($key === 'priority' || $key === 'requests_per_minute' ? (int) $value : (string) $value);
                }
            }
        }
        return $payload + $this->managedRulePayload($in);
    }
    private function cast(array $r): array { foreach(['enabled', 'preserve_query', 'respect_origin_cache_control', 'cache_authorized_requests', 'static_asset_cache_enabled', 'ignore_query_strings_for_static', 'bypass_logged_in_users', 'force_https', 'auto_renew', 'user_modified'] as $b){ if(array_key_exists($b,$r)){$r[$b]=((int)$r[$b])===1;}} foreach(['created_at','updated_at','ttl_seconds','requests_per_minute','status_code','priority','default_edge_ttl_seconds','default_browser_ttl_seconds','stale_if_error_seconds','last_generated_at','last_applied_at'] as $i){ if(isset($r[$i]) && $r[$i] !== null){$r[$i]=(int)$r[$i];}} if (array_key_exists('actions_json', $r)) { $r['actions'] = json_decode((string) $r['actions_json'], true) ?: []; } unset($r['private_key_pem']); return $r; }
    private function castSslJob(array $r): array {
        foreach (['created_at', 'updated_at', 'finished_at', 'progress_percent'] as $i) {
            if (isset($r[$i]) && $r[$i] !== null) {
                $r[$i] = (int) $r[$i];
            }
        }
        $r['hostnames'] = isset($r['hostnames_json']) ? (json_decode((string) $r['hostnames_json'], true) ?: []) : [];
        $staleAfter = max(60, (int) (getenv('CDNLITE_SSL_SCHEDULER_INTERVAL_SECONDS') ?: 30) * 2);
        $age = time() - (int) ($r['updated_at'] ?? time());
        $r['stale_seconds'] = $age;
        $r['scheduler_stale'] = ($r['status'] ?? '') === 'queued' && $age >= $staleAfter;
        if ($r['scheduler_stale']) {
            $r['scheduler_hint'] = 'ssl-scheduler has not claimed this queued job. Check the ssl-scheduler service and run php artisan cdn:ssl:renew-due to process queued SSL jobs.';
        }
        unset($r['hostnames_json']);
        return $r;
    }
    private function redirectV2Supported(): bool {
        if ($this->redirectV2ColumnsAvailable !== null) {
            return $this->redirectV2ColumnsAvailable;
        }
        $s = Database::pdo()->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='redirect_rules' AND column_name='match_type' LIMIT 1");
        $s->execute();
        $this->redirectV2ColumnsAvailable = $s->fetchColumn() !== false;
        return $this->redirectV2ColumnsAvailable;
    }
    private function rateLimitV2Supported(): bool {
        if ($this->rateLimitV2TableAvailable !== null) {
            return $this->rateLimitV2TableAvailable;
        }
        $s = Database::pdo()->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='rate_limit_rules' LIMIT 1");
        $s->execute();
        $this->rateLimitV2TableAvailable = $s->fetchColumn() !== false;
        return $this->rateLimitV2TableAvailable;
    }
}
