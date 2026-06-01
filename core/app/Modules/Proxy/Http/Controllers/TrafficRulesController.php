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
        $priority = Validator::intRange($body, 'priority', 1, 100000, 100);
        if (($priority['ok'] ?? false) !== true) { return $priority; }
        $matchType = Validator::enum($body, 'match_type', ['exact_path', 'prefix', 'wildcard_simple']);
        if (($matchType['ok'] ?? false) !== true) { return $matchType; }
        $preserveQuery = Validator::bool($body, 'preserve_query', true);
        if (($preserveQuery['ok'] ?? false) !== true) { return $preserveQuery; }
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
        if (array_key_exists('priority', $body)) {
            $priority = Validator::intRange($body, 'priority', 1, 100000);
            if (($priority['ok'] ?? false) !== true) { return $priority; }
        }
        if (array_key_exists('match_type', $body)) {
            $matchType = Validator::enum($body, 'match_type', ['exact_path', 'prefix', 'wildcard_simple']);
            if (($matchType['ok'] ?? false) !== true) { return $matchType; }
        }
        if (array_key_exists('preserve_query', $body)) {
            $preserveQuery = Validator::bool($body, 'preserve_query');
            if (($preserveQuery['ok'] ?? false) !== true) { return $preserveQuery; }
        }
        $r=$this->service->updateRedirect($siteId,$id,$body); return $r?['data'=>$r]:['error'=>'redirect_not_found','status'=>404];
    }
    public function deleteRedirect(string $siteId, string $id): array { return $this->service->deleteRedirect($siteId,$id)?['ok'=>true]:['error'=>'redirect_not_found','status'=>404]; }
    public function importRedirects(string $siteId, array $body): array {
        if (!isset($body['items']) || !is_array($body['items'])) {
            return ['error' => 'invalid_field', 'field' => 'items', 'detail' => 'must_be_array', 'status' => 422];
        }
        return ['data' => $this->service->importRedirects($siteId, $body['items'])];
    }
    public function exportRedirects(string $siteId): array { return ['data' => $this->service->exportRedirects($siteId)]; }
    public function testRedirect(string $siteId, array $body): array {
        $path = Validator::requiredString($body, 'path', 2048);
        if (($path['ok'] ?? false) !== true) { return $path; }
        if (!str_starts_with((string) $path['value'], '/')) {
            return ['error' => 'invalid_field', 'field' => 'path', 'detail' => 'must_start_with_slash', 'status' => 422];
        }
        $query = Validator::optionalString($body, 'query', 4096);
        if (($query['ok'] ?? false) !== true) { return $query; }
        $result = $this->service->testRedirect($siteId, (string) $path['value'], (string) ($query['value'] ?? ''));
        return ['data' => $result ?? ['matched' => false]];
    }

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
    public function updateWaf(string $siteId, string $id, array $body): array {
        if (array_key_exists('type', $body) && !in_array((string) $body['type'], ['path_contains', 'user_agent_contains'], true)) {
            return ['error' => 'invalid_field', 'field' => 'type', 'detail' => 'must_be_one_of_path_contains_user_agent_contains', 'status' => 422];
        }
        if (array_key_exists('pattern', $body) && (!is_string($body['pattern']) || trim((string) $body['pattern']) === '')) {
            return ['error' => 'invalid_field', 'field' => 'pattern', 'detail' => 'must_be_non_empty_string', 'status' => 422];
        }
        $r=$this->service->updateWaf($siteId,$id,$body); return $r?['data'=>$r]:['error'=>'waf_not_found','status'=>404];
    }

    public function updateCacheRule(string $siteId, string $id, array $body): array {
        if (array_key_exists('path_prefix', $body) && (!is_string($body['path_prefix']) || !str_starts_with((string) $body['path_prefix'], '/'))) {
            return ['error' => 'invalid_field', 'field' => 'path_prefix', 'detail' => 'must_start_with_slash', 'status' => 422];
        }
        if (array_key_exists('ttl_seconds', $body)) {
            $ttl = Validator::intRange($body, 'ttl_seconds', 1, 31536000);
            if (($ttl['ok'] ?? false) !== true) { return $ttl; }
        }
        $r=$this->service->updateCacheRule($siteId,$id,$body); return $r?['data'=>$r]:['error'=>'cache_rule_not_found','status'=>404];
    }
    public function deleteCacheRule(string $siteId, string $id): array { return $this->service->deleteCacheRule($siteId,$id)?['ok'=>true]:['error'=>'cache_rule_not_found','status'=>404]; }
    public function getSiteCacheSettings(string $siteId): array { return ['data' => $this->service->getSiteCacheSettings($siteId)]; }
    public function setSiteCacheSettings(string $siteId, array $body): array {
        $enabled = Validator::bool($body, 'enabled', true);
        if (($enabled['ok'] ?? false) !== true) { return $enabled; }
        $edgeTtl = Validator::intRange($body, 'default_edge_ttl_seconds', 1, 31536000, 3600);
        if (($edgeTtl['ok'] ?? false) !== true) { return $edgeTtl; }
        if (array_key_exists('default_browser_ttl_seconds', $body) && $body['default_browser_ttl_seconds'] !== null) {
            $browserTtl = Validator::intRange($body, 'default_browser_ttl_seconds', 1, 31536000);
            if (($browserTtl['ok'] ?? false) !== true) { return $browserTtl; }
        }
        $mode = Validator::enum($body, 'cache_query_string_mode', ['include_all', 'ignore_all', 'include_allowlist']);
        if (($mode['ok'] ?? false) !== true) { return $mode; }
        $respect = Validator::bool($body, 'respect_origin_cache_control', true);
        if (($respect['ok'] ?? false) !== true) { return $respect; }
        $authorized = Validator::bool($body, 'cache_authorized_requests', false);
        if (($authorized['ok'] ?? false) !== true) { return $authorized; }
        $stale = Validator::intRange($body, 'stale_if_error_seconds', 0, 31536000, 86400);
        if (($stale['ok'] ?? false) !== true) { return $stale; }
        return ['data' => $this->service->setSiteCacheSettings($siteId, $body)];
    }
    public function createCachePurgeRequest(string $siteId, array $body): array {
        $type = Validator::enum($body, 'type', ['url', 'prefix', 'site', 'everything']);
        if (($type['ok'] ?? false) !== true || ($type['exists'] ?? false) !== true) {
            return ['error' => 'invalid_field', 'field' => 'type', 'detail' => 'must_be_one_of_url_prefix_site_everything', 'status' => 422];
        }
        $value = Validator::optionalString($body, 'value', 4096);
        if (($value['ok'] ?? false) !== true) { return $value; }
        if (in_array((string) $type['value'], ['url', 'prefix'], true) && (($value['exists'] ?? false) !== true || (string) $value['value'] === '')) {
            return ['error' => 'invalid_field', 'field' => 'value', 'detail' => 'required_for_url_or_prefix', 'status' => 422];
        }
        return ['data' => $this->service->createCachePurgeRequest($siteId, $body)];
    }
    public function listCachePurgeRequests(string $siteId): array { return ['data' => $this->service->listCachePurgeRequests($siteId)]; }
    public function getCachePurgeRequest(string $siteId, string $id): array {
        $row = $this->service->getCachePurgeRequest($siteId, $id);
        return $row ? ['data' => $row] : ['error' => 'cache_purge_request_not_found', 'status' => 404];
    }
    public function createPageRule(string $siteId, array $body): array {
        $pattern = Validator::requiredString($body, 'pattern', 2048);
        if (($pattern['ok'] ?? false) !== true) { return $pattern; }
        if (!str_starts_with((string) $pattern['value'], '/')) {
            return ['error' => 'invalid_field', 'field' => 'pattern', 'detail' => 'must_start_with_slash', 'status' => 422];
        }
        $priority = Validator::intRange($body, 'priority', 1, 100000, 100);
        if (($priority['ok'] ?? false) !== true) { return $priority; }
        if (!isset($body['actions']) || !is_array($body['actions'])) {
            return ['error' => 'invalid_field', 'field' => 'actions', 'detail' => 'must_be_object', 'status' => 422];
        }
        return ['data' => $this->service->createPageRule($siteId, $body)];
    }
    public function listPageRules(string $siteId): array { return ['data' => $this->service->listPageRules($siteId)]; }
    public function updatePageRule(string $siteId, string $id, array $body): array {
        if (array_key_exists('pattern', $body) && (!is_string($body['pattern']) || !str_starts_with((string) $body['pattern'], '/'))) {
            return ['error' => 'invalid_field', 'field' => 'pattern', 'detail' => 'must_start_with_slash', 'status' => 422];
        }
        if (array_key_exists('priority', $body)) {
            $priority = Validator::intRange($body, 'priority', 1, 100000);
            if (($priority['ok'] ?? false) !== true) { return $priority; }
        }
        if (array_key_exists('actions', $body) && !is_array($body['actions'])) {
            return ['error' => 'invalid_field', 'field' => 'actions', 'detail' => 'must_be_object', 'status' => 422];
        }
        $row = $this->service->updatePageRule($siteId, $id, $body);
        return $row ? ['data' => $row] : ['error' => 'page_rule_not_found', 'status' => 404];
    }
    public function deletePageRule(string $siteId, string $id): array { return $this->service->deletePageRule($siteId, $id) ? ['ok' => true] : ['error' => 'page_rule_not_found', 'status' => 404]; }
    public function testPageRule(string $siteId, array $body): array {
        $path = Validator::requiredString($body, 'path', 2048);
        if (($path['ok'] ?? false) !== true) { return $path; }
        if (!str_starts_with((string) $path['value'], '/')) {
            return ['error' => 'invalid_field', 'field' => 'path', 'detail' => 'must_start_with_slash', 'status' => 422];
        }
        return ['data' => $this->service->testPageRule($siteId, (string) $path['value'])];
    }
    public function listSslCertificates(string $siteId): array { return ['data' => $this->service->listSslCertificates($siteId)]; }
    public function checkSslCertificates(string $siteId, array $body): array {
        $hostnames = [];
        if (array_key_exists('hostnames', $body)) {
            if (!is_array($body['hostnames'])) {
                return ['error' => 'invalid_field', 'field' => 'hostnames', 'detail' => 'must_be_array', 'status' => 422];
            }
            foreach ($body['hostnames'] as $h) {
                if (!is_string($h) || trim($h) === '') {
                    return ['error' => 'invalid_field', 'field' => 'hostnames', 'detail' => 'must_be_non_empty_string_array', 'status' => 422];
                }
                $hostnames[] = strtolower(trim($h));
            }
        }
        return ['data' => $this->service->checkSslCertificates($siteId, $hostnames)];
    }
}
