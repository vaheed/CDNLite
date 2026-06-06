<?php

namespace App\Modules\Dns\Http\Controllers;

use App\Modules\Dns\Services\DnsService;
use App\Support\Logger;
use App\Support\Validator;

class DnsController
{
    public function __construct(private DnsService $service)
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
        $contentByType = Validator::dnsRecordContent($input['type'], (string) $content['value']);
        if (($contentByType['ok'] ?? false) !== true) {
            return $contentByType;
        }
        $input['content'] = $contentByType['value'];
        $input['ttl'] = $ttl['value'];

        try {
            return ['data' => $this->service->create($domainId, $input)];
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            if ($message === 'domain_not_found') {
                return ['error' => 'domain_not_found', 'status' => 404];
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
            $contentByType = Validator::dnsRecordContent($typeValue, $contentValue);
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

        try {
            $record = $this->service->update($domainId, $recordId, $input);
        } catch (\RuntimeException $e) {
            $payload = ['error' => $e->getMessage(), 'status' => 502];
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
}
