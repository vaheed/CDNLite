<?php

namespace App\Modules\Proxy\Services;

use App\Support\Database;
use App\Support\Uuid;

class TrafficRulesService
{
    public function listRedirects(string $siteId): array { return $this->listRows('redirect_rules', $siteId); }
    public function createRedirect(string $siteId, array $in): array {
        $status = (int)($in['status_code'] ?? 302);
        if (!in_array($status, [301,302,307,308], true)) { throw new \InvalidArgumentException('invalid_status_code'); }
        return $this->insert('redirect_rules', $siteId, [
            'enabled' => !empty($in['enabled']),
            'source_path' => (string)($in['source_path'] ?? ''),
            'target_url' => (string)($in['target_url'] ?? ''),
            'status_code' => $status,
            'priority' => (int) ($in['priority'] ?? 100),
            'match_type' => (string) ($in['match_type'] ?? 'exact_path'),
            'preserve_query' => array_key_exists('preserve_query', $in) ? !empty($in['preserve_query']) : true,
        ]);
    }
    public function updateRedirect(string $siteId, string $id, array $in): ?array {
        if (array_key_exists('status_code', $in)) {
            $status = (int) $in['status_code'];
            if (!in_array($status, [301,302,307,308], true)) { throw new \InvalidArgumentException('invalid_status_code'); }
            $in['status_code'] = $status;
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
        if (!in_array($type, ['path_contains','user_agent_contains'], true)) { throw new \InvalidArgumentException('invalid_type'); }
        return $this->insert('waf_rules', $siteId, ['enabled'=>!empty($in['enabled']),'type'=>$type,'pattern'=>(string)($in['pattern'] ?? '')]);
    }
    public function updateWaf(string $siteId, string $id, array $in): ?array { return $this->update('waf_rules', $siteId, $id, $in); }
    public function deleteWaf(string $siteId, string $id): bool { return $this->delete('waf_rules', $siteId, $id); }

    public function listCacheRules(string $siteId): array { return $this->listRows('cache_rules', $siteId); }
    public function createCacheRule(string $siteId, array $in): array { return $this->insert('cache_rules', $siteId, ['enabled'=>!empty($in['enabled']),'path_prefix'=>(string)($in['path_prefix'] ?? '/'),'ttl_seconds'=>(int)($in['ttl_seconds'] ?? 60)]); }
    public function updateCacheRule(string $siteId, string $id, array $in): ?array { return $this->update('cache_rules', $siteId, $id, $in); }
    public function deleteCacheRule(string $siteId, string $id): bool { return $this->delete('cache_rules', $siteId, $id); }
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
        $nextVersion = $row ? ((int) $row['version']) + 1 : 2;
        if ($row) {
            $u = Database::pdo()->prepare('UPDATE cache_purge_versions SET version=:version,updated_at=:updated_at WHERE site_id=:site_id AND scope=:scope AND value=:value');
            $u->execute([':version' => $nextVersion, ':updated_at' => $now, ':site_id' => $siteId, ':scope' => $scope, ':value' => $scopeValue]);
        } else {
            $i = Database::pdo()->prepare('INSERT INTO cache_purge_versions (id,site_id,scope,value,version,updated_at) VALUES (:id,:site_id,:scope,:value,:version,:updated_at)');
            $i->execute([':id' => Uuid::v4(), ':site_id' => $siteId, ':scope' => $scope, ':value' => $scopeValue, ':version' => $nextVersion, ':updated_at' => $now]);
        }
        $r = Database::pdo()->prepare('INSERT INTO cache_purge_requests (id,site_id,type,value,status,requested_by,edge_seen_count,error,created_at,updated_at,completed_at) VALUES (:id,:site_id,:type,:value,:status,:requested_by,:edge_seen_count,:error,:created_at,:updated_at,:completed_at)');
        $r->execute([':id'=>$requestId,':site_id'=>$siteId,':type'=>$type,':value'=>$value,':status'=>'completed',':requested_by'=>null,':edge_seen_count'=>0,':error'=>null,':created_at'=>$now,':updated_at'=>$now,':completed_at'=>$now]);
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

    public function getRateLimit(string $siteId): ?array {
        $s = Database::pdo()->prepare('SELECT * FROM rate_limit_rules WHERE site_id=:site_id LIMIT 1'); $s->execute([':site_id'=>$siteId]); $r = $s->fetch(); return $r ? $this->cast($r):null;
    }
    public function setRateLimit(string $siteId, array $in): array {
        $now=time(); $existing=$this->getRateLimit($siteId); $rpm=(int)($in['requests_per_minute'] ?? 60); $enabled=!empty($in['enabled']);
        if ($existing) {
            $s=Database::pdo()->prepare('UPDATE rate_limit_rules SET enabled=:enabled,requests_per_minute=:rpm,updated_at=:u WHERE id=:id');
            $s->execute([':enabled'=>(int)$enabled,':rpm'=>$rpm,':u'=>$now,':id'=>$existing['id']]);
            return $this->getRateLimit($siteId);
        }
        $id=Uuid::v4();
        $s=Database::pdo()->prepare('INSERT INTO rate_limit_rules (id,site_id,enabled,requests_per_minute,created_at,updated_at) VALUES (:id,:site,:en,:rpm,:c,:u)');
        $s->execute([':id'=>$id,':site'=>$siteId,':en'=>(int)$enabled,':rpm'=>$rpm,':c'=>$now,':u'=>$now]);
        return $this->getRateLimit($siteId);
    }
    public function disableRateLimit(string $siteId): bool { $s=Database::pdo()->prepare('DELETE FROM rate_limit_rules WHERE site_id=:site'); $s->execute([':site'=>$siteId]); return $s->rowCount()>0; }

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
    private function cast(array $r): array { foreach(['enabled', 'preserve_query', 'respect_origin_cache_control', 'cache_authorized_requests'] as $b){ if(array_key_exists($b,$r)){$r[$b]=((int)$r[$b])===1;}} foreach(['created_at','updated_at','ttl_seconds','requests_per_minute','status_code','priority','default_edge_ttl_seconds','default_browser_ttl_seconds','stale_if_error_seconds'] as $i){ if(isset($r[$i])){$r[$i]=(int)$r[$i];}} return $r; }
}
