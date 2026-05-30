<?php

namespace App\Modules\Dns\Http\Controllers;

use App\Modules\Dns\Services\DnsService;

class DnsController
{
    public function __construct(private DnsService $service)
    {
    }

    public function list(int $siteId): array
    {
        return ['data' => $this->service->listBySite($siteId)];
    }

    public function create(int $siteId, array $input): array
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
            return ['error' => $message, 'status' => 502];
        }
    }

    public function delete(int $siteId, int $recordId): array
    {
        return $this->service->delete($siteId, $recordId)
            ? ['ok' => true]
            : ['error' => 'record_not_found', 'status' => 404];
    }
}
