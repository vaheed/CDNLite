<?php

namespace App\Modules\Onboarding\Http\Controllers;

use App\Modules\Onboarding\Services\OnboardingService;

class OnboardingController
{
    public function __construct(private OnboardingService $service) {}

    public function show(string $domainId): array
    {
        $result = $this->service->show($domainId);
        return $result === null ? ['error' => 'domain_not_found', 'status' => 404] : ['data' => $result];
    }

    public function answers(string $domainId, array $body): array
    {
        $result = $this->service->saveAnswers($domainId, (array) ($body['answers'] ?? $body));
        return $result === null ? ['error' => 'domain_not_found', 'status' => 404] : ['data' => $result];
    }

    public function preview(string $domainId): array
    {
        $result = $this->service->preview($domainId);
        return $result === null ? ['error' => 'domain_not_found', 'status' => 404] : ['data' => $result];
    }

    public function apply(string $domainId, array $body): array
    {
        $result = $this->service->apply($domainId, $body);
        return $result === null ? ['error' => 'domain_not_found', 'status' => 404] : ['data' => $result];
    }

    public function skip(string $domainId): array
    {
        $result = $this->service->skip($domainId);
        return $result === null ? ['error' => 'domain_not_found', 'status' => 404] : ['data' => $result];
    }

    public function resume(string $domainId): array
    {
        $result = $this->service->resume($domainId);
        return $result === null ? ['error' => 'domain_not_found', 'status' => 404] : ['data' => $result];
    }
}
