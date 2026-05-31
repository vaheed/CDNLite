<?php

namespace App\Modules\Edge\Http\Controllers;

use App\Modules\Dns\Services\EdgeDnsService;
use App\Modules\Edge\Services\EdgeService;
use App\Support\Logger;

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

    public function register(array $input): array
    {
        if (empty($input['edge_id'])) {
            return ['error' => 'edge_id_required', 'status' => 422];
        }

        $result = ['data' => $this->service->register($input)];
        $this->syncEdgeDnsRecords();
        return $result;
    }

    public function heartbeat(array $input): array
    {
        $ok = $this->service->heartbeat($input);
        if (!$ok) {
            return ['error' => 'edge_not_found', 'status' => 404];
        }

        $this->syncEdgeDnsRecords();
        return ['ok' => true];
    }

    private function syncEdgeDnsRecords(): void
    {
        try {
            $this->edgeDns->sync();
        } catch (\RuntimeException $e) {
            Logger::error('edge_dns_sync_failed', ['error' => $e->getMessage()]);
        }
    }
}
