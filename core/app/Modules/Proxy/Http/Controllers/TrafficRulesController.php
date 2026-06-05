<?php

namespace App\Modules\Proxy\Http\Controllers;

use App\Modules\Proxy\Services\TrafficRulesService;
use App\Modules\Proxy\Services\AcmeIssuerService;
use App\Support\Secrets;
use App\Support\Validator;

class TrafficRulesController
{
    public function __construct(private TrafficRulesService $service)
    {
    }

    public function createRedirect(string $domainId, array $body): array {
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
        return ['data' => $this->service->createRedirect($domainId, $body)];
    }
    public function listRedirects(string $domainId): array { return ['data' => $this->service->listRedirects($domainId)]; }
    public function updateRedirect(string $domainId, string $id, array $body): array {
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
        $r=$this->service->updateRedirect($domainId,$id,$body); return $r?['data'=>$r]:['error'=>'redirect_not_found','status'=>404];
    }
    public function deleteRedirect(string $domainId, string $id): array { return $this->service->deleteRedirect($domainId,$id)?['ok'=>true]:['error'=>'redirect_not_found','status'=>404]; }
    public function importRedirects(string $domainId, array $body): array {
        if (!isset($body['items']) || !is_array($body['items'])) {
            return ['error' => 'invalid_field', 'field' => 'items', 'detail' => 'must_be_array', 'status' => 422];
        }
        return ['data' => $this->service->importRedirects($domainId, $body['items'])];
    }
    public function exportRedirects(string $domainId): array { return ['data' => $this->service->exportRedirects($domainId)]; }
    public function testRedirect(string $domainId, array $body): array {
        $path = Validator::requiredString($body, 'path', 2048);
        if (($path['ok'] ?? false) !== true) { return $path; }
        if (!str_starts_with((string) $path['value'], '/')) {
            return ['error' => 'invalid_field', 'field' => 'path', 'detail' => 'must_start_with_slash', 'status' => 422];
        }
        $query = Validator::optionalString($body, 'query', 4096);
        if (($query['ok'] ?? false) !== true) { return $query; }
        $result = $this->service->testRedirect($domainId, (string) $path['value'], (string) ($query['value'] ?? ''));
        return ['data' => $result ?? ['matched' => false]];
    }

    public function setRateLimit(string $domainId, array $body): array {
        $rpm = Validator::intRange($body, 'requests_per_minute', 1, 100000, 60);
        if (($rpm['ok'] ?? false) !== true) { return $rpm; }
        if (array_key_exists('priority', $body)) {
            $priority = Validator::intRange($body, 'priority', 1, 100000);
            if (($priority['ok'] ?? false) !== true) { return $priority; }
        }
        if (array_key_exists('path_prefix', $body)) {
            $path = Validator::requiredString($body, 'path_prefix', 2048);
            if (($path['ok'] ?? false) !== true) { return $path; }
            if (!str_starts_with((string) $path['value'], '/')) {
                return ['error' => 'invalid_field', 'field' => 'path_prefix', 'detail' => 'must_start_with_slash', 'status' => 422];
            }
        }
        if (array_key_exists('key_type', $body)) {
            $keyType = Validator::enum($body, 'key_type', ['ip', 'ip_path']);
            if (($keyType['ok'] ?? false) !== true) { return $keyType; }
        }
        if (array_key_exists('action', $body)) {
            $action = Validator::enum($body, 'action', ['block']);
            if (($action['ok'] ?? false) !== true) { return $action; }
        }
        return ['data' => $this->service->setRateLimit($domainId, $body)];
    }
    public function getRateLimit(string $domainId): array { $r=$this->service->getRateLimit($domainId); return $r?['data'=>$r]:['error'=>'rate_limit_not_found','status'=>404]; }
    public function disableRateLimit(string $domainId): array { return $this->service->disableRateLimit($domainId)?['ok'=>true]:['error'=>'rate_limit_not_found','status'=>404]; }

    public function createWaf(string $domainId, array $body): array {
        $type = Validator::requiredString($body, 'type', 64);
        if (($type['ok'] ?? false) !== true) { return $type; }
        if (!in_array((string) $type['value'], ['path_contains', 'path_prefix', 'user_agent_contains', 'ip_cidr', 'country_is', 'method_is', 'header_contains'], true)) {
            return ['error' => 'invalid_field', 'field' => 'type', 'detail' => 'must_be_one_of_path_contains_path_prefix_user_agent_contains_ip_cidr_country_is_method_is_header_contains', 'status' => 422];
        }
        $pattern = Validator::requiredString($body, 'pattern', 2048);
        if (($pattern['ok'] ?? false) !== true) { return $pattern; }
        if (array_key_exists('priority', $body)) {
            $priority = Validator::intRange($body, 'priority', 1, 100000);
            if (($priority['ok'] ?? false) !== true) { return $priority; }
        }
        if (array_key_exists('action', $body)) {
            $action = Validator::enum($body, 'action', ['block', 'log', 'allow']);
            if (($action['ok'] ?? false) !== true) { return $action; }
        }
        if (array_key_exists('name', $body)) {
            $name = Validator::optionalString($body, 'name', 255);
            if (($name['ok'] ?? false) !== true) { return $name; }
        }
        if (array_key_exists('description', $body)) {
            $description = Validator::optionalString($body, 'description', 2048);
            if (($description['ok'] ?? false) !== true) { return $description; }
        }
        return ['data' => $this->service->createWaf($domainId, $body)];
    }
    public function listWaf(string $domainId): array { return ['data' => $this->service->listWaf($domainId)]; }
    public function deleteWaf(string $domainId, string $id): array { return $this->service->deleteWaf($domainId,$id)?['ok'=>true]:['error'=>'waf_not_found','status'=>404]; }

    public function createCacheRule(string $domainId, array $body): array {
        if (array_key_exists('path_prefix', $body)) {
            $path = Validator::requiredString($body, 'path_prefix', 2048);
            if (($path['ok'] ?? false) !== true) { return $path; }
            if (!str_starts_with((string) $path['value'], '/')) {
                return ['error' => 'invalid_field', 'field' => 'path_prefix', 'detail' => 'must_start_with_slash', 'status' => 422];
            }
        }
        $ttl = Validator::intRange($body, 'ttl_seconds', 1, 31536000, 60);
        if (($ttl['ok'] ?? false) !== true) { return $ttl; }
        return ['data' => $this->service->createCacheRule($domainId, $body)];
    }
    public function listCacheRules(string $domainId): array { return ['data' => $this->service->listCacheRules($domainId)]; }
    public function updateWaf(string $domainId, string $id, array $body): array {
        if (array_key_exists('type', $body) && !in_array((string) $body['type'], ['path_contains', 'path_prefix', 'user_agent_contains', 'ip_cidr', 'country_is', 'method_is', 'header_contains'], true)) {
            return ['error' => 'invalid_field', 'field' => 'type', 'detail' => 'must_be_one_of_path_contains_path_prefix_user_agent_contains_ip_cidr_country_is_method_is_header_contains', 'status' => 422];
        }
        if (array_key_exists('pattern', $body) && (!is_string($body['pattern']) || trim((string) $body['pattern']) === '')) {
            return ['error' => 'invalid_field', 'field' => 'pattern', 'detail' => 'must_be_non_empty_string', 'status' => 422];
        }
        if (array_key_exists('action', $body) && !in_array((string) $body['action'], ['block', 'log', 'allow'], true)) {
            return ['error' => 'invalid_field', 'field' => 'action', 'detail' => 'must_be_one_of_block_log_allow', 'status' => 422];
        }
        if (array_key_exists('priority', $body)) {
            $priority = Validator::intRange($body, 'priority', 1, 100000);
            if (($priority['ok'] ?? false) !== true) { return $priority; }
        }
        $r=$this->service->updateWaf($domainId,$id,$body); return $r?['data'=>$r]:['error'=>'waf_not_found','status'=>404];
    }

    public function updateCacheRule(string $domainId, string $id, array $body): array {
        if (array_key_exists('path_prefix', $body) && (!is_string($body['path_prefix']) || !str_starts_with((string) $body['path_prefix'], '/'))) {
            return ['error' => 'invalid_field', 'field' => 'path_prefix', 'detail' => 'must_start_with_slash', 'status' => 422];
        }
        if (array_key_exists('ttl_seconds', $body)) {
            $ttl = Validator::intRange($body, 'ttl_seconds', 1, 31536000);
            if (($ttl['ok'] ?? false) !== true) { return $ttl; }
        }
        $r=$this->service->updateCacheRule($domainId,$id,$body); return $r?['data'=>$r]:['error'=>'cache_rule_not_found','status'=>404];
    }
    public function deleteCacheRule(string $domainId, string $id): array { return $this->service->deleteCacheRule($domainId,$id)?['ok'=>true]:['error'=>'cache_rule_not_found','status'=>404]; }
    public function getDomainCacheSettings(string $domainId): array { return ['data' => $this->service->getDomainCacheSettings($domainId)]; }
    public function setDomainCacheSettings(string $domainId, array $body): array {
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
        return ['data' => $this->service->setDomainCacheSettings($domainId, $body)];
    }
    public function createCachePurgeRequest(string $domainId, array $body): array {
        $type = Validator::enum($body, 'type', ['url', 'prefix', 'domain', 'everything']);
        if (($type['ok'] ?? false) !== true || ($type['exists'] ?? false) !== true) {
            return ['error' => 'invalid_field', 'field' => 'type', 'detail' => 'must_be_one_of_url_prefix_domain_everything', 'status' => 422];
        }
        $value = Validator::optionalString($body, 'value', 4096);
        if (($value['ok'] ?? false) !== true) { return $value; }
        if (in_array((string) $type['value'], ['url', 'prefix'], true) && (($value['exists'] ?? false) !== true || (string) $value['value'] === '')) {
            return ['error' => 'invalid_field', 'field' => 'value', 'detail' => 'required_for_url_or_prefix', 'status' => 422];
        }
        return ['data' => $this->service->createCachePurgeRequest($domainId, $body)];
    }
    public function listCachePurgeRequests(string $domainId): array { return ['data' => $this->service->listCachePurgeRequests($domainId)]; }
    public function getCachePurgeRequest(string $domainId, string $id): array {
        $row = $this->service->getCachePurgeRequest($domainId, $id);
        return $row ? ['data' => $row] : ['error' => 'cache_purge_request_not_found', 'status' => 404];
    }
    public function createPageRule(string $domainId, array $body): array {
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
        return ['data' => $this->service->createPageRule($domainId, $body)];
    }
    public function listPageRules(string $domainId): array { return ['data' => $this->service->listPageRules($domainId)]; }
    public function updatePageRule(string $domainId, string $id, array $body): array {
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
        $row = $this->service->updatePageRule($domainId, $id, $body);
        return $row ? ['data' => $row] : ['error' => 'page_rule_not_found', 'status' => 404];
    }
    public function deletePageRule(string $domainId, string $id): array { return $this->service->deletePageRule($domainId, $id) ? ['ok' => true] : ['error' => 'page_rule_not_found', 'status' => 404]; }
    public function testPageRule(string $domainId, array $body): array {
        $path = Validator::requiredString($body, 'path', 2048);
        if (($path['ok'] ?? false) !== true) { return $path; }
        if (!str_starts_with((string) $path['value'], '/')) {
            return ['error' => 'invalid_field', 'field' => 'path', 'detail' => 'must_start_with_slash', 'status' => 422];
        }
        return ['data' => $this->service->testPageRule($domainId, (string) $path['value'])];
    }
    public function listSslCertificates(string $domainId): array { return ['data' => $this->service->listSslCertificates($domainId)]; }
    public function requestSslCertificate(string $domainId, array $body): array {
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
        try {
            return ['data' => $this->service->requestSslCertificate($domainId, $hostnames)];
        } catch (\OutOfBoundsException) {
            return ['error' => 'domain_not_found', 'status' => 404];
        } catch (\DomainException $e) {
            return ['error' => 'proxy_required', 'detail' => $e->getMessage(), 'status' => 422];
        }
    }
    public function checkSslCertificates(string $domainId, array $body): array {
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
        return ['data' => $this->service->checkSslCertificates($domainId, $hostnames)];
    }
    public function issueAcmeCertificate(string $domainId, array $body): array {
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
        try {
            return ['data' => (new AcmeIssuerService($this->service))->issue($domainId, $hostnames)];
        } catch (\OutOfBoundsException) {
            return ['error' => 'domain_not_found', 'status' => 404];
        } catch (\DomainException $e) {
            return ['error' => 'proxy_required', 'detail' => $e->getMessage(), 'status' => 422];
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_field', 'field' => 'hostnames', 'detail' => $e->getMessage(), 'status' => 422];
        } catch (\RuntimeException $e) {
            return ['error' => 'acme_issue_failed', 'detail' => $e->getMessage(), 'status' => 502];
        }
    }
    public function listSecurityEvents(string $domainId, array $query): array {
        $type = null;
        if (array_key_exists('type', $query) && is_string($query['type']) && trim((string) $query['type']) !== '') {
            $type = trim((string) $query['type']);
        }
        $limit = 100;
        if (array_key_exists('limit', $query) && is_string($query['limit']) && ctype_digit($query['limit'])) {
            $limit = (int) $query['limit'];
        }
        return ['data' => $this->service->listSecurityEvents($domainId, $type, $limit)];
    }
    public function importManualSslCertificate(string $domainId, array $body): array {
        if (!Secrets::isConfigured()) {
            return ['error' => 'invalid_field', 'field' => 'CDNLITE_SSL_SECRET_KEY', 'detail' => 'missing_required_env', 'status' => 422];
        }
        $hostname = Validator::requiredString($body, 'hostname', 255);
        if (($hostname['ok'] ?? false) !== true) { return $hostname; }
        $cert = Validator::requiredString($body, 'certificate_pem', 65535);
        if (($cert['ok'] ?? false) !== true) { return $cert; }
        $key = Validator::requiredString($body, 'private_key_pem', 65535);
        if (($key['ok'] ?? false) !== true) { return $key; }
        try {
            return ['data' => $this->service->importManualSslCertificate(
                $domainId,
                strtolower((string) $hostname['value']),
                (string) $cert['value'],
                (string) $key['value']
            )];
        } catch (\InvalidArgumentException $e) {
            return ['error' => 'invalid_field', 'field' => 'certificate', 'detail' => $e->getMessage(), 'status' => 422];
        } catch (\RuntimeException $e) {
            return ['error' => 'invalid_field', 'field' => 'CDNLITE_SSL_SECRET_KEY', 'detail' => $e->getMessage(), 'status' => 422];
        }
    }
}
