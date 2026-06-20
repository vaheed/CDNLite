<?php

namespace App\Modules\Recommendations\Http\Controllers;

use App\Modules\Recommendations\Services\RecommendationService;

class RecommendationController
{
    public function __construct(private RecommendationService $service)
    {
    }

    public function index(?string $domainId = null, array $query = []): array
    {
        $includeInactive = filter_var($query['include_inactive'] ?? false, FILTER_VALIDATE_BOOL);
        return ['data' => $this->service->list($domainId, $includeInactive)];
    }

    public function generate(?string $domainId = null): array
    {
        return ['data' => $this->service->generate($domainId)];
    }

    public function dismiss(string $domainId, string $id): array
    {
        $row = $this->service->dismiss($domainId, $id);
        return $row ? ['data' => $row] : ['error' => 'recommendation_not_found', 'status' => 404];
    }

    public function snooze(string $domainId, string $id, array $body): array
    {
        $row = $this->service->snooze($domainId, $id, (int) ($body['seconds'] ?? 86400));
        return $row ? ['data' => $row] : ['error' => 'recommendation_not_found', 'status' => 404];
    }

    public function apply(string $domainId, string $id): array
    {
        try {
            return $this->service->apply($domainId, $id);
        } catch (\InvalidArgumentException) {
            return ['error' => 'unsupported_recommendation_action', 'status' => 422];
        } catch (\DomainException $e) {
            return ['error' => $e->getMessage(), 'status' => 409];
        }
    }
}
