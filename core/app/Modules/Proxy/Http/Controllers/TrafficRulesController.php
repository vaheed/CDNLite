<?php

namespace App\Modules\Proxy\Http\Controllers;

use App\Modules\Proxy\Services\TrafficRulesService;
use App\Support\Validator;

class TrafficRulesController
{
    public function __construct(private TrafficRulesService $service)
    {
    }

    public function createRedirect(string $siteId, array $body): array {
        $source = Validator::requiredString($body, 'source_path', 2048);
        if (($source['ok'] ?? false) !== true) { return $source; }
        if (!str_starts_with((string) $source['value'], '/')) {
            return ['error' => 'invalid_field', 'field' => 'source_path', 'detail' => 'must_start_with_slash', 'status' => 422];
        }
        $target = Validator::requiredString($body, 'target_url', 4096);
        if (($target['ok'] ?? false) !== true) { return $target; }
        $status = Validator::intRange($body, 'status_code', 301, 308, 302);
        if (($status['ok'] ?? false) !== true || !in_array((int) $status['value'], [301, 302, 307, 308], true)) {
            return ['error' => 'invalid_field', 'field' => 'status_code', 'detail' => 'must_be_one_of_301_302_307_308', 'status' => 422];
        }
        return ['data' => $this->service->createRedirect($siteId, $body)];
    }
    public function listRedirects(string $siteId): array { return ['data' => $this->service->listRedirects($siteId)]; }
    public function updateRedirect(string $siteId, string $id, array $body): array {
        if (array_key_exists('source_path', $body) && (!is_string($body['source_path']) || !str_starts_with((string) $body['source_path'], '/'))) {
            return ['error' => 'invalid_field', 'field' => 'source_path', 'detail' => 'must_start_with_slash', 'status' => 422];
        }
        if (array_key_exists('status_code', $body) && !in_array((int) $body['status_code'], [301, 302, 307, 308], true)) {
            return ['error' => 'invalid_field', 'field' => 'status_code', 'detail' => 'must_be_one_of_301_302_307_308', 'status' => 422];
        }
        $r=$this->service->updateRedirect($siteId,$id,$body); return $r?['data'=>$r]:['error'=>'redirect_not_found','status'=>404];
    }
    public function deleteRedirect(string $siteId, string $id): array { return $this->service->deleteRedirect($siteId,$id)?['ok'=>true]:['error'=>'redirect_not_found','status'=>404]; }

    public function setRateLimit(string $siteId, array $body): array {
        $rpm = Validator::intRange($body, 'requests_per_minute', 1, 100000, 60);
        if (($rpm['ok'] ?? false) !== true) { return $rpm; }
        return ['data' => $this->service->setRateLimit($siteId, $body)];
    }
    public function getRateLimit(string $siteId): array { $r=$this->service->getRateLimit($siteId); return $r?['data'=>$r]:['error'=>'rate_limit_not_found','status'=>404]; }
    public function disableRateLimit(string $siteId): array { return $this->service->disableRateLimit($siteId)?['ok'=>true]:['error'=>'rate_limit_not_found','status'=>404]; }

    public function createWaf(string $siteId, array $body): array {
        $type = Validator::requiredString($body, 'type', 64);
        if (($type['ok'] ?? false) !== true) { return $type; }
        if (!in_array((string) $type['value'], ['path_contains', 'user_agent_contains'], true)) {
            return ['error' => 'invalid_field', 'field' => 'type', 'detail' => 'must_be_one_of_path_contains_user_agent_contains', 'status' => 422];
        }
        $pattern = Validator::requiredString($body, 'pattern', 2048);
        if (($pattern['ok'] ?? false) !== true) { return $pattern; }
        return ['data' => $this->service->createWaf($siteId, $body)];
    }
    public function listWaf(string $siteId): array { return ['data' => $this->service->listWaf($siteId)]; }
    public function updateWaf(string $siteId, string $id, array $body): array { $r=$this->service->updateWaf($siteId,$id,$body); return $r?['data'=>$r]:['error'=>'waf_not_found','status'=>404]; }
    public function deleteWaf(string $siteId, string $id): array { return $this->service->deleteWaf($siteId,$id)?['ok'=>true]:['error'=>'waf_not_found','status'=>404]; }

    public function createCacheRule(string $siteId, array $body): array {
        if (array_key_exists('path_prefix', $body)) {
            $path = Validator::requiredString($body, 'path_prefix', 2048);
            if (($path['ok'] ?? false) !== true) { return $path; }
            if (!str_starts_with((string) $path['value'], '/')) {
                return ['error' => 'invalid_field', 'field' => 'path_prefix', 'detail' => 'must_start_with_slash', 'status' => 422];
            }
        }
        $ttl = Validator::intRange($body, 'ttl_seconds', 1, 31536000, 60);
        if (($ttl['ok'] ?? false) !== true) { return $ttl; }
        return ['data' => $this->service->createCacheRule($siteId, $body)];
    }
    public function listCacheRules(string $siteId): array { return ['data' => $this->service->listCacheRules($siteId)]; }
    public function updateCacheRule(string $siteId, string $id, array $body): array { $r=$this->service->updateCacheRule($siteId,$id,$body); return $r?['data'=>$r]:['error'=>'cache_rule_not_found','status'=>404]; }
    public function deleteCacheRule(string $siteId, string $id): array { return $this->service->deleteCacheRule($siteId,$id)?['ok'=>true]:['error'=>'cache_rule_not_found','status'=>404]; }
}
