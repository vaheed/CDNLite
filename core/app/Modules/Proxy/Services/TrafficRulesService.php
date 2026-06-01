<?php

namespace App\Modules\Proxy\Services;

use App\Support\Database;
use App\Support\Secrets;
use App\Support\Uuid;

class TrafficRulesService
{
    private ?bool $redirectV2ColumnsAvailable = null;
    private ?bool $rateLimitV2TableAvailable = null;

    public function listRedirects(string $siteId): array { return $this->listRows('redirect_rules', $siteId); }
    public function createRedirect(string $siteId, array $in): array {
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
        return $this->insert('redirect_rules', $siteId, $payload);
    }
    public function updateRedirect(string $siteId, string $id, array $in): ?array {
        if (array_key_exists('status_code', $in)) {
            $status = (int) $in['status_code'];
            if (!in_array($status, [301,302,307,308], true)) { throw new \InvalidArgumentException('invalid_status_code'); }
            $in['status_code'] = $status;
        }
        if (!$this->redirectV2Supported()) {
            unset($in['priority'], $in['match_type'], $in['preserve_query']);
        }
        return $this->update('redirect_rules', $siteId, $id, $in);
    }
    public function deleteRedirect(string $siteId, string $id): bool { return $this->delete('redirect_rules', $siteId, $id); }
    public function importRedirects(string $siteId, array $items): array {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $out[] = $this->createRedirect($siteId, $item);
        }
        return $out;
    }
    public function exportRedirects(string $siteId): array {
        return $this->listRedirects($siteId);
    }
    public function testRedirect(string $siteId, string $path, string $query = ''): ?array {
        $rules = $this->listRedirects($siteId);
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

    public function listWaf(string $siteId): array { return $this->listRows('waf_rules', $siteId); }
    public function createWaf(string $siteId, array $in): array {
        $type = (string)($in['type'] ?? '');
        if (!in_array($type, ['path_contains', 'path_prefix', 'user_agent_contains', 'ip_cidr', 'country_is', 'method_is', 'header_contains'], true)) { throw new \InvalidArgumentException('invalid_type'); }
        $action = (string)($in['action'] ?? 'block');
        if (!in_array($action, ['block', 'log', 'allow'], true)) { throw new \InvalidArgumentException('invalid_action'); }
        return $this->insert('waf_rules', $siteId, [
            'enabled' => !empty($in['enabled']),
            'name' => isset($in['name']) ? (string) $in['name'] : null,
            'priority' => (int) ($in['priority'] ?? 100),
            'type' => $type,
            'pattern' => (string)($in['pattern'] ?? ''),
            'action' => $action,
            'description' => isset($in['description']) ? (string) $in['description'] : null,
        ]);
    }
    public function updateWaf(string $siteId, string $id, array $in): ?array { return $this->update('waf_rules', $siteId, $id, $in); }
    public function deleteWaf(string $siteId, string $id): bool { return $this->delete('waf_rules', $siteId, $id); }

    public function listCacheRules(string $siteId): array { return $this->listRows('cache_rules', $siteId); }
    public function createCacheRule(string $siteId, array $in): array { return $this->insert('cache_rules', $siteId, ['enabled'=>!empty($in['enabled']),'path_prefix'=>(string)($in['path_prefix'] ?? '/'),'ttl_seconds'=>(int)($in['ttl_seconds'] ?? 60)]); }
    public function updateCacheRule(string $siteId, string $id, array $in): ?array { return $this->update('cache_rules', $siteId, $id, $in); }
    public function deleteCacheRule(string $siteId, string $id): bool { return $this->delete('cache_rules', $siteId, $id); }
    public function listPageRules(string $siteId): array { return $this->listRows('page_rules', $siteId); }
    public function createPageRule(string $siteId, array $in): array {
        return $this->insert('page_rules', $siteId, [
            'enabled' => !empty($in['enabled']),
            'priority' => (int) ($in['priority'] ?? 100),
            'pattern' => (string) ($in['pattern'] ?? ''),
            'actions_json' => json_encode(($in['actions'] ?? []), JSON_UNESCAPED_SLASHES),
        ]);
    }
    public function updatePageRule(string $siteId, string $id, array $in): ?array {
        if (array_key_exists('actions', $in)) {
            $in['actions_json'] = json_encode($in['actions'], JSON_UNESCAPED_SLASHES);
            unset($in['actions']);
        }
        return $this->update('page_rules', $siteId, $id, $in);
    }
    public function deletePageRule(string $siteId, string $id): bool { return $this->delete('page_rules', $siteId, $id); }
    public function testPageRule(string $siteId, string $path): array {
        $rules = array_values(array_filter($this->listPageRules($siteId), static fn (array $r): bool => !empty($r['enabled'])));
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
    public function listSslCertificates(string $siteId): array {
        $s = Database::pdo()->prepare('SELECT * FROM ssl_certificates WHERE site_id=:site_id ORDER BY hostname ASC');
        $s->execute([':site_id' => $siteId]);
        return array_map([$this, 'cast'], $s->fetchAll());
    }
    public function checkSslCertificates(string $siteId, array $hostnames): array {
        $now = time();
        $targets = $hostnames === [] ? [''] : $hostnames;
        foreach ($targets as $hostname) {
            $h = trim((string) $hostname);
            if ($h === '') {
                continue;
            }
            $s = Database::pdo()->prepare('SELECT id FROM ssl_certificates WHERE site_id=:site_id AND hostname=:hostname LIMIT 1');
            $s->execute([':site_id' => $siteId, ':hostname' => $h]);
            $id = $s->fetchColumn();
            if ($id === false) {
                $i = Database::pdo()->prepare('INSERT INTO ssl_certificates (id,site_id,hostname,provider,status,issuer,serial_number,not_before,not_after,days_until_expiry,renewal_due_at,last_checked_at,last_error,created_at,updated_at) VALUES (:id,:site_id,:hostname,:provider,:status,:issuer,:serial,:not_before,:not_after,:days,:renewal,:checked,:error,:created,:updated)');
                $i->execute([
                    ':id' => Uuid::v4(), ':site_id' => $siteId, ':hostname' => $h, ':provider' => 'manual', ':status' => 'missing',
                    ':issuer' => null, ':serial' => null, ':not_before' => null, ':not_after' => null, ':days' => null, ':renewal' => null,
                    ':checked' => $now, ':error' => 'certificate_not_provisioned', ':created' => $now, ':updated' => $now,
                ]);
            } else {
                $u = Database::pdo()->prepare('UPDATE ssl_certificates SET last_checked_at=:checked,last_error=:error,updated_at=:updated WHERE id=:id');
                $u->execute([':checked' => $now, ':error' => 'certificate_not_provisioned', ':updated' => $now, ':id' => $id]);
            }
        }
        return $this->listSslCertificates($siteId);
    }
    public function importManualSslCertificate(string $siteId, string $hostname, string $certificatePem, string $privateKeyPem): array {
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
        if ($status !== 'active') {
            throw new \InvalidArgumentException('certificate_not_active');
        }

        $s = Database::pdo()->prepare('SELECT id FROM ssl_certificates WHERE site_id=:site_id AND hostname=:hostname LIMIT 1');
        $s->execute([':site_id' => $siteId, ':hostname' => $hostname]);
        $id = $s->fetchColumn();
        if ($id === false) {
            $id = Uuid::v4();
            $i = Database::pdo()->prepare('INSERT INTO ssl_certificates (id,site_id,hostname,provider,status,issuer,serial_number,not_before,not_after,days_until_expiry,renewal_due_at,last_checked_at,last_error,certificate_pem,private_key_pem,created_at,updated_at) VALUES (:id,:site_id,:hostname,:provider,:status,:issuer,:serial,:not_before,:not_after,:days,:renewal,:checked,:error,:cert,:key,:created,:updated)');
            $i->execute([':id'=>$id,':site_id'=>$siteId,':hostname'=>$hostname,':provider'=>'manual',':status'=>$status,':issuer'=>$issuer,':serial'=>$serial,':not_before'=>$notBefore,':not_after'=>$notAfter,':days'=>$days,':renewal'=>null,':checked'=>$now,':error'=>null,':cert'=>$certificatePem,':key'=>Secrets::encrypt($privateKeyPem),':created'=>$now,':updated'=>$now]);
        } else {
            $u = Database::pdo()->prepare('UPDATE ssl_certificates SET provider=:provider,status=:status,issuer=:issuer,serial_number=:serial,not_before=:not_before,not_after=:not_after,days_until_expiry=:days,last_checked_at=:checked,last_error=:error,certificate_pem=:cert,private_key_pem=:key,updated_at=:updated WHERE id=:id');
            $u->execute([':provider'=>'manual',':status'=>$status,':issuer'=>$issuer,':serial'=>$serial,':not_before'=>$notBefore,':not_after'=>$notAfter,':days'=>$days,':checked'=>$now,':error'=>null,':cert'=>$certificatePem,':key'=>Secrets::encrypt($privateKeyPem),':updated'=>$now,':id'=>$id]);
        }
        $q = Database::pdo()->prepare('SELECT * FROM ssl_certificates WHERE id=:id LIMIT 1');
        $q->execute([':id' => $id]);
        return $this->cast((array) $q->fetch());
    }
    public function listSslCertificatesForConfig(string $siteId, string $host): array {
        $s = Database::pdo()->prepare("SELECT hostname,certificate_pem,private_key_pem,status FROM ssl_certificates WHERE site_id=:site_id AND status='active' AND certificate_pem IS NOT NULL AND private_key_pem IS NOT NULL");
        $s->execute([':site_id' => $siteId]);
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
    public function getSiteCacheSettings(string $siteId): array {
        $s = Database::pdo()->prepare('SELECT * FROM site_cache_settings WHERE site_id=:site_id LIMIT 1');
        $s->execute([':site_id' => $siteId]);
        $row = $s->fetch();
        if ($row) {
            return $this->cast((array) $row);
        }
        $now = time();
        $defaults = [
            'site_id' => $siteId,
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
        $ins = Database::pdo()->prepare('INSERT INTO site_cache_settings (site_id,enabled,default_edge_ttl_seconds,default_browser_ttl_seconds,cache_query_string_mode,respect_origin_cache_control,cache_authorized_requests,stale_if_error_seconds,created_at,updated_at) VALUES (:site_id,:enabled,:edge,:browser,:mode,:respect,:authorized,:stale,:created_at,:updated_at)');
        $ins->execute([
            ':site_id' => $siteId,
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
    public function setSiteCacheSettings(string $siteId, array $in): array {
        $existing = $this->getSiteCacheSettings($siteId);
        $payload = [
            ':site_id' => $siteId,
            ':enabled' => (int) ($in['enabled'] ?? $existing['enabled']),
            ':edge' => (int) ($in['default_edge_ttl_seconds'] ?? $existing['default_edge_ttl_seconds']),
            ':browser' => array_key_exists('default_browser_ttl_seconds', $in) ? $in['default_browser_ttl_seconds'] : $existing['default_browser_ttl_seconds'],
            ':mode' => (string) ($in['cache_query_string_mode'] ?? $existing['cache_query_string_mode']),
            ':respect' => (int) ($in['respect_origin_cache_control'] ?? $existing['respect_origin_cache_control']),
            ':authorized' => (int) ($in['cache_authorized_requests'] ?? $existing['cache_authorized_requests']),
            ':stale' => (int) ($in['stale_if_error_seconds'] ?? $existing['stale_if_error_seconds']),
            ':updated_at' => time(),
        ];
        $u = Database::pdo()->prepare('UPDATE site_cache_settings SET enabled=:enabled,default_edge_ttl_seconds=:edge,default_browser_ttl_seconds=:browser,cache_query_string_mode=:mode,respect_origin_cache_control=:respect,cache_authorized_requests=:authorized,stale_if_error_seconds=:stale,updated_at=:updated_at WHERE site_id=:site_id');
        $u->execute($payload);
        return $this->getSiteCacheSettings($siteId);
    }
    public function createCachePurgeRequest(string $siteId, array $in): array {
        $type = (string) ($in['type'] ?? 'site');
        $value = array_key_exists('value', $in) ? (string) $in['value'] : null;
        $scope = $type === 'everything' ? 'site' : $type;
        $scopeValue = $scope === 'site' ? '*' : (string) ($value ?? '*');
        $now = time();
        $requestId = Uuid::v4();

        $existing = Database::pdo()->prepare('SELECT * FROM cache_purge_versions WHERE site_id=:site_id AND scope=:scope AND value=:value LIMIT 1');
        $existing->execute([':site_id' => $siteId, ':scope' => $scope, ':value' => $scopeValue]);
        $row = $existing->fetch();
        $beforeVersion = $row ? (int) $row['version'] : 1;
        $nextVersion = $beforeVersion + 1;
        if ($row) {
            $u = Database::pdo()->prepare('UPDATE cache_purge_versions SET version=:version,updated_at=:updated_at WHERE site_id=:site_id AND scope=:scope AND value=:value');
            $u->execute([':version' => $nextVersion, ':updated_at' => $now, ':site_id' => $siteId, ':scope' => $scope, ':value' => $scopeValue]);
        } else {
            $i = Database::pdo()->prepare('INSERT INTO cache_purge_versions (id,site_id,scope,value,version,updated_at) VALUES (:id,:site_id,:scope,:value,:version,:updated_at)');
            $i->execute([':id' => Uuid::v4(), ':site_id' => $siteId, ':scope' => $scope, ':value' => $scopeValue, ':version' => $nextVersion, ':updated_at' => $now]);
        }
        $r = Database::pdo()->prepare('INSERT INTO cache_purge_requests (id,site_id,type,value,status,requested_by,edge_seen_count,error,created_at,updated_at,completed_at) VALUES (:id,:site_id,:type,:value,:status,:requested_by,:edge_seen_count,:error,:created_at,:updated_at,:completed_at)');
        $r->execute([':id'=>$requestId,':site_id'=>$siteId,':type'=>$type,':value'=>$value,':status'=>'completed',':requested_by'=>null,':edge_seen_count'=>0,':error'=>null,':created_at'=>$now,':updated_at'=>$now,':completed_at'=>$now]);
        $audit = Database::pdo()->prepare('INSERT INTO audit_log (id, actor_type, actor_id, action, resource_type, resource_id, site_id, details_json, event, created_at) VALUES (:id,:actor_type,:actor_id,:action,:resource_type,:resource_id,:site_id,:details_json,:event,:created_at)');
        $audit->execute([
            ':id' => Uuid::v4(),
            ':actor_type' => 'system',
            ':actor_id' => null,
            ':action' => 'purge',
            ':resource_type' => 'cache',
            ':resource_id' => $requestId,
            ':site_id' => $siteId,
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
    public function listCachePurgeRequests(string $siteId): array {
        $s = Database::pdo()->prepare('SELECT * FROM cache_purge_requests WHERE site_id=:site_id ORDER BY created_at DESC');
        $s->execute([':site_id' => $siteId]);
        return array_map([$this, 'cast'], $s->fetchAll());
    }
    public function getCachePurgeRequest(string $siteId, string $id): ?array {
        $s = Database::pdo()->prepare('SELECT * FROM cache_purge_requests WHERE site_id=:site_id AND id=:id LIMIT 1');
        $s->execute([':site_id' => $siteId, ':id' => $id]);
        $r = $s->fetch();
        return $r ? $this->cast((array) $r) : null;
    }
    public function listCachePurgeVersionsForConfig(string $siteId, string $host): array {
        $s = Database::pdo()->prepare('SELECT scope,value,version,updated_at FROM cache_purge_versions WHERE site_id=:site_id ORDER BY scope ASC, value ASC');
        $s->execute([':site_id' => $siteId]);
        $rows = [];
        foreach ($s->fetchAll() as $r) {
            $rows[] = ['host' => $host, 'scope' => (string) $r['scope'], 'value' => (string) $r['value'], 'version' => (int) $r['version'], 'updated_at' => (int) $r['updated_at']];
        }
        return $rows;
    }
    public function listSecurityEvents(string $siteId, ?string $type = null, int $limit = 100): array {
        $query = 'SELECT id,event,details_json,created_at FROM audit_log WHERE site_id=:site_id';
        $params = [':site_id' => $siteId];
        if ($type !== null && $type !== '') {
            $query .= ' AND event=:event';
            $params[':event'] = $type;
        }
        $query .= ' ORDER BY created_at DESC LIMIT :limit';
        $stmt = Database::pdo()->prepare($query);
        $stmt->bindValue(':site_id', $siteId);
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

    public function getRateLimit(string $siteId): ?array {
        if ($this->rateLimitV2Supported()) {
            $s = Database::pdo()->prepare('SELECT * FROM rate_limit_rules_v2 WHERE site_id=:site_id ORDER BY priority ASC, created_at ASC LIMIT 1');
            $s->execute([':site_id'=>$siteId]); $r = $s->fetch(); return $r ? $this->cast($r):null;
        }
        $s = Database::pdo()->prepare('SELECT * FROM rate_limit_rules WHERE site_id=:site_id LIMIT 1');
        $s->execute([':site_id'=>$siteId]); $r = $s->fetch();
        if (!$r) { return null; }
        $row = $this->cast((array) $r);
        $row['priority'] = 100;
        $row['path_prefix'] = '/';
        $row['key_type'] = 'ip';
        $row['action'] = 'block';
        return $row;
    }
    public function setRateLimit(string $siteId, array $in): array {
        $now=time(); $existing=$this->getRateLimit($siteId); $rpm=(int)($in['requests_per_minute'] ?? 60); $enabled=!empty($in['enabled']);
        $priority = (int)($in['priority'] ?? 100);
        $pathPrefix = (string)($in['path_prefix'] ?? '/');
        $keyType = (string)($in['key_type'] ?? 'ip');
        $action = (string)($in['action'] ?? 'block');
        if ($existing) {
            if (!$this->rateLimitV2Supported()) {
                $s=Database::pdo()->prepare('UPDATE rate_limit_rules SET enabled=:enabled,requests_per_minute=:rpm,updated_at=:u WHERE id=:id');
                $s->execute([':enabled'=>(int)$enabled,':rpm'=>$rpm,':u'=>$now,':id'=>$existing['id']]);
                return $this->getRateLimit($siteId);
            }
            $s=Database::pdo()->prepare('UPDATE rate_limit_rules_v2 SET enabled=:enabled,priority=:priority,path_prefix=:path_prefix,key_type=:key_type,requests_per_minute=:rpm,action=:action,updated_at=:u WHERE id=:id');
            $s->execute([':enabled'=>(int)$enabled,':priority'=>$priority,':path_prefix'=>$pathPrefix,':key_type'=>$keyType,':rpm'=>$rpm,':action'=>$action,':u'=>$now,':id'=>$existing['id']]);
            return $this->getRateLimit($siteId);
        }
        $id=Uuid::v4();
        if (!$this->rateLimitV2Supported()) {
            $s=Database::pdo()->prepare('INSERT INTO rate_limit_rules (id,site_id,enabled,requests_per_minute,created_at,updated_at) VALUES (:id,:site,:en,:rpm,:c,:u)');
            $s->execute([':id'=>$id,':site'=>$siteId,':en'=>(int)$enabled,':rpm'=>$rpm,':c'=>$now,':u'=>$now]);
            return $this->getRateLimit($siteId);
        }
        $s=Database::pdo()->prepare('INSERT INTO rate_limit_rules_v2 (id,site_id,enabled,priority,path_prefix,key_type,requests_per_minute,action,created_at,updated_at) VALUES (:id,:site,:en,:priority,:path_prefix,:key_type,:rpm,:action,:c,:u)');
        $s->execute([':id'=>$id,':site'=>$siteId,':en'=>(int)$enabled,':priority'=>$priority,':path_prefix'=>$pathPrefix,':key_type'=>$keyType,':rpm'=>$rpm,':action'=>$action,':c'=>$now,':u'=>$now]);
        return $this->getRateLimit($siteId);
    }
    public function disableRateLimit(string $siteId): bool {
        $table = $this->rateLimitV2Supported() ? 'rate_limit_rules_v2' : 'rate_limit_rules';
        $s=Database::pdo()->prepare("DELETE FROM {$table} WHERE site_id=:site"); $s->execute([':site'=>$siteId]); return $s->rowCount()>0;
    }

    private function listRows(string $table, string $siteId): array { $s=Database::pdo()->prepare("SELECT * FROM {$table} WHERE site_id=:site_id ORDER BY created_at ASC"); $s->execute([':site_id'=>$siteId]); return array_map([$this,'cast'], $s->fetchAll()); }
    private function insert(string $table, string $siteId, array $in): array {
        $id=Uuid::v4(); $now=time(); $cols=array_keys($in); $names=implode(',', $cols); $bind=':'.implode(',:', $cols);
        $sql="INSERT INTO {$table} (id,site_id,{$names},created_at,updated_at) VALUES (:id,:site_id,{$bind},:created_at,:updated_at)";
        $p=[':id'=>$id,':site_id'=>$siteId,':created_at'=>$now,':updated_at'=>$now]; foreach($in as $k=>$v){$p[':'.$k]=is_bool($v)?(int)$v:$v;}
        $s=Database::pdo()->prepare($sql); $s->execute($p); $q=Database::pdo()->prepare("SELECT * FROM {$table} WHERE id=:id"); $q->execute([':id'=>$id]); return $this->cast((array)$q->fetch());
    }
    private function update(string $table, string $siteId, string $id, array $in): ?array {
        $q=Database::pdo()->prepare("SELECT * FROM {$table} WHERE id=:id AND site_id=:site LIMIT 1"); $q->execute([':id'=>$id,':site'=>$siteId]); if(!$q->fetch()){return null;}
        $sets=[]; $p=[':id'=>$id,':site'=>$siteId,':u'=>time()];
        foreach($in as $k=>$v){$sets[]="{$k}=:{$k}"; $p[':'.$k]=is_bool($v)?(int)$v:$v;}
        $sets[]='updated_at=:u';
        $s=Database::pdo()->prepare("UPDATE {$table} SET ".implode(',', $sets)." WHERE id=:id AND site_id=:site"); $s->execute($p);
        $r=Database::pdo()->prepare("SELECT * FROM {$table} WHERE id=:id"); $r->execute([':id'=>$id]); return $this->cast((array)$r->fetch());
    }
    private function delete(string $table, string $siteId, string $id): bool { $s=Database::pdo()->prepare("DELETE FROM {$table} WHERE id=:id AND site_id=:site"); $s->execute([':id'=>$id,':site'=>$siteId]); return $s->rowCount()>0; }
    private function cast(array $r): array { foreach(['enabled', 'preserve_query', 'respect_origin_cache_control', 'cache_authorized_requests'] as $b){ if(array_key_exists($b,$r)){$r[$b]=((int)$r[$b])===1;}} foreach(['created_at','updated_at','ttl_seconds','requests_per_minute','status_code','priority','default_edge_ttl_seconds','default_browser_ttl_seconds','stale_if_error_seconds'] as $i){ if(isset($r[$i]) && $r[$i] !== null){$r[$i]=(int)$r[$i];}} if (array_key_exists('actions_json', $r)) { $r['actions'] = json_decode((string) $r['actions_json'], true) ?: []; } unset($r['private_key_pem']); return $r; }
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
        $s = Database::pdo()->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name='rate_limit_rules_v2' LIMIT 1");
        $s->execute();
        $this->rateLimitV2TableAvailable = $s->fetchColumn() !== false;
        return $this->rateLimitV2TableAvailable;
    }
}
