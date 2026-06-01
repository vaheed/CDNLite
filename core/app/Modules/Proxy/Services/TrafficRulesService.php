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
            'enabled' => !empty($in['enabled']), 'source_path' => (string)($in['source_path'] ?? ''), 'target_url' => (string)($in['target_url'] ?? ''), 'status_code' => $status,
        ]);
    }
    public function updateRedirect(string $siteId, string $id, array $in): ?array { return $this->update('redirect_rules', $siteId, $id, $in); }
    public function deleteRedirect(string $siteId, string $id): bool { return $this->delete('redirect_rules', $siteId, $id); }

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
    private function cast(array $r): array { foreach(['enabled'] as $b){ if(array_key_exists($b,$r)){$r[$b]=((int)$r[$b])===1;}} foreach(['created_at','updated_at','ttl_seconds','requests_per_minute','status_code'] as $i){ if(isset($r[$i])){$r[$i]=(int)$r[$i];}} return $r; }
}
