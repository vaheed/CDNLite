<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Modules\Operations\Services\OperationsLogService;

class OperationsLogController
{
    public function __construct(private OperationsLogService $service)
    {
    }

    public function securityEvents(array $query): array
    {
        return ['data' => $this->service->securityEvents($query)];
    }

    public function securitySummary(array $query): array
    {
        return ['data' => $this->service->securitySummary($query)];
    }

    public function audit(array $query): array
    {
        return ['data' => $this->service->audit($query)];
    }
}
