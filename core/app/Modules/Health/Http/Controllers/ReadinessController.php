<?php

namespace App\Modules\Health\Http\Controllers;

use App\Modules\Health\Services\ReadinessService;

class ReadinessController
{
    public function __construct(private ReadinessService $service)
    {
    }

    public function index(): array
    {
        return $this->service->check();
    }
}
