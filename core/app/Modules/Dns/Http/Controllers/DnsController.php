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

    public function create(string $domainId, array $input): array
    {
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
}
