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
        $limit = $this->telemetryMaxItems();
        $items = $input['items'] ?? null;
        if (!is_array($items)) {
            return ['error' => 'items_must_be_array', 'status' => 422];
        }
        if (count($items) > $limit) {
            return ['error' => 'telemetry_batch_too_large', 'max_items' => $limit, 'status' => 413];
        }
        $payloadBytes = strlen(json_encode($input, JSON_UNESCAPED_SLASHES) ?: '');
        $maxPayloadBytes = $this->telemetryMaxPayloadBytes();
        if ($payloadBytes > $maxPayloadBytes) {
            return ['error' => 'telemetry_payload_too_large', 'max_payload_bytes' => $maxPayloadBytes, 'status' => 413];
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
        $limit = $this->telemetryMaxItems();
        $items = $input['items'] ?? null;
        if (!is_array($items)) {
            return ['error' => 'items_must_be_array', 'status' => 422];
        }
        if (count($items) > $limit) {
            return ['error' => 'telemetry_batch_too_large', 'max_items' => $limit, 'status' => 413];
        }
        $payloadBytes = strlen(json_encode($input, JSON_UNESCAPED_SLASHES) ?: '');
        $maxPayloadBytes = $this->telemetryMaxPayloadBytes();
        if ($payloadBytes > $maxPayloadBytes) {
            return ['error' => 'telemetry_payload_too_large', 'max_payload_bytes' => $maxPayloadBytes, 'status' => 413];
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
        $bucket = null;
        if (isset($input['domain_id'])) {
            if (!is_string($input['domain_id']) || trim($input['domain_id']) === '') {
                return ['error' => 'domain_id_must_be_non_empty_string', 'status' => 422];
            }
            $domainId = trim($input['domain_id']);
        }
        if (isset($input['bucket'])) {
            if (!is_string($input['bucket']) || !isset($this->allowedBuckets[$input['bucket']])) {
                return ['error' => 'bucket_must_be_one_of_minute_hour_day', 'status' => 422];
            }
            $bucket = $input['bucket'];
        }

        return $this->service->rebuildAggregates($domainId, $bucket);
    }

    public function rollupJob(string $jobId): array
    {
        $jobId = trim($jobId);
        if ($jobId === '') {
            return ['error' => 'job_id_must_be_non_empty_string', 'status' => 422];
        }
        $job = $this->service->rollupJob($jobId);
        if ($job === null) {
            return ['error' => 'job_not_found', 'status' => 404];
        }
        return ['data' => $job];
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
        $offset = 0;
        if (isset($query['offset']) && is_string($query['offset']) && ctype_digit($query['offset'])) {
            $offset = (int) $query['offset'];
        }
        return ['data' => $this->service->recentRequests($domainId, $limit, $offset, $query)];
    }

    public function activityTimeline(string $domainId, array $query): array
    {
        return ['data' => $this->service->activityTimeline($domainId, $query)];
    }

    public function activitySummary(string $domainId, array $query): array
    {
        return ['data' => $this->service->activitySummary($domainId, $query)];
    }

    public function originHealth(string $domainId): array
    {
        return ['data' => $this->service->originHealth($domainId)];
    }

    public function findRequest(string $domainId, string $requestId): array
    {
        $requestId = trim($requestId);
        if ($requestId === '') {
            return ['error' => 'request_id_must_be_non_empty', 'status' => 422];
        }
        $request = $this->service->findRequest($domainId, $requestId);
        if ($request === null) {
            return ['error' => 'request_not_found', 'status' => 404];
        }
        return ['data' => $request];
    }

    public function activityExport(string $domainId, array $query): array
    {
        return ['data' => $this->service->activityExport($domainId, $query)];
    }

    private function telemetryMaxItems(): int
    {
        $raw = getenv('TELEMETRY_MAX_BATCH_ITEMS') ?: getenv('CDNLITE_TELEMETRY_MAX_BATCH_ITEMS') ?: '1000';
        return max(1, min(100000, is_numeric($raw) ? (int) $raw : 1000));
    }

    private function telemetryMaxPayloadBytes(): int
    {
        $raw = getenv('TELEMETRY_MAX_PAYLOAD_BYTES') ?: getenv('CDNLITE_TELEMETRY_MAX_PAYLOAD_BYTES') ?: '1048576';
        return max(1024, min(10485760, is_numeric($raw) ? (int) $raw : 1048576));
    }
}
