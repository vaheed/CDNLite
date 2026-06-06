<?php

namespace App\Modules\Proxy\Services;

use App\Support\Database;
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
        if (!in_array($action, ['block', 'log', 'allow'], true)) { throw new \InvalidArgumentException('invalid_action'); }
        return $this->insert('waf_rules', $domainId, [
            'enabled' => !empty($in['enabled']),
            'name' => isset($in['name']) ? (string) $in['name'] : null,
            'priority' => (int) ($in['priority'] ?? 100),
            'type' => $type,
            'pattern' => (string)($in['pattern'] ?? ''),
            'action' => $action,
            'description' => isset($in['description']) ? (string) $in['description'] : null,
        ]);
    }
    public function updateWaf(string $domainId, string $id, array $in): ?array { return $this->update('waf_rules', $domainId, $id, $in); }
    public function deleteWaf(string $domainId, string $id): bool { return $this->delete('waf_rules', $domainId, $id); }

    public function listCacheRules(string $domainId): array { return $this->listRows('cache_rules', $domainId); }
    public function createCacheRule(string $domainId, array $in): array { return $this->insert('cache_rules', $domainId, ['enabled'=>!empty($in['enabled']),'path_prefix'=>(string)($in['path_prefix'] ?? '/'),'ttl_seconds'=>(int)($in['ttl_seconds'] ?? 60)]); }
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
            'INSERT INTO domain_ssl_settings (domain_id,force_https,min_tls_version,created_at,updated_at)
             VALUES (:domain_id,true,:min_tls_version,:created_at,:updated_at)'
        )->execute([':domain_id' => $domainId, ':min_tls_version' => '1.2', ':created_at' => $now, ':updated_at' => $now]);
        return $this->getSslSettings($domainId);
    }
    public function setSslSettings(string $domainId, array $input): array {
        $current = $this->getSslSettings($domainId);
        $forceHttps = array_key_exists('force_https', $input) ? !empty($input['force_https']) : (bool) $current['force_https'];
        $minTlsVersion = (string) ($input['min_tls_version'] ?? $current['min_tls_version']);
        Database::pdo()->prepare(
            'UPDATE domain_ssl_settings SET force_https=:force_https,min_tls_version=:min_tls_version,updated_at=:updated_at
             WHERE domain_id=:domain_id'
        )->execute([
            ':domain_id' => $domainId,
            ':force_https' => (int) $forceHttps,
            ':min_tls_version' => $minTlsVersion,
            ':updated_at' => time(),
        ]);
        return $this->getSslSettings($domainId);
    }
    public function requestSslCertificate(string $domainId, array $hostnames): array {
        $domain = $this->domainForSsl($domainId);
        if ($domain === null) {
            throw new \OutOfBoundsException('domain_not_found');
        }
        if ((int) $domain['proxy_enabled'] !== 1 || (string) $domain['status'] !== 'active') {
            throw new \DomainException('domain_proxy_must_be_active');
        }

        $targets = $hostnames === [] ? [(string) $domain['domain']] : $hostnames;
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
                    ':id' => Uuid::v4(), ':domain_id' => $domainId, ':hostname' => $h, ':provider' => 'cdnlite', ':status' => 'pending',
                    ':issuer' => null, ':serial' => null, ':not_before' => null, ':not_after' => null, ':days' => null, ':renewal' => null,
                    ':checked' => $now, ':error' => null, ':created' => $now, ':updated' => $now,
                ]);
            } else {
                $u = Database::pdo()->prepare("UPDATE ssl_certificates SET provider=:provider,status=:status,last_checked_at=:checked,last_error=NULL,updated_at=:updated WHERE id=:id AND status IN ('missing','pending')");
                $u->execute([':provider' => 'cdnlite', ':status' => 'pending', ':checked' => $now, ':updated' => $now, ':id' => $id]);
            }
        }
        return $this->listSslCertificates($domainId);
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
        $q = Database::pdo()->prepare('SELECT * FROM ssl_certificates WHERE id=:id LIMIT 1');
        $q->execute([':id' => $id]);
        return $this->cast((array) $q->fetch());
    }
    private function domainForSsl(string $domainId): ?array {
        $s = Database::pdo()->prepare('SELECT id,domain,proxy_enabled,status FROM domains WHERE id=:id LIMIT 1');
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
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $ins = Database::pdo()->prepare('INSERT INTO domain_cache_settings (domain_id,enabled,default_edge_ttl_seconds,default_browser_ttl_seconds,cache_query_string_mode,respect_origin_cache_control,cache_authorized_requests,stale_if_error_seconds,created_at,updated_at) VALUES (:domain_id,:enabled,:edge,:browser,:mode,:respect,:authorized,:stale,:created_at,:updated_at)');
        $ins->execute([
            ':domain_id' => $domainId,
            ':enabled' => 1,
            ':edge' => 3600,
            ':browser' => null,
            ':mode' => 'include_all',
            ':respect' => 1,
            ':authorized' => 0,
            ':stale' => 86400,
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
            ':updated_at' => time(),
        ];
        $u = Database::pdo()->prepare('UPDATE domain_cache_settings SET enabled=:enabled,default_edge_ttl_seconds=:edge,default_browser_ttl_seconds=:browser,cache_query_string_mode=:mode,respect_origin_cache_control=:respect,cache_authorized_requests=:authorized,stale_if_error_seconds=:stale,updated_at=:updated_at WHERE domain_id=:domain_id');
        $u->execute($payload);
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
        $query = 'SELECT id,event,details_json,created_at FROM audit_log WHERE domain_id=:domain_id';
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

    public function getRateLimit(string $domainId): ?array {
        $rules = $this->listRateLimits($domainId);
        return $rules[0] ?? null;
    }
    public function listRateLimits(string $domainId): array {
        $s = Database::pdo()->prepare('SELECT * FROM rate_limit_rules WHERE domain_id=:domain_id ORDER BY priority ASC, created_at ASC');
        $s->execute([':domain_id' => $domainId]);
        return array_map([$this, 'cast'], $s->fetchAll());
    }
    public function createRateLimit(string $domainId, array $in): array {
        return $this->insert('rate_limit_rules', $domainId, $this->rateLimitPayload($in));
    }
    public function updateRateLimit(string $domainId, string $id, array $in): ?array {
        return $this->update('rate_limit_rules', $domainId, $id, $this->rateLimitPayload($in, true));
    }
    public function deleteRateLimit(string $domainId, string $id): bool {
        return $this->delete('rate_limit_rules', $domainId, $id);
    }
    public function setRateLimit(string $domainId, array $in): array {
        $existing=$this->getRateLimit($domainId);
        if ($existing) {
            return (array) $this->updateRateLimit($domainId, (string) $existing['id'], $in);
        }
        return $this->createRateLimit($domainId, $in);
    }
    public function disableRateLimit(string $domainId): bool {
        $table = $this->rateLimitV2Supported() ? 'rate_limit_rules' : 'rate_limit_rules';
        $s=Database::pdo()->prepare("DELETE FROM {$table} WHERE domain_id=:domain"); $s->execute([':domain'=>$domainId]); return $s->rowCount()>0;
    }

    private function listRows(string $table, string $domainId): array { $s=Database::pdo()->prepare("SELECT * FROM {$table} WHERE domain_id=:domain_id ORDER BY created_at ASC"); $s->execute([':domain_id'=>$domainId]); return array_map([$this,'cast'], $s->fetchAll()); }
    private function insert(string $table, string $domainId, array $in): array {
        $id=Uuid::v4(); $now=time(); $cols=array_keys($in); $names=implode(',', $cols); $bind=':'.implode(',:', $cols);
        $sql="INSERT INTO {$table} (id,domain_id,{$names},created_at,updated_at) VALUES (:id,:domain_id,{$bind},:created_at,:updated_at)";
        $p=[':id'=>$id,':domain_id'=>$domainId,':created_at'=>$now,':updated_at'=>$now]; foreach($in as $k=>$v){$p[':'.$k]=is_bool($v)?(int)$v:$v;}
        $s=Database::pdo()->prepare($sql); $s->execute($p); $q=Database::pdo()->prepare("SELECT * FROM {$table} WHERE id=:id"); $q->execute([':id'=>$id]); return $this->cast((array)$q->fetch());
    }
    private function update(string $table, string $domainId, string $id, array $in): ?array {
        $q=Database::pdo()->prepare("SELECT * FROM {$table} WHERE id=:id AND domain_id=:domain LIMIT 1"); $q->execute([':id'=>$id,':domain'=>$domainId]); if(!$q->fetch()){return null;}
        $sets=[]; $p=[':id'=>$id,':domain'=>$domainId,':u'=>time()];
        foreach($in as $k=>$v){$sets[]="{$k}=:{$k}"; $p[':'.$k]=is_bool($v)?(int)$v:$v;}
        $sets[]='updated_at=:u';
        $s=Database::pdo()->prepare("UPDATE {$table} SET ".implode(',', $sets)." WHERE id=:id AND domain_id=:domain"); $s->execute($p);
        $r=Database::pdo()->prepare("SELECT * FROM {$table} WHERE id=:id"); $r->execute([':id'=>$id]); return $this->cast((array)$r->fetch());
    }
    private function delete(string $table, string $domainId, string $id): bool { $s=Database::pdo()->prepare("DELETE FROM {$table} WHERE id=:id AND domain_id=:domain"); $s->execute([':id'=>$id,':domain'=>$domainId]); return $s->rowCount()>0; }
    private function rateLimitPayload(array $in, bool $partial = false): array {
        $defaults = [
            'enabled' => true,
            'priority' => 100,
            'path_prefix' => '/',
            'key_type' => 'ip',
            'requests_per_minute' => 60,
            'action' => 'block',
        ];
        $payload = [];
        foreach ($defaults as $key => $default) {
            if (!$partial || array_key_exists($key, $in)) {
                $value = $in[$key] ?? $default;
                $payload[$key] = $key === 'enabled' ? !empty($value) : ($key === 'priority' || $key === 'requests_per_minute' ? (int) $value : (string) $value);
            }
        }
        return $payload;
    }
    private function cast(array $r): array { foreach(['enabled', 'preserve_query', 'respect_origin_cache_control', 'cache_authorized_requests', 'force_https'] as $b){ if(array_key_exists($b,$r)){$r[$b]=((int)$r[$b])===1;}} foreach(['created_at','updated_at','ttl_seconds','requests_per_minute','status_code','priority','default_edge_ttl_seconds','default_browser_ttl_seconds','stale_if_error_seconds'] as $i){ if(isset($r[$i]) && $r[$i] !== null){$r[$i]=(int)$r[$i];}} if (array_key_exists('actions_json', $r)) { $r['actions'] = json_decode((string) $r['actions_json'], true) ?: []; } unset($r['private_key_pem']); return $r; }
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
