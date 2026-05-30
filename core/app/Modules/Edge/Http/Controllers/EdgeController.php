<?php

namespace App\Modules\Edge\Http\Controllers;

use App\Modules\Edge\Services\EdgeService;

class EdgeController
{
    public function __construct(private EdgeService $service)
    {
    }

    public function list(): array
    {
        return ['data' => $this->service->list()];
    }

    public function register(array $input): array
    {
        if (empty($input['edge_id'])) {
            return ['error' => 'edge_id_required', 'status' => 422];
        }

        return ['data' => $this->service->register($input)];
    }

    public function heartbeat(array $input): array
    {
        $ok = $this->service->heartbeat($input);
        return $ok ? ['ok' => true] : ['error' => 'edge_not_found', 'status' => 404];
    }
}
