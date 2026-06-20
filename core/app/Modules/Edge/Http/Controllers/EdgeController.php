<?php

namespace App\Modules\Edge\Http\Controllers;

use App\Modules\Dns\Services\EdgeDnsService;
use App\Modules\Edge\Services\EdgeService;

class EdgeController
{
    private EdgeDnsService $edgeDns;

    public function __construct(private EdgeService $service)
    {
        $this->edgeDns = new EdgeDnsService();
    }

    public function list(): array
    {
        return ['data' => $this->service->list()];
    }

    public function pools(): array
    {
        return ['data' => $this->service->pools()];
    }

    public function dns(): array
    {
        return ['data' => $this->edgeDns->status()];
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
        if (!$ok) {
            return ['error' => 'edge_not_found', 'status' => 404];
        }

        return ['ok' => true];
    }
}
