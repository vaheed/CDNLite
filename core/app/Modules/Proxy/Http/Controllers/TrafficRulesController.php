<?php

namespace App\Modules\Proxy\Http\Controllers;

use App\Modules\Proxy\Services\TrafficRulesService;

class TrafficRulesController
{
    public function __construct(private TrafficRulesService $service)
    {
    }

    public function createRedirect(string $siteId, array $body): array { return ['data' => $this->service->createRedirect($siteId, $body)]; }
    public function listRedirects(string $siteId): array { return ['data' => $this->service->listRedirects($siteId)]; }
    public function updateRedirect(string $siteId, string $id, array $body): array { $r=$this->service->updateRedirect($siteId,$id,$body); return $r?['data'=>$r]:['error'=>'redirect_not_found','status'=>404]; }
    public function deleteRedirect(string $siteId, string $id): array { return $this->service->deleteRedirect($siteId,$id)?['ok'=>true]:['error'=>'redirect_not_found','status'=>404]; }

    public function setRateLimit(string $siteId, array $body): array { return ['data' => $this->service->setRateLimit($siteId, $body)]; }
    public function getRateLimit(string $siteId): array { $r=$this->service->getRateLimit($siteId); return $r?['data'=>$r]:['error'=>'rate_limit_not_found','status'=>404]; }
    public function disableRateLimit(string $siteId): array { return $this->service->disableRateLimit($siteId)?['ok'=>true]:['error'=>'rate_limit_not_found','status'=>404]; }

    public function createWaf(string $siteId, array $body): array { return ['data' => $this->service->createWaf($siteId, $body)]; }
    public function listWaf(string $siteId): array { return ['data' => $this->service->listWaf($siteId)]; }
    public function updateWaf(string $siteId, string $id, array $body): array { $r=$this->service->updateWaf($siteId,$id,$body); return $r?['data'=>$r]:['error'=>'waf_not_found','status'=>404]; }
    public function deleteWaf(string $siteId, string $id): array { return $this->service->deleteWaf($siteId,$id)?['ok'=>true]:['error'=>'waf_not_found','status'=>404]; }

    public function createCacheRule(string $siteId, array $body): array { return ['data' => $this->service->createCacheRule($siteId, $body)]; }
    public function listCacheRules(string $siteId): array { return ['data' => $this->service->listCacheRules($siteId)]; }
    public function updateCacheRule(string $siteId, string $id, array $body): array { $r=$this->service->updateCacheRule($siteId,$id,$body); return $r?['data'=>$r]:['error'=>'cache_rule_not_found','status'=>404]; }
    public function deleteCacheRule(string $siteId, string $id): array { return $this->service->deleteCacheRule($siteId,$id)?['ok'=>true]:['error'=>'cache_rule_not_found','status'=>404]; }
}
