<?php
namespace App\Modules\Overview\Services;

use App\Support\Database;

class OverviewService
{
    public function overview(?int $now = null): array
    {
        $now ??= time(); $since = $now - 86400; $pdo = Database::pdo();
        $domains = (array) $pdo->query("SELECT COUNT(*) domains_count, COUNT(*) FILTER (WHERE status='active') active_domains FROM domains")->fetch();
        $usage = $pdo->prepare("SELECT COALESCE(SUM(requests_count),0) requests, COALESCE(SUM(bytes_out),0) bandwidth, COALESCE(SUM(requests_count) FILTER (WHERE UPPER(cache_status)='HIT'),0) hits FROM usage_rollups WHERE ts>=:since");
        $usage->execute([':since' => $since]); $traffic = (array) $usage->fetch();
        $edges = $pdo->prepare("SELECT COUNT(*) FILTER (WHERE is_enabled=true AND COALESCE(last_heartbeat_at,last_heartbeat)>=:cutoff) online, COUNT(*) FILTER (WHERE is_enabled=true AND COALESCE(last_heartbeat_at,last_heartbeat)<:cutoff) offline FROM edge_nodes");
        $edges->execute([':cutoff' => $now - 300]); $edgeCounts = (array) $edges->fetch();
        $security = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE event IN ('waf_match','rate_limited') AND created_at>=:since");
        $security->execute([':since' => $since]);
        $ssl = $pdo->prepare('SELECT COUNT(*) FROM ssl_certificates WHERE not_after>=:now AND not_after<:expiry');
        $ssl->execute([':now' => $now, ':expiry' => $now + 2592000]);
        $top = $pdo->prepare('SELECT d.id domain_id,d.name,d.domain,COALESCE(SUM(u.requests_count),0) requests FROM domains d LEFT JOIN usage_rollups u ON u.domain_id=d.id AND u.ts>=:since GROUP BY d.id,d.name,d.domain ORDER BY requests DESC,d.domain ASC LIMIT 5');
        $top->execute([':since' => $since]);
        $requests = (int) ($traffic['requests'] ?? 0);
        return [
            'domains_count'=>(int)($domains['domains_count']??0), 'active_domains'=>(int)($domains['active_domains']??0),
            'total_requests_24h'=>$requests, 'bandwidth_24h_bytes'=>(int)($traffic['bandwidth']??0),
            'cache_hit_ratio_24h'=>$requests > 0 ? (int)$traffic['hits'] / $requests : 0.0,
            'edge_online'=>(int)($edgeCounts['online']??0), 'edge_offline'=>(int)($edgeCounts['offline']??0),
            'security_events_24h'=>(int)$security->fetchColumn(), 'ssl_expiring_count'=>(int)$ssl->fetchColumn(),
            'top_domains'=>array_map(fn(array $r)=>['domain_id'=>(string)$r['domain_id'],'name'=>(string)$r['name'],'domain'=>(string)$r['domain'],'requests'=>(int)$r['requests']], $top->fetchAll()),
            'recent_snapshots'=>array_map(fn(array $r)=>['version'=>(int)$r['version'],'generated_at'=>(int)$r['generated_at']], $pdo->query('SELECT version,generated_at FROM config_snapshots ORDER BY generated_at DESC,version DESC LIMIT 5')->fetchAll()),
        ];
    }

    public function warnings(?int $now = null): array
    {
        $now ??= time(); $pdo = Database::pdo(); $warnings = [];
        $ssl = $pdo->prepare('SELECT COUNT(*) FROM ssl_certificates WHERE not_after>=:now AND not_after<:expiry');
        $ssl->execute([':now'=>$now, ':expiry'=>$now+2592000]); $count=(int)$ssl->fetchColumn();
        if ($count) {
            $domain = $pdo->prepare('SELECT domain_id FROM ssl_certificates WHERE not_after>=:now AND not_after<:expiry ORDER BY not_after ASC LIMIT 1');
            $domain->execute([':now'=>$now, ':expiry'=>$now+2592000]);
            $domainId = $domain->fetchColumn();
            $warnings[]=['severity'=>'warning','message'=>sprintf('%d SSL certificate%s expire within 30 days',$count,$count===1?'':'s'),'link'=>$domainId ? '/domains/'.$domainId.'/ssl' : '/domains'];
        }
        $edges=$pdo->prepare('SELECT COUNT(*) FROM edge_nodes WHERE is_enabled=true AND COALESCE(last_heartbeat_at,last_heartbeat)<:cutoff');
        $edges->execute([':cutoff'=>$now-300]); $count=(int)$edges->fetchColumn();
        if ($count) $warnings[]=['severity'=>'critical','message'=>sprintf('%d edge node%s offline',$count,$count===1?' is':'s are'),'link'=>'/edge-nodes'];
        $count=(int)$pdo->query("SELECT COUNT(*) FROM domains WHERE status<>'active'")->fetchColumn();
        if ($count) $warnings[]=['severity'=>'warning','message'=>sprintf('%d domain%s need activation or verification',$count,$count===1?'':'s'),'link'=>'/domains'];
        return $warnings;
    }
}
