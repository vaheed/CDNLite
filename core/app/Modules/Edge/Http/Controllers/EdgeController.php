<?php

namespace App\Modules\Edge\Http\Controllers;

use App\Modules\Dns\Services\DnsService;
use App\Modules\Edge\Services\EdgeService;
use App\Support\Logger;

class EdgeController
{
    private DnsService $dns;

    public function __construct(private EdgeService $service)
    {
        $this->dns = new DnsService();
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
        $this->refreshProxiedDnsRecords();
        return $result;
    }

    public function heartbeat(array $input): array
    {
        $ok = $this->service->heartbeat($input);
        if (!$ok) {
            return ['error' => 'edge_not_found', 'status' => 404];
        }

        $this->refreshProxiedDnsRecords();
        return ['ok' => true];
    }

    private function refreshProxiedDnsRecords(): void
    {
        try {
            $this->dns->refreshAllProxiedARecords();
        } catch (\RuntimeException $e) {
            Logger::error('proxied_dns_refresh_failed', ['error' => $e->getMessage()]);
        }
    }
}
