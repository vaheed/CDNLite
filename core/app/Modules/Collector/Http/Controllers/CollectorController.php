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

    public function ingestSecurityEvents(array $input): array
    {
        $items = $input['items'] ?? null;
        if (!is_array($items)) {
            return ['error' => 'items_must_be_array', 'status' => 422];
        }
        $idempotencyKey = null;
        if (isset($input['idempotency_key']) && is_string($input['idempotency_key']) && trim($input['idempotency_key']) !== '') {
            $idempotencyKey = trim($input['idempotency_key']);
        }
        return $this->service->ingestSecurityEvents($items, $idempotencyKey);
    }

    public function summary(?string $domainId, ?string $bucket): array
    {
        if ($bucket !== null && !isset($this->allowedBuckets[$bucket])) {
            return ['error' => 'bucket_must_be_one_of_minute_hour_day', 'status' => 422];
        }
        return ['data' => $this->service->summary($domainId, $bucket)];
    }

    public function recalculate(array $input): array
    {
        $domainId = null;
        if (isset($input['domain_id'])) {
            if (!is_string($input['domain_id']) || trim($input['domain_id']) === '') {
                return ['error' => 'domain_id_must_be_non_empty_string', 'status' => 422];
            }
            $domainId = trim($input['domain_id']);
        }

        return $this->service->rebuildAggregates($domainId);
    }

    public function cacheAnalytics(?string $domainId = null): array
    {
        if ($domainId !== null && trim($domainId) === '') {
            return ['error' => 'domain_id_must_be_non_empty_string', 'status' => 422];
        }
        return ['data' => $this->service->cacheAnalytics($domainId ?? '')];
    }

    public function recentRequests(string $domainId, array $query): array
    {
        $limit = 100;
        if (isset($query['limit']) && is_string($query['limit']) && ctype_digit($query['limit'])) {
            $limit = (int) $query['limit'];
        }
        return ['data' => $this->service->recentRequests($domainId, $limit)];
    }
}
