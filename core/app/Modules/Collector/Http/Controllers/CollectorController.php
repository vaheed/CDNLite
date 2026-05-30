<?php

namespace App\Modules\Collector\Http\Controllers;

use App\Modules\Collector\Services\CollectorService;

class CollectorController
{
    public function __construct(private CollectorService $service)
    {
    }

    public function ingest(array $input): array
    {
        $items = $input['items'] ?? null;
        if (!is_array($items)) {
            return ['error' => 'items_must_be_array', 'status' => 422];
        }

        $idempotencyKey = null;
        if (isset($input['idempotency_key'])) {
            if (!is_string($input['idempotency_key']) || trim($input['idempotency_key']) === '') {
                return ['error' => 'idempotency_key_must_be_non_empty_string', 'status' => 422];
            }
            $idempotencyKey = trim($input['idempotency_key']);
        }

        return $this->service->ingest($items, $idempotencyKey);
    }

    public function summary(?int $siteId): array
    {
        return ['data' => $this->service->summary($siteId)];
    }
}
