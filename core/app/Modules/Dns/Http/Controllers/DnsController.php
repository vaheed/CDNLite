<?php

namespace App\Modules\Dns\Http\Controllers;

use App\Modules\Dns\Services\DnsService;
use App\Support\Logger;

class DnsController
{
    public function __construct(private DnsService $service)
    {
    }

    public function list(string $siteId): array
    {
        return ['data' => $this->service->listBySite($siteId)];
    }

    public function create(string $siteId, array $input): array
    {
        $required = ['type', 'name', 'content'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                return ['error' => $field . '_required', 'status' => 422];
            }
        }

        try {
            return ['data' => $this->service->create($siteId, $input)];
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            if ($message === 'site_not_found') {
                return ['error' => 'site_not_found', 'status' => 404];
            }
            $payload = ['error' => $message, 'status' => 502];
            if (Logger::isDebug()) {
                $payload['detail'] = $e->getMessage();
            }
            return $payload;
        }
    }

    public function update(string $siteId, string $recordId, array $input): array
    {
        if ($input === []) {
            return ['error' => 'dns_record_update_body_required', 'status' => 422];
        }

        try {
            $record = $this->service->update($siteId, $recordId, $input);
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

    public function delete(string $siteId, string $recordId): array
    {
        return $this->service->delete($siteId, $recordId)
            ? ['ok' => true]
            : ['error' => 'record_not_found', 'status' => 404];
    }
}
