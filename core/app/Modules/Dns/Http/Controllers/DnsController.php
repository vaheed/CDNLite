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

        return ['data' => $this->service->create($siteId, $input)];
    }

    public function delete(int $siteId, int $recordId): array
    {
        return $this->service->delete($siteId, $recordId)
            ? ['ok' => true]
            : ['error' => 'record_not_found', 'status' => 404];
    }
}
