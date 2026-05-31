<?php

namespace App\Modules\Collector\Http\Controllers;

use App\Modules\Collector\Services\CollectorService;

class CollectorController
{
    /** @var array<string,bool> */
    private array $allowedBuckets = [
        'minute' => true,
        'hour' => true,
        'day' => true,
    ];

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

    public function summary(?string $siteId, ?string $bucket): array
    {
        if ($bucket !== null && !isset($this->allowedBuckets[$bucket])) {
            return ['error' => 'bucket_must_be_one_of_minute_hour_day', 'status' => 422];
        }
        return ['data' => $this->service->summary($siteId, $bucket)];
    }

    public function recalculate(array $input): array
    {
        $siteId = null;
        if (isset($input['site_id'])) {
            if (!is_string($input['site_id']) || trim($input['site_id']) === '') {
                return ['error' => 'site_id_must_be_non_empty_string', 'status' => 422];
            }
            $siteId = trim($input['site_id']);
        }

        return $this->service->rebuildAggregates($siteId);
    }
}
