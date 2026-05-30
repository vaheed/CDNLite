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

        return ['ingested' => $this->service->ingest($items)];
    }

    public function summary(?int $siteId): array
    {
        return ['data' => $this->service->summary($siteId)];
    }
}
