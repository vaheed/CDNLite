<?php

namespace App\Modules\Dns\Http\Controllers;

use App\Modules\Dns\Services\DnsService;
use App\Modules\Dns\Services\GeoRoutingService;
use App\Support\Logger;
use App\Support\Validator;

class DnsController
{
    public function __construct(private DnsService $service, private GeoRoutingService $geo = new GeoRoutingService())
    {
    }

    public function list(string $domainId): array
    {
        return ['data' => $this->service->listByDomain($domainId)];
    }

    public function routing(string $domainId): array
    {
        $settings = $this->service->routing($domainId);
        return $settings === null
            ? ['error' => 'domain_not_found', 'status' => 404]
            : ['data' => $settings];
    }

    public function updateRouting(string $domainId, array $input): array
    {
        $mode = Validator::enum($input, 'routing_mode', ['geo', 'anycast', 'dns_only']);
        if (($mode['ok'] ?? false) !== true) {
            return $mode;
        }
        $input['routing_mode'] = $mode['value'];
        if (array_key_exists('geo_health_port', $input)) {
            $port = Validator::intRange($input, 'geo_health_port', 1, 65535);
            if (($port['ok'] ?? false) !== true) {
                return $port;
            }
            $input['geo_health_port'] = $port['value'];
        }
        foreach (['anycast_ipv4' => FILTER_FLAG_IPV4, 'anycast_ipv6' => FILTER_FLAG_IPV6] as $field => $flag) {
            if (isset($input[$field]) && trim((string) $input[$field]) !== ''
                && filter_var($input[$field], FILTER_VALIDATE_IP, $flag) === false) {
                return ['error' => 'invalid_' . $field, 'status' => 422];
            }
        }
        try {
            $settings = $this->service->updateRouting($domainId, $input);
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage(), 'status' => 422];
        }
        return $settings === null
            ? ['error' => 'domain_not_found', 'status' => 404]
            : ['data' => $settings];
    }

    public function previewRouting(string $domainId, string $recordId, array $input): array
    {
        try {
            $preview = $this->service->preview($domainId, $recordId, $input);
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage(), 'status' => 422];
        }
        return $preview === null
            ? ['error' => 'record_not_found', 'status' => 404]
            : ['data' => $preview];
    }

    public function create(string $domainId, array $input): array
    {
        $policy = $this->validateRoutingPolicy($input);
        if ($policy !== null) {
            return $policy;
        }
        $origin = $this->validateOriginOptions($input);
        if ($origin !== null) {
            return $origin;
        }
        $type = Validator::requiredString($input, 'type', 16);
        if (($type['ok'] ?? false) !== true) {
            return $type;
        }
        $name = Validator::requiredString($input, 'name', 255);
        if (($name['ok'] ?? false) !== true) {
            return $name;
        }
        $content = Validator::requiredString($input, 'content', 2048);
        if (($content['ok'] ?? false) !== true) {
            return $content;
        }
        $ttl = Validator::intRange($input, 'ttl', 60, 86400, 300);
        if (($ttl['ok'] ?? false) !== true) {
            return $ttl;
        }

        $input['type'] = strtoupper((string) $type['value']);
        $input['name'] = $name['value'];
        $contentByType = $this->validateRecordContent(
            $input['type'],
            (string) $content['value'],
            (bool) ($input['proxied'] ?? false)
        );
        if (($contentByType['ok'] ?? false) !== true) {
            return $contentByType;
        }
        $input['content'] = $contentByType['value'];
        $input['ttl'] = $ttl['value'];
        $apex = $this->validateApexType($input['name'], $input['type'], (bool) ($input['proxied'] ?? false));
        if ($apex !== null) {
            return $apex;
        }

        try {
            return ['data' => $this->service->create($domainId, $input)];
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            if ($message === 'domain_not_found') {
                return ['error' => 'domain_not_found', 'status' => 404];
            }
            if (in_array($message, ['anycast_requires_proxied_record', 'global_anycast_not_configured'], true)) {
                return ['error' => $message, 'status' => 422];
            }
            $payload = ['error' => $message, 'status' => 502];
            if (Logger::isDebug()) {
                $payload['detail'] = $e->getMessage();
            }
            return $payload;
        }
    }

    public function update(string $domainId, string $recordId, array $input): array
    {
        $policy = $this->validateRoutingPolicy($input);
        if ($policy !== null) {
            return $policy;
        }
        $origin = $this->validateOriginOptions($input);
        if ($origin !== null) {
            return $origin;
        }
        if ($input === []) {
            return ['error' => 'dns_record_update_body_required', 'status' => 422];
        }

        if (array_key_exists('type', $input)) {
            $type = Validator::requiredString($input, 'type', 16);
            if (($type['ok'] ?? false) !== true) {
                return $type;
            }
            $input['type'] = strtoupper((string) $type['value']);
        }
        if (array_key_exists('name', $input)) {
            $name = Validator::requiredString($input, 'name', 255);
            if (($name['ok'] ?? false) !== true) {
                return $name;
            }
            $input['name'] = $name['value'];
        }
        if (array_key_exists('content', $input)) {
            $content = Validator::requiredString($input, 'content', 2048);
            if (($content['ok'] ?? false) !== true) {
                return $content;
            }
            $input['content'] = $content['value'];
        }
        if (array_key_exists('type', $input) && array_key_exists('content', $input)) {
            $typeValue = (string) $input['type'];
            $contentValue = (string) $input['content'];
            $contentByType = $this->validateRecordContent(
                $typeValue,
                $contentValue,
                (bool) ($input['proxied'] ?? false)
            );
            if (($contentByType['ok'] ?? false) !== true) {
                return $contentByType;
            }
            $input['content'] = $contentByType['value'];
        }
        if (array_key_exists('ttl', $input)) {
            $ttl = Validator::intRange($input, 'ttl', 60, 86400);
            if (($ttl['ok'] ?? false) !== true) {
                return $ttl;
            }
        }
        $current = $this->service->find($domainId, $recordId);
        if ($current !== null) {
            $apex = $this->validateApexType(
                (string) ($input['name'] ?? $current['name']),
                (string) ($input['type'] ?? $current['type']),
                (bool) ($input['proxied'] ?? $current['proxied'])
            );
            if ($apex !== null) {
                return $apex;
            }
        }

        try {
            $record = $this->service->update($domainId, $recordId, $input);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $status = in_array($message, ['anycast_requires_proxied_record', 'global_anycast_not_configured'], true) ? 422 : 502;
            $payload = ['error' => $message, 'status' => $status];
            if (Logger::isDebug()) {
                $payload['detail'] = $e->getMessage();
            }
            return $payload;
        }

        return $record === null
            ? ['error' => 'record_not_found', 'status' => 404]
            : ['data' => $record];
    }

    public function delete(string $domainId, string $recordId): array
    {
        return $this->service->delete($domainId, $recordId)
            ? ['ok' => true]
            : ['error' => 'record_not_found', 'status' => 404];
    }

    public function geoRoutes(string $domainId, string $recordId): array
    {
        $routes = $this->geo->list($domainId, $recordId);
        return $routes === null
            ? ['error' => 'record_not_found', 'status' => 404]
            : ['data' => $routes, 'geodns_sync_active' => false];
    }

    public function updateGeoRoutes(string $domainId, string $recordId, array $input): array
    {
        if (!isset($input['routes']) || !is_array($input['routes'])) {
            return ['error' => 'geo_routes_array_required', 'status' => 422];
        }
        try {
            $routes = $this->geo->replace($domainId, $recordId, $input['routes']);
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage(), 'status' => 422];
        }
        return $routes === null
            ? ['error' => 'record_not_found', 'status' => 404]
            : ['data' => $routes, 'geodns_sync_active' => false];
    }

    private function validateOriginOptions(array &$input): ?array
    {
        if (array_key_exists('origin_port', $input)) {
            return ['error' => 'origin_port_not_supported', 'field' => 'origin_port', 'status' => 422];
        }
        if (array_key_exists('origin_host', $input)) {
            $host = Validator::optionalString($input, 'origin_host', 255);
            if (($host['ok'] ?? false) !== true) {
                return $host;
            }
            $input['origin_host'] = $host['value'];
        }
        if (array_key_exists('origin_tls_verify', $input)) {
            $mode = Validator::enum($input, 'origin_tls_verify', ['verify', 'ignore']);
            if (($mode['ok'] ?? false) !== true) {
                return $mode;
            }
            $input['origin_tls_verify'] = $mode['value'];
        }
        if (array_key_exists('geo_origins', $input) && !is_array($input['geo_origins'])) {
            return ['error' => 'geo_origins_must_be_object', 'field' => 'geo_origins', 'status' => 422];
        }
        return null;
    }

    private function validateRoutingPolicy(array &$input): ?array
    {
        if (!array_key_exists('routing_policy', $input)) {
            return null;
        }
        $policy = Validator::enum($input, 'routing_policy', ['standard', 'geo', 'anycast', 'geo_anycast']);
        if (($policy['ok'] ?? false) !== true) {
            return $policy;
        }
        $input['routing_policy'] = $policy['value'];
        if (in_array($policy['value'], ['anycast', 'geo_anycast'], true)
            && array_key_exists('proxied', $input) && !(bool) $input['proxied']) {
            return ['error' => 'anycast_requires_proxied_record', 'field' => 'routing_policy', 'status' => 422];
        }
        return null;
    }

    private function validateApexType(string $name, string $type, bool $proxied): ?array
    {
        $name = strtolower(rtrim(trim($name), '.'));
        if (($name === '' || $name === '@') && !$proxied && strtoupper($type) === 'CNAME') {
            return ['error' => 'apex_cname_not_allowed', 'field' => 'type', 'status' => 422];
        }
        return null;
    }

    private function validateRecordContent(string $type, string $content, bool $proxied): array
    {
        if ($proxied && in_array(strtoupper(trim($type)), ['A', 'AAAA'], true)) {
            return Validator::originHost($content, 'content');
        }
        return Validator::dnsRecordContent($type, $content);
    }
}
