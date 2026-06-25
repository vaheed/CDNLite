<?php

namespace App\Modules\Proxy\Http\Controllers;

use App\Modules\Proxy\Services\OriginHealthService;
use App\Support\Validator;

class OriginController
{
    public function __construct(private OriginHealthService $service)
    {
    }

    public function list(string $domainId): array
    {
        return ['data' => $this->service->list($domainId)];
    }

    public function create(string $domainId, array $body): array
    {
        $error = $this->validate($body, false);
        if ($error !== null) {
            return $error;
        }
        return ['data' => $this->service->create($domainId, $body)];
    }

    public function update(string $domainId, string $originId, array $body): array
    {
        $error = $this->validate($body, true);
        if ($error !== null) {
            return $error;
        }
        $origin = $this->service->update($domainId, $originId, $body);
        return $origin === null ? ['error' => 'origin_not_found', 'status' => 404] : ['data' => $origin];
    }

    public function delete(string $domainId, string $originId): array
    {
        return $this->service->delete($domainId, $originId) ? ['ok' => true] : ['error' => 'origin_not_found', 'status' => 404];
    }

    public function check(string $domainId, string $originId): array
    {
        $origin = $this->service->check($domainId, $originId);
        return $origin === null ? ['error' => 'origin_not_found', 'status' => 404] : ['data' => $origin];
    }

    public function test(string $domainId, string $originId): array
    {
        $result = $this->service->test($domainId, $originId);
        return $result === null ? ['error' => 'origin_not_found', 'status' => 404] : ['data' => $result];
    }

    private function validate(array $body, bool $partial): ?array
    {
        if ($partial && $body === []) {
            return ['error' => 'invalid_request', 'detail' => 'at_least_one_field_required', 'status' => 422];
        }
        if (!$partial || array_key_exists('host', $body)) {
            $host = Validator::requiredString($body, 'host', 255);
            if (($host['ok'] ?? false) !== true) {
                return $host;
            }
        }
        if (array_key_exists('scheme', $body)) {
            $scheme = Validator::enum($body, 'scheme', ['http', 'https']);
            if (($scheme['ok'] ?? false) !== true) {
                return ['error' => 'invalid_field', 'field' => 'scheme', 'detail' => 'must_be_one_of_http_https', 'status' => 422];
            }
        }
        if (array_key_exists('tls_verify', $body)) {
            $tlsVerify = Validator::enum($body, 'tls_verify', ['verify', 'ignore']);
            if (($tlsVerify['ok'] ?? false) !== true) {
                return ['error' => 'invalid_field', 'field' => 'tls_verify', 'detail' => 'must_be_verify_or_ignore', 'status' => 422];
            }
        }
        if (array_key_exists('role', $body)) {
            $role = Validator::enum($body, 'role', ['origin']);
            if (($role['ok'] ?? false) !== true) {
                return ['error' => 'invalid_field', 'field' => 'role', 'detail' => 'must_be_origin', 'status' => 422];
            }
        }
        foreach (['host_header', 'sni'] as $field) {
            if (array_key_exists($field, $body) && trim((string) $body[$field]) !== '') {
                $value = Validator::requiredString($body, $field, 255);
                if (($value['ok'] ?? false) !== true) {
                    return $value;
                }
            }
        }
        if (array_key_exists('port', $body)) {
            $port = Validator::intRange($body, 'port', 80, 443);
            if (($port['ok'] ?? false) !== true || !in_array((int) $port['value'], [80, 443], true)) {
                return ['error' => 'invalid_field', 'field' => 'port', 'detail' => 'must_be_80_or_443', 'status' => 422];
            }
        }
        if (array_key_exists('weight', $body)) {
            $weight = Validator::intRange($body, 'weight', 1, 10000);
            if (($weight['ok'] ?? false) !== true) {
                return $weight;
            }
        }
        foreach (['is_primary', 'enabled', 'preserve_host', 'health_check_enabled'] as $field) {
            if (array_key_exists($field, $body)) {
                $bool = Validator::bool($body, $field);
                if (($bool['ok'] ?? false) !== true) {
                    return $bool;
                }
            }
        }
        if (array_key_exists('health_check_path', $body)) {
            $path = Validator::requiredString($body, 'health_check_path', 2048);
            if (($path['ok'] ?? false) !== true) {
                return $path;
            }
            if (!str_starts_with((string) $path['value'], '/')) {
                return ['error' => 'invalid_field', 'field' => 'health_check_path', 'detail' => 'must_start_with_slash', 'status' => 422];
            }
        }
        foreach (['health_check_interval_seconds' => [5, 3600], 'health_check_timeout_seconds' => [1, 60]] as $field => $range) {
            if (array_key_exists($field, $body)) {
                $value = Validator::intRange($body, $field, $range[0], $range[1]);
                if (($value['ok'] ?? false) !== true) {
                    return $value;
                }
            }
        }
        return null;
    }
}
